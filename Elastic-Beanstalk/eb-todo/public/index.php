<?php
declare(strict_types=1);
require __DIR__ . '/../src/config.php';
require __DIR__ . '/../src/layout.php';

$userId = require_login();
$pdo = db();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);

    switch ($action) {
        case 'add':
            $title = trim((string) ($_POST['title'] ?? ''));
            if ($title === '' || mb_strlen($title) > 255) {
                $_SESSION['flash_error'] = 'Il titolo è obbligatorio (max 255 caratteri).';
            } else {
                $pdo->prepare('INSERT INTO todos (user_id, title) VALUES (?, ?)')
                    ->execute([$userId, $title]);
            }
            break;

        case 'toggle':
            $pdo->prepare('UPDATE todos SET done = 1 - done WHERE id = ? AND user_id = ?')
                ->execute([$id, $userId]);
            break;

        case 'delete':
            $pdo->prepare('DELETE FROM todos WHERE id = ? AND user_id = ?')
                ->execute([$id, $userId]);
            break;
    }
    redirect('index.php'); // pattern Post/Redirect/Get
}

$error = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_error']);

$stmt = $pdo->prepare('SELECT id, title, done FROM todos WHERE user_id = ? ORDER BY done ASC, id DESC');
$stmt->execute([$userId]);
$todos = $stmt->fetchAll();

render_header('Le mie attività');
?>
<h1>Le mie attività Versione 1.0</h1>
<?php if ($error): ?><div class="error"><?= e($error) ?></div><?php endif; ?>

<form method="post" class="row">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="add">
    <input type="text" name="title" placeholder="Nuova attività..." maxlength="255" required>
    <button type="submit">Aggiungi</button>
</form>

<?php if (!$todos): ?>
    <p>Nessuna attività. Aggiungine una!</p>
<?php else: ?>
<ul>
    <?php foreach ($todos as $t): ?>
    <li class="<?= $t['done'] ? 'done' : '' ?>">
        <span class="title"><?= e($t['title']) ?></span>
        <span>
            <form method="post" class="inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                <button type="submit"><?= $t['done'] ? 'Riapri' : 'Fatto' ?></button>
            </form>
            <form method="post" class="inline" onsubmit="return confirm('Eliminare l\'attività?');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                <button type="submit" class="link">Elimina</button>
            </form>
        </span>
    </li>
    <?php endforeach; ?>
</ul>
<?php endif; ?>
<?php render_footer();