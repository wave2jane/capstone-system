<?php
// One-time setup: creates the tables and the first administrator account.
// Open this page once in your browser, then DELETE this file from the server.
declare(strict_types=1);
require __DIR__ . '/config.php';

$msg = '';
$done = false;
$err = '';

try {
    $pdo = db();
    foreach (preg_split('/;\s*[\r\n]+/', (string)file_get_contents(__DIR__ . '/schema.sql')) as $stmt) {
        if (trim($stmt) !== '') {
            $pdo->exec($stmt);
        }
    }
    $has = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
} catch (Throwable $e) {
    $err = 'Could not connect to the database. Check DB_HOST, DB_NAME, DB_USER and DB_PASS in config.php.';
    $has = -1;
}

if ($has === 0 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $ok = hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''));
    $name = trim((string)($_POST['name'] ?? ''));
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $pass = (string)($_POST['password'] ?? '');
    if (!$ok) {
        $err = 'Session expired. Reload and try again.';
    } elseif (mb_strlen($name) < 2 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $err = 'Enter a valid name and email.';
    } elseif (strlen($pass) < 10) {
        $err = 'Use an admin password of at least 10 characters.';
    } else {
        $pdo->prepare("INSERT INTO users (role, full_name, email, password_hash) VALUES ('admin', ?, ?, ?)")
            ->execute([$name, $email, password_hash($pass, PASSWORD_DEFAULT)]);
        $done = true;
    }
}
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>CCDI Portal Setup</title>
<style>
body{font-family:system-ui,sans-serif;background:#ece8fb;margin:0;min-height:100dvh;display:grid;place-items:center;padding:16px;color:#1e1b4b}
.c{background:#fff;border-radius:16px;padding:28px;max-width:420px;width:100%;box-shadow:0 10px 30px rgba(49,46,129,.15)}
h1{font-size:1.3rem;margin:0 0 6px}label{display:block;margin-top:12px;font-weight:600;font-size:.9rem}
input{width:100%;padding:12px;border:1px solid #cbd5e1;border-radius:10px;font-size:1rem;margin-top:4px;box-sizing:border-box}
button{margin-top:18px;width:100%;padding:12px;border:0;border-radius:10px;background:#3730a3;color:#fff;font-size:1rem;font-weight:600;cursor:pointer}
.e{color:#b91c1c;margin-top:10px}.g{color:#166534}
</style></head><body><div class="c">
<h1>CCDI Portal Setup</h1>
<?php if ($has === -1): ?>
  <p class="e"><?= htmlspecialchars($err) ?></p>
<?php elseif ($done): ?>
  <p class="g">Tables are ready and the administrator account was created.</p>
  <p><strong>Delete setup.php from the server now</strong>, then <a href="index.html">open the portal</a>.</p>
<?php elseif ($has > 0): ?>
  <p>Setup is already complete. Please delete <code>setup.php</code> from the server.</p>
<?php else: ?>
  <p>Database tables are ready. Create the administrator account:</p>
  <?php if ($err): ?><p class="e"><?= htmlspecialchars($err) ?></p><?php endif; ?>
  <form method="post" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
    <label>Full name<input name="name" required></label>
    <label>Admin email<input name="email" type="email" required></label>
    <label>Password (10+ characters)<input name="password" type="password" minlength="10" required></label>
    <button>Create administrator</button>
  </form>
<?php endif; ?>
</div></body></html>
