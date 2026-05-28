<?php
/**
 * Script temporário para criar os diretórios obrigatórios do sistema
 * para usuários já existentes:
 * - "Anotações" (type = 1, deck_system = 1), vinculado em users.source_directory_id
 * - "Grafo" (type = 4, deck_mode = 'grafo', deck_system = 1)
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

// Migrações defensivas: garantem que as colunas existam para ambientes antigos.
try {
    $pdo->exec("ALTER TABLE users ADD COLUMN source_directory_id INT UNSIGNED NULL DEFAULT NULL AFTER home_directory_id");
    echo "[migração] Coluna users.source_directory_id criada.\n";
} catch (PDOException $e) {
    // Ignora caso já exista.
}

try {
    $pdo->exec("ALTER TABLE directories ADD COLUMN deck_mode VARCHAR(20) DEFAULT 'aleatorio' AFTER type");
    echo "[migração] Coluna directories.deck_mode criada.\n";
} catch (PDOException $e) {
    // Ignora caso já exista.
}

try {
    $pdo->exec("ALTER TABLE directories ADD COLUMN deck_system INT NOT NULL DEFAULT 0 AFTER deck_structure");
    echo "[migração] Coluna directories.deck_system criada.\n";
} catch (PDOException $e) {
    // Ignora caso já exista.
}

$stmtMissingSource = $pdo->query("
    SELECT u.id
    FROM users u
    LEFT JOIN directories d ON d.id = u.source_directory_id AND d.user_id = u.id
    WHERE u.source_directory_id IS NULL OR d.id IS NULL
    ORDER BY u.id ASC
");
$missingSourceUserIds = array_map('intval', $stmtMissingSource->fetchAll(PDO::FETCH_COLUMN));

$stmtMissingGraph = $pdo->query("
    SELECT u.id
    FROM users u
    LEFT JOIN directories d
      ON d.user_id = u.id
     AND d.parent_id IS NULL
     AND d.type = 4
     AND d.deck_system = 1
     AND d.deck_mode = 'grafo'
    WHERE d.id IS NULL
    ORDER BY u.id ASC
");
$missingGraphUserIds = array_map('intval', $stmtMissingGraph->fetchAll(PDO::FETCH_COLUMN));

if (empty($missingSourceUserIds) && empty($missingGraphUserIds)) {
    echo "Nenhum usuário pendente. Nada para fazer.\n";
    exit(0);
}

echo "Usuários sem Anotações: " . count($missingSourceUserIds) . "\n";
echo "Usuários sem Grafo: " . count($missingGraphUserIds) . "\n";
if ($dryRun) {
    echo "Modo dry-run ativado. Nenhuma alteração foi aplicada.\n";
    exit(0);
}

$createdSource = 0;
$createdGraph = 0;
$updatedUsers = 0;
$errors = 0;
$userIds = array_values(array_unique(array_merge($missingSourceUserIds, $missingGraphUserIds)));

foreach ($userIds as $userId) {
    try {
        $pdo->beginTransaction();

        $stmtLock = $pdo->prepare("SELECT id FROM users WHERE id = ? FOR UPDATE");
        $stmtLock->execute([$userId]);

        $stmtCurrentSource = $pdo->prepare("
            SELECT u.source_directory_id, d.id AS valid_directory_id
            FROM users u
            LEFT JOIN directories d ON d.id = u.source_directory_id AND d.user_id = u.id
            WHERE u.id = ?
            LIMIT 1
        ");
        $stmtCurrentSource->execute([$userId]);
        $sourceState = $stmtCurrentSource->fetch();

        if ($sourceState && empty($sourceState['valid_directory_id'])) {
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
                    deck_system, new_item_position, sort_order, icon, icon_color_from, icon_color_to
                ) VALUES (?, NULL, 1, ?, 'grid', 1, 'end', ?, 'fa-note-sticky', '#0ea5e9', '#2563eb')
            ");
            $stmtCreate->execute([$userId, $sourceNameEncrypted, $nextSortOrder]);
            $newDirectoryId = (int)$pdo->lastInsertId();
            $createdSource++;

            $stmtUpdate = $pdo->prepare("UPDATE users SET source_directory_id = ? WHERE id = ?");
            $stmtUpdate->execute([$newDirectoryId, $userId]);
            $updatedUsers += $stmtUpdate->rowCount();

            echo "[ok] usuário {$userId} => Anotações {$newDirectoryId}\n";
        }

        $stmtExistingGraph = $pdo->prepare("
            SELECT id
            FROM directories
            WHERE user_id = ?
              AND parent_id IS NULL
              AND type = 4
              AND deck_system = 1
              AND deck_mode = 'grafo'
            ORDER BY id ASC
            LIMIT 1
        ");
        $stmtExistingGraph->execute([$userId]);

        if (!$stmtExistingGraph->fetchColumn()) {
            $stmtMax = $pdo->prepare("
                SELECT COALESCE(MAX(sort_order), -1) + 1
                FROM directories
                WHERE user_id = ? AND parent_id IS NULL
                FOR UPDATE
            ");
            $stmtMax->execute([$userId]);
            $nextSortOrder = (int)$stmtMax->fetchColumn();

            $graphDeckNameEncrypted = Security::encryptData('Grafo');
            $stmtCreateGraph = $pdo->prepare("
                INSERT INTO directories (
                    user_id, parent_id, type, name_encrypted, default_view,
                    deck_mode, deck_system, new_item_position, sort_order, icon, icon_color_from, icon_color_to
                ) VALUES (?, NULL, 4, ?, 'grid', 'grafo', 1, 'end', ?, 'fa-diagram-project', '#8b5cf6', '#6366f1')
            ");
            $stmtCreateGraph->execute([$userId, $graphDeckNameEncrypted, $nextSortOrder]);
            $newGraphDirectoryId = (int)$pdo->lastInsertId();
            $createdGraph++;

            echo "[ok] usuário {$userId} => Grafo {$newGraphDirectoryId}\n";
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $errors++;
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo "[erro] usuário {$userId}: {$e->getMessage()}\n";
    }
}

echo "Concluído. Anotações criados: {$createdSource} | Grafos criados: {$createdGraph} | Usuários atualizados: {$updatedUsers} | Erros: {$errors}\n";
