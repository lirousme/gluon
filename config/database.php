<?php
// Arquivo: database.php
// Diretório: public_html/gluon/config/database.php

/**
 * CONFIGURAÇÃO DO BANCO DE DADOS E SEGURANÇA
 * Pilar: Seguro e Rápido.
 * Usa PDO para prevenir SQL Injection e gerencia a chave mestre de criptografia.
 */

function loadEnvFile($path) {
    if (!is_readable($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        $parts = explode('=', $line, 2);
        if (count($parts) !== 2) {
            continue;
        }

        $name = trim($parts[0]);
        $value = trim($parts[1]);

        if ($name === '') {
            continue;
        }

        if (
            (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
            (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            $value = substr($value, 1, -1);
        }

        $_ENV[$name] = $value;
        putenv("{$name}={$value}");
    }
}

function envValue($name, $default = '') {
    $value = $_ENV[$name] ?? getenv($name);
    return ($value === false || $value === null || $value === '') ? $default : $value;
}

loadEnvFile(__DIR__ . '/../.env');

// 1. Configurações do Banco de Dados
define('DB_HOST', envValue('DB_HOST', 'localhost'));
define('DB_NAME', envValue('DB_NAME', ''));
define('DB_USER', envValue('DB_USER', ''));
define('DB_PASS', envValue('DB_PASS', ''));

// 2. Chave de Criptografia Mestre (Guarde isso com a vida, não perca nunca)
define('ENCRYPTION_KEY', envValue('ENCRYPTION_KEY', 'UmaChaveMuitoForteDe32Caracteres!'));

// 3. API Keys de Serviços Externos (Fish Audio)
define('FISH_API_KEY', envValue('FISH_API_KEY', ''));
// IDs de Voz distintos para a Frente e para o Verso do Card
define('FISH_REFERENCE_ID_FRONT', envValue('FISH_REFERENCE_ID_FRONT', '78c1c2afbe86411f9c2cd213d25aa78a')); // Substitua pelo ID da voz da Frente
define('FISH_REFERENCE_ID_BACK', envValue('FISH_REFERENCE_ID_BACK', '1e4abcd5c0294c7c82293612c6bf0351'));   // Substitua pelo ID da voz do Verso
define('CRON_SECURE_TOKEN', envValue('CRON_SECURE_TOKEN', ''));
define('OPENAI_API_KEY', envValue('OPENAI_API_KEY', ''));

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
=======

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
