<?php

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
        $idBloco = isset($_GET['id_bloco']) ? (int)$_GET['id_bloco'] : 0;

        if ($idBloco <= 0) {
            http_response_code(422);
            echo json_encode(['status' => 'error', 'message' => 'Parâmetro id_bloco é obrigatório.']);
            exit;
        }

        $stmt = $pdo->prepare('SELECT id, id_bloco, texto, idioma, ordem FROM cards WHERE id_bloco = :id_bloco ORDER BY ordem ASC, id ASC');
        $stmt->execute([':id_bloco' => $idBloco]);
        $rows = $stmt->fetchAll();

        echo json_encode(['status' => 'success', 'data' => $rows]);
        exit;
    }

    if ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);

        $idBloco = (int)($input['id_bloco'] ?? 0);
        $texto = trim((string)($input['texto'] ?? ''));
        $idioma = strtolower(trim((string)($input['idioma'] ?? '')));
        $ordem = isset($input['ordem']) ? (int)$input['ordem'] : null;

        $idiomasPermitidos = ['pt-br', 'en-us', 'en-gb', 'fr-fr', 'es-es'];

        if ($idBloco <= 0 || $texto === '' || $idioma === '' || !in_array($idioma, $idiomasPermitidos, true)) {
            http_response_code(422);
            echo json_encode(['status' => 'error', 'message' => 'Dados inválidos para criar card.']);
            exit;
        }

        if ($ordem === null) {
            $stmtOrdem = $pdo->prepare('SELECT COALESCE(MAX(ordem), 0) + 1 AS proxima_ordem FROM cards WHERE id_bloco = :id_bloco');
            $stmtOrdem->execute([':id_bloco' => $idBloco]);
            $ordem = (int)($stmtOrdem->fetch()['proxima_ordem'] ?? 1);
        }

        $stmt = $pdo->prepare('INSERT INTO cards (id_bloco, texto, idioma, ordem) VALUES (:id_bloco, :texto, :idioma, :ordem)');
        $stmt->execute([
            ':id_bloco' => $idBloco,
            ':texto' => $texto,
            ':idioma' => $idioma,
            ':ordem' => $ordem,
        ]);

        echo json_encode([
            'status' => 'success',
            'data' => [
                'id' => (int)$pdo->lastInsertId(),
                'id_bloco' => $idBloco,
                'texto' => $texto,
                'idioma' => $idioma,
                'ordem' => $ordem,
            ],
        ]);
        exit;
    }

    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Método não permitido.']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Erro interno ao processar cards.']);
}
