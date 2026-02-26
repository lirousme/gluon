<?php
// Arquivo: schedule.php
// Diretório: public_html/gluon/api/schedule.php

/**
 * MICRO-API DA AGENDA / SCHEDULE
 * Pilar: Rápido e Fácil Manutenção.
 * Separa a responsabilidade de atualizar tempos na linha do tempo e visualizações.
 */

require_once BASE_PATH . '/config/database.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    die(json_encode(['status' => 'error', 'message' => 'Não autorizado. Faça login.']));
}

$pdo = Database::getConnection();
$user_id = $_SESSION['user_id'];
$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';

if ($action === 'update_times') {
    $id = (int)($input['id'] ?? 0);
    $start_date = !empty($input['start_date']) ? $input['start_date'] : null;
    $end_date = !empty($input['end_date']) ? $input['end_date'] : null;

    if ($id === 0) {
        die(json_encode(['status' => 'error', 'message' => 'ID de tarefa inválido.']));
    }

    // Atualiza apenas as datas garantindo que o usuário é o dono
    $stmt = $pdo->prepare("UPDATE directories SET start_date = ?, end_date = ? WHERE id = ? AND user_id = ?");
    
    if ($stmt->execute([$start_date, $end_date, $id, $user_id])) {
        echo json_encode(['status' => 'success', 'message' => 'Horário atualizado com sucesso.']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Erro ao atualizar horário.']);
    }
} 
else if ($action === 'update_view') {
    $id = (int)($input['id'] ?? 0);
    // Aceita as 3 opções de view da Agenda
    $view = in_array($input['view'] ?? '', ['timeline', 'kanban', 'list']) ? $input['view'] : 'timeline';

    if ($id === 0) {
        die(json_encode(['status' => 'error', 'message' => 'ID de agenda inválido.']));
    }

    // Atualiza a view salva diretamente na pasta da agenda (Type 2)
    $stmt = $pdo->prepare("UPDATE directories SET default_view = ? WHERE id = ? AND user_id = ? AND type = 2");
    
    if ($stmt->execute([$view, $id, $user_id])) {
        echo json_encode(['status' => 'success', 'message' => 'Preferência de visualização salva.']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Erro ao atualizar visualização.']);
    }
}
else if ($action === 'get_agenda_info') {
    // Busca informações básicas da pasta Agenda atual (Nome, Capa e View Preferida)
    $id = (int)($input['id'] ?? 0);
    $stmt = $pdo->prepare("SELECT name_encrypted, icon, icon_color_from, icon_color_to, default_view FROM directories WHERE id = ? AND user_id = ? AND type = 2");
    $stmt->execute([$id, $user_id]);
    $agenda = $stmt->fetch();

    if($agenda) {
        echo json_encode([
            'status' => 'success', 
            'data' => [
                'name' => Security::decryptData($agenda['name_encrypted']),
                'icon' => $agenda['icon'],
                'color_from' => $agenda['icon_color_from'],
                'color_to' => $agenda['icon_color_to'],
                'view' => $agenda['default_view'] ?? 'timeline'
            ]
        ]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Agenda não encontrada.']);
    }
}
else {
    echo json_encode(['status' => 'error', 'message' => 'Ação inválida.']);
}
?>
