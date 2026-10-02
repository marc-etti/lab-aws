<?php
declare(strict_types=1);
require __DIR__ . '/../src/config.php';
require __DIR__ . '/../src/layout.php';

if (current_user_id() !== null) {
    redirect('index.php');
}

$error = '';
$username = '';
$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    $stmt = db()->prepare('SELECT id, username, password_hash FROM users WHERE username = ?');
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash'])) {
        session_regenerate_id(true); // previene session fixation
        $_SESSION['user_id']  = (int) $user['id'];
        $_SESSION['username'] = $user['username'];

        // Aggiorna l'hash se l'algoritmo di default è cambiato
        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            $upd = db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
            $upd->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
        }
        redirect('index.php');
    }
    // Messaggio generico: non rivela se l'utente esiste
    $error = 'Credenziali non valide.';
}

render_header('Accesso');
?>
<h1>Accedi</h1>
<?php if ($flash): ?><div class="ok"><?= e($flash) ?></div><?php endif; ?>
<?php if ($error): ?><div class="error"><?= e($error) ?></div><?php endif; ?>
<form method="post">
    <?= csrf_field() ?>
    <label>Username</label>
    <input type="text" name="username" value="<?= e($username) ?>" required autofocus>
    <label>Password</label>
    <input type="password" name="password" required>
    <button type="submit">Accedi</button>
</form>
<p>Non hai un account? <a href="register.php">Registrati</a></p>
<?php render_footer();