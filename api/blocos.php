<?php
// Arquivo: blocos.php
// Diretório: public_html/gluon/api/blocos.php

require_once BASE_PATH . '/config/database.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Não autorizado. Faça login.']);
    exit;
}

$pdo = Database::getConnection();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    if ($method === 'GET') {
        $stmt = $pdo->query('SELECT id, nome_pt_br FROM blocos ORDER BY id DESC');
        $rows = $stmt->fetchAll();

        echo json_encode(['status' => 'success', 'data' => $rows]);
        exit;
    }

    if ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $nomePtBr = trim((string)($input['nome_pt_br'] ?? ''));

        if ($nomePtBr === '') {
            http_response_code(422);
            echo json_encode(['status' => 'error', 'message' => 'O campo nome_pt_br é obrigatório.']);
            exit;
        }

        $stmt = $pdo->prepare('INSERT INTO blocos (nome_pt_br) VALUES (:nome_pt_br)');
        $stmt->execute([':nome_pt_br' => $nomePtBr]);

        echo json_encode([
            'status' => 'success',
            'data' => [
                'id' => (int)$pdo->lastInsertId(),
                'nome_pt_br' => $nomePtBr,
            ],
        ]);
        exit;
    }

    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Método não permitido.']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Erro interno ao processar blocos.']);
}
