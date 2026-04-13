<?php

require_once BASE_PATH . '/config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Não autorizado. Faça login.']);
    exit;
}

$userId = (int)$_SESSION['user_id'];
$pdo = Database::getConnection();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    if ($method === 'GET') {
        $directoryId = isset($_GET['id_diretorio']) ? (int)$_GET['id_diretorio'] : 0;
        if ($directoryId <= 0) {
            http_response_code(422);
            echo json_encode(['status' => 'error', 'message' => 'Parâmetro id_diretorio inválido.']);
            exit;
        }

        $stmtDir = $pdo->prepare('SELECT id FROM directories WHERE id = :id AND user_id = :user_id LIMIT 1');
        $stmtDir->execute([':id' => $directoryId, ':user_id' => $userId]);
        if (!$stmtDir->fetch()) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Diretório não encontrado ou sem permissão.']);
            exit;
        }

        $stmt = $pdo->prepare(
            'SELECT
                p.id,
                p.id_diretorio,
                p.id_card_um,
                p.id_card_dois,
                c1.id AS card_um_id,
                c1.texto AS card_um_texto,
                c1.idioma AS card_um_idioma,
                c2.id AS card_dois_id,
                c2.texto AS card_dois_texto,
                c2.idioma AS card_dois_idioma
            FROM pares p
            INNER JOIN cards c1 ON c1.id = p.id_card_um
            INNER JOIN cards c2 ON c2.id = p.id_card_dois
            WHERE p.id_diretorio = :id_diretorio
            ORDER BY p.id ASC'
        );
        $stmt->execute([':id_diretorio' => $directoryId]);

        $rows = $stmt->fetchAll();
        $data = array_map(static function (array $row): array {
            return [
                'id' => (int)$row['id'],
                'id_diretorio' => (int)$row['id_diretorio'],
                'id_card_um' => (int)$row['id_card_um'],
                'id_card_dois' => (int)$row['id_card_dois'],
                'card_um' => [
                    'id' => (int)$row['card_um_id'],
                    'texto' => $row['card_um_texto'],
                    'idioma' => $row['card_um_idioma']
                ],
                'card_dois' => [
                    'id' => (int)$row['card_dois_id'],
                    'texto' => $row['card_dois_texto'],
                    'idioma' => $row['card_dois_idioma']
                ]
            ];
        }, $rows);

        echo json_encode(['status' => 'success', 'data' => $data]);
        exit;
    }

    if ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $action = trim((string)($input['action'] ?? ''));

        if ($action !== 'concluir_revisao') {
            http_response_code(422);
            echo json_encode(['status' => 'error', 'message' => 'Ação inválida.']);
            exit;
        }

        $pairId = (int)($input['id_par'] ?? 0);
        if ($pairId <= 0) {
            http_response_code(422);
            echo json_encode(['status' => 'error', 'message' => 'id_par inválido.']);
            exit;
        }

        $stmtPair = $pdo->prepare(
            'SELECT p.id, p.id_diretorio
             FROM pares p
             INNER JOIN directories d ON d.id = p.id_diretorio
             WHERE p.id = :id_par AND d.user_id = :user_id
             LIMIT 1'
        );
        $stmtPair->execute([':id_par' => $pairId, ':user_id' => $userId]);
        $pair = $stmtPair->fetch();

        if (!$pair) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Par não encontrado ou sem permissão.']);
            exit;
        }

        $pdo->beginTransaction();

        $stmtReview = $pdo->prepare('SELECT id, quantidade FROM revisoes WHERE id_par = :id_par AND id_usuario = :id_usuario LIMIT 1 FOR UPDATE');
        $stmtReview->execute([':id_par' => $pairId, ':id_usuario' => $userId]);
        $review = $stmtReview->fetch();

        $newQuantity = 1;
        if ($review) {
            $newQuantity = (int)$review['quantidade'] + 1;
        }

        $tz = new DateTimeZone('-03:00');
        $nextReviewDate = new DateTimeImmutable('now', $tz);
        $nextReviewDate = $nextReviewDate->modify('+' . $newQuantity . ' days');
        $nextReviewString = $nextReviewDate->format('Y-m-d H:i:s');

        if ($review) {
            $stmtUpdate = $pdo->prepare('UPDATE revisoes SET quantidade = :quantidade, proxima_revisao = :proxima_revisao WHERE id = :id');
            $stmtUpdate->execute([
                ':quantidade' => $newQuantity,
                ':proxima_revisao' => $nextReviewString,
                ':id' => (int)$review['id']
            ]);
            $reviewId = (int)$review['id'];
        } else {
            $stmtInsert = $pdo->prepare('INSERT INTO revisoes (id_par, id_usuario, quantidade, proxima_revisao) VALUES (:id_par, :id_usuario, :quantidade, :proxima_revisao)');
            $stmtInsert->execute([
                ':id_par' => $pairId,
                ':id_usuario' => $userId,
                ':quantidade' => $newQuantity,
                ':proxima_revisao' => $nextReviewString
            ]);
            $reviewId = (int)$pdo->lastInsertId();
        }

        $pdo->commit();

        echo json_encode([
            'status' => 'success',
            'data' => [
                'id_revisao' => $reviewId,
                'id_par' => $pairId,
                'quantidade' => $newQuantity,
                'proxima_revisao' => $nextReviewString
            ]
        ]);
        exit;
    }

    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Método não permitido.']);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Erro interno ao processar pares.']);
}
