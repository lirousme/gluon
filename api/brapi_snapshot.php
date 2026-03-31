<?php
header('Content-Type: application/json; charset=utf-8');

$baseDir = __DIR__ . '/../acoes/brapi_empresas';
$metaPath = $baseDir . '/meta.json';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

function normTicker(string $ticker): string
{
    return preg_replace('/[^A-Z0-9]/', '', strtoupper(trim($ticker))) ?? '';
}

function ensureStore(string $baseDir, string $metaPath): void
{
    if (!is_dir($baseDir)) {
        mkdir($baseDir, 0775, true);
    }
    if (!file_exists($metaPath)) {
        $initial = [
            'fetchedAt' => null,
            'tickers' => [],
            'requestMeta' => [],
            'raw' => null,
        ];
        file_put_contents(
            $metaPath,
            json_encode($initial, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL,
            LOCK_EX
        );
    }
}

function readJsonFile(string $path, array $fallback = []): array
{
    if (!file_exists($path)) {
        return $fallback;
    }
    $raw = file_get_contents($path);
    $data = json_decode($raw ?: '{}', true);
    return is_array($data) ? $data : $fallback;
}

function writeJsonFile(string $path, array $data): bool
{
    $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($encoded === false) {
        return false;
    }
    return file_put_contents($path, $encoded . PHP_EOL, LOCK_EX) !== false;
}

ensureStore($baseDir, $metaPath);

if ($method === 'GET') {
    $tickerParam = normTicker((string)($_GET['ticker'] ?? ''));
    $meta = readJsonFile($metaPath, []);

    if ($tickerParam !== '') {
        $tickerPath = $baseDir . '/' . $tickerParam . '.json';
        if (!file_exists($tickerPath)) {
            http_response_code(404);
            echo json_encode([
                'status' => 'error',
                'message' => "Ticker {$tickerParam} não encontrado no snapshot salvo.",
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        echo json_encode([
            'status' => 'ok',
            'snapshot' => [
                'fetchedAt' => $meta['fetchedAt'] ?? null,
                'tickers' => $meta['tickers'] ?? [],
                'requestMeta' => $meta['requestMeta'] ?? [],
                'raw' => $meta['raw'] ?? null,
                'resultsByTicker' => [
                    $tickerParam => readJsonFile($tickerPath, []),
                ],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $tickers = is_array($meta['tickers'] ?? null) ? $meta['tickers'] : [];
    $resultsByTicker = [];
    foreach ($tickers as $ticker) {
        $tickerNorm = normTicker((string)$ticker);
        if ($tickerNorm === '') {
            continue;
        }
        $tickerPath = $baseDir . '/' . $tickerNorm . '.json';
        if (file_exists($tickerPath)) {
            $resultsByTicker[$tickerNorm] = readJsonFile($tickerPath, []);
        }
    }

    $hasData = !empty($resultsByTicker) || !empty($meta['fetchedAt']);
    echo json_encode([
        'status' => 'ok',
        'snapshot' => $hasData ? [
            'fetchedAt' => $meta['fetchedAt'] ?? null,
            'tickers' => $tickers,
            'requestMeta' => $meta['requestMeta'] ?? [],
            'raw' => $meta['raw'] ?? null,
            'resultsByTicker' => $resultsByTicker,
        ] : null,
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
            'message' => 'Payload inválido.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $snapshot = $payload['snapshot'] ?? null;
    if (!is_array($snapshot)) {
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'message' => 'Informe um objeto snapshot válido.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $resultsByTicker = is_array($snapshot['resultsByTicker'] ?? null) ? $snapshot['resultsByTicker'] : [];
    $tickers = [];

    foreach ($resultsByTicker as $ticker => $empresaData) {
        $tickerNorm = normTicker((string)$ticker);
        if ($tickerNorm === '' || !is_array($empresaData) || empty($empresaData)) {
            continue;
        }
        $tickers[] = $tickerNorm;
        $tickerPath = $baseDir . '/' . $tickerNorm . '.json';
        if (!writeJsonFile($tickerPath, $empresaData)) {
            http_response_code(500);
            echo json_encode([
                'status' => 'error',
                'message' => "Não foi possível salvar {$tickerNorm}.json.",
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    $tickers = array_values(array_unique($tickers));
    sort($tickers);

    $meta = [
        'fetchedAt' => $snapshot['fetchedAt'] ?? gmdate('c'),
        'tickers' => $tickers,
        'requestMeta' => is_array($snapshot['requestMeta'] ?? null) ? $snapshot['requestMeta'] : [],
        'raw' => $snapshot['raw'] ?? null,
    ];

    if (!writeJsonFile($metaPath, $meta)) {
        http_response_code(500);
        echo json_encode([
            'status' => 'error',
            'message' => 'Não foi possível salvar meta do snapshot.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode([
        'status' => 'ok',
        'saved_tickers' => $tickers,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

http_response_code(405);
echo json_encode([
    'status' => 'error',
    'message' => 'Método não suportado.',
], JSON_UNESCAPED_UNICODE);
