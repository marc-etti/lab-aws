<?php
declare(strict_types=1);

header('Content-Type: application/json');

// Controllo "leggero": usato dall'ALB (non dipende dal database)
// Controllo "profondo": /health.php?deep=1 verifica anche la connessione al DB
if (isset($_GET['deep'])) {
    require __DIR__ . '/../src/config.php';
    try {
        db()->query('SELECT 1');
    } catch (Throwable $ex) {
        http_response_code(503);
        echo json_encode(['status' => 'error', 'db' => 'down']);
        exit;
    }
    echo json_encode(['status' => 'ok', 'db' => 'up']);
    exit;
}

echo json_encode(['status' => 'ok']);