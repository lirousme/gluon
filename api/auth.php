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

    $stmt = $pdo->prepare("INSERT INTO users (username, email, password_hash) VALUES (?, ?, ?)");
    if ($stmt->execute([$username, $email, $password_hash])) {
        echo json_encode(['status' => 'success', 'message' => 'Conta criada com sucesso! Você já pode fazer login.']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Erro ao criar conta.']);
    }
} 

elseif ($action === 'login') {
    $login_id = trim($input['login_id'] ?? '');
    $password = $input['password'] ?? '';
    $remember = $input['remember'] ?? false;

    if (empty($login_id) || empty($password)) {
        die(json_encode(['status' => 'error', 'message' => 'Preencha todos os campos.']));
    }

    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? OR username = ? LIMIT 1");
    $stmt->execute([$login_id, $login_id]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];

        if ($remember) {
            $token = bin2hex(random_bytes(32)); 
            $token_hash = hash('sha256', $token);
            
            $stmt = $pdo->prepare("UPDATE users SET remember_token = ? WHERE id = ?");
            $stmt->execute([$token_hash, $user['id']]);

            setcookie('gluon_remember', $token, time() + (86400 * 30), "/", "", false, true);
        }

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
    setcookie('gluon_remember', '', time() - 3600, "/", "", false, true);
    
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
