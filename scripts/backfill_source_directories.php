<?php
/**
 * Script temporário para criar o diretório obrigatório "Anotações"
 * para usuários já existentes que ainda não possuem source_directory_id válido.
 *
 * Uso:
 *   php scripts/backfill_source_directories.php
 *   php scripts/backfill_source_directories.php --dry-run
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Este script deve ser executado via CLI.\n");
}

require_once dirname(__DIR__) . '/config/database.php';

$dryRun = in_array('--dry-run', $argv ?? [], true);

$pdo = Database::getConnection();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Migração defensiva: garante que a coluna exista para ambientes antigos.
try {
    $pdo->exec("ALTER TABLE users ADD COLUMN source_directory_id INT UNSIGNED NULL DEFAULT NULL AFTER home_directory_id");
    echo "[migração] Coluna users.source_directory_id criada.\n";
} catch (PDOException $e) {
    // Ignora caso já exista.
}

$stmtMissing = $pdo->query("
    SELECT u.id
    FROM users u
    LEFT JOIN directories d ON d.id = u.source_directory_id AND d.user_id = u.id
    WHERE u.source_directory_id IS NULL OR d.id IS NULL
    ORDER BY u.id ASC
");
$userIds = array_map('intval', $stmtMissing->fetchAll(PDO::FETCH_COLUMN));

if (empty($userIds)) {
    echo "Nenhum usuário pendente. Nada para fazer.\n";
    exit(0);
}

echo "Usuários pendentes: " . count($userIds) . "\n";
if ($dryRun) {
    echo "Modo dry-run ativado. Nenhuma alteração foi aplicada.\n";
    exit(0);
}

$created = 0;
$updated = 0;
$errors = 0;

foreach ($userIds as $userId) {
    try {
        $pdo->beginTransaction();

        $stmtMax = $pdo->prepare("
            SELECT COALESCE(MAX(sort_order), -1) + 1
            FROM directories
            WHERE user_id = ? AND parent_id IS NULL
            FOR UPDATE
        ");
        $stmtMax->execute([$userId]);
        $nextSortOrder = (int)$stmtMax->fetchColumn();

        $sourceNameEncrypted = Security::encryptData('Anotações');
        $stmtCreate = $pdo->prepare("
            INSERT INTO directories (
                user_id, parent_id, type, name_encrypted, default_view,
                new_item_position, sort_order, icon, icon_color_from, icon_color_to
            ) VALUES (?, NULL, 1, ?, 'grid', 'end', ?, 'fa-note-sticky', '#0ea5e9', '#2563eb')
        ");
        $stmtCreate->execute([$userId, $sourceNameEncrypted, $nextSortOrder]);
        $newDirectoryId = (int)$pdo->lastInsertId();
        $created++;

        $stmtUpdate = $pdo->prepare("UPDATE users SET source_directory_id = ? WHERE id = ?");
        $stmtUpdate->execute([$newDirectoryId, $userId]);
        $updated += $stmtUpdate->rowCount();

        $pdo->commit();
        echo "[ok] usuário {$userId} => diretório {$newDirectoryId}\n";
    } catch (Throwable $e) {
        $errors++;
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo "[erro] usuário {$userId}: {$e->getMessage()}\n";
    }
}

echo "Concluído. Criados: {$created} | Usuários atualizados: {$updated} | Erros: {$errors}\n";

