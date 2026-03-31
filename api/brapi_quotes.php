<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';

$defaultTickers = [
    'LEVE3','BBSE3','BRAP3','ITSA3','ITSA4','WIZC3','BRSR6','BRAP4','SANB11','ITUB3','ITUB4','TAEE3','TAEE11','ODPV3','TAEE4','TIMS3','BBDC3','VIVT3','CPFE3','BRSR3','B3SA3','ABCB4','BRSR5','CXSE3','BBDC4','FLRY3','VALE3','ISAE4','CMIG4','PSSA3','ABEV3','CPLE11','ISAE3','SANB4','UNIP6','UNIP3','HYPE3','KLBN11','BBAS3','UNIP5','SANB3','BMEB3','SAPR11','MYPK3','TUPY3','SLCE3','BMEB4','KLBN3','KLBN4','EGIE3','SAPR4','CMIG3','BPAC5','ALUP11','UGPA3','AURE3','SAPR3','BPAC11','VBBR3','ALUP4','NEOE3','SBSP3','ALUP3','ENGI11','CSMG3','ENGI4','EQTL3','ENGI3','MEGA3','BPAC3','AESB3','SRNA3','CCRO3','FESA3','FESA4','CLSC3','CPLE3','ROMI3','CGAS3','CGAS5','CLSC4','BRIV3','BRIV4'
];

$allModules = [
    'summaryProfile',
    'balanceSheetHistory',
    'balanceSheetHistoryQuarterly',
    'defaultKeyStatistics',
    'defaultKeyStatisticsHistory',
    'defaultKeyStatisticsHistoryQuarterly',
    'incomeStatementHistory',
    'incomeStatementHistoryQuarterly',
    'financialData',
    'financialDataHistory',
    'financialDataHistoryQuarterly',
    'valueAddedHistory',
    'valueAddedHistoryQuarterly',
    'cashflowHistory',
    'cashflowHistoryQuarterly'
];

$token = envValue('TOKEN_BRAPI', '');
if ($token === '') {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'TOKEN_BRAPI não configurado no arquivo .env.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$tickersParam = $_GET['tickers'] ?? implode(',', $defaultTickers);
$rawTickers = preg_split('/[,\s]+/', strtoupper($tickersParam));
$tickers = array_values(array_unique(array_filter($rawTickers, static function ($ticker) {
    return preg_match('/^[A-Z0-9]{4,6}$/', $ticker) === 1;
})));

if (count($tickers) === 0) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => 'Informe ao menos um ticker válido.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$allDataParam = strtolower((string) ($_GET['all_data'] ?? '1'));
$allDataEnabled = in_array($allDataParam, ['1', 'true', 'yes', 'on'], true);

$queryParams = [
    'token' => $token,
];

if ($allDataEnabled) {
    $queryParams['fundamental'] = 'true';
    $queryParams['dividends'] = 'true';
    $queryParams['modules'] = implode(',', $allModules);
}

$endpoint = 'https://brapi.dev/api/quote/' . implode(',', $tickers) . '?' . http_build_query($queryParams);

$ch = curl_init($endpoint);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_HTTPHEADER => ['Accept: application/json']
]);

$body = curl_exec($ch);
$curlError = curl_error($ch);
$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($body === false) {
    http_response_code(502);
    echo json_encode([
        'status' => 'error',
        'message' => 'Falha ao conectar com a brapi.',
        'details' => $curlError
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$data = json_decode($body, true);
if (!is_array($data)) {
    http_response_code(502);
    echo json_encode([
        'status' => 'error',
        'message' => 'Resposta inválida da brapi.',
        'raw' => $body
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code($httpCode > 0 ? $httpCode : 200);
echo json_encode([
    'status' => $httpCode >= 400 ? 'error' : 'ok',
    'request_count' => 1,
    'tickers_requested' => $tickers,
    'all_data' => $allDataEnabled,
    'enabled_modules' => $allDataEnabled ? $allModules : [],
    'endpoint' => $endpoint,
    'brapi' => $data
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
