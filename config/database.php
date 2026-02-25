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
define('DB_USER', ''); // Altere em produção
define('DB_PASS', '');     // Altere em produção

// 2. Chave de Criptografia Mestre (Guarde isso com a vida, não perca nunca)
// Idealmente isso viria de uma variável de ambiente fora da pasta public_html
define('ENCRYPTION_KEY', 'UmaChaveMuitoForteDe32Caracteres!');

class Database {
    private static $pdo = null;

    public static function getConnection() {
        if (self::$pdo === null) {
            try {
                $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
                $options = [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false, // Garante tipos nativos e máxima segurança
                ];
                self::$pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
            } catch (PDOException $e) {
                // Em produção, registre o erro em um log, não exiba na tela
                die(json_encode(['status' => 'error', 'message' => 'Database connection failed.']));
            }
        }
        return self::$pdo;
    }
}

class Security {
    /**
     * Criptografa dados sensíveis dos usuários (Ninguém além do sistema consegue ler)
     */
    public static function encryptData($data) {
        $iv = random_bytes(openssl_cipher_iv_length('aes-256-gcm'));
        $encrypted = openssl_encrypt($data, 'aes-256-gcm', ENCRYPTION_KEY, 0, $iv, $tag);
        return base64_encode($iv . $tag . $encrypted);
    }

    /**
     * Descriptografa dados sensíveis
     */
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