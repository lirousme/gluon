<?php
// Arquivo: index.php
// Diretório: public_html/gluon/index.php

/**
 * GLUON - FRONT CONTROLLER
 * Pilar: Fácil Manutenção e Rápido.
 * Este arquivo recebe todas as requisições e inclui o arquivo correto de forma dinâmica.
 * Evita repetição de código e centraliza a segurança.
 */

// Inicia sessão segura
session_start([
    'cookie_httponly' => true, // Previne roubo de sessão via XSS
    'cookie_secure' => isset($_SERVER['HTTPS']), // Apenas HTTPS se disponível
    'cookie_samesite' => 'Lax',
    'use_strict_mode' => true
]);

// Configurações básicas
define('BASE_PATH', __DIR__);

// Recupera sessão via "Manter conectado" mesmo após o iOS/Safari descartar a sessão em memória
if (!isset($_SESSION['user_id']) && isset($_COOKIE['gluon_remember'])) {
    require_once BASE_PATH . '/config/database.php';

    $pdo = Database::getConnection();
    $token_hash = hash('sha256', $_COOKIE['gluon_remember']);

    $stmt = $pdo->prepare("SELECT id, username FROM users WHERE remember_token = ? LIMIT 1");
    $stmt->execute([$token_hash]);
    $user = $stmt->fetch();

    if ($user) {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];

        $is_https = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        $cookie_domain = $_SERVER['HTTP_HOST'] ?? '';
        if (strpos($cookie_domain, ':') !== false) {
            $cookie_domain = explode(':', $cookie_domain, 2)[0];
        }
        if (filter_var($cookie_domain, FILTER_VALIDATE_IP) || $cookie_domain === 'localhost') {
            $cookie_domain = '';
        }

        setcookie(session_name(), session_id(), [
            'expires' => time() + (86400 * 30),
            'path' => '/',
            'domain' => $cookie_domain,
            'secure' => $is_https,
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
    }
}

// Roteamento Simples e Ultra-rápido (Corrigido para rodar na raiz do domínio)
$request_uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$script_name = dirname($_SERVER['SCRIPT_NAME']);

// Garante que se estiver na raiz do domínio, o base path seja vazio e não uma barra solta
if ($script_name === '/' || $script_name === '\\') {
    $script_name = '';
}

// Extrai a rota real removendo apenas o caminho base do início
$route = substr($request_uri, strlen($script_name));
$route = trim($route, '/');

// Se a rota começar com 'api', redireciona para a pasta /api/
if (strpos($route, 'api/') === 0) {
    header('Content-Type: application/json');

    // Segurança: Impede ataques de Directory Traversal (ex: api/../../etc/passwd)
    $route = str_replace(['../', '..\\'], '', $route);

    $api_file = BASE_PATH . '/' . $route . '.php';

    if (file_exists($api_file)) {
        ob_start();
        require_once $api_file;
        $api_output = ob_get_clean();

        $api_output = ltrim((string)$api_output);
        if ($api_output === '') {
            exit;
        }

        json_decode($api_output, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            echo $api_output;
            exit;
        }

        $json_start = strpos($api_output, '{');
        $json_end = strrpos($api_output, '}');
        if ($json_start !== false && $json_end !== false && $json_end > $json_start) {
            $candidate = substr($api_output, $json_start, $json_end - $json_start + 1);
            json_decode($candidate, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                echo $candidate;
                exit;
            }
        }

        http_response_code(500);
        echo json_encode([
            'status' => 'error',
            'message' => 'Resposta inválida da API. Verifique conflitos de merge/saída inesperada em arquivos PHP.'
        ]);
    } else {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'API endpoint not found.']);
    }
    exit;
}


// Roteamento de Views (Front-end)
// Se a rota for vazia, joga para o login por padrão (ou dashboard se logado)
if ($route === '') {
    $route = isset($_SESSION['user_id']) ? 'dashboard' : 'login';
}

// Segurança: Impede manipulação de caminhos na inclusão das views
$route = str_replace(['../', '..\\'], '', $route);
$view_php_file = BASE_PATH . '/views/' . $route . '.php';
$view_html_file = BASE_PATH . '/views/' . $route . '.html';

if (file_exists($view_php_file)) {
    // Prioriza views em PHP para permitir composição em partes menores
    require $view_php_file;
} elseif (file_exists($view_html_file)) {
    // Mantém suporte às views HTML estáticas
    readfile($view_html_file);
} else {
    // Arquivo não encontrado
    http_response_code(404);
    echo "<h1>404 - Página não encontrada</h1>";
}
?>
