<?php
// Arquivo: search.php
// Diretório: public_html/gluon/api/search.php
// Pilar: Seguro, Rápido e Escalável.

require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    die(json_encode(['status' => 'error', 'message' => 'Não autorizado. Faça login.']));
}

$pdo = Database::getConnection();
$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';

if ($action === 'search_users') {
    $query = trim($input['query'] ?? '');

    // Se a query for vazia, retorna array vazio imediatamente (Zero custo no banco de dados)
    if (empty($query)) {
        echo json_encode(['status' => 'success', 'data' => []]);
        exit;
    }

    // Busca rápida via LIKE usando PDO Statement para evitar SQL Injection.
    // Limitamos para 15 registros para manter a performance ultra rápida simulando o "Instagram".
    // Ignoramos a si mesmo da busca (id != ?).
    $stmt = $pdo->prepare("SELECT id, username FROM users WHERE username LIKE ? AND id != ? LIMIT 15");
    $searchTerm = '%' . $query . '%';
    $stmt->execute([$searchTerm, $_SESSION['user_id']]);
    $users = $stmt->fetchAll();

    echo json_encode(['status' => 'success', 'data' => $users]);
} else {
    echo json_encode(['status' => 'error', 'message' => 'Ação inválida.']);
}
?>
