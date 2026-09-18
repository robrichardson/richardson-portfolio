<?php
/**
 * Dropbox — shared library.
 * Config, database, Google HTTP. No output of its own.
 */
declare(strict_types=1);

define('DROPBOX_PRIVATE_DIR', dirname(__DIR__, 2) . '/private/dropbox');
define('DROPBOX_CONFIG_FILE', DROPBOX_PRIVATE_DIR . '/config.php');
define('DROPBOX_DB_FILE',     DROPBOX_PRIVATE_DIR . '/dropbox.sqlite');

const GOOGLE_TOKEN_URL  = 'https://oauth2.googleapis.com/token';
const GOOGLE_AUTH_URL   = 'https://accounts.google.com/o/oauth2/v2/auth';
const GOOGLE_DRIVE_API  = 'https://www.googleapis.com/drive/v3';
const GOOGLE_UPLOAD_API = 'https://www.googleapis.com/upload/drive/v3';
const DRIVE_SCOPE       = 'https://www.googleapis.com/auth/drive.file';

// ---------------------------------------------------------------------
// Config
// ---------------------------------------------------------------------

function dropbox_config_defaults(): array
{
    return [
        'client_id'             => '',
        'client_secret'         => '',
        'refresh_token'         => '',
        'redirect_uri'          => '',
        'passphrase_hash'       => null,
        'setup_key'             => '',
        'root_folder_name'      => 'Client Uploads',
        'notify_email'          => null,
        'notify_from'           => null,
        'max_files_per_batch'   => 25,
        'max_file_bytes'        => 5368709120,
        'max_batch_bytes'       => 21474836480,
        'blocked_extensions'    => [],
        'max_attempts_per_hour' => 12,
    ];
}

function dropbox_config(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $cfg = dropbox_config_reload();
    }
    return $cfg;
}

/** Read config.php fresh from disk, with defaults filled in. */
function dropbox_config_reload(): array
{
    if (!is_file(DROPBOX_CONFIG_FILE)) {
        throw new RuntimeException('Config file not found at ' . DROPBOX_CONFIG_FILE);
    }
    $loaded = include DROPBOX_CONFIG_FILE;
    if (!is_array($loaded)) {
        throw new RuntimeException('Config file did not return an array.');
    }
    return $loaded + dropbox_config_defaults();
}

/** Rewrite a single top-level string value in config.php, preserving the rest. */
function dropbox_config_set(string $key, string $value): void
{
    $src = file_get_contents(DROPBOX_CONFIG_FILE);
    if ($src === false) {
        throw new RuntimeException('Could not read config file.');
    }
    $quoted  = var_export($value, true);
    $pattern = "/(['\"]" . preg_quote($key, '/') . "['\"]\s*=>\s*)(?:'(?:\\\\.|[^'\\\\])*'|\"(?:\\\\.|[^\"\\\\])*\"|null)/";

    if (preg_match($pattern, $src)) {
        $updated = preg_replace($pattern, '${1}' . str_replace('$', '\\$', $quoted), $src, 1);
    } else {
        $updated = preg_replace('/\breturn\s*\[/', "return [\n    " . var_export($key, true) . " => {$quoted},", $src, 1);
    }
    if (!is_string($updated)) {
        throw new RuntimeException('Could not rewrite config file.');
    }
    if (file_put_contents(DROPBOX_CONFIG_FILE, $updated, LOCK_EX) === false) {
        throw new RuntimeException('Could not write config file. Check permissions on ' . DROPBOX_PRIVATE_DIR);
    }
    // cPanel runs opcache with a revalidate delay, so without this the next
    // request can still compile the old config for a second or two.
    if (function_exists('opcache_invalidate')) {
        @opcache_invalidate(DROPBOX_CONFIG_FILE, true);
    }
    clearstatcache(true, DROPBOX_CONFIG_FILE);
}

// ---------------------------------------------------------------------
// Database
// ---------------------------------------------------------------------

function dropbox_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    if (!is_dir(DROPBOX_PRIVATE_DIR) && !@mkdir(DROPBOX_PRIVATE_DIR, 0700, true)) {
        throw new RuntimeException('Could not create ' . DROPBOX_PRIVATE_DIR);
    }
    $pdo = new PDO('sqlite:' . DROPBOX_DB_FILE, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA busy_timeout = 5000');

    $pdo->exec('CREATE TABLE IF NOT EXISTS settings (
        key TEXT PRIMARY KEY,
        value TEXT NOT NULL
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS batches (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        token         TEXT NOT NULL UNIQUE,
        created_at    TEXT NOT NULL,
        completed_at  TEXT,
        ip            TEXT,
        sender_name   TEXT,
        sender_email  TEXT,
        note          TEXT,
        folder_id     TEXT,
        folder_name   TEXT,
        folder_url    TEXT,
        status        TEXT NOT NULL DEFAULT "open"
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS files (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        batch_id      INTEGER NOT NULL,
        idx           INTEGER NOT NULL,
        name          TEXT NOT NULL,
        size          INTEGER NOT NULL,
        mime          TEXT,
        session_uri   TEXT NOT NULL,
        drive_file_id TEXT,
        status        TEXT NOT NULL DEFAULT "pending",
        FOREIGN KEY (batch_id) REFERENCES batches(id)
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS attempts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ip TEXT NOT NULL,
        at INTEGER NOT NULL,
        ok INTEGER NOT NULL DEFAULT 0
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_attempts_ip_at ON attempts(ip, at)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_files_batch ON files(batch_id)');

    return $pdo;
}

function dropbox_setting(string $key): ?string
{
    $st = dropbox_db()->prepare('SELECT value FROM settings WHERE key = ?');
    $st->execute([$key]);
    $row = $st->fetch();
    return $row === false ? null : (string) $row['value'];
}

function dropbox_setting_put(string $key, string $value): void
{
    $st = dropbox_db()->prepare(
        'INSERT INTO settings (key, value) VALUES (?, ?)
         ON CONFLICT(key) DO UPDATE SET value = excluded.value'
    );
    $st->execute([$key, $value]);
}

// ---------------------------------------------------------------------
// HTTP
// ---------------------------------------------------------------------

/**
 * @return array{status:int, body:string, headers:array<string,string>}
 */
function dropbox_http(
    string $method,
    string $url,
    array $headers = [],
    string|null $body = null,
    $bodyStream = null,
    int $bodySize = 0
): array {
    if (!function_exists('curl_init')) {
        throw new RuntimeException('The PHP cURL extension is not available on this server.');
    }
    $ch = curl_init($url);
    $responseHeaders = [];

    $opts = [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT        => 300,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$responseHeaders): int {
            $len = strlen($line);
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
            return $len;
        },
    ];

    if ($bodyStream !== null) {
        $opts[CURLOPT_UPLOAD]     = true;
        $opts[CURLOPT_INFILE]     = $bodyStream;
        $opts[CURLOPT_INFILESIZE] = $bodySize;
    } elseif ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = $body;
    }

    curl_setopt_array($ch, $opts);
    $out = curl_exec($ch);

    if ($out === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('Network request failed: ' . $err);
    }
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    return ['status' => $status, 'body' => (string) $out, 'headers' => $responseHeaders];
}

// ---------------------------------------------------------------------
// Google auth
// ---------------------------------------------------------------------

function dropbox_access_token(): string
{
    $cached  = dropbox_setting('access_token');
    $expires = (int) (dropbox_setting('access_token_expires') ?? 0);
    if ($cached !== null && $expires > time() + 60) {
        return $cached;
    }

    $cfg = dropbox_config();
    if ($cfg['refresh_token'] === '') {
        throw new RuntimeException('No refresh token stored. Run setup.php first.');
    }

    $res = dropbox_http('POST', GOOGLE_TOKEN_URL,
        ['Content-Type: application/x-www-form-urlencoded'],
        http_build_query([
            'grant_type'    => 'refresh_token',
            'refresh_token' => $cfg['refresh_token'],
            'client_id'     => $cfg['client_id'],
            'client_secret' => $cfg['client_secret'],
        ])
    );

    $data = json_decode($res['body'], true);
    if ($res['status'] !== 200 || !isset($data['access_token'])) {
        $reason = $data['error_description'] ?? $data['error'] ?? $res['body'];
        throw new RuntimeException('Could not refresh the Google token: ' . $reason);
    }

    dropbox_setting_put('access_token', $data['access_token']);
    dropbox_setting_put('access_token_expires', (string) (time() + (int) ($data['expires_in'] ?? 3600)));

    return (string) $data['access_token'];
}

function dropbox_auth_header(): array
{
    return ['Authorization: Bearer ' . dropbox_access_token()];
}

// ---------------------------------------------------------------------
// Drive
// ---------------------------------------------------------------------

function dropbox_create_folder(string $name, ?string $parentId = null): array
{
    $meta = ['name' => $name, 'mimeType' => 'application/vnd.google-apps.folder'];
    if ($parentId !== null) {
        $meta['parents'] = [$parentId];
    }
    $res = dropbox_http('POST', GOOGLE_DRIVE_API . '/files?fields=id,name,webViewLink',
        array_merge(dropbox_auth_header(), ['Content-Type: application/json; charset=UTF-8']),
        json_encode($meta, JSON_UNESCAPED_UNICODE)
    );
    $data = json_decode($res['body'], true);
    if ($res['status'] >= 300 || !isset($data['id'])) {
        throw new RuntimeException('Could not create the Drive folder: ' . $res['body']);
    }
    return $data;
}

/** The app-owned top-level folder. Created once, then remembered. */
function dropbox_root_folder_id(): string
{
    $stored = dropbox_setting('root_folder_id');
    if ($stored !== null && $stored !== '') {
        $res = dropbox_http('GET', GOOGLE_DRIVE_API . '/files/' . rawurlencode($stored) . '?fields=id,trashed', dropbox_auth_header());
        $data = json_decode($res['body'], true);
        if ($res['status'] === 200 && empty($data['trashed'])) {
            return $stored;
        }
    }
    $folder = dropbox_create_folder(dropbox_config()['root_folder_name']);
    dropbox_setting_put('root_folder_id', $folder['id']);
    return (string) $folder['id'];
}

/** Ask Drive for a resumable session and return its URI. */
function dropbox_create_upload_session(string $name, string $mime, int $size, string $parentId): string
{
    $meta = ['name' => $name, 'parents' => [$parentId]];
    $res = dropbox_http('POST', GOOGLE_UPLOAD_API . '/files?uploadType=resumable',
        array_merge(dropbox_auth_header(), [
            'Content-Type: application/json; charset=UTF-8',
            'X-Upload-Content-Type: ' . ($mime !== '' ? $mime : 'application/octet-stream'),
            'X-Upload-Content-Length: ' . $size,
        ]),
        json_encode($meta, JSON_UNESCAPED_UNICODE)
    );
    if ($res['status'] >= 300 || empty($res['headers']['location'])) {
        throw new RuntimeException('Could not start the upload session: ' . $res['body']);
    }
    return (string) $res['headers']['location'];
}

// ---------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------

function dropbox_client_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

/** Strip anything path-like or control-ish out of a client-supplied filename. */
function dropbox_safe_filename(string $name): string
{
    $name = str_replace(["\0", "\r", "\n", "\t"], '', $name);
    $name = basename(str_replace('\\', '/', $name));
    $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
    $name = trim($name, " .");
    if ($name === '') {
        $name = 'unnamed-file';
    }
    if (mb_strlen($name) > 180) {
        $ext  = pathinfo($name, PATHINFO_EXTENSION);
        $ext  = is_string($ext) && $ext !== '' ? '.' . mb_substr($ext, 0, 12) : '';
        $name = mb_substr($name, 0, 180 - mb_strlen($ext)) . $ext;
    }
    return $name;
}

/** Collapse anything that could break out of a mail header or a folder name. */
function dropbox_single_line(string $s, int $max = 120): string
{
    $s = str_replace(["\r", "\n", "\0"], ' ', $s);
    $s = preg_replace('/[\x00-\x1F\x7F]/u', '', $s) ?? '';
    $s = trim(preg_replace('/\s+/u', ' ', $s) ?? '');
    return mb_substr($s, 0, $max);
}

function dropbox_extension(string $filename): string
{
    $ext = pathinfo($filename, PATHINFO_EXTENSION);
    return strtolower(is_string($ext) ? $ext : '');
}

function dropbox_format_bytes(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    $n = (float) $bytes;
    while ($n >= 1024 && $i < count($units) - 1) {
        $n /= 1024;
        $i++;
    }
    return ($i === 0 ? (string) (int) $n : number_format($n, 1)) . ' ' . $units[$i];
}
