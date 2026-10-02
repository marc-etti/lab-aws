<?php
declare(strict_types=1);

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

/** Legge una variabile d'ambiente (le environment properties di EB
 *  possono comparire in getenv(), $_SERVER o $_ENV a seconda della configurazione) */
function env(string $key, ?string $default = null): ?string
{
    $v = getenv($key);
    if ($v !== false && $v !== '') {
        return $v;
    }
    foreach ([$_SERVER, $_ENV] as $src) {
        if (isset($src[$key]) && $src[$key] !== '') {
            return (string) $src[$key];
        }
    }
    return $default;
}

/** Connessione PDO: DB_* (locale/RDS esterno) con fallback su RDS_* (RDS accoppiato EB) */
function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $host = env('DB_HOST')     ?? env('RDS_HOSTNAME') ?? 'db';
        $port = env('DB_PORT')     ?? env('RDS_PORT')     ?? '3306';
        $name = env('DB_NAME')     ?? env('RDS_DB_NAME')  ?? 'todoapp';
        $user = env('DB_USER')     ?? env('RDS_USERNAME') ?? 'todo';
        $pass = env('DB_PASSWORD') ?? env('RDS_PASSWORD') ?? '';

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        // TLS verso RDS: attivo solo se è impostata DB_SSL_CA
        // (in locale con Docker Compose la variabile non c'è e la connessione resta in chiaro)
        $ca = env('DB_SSL_CA');
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

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

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