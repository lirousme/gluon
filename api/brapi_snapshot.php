<?php
header('Content-Type: application/json; charset=utf-8');

$baseDir = __DIR__ . '/../acoes/brapi_empresas';
$dbPath = $baseDir . '/brapi_snapshot.sqlite';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

function normTicker(string $ticker): string
{
    return preg_replace('/[^A-Z0-9]/', '', strtoupper(trim($ticker))) ?? '';
}

function toTimestampMs($value): ?int
{
    if ($value === null || $value === '') {
        return null;
    }

    if (is_numeric($value)) {
        $numeric = (float)$value;
        if ($numeric <= 0) {
            return null;
        }
        return $numeric < 1000000000000 ? (int)round($numeric * 1000) : (int)round($numeric);
    }

    if (is_string($value)) {
        $asTime = strtotime($value);
        if ($asTime === false || $asTime <= 0) {
            return null;
        }
        return (int)$asTime * 1000;
    }

    return null;
}

function respondError(int $status, string $message): void
{
    http_response_code($status);
    echo json_encode([
        'status' => 'error',
        'message' => $message,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function ensureStore(string $baseDir, string $dbPath): PDO
{
    if (!is_dir($baseDir) && !mkdir($baseDir, 0775, true) && !is_dir($baseDir)) {
        respondError(500, 'Não foi possível criar diretório do snapshot.');
    }

    try {
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA synchronous = NORMAL');
        $pdo->exec('PRAGMA foreign_keys = ON');

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS snapshots (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                fetched_at TEXT NOT NULL,
                request_meta_json TEXT,
                raw_json TEXT,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS quote_companies (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                snapshot_id INTEGER NOT NULL,
                ticker TEXT NOT NULL,
                short_name TEXT,
                long_name TEXT,
                currency TEXT,
                regular_market_price REAL,
                regular_market_change REAL,
                regular_market_change_percent REAL,
                regular_market_volume INTEGER,
                regular_market_time TEXT,
                market_cap REAL,
                regular_market_open REAL,
                regular_market_previous_close REAL,
                fifty_two_week_low REAL,
                fifty_two_week_high REAL,
                price_earnings REAL,
                earnings_per_share REAL,
                payload_json TEXT NOT NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(snapshot_id, ticker),
                FOREIGN KEY(snapshot_id) REFERENCES snapshots(id) ON DELETE CASCADE
            )'
        );

        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_quote_companies_snapshot_ticker ON quote_companies(snapshot_id, ticker)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_quote_companies_ticker ON quote_companies(ticker)');

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS quote_volume_history (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                ticker TEXT NOT NULL,
                regular_market_time INTEGER NOT NULL,
                regular_market_volume INTEGER NOT NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(ticker, regular_market_time, regular_market_volume)
            )'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS quote_price_history (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                ticker TEXT NOT NULL,
                regular_market_time INTEGER NOT NULL,
                regular_market_day_high REAL NOT NULL,
                regular_market_day_low REAL NOT NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(ticker, regular_market_time, regular_market_day_high, regular_market_day_low)
            )'
        );

        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_quote_volume_history_ticker_time ON quote_volume_history(ticker, regular_market_time)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_quote_price_history_ticker_time ON quote_price_history(ticker, regular_market_time)');

        return $pdo;
    } catch (Throwable $e) {
        respondError(500, 'Falha ao inicializar SQLite: ' . $e->getMessage());
    }
}

function fetchTickerHistory(PDO $pdo, string $ticker): array
{
    $volumeStmt = $pdo->prepare(
        'SELECT regular_market_time, regular_market_volume
         FROM quote_volume_history
         WHERE ticker = :ticker
         ORDER BY regular_market_time ASC
         LIMIT 5000'
    );
    $volumeStmt->execute([':ticker' => $ticker]);
    $volumeHistory = [];
    while ($row = $volumeStmt->fetch()) {
        $volumeHistory[] = [
            'timestamp' => (int)$row['regular_market_time'],
            'volume' => (int)$row['regular_market_volume'],
        ];
    }

    $priceStmt = $pdo->prepare(
        'SELECT regular_market_time, regular_market_day_high, regular_market_day_low
         FROM quote_price_history
         WHERE ticker = :ticker
         ORDER BY regular_market_time ASC
         LIMIT 5000'
    );
    $priceStmt->execute([':ticker' => $ticker]);
    $priceHistory = [];
    while ($row = $priceStmt->fetch()) {
        $high = (float)$row['regular_market_day_high'];
        $low = (float)$row['regular_market_day_low'];
        $priceHistory[] = [
            'timestamp' => (int)$row['regular_market_time'],
            'high' => $high,
            'low' => $low,
            'avg' => ($high + $low) / 2,
        ];
    }

    return [
        'volume' => $volumeHistory,
        'price' => $priceHistory,
    ];
}

function fetchLatestSnapshot(PDO $pdo, string $tickerFilter = ''): ?array
{
    $snapshotStmt = $pdo->query('SELECT * FROM snapshots ORDER BY id DESC LIMIT 1');
    $snapshot = $snapshotStmt->fetch();
    if (!$snapshot) {
        return null;
    }

    if ($tickerFilter !== '') {
        $companyStmt = $pdo->prepare(
            'SELECT qc.ticker, qc.payload_json
             FROM quote_companies qc
             WHERE qc.ticker = :ticker
             AND qc.snapshot_id = (
                 SELECT MAX(snapshot_id)
                 FROM quote_companies
                 WHERE ticker = :ticker
             )
             LIMIT 1'
        );
        $companyStmt->execute([':ticker' => $tickerFilter]);
    } else {
        $companyStmt = $pdo->query(
            'SELECT qc.ticker, qc.payload_json
             FROM quote_companies qc
             INNER JOIN (
                 SELECT ticker, MAX(snapshot_id) AS max_snapshot_id
                 FROM quote_companies
                 GROUP BY ticker
             ) latest ON latest.ticker = qc.ticker AND latest.max_snapshot_id = qc.snapshot_id
             ORDER BY qc.ticker'
        );
    }

    $resultsByTicker = [];
    $tickers = [];
    while ($row = $companyStmt->fetch()) {
        $ticker = normTicker((string)($row['ticker'] ?? ''));
        if ($ticker === '') {
            continue;
        }
        $payload = json_decode((string)$row['payload_json'], true);
        if (!is_array($payload)) {
            continue;
        }
        $resultsByTicker[$ticker] = $payload;
        $tickers[] = $ticker;
    }

    $requestMeta = json_decode((string)($snapshot['request_meta_json'] ?? '{}'), true);
    $raw = json_decode((string)($snapshot['raw_json'] ?? 'null'), true);

    return [
        'id' => (int)$snapshot['id'],
        'fetchedAt' => $snapshot['fetched_at'],
        'tickers' => array_values(array_unique($tickers)),
        'requestMeta' => is_array($requestMeta) ? $requestMeta : [],
        'raw' => $raw,
        'resultsByTicker' => $resultsByTicker,
    ];
}

function isAssociativeArray(array $value): bool
{
    if ($value === []) {
        return false;
    }
    return array_keys($value) !== range(0, count($value) - 1);
}

function isMissingValue($value): bool
{
    if ($value === null) {
        return true;
    }

    if (is_string($value)) {
        return trim($value) === '';
    }

    if (is_array($value)) {
        return count($value) === 0;
    }

    return false;
}

function mergeTickerPayload(array $incoming, array $fallback): array
{
    $merged = $incoming;
    foreach ($fallback as $key => $fallbackValue) {
        if (!array_key_exists($key, $incoming) || isMissingValue($incoming[$key])) {
            $merged[$key] = $fallbackValue;
            continue;
        }

        $incomingValue = $incoming[$key];
        if (is_array($incomingValue) && is_array($fallbackValue) && isAssociativeArray($incomingValue) && isAssociativeArray($fallbackValue)) {
            $merged[$key] = mergeTickerPayload($incomingValue, $fallbackValue);
        }
    }
    return $merged;
}

$pdo = ensureStore($baseDir, $dbPath);

if ($method === 'GET') {
    try {
        $tickerParam = normTicker((string)($_GET['ticker'] ?? ''));
        $snapshot = fetchLatestSnapshot($pdo, $tickerParam);
        if ($snapshot === null) {
            echo json_encode(['status' => 'ok', 'snapshot' => null], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        $includeHistory = ($_GET['includeHistory'] ?? '') === '1';
        if ($tickerParam !== '') {
            if (!isset($snapshot['resultsByTicker'][$tickerParam])) {
                respondError(404, "Ticker {$tickerParam} não encontrado no snapshot salvo.");
            }
            $snapshot['tickers'] = [$tickerParam];
            $snapshot['resultsByTicker'] = [
                $tickerParam => $snapshot['resultsByTicker'][$tickerParam],
            ];
            $includeHistory = true;
        }

        if ($includeHistory) {
            $snapshot['historyByTicker'] = [];
            foreach ($snapshot['tickers'] as $ticker) {
                $snapshot['historyByTicker'][$ticker] = fetchTickerHistory($pdo, (string)$ticker);
            }
        }

        unset($snapshot['id']);
        echo json_encode([
            'status' => 'ok',
            'snapshot' => $snapshot,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    } catch (Throwable $e) {
        respondError(500, 'Falha ao consultar snapshot no SQLite: ' . $e->getMessage());
    }
}

if ($method === 'POST') {
    $rawInput = file_get_contents('php://input') ?: '{}';
    $payload = json_decode($rawInput, true);
    if (!is_array($payload)) {
        respondError(400, 'Payload inválido.');
    }

    $snapshot = $payload['snapshot'] ?? null;
    if (!is_array($snapshot)) {
        respondError(400, 'Informe um objeto snapshot válido.');
    }

    $resultsByTicker = is_array($snapshot['resultsByTicker'] ?? null) ? $snapshot['resultsByTicker'] : [];
    if (empty($resultsByTicker)) {
        respondError(400, 'Nenhum resultado para salvar.');
    }

    $latestSnapshot = fetchLatestSnapshot($pdo);
    $previousByTicker = is_array($latestSnapshot['resultsByTicker'] ?? null) ? $latestSnapshot['resultsByTicker'] : [];

    foreach ($resultsByTicker as $ticker => $empresaData) {
        $tickerNorm = normTicker((string)$ticker);
        if ($tickerNorm === '') {
            continue;
        }

        $fallbackData = $previousByTicker[$tickerNorm] ?? null;
        if (is_array($empresaData) && is_array($fallbackData)) {
            $resultsByTicker[$ticker] = mergeTickerPayload($empresaData, $fallbackData);
            continue;
        }

        if ((!is_array($empresaData) || empty($empresaData)) && is_array($fallbackData)) {
            $resultsByTicker[$ticker] = $fallbackData;
        }
    }

    $snapshot['resultsByTicker'] = $resultsByTicker;

    $fetchedAt = (string)($snapshot['fetchedAt'] ?? gmdate('c'));
    $requestMetaJson = json_encode(
        is_array($snapshot['requestMeta'] ?? null) ? $snapshot['requestMeta'] : [],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    $rawJson = json_encode($snapshot['raw'] ?? null, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($requestMetaJson === false || $rawJson === false) {
        respondError(500, 'Não foi possível serializar metadados do snapshot.');
    }

    try {
        $pdo->beginTransaction();

        $insSnapshot = $pdo->prepare(
            'INSERT INTO snapshots (fetched_at, request_meta_json, raw_json) VALUES (:fetched_at, :request_meta_json, :raw_json)'
        );
        $insSnapshot->execute([
            ':fetched_at' => $fetchedAt,
            ':request_meta_json' => $requestMetaJson,
            ':raw_json' => $rawJson,
        ]);

        $snapshotId = (int)$pdo->lastInsertId();
        $insCompany = $pdo->prepare(
            'INSERT INTO quote_companies (
                snapshot_id, ticker, short_name, long_name, currency,
                regular_market_price, regular_market_change, regular_market_change_percent,
                regular_market_volume, regular_market_time, market_cap,
                regular_market_open, regular_market_previous_close,
                fifty_two_week_low, fifty_two_week_high,
                price_earnings, earnings_per_share, payload_json
            ) VALUES (
                :snapshot_id, :ticker, :short_name, :long_name, :currency,
                :regular_market_price, :regular_market_change, :regular_market_change_percent,
                :regular_market_volume, :regular_market_time, :market_cap,
                :regular_market_open, :regular_market_previous_close,
                :fifty_two_week_low, :fifty_two_week_high,
                :price_earnings, :earnings_per_share, :payload_json
            )'
        );
        $insVolume = $pdo->prepare(
            'INSERT OR IGNORE INTO quote_volume_history (ticker, regular_market_time, regular_market_volume)
             VALUES (:ticker, :regular_market_time, :regular_market_volume)'
        );
        $insPrice = $pdo->prepare(
            'INSERT OR IGNORE INTO quote_price_history (ticker, regular_market_time, regular_market_day_high, regular_market_day_low)
             VALUES (:ticker, :regular_market_time, :regular_market_day_high, :regular_market_day_low)'
        );

        $savedTickers = [];
        foreach ($resultsByTicker as $ticker => $empresaData) {
            $tickerNorm = normTicker((string)$ticker);
            if ($tickerNorm === '' || !is_array($empresaData) || empty($empresaData)) {
                continue;
            }

            $payloadJson = json_encode($empresaData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($payloadJson === false) {
                continue;
            }

            $insCompany->execute([
                ':snapshot_id' => $snapshotId,
                ':ticker' => $tickerNorm,
                ':short_name' => $empresaData['shortName'] ?? null,
                ':long_name' => $empresaData['longName'] ?? null,
                ':currency' => $empresaData['currency'] ?? null,
                ':regular_market_price' => $empresaData['regularMarketPrice'] ?? null,
                ':regular_market_change' => $empresaData['regularMarketChange'] ?? null,
                ':regular_market_change_percent' => $empresaData['regularMarketChangePercent'] ?? null,
                ':regular_market_volume' => $empresaData['regularMarketVolume'] ?? null,
                ':regular_market_time' => $empresaData['regularMarketTime'] ?? null,
                ':market_cap' => $empresaData['marketCap'] ?? null,
                ':regular_market_open' => $empresaData['regularMarketOpen'] ?? null,
                ':regular_market_previous_close' => $empresaData['regularMarketPreviousClose'] ?? null,
                ':fifty_two_week_low' => $empresaData['fiftyTwoWeekLow'] ?? null,
                ':fifty_two_week_high' => $empresaData['fiftyTwoWeekHigh'] ?? null,
                ':price_earnings' => $empresaData['priceEarnings'] ?? null,
                ':earnings_per_share' => $empresaData['earningsPerShare'] ?? null,
                ':payload_json' => $payloadJson,
            ]);

            $marketTime = toTimestampMs($empresaData['regularMarketTime'] ?? null);
            $volume = isset($empresaData['regularMarketVolume']) ? (int)$empresaData['regularMarketVolume'] : 0;
            $high = isset($empresaData['regularMarketDayHigh']) ? (float)$empresaData['regularMarketDayHigh'] : 0;
            $low = isset($empresaData['regularMarketDayLow']) ? (float)$empresaData['regularMarketDayLow'] : 0;

            if ($marketTime !== null && $volume > 0) {
                $insVolume->execute([
                    ':ticker' => $tickerNorm,
                    ':regular_market_time' => $marketTime,
                    ':regular_market_volume' => $volume,
                ]);
            }

            if ($marketTime !== null && $high > 0 && $low > 0) {
                $insPrice->execute([
                    ':ticker' => $tickerNorm,
                    ':regular_market_time' => $marketTime,
                    ':regular_market_day_high' => $high,
                    ':regular_market_day_low' => $low,
                ]);
            }

            $savedTickers[] = $tickerNorm;
        }

        if (count($savedTickers) === 0) {
            throw new RuntimeException('Nenhuma empresa válida foi salva no SQLite.');
        }

        // Mantém histórico curto para evitar crescimento infinito do arquivo.
        $pdo->exec('DELETE FROM snapshots WHERE id NOT IN (SELECT id FROM snapshots ORDER BY id DESC LIMIT 10)');

        $pdo->commit();

        echo json_encode([
            'status' => 'ok',
            'storage' => 'sqlite',
            'db_path' => 'acoes/brapi_empresas/brapi_snapshot.sqlite',
            'saved_snapshot_id' => $snapshotId,
            'saved_tickers' => array_values(array_unique($savedTickers)),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        respondError(500, 'Não foi possível salvar snapshot no SQLite: ' . $e->getMessage());
    }
}

respondError(405, 'Método não suportado.');
