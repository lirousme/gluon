<?php
// Arquivo: user.php
// Diretório: public_html/gluon/api/user.php

/**
 * API DE USUÁRIO
 * Pilar: Seguro e Fácil Manutenção.
 * Gerencia preferências, perfil e configurações da conta (Ex: Visualização da Raiz).
 */

require_once BASE_PATH . '/config/database.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    die(json_encode(['status' => 'error', 'message' => 'Não autorizado. Faça login.']));
}

$pdo = Database::getConnection();
$user_id = $_SESSION['user_id'];
$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';

if ($action === 'get_prefs') {
    // Busca as preferências globais do usuário (neste caso, a view da raiz)
    $stmt = $pdo->prepare("SELECT root_view FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();

    if ($user) {
        echo json_encode(['status' => 'success', 'data' => ['root_view' => $user['root_view'] ?? 'grid']]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Usuário não encontrado.']);
    }
} 

elseif ($action === 'update_root_view') {
    $view = in_array($input['view'] ?? '', ['grid', 'list', 'kanban']) ? $input['view'] : 'grid';

    $stmt = $pdo->prepare("UPDATE users SET root_view = ? WHERE id = ?");
    if ($stmt->execute([$view, $user_id])) {
        echo json_encode(['status' => 'success', 'message' => 'Preferência atualizada.']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Erro ao atualizar preferência.']);
    }
}

else {
    echo json_encode(['status' => 'error', 'message' => 'Ação inválida.']);
}
?>
