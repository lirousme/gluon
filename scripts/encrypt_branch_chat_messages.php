<?php
/**
 * Criptografa mensagens antigas que ainda estejam em texto puro.
 *
 * A API também faz essa conversão gradualmente durante a leitura. Este script
 * permite concluir a migração imediatamente após aplicar a alteração de schema.
 *
 * Uso:
 *   php scripts/encrypt_branch_chat_messages.php
 *   php scripts/encrypt_branch_chat_messages.php --dry-run
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Este script deve ser executado via CLI.\n");
}

require_once dirname(__DIR__) . '/config/database.php';

$dryRun = in_array('--dry-run', $argv ?? [], true);
$pdo = Database::getConnection();
$select = $pdo->query(
    'SELECT id, texto_encrypted FROM mensagens WHERE texto_encrypted IS NOT NULL ORDER BY id'
);
$update = $pdo->prepare(
    'UPDATE mensagens SET texto_encrypted = :encrypted WHERE id = :id AND texto_encrypted = :plain'
);
$pending = 0;
$encrypted = 0;

while ($message = $select->fetch()) {
    $storedText = (string)$message['texto_encrypted'];
    if (Security::decryptData($storedText) !== false) {
        continue;
    }

    $pending++;
    if (!$dryRun) {
        $update->execute([
            ':encrypted' => Security::encryptData($storedText),
            ':id' => $message['id'],
            ':plain' => $storedText,
        ]);
        $encrypted += $update->rowCount();
    }
}

if ($dryRun) {
    echo "Mensagens em texto puro encontradas: {$pending}. Nenhuma alteração foi aplicada.\n";
    exit(0);
}

echo "Mensagens encontradas: {$pending} | mensagens criptografadas: {$encrypted}.\n";
