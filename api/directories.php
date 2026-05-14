<?php
// Arquivo: directories.php
// Diretório: public_html/gluon/api/directories.php

/**
 * API DE DIRETÓRIOS E ARQUIVOS
 * Pilar: Seguro, Rápido e Escalável.
 * Suporta Árvores de Pastas, Código, Agendas, Portais (Atalhos Dinâmicos) e Recorrências.
 */

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once BASE_PATH . '/config/database.php';

// =========================================================================
// VERIFICAÇÃO DE SEGURANÇA (AUTENTICAÇÃO)
// =========================================================================
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    die(json_encode(['status' => 'error', 'message' => 'Não autorizado. Faça login.']));
}

$pdo = Database::getConnection();
$user_id = $_SESSION['user_id'];
ensureLegacyDirectoryTypesCleanup($pdo, $user_id);
$method = $_SERVER['REQUEST_METHOD'];

// Captura do input via JSON
$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';

try {
    $pdo->exec("ALTER TABLE flashcard_book_progress ADD COLUMN next_review_at DATETIME DEFAULT NULL AFTER completed_reads");
} catch (PDOException $e) {}


// =========================================================================
// FUNÇÃO HELPER: CALCULAR A PRÓXIMA DATA DE RECORRÊNCIA
// =========================================================================
function calculateNextRunDate($type, $interval, $days_of_week, $custom_dates, $base_date, $time_start = null, $time_end = null) {
    // Se não houver data base, assume o momento atual
    $date = $base_date ? new DateTime($base_date) : new DateTime();
    $interval = (int)$interval > 0 ? (int)$interval : 1;

    if ($type === 'minutely') {
        $date->modify("+$interval minute");

        if ($time_start && $time_end) {
            $currentTimeStr = $date->format('H:i:s');

            if ($currentTimeStr >= $time_end) {
                $date->modify('+1 day');
                $date->setTime((int)substr($time_start, 0, 2), (int)substr($time_start, 3, 2), 0);
            } elseif ($currentTimeStr < $time_start) {
                $date->setTime((int)substr($time_start, 0, 2), (int)substr($time_start, 3, 2), 0);
            }
        }
    } elseif ($type === 'hourly') {
        $date->modify("+$interval hour");

        // Regra do Limite de Horário (Janela)
        if ($time_start && $time_end) {
            $currentTimeStr = $date->format('H:i:s');
            
            // Se passar do horário limite ou não tiver atingido o de inicio ainda (devido à virada de dia)
            if ($currentTimeStr >= $time_end) {
                // Joga pro dia seguinte no horário de início
                $date->modify('+1 day');
                $date->setTime((int)substr($time_start, 0, 2), (int)substr($time_start, 3, 2), 0);
            } elseif ($currentTimeStr < $time_start) {
                // Ajusta para o horário de início (se a tarefa foi jogada pro outro dia na hora de criar, ex)
                $date->setTime((int)substr($time_start, 0, 2), (int)substr($time_start, 3, 2), 0);
            }
        }
    } elseif ($type === 'daily') {
        $date->modify("+$interval day");
    } elseif ($type === 'weekly') {
        $date->modify("+$interval week");
    } elseif ($type === 'monthly') {
        $date->modify("+$interval month");
    } elseif ($type === 'yearly') {
        $date->modify("+$interval year");
    } elseif ($type === 'custom' && !empty($custom_dates)) {
        // Lógica para datas personalizadas
        $dates = json_decode($custom_dates, true);
        if (is_array($dates) && count($dates) > 0) {
            sort($dates);
            $ref_date = clone $date; 
            foreach ($dates as $d) {
                $cd = new DateTime($d);
                if ($cd > $ref_date) {
                    return $cd->format('Y-m-d H:i:s');
                }
            }
            return null; // Não há datas futuras programadas
        }
    }
    return $date->format('Y-m-d H:i:s');
}


// =========================================================================
// FUNÇÃO HELPER: DUPLICAR ÁRVORE DE DIRETÓRIOS (RECURSIVA)
// =========================================================================
function duplicateDirectoryTree($source_id, $target_parent_id, $user_id, $pdo, $is_top_level = true) {
    // 1. Busca os dados do diretório original
    $stmt = $pdo->prepare("SELECT * FROM directories WHERE id = ? AND user_id = ?");
    $stmt->execute([$source_id, $user_id]);
    $sourceDir = $stmt->fetch();
    
    if (!$sourceDir) {
        return false;
    }

    $newNameEnc = $sourceDir['name_encrypted'];
    
    // Se for o nível superior da cópia, adiciona "(Cópia)" ao nome
    if ($is_top_level) {
        $decryptedName = Security::decryptData($newNameEnc);
        $newNameEnc = Security::encryptData($decryptedName . " (Cópia)");
    }

    // 2. Calcula a ordem de classificação (sort_order) para o novo local
    // sempre normalizando os irmãos antes para evitar colisões de sort_order.
    $newOrder = getNextSiblingSortOrder($pdo, $user_id, $target_parent_id);

    // 3. Insere o novo diretório na tabela
    $stmtInsert = $pdo->prepare("
        INSERT INTO directories (
            user_id, parent_id, target_id, type, name_encrypted, default_view, 
            open_mode, new_item_position, sort_order, icon, icon_color_from, icon_color_to, 
            cover_url_encrypted, start_date, end_date, is_recurring
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    $stmtInsert->execute([
        $user_id,
        $target_parent_id,
        $sourceDir['target_id'],
        $sourceDir['type'],
        $newNameEnc,
        $sourceDir['default_view'],
        in_array($sourceDir['open_mode'] ?? '', ['fullscreen', 'preview'], true) ? $sourceDir['open_mode'] : 'fullscreen',
        $sourceDir['new_item_position'],
        $newOrder,
        $sourceDir['icon'],
        $sourceDir['icon_color_from'],
        $sourceDir['icon_color_to'],
        $sourceDir['cover_url_encrypted'],
        $sourceDir['start_date'],
        $sourceDir['end_date'],
        $sourceDir['is_recurring']
    ]);
    
    $newDirId = $pdo->lastInsertId();

    // 4. Clona a regra de recorrência (se existir)
    if ($sourceDir['is_recurring'] == 1) {
        $stmtRec = $pdo->prepare("SELECT * FROM directory_recurrences WHERE directory_id = ?");
        $stmtRec->execute([$source_id]);
        $rec = $stmtRec->fetch();
        
        if ($rec) {
            $stmtInsRec = $pdo->prepare("
                INSERT INTO directory_recurrences (
                    directory_id, type, interval_value, days_of_week, 
                    custom_dates, exceptions, time_start, time_end, end_date, next_run_date
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmtInsRec->execute([
                $newDirId, 
                $rec['type'], 
                $rec['interval_value'], 
                $rec['days_of_week'], 
                $rec['custom_dates'], 
                $rec['exceptions'] ?? null, 
                $rec['time_start'] ?? null,
                $rec['time_end'] ?? null,
                $rec['end_date'], 
                $rec['next_run_date']
            ]);
        }
    }

    // 5. Clona o conteúdo do ficheiro de código (se for do tipo 1)
    if ((int)$sourceDir['type'] === 1) {
        $stmtCode = $pdo->prepare("SELECT language, content_encrypted FROM files_code WHERE directory_id = ?");
        $stmtCode->execute([$source_id]);
        $codeData = $stmtCode->fetch();
        
        if ($codeData) {
            $stmtInsertCode = $pdo->prepare("INSERT INTO files_code (directory_id, language, content_encrypted) VALUES (?, ?, ?)");
            $stmtInsertCode->execute([$newDirId, $codeData['language'], $codeData['content_encrypted']]);
        }
    }

    // 6. Clona recursivamente os subdiretórios
    $stmtChildren = $pdo->prepare("SELECT id FROM directories WHERE parent_id = ? AND user_id = ?");
    $stmtChildren->execute([$source_id, $user_id]);
    $children = $stmtChildren->fetchAll();

    foreach ($children as $child) {
        duplicateDirectoryTree($child['id'], $newDirId, $user_id, $pdo, false);
    }

    return true;
}

function normalizeSiblingSortOrders($pdo, $user_id, $parent_id) {
    $stmt = $pdo->prepare(
        "SELECT id, sort_order
         FROM directories
         WHERE user_id = ?
           AND (parent_id = ? OR (parent_id IS NULL AND ? IS NULL))
         ORDER BY sort_order ASC, id ASC
         FOR UPDATE"
    );
    $stmt->execute([$user_id, $parent_id, $parent_id]);

    $siblings = $stmt->fetchAll();

    foreach ($siblings as $index => $sibling) {
        if ((int)$sibling['sort_order'] !== $index) {
            $stmtUpdate = $pdo->prepare("UPDATE directories SET sort_order = ? WHERE id = ? AND user_id = ?");
            $stmtUpdate->execute([$index, $sibling['id'], $user_id]);
        }
    }

    return count($siblings);
}

function getNextSiblingSortOrder($pdo, $user_id, $parent_id) {
    $count = normalizeSiblingSortOrders($pdo, $user_id, $parent_id);
    return $count;
}

function getSortOrderForNewSibling($pdo, $user_id, $parent_id, $parentPref) {
    $count = normalizeSiblingSortOrders($pdo, $user_id, $parent_id);

    if ($parentPref === 'start') {
        $stmtShift = $pdo->prepare(
            "UPDATE directories
             SET sort_order = sort_order + 1
             WHERE user_id = ?
               AND (parent_id = ? OR (parent_id IS NULL AND ? IS NULL))"
        );
        $stmtShift->execute([$user_id, $parent_id, $parent_id]);
        return 0;
    }

    return $count;
}

function normalizeDeckLanguageForDirectory($value, $default = 'pt-BR') {
    $allowed = ['pt-BR', 'en-US', 'en-GB'];
    return in_array($value, $allowed, true) ? $value : $default;
}

function normalizeDeckStructureForDirectory($value, $default = 'fatos') {
    $allowed = ['fatos', 'perguntas', 'traducoes', 'parafrases'];
    return in_array($value, $allowed, true) ? $value : $default;
}

function normalizeChildDefaultType($value, $default = 0) {
    $allowed = [0, 1, 2, 4];
    $type = (int)$value;
    return in_array($type, $allowed, true) ? $type : (int)$default;
}

function getDirectoryTypeById(PDO $pdo, int $user_id, ?int $directory_id): ?int {
    if ($directory_id === null) return null;
    $stmt = $pdo->prepare("SELECT type FROM directories WHERE id = ? AND user_id = ? LIMIT 1");
    $stmt->execute([$directory_id, $user_id]);
    $row = $stmt->fetch();
    if (!$row) return null;
    return (int)$row['type'];
}

function validateDirectoryHierarchy(PDO $pdo, int $user_id, ?int $parent_id, int $type): ?string {
    $parentType = getDirectoryTypeById($pdo, $user_id, $parent_id);
    if ($parent_id !== null && $parentType === null) {
        return 'Diretório pai inválido.';
    }

    if (!in_array($type, [0, 1, 2, 4], true)) return 'Tipo de diretório não suportado.';

    return null;
}

function ensureLegacyDirectoryTypesCleanup(PDO $pdo, int $user_id): void {
    static $alreadyRan = false;
    if ($alreadyRan) return;
    $alreadyRan = true;

    $stmt = $pdo->prepare("DELETE FROM directories WHERE user_id = ? AND type IN (3,5,6,7,8,9,10)");
    $stmt->execute([$user_id]);

    foreach (['adjacency_items', 'conditional_items', 'plano_meta', 'track_node_slides', 'track_generation_jobs', 'track_user_progress', 'track_nodes', 'track_node_links', 'map_nodes', 'map_edges'] as $table) {
        try { $pdo->exec("DROP TABLE IF EXISTS {$table}"); } catch (Throwable $e) {}
    }
}

function normalizeChildDefaultView($value, $default = 'grid') {
    $allowed = ['grid', 'list', 'kanban'];
    return in_array($value, $allowed, true) ? $value : $default;
}

function ensureScheduleTagTables(PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS schedule_tags (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            name VARCHAR(80) NOT NULL,
            color VARCHAR(7) NOT NULL DEFAULT '#3b82f6',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_user_tag_name (user_id, name),
            INDEX idx_user_id (user_id),
            CONSTRAINT fk_schedule_tags_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS directory_tag_links (
            directory_id INT UNSIGNED NOT NULL,
            tag_id INT UNSIGNED NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (directory_id, tag_id),
            INDEX idx_tag_id (tag_id),
            CONSTRAINT fk_directory_tag_links_directory FOREIGN KEY (directory_id) REFERENCES directories(id) ON DELETE CASCADE,
            CONSTRAINT fk_directory_tag_links_tag FOREIGN KEY (tag_id) REFERENCES schedule_tags(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function normalizeTagIds($value): array {
    if (!is_array($value)) return [];
    $clean = [];
    foreach ($value as $id) {
        $tagId = (int)$id;
        if ($tagId > 0) $clean[] = $tagId;
    }
    return array_values(array_unique($clean));
}

function getFolderDeckPresetForParentChain($pdo, $user_id, $parent_id) {
    $front = 'pt-BR';
    $back = 'en-GB';
    $structure = 'fatos';

    if ($parent_id === null) {
        return [$front, $back, $structure];
    }

    $current = (int)$parent_id;
    while ($current > 0) {
        $stmt = $pdo->prepare("SELECT id, parent_id, type, deck_front_language, deck_back_language, deck_structure FROM directories WHERE id = ? AND user_id = ? LIMIT 1");
        $stmt->execute([$current, $user_id]);
        $row = $stmt->fetch();
        if (!$row) {
            break;
        }

        if ((int)$row['type'] === 0) {
            $front = normalizeDeckLanguageForDirectory($row['deck_front_language'] ?? 'pt-BR', 'pt-BR');
            $back = normalizeDeckLanguageForDirectory($row['deck_back_language'] ?? 'en-GB', 'en-GB');
            $structure = normalizeDeckStructureForDirectory($row['deck_structure'] ?? 'fatos', 'fatos');
            break;
        }

        if ($row['parent_id'] === null) {
            break;
        }
        $current = (int)$row['parent_id'];
    }

    return [$front, $back, $structure];
}




function ensureDirectoriesCompletionColumn(PDO $pdo): void {
    $stmt = $pdo->query("SHOW COLUMNS FROM directories LIKE 'is_completed'");
    $exists = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
    if (!$exists) {
        $pdo->exec("ALTER TABLE directories ADD COLUMN is_completed TINYINT(1) NOT NULL DEFAULT 0 AFTER is_recurring");
        $pdo->exec("CREATE INDEX idx_is_completed ON directories (is_completed)");
    }
}
// =========================================================================
// ROTAS DA API
// =========================================================================

if ($action === 'fetch') {
    ensureScheduleTagTables($pdo);
    ensureDirectoriesCompletionColumn($pdo);
    $parent_id = isset($input['parent_id']) && $input['parent_id'] !== null ? (int)$input['parent_id'] : null;
    $target_user_id = isset($input['target_user_id']) ? (int)$input['target_user_id'] : $user_id;
    $effective_target_user_id = $target_user_id;
    $is_owner_context = ($effective_target_user_id === (int)$user_id);

    if ($parent_id !== null && $is_owner_context) {
        $stmtParentOwner = $pdo->prepare("SELECT user_id, is_public FROM directories WHERE id = ?");
        $stmtParentOwner->execute([$parent_id]);
        $parentOwner = $stmtParentOwner->fetch();

        if ($parentOwner && (int)$parentOwner['user_id'] !== $user_id) {
            $stmtSavedParent = $pdo->prepare("SELECT 1 FROM saved_directories WHERE user_id = ? AND directory_id = ?");
            $stmtSavedParent->execute([$user_id, $parent_id]);
            if (!$stmtSavedParent->fetchColumn() || (int)$parentOwner['is_public'] !== 1) {
                echo json_encode(['status' => 'success', 'data' => []]);
                exit;
            }

            $effective_target_user_id = (int)$parentOwner['user_id'];
            $is_owner_context = false;
        }
    }

    $query = "
        SELECT d.id, d.type, d.target_id, d.name_encrypted, d.parent_id, d.default_view, d.open_mode, d.deck_mode,
               d.deck_front_language, d.deck_back_language, d.deck_structure,
               d.new_item_position, d.sort_order, d.icon, d.icon_color_from, d.icon_color_to, 
               d.cover_url_encrypted, d.start_date, d.end_date, d.is_recurring, d.is_completed, d.is_public, d.child_default_type, d.child_default_view,
               COALESCE(deck_stats.total_cards, 0) as deck_total_cards,
               COALESCE(deck_stats.total_score, 0) as deck_total_score,
               COALESCE(deck_stats.due_cards, 0) as deck_due_cards,
               COALESCE(book_progress.current_index, 0) as book_current_index,
               COALESCE(book_progress.completed_reads, 0) as book_completed_reads,
               book_progress.next_review_at as book_next_review_at,
               dr.type as rec_type, dr.interval_value as rec_interval, dr.days_of_week as rec_days, 
               dr.custom_dates as rec_custom, dr.exceptions as rec_exceptions, dr.time_start as rec_time_start, dr.time_end as rec_time_end, dr.end_date as rec_end 
        FROM directories d 
        LEFT JOIN directory_recurrences dr ON d.id = dr.directory_id
        LEFT JOIN (
            SELECT f.directory_id,
                   COUNT(f.id) as total_cards,
                   COALESCE(SUM(fs.score), 0) as total_score,
                   COALESCE(SUM(CASE WHEN fs.next_review_at IS NULL OR fs.next_review_at <= NOW() THEN 1 ELSE 0 END), 0) as due_cards
            FROM flashcards f
            INNER JOIN directories fd ON fd.id = f.directory_id AND fd.user_id = ?
            LEFT JOIN flashcard_scores fs ON fs.flashcard_id = f.id AND fs.user_id = ?
            GROUP BY f.directory_id
        ) deck_stats ON deck_stats.directory_id = d.id
        LEFT JOIN flashcard_book_progress book_progress ON book_progress.directory_id = d.id AND book_progress.user_id = ?
        WHERE d.user_id = ? 
    ";
    
    if (!$is_owner_context) {
        $query .= " AND d.is_public = 1";
    }

    if ($parent_id === null) {
        $query .= " AND d.parent_id IS NULL";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$effective_target_user_id, $user_id, $user_id, $effective_target_user_id]);
    } else {
        if (!$is_owner_context) {
            $stmtParent = $pdo->prepare("SELECT user_id, is_public FROM directories WHERE id = ?");
            $stmtParent->execute([$parent_id]);
            $parent = $stmtParent->fetch();
            if (!$parent || (int)$parent['user_id'] !== $effective_target_user_id || (int)$parent['is_public'] !== 1) {
                echo json_encode(['status' => 'success', 'data' => []]);
                exit;
            }
        }

        $query .= " AND d.parent_id = ?";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$effective_target_user_id, $user_id, $user_id, $effective_target_user_id, $parent_id]);
    }

    $directories = $stmt->fetchAll();

    if ($parent_id === null && $is_owner_context) {
        $stmtSavedRoot = $pdo->prepare("\n            SELECT d.id, d.type, d.target_id, d.name_encrypted, d.parent_id, d.default_view, d.open_mode, d.deck_mode,\n                   d.deck_front_language, d.deck_back_language, d.deck_structure,\n                   d.new_item_position, d.sort_order, d.icon, d.icon_color_from, d.icon_color_to,\n                   d.cover_url_encrypted, d.start_date, d.end_date, d.is_recurring, d.is_completed, d.is_public, d.child_default_type, d.child_default_view,\n                   COALESCE(deck_stats.total_cards, 0) as deck_total_cards,\n                   COALESCE(deck_stats.total_score, 0) as deck_total_score,
               COALESCE(deck_stats.due_cards, 0) as deck_due_cards,\n                   COALESCE(book_progress.current_index, 0) as book_current_index,\n                   COALESCE(book_progress.completed_reads, 0) as book_completed_reads,
               book_progress.next_review_at as book_next_review_at,\n                   dr.type as rec_type, dr.interval_value as rec_interval, dr.days_of_week as rec_days,\n                   dr.custom_dates as rec_custom, dr.exceptions as rec_exceptions, dr.time_start as rec_time_start, dr.time_end as rec_time_end, dr.end_date as rec_end,\n                   d.user_id as owner_user_id\n            FROM saved_directories sd\n            INNER JOIN directories d ON d.id = sd.directory_id\n            LEFT JOIN directory_recurrences dr ON d.id = dr.directory_id\n            LEFT JOIN (\n                SELECT f.directory_id,\n                       COUNT(f.id) as total_cards,\n                       COALESCE(SUM(fs.score), 0) as total_score,
                   COALESCE(SUM(CASE WHEN fs.next_review_at IS NULL OR fs.next_review_at <= NOW() THEN 1 ELSE 0 END), 0) as due_cards\n                FROM flashcards f\n                INNER JOIN saved_directories sd_filter ON sd_filter.directory_id = f.directory_id AND sd_filter.user_id = ?\n                LEFT JOIN flashcard_scores fs ON fs.flashcard_id = f.id AND fs.user_id = ?\n                GROUP BY f.directory_id\n            ) deck_stats ON deck_stats.directory_id = d.id\n            LEFT JOIN flashcard_book_progress book_progress ON book_progress.directory_id = d.id AND book_progress.user_id = ?\n            WHERE sd.user_id = ? AND d.user_id != ? AND d.is_public = 1 AND d.parent_id IS NULL\n        ");
        $stmtSavedRoot->execute([$user_id, $user_id, $user_id, $user_id, $user_id]);
        $directories = array_merge($directories, $stmtSavedRoot->fetchAll());
    }
    elseif ($parent_id === null && !$is_owner_context) {
        $stmtSavedRootTarget = $pdo->prepare("\n            SELECT d.id, d.type, d.target_id, d.name_encrypted, d.parent_id, d.default_view, d.open_mode, d.deck_mode,\n                   d.deck_front_language, d.deck_back_language, d.deck_structure,\n                   d.new_item_position, d.sort_order, d.icon, d.icon_color_from, d.icon_color_to,\n                   d.cover_url_encrypted, d.start_date, d.end_date, d.is_recurring, d.is_completed, d.is_public, d.child_default_type, d.child_default_view,\n                   COALESCE(deck_stats.total_cards, 0) as deck_total_cards,\n                   COALESCE(deck_stats.total_score, 0) as deck_total_score,
               COALESCE(deck_stats.due_cards, 0) as deck_due_cards,\n                   COALESCE(book_progress.current_index, 0) as book_current_index,\n                   COALESCE(book_progress.completed_reads, 0) as book_completed_reads,
               book_progress.next_review_at as book_next_review_at,\n                   dr.type as rec_type, dr.interval_value as rec_interval, dr.days_of_week as rec_days,\n                   dr.custom_dates as rec_custom, dr.exceptions as rec_exceptions, dr.time_start as rec_time_start, dr.time_end as rec_time_end, dr.end_date as rec_end,\n                   d.user_id as owner_user_id\n            FROM saved_directories sd\n            INNER JOIN directories d ON d.id = sd.directory_id\n            LEFT JOIN directory_recurrences dr ON d.id = dr.directory_id\n            LEFT JOIN (\n                SELECT f.directory_id,\n                       COUNT(f.id) as total_cards,\n                       COALESCE(SUM(fs.score), 0) as total_score,
                   COALESCE(SUM(CASE WHEN fs.next_review_at IS NULL OR fs.next_review_at <= NOW() THEN 1 ELSE 0 END), 0) as due_cards\n                FROM flashcards f\n                INNER JOIN saved_directories sd_filter ON sd_filter.directory_id = f.directory_id AND sd_filter.user_id = ?\n                LEFT JOIN flashcard_scores fs ON fs.flashcard_id = f.id AND fs.user_id = ?\n                GROUP BY f.directory_id\n            ) deck_stats ON deck_stats.directory_id = d.id\n            LEFT JOIN flashcard_book_progress book_progress ON book_progress.directory_id = d.id AND book_progress.user_id = ?\n            WHERE sd.user_id = ? AND d.user_id != ? AND d.is_public = 1 AND d.parent_id IS NULL\n        ");
        $stmtSavedRootTarget->execute([$effective_target_user_id, $user_id, $user_id, $effective_target_user_id, $effective_target_user_id]);
        $directories = array_merge($directories, $stmtSavedRootTarget->fetchAll());
    }
    $response = [];
    
    $directoryIds = array_map(static fn($dir) => (int)$dir['id'], $directories);
    $tagsByDirectory = [];
    if (!empty($directoryIds)) {
        $placeholders = implode(',', array_fill(0, count($directoryIds), '?'));
        $stmtTags = $pdo->prepare(
            "SELECT dtl.directory_id, st.id, st.name, st.color
             FROM directory_tag_links dtl
             INNER JOIN schedule_tags st ON st.id = dtl.tag_id
             WHERE st.user_id = ? AND dtl.directory_id IN ($placeholders)
             ORDER BY st.name ASC"
        );
        $stmtTags->execute(array_merge([$user_id], $directoryIds));
        foreach ($stmtTags->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $dirId = (int)$row['directory_id'];
            if (!isset($tagsByDirectory[$dirId])) $tagsByDirectory[$dirId] = [];
            $tagsByDirectory[$dirId][] = [
                'id' => (int)$row['id'],
                'name' => $row['name'],
                'color' => $row['color']
            ];
        }
    }

    foreach ($directories as $dir) {
        $dirId = (int)$dir['id'];
        $deckTotalCards = (int)($dir['deck_total_cards'] ?? 0);
        $deckTotalScore = (int)($dir['deck_total_score'] ?? 0);
        $deckDueCards = (int)($dir['deck_due_cards'] ?? 0);
        $deckPercentage = $deckTotalCards > 0 ? (int)round(($deckTotalScore / ($deckTotalCards * 20)) * 100) : 0;
        $isBookDeck = (($dir['deck_mode'] ?? 'aleatorio') === 'livro');
        $bookCurrentIndex = (int)($dir['book_current_index'] ?? 0);
        $bookCompletedReads = (int)($dir['book_completed_reads'] ?? 0);
        $bookNextReviewAt = $dir['book_next_review_at'] ?? null;
        if ($isBookDeck) {
            $bookHasDueReview = empty($bookNextReviewAt) || strtotime($bookNextReviewAt) <= time();
            $deckDueCards = ($deckTotalCards > 0 && $bookHasDueReview) ? $deckTotalCards : 0;
        }
        $bookPercentage = $deckTotalCards > 0 ? (int)round((min($bookCurrentIndex, $deckTotalCards) / $deckTotalCards) * 100) : 0;

        $response[] = [
            'id' => $dir['id'],
            'type' => (int)($dir['type'] ?? 0),
            'target_id' => $dir['target_id'],
            'parent_id' => $dir['parent_id'],
            'view' => $dir['default_view'] ?? 'grid',
            'open_mode' => in_array($dir['open_mode'] ?? '', ['fullscreen', 'preview'], true) ? $dir['open_mode'] : 'fullscreen',
            'new_item_position' => $dir['new_item_position'] ?? 'end',
            'sort_order' => (int)($dir['sort_order'] ?? 0),
            'name' => Security::decryptData($dir['name_encrypted']),
            'icon' => $dir['icon'] ?? 'fa-folder',
            'color_from' => $dir['icon_color_from'] ?? '#3b82f6',
            'color_to' => $dir['icon_color_to'] ?? '#6366f1',
            'cover_url' => !empty($dir['cover_url_encrypted']) ? Security::decryptData($dir['cover_url_encrypted']) : '',
            'deck_mode' => $dir['deck_mode'] ?? 'aleatorio',
            'deck_front_language' => normalizeDeckLanguageForDirectory($dir['deck_front_language'] ?? 'pt-BR', 'pt-BR'),
            'deck_back_language' => normalizeDeckLanguageForDirectory($dir['deck_back_language'] ?? 'en-GB', 'en-GB'),
            'deck_structure' => normalizeDeckStructureForDirectory($dir['deck_structure'] ?? 'fatos', 'fatos'),
            'deck_total_cards' => $deckTotalCards,
            'deck_due_cards' => $deckDueCards,
            'deck_percentage' => $deckPercentage,
            'book_percentage' => $isBookDeck ? $bookPercentage : 0,
            'book_rank' => $isBookDeck ? $bookCompletedReads : 0,
            'start_date' => $dir['start_date'],
            'end_date' => $dir['end_date'],
            'is_recurring' => (int)($dir['is_recurring'] ?? 0),
            'is_completed' => (int)($dir['is_completed'] ?? 0),
            'is_public' => (int)($dir['is_public'] ?? 0),
            'child_default_type' => normalizeChildDefaultType($dir['child_default_type'] ?? 0, 0),
            'child_default_view' => normalizeChildDefaultView($dir['child_default_view'] ?? 'grid', 'grid'),
            'rec_type' => $dir['rec_type'] ?? 'daily',
            'rec_interval' => (int)($dir['rec_interval'] ?? 1),
            'rec_days' => $dir['rec_days'] ?? '',
            'rec_custom' => $dir['rec_custom'] ?? '',
            'rec_exceptions' => $dir['rec_exceptions'] ?? '[]',
            'rec_time_start' => $dir['rec_time_start'] ?? null,
            'rec_time_end' => $dir['rec_time_end'] ?? null,
            'rec_end' => $dir['rec_end'] ?? '',
            'owner_user_id' => isset($dir['owner_user_id']) ? (int)$dir['owner_user_id'] : (int)$effective_target_user_id,
            'is_read_only' => (isset($dir['owner_user_id']) ? (int)$dir['owner_user_id'] : (int)$effective_target_user_id) !== (int)$user_id ? 1 : 0
            ,'tags' => $tagsByDirectory[$dirId] ?? []
        ];
    }

    usort($response, function($a, $b) {
        if ($a['sort_order'] === $b['sort_order']) {
            return strcasecmp($a['name'], $b['name']);
        }
        return $a['sort_order'] <=> $b['sort_order'];
    });

    echo json_encode(['status' => 'success', 'data' => $response]);
} 

elseif ($action === 'get_path') {
    $dir_id = isset($input['id']) && $input['id'] !== null ? (int)$input['id'] : null;
    $target_user_id = isset($input['target_user_id']) ? (int)$input['target_user_id'] : $user_id;
    $is_owner_context = ($target_user_id === (int)$user_id);

    if ($dir_id !== null && $is_owner_context) {
        $stmtDirOwner = $pdo->prepare("SELECT user_id, is_public FROM directories WHERE id = ?");
        $stmtDirOwner->execute([$dir_id]);
        $dirOwner = $stmtDirOwner->fetch();
        if ($dirOwner && (int)$dirOwner['user_id'] !== $user_id) {
            $stmtSaved = $pdo->prepare("SELECT 1 FROM saved_directories WHERE user_id = ? AND directory_id = ?");
            $stmtSaved->execute([$user_id, $dir_id]);
            if ($stmtSaved->fetchColumn() && (int)$dirOwner['is_public'] === 1) {
                $target_user_id = (int)$dirOwner['user_id'];
                $is_owner_context = false;
            }
        }
    }
    $path = [];
    $curr = $dir_id;
    
    while ($curr !== null) {
        $stmt = $pdo->prepare("SELECT id, type, name_encrypted, default_view, open_mode, parent_id, is_public, child_default_type, child_default_view FROM directories WHERE id = ? AND user_id = ?");
        $stmt->execute([$curr, $target_user_id]);
        $dir = $stmt->fetch();
        
        if ($dir) {
            if (!$is_owner_context && (int)$dir['is_public'] !== 1) {
                $path = [];
                break;
            }
            array_unshift($path, [
                'id' => $dir['id'],
                'type' => (int)$dir['type'],
                'name' => Security::decryptData($dir['name_encrypted']),
                'view' => $dir['default_view'],
                'open_mode' => in_array($dir['open_mode'] ?? '', ['fullscreen', 'preview'], true) ? $dir['open_mode'] : 'fullscreen',
                'child_default_type' => normalizeChildDefaultType($dir['child_default_type'] ?? 0, 0),
                'child_default_view' => normalizeChildDefaultView($dir['child_default_view'] ?? 'grid', 'grid')
            ]);
            $curr = $dir['parent_id'];
        } else {
            break;
        }
    }
    
    echo json_encode(['status' => 'success', 'data' => $path]);
}

elseif ($action === 'create') {
    ensureScheduleTagTables($pdo);
    $name = trim($input['name'] ?? '');
    $parent_id = isset($input['parent_id']) && $input['parent_id'] !== null ? (int)$input['parent_id'] : null;
    $type = isset($input['type']) ? (int)$input['type'] : 0; 
    $view = in_array($input['view'] ?? '', ['grid', 'list', 'kanban']) ? $input['view'] : 'grid';
    $new_item_position = in_array($input['new_item_position'] ?? '', ['start', 'end']) ? $input['new_item_position'] : 'end';
    $open_mode = in_array($input['open_mode'] ?? '', ['fullscreen', 'preview'], true) ? $input['open_mode'] : 'fullscreen';
    
    $default_icon = 'fa-folder';
    if($type === 1) $default_icon = 'fa-file-code';
    if($type === 2) $default_icon = 'fa-calendar-days';

    $icon = preg_match('/^fa-[a-z0-9-]+$/', $input['icon'] ?? '') ? $input['icon'] : $default_icon;
    $color_from = preg_match('/^#[a-fA-F0-9]{6}$/', $input['color_from'] ?? '') ? $input['color_from'] : '#3b82f6';
    $color_to = preg_match('/^#[a-fA-F0-9]{6}$/', $input['color_to'] ?? '') ? $input['color_to'] : '#6366f1';
    $cover_url = trim($input['cover_url'] ?? '');

    $start_date = !empty($input['start_date']) ? $input['start_date'] : null;
    $end_date = !empty($input['end_date']) ? $input['end_date'] : null;

    $is_recurring = isset($input['is_recurring']) ? (int)$input['is_recurring'] : 0;
    $is_public = isset($input['is_public']) ? (int)$input['is_public'] : 0;
    $child_default_type = normalizeChildDefaultType($input['child_default_type'] ?? 0, 0);
    $child_default_view = normalizeChildDefaultView($input['child_default_view'] ?? 'grid', 'grid');
    $rec_type = $input['rec_type'] ?? 'daily';
    $rec_interval = (int)($input['rec_interval'] ?? 1);
    $rec_days = !empty($input['rec_days']) ? $input['rec_days'] : null;
    $rec_custom = !empty($input['rec_custom']) ? $input['rec_custom'] : null;
    $rec_end = !empty($input['rec_end']) ? $input['rec_end'] : null;
    $rec_time_start = !empty($input['rec_time_start']) ? $input['rec_time_start'] : null;
    $rec_time_end = !empty($input['rec_time_end']) ? $input['rec_time_end'] : null;
    $deck_front_language = normalizeDeckLanguageForDirectory($input['deck_front_language'] ?? 'pt-BR', 'pt-BR');
    $deck_back_language = normalizeDeckLanguageForDirectory($input['deck_back_language'] ?? 'en-GB', 'en-GB');
    $deck_structure = normalizeDeckStructureForDirectory($input['deck_structure'] ?? 'fatos', 'fatos');
    $tag_ids = normalizeTagIds($input['tag_ids'] ?? []);

    if (empty($name)) {
        die(json_encode(['status' => 'error', 'message' => 'O nome não pode ser vazio.']));
    }

    $hierarchyError = validateDirectoryHierarchy($pdo, $user_id, $parent_id, $type);
    if ($hierarchyError !== null) {
        die(json_encode(['status' => 'error', 'message' => $hierarchyError]));
    }

    $name_encrypted = Security::encryptData($name);
    $cover_url_encrypted = !empty($cover_url) ? Security::encryptData($cover_url) : null;

    $clientParentPref = in_array($input['parent_new_item_position'] ?? '', ['start', 'end']) ? $input['parent_new_item_position'] : null;
    if ($clientParentPref !== null) {
        $parentPref = $clientParentPref;
    } elseif ($parent_id === null) {
        $stmtPref = $pdo->prepare("SELECT root_new_item_position FROM users WHERE id = ?");
        $stmtPref->execute([$user_id]);
        $parentPref = $stmtPref->fetchColumn() ?: 'end';
    } else {
        $stmtPref = $pdo->prepare("SELECT new_item_position FROM directories WHERE id = ? AND user_id = ?");
        $stmtPref->execute([$parent_id, $user_id]);
        $parentPref = $stmtPref->fetchColumn() ?: 'end';
    }

    try {
        $pdo->beginTransaction();

        if ($type === 4) {
            [$deck_front_language, $deck_back_language, $deck_structure] = getFolderDeckPresetForParentChain($pdo, $user_id, $parent_id);
        }


        $newOrder = getSortOrderForNewSibling($pdo, $user_id, $parent_id, $parentPref);

        $stmt = $pdo->prepare("
            INSERT INTO directories (
                user_id, parent_id, type, name_encrypted, default_view, 
                open_mode, new_item_position, sort_order, icon, icon_color_from, 
                icon_color_to, cover_url_encrypted, start_date, end_date, is_recurring, is_public,
                child_default_type, child_default_view,
                deck_front_language, deck_back_language, deck_structure
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $user_id, $parent_id, $type, $name_encrypted, $view, $open_mode, 
            $new_item_position, $newOrder, $icon, $color_from, $color_to, 
            $cover_url_encrypted, $start_date, $end_date, $is_recurring, $is_public,
            $child_default_type, $child_default_view,
            $deck_front_language, $deck_back_language, $deck_structure
        ]);
        
        $new_dir_id = $pdo->lastInsertId();

        if (!empty($tag_ids)) {
            $stmtValidTags = $pdo->prepare("SELECT id FROM schedule_tags WHERE user_id = ? AND id IN (" . implode(',', array_fill(0, count($tag_ids), '?')) . ")");
            $stmtValidTags->execute(array_merge([$user_id], $tag_ids));
            $validTagIds = array_map('intval', $stmtValidTags->fetchAll(PDO::FETCH_COLUMN));

            if (!empty($validTagIds)) {
                $stmtLink = $pdo->prepare("INSERT INTO directory_tag_links (directory_id, tag_id) VALUES (?, ?)");
                foreach ($validTagIds as $tagId) {
                    $stmtLink->execute([$new_dir_id, $tagId]);
                }
            }
        }

        if ($is_recurring) {
            $next_run = calculateNextRunDate($rec_type, $rec_interval, $rec_days, $rec_custom, $start_date, $rec_time_start, $rec_time_end);
            if ($next_run) {
                $stmtRec = $pdo->prepare("
                    INSERT INTO directory_recurrences (
                        directory_id, type, interval_value, days_of_week, 
                        custom_dates, time_start, time_end, end_date, next_run_date
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmtRec->execute([$new_dir_id, $rec_type, $rec_interval, $rec_days, $rec_custom, $rec_time_start, $rec_time_end, $rec_end, $next_run]);
            }
        }

        $pdo->commit();
        echo json_encode(['status' => 'success', 'message' => 'Item criado com sucesso.']);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['status' => 'error', 'message' => 'Erro ao criar item.']);
    }
}

elseif ($action === 'create_portal') {
    $raw_target_parent_id = $input['target_parent_id'] ?? null;
    if ($raw_target_parent_id === '' || $raw_target_parent_id === 'null' || $raw_target_parent_id === 'undefined') {
        $raw_target_parent_id = null;
    }

    if ($raw_target_parent_id !== null && !is_numeric($raw_target_parent_id)) {
        die(json_encode(['status' => 'error', 'message' => 'Destino inválido para criação do portal.']));
    }

    $target_parent_id = $raw_target_parent_id !== null ? (int)$raw_target_parent_id : null;
    if ($target_parent_id !== null && $target_parent_id <= 0) {
        die(json_encode(['status' => 'error', 'message' => 'Destino inválido para criação do portal.']));
    }

    if ($target_parent_id !== null) {
        $stmtParent = $pdo->prepare("SELECT id FROM directories WHERE id = ? AND user_id = ? LIMIT 1");
        $stmtParent->execute([$target_parent_id, $user_id]);
        if (!$stmtParent->fetch()) {
            die(json_encode(['status' => 'error', 'message' => 'Pasta de destino inválida para criar o portal.']));
        }
    }

    $start_date = !empty($input['start_date']) ? $input['start_date'] : null;
    $end_date = !empty($input['end_date']) ? $input['end_date'] : null;

    $stmtUser = $pdo->prepare("SELECT copied_directory_id FROM users WHERE id = ?");
    $stmtUser->execute([$user_id]);
    $target_id = $stmtUser->fetchColumn();

    if (!$target_id) {
        die(json_encode(['status' => 'error', 'message' => 'Nenhum diretório alvo selecionado. Use "Copiar Diretório" antes.']));
    }

    $stmtOrig = $pdo->prepare("SELECT type, target_id, name_encrypted, icon_color_from, icon_color_to FROM directories WHERE id = ? AND user_id = ?");
    $stmtOrig->execute([$target_id, $user_id]);
    $original = $stmtOrig->fetch();

    if (!$original) {
        die(json_encode(['status' => 'error', 'message' => 'O diretório alvo não existe mais ou sem permissão.']));
    }

    $resolved_target_id = (int)$target_id;
    if ((int)($original['type'] ?? 0) === 3) {
        $portalTargetId = isset($original['target_id']) ? (int)$original['target_id'] : 0;
        if ($portalTargetId <= 0) {
            die(json_encode(['status' => 'error', 'message' => 'Portal copiado está corrompido. Copie um diretório válido.']));
        }

        $stmtPortalTarget = $pdo->prepare("SELECT id, name_encrypted, icon_color_from, icon_color_to FROM directories WHERE id = ? AND user_id = ?");
        $stmtPortalTarget->execute([$portalTargetId, $user_id]);
        $portalTarget = $stmtPortalTarget->fetch();

        if (!$portalTarget) {
            die(json_encode(['status' => 'error', 'message' => 'Destino do portal copiado não existe mais. Copie outro diretório.']));
        }

        $resolved_target_id = (int)$portalTargetId;
        $original = $portalTarget;
    }

    $decryptedName = Security::decryptData($original['name_encrypted']);
    $newNameEnc = Security::encryptData($decryptedName);

    try {
        $pdo->beginTransaction();

        $newOrder = getNextSiblingSortOrder($pdo, $user_id, $target_parent_id);

        $stmtInsert = $pdo->prepare("
        INSERT INTO directories (
            user_id, parent_id, target_id, type, name_encrypted, 
            sort_order, icon, icon_color_from, icon_color_to, start_date, end_date
        ) VALUES (?, ?, ?, 3, ?, ?, 'fa-door-open', ?, ?, ?, ?)
    ");
    
        if ($stmtInsert->execute([$user_id, $target_parent_id, $resolved_target_id, $newNameEnc, $newOrder, $original['icon_color_from'], $original['icon_color_to'], $start_date, $end_date])) {
            $newPortalId = (int)$pdo->lastInsertId();
            if ($newPortalId <= 0) {
                throw new RuntimeException('Falha ao recuperar o ID do portal criado.');
            }

            $stmtCreated = $pdo->prepare("SELECT id FROM directories WHERE id = ? AND user_id = ? LIMIT 1");
            $stmtCreated->execute([$newPortalId, $user_id]);
            if (!$stmtCreated->fetch()) {
                throw new RuntimeException('Portal não persistido após inserção.');
            }

            $pdo->prepare("UPDATE users SET copied_directory_id = NULL WHERE id = ?")->execute([$user_id]);
            $pdo->commit();
            echo json_encode(['status' => 'success', 'message' => 'Portal criado com sucesso!']);
        } else {
            $pdo->rollBack();
            echo json_encode(['status' => 'error', 'message' => 'Erro interno ao criar portal.']);
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo json_encode(['status' => 'error', 'message' => 'Erro interno ao criar portal.']);
    }
}

elseif ($action === 'update') {
    ensureScheduleTagTables($pdo);
    $id = (int)($input['id'] ?? 0);
    $name = trim($input['name'] ?? '');
    $view = in_array($input['view'] ?? '', ['grid', 'list', 'kanban']) ? $input['view'] : 'grid';
    $new_item_position = in_array($input['new_item_position'] ?? '', ['start', 'end']) ? $input['new_item_position'] : 'end';
    $open_mode = in_array($input['open_mode'] ?? '', ['fullscreen', 'preview'], true) ? $input['open_mode'] : 'fullscreen';
    
    $icon = preg_match('/^fa-[a-z0-9-]+$/', $input['icon'] ?? '') ? $input['icon'] : 'fa-folder';
    $color_from = preg_match('/^#[a-fA-F0-9]{6}$/', $input['color_from'] ?? '') ? $input['color_from'] : '#3b82f6';
    $color_to = preg_match('/^#[a-fA-F0-9]{6}$/', $input['color_to'] ?? '') ? $input['color_to'] : '#6366f1';
    $cover_url = trim($input['cover_url'] ?? '');

    $is_recurring = isset($input['is_recurring']) ? (int)$input['is_recurring'] : 0;
    $is_public = isset($input['is_public']) ? (int)$input['is_public'] : 0;
    $child_default_type = normalizeChildDefaultType($input['child_default_type'] ?? 0, 0);
    $child_default_view = normalizeChildDefaultView($input['child_default_view'] ?? 'grid', 'grid');
    $rec_type = $input['rec_type'] ?? 'daily';
    $rec_interval = (int)($input['rec_interval'] ?? 1);
    $rec_days = !empty($input['rec_days']) ? $input['rec_days'] : null;
    $rec_custom = !empty($input['rec_custom']) ? $input['rec_custom'] : null;
    $rec_end = !empty($input['rec_end']) ? $input['rec_end'] : null;
    $rec_time_start = !empty($input['rec_time_start']) ? $input['rec_time_start'] : null;
    $rec_time_end = !empty($input['rec_time_end']) ? $input['rec_time_end'] : null;
    $deck_front_language = normalizeDeckLanguageForDirectory($input['deck_front_language'] ?? 'pt-BR', 'pt-BR');
    $deck_back_language = normalizeDeckLanguageForDirectory($input['deck_back_language'] ?? 'en-GB', 'en-GB');
    $deck_structure = normalizeDeckStructureForDirectory($input['deck_structure'] ?? 'fatos', 'fatos');
    $tag_ids = normalizeTagIds($input['tag_ids'] ?? []);

    if (empty($name) || $id === 0) {
        die(json_encode(['status' => 'error', 'message' => 'Dados inválidos.']));
    }

    $stmtType = $pdo->prepare("SELECT type FROM directories WHERE id = ? AND user_id = ?");
    $stmtType->execute([$id, $user_id]);
    $currentType = (int)$stmtType->fetchColumn();
    $type = isset($input['type']) ? (int)$input['type'] : $currentType;
    if ($type === 3) {
        $icon = 'fa-door-open';
    }

    $stmtParent = $pdo->prepare("SELECT parent_id FROM directories WHERE id = ? AND user_id = ?");
    $stmtParent->execute([$id, $user_id]);
    $currentParentIdRaw = $stmtParent->fetchColumn();
    $currentParentId = $currentParentIdRaw !== false && $currentParentIdRaw !== null ? (int)$currentParentIdRaw : null;
    $hierarchyError = validateDirectoryHierarchy($pdo, $user_id, $currentParentId, $type);
    if ($hierarchyError !== null) {
        die(json_encode(['status' => 'error', 'message' => $hierarchyError]));
    }

    $name_encrypted = Security::encryptData($name);
    $cover_url_encrypted = !empty($cover_url) ? Security::encryptData($cover_url) : null;
    
    try {
        $pdo->beginTransaction();

        if (array_key_exists('start_date', $input) || array_key_exists('end_date', $input)) {
            $start_date = !empty($input['start_date']) ? $input['start_date'] : null;
            $end_date = !empty($input['end_date']) ? $input['end_date'] : null;
            
            $stmt = $pdo->prepare("
                UPDATE directories SET 
                    type = ?, name_encrypted = ?, default_view = ?, new_item_position = ?, 
                    open_mode = ?,
                    icon = ?, icon_color_from = ?, icon_color_to = ?, cover_url_encrypted = ?, 
                    start_date = ?, end_date = ?, is_recurring = ?, is_public = ?,
                    child_default_type = ?, child_default_view = ?,
                    deck_front_language = ?, deck_back_language = ?, deck_structure = ?
                WHERE id = ? AND user_id = ?
            ");
            $stmt->execute([$type, $name_encrypted, $view, $new_item_position, $open_mode, $icon, $color_from, $color_to, $cover_url_encrypted, $start_date, $end_date, $is_recurring, $is_public, $child_default_type, $child_default_view, $deck_front_language, $deck_back_language, $deck_structure, $id, $user_id]);
        } else {
            $stmt = $pdo->prepare("
                UPDATE directories SET 
                    type = ?, name_encrypted = ?, default_view = ?, new_item_position = ?, 
                    open_mode = ?,
                    icon = ?, icon_color_from = ?, icon_color_to = ?, cover_url_encrypted = ?, 
                    is_recurring = ?, is_public = ?,
                    child_default_type = ?, child_default_view = ?,
                    deck_front_language = ?, deck_back_language = ?, deck_structure = ?
                WHERE id = ? AND user_id = ?
            ");
            $stmt->execute([$type, $name_encrypted, $view, $new_item_position, $open_mode, $icon, $color_from, $color_to, $cover_url_encrypted, $is_recurring, $is_public, $child_default_type, $child_default_view, $deck_front_language, $deck_back_language, $deck_structure, $id, $user_id]);
        }

        // Se ativado, processa a recorrência salvando os dados
        if ($is_recurring) {
            $base_date = $start_date ?? null; 
            $next_run = calculateNextRunDate($rec_type, $rec_interval, $rec_days, $rec_custom, $base_date, $rec_time_start, $rec_time_end);
            
            // Busca as exceções antigas para não apagá-las durante um simples update de nome
            $stmtEx = $pdo->prepare("SELECT exceptions FROM directory_recurrences WHERE directory_id = ?");
            $stmtEx->execute([$id]);
            $existing_rec = $stmtEx->fetch();
            $existing_exceptions = $existing_rec ? $existing_rec['exceptions'] : null;

            $stmtRec = $pdo->prepare("
                INSERT INTO directory_recurrences (
                    directory_id, type, interval_value, days_of_week, custom_dates, exceptions, time_start, time_end, end_date, next_run_date
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?) 
                ON DUPLICATE KEY UPDATE 
                    type=VALUES(type), 
                    interval_value=VALUES(interval_value), 
                    days_of_week=VALUES(days_of_week), 
                    custom_dates=VALUES(custom_dates), 
                    exceptions=VALUES(exceptions), 
                    time_start=VALUES(time_start),
                    time_end=VALUES(time_end),
                    end_date=VALUES(end_date), 
                    next_run_date=VALUES(next_run_date)
            ");
            $stmtRec->execute([$id, $rec_type, $rec_interval, $rec_days, $rec_custom, $existing_exceptions, $rec_time_start, $rec_time_end, $rec_end, $next_run]);
        } else {
            $pdo->prepare("DELETE FROM directory_recurrences WHERE directory_id = ?")->execute([$id]);
        }

        $pdo->prepare("DELETE FROM directory_tag_links WHERE directory_id = ?")->execute([$id]);
        if (!empty($tag_ids)) {
            $stmtValidTags = $pdo->prepare("SELECT id FROM schedule_tags WHERE user_id = ? AND id IN (" . implode(',', array_fill(0, count($tag_ids), '?')) . ")");
            $stmtValidTags->execute(array_merge([$user_id], $tag_ids));
            $validTagIds = array_map('intval', $stmtValidTags->fetchAll(PDO::FETCH_COLUMN));

            if (!empty($validTagIds)) {
                $stmtLink = $pdo->prepare("INSERT INTO directory_tag_links (directory_id, tag_id) VALUES (?, ?)");
                foreach ($validTagIds as $tagId) {
                    $stmtLink->execute([$id, $tagId]);
                }
            }
        }

        $pdo->commit();
        echo json_encode(['status' => 'success', 'message' => 'Item atualizado.']);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['status' => 'error', 'message' => 'Erro ao atualizar.']);
    }
}

elseif ($action === 'reorder') {
    $order = $input['order'] ?? [];
    $has_parent_id = array_key_exists('parent_id', $input);
    $new_parent_id = $has_parent_id ? $input['parent_id'] : null;

    if (!is_array($order)) {
        die(json_encode(['status' => 'error', 'message' => 'Formato de ordem inválido.']));
    }

    try {
        $pdo->beginTransaction();
        
        if ($has_parent_id) {
            $stmt = $pdo->prepare("UPDATE directories SET sort_order = ?, parent_id = ? WHERE id = ? AND user_id = ?");
            foreach ($order as $index => $id) { 
                $stmt->execute([$index, $new_parent_id, (int)$id, $user_id]); 
            }
        } else {
            $stmt = $pdo->prepare("UPDATE directories SET sort_order = ? WHERE id = ? AND user_id = ?");
            foreach ($order as $index => $id) { 
                $stmt->execute([$index, (int)$id, $user_id]); 
            }
        }
        
        $pdo->commit();
        echo json_encode(['status' => 'success', 'message' => 'Ordem atualizada.']);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['status' => 'error', 'message' => 'Erro ao reordenar.']);
    }
}

elseif ($action === 'delete') {
    $id = (int)($input['id'] ?? 0);
    $scope = $input['scope'] ?? 'all'; 
    $target_date_str = $input['target_date'] ?? null; 

    if ($id === 0) {
        die(json_encode(['status' => 'error', 'message' => 'ID inválido.']));
    }

    $stmtProtected = $pdo->prepare("SELECT source_directory_id FROM users WHERE id = ? LIMIT 1");
    $stmtProtected->execute([$user_id]);
    $source_directory_id = (int)($stmtProtected->fetchColumn() ?: 0);
    if ($source_directory_id > 0 && $id === $source_directory_id) {
        die(json_encode(['status' => 'error', 'message' => 'Este diretório é obrigatório da sua conta e não pode ser excluído.']));
    }

    if ($scope === 'single') {
        if (!$target_date_str) {
            die(json_encode(['status' => 'error', 'message' => 'Data alvo para exclusão não foi informada.']));
        }

        $stmt = $pdo->prepare(
            "SELECT dr.exceptions, dr.type
             FROM directory_recurrences dr
             INNER JOIN directories d ON d.id = dr.directory_id
             WHERE dr.directory_id = ?
               AND d.user_id = ?
             LIMIT 1"
        );
        $stmt->execute([$id, $user_id]);
        $rec = $stmt->fetch();
        
        if ($rec !== false) {
            $exceptions = !empty($rec['exceptions']) ? json_decode($rec['exceptions'], true) : [];
            if (!is_array($exceptions)) $exceptions = [];
            
            // Regra especial: Se for repetição por hora, a exclusão é do bloco específico (Data + Hora)
            // Se for diária/semanal, exclui o dia todo (Y-m-d).
            if (in_array($rec['type'], ['hourly', 'minutely'], true)) {
                $parsedTarget = DateTime::createFromFormat('Y-m-d H:i:s', $target_date_str)
                    ?: DateTime::createFromFormat('Y-m-d H:i', $target_date_str);
                if (!$parsedTarget) {
                    die(json_encode(['status' => 'error', 'message' => 'Data alvo inválida para ocorrência recorrente.']));
                }
                $exception_value = $parsedTarget->format('Y-m-d H:i:s');
                $msg_date = date('d/m/Y H:i', strtotime($exception_value));
            } else {
                $parsedTarget = DateTime::createFromFormat('Y-m-d', $target_date_str)
                    ?: DateTime::createFromFormat('Y-m-d H:i:s', $target_date_str)
                    ?: DateTime::createFromFormat('Y-m-d H:i', $target_date_str);
                if (!$parsedTarget) {
                    die(json_encode(['status' => 'error', 'message' => 'Data alvo inválida para exclusão desta ocorrência.']));
                }
                $exception_value = $parsedTarget->format('Y-m-d');
                $msg_date = date('d/m/Y', strtotime($exception_value));
            }
            
            if (!in_array($exception_value, $exceptions, true)) {
                $exceptions[] = $exception_value;
                $pdo->prepare("UPDATE directory_recurrences SET exceptions = ? WHERE directory_id = ?")->execute([json_encode($exceptions), $id]);
            }
            
            echo json_encode([
                'status' => 'success', 
                'message' => "A ocorrência de " . $msg_date . " foi removida com sucesso."
            ]);
            exit;
        } else {
            die(json_encode(['status' => 'error', 'message' => 'Regra de repetição não encontrada ou evento não é recorrente.']));
        }
    }

    $stmt = $pdo->prepare("DELETE FROM directories WHERE id = ? AND user_id = ?");
    if ($stmt->execute([$id, $user_id])) {
        echo json_encode(['status' => 'success', 'message' => 'Excluído com sucesso.']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Erro ao excluir.']);
    }
}

elseif ($action === 'paste') {
    $target_parent_id = isset($input['target_parent_id']) && $input['target_parent_id'] !== null ? (int)$input['target_parent_id'] : null;

    $stmtUser = $pdo->prepare("SELECT copied_directory_id FROM users WHERE id = ?");
    $stmtUser->execute([$user_id]);
    $copied_id = $stmtUser->fetchColumn();

    if (!$copied_id) {
        die(json_encode(['status' => 'error', 'message' => 'Nenhum diretório na área de transferência.']));
    }

    $currTarget = $target_parent_id;
    while ($currTarget !== null) {
        if ($currTarget == $copied_id) {
            die(json_encode(['status' => 'error', 'message' => 'Erro: Não é possível colar um diretório dentro dele mesmo.']));
        }
        $stmtCheck = $pdo->prepare("SELECT parent_id FROM directories WHERE id = ? AND user_id = ?");
        $stmtCheck->execute([$currTarget, $user_id]);
        $currTarget = $stmtCheck->fetchColumn() ?: null;
    }

    try {
        $pdo->beginTransaction();
        
        if (duplicateDirectoryTree($copied_id, $target_parent_id, $user_id, $pdo, true)) {
            $pdo->commit();
            echo json_encode(['status' => 'success', 'message' => 'Diretório colado com sucesso!']);
        } else {
            $pdo->rollBack();
            echo json_encode(['status' => 'error', 'message' => 'Erro ao colar: Diretório original não encontrado.']);
        }
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['status' => 'error', 'message' => 'Erro interno na base de dados ao colar o diretório.']);
    }
}

elseif ($action === 'move') {
    $target_parent_id = isset($input['target_parent_id']) && $input['target_parent_id'] !== null ? (int)$input['target_parent_id'] : null;

    $stmtUser = $pdo->prepare("SELECT copied_directory_id FROM users WHERE id = ?");
    $stmtUser->execute([$user_id]);
    $copied_id = $stmtUser->fetchColumn();

    if (!$copied_id) {
        die(json_encode(['status' => 'error', 'message' => 'Nenhum diretório selecionado para mover.']));
    }

    $currTarget = $target_parent_id;
    while ($currTarget !== null) {
        if ($currTarget == $copied_id) {
            die(json_encode(['status' => 'error', 'message' => 'Erro: Não é possível mover um diretório para dentro dele mesmo ou de seus subdiretórios.']));
        }
        $stmtCheck = $pdo->prepare("SELECT parent_id FROM directories WHERE id = ? AND user_id = ?");
        $stmtCheck->execute([$currTarget, $user_id]);
        $currTarget = $stmtCheck->fetchColumn() ?: null;
    }

    try {
        $pdo->beginTransaction();
        
        $newOrder = getNextSiblingSortOrder($pdo, $user_id, $target_parent_id);

        $pdo->prepare("UPDATE directories SET parent_id = ?, sort_order = ? WHERE id = ? AND user_id = ?")->execute([$target_parent_id, $newOrder, $copied_id, $user_id]);
        $pdo->prepare("UPDATE users SET copied_directory_id = NULL WHERE id = ?")->execute([$user_id]);

        $pdo->commit();
        echo json_encode(['status' => 'success', 'message' => 'Diretório movido com sucesso!']);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['status' => 'error', 'message' => 'Erro interno na base de dados ao mover o diretório.']);
    }
}

else {
    echo json_encode(['status' => 'error', 'message' => 'Ação inválida.']);
}
?>
