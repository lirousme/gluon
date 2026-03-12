<?php
// Arquivo: database.php
// Diretório: public_html/gluon/config/database.php

/**
 * CONFIGURAÇÃO DO BANCO DE DADOS E SEGURANÇA
 * Pilar: Seguro e Rápido.
 * Usa PDO para prevenir SQL Injection e gerencia a chave mestre de criptografia.
 */

/**
 * Carrega variáveis de ambiente do arquivo .env sem depender de bibliotecas externas.
 */
function loadEnvFile($envPath) {
    if (!is_readable($envPath)) {
        return;
    }

    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);

        if ($line === '' || strpos($line, '#') === 0 || strpos($line, '=') === false) {
            continue;
        }

        list($key, $value) = array_map('trim', explode('=', $line, 2));
        if ($key === '') {
            continue;
        }

        $value = trim($value, "\"'");

        if (getenv($key) === false) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }
}

function envValue($key, $default = '') {
    $value = getenv($key);
    return $value === false ? $default : $value;
}

loadEnvFile(dirname(__DIR__) . '/.env');

// 1. Configurações do Banco de Dados
define('DB_HOST', envValue('DB_HOST', 'localhost'));
define('DB_NAME', envValue('DB_NAME', ''));
define('DB_USER', envValue('DB_USER', ''));
define('DB_PASS', envValue('DB_PASS', ''));

// 2. Chave de Criptografia Mestre (Guarde isso com a vida, não perca nunca)
define('ENCRYPTION_KEY', envValue('ENCRYPTION_KEY', ''));

// 3. API Keys de Serviços Externos
define('FISH_API_KEY', envValue('FISH_API_KEY', ''));
define('FISH_REFERENCE_ID_FRONT', envValue('FISH_REFERENCE_ID_FRONT', '78c1c2afbe86411f9c2cd213d25aa78a'));
define('FISH_REFERENCE_ID_BACK', envValue('FISH_REFERENCE_ID_BACK', '1e4abcd5c0294c7c82293612c6bf0351'));
define('FISH_REFERENCE_ID_PT_BR', envValue('FISH_REFERENCE_ID_PT_BR', FISH_REFERENCE_ID_FRONT));
define('FISH_REFERENCE_ID_EN_US', envValue('FISH_REFERENCE_ID_EN_US', FISH_REFERENCE_ID_BACK));
define('FISH_REFERENCE_ID_EN_GB', envValue('FISH_REFERENCE_ID_EN_GB', FISH_REFERENCE_ID_BACK));
define('OPENAI_API_KEY', envValue('OPENAI_API_KEY', ''));
define('OPENAI_TTS_VOICE_DEFAULT', envValue('OPENAI_TTS_VOICE_DEFAULT', 'alloy'));
define('OPENAI_TTS_VOICE_PT_BR', envValue('OPENAI_TTS_VOICE_PT_BR', OPENAI_TTS_VOICE_DEFAULT));
define('OPENAI_TTS_VOICE_EN_US', envValue('OPENAI_TTS_VOICE_EN_US', OPENAI_TTS_VOICE_DEFAULT));
define('OPENAI_TTS_VOICE_EN_GB', envValue('OPENAI_TTS_VOICE_EN_GB', OPENAI_TTS_VOICE_DEFAULT));

class Database {
    private static $pdo = null;

    public static function getConnection() {
        if (self::$pdo === null) {
            try {
                $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
                $options = [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ];
                self::$pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
            } catch (PDOException $e) {
                die(json_encode(['status' => 'error', 'message' => 'Database connection failed.']));
            }
        }
        return self::$pdo;
    }
}

class Security {
    public static function encryptData($data) {
        $iv = random_bytes(openssl_cipher_iv_length('aes-256-gcm'));
        $encrypted = openssl_encrypt($data, 'aes-256-gcm', ENCRYPTION_KEY, 0, $iv, $tag);
        return base64_encode($iv . $tag . $encrypted);
    }

    public static function decryptData($data) {
        $data = base64_decode($data);
        $iv_length = openssl_cipher_iv_length('aes-256-gcm');
        $iv = substr($data, 0, $iv_length);
        $tag = substr($data, $iv_length, 16);
        $encrypted = substr($data, $iv_length + 16);
        return openssl_decrypt($encrypted, 'aes-256-gcm', ENCRYPTION_KEY, 0, $iv, $tag);
    }
}
?>
