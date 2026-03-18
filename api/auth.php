<?php
// Arquivo: auth.php
// Diretório: public_html/gluon/api/auth.php

/**
 * API DE AUTENTICAÇÃO
 * Gerencia Login, Registro, Logout Seguro e "Manter Logado".
 */

require_once BASE_PATH . '/config/database.php';

$pdo = Database::getConnection();
$method = $_SERVER['REQUEST_METHOD'];

// Apenas aceita requisições POST para autenticação
if ($method !== 'POST') {
    die(json_encode(['status' => 'error', 'message' => 'Invalid request method.']));
}

// Recebe os dados do front-end (JSON)
$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';

if ($action === 'register') {
    $username = trim($input['username'] ?? '');
    $email = trim($input['email'] ?? '');
    $password = $input['password'] ?? '';

    if (empty($username) || empty($email) || empty($password)) {
        die(json_encode(['status' => 'error', 'message' => 'Preencha todos os campos.']));
    }

    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
    $stmt->execute([$username, $email]);
    if ($stmt->fetch()) {
        die(json_encode(['status' => 'error', 'message' => 'Usuário ou E-mail já cadastrado.']));
    }

    $password_hash = password_hash($password, PASSWORD_ARGON2ID);

    try {
        $pdo->beginTransaction();

        try {
            $pdo->exec("ALTER TABLE users ADD COLUMN source_directory_id INT UNSIGNED DEFAULT NULL AFTER home_directory_id");
        } catch (PDOException $e) {}

        $stmt = $pdo->prepare("INSERT INTO users (username, email, password_hash) VALUES (?, ?, ?)");
        $stmt->execute([$username, $email, $password_hash]);
        $new_user_id = (int)$pdo->lastInsertId();

        $source_name_encrypted = Security::encryptData('Anotações');
        $stmtDir = $pdo->prepare("
            INSERT INTO directories (
                user_id, parent_id, type, name_encrypted, default_view,
                new_item_position, sort_order, icon, icon_color_from, icon_color_to
            ) VALUES (?, NULL, 1, ?, 'grid', 'end', 0, 'fa-note-sticky', '#0ea5e9', '#2563eb')
        ");
        $stmtDir->execute([$new_user_id, $source_name_encrypted]);
        $source_directory_id = (int)$pdo->lastInsertId();

        $stmtUser = $pdo->prepare("UPDATE users SET source_directory_id = ? WHERE id = ?");
        $stmtUser->execute([$source_directory_id, $new_user_id]);

        $pdo->commit();
        echo json_encode(['status' => 'success', 'message' => 'Conta criada com sucesso! Você já pode fazer login.']);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['status' => 'error', 'message' => 'Erro ao criar conta.']);
    }
} 

elseif ($action === 'login') {
    $login_id = trim($input['login_id'] ?? '');
    $password = $input['password'] ?? '';

    if (empty($login_id) || empty($password)) {
        die(json_encode(['status' => 'error', 'message' => 'Preencha todos os campos.']));
    }

    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? OR username = ? LIMIT 1");
    $stmt->execute([$login_id, $login_id]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];

        $remember_lifetime = 60 * 60 * 24 * 365 * 10; // 10 anos
        $token = bin2hex(random_bytes(32));
        $token_hash = hash('sha256', $token);

        $stmt = $pdo->prepare("UPDATE users SET remember_token = ? WHERE id = ?");
        $stmt->execute([$token_hash, $user['id']]);

        setcookie('gluon_remember', $token, time() + $remember_lifetime, "/", "", isset($_SERVER['HTTPS']), true);

        echo json_encode(['status' => 'success', 'message' => 'Login realizado com sucesso.', 'redirect' => '/dashboard']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Credenciais inválidas.']);
    }
}

elseif ($action === 'logout') {
    // Apaga o token de remember me do banco de dados (Segurança de sessão persistente)
    if (isset($_SESSION['user_id'])) {
        $stmt = $pdo->prepare("UPDATE users SET remember_token = NULL WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
    }
    
    // Mata a sessão
    session_unset();
    session_destroy();
    
    // Deleta o cookie do navegador definindo data de expiração no passado
    setcookie('gluon_remember', '', time() - 3600, "/", "", isset($_SERVER['HTTPS']), true);

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 3600, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    
    echo json_encode(['status' => 'success', 'message' => 'Deslogado com sucesso.']);
}

elseif ($action === 'check_remember') {
    if (!isset($_SESSION['user_id']) && isset($_COOKIE['gluon_remember'])) {
        $token = $_COOKIE['gluon_remember'];
        $token_hash = hash('sha256', $token);

        $stmt = $pdo->prepare("SELECT id, username FROM users WHERE remember_token = ? LIMIT 1");
        $stmt->execute([$token_hash]);
        $user = $stmt->fetch();

        if ($user) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            echo json_encode(['status' => 'success', 'logged_in' => true]);
            exit;
        }
    }
    echo json_encode(['status' => 'success', 'logged_in' => isset($_SESSION['user_id'])]);
}
?>
