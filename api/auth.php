<?php
// Arquivo: auth.php
// Diretório: public_html/gluon/api/auth.php

/**
 * API DE AUTENTICAÇÃO
 * Gerencia Login, Registro e "Manter Logado".
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

    // Validação básica
    if (empty($username) || empty($email) || empty($password)) {
        die(json_encode(['status' => 'error', 'message' => 'Preencha todos os campos.']));
    }

    // Verifica se usuário ou email já existe
    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
    $stmt->execute([$username, $email]);
    if ($stmt->fetch()) {
        die(json_encode(['status' => 'error', 'message' => 'Usuário ou E-mail já cadastrado.']));
    }

    // Hash da senha seguro (Argon2id)
    $password_hash = password_hash($password, PASSWORD_ARGON2ID);

    // Insere no banco
    $stmt = $pdo->prepare("INSERT INTO users (username, email, password_hash) VALUES (?, ?, ?)");
    if ($stmt->execute([$username, $email, $password_hash])) {
        echo json_encode(['status' => 'success', 'message' => 'Conta criada com sucesso! Você já pode fazer login.']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Erro ao criar conta.']);
    }
} 

elseif ($action === 'login') {
    $login_id = trim($input['login_id'] ?? ''); // Pode ser email ou username
    $password = $input['password'] ?? '';
    $remember = $input['remember'] ?? false;

    if (empty($login_id) || empty($password)) {
        die(json_encode(['status' => 'error', 'message' => 'Preencha todos os campos.']));
    }

    // Busca ultra-rápida por email ou username
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? OR username = ? LIMIT 1");
    $stmt->execute([$login_id, $login_id]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash'])) {
        // Sucesso no login
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];

        // Lógica de "Manter logado"
        if ($remember) {
            // Gera um token criptograficamente seguro
            $token = bin2hex(random_bytes(32)); 
            // Salva o hash do token no banco de dados
            $token_hash = hash('sha256', $token);
            
            $stmt = $pdo->prepare("UPDATE users SET remember_token = ? WHERE id = ?");
            $stmt->execute([$token_hash, $user['id']]);

            // Define o cookie no navegador do usuário (Válido por 30 dias)
            // HttpOnly = true impede que Javascript malicioso roube o cookie
            setcookie('gluon_remember', $token, time() + (86400 * 30), "/", "", false, true);
        }

        echo json_encode(['status' => 'success', 'message' => 'Login realizado com sucesso.', 'redirect' => '/dashboard']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Credenciais inválidas.']);
    }
}

elseif ($action === 'check_remember') {
    // Lógica executada automaticamente caso a sessão expire mas o cookie exista
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