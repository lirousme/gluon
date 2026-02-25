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
    'use_strict_mode' => true
]);

// Configurações básicas
define('BASE_PATH', __DIR__);

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
        require_once $api_file;
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
$view_file = BASE_PATH . '/views/' . $route . '.html';

if (file_exists($view_file)) {
    // Retorna a view HTML ultra-rápida (direto pro output buffer)
    readfile($view_file);
} else {
    // Arquivo não encontrado
    http_response_code(404);
    echo "<h1>404 - Página não encontrada</h1>";
}
?>
