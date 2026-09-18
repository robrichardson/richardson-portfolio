<?php
/**
 * Dropbox — backend.
 *
 * File bytes never pass through this server on the happy path: it mints
 * Google resumable upload sessions and the browser PUTs straight to Google.
 * The proxy action exists only as a fallback when the browser can't reach
 * Google directly.
 */
declare(strict_types=1);

require __DIR__ . '/_lib.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function out(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function fail(string $message, int $status = 400): never
{
    out(['ok' => false, 'error' => $message], $status);
}

function json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

/** Load a batch by its token, or stop. */
function require_batch(string $token): array
{
    if ($token === '') {
        fail('Missing upload token.', 401);
    }
    $st = dropbox_db()->prepare('SELECT * FROM batches WHERE token = ?');
    $st->execute([$token]);
    $batch = $st->fetch();
    if ($batch === false) {
        fail('Unknown or expired upload token.', 401);
    }
    return $batch;
}

/** Look up one file of a batch by index. The session URI is never client-supplied. */
function require_file(array $batch, int $idx): array
{
    $st = dropbox_db()->prepare('SELECT * FROM files WHERE batch_id = ? AND idx = ?');
    $st->execute([$batch['id'], $idx]);
    $file = $st->fetch();
    if ($file === false) {
        fail('Unknown file index.', 404);
    }
    return $file;
}

// ---------------------------------------------------------------------

$action = (string) ($_GET['action'] ?? '');

try {
    $cfg = dropbox_config();

    switch ($action) {

        // -------------------------------------------------------------
        // Open a drop: check the passphrase, make a folder, mint sessions.
        // -------------------------------------------------------------
        case 'start': {
            $in = json_body();
            $ip = dropbox_client_ip();

            // Throttle by IP regardless of outcome.
            dropbox_db()->prepare('DELETE FROM attempts WHERE at < ?')->execute([time() - 86400]);
            $st = dropbox_db()->prepare('SELECT COUNT(*) AS n FROM attempts WHERE ip = ? AND at > ? AND ok = 0');
            $st->execute([$ip, time() - 3600]);
            if ((int) $st->fetch()['n'] >= (int) $cfg['max_attempts_per_hour']) {
                fail('Too many attempts. Try again in an hour.', 429);
            }

            $passOk = true;
            if ($cfg['passphrase_hash'] !== null && $cfg['passphrase_hash'] !== '') {
                $passOk = password_verify((string) ($in['passphrase'] ?? ''), (string) $cfg['passphrase_hash']);
            }

            $log = dropbox_db()->prepare('INSERT INTO attempts (ip, at, ok) VALUES (?, ?, ?)');
            $log->execute([$ip, time(), $passOk ? 1 : 0]);

            if (!$passOk) {
                usleep(400000);
                fail('That passphrase is not right.', 403);
            }

            // Collapsed to a single line: these end up in a mail header and a folder name.
            $name  = dropbox_single_line((string) ($in['name'] ?? ''), 80);
            $email = dropbox_single_line((string) ($in['email'] ?? ''), 160);
            $note  = mb_substr(str_replace("\0", '', (string) ($in['note'] ?? '')), 0, 2000);
            if ($name === '') {
                fail('Please give your name.');
            }
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                fail('That email address does not look right.');
            }

            $files = $in['files'] ?? [];
            if (!is_array($files) || $files === []) {
                fail('No files selected.');
            }
            if (count($files) > (int) $cfg['max_files_per_batch']) {
                fail('Too many files at once. The limit is ' . $cfg['max_files_per_batch'] . '.');
            }

            $total = 0;
            $clean = [];
            $n     = 0;
            foreach ($files as $f) {
                if (!is_array($f)) {
                    fail('Malformed file list.');
                }
                $fname = dropbox_safe_filename((string) ($f['name'] ?? ''));
                $fsize = (int) ($f['size'] ?? 0);
                $fmime = (string) ($f['type'] ?? '');

                if ($fsize <= 0) {
                    fail('"' . $fname . '" looks empty.');
                }
                if ($fsize > (int) $cfg['max_file_bytes']) {
                    fail('"' . $fname . '" is larger than the ' . dropbox_format_bytes((int) $cfg['max_file_bytes']) . ' per-file limit.');
                }
                $ext = dropbox_extension($fname);
                if ($ext !== '' && in_array($ext, (array) $cfg['blocked_extensions'], true)) {
                    fail('"' . $fname . '" is a file type I can\'t accept. Zip it if you need to send it.');
                }
                $total += $fsize;
                $clean[] = ['idx' => $n++, 'name' => $fname, 'size' => $fsize, 'mime' => $fmime];
            }
            if ($total > (int) $cfg['max_batch_bytes']) {
                fail('That drop totals ' . dropbox_format_bytes($total) . ', over the ' . dropbox_format_bytes((int) $cfg['max_batch_bytes']) . ' limit.');
            }

            // Folder per drop, under the app-owned root.
            $root       = dropbox_root_folder_id();
            $stamp      = date('Y-m-d');
            $folderName = $stamp . ' — ' . $name;
            $folder     = dropbox_create_folder($folderName, $root);

            $token = bin2hex(random_bytes(24));
            $db    = dropbox_db();
            $db->prepare(
                'INSERT INTO batches (token, created_at, ip, sender_name, sender_email, note, folder_id, folder_name, folder_url)
                 VALUES (?,?,?,?,?,?,?,?,?)'
            )->execute([
                $token, date('c'), $ip, $name, $email, $note,
                $folder['id'], $folderName, $folder['webViewLink'] ?? null,
            ]);
            $batchId = (int) $db->lastInsertId();

            $sessions = [];
            $ins = $db->prepare('INSERT INTO files (batch_id, idx, name, size, mime, session_uri) VALUES (?,?,?,?,?,?)');
            foreach ($clean as $f) {
                $uri = dropbox_create_upload_session($f['name'], $f['mime'], $f['size'], (string) $folder['id']);
                $ins->execute([$batchId, $f['idx'], $f['name'], $f['size'], $f['mime'], $uri]);
                $sessions[] = ['idx' => $f['idx'], 'name' => $f['name'], 'uploadUrl' => $uri];
            }

            out(['ok' => true, 'token' => $token, 'files' => $sessions]);
        }

        // -------------------------------------------------------------
        // Fallback: relay one chunk through this server to Google.
        // -------------------------------------------------------------
        case 'proxy': {
            $batch = require_batch((string) ($_GET['token'] ?? ''));
            $file  = require_file($batch, (int) ($_GET['idx'] ?? -1));

            $start = (int) ($_GET['start'] ?? -1);
            $end   = (int) ($_GET['end'] ?? -1);
            $size  = (int) $file['size'];
            if ($start < 0 || $end < $start || $end >= $size) {
                fail('Bad chunk range.');
            }

            $in = fopen('php://input', 'rb');
            if ($in === false) {
                fail('Could not read the chunk.', 500);
            }
            $tmp = tmpfile();
            if ($tmp === false) {
                fail('Could not buffer the chunk.', 500);
            }
            $copied = stream_copy_to_stream($in, $tmp);
            fclose($in);
            rewind($tmp);

            $expected = $end - $start + 1;
            if ($copied !== $expected) {
                fclose($tmp);
                fail('Chunk was ' . (int) $copied . ' bytes, expected ' . $expected . '.');
            }

            $res = dropbox_http('PUT', (string) $file['session_uri'],
                ['Content-Range: bytes ' . $start . '-' . $end . '/' . $size],
                null, $tmp, $expected
            );
            fclose($tmp);

            out(['ok' => true, 'status' => $res['status'], 'range' => $res['headers']['range'] ?? null]);
        }

        // -------------------------------------------------------------
        // Ask Google how many bytes it actually has, server-side.
        // -------------------------------------------------------------
        case 'status': {
            $batch = require_batch((string) ($_GET['token'] ?? ''));
            $file  = require_file($batch, (int) ($_GET['idx'] ?? -1));

            $res = dropbox_http('PUT', (string) $file['session_uri'],
                ['Content-Range: bytes */' . (int) $file['size'], 'Content-Length: 0'], ''
            );

            $received = 0;
            if (!empty($res['headers']['range']) && preg_match('/bytes=0-(\d+)/', $res['headers']['range'], $m)) {
                $received = (int) $m[1] + 1;
            }
            $complete = in_array($res['status'], [200, 201], true);

            if ($complete) {
                $data = json_decode($res['body'], true);
                dropbox_db()->prepare('UPDATE files SET status = "done", drive_file_id = ? WHERE id = ?')
                    ->execute([$data['id'] ?? null, $file['id']]);
                $received = (int) $file['size'];
            }

            out(['ok' => true, 'complete' => $complete, 'received' => $received, 'expired' => $res['status'] === 404]);
        }

        // -------------------------------------------------------------
        // Close the drop and let Rob know.
        // -------------------------------------------------------------
        case 'finish': {
            $batch = require_batch((string) ($_GET['token'] ?? ''));

            $st = dropbox_db()->prepare('SELECT * FROM files WHERE batch_id = ? ORDER BY idx');
            $st->execute([$batch['id']]);
            $files = $st->fetchAll();

            $done = array_values(array_filter($files, fn(array $f): bool => $f['status'] === 'done'));

            dropbox_db()->prepare('UPDATE batches SET status = ?, completed_at = ? WHERE id = ?')
                ->execute([count($done) === count($files) ? 'complete' : 'partial', date('c'), $batch['id']]);

            if (!empty($cfg['notify_email']) && $done !== []) {
                $lines = [];
                foreach ($done as $f) {
                    $lines[] = '  • ' . $f['name'] . '  (' . dropbox_format_bytes((int) $f['size']) . ')';
                }
                $body = "New files in your drop box.\n\n"
                      . 'From:   ' . $batch['sender_name'] . ($batch['sender_email'] ? ' <' . $batch['sender_email'] . '>' : '') . "\n"
                      . 'Folder: ' . $batch['folder_name'] . "\n"
                      . ($batch['folder_url'] ? 'Open:   ' . $batch['folder_url'] . "\n" : '')
                      . "\n" . count($done) . ' file' . (count($done) === 1 ? '' : 's') . ":\n"
                      . implode("\n", $lines) . "\n"
                      . ($batch['note'] ? "\nMessage:\n" . $batch['note'] . "\n" : '');

                $headers = 'From: ' . ($cfg['notify_from'] ?: 'dropbox@' . ($_SERVER['HTTP_HOST'] ?? 'localhost')) . "\r\n"
                         . "Content-Type: text/plain; charset=utf-8\r\n";
                if ($batch['sender_email']) {
                    $headers .= 'Reply-To: ' . $batch['sender_email'] . "\r\n";
                }
                @mail((string) $cfg['notify_email'], 'Drop box: ' . count($done) . ' file(s) from ' . $batch['sender_name'], $body, $headers);
            }

            out(['ok' => true, 'received' => count($done), 'expected' => count($files)]);
        }

        default:
            fail('Unknown action.', 404);
    }

} catch (Throwable $e) {
    // Detail goes to the server log; the browser gets a reference, not a stack trace.
    $ref = substr(bin2hex(random_bytes(4)), 0, 8);
    error_log('[dropbox ' . $ref . '] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    out(['ok' => false, 'error' => 'Something went wrong at my end (ref ' . $ref . '). Please try again, or email me.'], 500);
}
