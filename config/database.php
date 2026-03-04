<?php
// Arquivo: database.php
// Diretório: public_html/gluon/config/database.php

/**
 * CONFIGURAÇÃO DO BANCO DE DADOS E SEGURANÇA
 * Pilar: Seguro e Rápido.
 * Usa PDO para prevenir SQL Injection e gerencia a chave mestre de criptografia.
 */

// 1. Configurações do Banco de Dados
define('DB_HOST', '');
define('DB_NAME', '');
define('DB_USER', ''); 
define('DB_PASS', '');

// 2. Chave de Criptografia Mestre (Guarde isso com a vida, não perca nunca)
define('ENCRYPTION_KEY', 'UmaChaveMuitoForteDe32Caracteres!');

// 3. API Keys de Serviços Externos (Fish Audio)
define('FISH_API_KEY', '');
// IDs de Voz distintos para a Frente e para o Verso do Card
define('FISH_REFERENCE_ID_FRONT', '78c1c2afbe86411f9c2cd213d25aa78a'); // Substitua pelo ID da voz da Frente
define('FISH_REFERENCE_ID_BACK', '1e4abcd5c0294c7c82293612c6bf0351');   // Substitua pelo ID da voz do Verso

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
