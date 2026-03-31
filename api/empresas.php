<?php
header('Content-Type: application/json; charset=utf-8');

$filePath = __DIR__ . '/../acoes/empresas.json';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$maxEmpresas = 20;

function normTicker(string $ticker): string
{
    return preg_replace('/[^A-Z0-9]/', '', strtoupper(trim($ticker))) ?? '';
}

function sanitizeLista(array $lista, int $maxEmpresas): array
{
    $map = [];
    foreach ($lista as $item) {
        if (!is_array($item)) {
            continue;
        }
        $ticker = normTicker((string)($item['ticker'] ?? ''));
        if ($ticker === '') {
            continue;
        }
        if (!isset($map[$ticker])) {
            $map[$ticker] = [
                'ticker' => $ticker,
                'nome' => trim((string)($item['nome'] ?? ''))
            ];
        }
    }

    $result = array_values($map);
    usort($result, static fn(array $a, array $b): int => strcmp($a['ticker'], $b['ticker']));
    return array_slice($result, 0, $maxEmpresas);
}

if (!file_exists($filePath)) {
    file_put_contents($filePath, "[]\n");
}

if ($method === 'GET') {
    $raw = file_get_contents($filePath);
    $data = json_decode($raw ?: '[]', true);
    if (!is_array($data)) {
        $data = [];
    }
    echo json_encode([
        'status' => 'ok',
        'empresas' => sanitizeLista($data, $maxEmpresas),
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

    $lista = sanitizeLista($payload['empresas'] ?? [], $maxEmpresas);
    $saved = json_encode($lista, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($saved === false || file_put_contents($filePath, $saved . PHP_EOL, LOCK_EX) === false) {
        http_response_code(500);
        echo json_encode([
            'status' => 'error',
            'message' => 'Não foi possível salvar empresas.json.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode([
        'status' => 'ok',
        'empresas' => $lista
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

http_response_code(405);
echo json_encode([
    'status' => 'error',
    'message' => 'Método não suportado.'
], JSON_UNESCAPED_UNICODE);
