<?php
declare(strict_types=1);
require __DIR__ . '/../src/config.php';
require __DIR__ . '/../src/layout.php';

if (current_user_id() !== null) {
    redirect('index.php');
}

$errors = [];
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $confirm  = (string) ($_POST['confirm'] ?? '');

    if (!preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $username)) {
        $errors[] = 'Username: 3-50 caratteri (lettere, numeri, _ . -).';
    }
    if (strlen($password) < 8) {
        $errors[] = 'La password deve avere almeno 8 caratteri.';
    }
    if ($password !== $confirm) {
        $errors[] = 'Le password non coincidono.';
    }

    if (!$errors) {
        try {
            $stmt = db()->prepare('INSERT INTO users (username, password_hash) VALUES (?, ?)');
            $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT)]);
            $_SESSION['flash'] = 'Registrazione completata. Ora puoi accedere.';
            redirect('login.php');
        } catch (PDOException $ex) {
            if ($ex->getCode() === '23000') {
                $errors[] = 'Username già in uso.';
            } else {
                error_log($ex->getMessage());
                $errors[] = 'Errore interno, riprova.';
            }
        }
    }
}

render_header('Registrazione');
?>
<h1>Crea un account</h1>
<?php foreach ($errors as $err): ?>
    <div class="error"><?= e($err) ?></div>
<?php endforeach; ?>
<form method="post" autocomplete="off">
    <?= csrf_field() ?>
    <label>Username</label>
    <input type="text" name="username" value="<?= e($username) ?>" required>
    <label>Password (min. 8 caratteri)</label>
    <input type="password" name="password" required>
    <label>Conferma password</label>
    <input type="password" name="confirm" required>
    <button type="submit">Registrati</button>
</form>
<?php render_footer();