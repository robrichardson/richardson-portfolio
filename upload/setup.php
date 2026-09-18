<?php
/**
 * Dropbox — one-time setup.
 *
 * Visit /upload/setup.php?key=YOUR_SETUP_KEY, click through the Google
 * consent screen once, and the refresh token gets written into the private
 * config file. Delete this file afterwards (or leave it — it refuses to do
 * anything without the key).
 */
declare(strict_types=1);

require __DIR__ . '/_lib.php';

header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

$messages = [];
$errors   = [];

function h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

try {
    $cfg = dropbox_config();
} catch (Throwable $e) {
    http_response_code(500);
    echo '<!doctype html><meta charset="utf-8"><title>Setup</title>'
       . '<body style="font:15px/1.6 ui-monospace,monospace;background:#0a0a0a;color:#f4f4f0;padding:40px">'
       . '<h1 style="color:#dfe104">Config not found</h1><p>' . h($e->getMessage()) . '</p>'
       . '<p>Upload <code>config.php</code> to <code>' . h(DROPBOX_PRIVATE_DIR) . '</code> and reload.</p>';
    exit;
}

// --- gate -------------------------------------------------------------
$expectedKey = (string) $cfg['setup_key'];
$providedKey = (string) ($_GET['key'] ?? $_POST['key'] ?? $_GET['state'] ?? '');

if ($expectedKey === '' || $expectedKey === 'change-me-to-something-long-and-random') {
    $errors[] = 'Set a real setup_key in config.php before using this page.';
} elseif (!hash_equals($expectedKey, $providedKey)) {
    http_response_code(403);
    echo '<!doctype html><meta charset="utf-8"><title>Setup</title>'
       . '<body style="font:15px/1.6 ui-monospace,monospace;background:#0a0a0a;color:#f4f4f0;padding:40px">'
       . '<h1 style="color:#dfe104">Nope</h1><p>Add <code>?key=</code> with your setup key.</p>';
    exit;
}

// --- OAuth callback ---------------------------------------------------
if (isset($_GET['error'])) {
    $errors[] = 'Google said: ' . (string) $_GET['error'];
}

if (isset($_GET['code']) && $errors === []) {
    try {
        $res = dropbox_http('POST', GOOGLE_TOKEN_URL,
            ['Content-Type: application/x-www-form-urlencoded'],
            http_build_query([
                'code'          => (string) $_GET['code'],
                'client_id'     => $cfg['client_id'],
                'client_secret' => $cfg['client_secret'],
                'redirect_uri'  => $cfg['redirect_uri'],
                'grant_type'    => 'authorization_code',
            ])
        );
        $data = json_decode($res['body'], true);

        if ($res['status'] !== 200 || empty($data['refresh_token'])) {
            $why = $data['error_description'] ?? $data['error'] ?? $res['body'];
            if ($res['status'] === 200 && empty($data['refresh_token'])) {
                $why = 'Google returned an access token but no refresh token. '
                     . 'Revoke this app at myaccount.google.com/permissions and try again.';
            }
            $errors[] = 'Token exchange failed: ' . $why;
        } else {
            dropbox_config_set('refresh_token', (string) $data['refresh_token']);
            dropbox_setting_put('access_token', (string) $data['access_token']);
            dropbox_setting_put('access_token_expires', (string) (time() + (int) ($data['expires_in'] ?? 3600)));
            $messages[] = 'Refresh token saved to config.php.';
        }
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
}

// --- set passphrase ---------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['passphrase'])) {
    $new = (string) $_POST['passphrase'];
    if (!hash_equals($expectedKey, (string) ($_POST['key'] ?? ''))) {
        $errors[] = 'Bad setup key on that form.';
    } elseif (strlen($new) < 6) {
        $errors[] = 'Use a passphrase of at least 6 characters.';
    } else {
        try {
            dropbox_config_set('passphrase_hash', password_hash($new, PASSWORD_DEFAULT));
            $messages[] = 'Passphrase saved. Give it to clients along with the upload link.';
            $cfg = dropbox_config_reload();
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
}

// --- self test --------------------------------------------------------
$checks = [];
$checks[] = ['PHP version', PHP_VERSION, version_compare(PHP_VERSION, '8.0', '>=')];
$checks[] = ['cURL extension', function_exists('curl_init') ? 'present' : 'MISSING', function_exists('curl_init')];
$checks[] = ['SQLite (PDO)', in_array('sqlite', PDO::getAvailableDrivers(), true) ? 'present' : 'MISSING', in_array('sqlite', PDO::getAvailableDrivers(), true)];
$checks[] = ['Private dir', DROPBOX_PRIVATE_DIR, is_dir(DROPBOX_PRIVATE_DIR)];
$checks[] = ['Config writable', is_writable(DROPBOX_CONFIG_FILE) ? 'yes' : 'no', is_writable(DROPBOX_CONFIG_FILE)];
$checks[] = ['Private dir outside web root', str_contains(DROPBOX_PRIVATE_DIR, 'public_html') ? 'NO — MOVE IT' : 'yes', !str_contains(DROPBOX_PRIVATE_DIR, 'public_html')];
$checks[] = ['Client ID set', $cfg['client_id'] !== '' ? 'yes' : 'no', $cfg['client_id'] !== ''];
$checks[] = ['Client secret set', $cfg['client_secret'] !== '' ? 'yes' : 'no', $cfg['client_secret'] !== ''];
$checks[] = ['Passphrase set', $cfg['passphrase_hash'] ? 'yes' : 'no — drop box is OPEN', (bool) $cfg['passphrase_hash']];

$connected = false;
try {
    $connected = !empty(dropbox_config_reload()['refresh_token']);
} catch (Throwable) {
}
$checks[] = ['Refresh token stored', $connected ? 'yes' : 'not yet', $connected];

if ($connected && isset($_GET['test'])) {
    try {
        dropbox_access_token();
        $checks[] = ['Token refresh', 'works', true];
        $rootId = dropbox_root_folder_id();
        $checks[] = ['Drive folder', $cfg['root_folder_name'] . ' (' . $rootId . ')', true];
    } catch (Throwable $e) {
        $checks[] = ['Drive check', $e->getMessage(), false];
    }
}

$authUrl = GOOGLE_AUTH_URL . '?' . http_build_query([
    'client_id'              => $cfg['client_id'],
    'redirect_uri'           => $cfg['redirect_uri'],
    'response_type'          => 'code',
    'scope'                  => DRIVE_SCOPE,
    'access_type'            => 'offline',
    'prompt'                 => 'consent',
    'include_granted_scopes' => 'true',
    'state'                  => $expectedKey,
]);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Drop box setup</title>
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  :root{--bg:#0a0a0a;--fg:#f4f4f0;--acid:#dfe104;--line:#3f3f3f}
  body{background:var(--bg);color:var(--fg);font:15px/1.6 ui-monospace,'JetBrains Mono',monospace;padding:48px 24px;max-width:760px;margin:0 auto}
  h1{font:700 32px/1 system-ui,sans-serif;text-transform:uppercase;letter-spacing:-.01em;margin-bottom:8px}
  .sub{color:#a0a0a0;font-size:13px;letter-spacing:.08em;text-transform:uppercase;margin-bottom:40px}
  h2{font:700 13px/1 ui-monospace,monospace;letter-spacing:.2em;text-transform:uppercase;color:var(--acid);margin:36px 0 14px}
  table{width:100%;border-collapse:collapse;border:2px solid var(--line)}
  td{padding:9px 14px;border-bottom:1px solid var(--line);font-size:13px;vertical-align:top}
  tr:last-child td{border-bottom:0}
  td:first-child{color:#a0a0a0;width:220px}
  .ok{color:var(--acid)}
  .bad{color:#ff5f57}
  .btn{display:inline-flex;align-items:center;gap:10px;font:700 13px/1 ui-monospace,monospace;letter-spacing:.1em;text-transform:uppercase;background:var(--acid);color:#000;border:2px solid var(--acid);padding:15px 28px;text-decoration:none;margin-right:12px}
  .btn:hover{background:var(--bg);color:var(--acid)}
  .btn.ghost{background:transparent;color:var(--fg);border-color:var(--line)}
  .btn.ghost:hover{border-color:var(--acid);color:var(--acid)}
  .note{border-left:2px solid var(--acid);padding:12px 16px;margin:16px 0;font-size:13px;color:#c2c2c2;background:#141414}
  .note.bad{border-color:#ff5f57}
  code{color:var(--acid);font-size:12px;word-break:break-all}
  ol{margin:12px 0 0 20px;font-size:13px;color:#c2c2c2}
  ol li{margin-bottom:8px}
</style>
</head>
<body>

<h1>Drop box setup</h1>
<p class="sub">robrichardson.co.uk/upload</p>

<?php foreach ($messages as $m): ?>
  <div class="note"><?= h($m) ?></div>
<?php endforeach; ?>
<?php foreach ($errors as $e): ?>
  <div class="note bad"><?= h($e) ?></div>
<?php endforeach; ?>

<h2>Checks</h2>
<table>
<?php foreach ($checks as [$label, $value, $good]): ?>
  <tr>
    <td><?= h($label) ?></td>
    <td class="<?= $good ? 'ok' : 'bad' ?>"><?= $good ? '✓' : '✕' ?> <?= h($value) ?></td>
  </tr>
<?php endforeach; ?>
</table>

<h2><?= $connected ? 'Connected' : 'Connect' ?></h2>

<?php if (!$connected): ?>
  <p style="font-size:13px;color:#c2c2c2;margin-bottom:20px">
    Sign in as the Google account whose Drive should receive the files. You'll see an
    "unverified app" warning — that's expected for a private app using a non-sensitive
    scope. Click <em>Advanced</em> then <em>Go to robrichardson.co.uk</em>.
  </p>
  <a class="btn" href="<?= h($authUrl) ?>">Connect Google Drive</a>
<?php else: ?>
  <p style="font-size:13px;color:#c2c2c2;margin-bottom:20px">
    Drive is connected. Run the live test, then delete <code>setup.php</code> from the server.
  </p>
  <a class="btn" href="?key=<?= urlencode($expectedKey) ?>&amp;test=1">Run live test</a>
  <a class="btn ghost" href="<?= h($authUrl) ?>">Re-authorise</a>
<?php endif; ?>

<h2>Client passphrase</h2>
<p style="font-size:13px;color:#c2c2c2;margin-bottom:16px">
  Sets <code>passphrase_hash</code> in config.php. Clients type this before they can upload.
</p>
<form method="post" action="?key=<?= urlencode($expectedKey) ?>" style="display:flex;gap:12px;flex-wrap:wrap;align-items:center">
  <input type="hidden" name="key" value="<?= h($expectedKey) ?>">
  <input type="text" name="passphrase" placeholder="new passphrase" autocomplete="off"
         style="background:#141414;color:var(--fg);border:2px solid var(--line);padding:14px 15px;font:400 14px/1 ui-monospace,monospace;min-width:280px">
  <button type="submit" class="btn" style="border:2px solid var(--acid);cursor:pointer">Set passphrase</button>
</form>

<h2>Reminders</h2>
<ol>
  <li>OAuth consent screen must be set to <strong>In production</strong>, not Testing — otherwise the refresh token dies after 7 days.</li>
  <li>Authorised redirect URI in the Google console must be exactly <code><?= h($cfg['redirect_uri']) ?></code></li>
  <li>Scope is <code>drive.file</code> only. Non-sensitive, so no verification review.</li>
  <li>Delete this file once everything works.</li>
</ol>

</body>
</html>
