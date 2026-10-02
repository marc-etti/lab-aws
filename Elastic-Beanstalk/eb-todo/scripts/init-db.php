<?php
declare(strict_types=1);

$get = fn(string ...$keys) => array_values(array_filter(
    array_map(fn($k) => getenv($k) ?: null, $keys)))[0] ?? null;

$host = $get('DB_HOST', 'RDS_HOSTNAME');
$port = $get('DB_PORT', 'RDS_PORT') ?? '3306';
$name = $get('DB_NAME', 'RDS_DB_NAME');
$user = $get('DB_USER', 'RDS_USERNAME');
$pass = $get('DB_PASSWORD', 'RDS_PASSWORD') ?? '';

$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
$ca = $get('DB_SSL_CA');
if ($ca) {
    $options[PDO::MYSQL_ATTR_SSL_CA] = $ca;   // TLS con verifica del certificato
}

$pdo = new PDO(
    "mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4",
    $user, $pass, $options
);

$sql = file_get_contents(__DIR__ . '/../db/init.sql');
foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
    $pdo->exec($stmt);
}
echo "Schema applicato su $host/$name\n";