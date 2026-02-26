<?php
// Arquivo: user.php
// Diretório: public_html/gluon/api/user.php

/**
 * API DE USUÁRIO
 * Pilar: Seguro e Fácil Manutenção.
 * Gerencia preferências, perfil e configurações da conta (Ex: Visualização e Ordem da Raiz).
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
    // Busca as preferências globais do usuário
    $stmt = $pdo->prepare("SELECT root_view, root_new_item_position FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();

    if ($user) {
        echo json_encode(['status' => 'success', 'data' => [
            'root_view' => $user['root_view'] ?? 'grid',
            'root_new_item_position' => $user['root_new_item_position'] ?? 'end'
        ]]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Usuário não encontrado.']);
    }
} 

elseif ($action === 'update_root_prefs') {
    $view = in_array($input['view'] ?? '', ['grid', 'list', 'kanban']) ? $input['view'] : 'grid';
    $position = in_array($input['new_item_position'] ?? '', ['start', 'end']) ? $input['new_item_position'] : 'end';

    $stmt = $pdo->prepare("UPDATE users SET root_view = ?, root_new_item_position = ? WHERE id = ?");
    if ($stmt->execute([$view, $position, $user_id])) {
        echo json_encode(['status' => 'success', 'message' => 'Preferências da raiz atualizadas.']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Erro ao atualizar preferência.']);
    }
}

else {
    echo json_encode(['status' => 'error', 'message' => 'Ação inválida.']);
}
?>
