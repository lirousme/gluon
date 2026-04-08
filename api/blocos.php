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
        $stmt = $pdo->query('SELECT id, nome_pt_br, ordem FROM blocos ORDER BY ordem ASC, id ASC');
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

        $stmtOrdem = $pdo->query('SELECT COALESCE(MAX(ordem), 0) + 1 AS proxima_ordem FROM blocos');
        $ordem = (int)($stmtOrdem->fetch()['proxima_ordem'] ?? 1);

        $stmt = $pdo->prepare('INSERT INTO blocos (nome_pt_br, ordem) VALUES (:nome_pt_br, :ordem)');
        $stmt->execute([
            ':nome_pt_br' => $nomePtBr,
            ':ordem' => $ordem,
        ]);

        echo json_encode([
            'status' => 'success',
            'data' => [
                'id' => (int)$pdo->lastInsertId(),
                'nome_pt_br' => $nomePtBr,
                'ordem' => $ordem,
            ],
        ]);
        exit;
    }

    if ($method === 'PUT') {
        $input = json_decode(file_get_contents('php://input'), true);
        $id = (int)($input['id'] ?? 0);
        $nomePtBr = trim((string)($input['nome_pt_br'] ?? ''));

        if ($id <= 0 || $nomePtBr === '') {
            http_response_code(422);
            echo json_encode(['status' => 'error', 'message' => 'Dados inválidos para atualizar bloco.']);
            exit;
        }

        $stmt = $pdo->prepare('UPDATE blocos SET nome_pt_br = :nome_pt_br WHERE id = :id');
        $stmt->execute([
            ':id' => $id,
            ':nome_pt_br' => $nomePtBr,
        ]);

        if ($stmt->rowCount() === 0) {
            http_response_code(404);
            echo json_encode(['status' => 'error', 'message' => 'Bloco não encontrado.']);
            exit;
        }

        echo json_encode([
            'status' => 'success',
            'data' => [
                'id' => $id,
                'nome_pt_br' => $nomePtBr,
            ],
        ]);
        exit;
    }

    if ($method === 'DELETE') {
        $input = json_decode(file_get_contents('php://input'), true);
        $id = (int)($input['id'] ?? 0);

        if ($id <= 0) {
            http_response_code(422);
            echo json_encode(['status' => 'error', 'message' => 'ID inválido para excluir bloco.']);
            exit;
        }

        $stmt = $pdo->prepare('DELETE FROM blocos WHERE id = :id');
        $stmt->execute([':id' => $id]);

        if ($stmt->rowCount() === 0) {
            http_response_code(404);
            echo json_encode(['status' => 'error', 'message' => 'Bloco não encontrado.']);
            exit;
        }

        echo json_encode(['status' => 'success']);
        exit;
    }

    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Método não permitido.']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Erro interno ao processar blocos.']);
}
