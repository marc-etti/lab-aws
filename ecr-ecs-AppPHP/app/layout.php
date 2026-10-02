<?php
declare(strict_types=1);

function render_header(string $title): void
{
    $loggedIn = current_user_id() !== null;
    ?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> - Todo App</title>
    <style>
        body { font-family: system-ui, sans-serif; background: #f4f5f7; margin: 0; color: #222; }
        header { background: #232f3e; color: #fff; padding: 12px 20px; display: flex; justify-content: space-between; align-items: center; }
        header a { color: #ff9900; text-decoration: none; margin-left: 12px; }
        main { max-width: 560px; margin: 30px auto; background: #fff; padding: 24px; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,.15); }
        h1 { margin-top: 0; font-size: 1.4rem; }
        input[type=text], input[type=password] { width: 100%; padding: 9px; margin: 6px 0 14px; box-sizing: border-box; border: 1px solid #bbb; border-radius: 4px; }
        button { padding: 8px 14px; border: 0; border-radius: 4px; background: #ff9900; cursor: pointer; font-weight: 600; }
        button.link { background: none; color: #c00; padding: 0 4px; font-weight: 400; }
        .error { background: #fde8e8; color: #a00; padding: 8px 12px; border-radius: 4px; margin-bottom: 12px; }
        .ok { background: #e6f6e6; color: #060; padding: 8px 12px; border-radius: 4px; margin-bottom: 12px; }
        ul { list-style: none; padding: 0; }
        li { display: flex; align-items: center; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #eee; }
        li.done .title { text-decoration: line-through; color: #888; }
        .row { display: flex; gap: 8px; }
        .row input { margin: 0; }
        form.inline { display: inline; margin: 0; }
    </style>
</head>
<body>
<header>
    <strong>Todo App</strong>
    <nav>
        <?php if ($loggedIn): ?>
            <span><?= e($_SESSION['username'] ?? '') ?></span>
            <a href="logout.php">Esci</a>
        <?php else: ?>
            <a href="login.php">Accedi</a>
            <a href="register.php">Registrati</a>
        <?php endif; ?>
    </nav>
</header>
<main>
<?php
}

function render_footer(): void
{
    echo "</main>\n</body>\n</html>";
}