<?php
header('Content-Type: application/json; charset=utf-8');

$filePath = __DIR__ . '/../acoes/brapi_snapshot.json';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

function ensureSnapshotFile(string $filePath): void
{
    if (!file_exists($filePath)) {
        file_put_contents($filePath, "{}\n");
    }
}

ensureSnapshotFile($filePath);

if ($method === 'GET') {
    $raw = file_get_contents($filePath);
    $snapshot = json_decode($raw ?: '{}', true);
    if (!is_array($snapshot) || empty($snapshot)) {
        $snapshot = null;
    }

    echo json_encode([
        'status' => 'ok',
        'snapshot' => $snapshot
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($method === 'POST') {
    $rawInput = file_get_contents('php://input') ?: '{}';
    $payload = json_decode($rawInput, true);
    if (!is_array($payload)) {
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'message' => 'Payload inválido.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $snapshot = $payload['snapshot'] ?? null;
    if (!is_array($snapshot) || empty($snapshot)) {
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'message' => 'Informe um objeto snapshot válido.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $saved = json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($saved === false || file_put_contents($filePath, $saved . PHP_EOL, LOCK_EX) === false) {
        http_response_code(500);
        echo json_encode([
            'status' => 'error',
            'message' => 'Não foi possível salvar brapi_snapshot.json.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode([
        'status' => 'ok'
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

http_response_code(405);
echo json_encode([
    'status' => 'error',
    'message' => 'Método não suportado.'
], JSON_UNESCAPED_UNICODE);
