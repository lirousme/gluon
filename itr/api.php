<?php
/**
 * Backend API para o Analisador de Balanços ITR
 * Gerencia pastas e o arquivo JSON de catálogo sem necessidade de banco de dados.
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$empresasDir = __DIR__ . '/empresas';
$manifestFile = $empresasDir . '/manifest.json';

// Garante que a pasta empresas existe
if (!file_exists($empresasDir)) {
    mkdir($empresasDir, 0755, true);
}

function getManifest($manifestFile) {
    if (file_exists($manifestFile)) {
        $content = file_get_contents($manifestFile);
        $data = json_decode($content, true);
        if (is_array($data)) return $data;
    }
    return [];
}

function saveManifest($manifestFile, $data) {
    file_put_contents($manifestFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// GET: Retorna o catálogo de empresas e arquivos
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'get_companies') {
    echo json_encode(['success' => true, 'data' => getManifest($manifestFile)]);
    exit;
}

// POST: Cadastra uma nova Empresa e cria sua pasta
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'add_company') {
    $input = json_decode(file_get_contents('php://input'), true);
    $cvmCode = str_pad(trim($input['cvm_code'] ?? ''), 6, '0', STR_PAD_LEFT);
    $name = trim($input['name'] ?? '');

    if (!$cvmCode || !$name) {
        echo json_encode(['success' => false, 'message' => 'Código CVM e Nome são obrigatórios.']);
        exit;
    }

    // Cria a pasta da empresa se não existir
    $targetDir = $empresasDir . '/' . $cvmCode;
    if (!file_exists($targetDir)) {
        mkdir($targetDir, 0755, true);
    }

    $manifest = getManifest($manifestFile);
    if (!isset($manifest[$cvmCode])) {
        $manifest[$cvmCode] = [
            'name' => $name,
            'files' => []
        ];
    } else {
        $manifest[$cvmCode]['name'] = $name;
    }

    saveManifest($manifestFile, $manifest);
    echo json_encode(['success' => true, 'data' => $manifest, 'message' => "Empresa $name cadastrada com sucesso!"]);
    exit;
}

// POST: Salva o XML no disco e atualiza o JSON
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'upload_xml') {
    $cvmCode = str_pad(trim($_POST['cvm_code'] ?? ''), 6, '0', STR_PAD_LEFT);
    $filename = trim($_POST['filename'] ?? '');
    $label = trim($_POST['label'] ?? '');
    $companyName = trim($_POST['company_name'] ?? '');
    $xmlContent = $_POST['xml_content'] ?? '';

    if (!$cvmCode || !$filename || !$xmlContent) {
        echo json_encode(['success' => false, 'message' => 'Dados incompletos para upload.']);
        exit;
    }

    // Garante que a pasta da empresa existe
    $targetDir = $empresasDir . '/' . $cvmCode;
    if (!file_exists($targetDir)) {
        mkdir($targetDir, 0755, true);
    }

    // Salva o arquivo XML no servidor
    $filePath = $targetDir . '/' . $filename;
    file_put_contents($filePath, $xmlContent);

    // Atualiza o manifest.json
    $manifest = getManifest($manifestFile);
    if (!isset($manifest[$cvmCode])) {
        $manifest[$cvmCode] = [
            'name' => $companyName ?: "Empresa CVM $cvmCode",
            'files' => []
        ];
    }

    // Adiciona ou atualiza o arquivo no catálogo
    $exists = false;
    foreach ($manifest[$cvmCode]['files'] as &$f) {
        if ($f['name'] === $filename) {
            $f['label'] = $label;
            $exists = true;
            break;
        }
    }

    if (!$exists) {
        $manifest[$cvmCode]['files'][] = [
            'name' => $filename,
            'label' => $label
        ];
    }

    saveManifest($manifestFile, $manifest);
    echo json_encode(['success' => true, 'data' => $manifest, 'message' => 'Arquivo XML salvo no servidor e registrado no catálogo!']);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Ação não reconhecida.']);
