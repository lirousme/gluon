<?php
// Arquivo: user.php
// Diretório: public_html/gluon/api/user.php

/**
 * API DE USUÁRIO
 * Pilar: Seguro e Fácil Manutenção.
 * Gerencia configurações da conta, perfil, dados e deleção de segurança.
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

// === PREFERÊNCIAS DO DASHBOARD ===

if ($action === 'get_prefs') {
    $stmt = $pdo->prepare("SELECT root_view, root_new_item_position, copied_directory_id FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();

    if ($user) {
        echo json_encode(['status' => 'success', 'data' => [
            'root_view' => $user['root_view'] ?? 'grid',
            'root_new_item_position' => $user['root_new_item_position'] ?? 'end',
            'copied_directory_id' => $user['copied_directory_id']
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

elseif ($action === 'copy_directory') {
    $dir_id = isset($input['dir_id']) && $input['dir_id'] !== null ? (int)$input['dir_id'] : null;

    if ($dir_id !== null) {
        $stmt = $pdo->prepare("SELECT id FROM directories WHERE id = ? AND user_id = ?");
        $stmt->execute([$dir_id, $user_id]);
        if (!$stmt->fetch()) {
            die(json_encode(['status' => 'error', 'message' => 'Diretório inválido ou sem permissão.']));
        }
    }

    $stmt = $pdo->prepare("UPDATE users SET copied_directory_id = ? WHERE id = ?");
    if ($stmt->execute([$dir_id, $user_id])) {
        echo json_encode(['status' => 'success', 'message' => 'Diretório copiado com sucesso!']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Erro ao copiar diretório.']);
    }
}

// === GERENCIAMENTO DE PERFIL E CONTA ===

elseif ($action === 'get_profile') {
    $stmt = $pdo->prepare("SELECT username, email FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();
    
    if ($user) {
        echo json_encode(['status' => 'success', 'data' => $user]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Usuário não encontrado.']);
    }
}

elseif ($action === 'update_profile') {
    $username = trim($input['username'] ?? '');
    $email = trim($input['email'] ?? '');
    $current_password = $input['current_password'] ?? '';
    $new_password = $input['new_password'] ?? '';

    if (empty($username) || empty($email) || empty($current_password)) {
        die(json_encode(['status' => 'error', 'message' => 'Campos de username, e-mail e senha atual são obrigatórios.']));
    }

    // Validação estrita de segurança da senha atual
    $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($current_password, $user['password_hash'])) {
        die(json_encode(['status' => 'error', 'message' => 'A senha atual está incorreta.']));
    }

    // Validação de unicidade no banco (Não deixa pegar o email ou username de outra pessoa)
    $stmt = $pdo->prepare("SELECT id FROM users WHERE (username = ? OR email = ?) AND id != ?");
    $stmt->execute([$username, $email, $user_id]);
    if ($stmt->fetch()) {
        die(json_encode(['status' => 'error', 'message' => 'Username ou E-mail já está em uso por outra pessoa.']));
    }

    // Executa o Update
    if (!empty($new_password)) {
        $hash = password_hash($new_password, PASSWORD_ARGON2ID);
        $stmt = $pdo->prepare("UPDATE users SET username = ?, email = ?, password_hash = ? WHERE id = ?");
        $stmt->execute([$username, $email, $hash, $user_id]);
    } else {
        $stmt = $pdo->prepare("UPDATE users SET username = ?, email = ? WHERE id = ?");
        $stmt->execute([$username, $email, $user_id]);
    }

    // Atualiza a sessão
    $_SESSION['username'] = $username;
    
    echo json_encode(['status' => 'success', 'message' => 'Perfil atualizado com sucesso!']);
}

elseif ($action === 'delete_account') {
    $password = $input['password'] ?? '';

    if (empty($password)) {
        die(json_encode(['status' => 'error', 'message' => 'A senha é obrigatória para excluir a conta.']));
    }

    $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        die(json_encode(['status' => 'error', 'message' => 'Senha incorreta. Ação de segurança cancelada.']));
    }

    // Deleta o usuário da base de dados. 
    // Devido ao "ON DELETE CASCADE" configurado no MySQL, TODOS os diretórios são automaticamente excluídos de forma limpa.
    $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
    
    if ($stmt->execute([$user_id])) {
        // Limpeza drástica da sessão e cookies do front controller
        session_unset();
        session_destroy();
        setcookie('gluon_remember', '', time() - 3600, "/", "", false, true);
        
        echo json_encode(['status' => 'success', 'message' => 'Conta e dados excluídos permanentemente.']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Erro interno ao excluir a conta.']);
    }
}

else {
    echo json_encode(['status' => 'error', 'message' => 'Ação inválida.']);
}
?>
