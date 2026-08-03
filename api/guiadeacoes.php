<?php
require_once BASE_PATH . '/config/database.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Não autorizado. Faça login.']);
    exit;
}

$spreadsheetColumns = preg_split('/\s+/', trim('sigla setor cotacao pt_projetivo pt_medio preco_consenso part_ibov val_mercado vol_med_3m retorno_ano retorno_12m_prov retorno_12m_sem_prov retorno_36m_prov retorno_36m_sem_prov retorno_60m_prov retorno_60m_sem_prov dpa_medio dy_medio dy_12m prov_p_acao_12m dt_ultimo_prov projecao_div dy_projetivo dif_mercado_pt_projetivo pm_pt_projetivo payout_medio projecao_lucro qtde_acoes projecao_lpa vpa p_vpa p_l ev ev_ebitda ebitda div_bruta div_liquida caixa div_liquida_ebitda lucro_liquido lpa margem_ebitda margem_liquida roe roic ev_ebit market_value_at psr market_value_acpc market_value_ac_pc_pnc fcfpa fcfy div_liqui_pl div_liqui_ebitda lc margem_bruta_pct roa cagr_receita cagr_lucro cagr_div cotacao_fechamento'));
$manualColumns = [
    'free_float',
    'nome_da_empresa',
    'ativos',
    'ativos_circ',
    'freq_div',
    'datas_resultados',
    'meses_mdi',
    'datas_assembleias',
    'pauta_assembleias',
    'datas_conselhos',
];
$columns = array_merge($spreadsheetColumns, $manualColumns);
$pdo = Database::getConnection();

function customColumns(PDO $pdo): array {
    try {
        return $pdo->query('SELECT nome, rotulo, tipo FROM guia_de_acoes_colunas ORDER BY criado_em, nome')->fetchAll();
    } catch (Throwable $error) {
        return [];
    }
}

function normalizeColumnName(string $value): string {
    $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
    $value = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '_', $value), '_'));
    return $value;
}

function failUpload(int $code, string $message): void {
    http_response_code($code);
    echo json_encode(['status' => 'error', 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

function columnIndex(string $reference): int {
    preg_match('/^[A-Z]+/i', $reference, $matches);
    $index = 0;
    foreach (str_split(strtoupper($matches[0] ?? 'A')) as $letter) {
        $index = ($index * 26) + ord($letter) - 64;
    }
    return $index - 1;
}

function readXlsx(string $path): array {
    if (!class_exists('ZipArchive')) {
        failUpload(500, 'A extensão PHP ZipArchive é necessária para ler arquivos .xlsx.');
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        failUpload(422, 'Não foi possível abrir a planilha .xlsx.');
    }
    $shared = [];
    $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($sharedXml !== false) {
        $xml = simplexml_load_string($sharedXml);
        foreach ($xml->si ?? [] as $item) {
            $texts = $item->xpath('.//*[local-name()="t"]');
            $shared[] = implode('', array_map('strval', $texts ?: []));
        }
    }
    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    if ($sheetXml === false) {
        failUpload(422, 'A primeira aba da planilha não foi encontrada.');
    }
    $xml = simplexml_load_string($sheetXml);
    $rows = [];
    foreach ($xml->xpath('//*[local-name()="sheetData"]/*[local-name()="row"]') ?: [] as $row) {
        $values = [];
        foreach ($row->xpath('./*[local-name()="c"]') ?: [] as $cell) {
            $attributes = $cell->attributes();
            $index = columnIndex((string)$attributes['r']);
            $type = (string)$attributes['t'];
            $raw = (string)($cell->v ?? '');
            if ($type === 's') {
                $raw = $shared[(int)$raw] ?? '';
            } elseif ($type === 'inlineStr') {
                $texts = $cell->xpath('.//*[local-name()="t"]');
                $raw = implode('', array_map('strval', $texts ?: []));
            }
            $values[$index] = trim($raw);
        }
        if ($values !== []) {
            $rows[] = $values;
        }
    }
    return $rows;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $rows = $pdo->query('SELECT * FROM guia_de_acoes WHERE cotacao IS NOT NULL AND cotacao <> 0 AND vpa IS NOT NULL AND vpa <> 0 AND pt_medio IS NOT NULL AND pt_medio <> 0 ORDER BY sigla')->fetchAll();
    echo json_encode(['status' => 'success', 'data' => $rows, 'custom_columns' => customColumns($pdo)], JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'DELETE') {
    $input = json_decode(file_get_contents('php://input') ?: '{}', true);
    $name = (string)($input['name'] ?? '');
    $custom = array_column(customColumns($pdo), null, 'nome');
    if (!isset($custom[$name])) {
        failUpload(422, 'Somente colunas manuais criadas nesta tela podem ser excluídas. Colunas do sistema e usadas em cálculos são protegidas.');
    }
    try {
        $pdo->exec("ALTER TABLE guia_de_acoes DROP COLUMN `{$name}`");
        $statement = $pdo->prepare('DELETE FROM guia_de_acoes_colunas WHERE nome = ?');
        $statement->execute([$name]);
    } catch (Throwable $error) {
        failUpload(409, 'Não foi possível excluir a coluna. Ela pode estar sendo usada por outro recurso.');
    }
    echo json_encode(['status' => 'success', 'message' => 'Coluna excluída.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'PATCH') {
    $input = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($input)) {
        failUpload(400, 'Dados inválidos para a atualização.');
    }

    if (($input['action'] ?? '') === 'rename_column') {
        $name = (string)($input['name'] ?? '');
        $label = trim((string)($input['label'] ?? ''));
        if ($label === '' || mb_strlen($label) > 120) failUpload(422, 'Informe um nome de exibição com até 120 caracteres.');
        $statement = $pdo->prepare('UPDATE guia_de_acoes_colunas SET rotulo = ? WHERE nome = ?');
        $statement->execute([$label, $name]);
        if (!$statement->rowCount()) {
            $statement = $pdo->prepare('SELECT 1 FROM guia_de_acoes_colunas WHERE nome = ?');
            $statement->execute([$name]);
            if (!$statement->fetchColumn()) failUpload(422, 'Somente colunas manuais criadas nesta tela podem ser renomeadas.');
        }
        echo json_encode(['status' => 'success', 'message' => 'Nome de exibição atualizado.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $custom = customColumns($pdo);
    $columns = array_merge($columns, array_column($custom, 'nome'));
    $ticker = strtoupper(trim((string)($input['sigla'] ?? '')));
    $column = (string)($input['field'] ?? '');
    if (!preg_match('/^[A-Z0-9.\-]{2,20}$/', $ticker)) {
        failUpload(422, 'A sigla informada é inválida.');
    }
    if (!in_array($column, $columns, true)) {
        failUpload(422, 'Esta coluna não pode ser editada manualmente.');
    }

    $value = trim((string)($input['value'] ?? ''));
    if ($column === 'sigla') {
        $value = strtoupper($value);
        if (!preg_match('/^[A-Z0-9.\-]{2,20}$/', $value)) {
            failUpload(422, 'A nova sigla é inválida.');
        }
    } elseif (in_array($column, array_merge(['setor', 'nome_da_empresa', 'freq_div', 'datas_resultados', 'meses_mdi', 'datas_assembleias', 'pauta_assembleias', 'datas_conselhos'], array_column(array_filter($custom, static fn($item) => $item['tipo'] === 'texto'), 'nome')), true)) {
        $value = $value === '' ? null : $value;
    } elseif ($column === 'dt_ultimo_prov') {
        if ($value === '') {
            $value = null;
        } else {
            $date = DateTime::createFromFormat('!Y-m-d', $value);
            if (!$date || $date->format('Y-m-d') !== $value) {
                failUpload(422, 'Informe a data no formato AAAA-MM-DD.');
            }
        }
    } else {
        $value = $value === '' ? null : str_replace(',', '.', $value);
        if ($value !== null && !is_numeric($value)) {
            failUpload(422, 'Informe um valor numérico válido.');
        }
    }

    try {
        $statement = $pdo->prepare("UPDATE guia_de_acoes SET {$column} = ? WHERE sigla = ?");
        $statement->execute([$value, $ticker]);
    } catch (Throwable $error) {
        failUpload(409, 'Não foi possível atualizar o valor. Verifique se ele já está em uso.');
    }
    if ($statement->rowCount() === 0) {
        $exists = $pdo->prepare('SELECT 1 FROM guia_de_acoes WHERE sigla = ?');
        $exists->execute([$ticker]);
        if (!$exists->fetchColumn()) {
            failUpload(404, 'Empresa não encontrada.');
        }
    }

    echo json_encode(['status' => 'success', 'message' => 'Valor atualizado.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    failUpload(405, 'Método não suportado.');
}
if (str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) {
    $input = json_decode(file_get_contents('php://input') ?: '{}', true);
    $label = trim((string)($input['label'] ?? ''));
    $name = normalizeColumnName((string)($input['name'] ?? $label));
    $type = (string)($input['type'] ?? 'texto');
    if ($label === '' || mb_strlen($label) > 120 || !preg_match('/^[a-z][a-z0-9_]{1,62}$/', $name)) failUpload(422, 'Informe um nome válido (mínimo de 2 caracteres).');
    if (!in_array($type, ['texto', 'numero'], true)) failUpload(422, 'Tipo de coluna inválido.');
    if (in_array($name, $columns, true) || in_array($name, array_column(customColumns($pdo), 'nome'), true)) failUpload(409, 'Já existe uma coluna com esse identificador.');
    try {
        $sqlType = $type === 'numero' ? 'DECIMAL(24, 8)' : 'TEXT';
        $pdo->exec("ALTER TABLE guia_de_acoes ADD COLUMN `{$name}` {$sqlType} NULL");
        $statement = $pdo->prepare('INSERT INTO guia_de_acoes_colunas (nome, rotulo, tipo) VALUES (?, ?, ?)');
        $statement->execute([$name, $label, $type]);
    } catch (Throwable $error) {
        try { $pdo->exec("ALTER TABLE guia_de_acoes DROP COLUMN `{$name}`"); } catch (Throwable $ignored) {}
        failUpload(409, 'Não foi possível criar a coluna. Verifique se o nome já está em uso.');
    }
    echo json_encode(['status' => 'success', 'message' => 'Coluna manual criada.', 'column' => ['nome' => $name, 'rotulo' => $label, 'tipo' => $type]], JSON_UNESCAPED_UNICODE);
    exit;
}
if (!isset($_FILES['planilha']) || ($_FILES['planilha']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    failUpload(422, 'Selecione uma planilha .xlsx válida.');
}
if (($_FILES['planilha']['size'] ?? 0) > 10 * 1024 * 1024 || strtolower(pathinfo($_FILES['planilha']['name'] ?? '', PATHINFO_EXTENSION)) !== 'xlsx') {
    failUpload(422, 'O arquivo deve ser .xlsx e ter no máximo 10 MB.');
}

$rows = readXlsx($_FILES['planilha']['tmp_name']);
if ($rows === []) {
    failUpload(422, 'A planilha está vazia.');
}
$header = array_map(static fn($value) => strtolower(trim((string)$value)), $rows[0]);
$positions = [];
foreach ($spreadsheetColumns as $column) {
    $position = array_search($column, $header, true);
    if ($position === false) {
        failUpload(422, "Coluna obrigatória ausente: {$column}.");
    }
    $positions[$column] = $position;
}

$placeholders = implode(', ', array_fill(0, count($spreadsheetColumns), '?'));
// O setor pertence ao cadastro da empresa: uma importacao pode defini-lo na
// inclusao, mas nunca deve sobrescreve-lo ao atualizar indicadores existentes.
$updateColumns = array_values(array_diff($spreadsheetColumns, ['sigla', 'setor']));
$updates = implode(', ', array_map(static fn($column) => "{$column} = VALUES({$column})", $updateColumns));
$sql = 'INSERT INTO guia_de_acoes (' . implode(', ', $spreadsheetColumns) . ") VALUES ({$placeholders}) ON DUPLICATE KEY UPDATE {$updates}";
$statement = $pdo->prepare($sql);
$inserted = 0;
$updated = 0;
$ignored = 0;
$pdo->beginTransaction();
try {
    foreach (array_slice($rows, 1) as $row) {
        $ticker = strtoupper(trim((string)($row[$positions['sigla']] ?? '')));
        if ($ticker === '' || !preg_match('/^[A-Z0-9.\-]{2,20}$/', $ticker)) {
            $ignored++;
            continue;
        }
        $exists = $pdo->prepare('SELECT 1 FROM guia_de_acoes WHERE sigla = ?');
        $exists->execute([$ticker]);
        $values = [];
        foreach ($spreadsheetColumns as $column) {
            $value = trim((string)($row[$positions[$column]] ?? ''));
            if ($column === 'sigla') {
                $value = $ticker;
            } elseif ($column === 'setor') {
                $value = $value === '' ? null : $value;
            } elseif ($column === 'dt_ultimo_prov') {
                if ($value === '') {
                    $value = null;
                } elseif (is_numeric($value)) {
                    $value = gmdate('Y-m-d', (int)(($value - 25569) * 86400));
                } else {
                    $date = DateTime::createFromFormat('d/m/Y', $value);
                    $value = $date ? $date->format('Y-m-d') : null;
                }
            } else {
                $value = $value === '' ? null : str_replace(',', '.', $value);
                if ($value !== null && !is_numeric($value)) {
                    $value = null;
                }
            }
            $values[] = $value;
        }
        $statement->execute($values);
        $exists->fetchColumn() ? $updated++ : $inserted++;
    }
    $pdo->commit();
} catch (Throwable $error) {
    $pdo->rollBack();
    failUpload(500, 'Falha ao importar a planilha. Nenhum registro foi alterado.');
}

echo json_encode(['status' => 'success', 'message' => 'Importação concluída.', 'inserted' => $inserted, 'updated' => $updated, 'ignored' => $ignored], JSON_UNESCAPED_UNICODE);
