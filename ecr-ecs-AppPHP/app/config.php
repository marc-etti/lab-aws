<?php
declare(strict_types=1);

// Sessione con cookie sicuri (funziona anche dietro ALB con HTTPS)
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'httponly' => true,
    'secure'   => $isHttps,
    'samesite' => 'Lax',
]);
session_start();

/** Connessione PDO (singleton), configurata da variabili d'ambiente */
function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $host = getenv('DB_HOST') ?: 'db';
        $port = getenv('DB_PORT') ?: '3306';
        $name = getenv('DB_NAME') ?: 'todoapp';
        $user = getenv('DB_USER') ?: 'todo';
        $pass = getenv('DB_PASSWORD') ?: '';

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        // TLS verso RDS: attivo solo se è impostata DB_SSL_CA
        // (in locale con Docker Compose la variabile non c'è e la connessione resta in chiaro)
        $ca = getenv('DB_SSL_CA');
        if ($ca) {
            $options[PDO::MYSQL_ATTR_SSL_CA] = $ca;
        }

        $pdo = new PDO(
            "mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4",
            $user,
            $pass,
            $options
        );
    }
    return $pdo;
}

/** Escape per l'output HTML */
function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

/** Protezione CSRF */
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    $token = $_POST['csrf'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['csrf'] ?? '', $token)) {
        http_response_code(403);
        exit('Richiesta non valida (CSRF).');
    }
}

/** Autenticazione */
function current_user_id(): ?int
{
    return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
}

function require_login(): int
{
    $id = current_user_id();
    if ($id === null) {
        redirect('login.php');
    }
    return $id;
}