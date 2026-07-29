<?php
/**
 * Converte imagens antigas de branch chats em data URI base64 criptografada.
 * Execute depois da migration 2026_07_29_encrypt_branch_chat_images.sql.
 *
 * Uso:
 *   php scripts/encrypt_branch_chat_images.php
 *   php scripts/encrypt_branch_chat_images.php --dry-run
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Este script deve ser executado via CLI.\n");
}

require_once dirname(__DIR__) . '/config/database.php';

$dryRun = in_array('--dry-run', $argv ?? [], true);
$pdo = Database::getConnection();
$select = $pdo->query(
    'SELECT id, imagem_encrypted FROM mensagens WHERE imagem_encrypted IS NOT NULL ORDER BY id'
);
$update = $pdo->prepare(
    'UPDATE mensagens SET imagem_encrypted = :encrypted WHERE id = :id AND imagem_encrypted = :legacy'
);
$pending = 0;
$encrypted = 0;

while ($message = $select->fetch()) {
    $storedImage = (string)$message['imagem_encrypted'];
    if (Security::decryptData($storedImage) !== false) {
        continue;
    }

    $legacyFile = null;
    $dataUri = null;
    if (str_starts_with($storedImage, 'data:image/')) {
        $dataUri = $storedImage;
    } elseif (str_starts_with($storedImage, '/uploads/branch_chats/')) {
        $legacyFile = dirname(__DIR__) . $storedImage;
        if (is_file($legacyFile)) {
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($legacyFile);
            $contents = file_get_contents($legacyFile);
            if (is_string($mime) && str_starts_with($mime, 'image/') && $contents !== false) {
                $dataUri = 'data:' . $mime . ';base64,' . base64_encode($contents);
            }
        }
    }

    if ($dataUri === null) {
        fwrite(STDERR, "Imagem {$message['id']} não pôde ser convertida.\n");
        continue;
    }

    $pending++;
    if (!$dryRun) {
        $update->execute([
            ':encrypted' => Security::encryptData($dataUri),
            ':id' => $message['id'],
            ':legacy' => $storedImage,
        ]);
        if ($update->rowCount() === 1) {
            $encrypted++;
            if ($legacyFile !== null) {
                @unlink($legacyFile);
            }
        }
    }
}

if ($dryRun) {
    echo "Imagens antigas encontradas: {$pending}. Nenhuma alteração foi aplicada.\n";
    exit(0);
}

echo "Imagens encontradas: {$pending} | imagens criptografadas: {$encrypted}.\n";
