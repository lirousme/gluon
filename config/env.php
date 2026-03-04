<?php
// Arquivo: env.php
// Diretório: public_html/gluon/config/env.php

/**
 * BOOTSTRAP DE AMBIENTE
 * Isola carregamento de .env em arquivo próprio para reduzir conflitos em database.php.
 */

if (!function_exists('gluon_starts_with')) {
    function gluon_starts_with($haystack, $needle) {
        return substr($haystack, 0, strlen($needle)) === $needle;
    }
}

if (!function_exists('gluon_ends_with')) {
    function gluon_ends_with($haystack, $needle) {
        if ($needle === '') {
            return true;
        }
        return substr($haystack, -strlen($needle)) === $needle;
    }
}

if (!function_exists('gluon_load_env_file')) {
    function gluon_load_env_file($path) {
        if (!is_readable($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || gluon_starts_with($line, '#')) {
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
                (gluon_starts_with($value, '"') && gluon_ends_with($value, '"')) ||
                (gluon_starts_with($value, "'") && gluon_ends_with($value, "'"))
            ) {
                $value = substr($value, 1, -1);
            }

            $_ENV[$name] = $value;
            putenv("{$name}={$value}");
        }
    }
}

if (!function_exists('gluon_env')) {
    function gluon_env($name, $default = '') {
        $value = $_ENV[$name] ?? getenv($name);
        return ($value === false || $value === null || $value === '') ? $default : $value;
    }
}

gluon_load_env_file(__DIR__ . '/../.env');

if (!defined('DB_HOST')) define('DB_HOST', gluon_env('DB_HOST', 'localhost'));
if (!defined('DB_NAME')) define('DB_NAME', gluon_env('DB_NAME', ''));
if (!defined('DB_USER')) define('DB_USER', gluon_env('DB_USER', ''));
if (!defined('DB_PASS')) define('DB_PASS', gluon_env('DB_PASS', ''));
if (!defined('ENCRYPTION_KEY')) define('ENCRYPTION_KEY', gluon_env('ENCRYPTION_KEY', 'UmaChaveMuitoForteDe32Caracteres!'));
if (!defined('FISH_API_KEY')) define('FISH_API_KEY', gluon_env('FISH_API_KEY', ''));
if (!defined('FISH_REFERENCE_ID_FRONT')) define('FISH_REFERENCE_ID_FRONT', gluon_env('FISH_REFERENCE_ID_FRONT', '78c1c2afbe86411f9c2cd213d25aa78a'));
if (!defined('FISH_REFERENCE_ID_BACK')) define('FISH_REFERENCE_ID_BACK', gluon_env('FISH_REFERENCE_ID_BACK', '1e4abcd5c0294c7c82293612c6bf0351'));
if (!defined('CRON_SECURE_TOKEN')) define('CRON_SECURE_TOKEN', gluon_env('CRON_SECURE_TOKEN', ''));
if (!defined('OPENAI_API_KEY')) define('OPENAI_API_KEY', gluon_env('OPENAI_API_KEY', ''));
