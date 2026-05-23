<?php
// Arquivo: flashcards.php
// Diretório: public_html/gluon/api/flashcards.php

/**
 * MICRO-API DE FLASHCARDS
 * Pilar: Seguro, Rápido e Escalável.
 * Gerencia CRUD, Criptografia, Repetição Espaçada, Modos de Deck e Geração de Áudio (TTS).
 * Atualização: Suporte para salvamento e deleção de imagens do Flashcard com criptografia.
 */

require_once __DIR__ . '/../config/database.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('Content-Type: application/json; charset=utf-8');

set_exception_handler(function (Throwable $e) {
    error_log('[flashcards][uncaught_exception] ' . $e->getMessage());
    if (!headers_sent()) http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Erro interno da API de flashcards.']);
});

register_shutdown_function(function () {
    $lastError = error_get_last();
    if ($lastError && in_array($lastError['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        error_log('[flashcards][fatal] ' . ($lastError['message'] ?? 'fatal') . ' @ ' . ($lastError['file'] ?? '-') . ':' . ($lastError['line'] ?? 0));
        if (!headers_sent()) http_response_code(500);
        if (!ob_get_length()) {
            echo json_encode(['status' => 'error', 'message' => 'Falha fatal na API de flashcards.']);
        }
    }
});

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    die(json_encode(['status' => 'error', 'message' => 'Não autorizado. Faça login.']));
}

$pdo = Database::getConnection();
$user_id = $_SESSION['user_id'];
$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? ($_GET['action'] ?? '');

// =========================================================================
// FAIL-SAFE MIGRATION: Garante que as colunas de imagens existam sem stress
// =========================================================================
try {
    $pdo->exec("ALTER TABLE flashcards ADD COLUMN image_front_encrypted LONGTEXT DEFAULT NULL AFTER back_encrypted");
} catch (PDOException $e) {}

// Garante compatibilidade com cards "apenas verso": frente precisa aceitar NULL
try {
    $pdo->exec("ALTER TABLE flashcards MODIFY COLUMN front_encrypted TEXT DEFAULT NULL");
} catch (PDOException $e) {}

try {
    $pdo->exec("ALTER TABLE flashcards ADD COLUMN image_back_encrypted LONGTEXT DEFAULT NULL AFTER image_front_encrypted");
} catch (PDOException $e) {}

try {
    $pdo->exec("ALTER TABLE flashcards ADD COLUMN audio_front_encrypted LONGTEXT DEFAULT NULL AFTER image_back_encrypted");
} catch (PDOException $e) {}

try {
    $pdo->exec("ALTER TABLE flashcards ADD COLUMN audio_back_encrypted LONGTEXT DEFAULT NULL AFTER audio_front_encrypted");
} catch (PDOException $e) {}

try {
    $pdo->exec("ALTER TABLE directories ADD COLUMN deck_front_language VARCHAR(10) NOT NULL DEFAULT 'pt-BR' AFTER deck_mode");
} catch (PDOException $e) {}

try {
    $pdo->exec("ALTER TABLE directories ADD COLUMN deck_back_language VARCHAR(10) NOT NULL DEFAULT 'en-GB' AFTER deck_front_language");
} catch (PDOException $e) {}

try {
    $pdo->exec("ALTER TABLE directories ADD COLUMN deck_structure VARCHAR(20) NOT NULL DEFAULT 'traducoes' AFTER deck_back_language");
} catch (PDOException $e) {}

try {
    $pdo->exec("ALTER TABLE directories ADD COLUMN deck_generation_base_prompt TEXT DEFAULT NULL AFTER deck_structure");
} catch (PDOException $e) {}

try {
    $pdo->exec("ALTER TABLE flashcard_book_progress ADD COLUMN completed_reads TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER current_index");
} catch (PDOException $e) {}

try {
    $pdo->exec("ALTER TABLE flashcard_book_progress ADD COLUMN next_review_at DATETIME DEFAULT NULL AFTER completed_reads");
} catch (PDOException $e) {}

try {
    $pdo->exec("ALTER TABLE users ADD COLUMN tts_provider VARCHAR(20) NOT NULL DEFAULT 'fishaudio' AFTER home_directory_id");
} catch (PDOException $e) {}


try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS pronuncias (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        language VARCHAR(10) NOT NULL,
        source_text VARCHAR(255) NOT NULL,
        target_text VARCHAR(255) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_language_source (language, source_text),
        INDEX idx_language (language)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (PDOException $e) {}
// =========================================================================


try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS flashcard_batch_jobs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        directory_id INT UNSIGNED NOT NULL,
        topic VARCHAR(200) DEFAULT NULL,
        deck_structure VARCHAR(20) NOT NULL DEFAULT 'traducoes',
        openai_input_file_id VARCHAR(80) DEFAULT NULL,
        openai_batch_id VARCHAR(80) DEFAULT NULL,
        openai_output_file_id VARCHAR(80) DEFAULT NULL,
        openai_error_file_id VARCHAR(80) DEFAULT NULL,
        status VARCHAR(30) NOT NULL DEFAULT 'submitted',
        error_message TEXT DEFAULT NULL,
        result_cards_json LONGTEXT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        completed_at DATETIME DEFAULT NULL,
        INDEX idx_user_deck (user_id, directory_id),
        INDEX idx_openai_batch (openai_batch_id),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (directory_id) REFERENCES directories(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (PDOException $e) {}

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS flashcard_tags (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        name_encrypted TEXT NOT NULL,
        name_pt_br_encrypted TEXT DEFAULT NULL,
        numero INT DEFAULT NULL,
        color VARCHAR(20) NOT NULL DEFAULT '#334155',
        is_book TINYINT(1) NOT NULL DEFAULT 0,
        is_verb_tense TINYINT(1) NOT NULL DEFAULT 0,
        is_sentence_type TINYINT(1) NOT NULL DEFAULT 0,
        is_lexical_chunk TINYINT(1) NOT NULL DEFAULT 0,
        is_relation_type TINYINT(1) NOT NULL DEFAULT 0,
        is_word TINYINT(1) NOT NULL DEFAULT 0,
        is_month TINYINT(1) NOT NULL DEFAULT 0,
        is_day TINYINT(1) NOT NULL DEFAULT 0,
        is_year TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_tag_user (user_id),
        INDEX idx_tag_numero (numero),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (PDOException $e) {}

try {
    $pdo->exec("ALTER TABLE flashcard_tags ADD COLUMN is_book TINYINT(1) NOT NULL DEFAULT 0");
} catch (PDOException $e) {}

try {
    $pdo->exec("ALTER TABLE flashcard_tags ADD COLUMN is_verb_tense TINYINT(1) NOT NULL DEFAULT 0");
} catch (PDOException $e) {}

try {
    $pdo->exec("ALTER TABLE flashcard_tags ADD COLUMN is_sentence_type TINYINT(1) NOT NULL DEFAULT 0");
} catch (PDOException $e) {}

try {
    $pdo->exec("ALTER TABLE flashcard_tags ADD COLUMN is_lexical_chunk TINYINT(1) NOT NULL DEFAULT 0");
} catch (PDOException $e) {}

try {
    $pdo->exec("ALTER TABLE flashcard_tags ADD COLUMN is_relation_type TINYINT(1) NOT NULL DEFAULT 0");
} catch (PDOException $e) {}

try {
    $pdo->exec("ALTER TABLE flashcard_tags ADD COLUMN is_word TINYINT(1) NOT NULL DEFAULT 0");
} catch (PDOException $e) {}

try {
    $pdo->exec("ALTER TABLE flashcard_tags ADD COLUMN is_month TINYINT(1) NOT NULL DEFAULT 0");
} catch (PDOException $e) {}

try {
    $pdo->exec("ALTER TABLE flashcard_tags ADD COLUMN is_day TINYINT(1) NOT NULL DEFAULT 0");
} catch (PDOException $e) {}

try {
    $pdo->exec("ALTER TABLE flashcard_tags ADD COLUMN is_year TINYINT(1) NOT NULL DEFAULT 0");
} catch (PDOException $e) {}

try {
    // Colunas legadas em texto puro (name, name_pt_br) removidas por segurança.
} catch (PDOException $e) {}

try {
    $pdo->exec("ALTER TABLE flashcard_tags ADD COLUMN numero INT DEFAULT NULL AFTER name_pt_br_encrypted");
} catch (PDOException $e) {}

try {
    // Índice legado de coluna removida.
} catch (PDOException $e) {}

try {
    $pdo->exec("ALTER TABLE flashcard_tags ADD COLUMN name_encrypted TEXT DEFAULT NULL AFTER user_id");
} catch (PDOException $e) {}

try {
    $pdo->exec("ALTER TABLE flashcard_tags ADD COLUMN name_pt_br_encrypted TEXT DEFAULT NULL AFTER name_encrypted");
} catch (PDOException $e) {}

try {
    $pdo->exec("ALTER TABLE flashcard_tags ADD INDEX idx_tag_numero (numero)");
} catch (PDOException $e) {}

try {
    $pdo->exec("ALTER TABLE flashcard_tags DROP INDEX uniq_user_tag_name");
} catch (PDOException $e) {}

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS flashcard_tag_links (
        flashcard_id INT UNSIGNED NOT NULL,
        tag_id INT UNSIGNED NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (flashcard_id, tag_id),
        INDEX idx_tag_id (tag_id),
        INDEX idx_flashcard_id (flashcard_id),
        FOREIGN KEY (flashcard_id) REFERENCES flashcards(id) ON DELETE CASCADE,
        FOREIGN KEY (tag_id) REFERENCES flashcard_tags(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (PDOException $e) {}

foreach (['subjects_links', 'objects_links', 'tipo_frasal_links', 'tense_links', 'lexical_chunks_links', 'relation_links', 'words_links'] as $linkTable) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS {$linkTable} (
            flashcard_id INT UNSIGNED NOT NULL,
            tag_id INT UNSIGNED NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (flashcard_id, tag_id),
            INDEX idx_tag_id (tag_id),
            INDEX idx_flashcard_id (flashcard_id),
            FOREIGN KEY (flashcard_id) REFERENCES flashcards(id) ON DELETE CASCADE,
            FOREIGN KEY (tag_id) REFERENCES flashcard_tags(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (PDOException $e) {}
}

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS idiomas_links (
        flashcard_id INT UNSIGNED NOT NULL,
        tag_id INT UNSIGNED NOT NULL,
        segundo_idioma_tag_id INT UNSIGNED NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (flashcard_id, tag_id),
        INDEX idx_tag_id (tag_id),
        INDEX idx_segundo_idioma_tag_id (segundo_idioma_tag_id),
        INDEX idx_flashcard_id (flashcard_id),
        FOREIGN KEY (flashcard_id) REFERENCES flashcards(id) ON DELETE CASCADE,
        FOREIGN KEY (tag_id) REFERENCES flashcard_tags(id) ON DELETE CASCADE,
        FOREIGN KEY (segundo_idioma_tag_id) REFERENCES flashcard_tags(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (PDOException $e) {}

try {
    $pdo->exec("ALTER TABLE idiomas_links ADD COLUMN segundo_idioma_tag_id INT UNSIGNED NULL AFTER tag_id");
} catch (PDOException $e) {}

try {
    $pdo->exec("ALTER TABLE idiomas_links ADD INDEX idx_segundo_idioma_tag_id (segundo_idioma_tag_id)");
} catch (PDOException $e) {}

try {
    $pdo->exec("ALTER TABLE idiomas_links ADD CONSTRAINT fk_idiomas_links_segundo_tag FOREIGN KEY (segundo_idioma_tag_id) REFERENCES flashcard_tags(id) ON DELETE SET NULL");
} catch (PDOException $e) {}

/**
 * Função sanitizeTagIds: Normaliza uma lista de IDs de tags recebida na requisição, mantendo apenas inteiros positivos únicos.
 */
function sanitizeTagIds($rawTagIds): array {
    if (!is_array($rawTagIds)) return [];
    return array_values(array_unique(array_filter(array_map('intval', $rawTagIds), static fn($id) => $id > 0)));
}

function tagCombinationAlreadyExists(PDO $pdo, int $userId, string $name, ?string $namePtBr, ?int $numero, ?int $excludeTagId = null): bool
{
    $sql = "
        SELECT id, name_encrypted, name_pt_br_encrypted, numero
        FROM flashcard_tags
        WHERE user_id = ?
    ";
    $params = [$userId];
    if ($excludeTagId !== null) {
        $sql .= " AND id <> ?";
        $params[] = $excludeTagId;
    }
    $sql .= " ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $rowName = '';
        if (!empty($row['name_encrypted'])) {
            $decrypted = Security::decryptData((string)$row['name_encrypted']);
            $rowName = $decrypted !== false ? (string)$decrypted : '';
        } else {
            $rowName = '';
        }

        $rowNamePtBr = null;
        if (!empty($row['name_pt_br_encrypted'])) {
            $decryptedPtBr = Security::decryptData((string)$row['name_pt_br_encrypted']);
            $rowNamePtBr = $decryptedPtBr !== false ? (string)$decryptedPtBr : null;
        } else {
            $rowNamePtBr = null;
        }

        $rowName = trim($rowName);
        $rowNamePtBr = $rowNamePtBr === null ? null : trim($rowNamePtBr);
        if ($rowNamePtBr === '') $rowNamePtBr = null;
        $rowNumero = ($row['numero'] === null || $row['numero'] === '') ? null : (int)$row['numero'];

        if ($rowName === $name && $rowNamePtBr === $namePtBr && $rowNumero === $numero) {
            return true;
        }
    }
    return false;
}

/**
 * Função getAllowedCardTagLinkTables: Retorna a lista branca das tabelas de vínculo entre cards e tags permitidas para consultas e escrita.
 */

function resolveTagColorByCategory(array $tagFlags): string {
    if (($tagFlags['is_book'] ?? 0) === 1) return '#f59e0b';
    if (($tagFlags['is_verb_tense'] ?? 0) === 1) return '#38bdf8';
    if (($tagFlags['is_sentence_type'] ?? 0) === 1) return '#fb7185';
    if (($tagFlags['is_lexical_chunk'] ?? 0) === 1) return '#d946ef';
    if (($tagFlags['is_relation_type'] ?? 0) === 1) return '#22d3ee';
    if (($tagFlags['is_word'] ?? 0) === 1) return '#84cc16';
    if (($tagFlags['is_month'] ?? 0) === 1) return '#14b8a6';
    if (($tagFlags['is_day'] ?? 0) === 1) return '#6366f1';
    if (($tagFlags['is_year'] ?? 0) === 1) return '#a855f7';
    return '#334155';
}

function getAllowedCardTagLinkTables(): array {
    return ['flashcard_tag_links', 'subjects_links', 'objects_links', 'tipo_frasal_links', 'tense_links', 'lexical_chunks_links', 'relation_links', 'words_links', 'idiomas_links'];
}

/**
 * Função fetchLinkedTagsByCard: Busca as tags vinculadas a vários flashcards em uma tabela de ligação e devolve agrupado por card.
 */
function fetchLinkedTagsByCard(PDO $pdo, string $linkTable, array $cardIds, int $user_id): array {
    $allowedTables = getAllowedCardTagLinkTables();
    if (!in_array($linkTable, $allowedTables, true) || empty($cardIds)) return [];

    $tagPlaceholders = implode(',', array_fill(0, count($cardIds), '?'));
    $stmtTags = $pdo->prepare("
        SELECT l.flashcard_id, t.id AS tag_id, t.name_encrypted, t.name_pt_br_encrypted, t.numero, t.color
        FROM {$linkTable} l
        JOIN flashcard_tags t ON t.id = l.tag_id
        WHERE l.flashcard_id IN ($tagPlaceholders) AND t.user_id IN (?, 5)
        ORDER BY t.id ASC
    ");
    $stmtTags->execute(array_merge($cardIds, [$user_id]));

    $linkedTagsByCard = [];
    foreach ($stmtTags->fetchAll() as $tagRow) {
        $flashcardId = (int)$tagRow['flashcard_id'];
        if (!isset($linkedTagsByCard[$flashcardId])) $linkedTagsByCard[$flashcardId] = [];
        $linkedTagsByCard[$flashcardId][] = [
            'id' => (int)$tagRow['tag_id'],
            'name' => !empty($tagRow['name_encrypted']) ? Security::decryptData($tagRow['name_encrypted']) : '',
            'name_pt_br' => !empty($tagRow['name_pt_br_encrypted']) ? Security::decryptData($tagRow['name_pt_br_encrypted']) : null,
            'numero' => isset($tagRow['numero']) ? (int)$tagRow['numero'] : null,
            'color' => $tagRow['color']
        ];
    }
    return $linkedTagsByCard;
}

/**
 * Função fetchLinkedTagsByCardColumn: Busca tags vinculadas aos cards usando uma coluna específica de tag (principal ou secundária).
 */
function fetchLinkedTagsByCardColumn(PDO $pdo, string $linkTable, string $tagColumn, array $cardIds, int $user_id): array {
    $allowedTables = getAllowedCardTagLinkTables();
    $allowedColumns = ['tag_id', 'segundo_idioma_tag_id'];
    if (!in_array($linkTable, $allowedTables, true) || !in_array($tagColumn, $allowedColumns, true) || empty($cardIds)) return [];

    $tagPlaceholders = implode(',', array_fill(0, count($cardIds), '?'));
    $stmtTags = $pdo->prepare("
        SELECT l.flashcard_id, t.id AS tag_id, t.name_encrypted, t.color
        FROM {$linkTable} l
        JOIN flashcard_tags t ON t.id = l.{$tagColumn}
        WHERE l.flashcard_id IN ($tagPlaceholders) AND t.user_id IN (?, 5)
        ORDER BY t.id ASC
    ");
    $stmtTags->execute(array_merge($cardIds, [$user_id]));

    $linkedTagsByCard = [];
    foreach ($stmtTags->fetchAll() as $tagRow) {
        $flashcardId = (int)$tagRow['flashcard_id'];
        if (!isset($linkedTagsByCard[$flashcardId])) $linkedTagsByCard[$flashcardId] = [];
        $linkedTagsByCard[$flashcardId][] = [
            'id' => (int)$tagRow['tag_id'],
            'name' => !empty($tagRow['name_encrypted']) ? Security::decryptData($tagRow['name_encrypted']) : '',
            'color' => $tagRow['color']
        ];
    }
    return $linkedTagsByCard;
}

/**
 * Função syncCardIdiomaLinks: Sincroniza o vínculo de idiomas de um card, mantendo no máximo um idioma principal e um secundário válidos.
 */
function syncCardIdiomaLinks(PDO $pdo, int $card_id, array $principalTagIds, array $secundarioTagIds, int $user_id): void {
    $pdo->prepare("DELETE l FROM idiomas_links l JOIN flashcards f ON f.id = l.flashcard_id JOIN directories d ON d.id = f.directory_id WHERE l.flashcard_id = ? AND d.user_id = ?")
        ->execute([$card_id, $user_id]);

    $principalTagId = (int)($principalTagIds[0] ?? 0);
    if ($principalTagId <= 0) return;

    $secundarioTagId = (int)($secundarioTagIds[0] ?? 0);
    $stmtPrincipal = $pdo->prepare("SELECT id FROM flashcard_tags WHERE id = ? AND user_id = ?");
    $stmtPrincipal->execute([$principalTagId, $user_id]);
    if (!$stmtPrincipal->fetchColumn()) return;

    $secundarioValue = null;
    if ($secundarioTagId > 0) {
        $stmtSecundario = $pdo->prepare("SELECT id FROM flashcard_tags WHERE id = ? AND user_id = ?");
        $stmtSecundario->execute([$secundarioTagId, $user_id]);
        if ($stmtSecundario->fetchColumn()) $secundarioValue = $secundarioTagId;
    }

    $stmtInsert = $pdo->prepare("
        INSERT INTO idiomas_links (flashcard_id, tag_id, segundo_idioma_tag_id)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE segundo_idioma_tag_id = VALUES(segundo_idioma_tag_id)
    ");
    $stmtInsert->execute([$card_id, $principalTagId, $secundarioValue]);
}

/**
 * Função syncCardTagLinks: Sincroniza os vínculos de tags de um card em uma tabela de ligação, removendo antigos e inserindo os novos válidos.
 */
function syncCardTagLinks(PDO $pdo, string $linkTable, int $card_id, array $tagIds, int $user_id): void {
    $allowedTables = getAllowedCardTagLinkTables();
    if (!in_array($linkTable, $allowedTables, true)) return;

    $pdo->prepare("DELETE l FROM {$linkTable} l JOIN flashcards f ON f.id = l.flashcard_id JOIN directories d ON d.id = f.directory_id WHERE l.flashcard_id = ? AND d.user_id = ?")
        ->execute([$card_id, $user_id]);

    if (empty($tagIds)) return;

    $stmtInsertTag = $pdo->prepare("
        INSERT IGNORE INTO {$linkTable} (flashcard_id, tag_id)
        SELECT ?, t.id FROM flashcard_tags t WHERE t.id = ? AND t.user_id = ?
    ");
    foreach ($tagIds as $tag_id) {
        $stmtInsertTag->execute([$card_id, $tag_id, $user_id]);
    }
}

// Função auxiliar para verificar se o usuário é dono do deck (Segurança IDOR)
/**
 * Função isFlashcardDeckDirectoryType: Verifica se o tipo de diretório representa um deck de flashcards aceito (tradicional ou fase).
 */
function isFlashcardDeckDirectoryType($type): bool {
    $type = (int)$type;
    return $type === 4 || $type === 10; // Deck tradicional ou Fase (deck de fase)
}

/**
 * Função verifyDeckOwnership: Confere se o deck pertence ao usuário e retorna seus metadados de configuração.
 */
function verifyDeckOwnership($pdo, $deck_id, $user_id, bool $allowPublicUserFive = false) {
    if ($allowPublicUserFive) {
        $stmt = $pdo->prepare("SELECT id, type, name_encrypted, deck_mode, deck_front_language, deck_back_language, deck_structure, deck_generation_base_prompt FROM directories WHERE id = ? AND user_id IN (?, 5) AND type IN (4, 10)");
        $stmt->execute([$deck_id, $user_id]);
        return $stmt->fetch();
    }
    $stmt = $pdo->prepare("SELECT id, type, name_encrypted, deck_mode, deck_front_language, deck_back_language, deck_structure, deck_generation_base_prompt FROM directories WHERE id = ? AND user_id = ? AND type IN (4, 10)");
    $stmt->execute([$deck_id, $user_id]);
    return $stmt->fetch();
}

/**
 * Função validatePhaseDeckUnlock: Valida se uma fase pode ser acessada, exigindo que a fase anterior esteja sem revisões pendentes.
 */
function validatePhaseDeckUnlock($pdo, $deck_id, $user_id): ?string {
    $stmtPhase = $pdo->prepare("
        SELECT p.id AS phase_id, p.parent_id AS map_id, m.parent_id AS track_id
        FROM directories p
        INNER JOIN directories m ON m.id = p.parent_id AND m.user_id = p.user_id
        WHERE p.id = ? AND p.user_id = ? AND p.type = 10 AND m.type = 9
        LIMIT 1
    ");
    $stmtPhase->execute([$deck_id, $user_id]);
    $phase = $stmtPhase->fetch();
    if (!$phase) return null;

    $track_id = isset($phase['track_id']) ? (int)$phase['track_id'] : 0;
    if ($track_id <= 0) return null;

    $stmtOrderedPhases = $pdo->prepare("
        SELECT p.id
        FROM directories m
        INNER JOIN directories p ON p.parent_id = m.id AND p.user_id = m.user_id AND p.type = 10
        WHERE m.user_id = ? AND m.type = 9 AND m.parent_id = ?
        ORDER BY m.sort_order ASC, m.id ASC, p.sort_order ASC, p.id ASC
    ");
    $stmtOrderedPhases->execute([$user_id, $track_id]);
    $orderedPhaseIds = array_map('intval', $stmtOrderedPhases->fetchAll(PDO::FETCH_COLUMN));

    $currentIndex = array_search((int)$deck_id, $orderedPhaseIds, true);
    if ($currentIndex === false || $currentIndex === 0) return null;

    $previousPhaseId = (int)$orderedPhaseIds[$currentIndex - 1];
    $stmtPrevStats = $pdo->prepare("
        SELECT
            COUNT(f.id) AS total_cards,
            COALESCE(SUM(CASE WHEN fs.next_review_at IS NULL OR fs.next_review_at <= NOW() THEN 1 ELSE 0 END), 0) AS due_cards
        FROM flashcards f
        LEFT JOIN flashcard_scores fs ON fs.flashcard_id = f.id AND fs.user_id = ?
        WHERE f.directory_id = ?
    ");
    $stmtPrevStats->execute([$user_id, $previousPhaseId]);
    $stats = $stmtPrevStats->fetch();

    $totalCards = (int)($stats['total_cards'] ?? 0);
    $dueCards = (int)($stats['due_cards'] ?? 0);
    if ($totalCards <= 0) {
        return 'Esta fase está bloqueada. A fase anterior ainda não possui flashcards para revisão.';
    }
    if ($dueCards > 0) {
        return 'Esta fase está bloqueada. Revise todos os flashcards da fase anterior para desbloquear.';
    }
    return null;
}


/**
 * Função buildGraphCardsSequence: Monta uma sequência balanceada de cards por tags e score para estudo em lotes.
 */
function buildGraphCardsSequence(array $cards, array $seedTagsByCard, int $batchSize = 6, array $evenTagsSourcesByCard = []): array
{
    // Se não existem cards disponíveis, não tem o que montar.
    // Então a função retorna uma lista vazia.
    if (empty($cards)) return [];

    // Garante que o tamanho do lote nunca seja menor que 1.
    // Se alguém passar 0, -1, -5 etc., vira 1.
    $batchSize = max(1, $batchSize);

    // Array que vai guardar o score de cada card pelo ID.
    // Exemplo:
    // $scoreByCard[10] = 3;
    // $scoreByCard[15] = 0;
    $scoreByCard = [];

    // Percorre todos os cards recebidos.
    foreach ($cards as $card) {
        // Pega o ID do card e associa ao score dele.
        // Se o score não existir, usa 0 como padrão.
        $scoreByCard[(int)$card['id']] = (int)($card['score'] ?? 0);
    }

    // Array que vai inverter a relação:
    //
    // Antes:
    // card 10 tem tag 1 e tag 2
    //
    // Depois:
    // tag 1 tem card 10
    // tag 2 tem card 10
    $cardsByTag = [];

    // Percorre o array que diz quais tags cada card possui.
    foreach ($seedTagsByCard as $cardId => $tags) {
        // Percorre todas as tags daquele card.
        foreach ($tags as $tag) {
            // Pega o ID da tag.
            // Se não existir ID, usa 0.
            $tagId = (int)($tag['id'] ?? 0);

            // Se a tag for inválida, pula para a próxima.
            if ($tagId <= 0) continue;

            // Se ainda não existir uma lista para essa tag,
            // cria uma lista vazia.
            if (!isset($cardsByTag[$tagId])) {
                $cardsByTag[$tagId] = [];
            }

            // Adiciona esse card dentro da lista de cards dessa tag.
            $cardsByTag[$tagId][] = (int)$cardId;
        }
    }

    // Se nenhum card tiver tag, não dá para usar lógica de grafo.
    // Então ele simplesmente ordena os cards pelo menor score.
    if (empty($cardsByTag)) {
        // Ordena os cards:
        // 1. Primeiro pelo menor score.
        // 2. Se o score empatar, pelo menor ID.
        usort(
            $cards,
            static fn($a, $b) =>
                ((int)$a['score'] <=> (int)$b['score'])
                ?: ((int)$a['id'] <=> (int)$b['id'])
        );

        // Transforma cada card no formato de retorno da função.
        // Como não existe tag de decisão, decision_tag fica null.
        // Depois corta a lista para retornar no máximo $batchSize cards.
        return array_slice(
            array_map(
                static fn($c) => [
                    'card_id' => (int)$c['id'],
                    'decision_tag' => null
                ],
                $cards
            ),
            0,
            $batchSize
        );
    }

    // Função interna que calcula a "força" ou "sensibilidade" de uma tag.
    //
    // Ela soma os scores de todos os cards daquela tag.
    //
    // Exemplo:
    // tag 5 tem cards 10, 11 e 12
    // card 10 score 2
    // card 11 score 4
    // card 12 score 1
    //
    // sensibilidade da tag 5 = 2 + 4 + 1 = 7
    $calcTagSens = static function (int $tagId) use (&$cardsByTag, &$scoreByCard): int {
        // Começa a soma em zero.
        $sum = 0;

        // Percorre todos os cards que pertencem a essa tag.
        foreach ($cardsByTag[$tagId] ?? [] as $cid) {
            // Soma o score desse card.
            // Se não existir score para ele, usa 0.
            $sum += (int)($scoreByCard[$cid] ?? 0);
        }

        // Retorna a soma total dos scores dos cards dessa tag.
        return $sum;
    };

    // Função interna que escolhe o card mais fraco dentro de uma tag.
    //
    // "Mais fraco" aqui significa:
    // o card com menor score.
    $pickMinCardInTag = static function (int $tagId, array $avoidCards = []) use (&$cardsByTag, &$scoreByCard): ?int {
        // Guarda o melhor card encontrado até agora.
        $best = null;

        // Guarda o score do melhor card encontrado até agora.
        $bestScore = null;

        // Percorre todos os cards dessa tag.
        foreach ($cardsByTag[$tagId] ?? [] as $cid) {
            // Se esse card está na lista de cards que devem ser evitados,
            // pula ele.
            if (isset($avoidCards[$cid])) continue;

            // Pega o score do card.
            // Se não existir score, considera 0.
            $s = (int)($scoreByCard[$cid] ?? 0);

            // Escolhe esse card se:
            //
            // 1. Ainda não existe nenhum melhor card;
            // ou
            // 2. O score dele é menor que o melhor score atual;
            // ou
            // 3. O score empatou, mas o ID dele é menor.
            if (
                $best === null
                || $s < $bestScore
                || ($s === $bestScore && $cid < $best)
            ) {
                $best = $cid;
                $bestScore = $s;
            }
        }

        // Retorna o ID do card escolhido.
        // Se nenhum card foi encontrado, retorna null.
        return $best;
    };

    // Função interna que escolhe um card "mais forte" dentro de uma tag.
    //
    // Mas ela não pega simplesmente o maior score absoluto.
    // Ela tenta pegar o maior score dentro de faixas:
    //
    // menor que 5,
    // depois menor que 10,
    // depois menor que 15,
    // e assim por diante.
    $pickMaxCardInTag = static function (int $tagId, array $avoidCards = []) use (&$cardsByTag, &$scoreByCard): ?int {
        // Lista dos cards candidatos.
        $candidateIds = [];

        // Percorre todos os cards dessa tag.
        foreach ($cardsByTag[$tagId] ?? [] as $cid) {
            // Se o card já foi usado ou deve ser evitado, pula.
            if (isset($avoidCards[$cid])) continue;

            // Adiciona o card como candidato.
            $candidateIds[] = (int)$cid;
        }

        // Se não sobrou nenhum candidato, retorna null.
        if (empty($candidateIds)) return null;

        // Vai descobrir qual é o maior score entre os candidatos.
        $maxScore = null;

        // Percorre os cards candidatos.
        foreach ($candidateIds as $cid) {
            // Pega o score do card.
            $s = (int)($scoreByCard[$cid] ?? 0);

            // Atualiza o maior score encontrado.
            if ($maxScore === null || $s > $maxScore) {
                $maxScore = $s;
            }
        }

        // Começa procurando cards com score abaixo de 5.
        $limit = 5;

        // Continua aumentando o limite até passar do maior score.
        while ($limit <= ((int)$maxScore + 5)) {
            // Melhor card encontrado dentro dessa faixa.
            $best = null;

            // Score do melhor card encontrado dentro dessa faixa.
            $bestScore = null;

            // Percorre todos os candidatos.
            foreach ($candidateIds as $cid) {
                // Pega o score do card.
                $s = (int)($scoreByCard[$cid] ?? 0);

                // Se o score é maior ou igual ao limite atual,
                // esse card não entra nessa faixa.
                //
                // Exemplo:
                // limite = 5
                // aceita scores 0, 1, 2, 3, 4
                // não aceita 5, 6, 7...
                if ($s >= $limit) continue;

                // Escolhe o card se:
                //
                // 1. Ainda não existe melhor card;
                // ou
                // 2. O score dele é maior que o melhor score atual;
                // ou
                // 3. O score empatou, mas o ID dele é menor.
                if (
                    $best === null
                    || $s > $bestScore
                    || ($s === $bestScore && $cid < $best)
                ) {
                    $best = $cid;
                    $bestScore = $s;
                }
            }

            // Se achou algum card dentro dessa faixa,
            // retorna esse card.
            if ($best !== null) return $best;

            // Se não achou, aumenta o limite em 5.
            //
            // Primeiro tenta menor que 5.
            // Depois menor que 10.
            // Depois menor que 15.
            // etc.
            $limit += 5;
        }

        // Se por algum motivo não encontrou nada,
        // retorna null.
        return null;
    };

    // Pega todos os IDs das tags existentes.
    $tagIds = array_keys($cardsByTag);

    // Ordena as tags pela sensibilidade.
    //
    // A tag com menor soma de scores vem primeiro.
    //
    // Se duas tags tiverem a mesma soma,
    // a tag com menor ID vem primeiro.
    usort($tagIds, static function ($a, $b) use ($calcTagSens) {
        // Calcula a soma dos scores da tag A.
        $sa = $calcTagSens((int)$a);

        // Calcula a soma dos scores da tag B.
        $sb = $calcTagSens((int)$b);

        // Ordena primeiro pela menor soma.
        // Se empatar, ordena pelo menor ID da tag.
        return ($sa <=> $sb) ?: ((int)$a <=> (int)$b);
    });

    // A tag-base será a tag mais fraca,
    // ou seja, a tag com menor soma de scores.
    $baseTagId = (int)$tagIds[0];

    // Lista final dos cards escolhidos.
    $chosen = [];

    // Lista de cards já usados.
    // Serve para impedir repetir o mesmo card no mesmo lote.
    $used = [];

    // Histórico das tags usadas nas escolhas principais.
    $oddDecisionTagsHistory = [];

    // Histórico dos cards escolhidos nas escolhas principais.
    $oddCardsHistory = [];

    // Enquanto a quantidade de cards escolhidos for menor que o tamanho do lote,
    // continua montando a sequência.
    while (count($chosen) < $batchSize) {
        // Escolhe o card mais fraco da tag-base.
        //
        // Esse é o card principal da rodada.
        $oddCard = $pickMinCardInTag($baseTagId, $used);

        // Se não encontrou nenhum card disponível nessa tag,
        // encerra o loop.
        if ($oddCard === null) break;

        // Adiciona o card escolhido na lista final.
        $chosen[] = [
            // ID do card escolhido.
            'card_id' => $oddCard,

            // A tag que decidiu a escolha desse card.
            // Aqui é a tag-base.
            'decision_tag' => $baseTagId,

            // Tags que já tinham sido usadas antes.
            // Isso serve como histórico/explicação da escolha.
            'excluded_tags' => array_values(
                array_unique(
                    array_map('intval', $oddDecisionTagsHistory)
                )
            ),

            // Cards principais que já tinham sido escolhidos antes.
            // Também serve como histórico/explicação.
            'excluded_cards' => array_values(
                array_unique(
                    array_map('intval', $oddCardsHistory)
                )
            ),
        ];

        // Marca esse card como usado,
        // para ele não ser escolhido de novo nesse mesmo lote.
        $used[$oddCard] = true;

        // Guarda a tag-base no histórico de tags principais.
        $oddDecisionTagsHistory[] = $baseTagId;

        // Guarda o card escolhido no histórico de cards principais.
        $oddCardsHistory[] = $oddCard;

        // Aumenta o score desse card apenas localmente.
        //
        // Isso NÃO altera o banco de dados.
        // É só uma simulação dentro dessa função.
        //
        // A ideia é:
        // "se esse card já foi escolhido agora,
        // ele fica um pouco menos prioritário nas próximas escolhas".
        $scoreByCard[$oddCard] = (int)$scoreByCard[$oddCard] + 1;

        // Se já atingiu o tamanho máximo do lote,
        // para aqui.
        if (count($chosen) >= $batchSize) break;

        // Pega todas as tags ligadas ao card principal escolhido.
        //
        // Exemplo:
        // card 10 tem tags 1, 2 e 3
        //
        // então:
        // $linkedTagIds = [1, 2, 3]
        $linkedTagIds = array_map(
            static fn($t) => (int)$t['id'],
            $seedTagsByCard[$oddCard] ?? []
        );

        foreach ($evenTagsSourcesByCard as $tagsSourceByCard) {
            $linkedTagIds = array_merge(
                $linkedTagIds,
                array_map(
                    static fn($t) => (int)$t['id'],
                    $tagsSourceByCard[$oddCard] ?? []
                )
            );
        }

        // Remove tags repetidas e tags inválidas.
        $linkedTagIds = array_values(
            array_filter(
                array_unique($linkedTagIds),
                static fn($tid) => $tid > 0
            )
        );

        // Se esse card não possui nenhuma tag válida,
        // pula para a próxima rodada.
        if (empty($linkedTagIds)) continue;

        // Remove das tags ligadas aquelas que já foram usadas
        // no histórico de decisões principais.
        //
        // Isso evita ficar repetindo sempre a mesma tag.
        $linkedTagIdsFiltered = array_values(
            array_filter(
                $linkedTagIds,
                static fn($tid) => !in_array((int)$tid, $oddDecisionTagsHistory, true)
            )
        );

        // Se depois de filtrar ainda sobrou alguma tag,
        // usa a versão filtrada.
        //
        // Mas se todas foram removidas,
        // mantém a lista original para não ficar sem opção.
        if (!empty($linkedTagIdsFiltered)) {
            $linkedTagIds = $linkedTagIdsFiltered;
        }

        // Escolhe a tag relacionada usando a lógica de faixas de 5 pontos.
        //
        // Regras:
        // 1) Começa na faixa "menor que 5".
        // 2) Se nenhuma tag couber, sobe para "menor que 10", depois 15, etc.
        // 3) Dentro da faixa atual, escolhe a tag de MAIOR score.
        // 4) Se empatar score, usa o menor ID da tag.
        //
        // Exemplo:
        // scores: 11, 8, 6, 3, 0
        // faixa <5  => escolhe 3
        // quando todas >=5, faixa <10 => escolhe 8
        $strongestTagId = null;
        $maxLinkedTagScore = null;

        foreach ($linkedTagIds as $tid) {
            $tagScore = $calcTagSens((int)$tid);
            if ($maxLinkedTagScore === null || $tagScore > $maxLinkedTagScore) {
                $maxLinkedTagScore = $tagScore;
            }
        }

        if ($maxLinkedTagScore !== null) {
            $limit = 5;

            while ($limit <= ((int)$maxLinkedTagScore + 5)) {
                $bestTagId = null;
                $bestTagScore = null;

                foreach ($linkedTagIds as $tid) {
                    $tagId = (int)$tid;
                    $tagScore = $calcTagSens($tagId);

                    // Fora da faixa atual: desconsidera.
                    if ($tagScore >= $limit) continue;

                    if (
                        $bestTagId === null
                        || $tagScore > $bestTagScore
                        || ($tagScore === $bestTagScore && $tagId < $bestTagId)
                    ) {
                        $bestTagId = $tagId;
                        $bestTagScore = $tagScore;
                    }
                }

                if ($bestTagId !== null) {
                    $strongestTagId = $bestTagId;
                    break;
                }

                $limit += 5;
            }
        }

        if ($strongestTagId === null) continue;

        // Dentro dessa tag mais forte, escolhe um card forte.
        //
        // Esse será o segundo card da rodada.
        $evenCard = $pickMaxCardInTag($strongestTagId, $used);

        // Se não encontrou card disponível nessa tag,
        // pula para a próxima rodada.
        if ($evenCard === null) continue;

        // Adiciona o segundo card da rodada na lista final.
        $chosen[] = [
            // ID do card escolhido.
            'card_id' => $evenCard,

            // Tag que decidiu a escolha desse card.
            // Aqui é a tag relacionada mais forte.
            'decision_tag' => $strongestTagId,

            // Histórico de tags principais já usadas.
            'excluded_tags' => array_values(
                array_unique(
                    array_map('intval', $oddDecisionTagsHistory)
                )
            ),

            // Histórico de cards principais já usados.
            'excluded_cards' => array_values(
                array_unique(
                    array_map('intval', $oddCardsHistory)
                )
            ),
        ];

        // Marca esse segundo card como usado,
        // para ele não aparecer de novo no mesmo lote.
        $used[$evenCard] = true;

        // Também aumenta o score dele localmente.
        //
        // De novo:
        // isso não salva no banco.
        // Só muda a pontuação durante a montagem desse lote.
        $scoreByCard[$evenCard] = (int)$scoreByCard[$evenCard] + 1;
    }

    // Retorna a sequência final de cards escolhidos.
    return $chosen;
}


/**
 * Função verifyDirectoryOwnership: Confere se um diretório pertence ao usuário autenticado.
 */
function verifyDirectoryOwnership($pdo, $directory_id, $user_id) {
    $stmt = $pdo->prepare("SELECT id, type, name_encrypted FROM directories WHERE id = ? AND user_id = ?");
    $stmt->execute([$directory_id, $user_id]);
    return $stmt->fetch();
}

/**
 * Função collectDecksFromDirectoryTree: Percorre a árvore de diretórios a partir de uma raiz e coleta todos os decks de flashcards encontrados.
 */
function collectDecksFromDirectoryTree($pdo, $root_directory_id, $user_id) {
    $decks = [];
    $visited = [];

    $stmtRoot = $pdo->prepare("SELECT id, type, deck_front_language, deck_back_language, deck_structure FROM directories WHERE id = ? AND user_id = ?");
    $stmtRoot->execute([$root_directory_id, $user_id]);
    $root = $stmtRoot->fetch();
    if (!$root) {
        return $decks;
    }

    $queue = [(int)$root['id']];
    if (isFlashcardDeckDirectoryType($root['type'])) {
        $decks[] = $root;
    }

    while (!empty($queue)) {
        $pending = [];
        foreach ($queue as $directory_id) {
            if (!isset($visited[$directory_id])) {
                $visited[$directory_id] = true;
                $pending[] = $directory_id;
            }
        }

        if (empty($pending)) {
            break;
        }

        $placeholders = implode(',', array_fill(0, count($pending), '?'));
        $params = array_merge([$user_id], $pending);
        $stmtChildren = $pdo->prepare("SELECT id, type, deck_front_language, deck_back_language, deck_structure FROM directories WHERE user_id = ? AND parent_id IN ($placeholders)");
        $stmtChildren->execute($params);
        $children = $stmtChildren->fetchAll();

        $queue = [];
        foreach ($children as $child) {
            $child_id = (int)$child['id'];
            $queue[] = $child_id;
            if (isFlashcardDeckDirectoryType($child['type'])) {
                $decks[] = $child;
            }
        }
    }

    return $decks;
}

/**
 * Função countPendingAudiosForDeck: Conta quantos lados de cards de um deck ainda precisam ter áudio gerado.
 */
function countPendingAudiosForDeck($pdo, $deck_id) {
    $pending = 0;
    $stmtCards = $pdo->prepare("SELECT front_encrypted, back_encrypted, has_audio_front, has_audio_back FROM flashcards WHERE directory_id = ?");
    $stmtCards->execute([$deck_id]);
    $cards = $stmtCards->fetchAll();

    foreach ($cards as $card) {
        if ((int)$card['has_audio_front'] === 0) {
            $front_text = !empty($card['front_encrypted']) ? trim(strip_tags(Security::decryptData($card['front_encrypted']))) : '';
            if ($front_text !== '' && !cardTextContainsMathNotation($front_text)) {
                $pending++;
            }
        }

        if ((int)$card['has_audio_back'] === 0) {
            $back_text = !empty($card['back_encrypted']) ? trim(strip_tags(Security::decryptData($card['back_encrypted']))) : '';
            if ($back_text !== '' && !cardTextContainsMathNotation($back_text)) {
                $pending++;
            }
        }
    }

    return $pending;
}

/**
 * Função findNextPendingAudioJobForDeck: Localiza o próximo lado de card pendente de áudio e retorna os dados necessários para geração.
 */
function findNextPendingAudioJobForDeck($pdo, $deck_id, $front_language, $back_language) {
    $stmtCards = $pdo->prepare("SELECT id, front_encrypted, back_encrypted, has_audio_front, has_audio_back FROM flashcards WHERE directory_id = ? AND (has_audio_front = 0 OR has_audio_back = 0) ORDER BY sort_order ASC, id ASC");
    $stmtCards->execute([$deck_id]);
    $cards = $stmtCards->fetchAll();

    foreach ($cards as $card) {
        if ((int)$card['has_audio_front'] === 0) {
            $front_text = !empty($card['front_encrypted']) ? trim(strip_tags(Security::decryptData($card['front_encrypted']))) : '';
            if ($front_text !== '' && !cardTextContainsMathNotation($front_text)) {
                return [
                    'card_id' => (int)$card['id'],
                    'side' => 'front',
                    'text' => $front_text,
                    'language' => $front_language
                ];
            }
        }

        if ((int)$card['has_audio_back'] === 0) {
            $back_text = !empty($card['back_encrypted']) ? trim(strip_tags(Security::decryptData($card['back_encrypted']))) : '';
            if ($back_text !== '' && !cardTextContainsMathNotation($back_text)) {
                return [
                    'card_id' => (int)$card['id'],
                    'side' => 'back',
                    'text' => $back_text,
                    'language' => $back_language
                ];
            }
        }
    }

    return null;
}

// Função auxiliar para verificar a propriedade de um card unitário
/**
 * Função verifyCardOwnership: Confere se um card pertence ao usuário por meio do deck ao qual ele está associado.
 */
function verifyCardOwnership($pdo, $card_id, $user_id, bool $allowPublicUserFive = false) {
    if ($allowPublicUserFive) {
        $stmt = $pdo->prepare("SELECT f.id, f.directory_id FROM flashcards f JOIN directories d ON f.directory_id = d.id WHERE f.id = ? AND d.user_id IN (?, 5)");
        $stmt->execute([$card_id, $user_id]);
        return $stmt->fetch();
    }
    $stmt = $pdo->prepare("SELECT f.id, f.directory_id FROM flashcards f JOIN directories d ON f.directory_id = d.id WHERE f.id = ? AND d.user_id = ?");
    $stmt->execute([$card_id, $user_id]);
    return $stmt->fetch();
}

/**
 * Função para ajustar a pronúncia do TTS (Text-to-Speech).
 * Substitui siglas e palavras estrangeiras pela sua fonética correspondente em português.
 * Pilar: Fácil Manutenção (Basta adicionar novas siglas no array $replacements).
 */
/**
 * Função normalizeDeckLanguage: Normaliza o idioma do deck para um valor permitido, aplicando fallback padrão.
 */
function normalizeDeckLanguage($value, $default = 'pt-BR') {
    $allowed = ['pt-BR', 'en-US', 'en-GB'];
    return in_array($value, $allowed, true) ? $value : $default;
}

/**
 * Função normalizeDeckStructure: Normaliza a estrutura do deck para um tipo permitido, aplicando fallback padrão.
 */
function normalizeDeckStructure($value, $default = 'traducoes') {
    $allowed = ['fatos', 'perguntas', 'traducoes', 'parafrases', 'ingles'];
    return in_array($value, $allowed, true) ? $value : $default;
}

/**
 * Função getFishReferenceIdByLanguage: Retorna o reference_id da Fish Audio conforme o idioma selecionado.
 */
function getFishReferenceIdByLanguage($language) {
    switch ($language) {
        case 'pt-BR': return FISH_REFERENCE_ID_PT_BR;
        case 'en-US': return FISH_REFERENCE_ID_EN_US;
        case 'en-GB': return FISH_REFERENCE_ID_EN_GB;
        default: return FISH_REFERENCE_ID_BACK;
    }
}

/**
 * Função getGoogleTtsVoiceByLanguage: Retorna a voz padrão do Google TTS para o idioma informado.
 */
function getGoogleTtsVoiceByLanguage($language) {
    switch ($language) {
        case 'pt-BR': return 'pt-BR-Chirp3-HD-Fenrir'; //Algenib* //Charon //Enceladus** //Fenrir** //Iapetus //Vindemiatrix*FEMME //Pulcherrima*FEMME
        case 'en-US': return 'en-US-Chirp3-HD-Fenrir';
        case 'en-GB': return 'en-GB-Chirp3-HD-Fenrir';
        default: return 'en-US-Chirp3-HD-Fenrir';
    }
}

/**
 * Função getGoogleTtsAlternateVoiceByLanguage: Retorna uma voz alternativa do Google TTS para evitar repetição entre frente e verso.
 */
function getGoogleTtsAlternateVoiceByLanguage($language) {
    switch ($language) {
        case 'pt-BR': return 'pt-BR-Chirp3-HD-Fenrir';
        case 'en-US': return 'en-US-Chirp3-HD-Enceladus';
        case 'en-GB': return 'en-GB-Chirp3-HD-Fenrir';
        default: return 'en-US-Chirp3-HD-Enceladus';
    }
}

/**
 * Função getGoogleTtsVoiceForDeckContext: Escolhe a voz do Google TTS considerando lado do card, idiomas e estrutura do deck.
 */
function getGoogleTtsVoiceForDeckContext($side, $language, $deck_structure, $front_language, $back_language) {
    $normalized_structure = normalizeDeckStructure($deck_structure, 'traducoes');
    $normalized_front = normalizeDeckLanguage($front_language, 'pt-BR');
    $normalized_back = normalizeDeckLanguage($back_language, 'en-GB');

    if (
        $normalized_structure === 'traducoes'
        && $normalized_front === 'pt-BR'
        && $normalized_back === 'en-GB'
    ) {
        if ($side === 'front') {
            return 'pt-BR-Chirp3-HD-Algenib';
        }

        if ($side === 'back') {
            return 'en-GB-Chirp3-HD-Fenrir';
        }
    }

    if (
        $normalized_structure === 'perguntas'
        && $normalized_front === 'pt-BR'
        && $normalized_back === 'pt-BR'
    ) {
        if ($side === 'front') {
            return 'pt-BR-Chirp3-HD-Fenrir'; //pt-BR-Chirp3-HD-Rasalgethi
        }

        if ($side === 'back') {
            return 'pt-BR-Chirp3-HD-Fenrir';//pt-BR-Chirp3-HD-Algenib //pt-BR-Chirp3-HD-Zubenelgenubi
        }
    }

    if ($side === 'back') {
        $front_default_voice = getGoogleTtsVoiceByLanguage($normalized_front);
        $back_default_voice = getGoogleTtsVoiceByLanguage($normalized_back);

        if ($front_default_voice === $back_default_voice) {
            return getGoogleTtsAlternateVoiceByLanguage($normalized_back);
        }

        return $back_default_voice;
    }

    return getGoogleTtsVoiceByLanguage($normalized_front);
}

/**
 * Função getLanguageLabel: Converte código de idioma em rótulo legível para exibição.
 */
function getLanguageLabel($language) {
    $map = [
        'pt-BR' => 'Português Brasileiro',
        'en-US' => 'Inglês Americano',
        'en-GB' => 'Inglês Britânico'
    ];
    return $map[$language] ?? $language;
}

/**
 * Função adjustPronunciationForTTS: Aplica substituições fonéticas cadastradas para melhorar a pronúncia do TTS.
 */
function adjustPronunciationForTTS($pdo, $text, $language) {
    $allowed = ['pt-BR', 'en-US', 'en-GB'];
    if (!in_array($language, $allowed, true)) {
        return $text;
    }

    $stmt = $pdo->prepare("SELECT source_text, target_text FROM pronuncias WHERE language = ? ORDER BY CHAR_LENGTH(source_text) DESC");
    $stmt->execute([$language]);
    $replacements = $stmt->fetchAll();

    if (!$replacements) {
        return $text;
    }

    foreach ($replacements as $item) {
        $source = trim((string)$item['source_text']);
        $target = (string)$item['target_text'];
        if ($source === '') {
            continue;
        }

        $pattern = '/\\b' . preg_quote($source, '/') . '\\b/iu';
        $text = preg_replace($pattern, $target, $text);
    }

    return $text;
}

/**
 * Função cardTextContainsMathNotation: Detecta se o texto contém notação matemática/LaTeX para evitar geração indevida de áudio.
 */
function cardTextContainsMathNotation($text) {
    $value = trim((string)$text);
    if ($value === '') {
        return false;
    }

    $patterns = [
        '/\\\\\(|\\\\\)|\\\\\[|\\\\\]/u',
        '/\$\$[^$]+\$\$|\$[^$]+\$/u',
        '/\\\\(frac|sqrt|sum|int|prod|lim|cdot|times|pm|mp|neq|leq|geq|left|right|alpha|beta|gamma|delta|theta|lambda|mu|pi|sigma|omega)\b/iu',
        '/[∑∫√≈≠≤≥∞πΔθλμ±÷×]/u',
        '/[²³¹⁰⁴⁵⁶⁷⁸⁹⁻⁺₀₁₂₃₄₅₆₇₈₉]/u',
        '/[A-Za-z0-9\)\]]\s*=\s*[A-Za-z0-9\(\[\\-]/u',
        '/\d+\s*[+\-*/^]\s*\d+/u',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $value) === 1) {
            return true;
        }
    }

    return false;
}

/**
 * Função decodeTtsAudioBinaryFromJsonPayload: Extrai e decodifica áudio binário de diferentes formatos de payload JSON de provedores TTS.
 */
function decodeTtsAudioBinaryFromJsonPayload($payload) {
    if (!is_array($payload)) {
        return null;
    }

    $candidateKeys = ['audio', 'audio_base64', 'audioContent', 'base64', 'data', 'result'];

    foreach ($candidateKeys as $key) {
        if (!array_key_exists($key, $payload)) {
            continue;
        }

        $value = $payload[$key];
        if (is_array($value)) {
            $nested = decodeTtsAudioBinaryFromJsonPayload($value);
            if ($nested !== null) {
                return $nested;
            }
            continue;
        }

        if (!is_string($value)) {
            continue;
        }

        $raw = trim($value);
        if ($raw === '') {
            continue;
        }

        if (preg_match('#^data:audio/[^;]+;base64,#i', $raw) === 1) {
            $parts = explode(',', $raw, 2);
            $raw = $parts[1] ?? '';
        }

        $decoded = base64_decode($raw, true);
        if ($decoded !== false && $decoded !== '') {
            return $decoded;
        }
    }

    return null;
}

/**
 * Função normalizeStoredAudioToBinary: Normaliza áudio salvo (base64 ou data URI) para conteúdo binário utilizável.
 */
function normalizeStoredAudioToBinary($audioValue) {
    if (!is_string($audioValue) || $audioValue === '') {
        return null;
    }

    $raw = trim($audioValue);
    if (preg_match('#^data:audio/[^;]+;base64,#i', $raw) === 1) {
        $parts = explode(',', $raw, 2);
        $raw = $parts[1] ?? '';
    }

    $decoded = base64_decode($raw, true);
    if ($decoded !== false && $decoded !== '') {
        return $decoded;
    }

    return $audioValue;
}

/**
 * Função normalizeTtsProvider: Normaliza o provedor de TTS para valores suportados, com fallback.
 */
function normalizeTtsProvider($value, $default = 'fishaudio') {
    $allowed = ['fishaudio', 'openai', 'google'];
    return in_array($value, $allowed, true) ? $value : $default;
}

/**
 * Função getUserTtsProvider: Obtém do banco o provedor de TTS preferido do usuário, já normalizado.
 */
function getUserTtsProvider($pdo, $user_id) {
    $stmt = $pdo->prepare("SELECT tts_provider FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$user_id]);
    $provider = $stmt->fetchColumn();
    return normalizeTtsProvider((string)$provider, 'fishaudio');
}

/**
 * Função buildTtsProviderErrorDetails: Monta uma mensagem de erro detalhada e padronizada para falhas de provedores TTS.
 */
function buildTtsProviderErrorDetails($provider, $httpcode, $curlError, $responseBody = null) {
    $providerLabel = strtoupper((string)$provider);
    $parts = ["Provider {$providerLabel}"];

    if ($httpcode > 0) {
        $parts[] = "HTTP {$httpcode}";
    }

    if (is_string($curlError) && trim($curlError) !== '') {
        $parts[] = 'cURL: ' . trim($curlError);
    }

    if (is_string($responseBody) && trim($responseBody) !== '') {
        $decoded = json_decode($responseBody, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $apiMessage = null;
            if (isset($decoded['error']['message']) && is_string($decoded['error']['message'])) {
                $apiMessage = $decoded['error']['message'];
            } elseif (isset($decoded['message']) && is_string($decoded['message'])) {
                $apiMessage = $decoded['message'];
            }

            if ($apiMessage !== null && trim($apiMessage) !== '') {
                $parts[] = 'API: ' . trim($apiMessage);
            }
        }
    }

    return implode(' | ', $parts);
}

/**
 * Função requestFishAudioTts: Solicita áudio ao provedor Fish Audio e retorna o binário gerado.
 */
function requestFishAudioTts($text_to_speech, $language, &$error_details = null) {
    if (trim((string)FISH_API_KEY) === '') {
        $error_details = 'Chave FISH_API_KEY não configurada.';
        return null;
    }

    $reference_id = getFishReferenceIdByLanguage($language);
    $ch = curl_init('https://api.fish.audio/v1/tts');
    $payload = json_encode([
        "text" => $text_to_speech,
        "reference_id" => $reference_id,
        "format" => "mp3"
    ]);

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer " . FISH_API_KEY,
        "Content-Type: application/json",
        "model: s2"
    ]);

    $response = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($httpcode !== 200 || !$response) {
        $error_details = buildTtsProviderErrorDetails('fishaudio', (int)$httpcode, $curlError, is_string($response) ? $response : null);
        return null;
    }

    $audio_binary = null;
    $decodedResponse = json_decode($response, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decodedResponse)) {
        $audio_binary = decodeTtsAudioBinaryFromJsonPayload($decodedResponse);
    }

    if ($audio_binary === null) {
        $audio_binary = $response;
    }

    $error_details = null;

    return is_string($audio_binary) && $audio_binary !== '' ? $audio_binary : null;
}

/**
 * Função requestOpenAITts: Solicita áudio ao endpoint de TTS da OpenAI e retorna o binário MP3.
 */
function requestOpenAITts($text_to_speech, &$error_details = null) {
    if (trim((string)OPENAI_API_KEY) === '') {
        $error_details = 'Chave OPENAI_API_KEY não configurada.';
        return null;
    }

    $ch = curl_init('https://api.openai.com/v1/audio/speech');
    $payload = json_encode([
        'model' => 'gpt-5.4',
        'voice' => 'alloy',
        'input' => $text_to_speech,
        'format' => 'mp3'
    ]);

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . OPENAI_API_KEY,
        'Content-Type: application/json'
    ]);

    $response = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($httpcode !== 200 || !$response) {
        $error_details = buildTtsProviderErrorDetails('openai', (int)$httpcode, $curlError, is_string($response) ? $response : null);
        return null;
    }

    $error_details = null;

    return is_string($response) && $response !== '' ? $response : null;
}


/**
 * Função requestGoogleCloudTts: Solicita áudio ao Google Cloud TTS com voz contextual do deck e retorna o binário.
 */
function requestGoogleCloudTts($text_to_speech, $language, $side = null, $deck_structure = 'traducoes', $front_language = 'pt-BR', $back_language = 'en-GB', &$error_details = null) {
    if (trim((string)GOOGLE_CLOUD_API_KEY) === '') {
        $error_details = 'Chave GOOGLE_CLOUD_API_KEY não configurada.';
        return null;
    }

    $voice_name = getGoogleTtsVoiceForDeckContext($side, $language, $deck_structure, $front_language, $back_language);
    $ch = curl_init('https://texttospeech.googleapis.com/v1/text:synthesize?key=' . rawurlencode(GOOGLE_CLOUD_API_KEY));
    $payload = json_encode([
        'input' => ['text' => $text_to_speech],
        'voice' => [
            'languageCode' => $language,
            'name' => $voice_name
        ],
        'audioConfig' => [
            'audioEncoding' => 'MP3'
        ]
    ]);

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json'
    ]);

    $response = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($httpcode !== 200 || !$response) {
        $error_details = buildTtsProviderErrorDetails('google', (int)$httpcode, $curlError, is_string($response) ? $response : null);
        return null;
    }

    $decodedResponse = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($decodedResponse)) {
        $error_details = 'Provider GOOGLE | resposta inválida ao decodificar JSON.';
        return null;
    }

    $audio_binary = decodeTtsAudioBinaryFromJsonPayload($decodedResponse);
    if (!is_string($audio_binary) || $audio_binary === '') {
        $error_details = buildTtsProviderErrorDetails('google', (int)$httpcode, '', $response);
        return null;
    }

    $error_details = null;
    return is_string($audio_binary) && $audio_binary !== '' ? $audio_binary : null;
}



/**
 * Função generateAndPersistCardAudio: Gera áudio para um lado do card com o provedor configurado e persiste no banco criptografado.
 */
function generateAndPersistCardAudio($pdo, $user_id, $card_id, $side, $text, $language, $deck_structure = 'traducoes', $front_language = 'pt-BR', $back_language = 'en-GB', &$error_details = null) {
    $text_to_speech = adjustPronunciationForTTS($pdo, $text, $language);
    $provider = getUserTtsProvider($pdo, (int)$user_id);
    $provider_error = null;

    if ($provider === 'openai') {
        $audio_binary = requestOpenAITts($text_to_speech, $provider_error);
    } elseif ($provider === 'google') {
        $audio_binary = requestGoogleCloudTts($text_to_speech, $language, $side, $deck_structure, $front_language, $back_language, $provider_error);
    } else {
        $audio_binary = requestFishAudioTts($text_to_speech, $language, $provider_error);
    }

    if (!is_string($audio_binary) || $audio_binary === '') {
        $error_details = $provider_error ?: ('Falha ao gerar áudio com o provider ' . strtoupper($provider) . '.');
        return false;
    }

    $audio_b64 = base64_encode($audio_binary);
    $audio_encrypted = Security::encryptData($audio_b64);

    $audioCol = $side === 'front' ? 'audio_front_encrypted' : 'audio_back_encrypted';
    $col = $side === 'front' ? 'has_audio_front' : 'has_audio_back';
    $stmt = $pdo->prepare("UPDATE flashcards SET $col = 1, $audioCol = ? WHERE id = ?");
    $stmt->execute([$audio_encrypted, $card_id]);

    $error_details = null;

    return true;
}

/**
 * Função buildDefaultBasePromptByStructure: Monta o prompt base padrão de geração conforme a estrutura do deck.
 */
function buildDefaultBasePromptByStructure($deck_name, $deck_structure) {
    $basePrompt = '';
    if ($deck_structure === 'fatos') {
        $basePrompt = 'Me dê informações sobre o assunto "$deck_name", em uma tabela de uma única coluna, onde cada linha é uma informação. As informações devem ser de fácil compreensão. As informações devem ser óbvias evidentes e de rápida assimilação e de preferência curtas. As informações devem ser sequenciais, uma sendo continuação da outra, igual em um livro, em um texto grande. Dê exemplo ou faça analogias, quando cabível, para o aluno sair um pouco da abstração e conseguir entender tanto a parte abstrata quanto enxergar o abstrato no mundo concreto.';
    } elseif ($deck_structure === 'perguntas') {
        $basePrompt = 'Me dê perguntas que induzam conhecimento fundamental, elementar, essencial, indispensável sobre o assunto "$deck_name" coisas elementares e ontológicas sobre, hermenêutica, em uma tabela de duas colunas, onde cada linha é uma pergunta, a primeira coluna é a pergunta, e a segunda é a resposta. As perguntas e respostas devem ser óbvias evidentes e de rápida assimilação. Use linguagem simples e de fácil assimilação para pessoas de qualquer nível intelectual. As pessoas devem conseguir decodificar a informação codificada nas perguntas e respostas de forma assustadoramente fácil. As perguntas devem ser simples e de preferência curtas. Nenhuma pergunta pode ser igual as informações anteriores (salvo em paráfrases, uso de sinônimos e oposição ex. frase negativa e frase afirmativa) que já fiz. O objetivo é construir aprendizado progressivo sem redundância. Público alvo são crianças de 10 à 17 anos.';
    } elseif ($deck_structure === 'parafrases') {
        $basePrompt = 'gere 5 paráfrases dessa: "$deck_name".';
    } elseif ($deck_structure === 'ingles') {
        $basePrompt = '
        Explique o termo/expressão "$deck_name" e quais todas as formas de usar ele em frases em inglês, semânticamente, morfologicamente, sintática e pragmática.
        Perguntas assustadoramente simples de entender e respostas também. Qualquer leigo deve ter extrema facilidade assustadora de compreender o texto da pergunta e da resposta.
        Quando cabível apenas: Abranja todas as polissemias, homonímias, flexões de gênero, flexões de número, flexões de grau, flexões para adequar a pronomes, flexões de tempo, flexões de modo.
        Quando cabível apenas: Abranja também verbos frasais.
        Quando não cabível e não houver variação morfológica no termo, não precisa perguntas sobre essa flexão, só faça perguntas de flexão, quando houver variação morfológica no termo.
        Também faça perguntas sobre se pode usar esse termo em determinada posição na fase ou não.
        Caso a expressão seja um verbo, faça perguntas sobre a morfologia e semântica adequada para cada pessoa e para cada tempo. Explique a semântica de acordo com o tempo, não precisa falar o nome do tempo, quando for fala de flexões temporais de um verbo, referencie essa flexão pela semântica dela e não pelo nome do tempo verbal.
        Lembre-se, o aluno não é professor de gramática, então ele não sabe o significado do glossário gramatical que coloquei nesse prompt, logo, não use ele.
        Finalize todas as explicações com exemplo e tradução.
        Caso o termo for verbo, lembre-se das flexões de tempo nos 12 tempos verbais.
        Caso o termo for verbo, lembre-se das variações em verbos frasais.
        Os textos serão lidos por TTS, então evite sinais e setas, pode usar vígulas, aspas, pontos finais e de interrogação.
        Gere 100 cards.';
    } else {
        $basePrompt = 'Crie 10 frases ordinárias em inglês que tenha dentro da usa estrutura sintática exatamente esse bloco lexical "$deck_name". Front/frente do card a frase traduzida para português brasileiro e back/verso do card a frase original em inglês.';
    }
    return $basePrompt;
}

/**
 * Função applyGenerationPromptTemplateVariables: Substitui variáveis de template (ex.: $deck_name) no prompt de geração.
 */
function applyGenerationPromptTemplateVariables($prompt, $deck_name) {
    $normalizedPrompt = (string)$prompt;
    $normalizedDeckName = trim((string)$deck_name);
    return str_replace('$deck_name', $normalizedDeckName, $normalizedPrompt);
}

/**
 * Função normalizeGenerationBasePromptInput: Sanitiza e limita o tamanho do prompt base personalizado antes do uso.
 */
function normalizeGenerationBasePromptInput($value) {
    $text = trim((string)$value);
    if ($text === '') {
        return '';
    }
    return function_exists('mb_substr') ? mb_substr($text, 0, 5000) : substr($text, 0, 5000);
}

/**
 * Função buildFlashcardsGenerationPayload: Monta o payload completo para gerar flashcards via modelo, com schema JSON estrito.
 */
function buildFlashcardsGenerationPayload($deck_name, $deck_structure, $historyText, $model = 'gpt-5.4', $customBasePrompt = '') {
    $basePrompt = normalizeGenerationBasePromptInput($customBasePrompt);
    if ($basePrompt === '') {
        $basePrompt = buildDefaultBasePromptByStructure($deck_name, $deck_structure);
    }
    $basePrompt = applyGenerationPromptTemplateVariables($basePrompt, $deck_name);

    $systemPrompt = 'Você é um gerador de flashcards para estudo. Retorne APENAS JSON válido no formato {"cards":[{"front":"...","back":"..."}]}. Não use markdown. Nunca deixe "front" vazio. Para estruturas perguntas, traducoes e ingles, nunca deixe "back" vazio. Para estruturas fatos e parafrases, deixe back vazio. Preserve exatamente caracteres Unicode.';

    $userPrompt = $basePrompt
        . "

REGRAS DE LIMPEZA DE SAÍDA:
Nunca inclua menus, botões, placeholders, atalhos de teclado, termos de interface ou listas de símbolos soltas. Retorne apenas conteúdo pedagógico dos cards.";

    if ($deck_structure === 'parafrases') {
        $userPrompt .= "

Não considere cards anteriores deste deck para gerar a resposta.";
    } else {
        $userPrompt .= "

CARDS JÁ EXISTENTES NESTE DECK:
" . $historyText
            . "

Gere novos cards sem repetição de conteúdo com o histórico.";
    }

    $requiresBack = in_array($deck_structure, ['perguntas', 'traducoes', 'ingles'], true);
    $backSchema = $requiresBack ? ['type' => 'string', 'minLength' => 1] : ['type' => 'string'];

    return [
        'model' => $model,
        'messages' => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userPrompt]
        ],
        'response_format' => [
            'type' => 'json_schema',
            'json_schema' => [
                'name' => 'cards_preview_response',
                'strict' => true,
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'cards' => [
                            'type' => 'array',
                            'minItems' => 1,
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'front' => ['type' => 'string', 'minLength' => 1],
                                    'back' => $backSchema
                                ],
                                'required' => ['front', 'back'],
                                'additionalProperties' => false
                            ]
                        ]
                    ],
                    'required' => ['cards'],
                    'additionalProperties' => false
                ]
            ]
        ]
    ];
}

/**
 * Função sanitizeGeneratedCards: Valida e higieniza o JSON de cards gerado, removendo itens inválidos.
 */
function sanitizeGeneratedCards($rawContent, $deck_structure) {
    $raw = trim((string)$rawContent);
    if ($raw !== '' && str_starts_with($raw, '```')) {
        $raw = preg_replace('/^```(?:json)?\s*/i', '', $raw);
        $raw = preg_replace('/\s*```$/', '', $raw);
        $raw = trim((string)$raw);
    }
    $json = json_decode($raw, true);
    if (!is_array($json) || !isset($json['cards']) || !is_array($json['cards'])) {
        return [];
    }

    $cards = [];
    foreach ($json['cards'] as $card) {
        $front = trim((string)($card['front'] ?? ''));
        $back = trim((string)($card['back'] ?? ''));
        if ($front === '') continue;
        if (in_array($deck_structure, ['fatos', 'parafrases'], true)) {
            $back = '';
        } elseif ($back === '') {
            continue;
        }
        $cards[] = ['front' => $front, 'back' => $back];
    }
    return $cards;
}

/**
 * Função openaiJsonRequest: Executa requisição POST JSON para a OpenAI e retorna HTTP code, resposta e erro cURL.
 */
function openaiJsonRequest($url, $payload) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . OPENAI_API_KEY
    ]);
    $response = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    return [$httpcode, $response, $curlError];
}

/**
 * Função openaiGetRequest: Executa requisição GET para a OpenAI e retorna HTTP code, resposta e erro cURL.
 */
function openaiGetRequest($url) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPGET, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . OPENAI_API_KEY
    ]);
    $response = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    return [$httpcode, $response, $curlError];
}


/**
 * Função normalizeDictionarySentence: Normaliza frase para uso em dicionário, limpando espaços e formatação básica.
 */
function normalizeDictionarySentence($value) {
    $text = trim((string)$value);
    if ($text === '') return '';
    $text = preg_replace('/\s+/u', ' ', $text);
    return trim((string)$text);
}

/**
 * Função normalizeDictionarySentenceKey: Gera chave normalizada da frase para deduplicação no dicionário de sentenças.
 */
function normalizeDictionarySentenceKey($value) {
    $normalized = normalizeDictionarySentence($value);
    if ($normalized === '') return '';
    return mb_strtolower($normalized, 'UTF-8');
}

/**
 * Função extractDictionaryCandidatesFromGpt: Extrai e valida candidatos de sentenças retornados pelo GPT para alimentar o dicionário.
 */
function extractDictionaryCandidatesFromGpt($text) {
    $inputText = trim((string)$text);
    if ($inputText === '') {
        return ['ok' => false, 'error' => 'Texto vazio para análise.', 'candidates' => []];
    }
    if (OPENAI_API_KEY === '') {
        return ['ok' => false, 'error' => 'OPENAI_API_KEY não configurada no .env.', 'candidates' => []];
    }

    $payload = [
        'model' => 'gpt-5.4',
        'temperature' => 0,
        'response_format' => [
            'type' => 'json_schema',
            'json_schema' => [
                'name' => 'dictionary_candidates',
                'strict' => true,
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'generated_sentences' => ['type' => 'array', 'items' => ['type' => 'string']]
                    ],
                    'required' => ['generated_sentences'],
                    'additionalProperties' => false
                ]
            ]
        ],
        'messages' => [
            [
                'role' => 'system',
                'content' => 'Você retorna apenas JSON válido seguindo o schema.'
            ],
            [
                'role' => 'user',
                'content' => 'split this sentence into lexical chuncks, then create one sentence for each lexical chunk, replacing that chunk with [LEXICAL CHUNK]. Return ONLY JSON with key generated_sentences. Sentence: "' . $inputText . '"'
            ]
        ]
    ];

    list($httpcode, $response, $curlError) = openaiJsonRequest('https://api.openai.com/v1/chat/completions', $payload);
    if ($httpcode !== 200 || !$response) {
        $details = trim((string)$curlError);
        $decodedErr = json_decode((string)$response, true);
        if (!$details && is_array($decodedErr)) {
            $details = (string)($decodedErr['error']['message'] ?? '');
        }
        return ['ok' => false, 'error' => 'Erro ao analisar frase com OpenAI.' . ($details !== '' ? (' Detalhes: ' . $details) : ''), 'candidates' => []];
    }

    $decoded = json_decode($response, true);
    $content = trim((string)($decoded['choices'][0]['message']['content'] ?? ''));
    if ($content === '') {
        return ['ok' => false, 'error' => 'OpenAI não retornou conteúdo analisável.', 'candidates' => []];
    }

    $parsed = json_decode($content, true);
    if (!is_array($parsed)) {
        return ['ok' => false, 'error' => 'OpenAI retornou JSON inválido para candidatos.', 'candidates' => []];
    }

    $candidatesByKey = [];
    foreach (($parsed['generated_sentences'] ?? []) as $item) {
        $sentence = normalizeDictionarySentence($item);
        $sentenceKey = normalizeDictionarySentenceKey($sentence);
        if ($sentence !== '' && $sentenceKey !== '' && !isset($candidatesByKey[$sentenceKey])) {
            $candidatesByKey[$sentenceKey] = $sentence;
        }
    }

    return ['ok' => true, 'error' => '', 'candidates' => array_values($candidatesByKey)];
}

/**
 * Função syncBatchJobWithOpenAI: Sincroniza o status de um job em lote com a OpenAI e persiste progresso/resultado no banco.
 */
function syncBatchJobWithOpenAI($pdo, $job) {
    $openaiBatchId = trim((string)($job['openai_batch_id'] ?? ''));
    if ($openaiBatchId === '') {
        return ['ok' => false, 'error' => 'Job sem batch_id da OpenAI.', 'job' => null];
    }

    list($statusCode, $statusResponse, $statusErr) = openaiGetRequest('https://api.openai.com/v1/batches/' . rawurlencode($openaiBatchId));
    if ($statusCode !== 200 || !$statusResponse) {
        $details = trim($statusErr);
        $decodedErr = json_decode((string)$statusResponse, true);
        if (!$details && is_array($decodedErr)) $details = (string)($decodedErr['error']['message'] ?? '');
        return ['ok' => false, 'error' => 'Falha ao consultar status na OpenAI.' . ($details ? (' Detalhes: ' . $details) : ''), 'job' => null];
    }

    $statusDecoded = json_decode($statusResponse, true);
    $newStatus = trim((string)($statusDecoded['status'] ?? $job['status']));
    $outputFileId = trim((string)($statusDecoded['output_file_id'] ?? ''));
    $errorFileId = trim((string)($statusDecoded['error_file_id'] ?? ''));

    $cardsJsonToStore = $job['result_cards_json'];
    $errorMessage = $job['error_message'];
    $completedAt = $job['completed_at'];

    if ($newStatus === 'completed' && $outputFileId !== '') {
        list($fileCode, $fileContent, $fileErr) = openaiGetRequest('https://api.openai.com/v1/files/' . rawurlencode($outputFileId) . '/content');
        if ($fileCode === 200 && $fileContent) {
            $cards = [];
            $lines = preg_split('/\r\n|\r|\n/', (string)$fileContent);
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '') continue;
                $lineDecoded = json_decode($line, true);
                $content = (string)($lineDecoded['response']['body']['choices'][0]['message']['content'] ?? '');
                if ($content === '') continue;
                $cards = sanitizeGeneratedCards($content, $job['deck_structure']);
                if (!empty($cards)) break;
            }
            if (!empty($cards)) {
                $cardsJsonToStore = json_encode($cards, JSON_UNESCAPED_UNICODE);
                $errorMessage = null;
            } else {
                $errorMessage = 'Batch concluído, mas o conteúdo retornou vazio ou fora do formato esperado.';
            }
            $completedAt = date('Y-m-d H:i:s');
        } else {
            $errorMessage = 'Batch concluído, porém não foi possível baixar o arquivo de saída.' . ($fileErr ? (' Detalhes: ' . $fileErr) : '');
        }
    } elseif (in_array($newStatus, ['failed', 'cancelled', 'expired'], true)) {
        $errorMessage = 'O batch terminou com status: ' . $newStatus;
        $completedAt = date('Y-m-d H:i:s');
    }

    $upd = $pdo->prepare("UPDATE flashcard_batch_jobs SET status = ?, openai_output_file_id = ?, openai_error_file_id = ?, error_message = ?, result_cards_json = ?, completed_at = ? WHERE id = ?");
    $upd->execute([$newStatus, $outputFileId !== '' ? $outputFileId : null, $errorFileId !== '' ? $errorFileId : null, $errorMessage, $cardsJsonToStore, $completedAt, (int)$job['id']]);

    return [
        'ok' => true,
        'error' => null,
        'job' => [
            'id' => (int)$job['id'],
            'openai_batch_id' => $openaiBatchId,
            'status' => $newStatus,
            'openai_output_file_id' => $outputFileId,
            'has_result' => !empty($cardsJsonToStore),
            'error_message' => $errorMessage
        ]
    ];
}

/**
 * Função fetchDeckHistoryText: Monta texto histórico dos cards do deck para evitar repetição em novas gerações.
 */
function fetchDeckHistoryText($pdo, $deck_id) {
    $stmt = $pdo->prepare("SELECT front_encrypted, back_encrypted FROM flashcards WHERE directory_id = ? ORDER BY sort_order ASC, id ASC");
    $stmt->execute([$deck_id]);
    $existing_cards = $stmt->fetchAll();

    $history_lines = [];
    foreach ($existing_cards as $c) {
        $front = trim(!empty($c['front_encrypted']) ? Security::decryptData($c['front_encrypted']) : '');
        $back = trim(!empty($c['back_encrypted']) ? Security::decryptData($c['back_encrypted']) : '');
        if ($front === '' && $back === '') continue;
        $history_lines[] = "Frente: {$front} | Verso: {$back}";
    }
    return !empty($history_lines) ? implode("
", $history_lines) : 'Sem cards prévios no deck.';
}

if ($action === 'fetch') {
    $deck_id = (int)($input['deck_id'] ?? 0);
    $directory_id = (int)($input['directory_id'] ?? 0);
    $study_scope = (string)($input['study_scope'] ?? '');

    if ($study_scope === 'directory_random') {
        if ($directory_id === 0) die(json_encode(['status' => 'error', 'message' => 'ID do diretório inválido.']));

        $directory = verifyDirectoryOwnership($pdo, $directory_id, $user_id);
        if (!$directory) {
            die(json_encode(['status' => 'error', 'message' => 'Diretório não encontrado ou sem permissão.']));
        }

        $decks = collectDecksFromDirectoryTree($pdo, $directory_id, $user_id);
        $deck_ids = [];
        foreach ($decks as $deckEntry) {
            $deck_ids[] = (int)$deckEntry['id'];
        }

        if (empty($deck_ids)) {
            echo json_encode([
                'status' => 'success',
                'deck_name' => 'Revisão da Pasta • ' . Security::decryptData($directory['name_encrypted']),
                'deck_mode' => 'aleatorio',
                'deck_front_language' => 'pt-BR',
                'deck_back_language' => 'en-GB',
                'deck_structure' => 'traducoes',
                'generation_base_prompt_default' => '',
                'deck_percentage' => 0,
                'book_completed_reads' => 0,
                'book_completed_reads_max' => 3,
                'book_next_review_at' => null,
                'total_cards' => 0,
                'current_index' => 0,
                'data' => []
            ]);
            exit;
        }

        $placeholders = implode(',', array_fill(0, count($deck_ids), '?'));

        $stmtCards = $pdo->prepare("
            SELECT f.id, f.front_encrypted, f.back_encrypted, f.image_front_encrypted, f.image_back_encrypted, f.has_audio_front, f.has_audio_back, COALESCE(fs.score, 0) as score
            FROM flashcards f
            LEFT JOIN flashcard_scores fs ON fs.flashcard_id = f.id AND fs.user_id = ?
            WHERE f.directory_id IN ($placeholders)
              AND f.has_audio_front = 1
              AND f.has_audio_back = 1
              AND (
                (fs.id IS NOT NULL AND fs.next_review_at IS NOT NULL AND fs.next_review_at <= NOW())
                OR fs.id IS NULL
                OR COALESCE(fs.score, 0) = 0
              )
            ORDER BY
                CASE
                    WHEN fs.id IS NOT NULL AND fs.next_review_at IS NOT NULL AND fs.next_review_at <= NOW() THEN 0
                    WHEN fs.id IS NULL OR COALESCE(fs.score, 0) = 0 THEN 1
                    ELSE 2
                END,
                RAND()
        ");
        $stmtCards->execute(array_merge([$user_id], $deck_ids));
        $cards = $stmtCards->fetchAll();

        $stmtTotal = $pdo->prepare("SELECT COUNT(id) FROM flashcards WHERE directory_id IN ($placeholders)");
        $stmtTotal->execute($deck_ids);
        $total_cards_in_directory = (int)$stmtTotal->fetchColumn();

        $stmtScore = $pdo->prepare("
            SELECT COALESCE(SUM(fs.score), 0)
            FROM flashcard_scores fs
            JOIN flashcards f ON fs.flashcard_id = f.id
            WHERE fs.user_id = ? AND f.directory_id IN ($placeholders)
        ");
        $stmtScore->execute(array_merge([$user_id], $deck_ids));
        $total_score_directory = (int)$stmtScore->fetchColumn();
        $max_possible_score = $total_cards_in_directory * 20;
        $deck_percentage = $max_possible_score > 0 ? round(($total_score_directory / $max_possible_score) * 100) : 0;
        
        //$cardIds são todos os cards (do deck) sem discriminação no caso de grafos, e com filtro de vencimento no caso de aleatório
        $cardIds = array_map(static fn($card) => (int)$card['id'], $cards);
        //$subjectTagsByCard todas as tags da subcategoria "subject" dos cards de $cardIds
        $subjectTagsByCard = fetchLinkedTagsByCard($pdo, 'subjects_links', $cardIds, $user_id);
        $objectTagsByCard = fetchLinkedTagsByCard($pdo, 'objects_links', $cardIds, $user_id);
        $tipoFrasalTagsByCard = fetchLinkedTagsByCard($pdo, 'tipo_frasal_links', $cardIds, $user_id);
        $tenseTagsByCard = fetchLinkedTagsByCard($pdo, 'tense_links', $cardIds, $user_id);
        $lexicalChunksTagsByCard = fetchLinkedTagsByCard($pdo, 'lexical_chunks_links', $cardIds, $user_id);
        $relationTagsByCard = fetchLinkedTagsByCard($pdo, 'relation_links', $cardIds, $user_id);
        $wordsTagsByCard = fetchLinkedTagsByCard($pdo, 'words_links', $cardIds, $user_id);
        $idiomaPrincipalTagsByCard = fetchLinkedTagsByCardColumn($pdo, 'idiomas_links', 'tag_id', $cardIds, $user_id);
        $idiomaSecundarioTagsByCard = fetchLinkedTagsByCardColumn($pdo, 'idiomas_links', 'segundo_idioma_tag_id', $cardIds, $user_id);

        $response = [];
        foreach ($cards as $card) {
            $response[] = [
                'id' => $card['id'],
                'front' => !empty($card['front_encrypted']) ? Security::decryptData($card['front_encrypted']) : '',
                'back' => !empty($card['back_encrypted']) ? Security::decryptData($card['back_encrypted']) : '',
                'image_front' => !empty($card['image_front_encrypted']) ? Security::decryptData($card['image_front_encrypted']) : null,
                'image_back' => !empty($card['image_back_encrypted']) ? Security::decryptData($card['image_back_encrypted']) : null,
                'has_audio_front' => (int)$card['has_audio_front'],
                'has_audio_back' => (int)$card['has_audio_back'],
                'score' => (int)$card['score'],
                'subject_tags' => $subjectTagsByCard[(int)$card['id']] ?? [],
                'object_tags' => $objectTagsByCard[(int)$card['id']] ?? [],
                'tipo_frasal_tags' => $tipoFrasalTagsByCard[(int)$card['id']] ?? [],
                'tense_tags' => $tenseTagsByCard[(int)$card['id']] ?? [],
                'lexical_chunks_tags' => $lexicalChunksTagsByCard[(int)$card['id']] ?? [],
                'relation_tags' => $relationTagsByCard[(int)$card['id']] ?? [],
                'words_tags' => $wordsTagsByCard[(int)$card['id']] ?? [],
                'idioma_principal_tags' => $idiomaPrincipalTagsByCard[(int)$card['id']] ?? [],
                'idioma_secundario_tags' => $idiomaSecundarioTagsByCard[(int)$card['id']] ?? [],
                'idiomas_tags' => $idiomaPrincipalTagsByCard[(int)$card['id']] ?? []
            ];
        }

        echo json_encode([
            'status' => 'success',
            'deck_name' => 'Revisão da Pasta • ' . Security::decryptData($directory['name_encrypted']),
            'deck_mode' => 'aleatorio',
            'deck_front_language' => 'pt-BR',
            'deck_back_language' => 'en-GB',
            'deck_structure' => 'traducoes',
            'generation_base_prompt_default' => '',
            'deck_percentage' => $deck_percentage,
            'book_completed_reads' => 0,
            'book_completed_reads_max' => 3,
            'book_next_review_at' => null,
            'total_cards' => $total_cards_in_directory,
            'current_index' => 0,
            'data' => $response
        ]);
        exit;
    }

    if ($deck_id === 0) die(json_encode(['status' => 'error', 'message' => 'ID do deck inválido.']));

    $deck = verifyDeckOwnership($pdo, $deck_id, $user_id, true);
    if (!$deck) {
        die(json_encode(['status' => 'error', 'message' => 'Deck não encontrado ou sem permissão.']));
    }
    $unlockError = validatePhaseDeckUnlock($pdo, $deck_id, $user_id);
    if ($unlockError !== null) {
        die(json_encode(['status' => 'error', 'message' => $unlockError]));
    }

    $deck_mode = $deck['deck_mode'] ?? 'aleatorio';
    $current_index = 0;
    $book_completed_reads = 0;

    $book_next_review_at = null;
    $random_next_review_at = null;

    if (in_array($deck_mode, ['aleatorio', 'grafo'], true)) {
        $orderClause = $deck_mode === 'grafo' ? 'ORDER BY f.id ASC' : 'ORDER BY RAND()';

        if ($deck_mode === 'grafo') {
            // No modo grafo, busca cards de qualquer deck do usuário
            // e também dos decks pertencentes ao usuário de id 5.
            $stmt = $pdo->prepare("
                SELECT f.id, f.front_encrypted, f.back_encrypted, f.image_front_encrypted, f.image_back_encrypted, f.has_audio_front, f.has_audio_back, COALESCE(fs.score, 0) as score 
                FROM flashcards f
                JOIN directories d ON d.id = f.directory_id
                LEFT JOIN flashcard_scores fs ON fs.flashcard_id = f.id AND fs.user_id = ?
                WHERE d.user_id IN (?, 5)
                {$orderClause}
            ");
            $stmt->execute([$user_id, $user_id]);
        } else {
            // No modo aleatório, mantém filtro por deck e cards vencidos.
            $stmt = $pdo->prepare("
                SELECT f.id, f.front_encrypted, f.back_encrypted, f.image_front_encrypted, f.image_back_encrypted, f.has_audio_front, f.has_audio_back, COALESCE(fs.score, 0) as score 
                FROM flashcards f
                LEFT JOIN flashcard_scores fs ON fs.flashcard_id = f.id AND fs.user_id = ?
                WHERE f.directory_id = ?
                  AND (fs.next_review_at IS NULL OR fs.next_review_at <= NOW())
                {$orderClause}
            ");
            $stmt->execute([$user_id, $deck_id]);
        }
        
        //Caso não tiver nenhum card disponível, verifica qual data e hora o próximo ficará disponível (vale para o Modo Aleatório)
        $stmtNextRandomReview = $pdo->prepare("
            SELECT MIN(fs.next_review_at)
            FROM flashcard_scores fs
            JOIN flashcards f ON f.id = fs.flashcard_id
            WHERE f.directory_id = ?
              AND fs.user_id = ?
              AND fs.next_review_at IS NOT NULL
              AND fs.next_review_at > NOW()
        ");
        $stmtNextRandomReview->execute([$deck_id, $user_id]);
        $random_next_review_at = $stmtNextRandomReview->fetchColumn() ?: null;
    } else {
        $stmt = $pdo->prepare("
            SELECT f.id, f.front_encrypted, f.back_encrypted, f.image_front_encrypted, f.image_back_encrypted, f.has_audio_front, f.has_audio_back, 0 as score 
            FROM flashcards f
            WHERE f.directory_id = ? 
            ORDER BY f.sort_order ASC, f.id ASC
        ");
        $stmt->execute([$deck_id]);

        $stmtProg = $pdo->prepare("SELECT current_index, completed_reads, next_review_at FROM flashcard_book_progress WHERE user_id = ? AND directory_id = ?");
        $stmtProg->execute([$user_id, $deck_id]);
        $progressData = $stmtProg->fetch();
        if ($progressData) {
            $current_index = (int)($progressData['current_index'] ?? 0);
            $book_completed_reads = min(3, (int)($progressData['completed_reads'] ?? 0));
            $book_next_review_at = $progressData['next_review_at'] ?? null;
        }
    }
    
    $cards = $stmt->fetchAll();

    if ($deck_mode === 'livro' && !empty($book_next_review_at) && strtotime($book_next_review_at) > time()) {
        $cards = [];
        $current_index = 0;
    }

    $stmtTotal = $pdo->prepare("SELECT COUNT(id) FROM flashcards WHERE directory_id = ?");
    $stmtTotal->execute([$deck_id]);
    $total_cards_in_deck = (int)$stmtTotal->fetchColumn();

    if ($deck_mode === 'livro') {
        $deck_percentage = (int)round(($book_completed_reads / 3) * 100);
    } else {
        $stmtScore = $pdo->prepare("
            SELECT SUM(score) FROM flashcard_scores fs 
            JOIN flashcards f ON fs.flashcard_id = f.id 
            WHERE f.directory_id = ? AND fs.user_id = ?
        ");
        $stmtScore->execute([$deck_id, $user_id]);
        $total_score_deck = (int)$stmtScore->fetchColumn();

        $max_possible_score = $total_cards_in_deck * 20;
        $deck_percentage = $max_possible_score > 0 ? round(($total_score_deck / $max_possible_score) * 100) : 0;
    }

    $cardIds = array_map(static fn($card) => (int)$card['id'], $cards);
    $subjectTagsByCard = [];
    $objectTagsByCard = [];
    $tipoFrasalTagsByCard = [];
    $tenseTagsByCard = [];
    $lexicalChunksTagsByCard = [];
    $relationTagsByCard = [];
    $wordsTagsByCard = [];
    $idiomaPrincipalTagsByCard = [];
    $idiomaSecundarioTagsByCard = [];

    $graphDecisionByCardId = [];
    if ($deck_mode === 'grafo') {
        $subjectTagsByCard = fetchLinkedTagsByCard($pdo, 'subjects_links', $cardIds, $user_id);
        $objectTagsByCard = fetchLinkedTagsByCard($pdo, 'objects_links', $cardIds, $user_id);
        $tipoFrasalTagsByCard = fetchLinkedTagsByCard($pdo, 'tipo_frasal_links', $cardIds, $user_id);
        $tenseTagsByCard = fetchLinkedTagsByCard($pdo, 'tense_links', $cardIds, $user_id);
        $lexicalChunksTagsByCard = fetchLinkedTagsByCard($pdo, 'lexical_chunks_links', $cardIds, $user_id);
        $relationTagsByCard = fetchLinkedTagsByCard($pdo, 'relation_links', $cardIds, $user_id);
        $wordsTagsByCard = fetchLinkedTagsByCard($pdo, 'words_links', $cardIds, $user_id);
        $idiomaPrincipalTagsByCard = fetchLinkedTagsByCardColumn($pdo, 'idiomas_links', 'tag_id', $cardIds, $user_id);
        $idiomaSecundarioTagsByCard = fetchLinkedTagsByCardColumn($pdo, 'idiomas_links', 'segundo_idioma_tag_id', $cardIds, $user_id);
        
        //entre colchetes estão as tags que serão consideradas nos evenCards/cards pares
        $graphCards = buildGraphCardsSequence(
            $cards,
            $subjectTagsByCard,
            6,
            [
                $subjectTagsByCard,
                $objectTagsByCard,
                $lexicalChunksTagsByCard,
                $wordsTagsByCard
            ]
        );
        if (!empty($graphCards)) {
            $cardsById = [];
            foreach ($cards as $cardRow) $cardsById[(int)$cardRow['id']] = $cardRow;

            $orderedCards = [];
            foreach ($graphCards as $entry) {
                $cid = (int)($entry['card_id'] ?? 0);
                if ($cid <= 0 || !isset($cardsById[$cid])) continue;
                $orderedCards[] = $cardsById[$cid];
                $graphDecisionByCardId[] = isset($entry['decision_tag']) ? (int)$entry['decision_tag'] : null;
            }
            $cards = $orderedCards;
        } else {
            $cards = [];
        }
    }

    $response = [];
    foreach ($cards as $idx => $card) {
        $response[] = [
            'id' => $card['id'],
            'front' => !empty($card['front_encrypted']) ? Security::decryptData($card['front_encrypted']) : '',
            'back' => !empty($card['back_encrypted']) ? Security::decryptData($card['back_encrypted']) : '',
            'image_front' => !empty($card['image_front_encrypted']) ? Security::decryptData($card['image_front_encrypted']) : null,
            'image_back' => !empty($card['image_back_encrypted']) ? Security::decryptData($card['image_back_encrypted']) : null,
            'has_audio_front' => (int)$card['has_audio_front'],
            'has_audio_back' => (int)$card['has_audio_back'],
            'score' => (int)$card['score'],
            'subject_tags' => $subjectTagsByCard[(int)$card['id']] ?? [],
            'object_tags' => $objectTagsByCard[(int)$card['id']] ?? [],
            'tipo_frasal_tags' => $tipoFrasalTagsByCard[(int)$card['id']] ?? [],
            'tense_tags' => $tenseTagsByCard[(int)$card['id']] ?? [],
            'lexical_chunks_tags' => $lexicalChunksTagsByCard[(int)$card['id']] ?? [],
            'relation_tags' => $relationTagsByCard[(int)$card['id']] ?? [],
            'words_tags' => $wordsTagsByCard[(int)$card['id']] ?? [],
            'idioma_principal_tags' => $idiomaPrincipalTagsByCard[(int)$card['id']] ?? [],
            'idioma_secundario_tags' => $idiomaSecundarioTagsByCard[(int)$card['id']] ?? [],
            'idiomas_tags' => $idiomaPrincipalTagsByCard[(int)$card['id']] ?? [],
            'graph_decision_tag_id' => ($deck_mode === 'grafo' ? ($graphDecisionByCardId[$idx] ?? null) : ($graphDecisionByCardId[(int)$card['id']] ?? null))
        ];
    }

    $stored_base_prompt = trim((string)($deck['deck_generation_base_prompt'] ?? ''));
    if ($stored_base_prompt === '') {
        $stored_base_prompt = buildDefaultBasePromptByStructure(
            Security::decryptData($deck['name_encrypted']),
            normalizeDeckStructure($deck['deck_structure'] ?? 'traducoes', 'traducoes')
        );
    }

    echo json_encode([
        'status' => 'success', 
        'deck_name' => Security::decryptData($deck['name_encrypted']),
        'deck_mode' => $deck_mode,
        'deck_front_language' => normalizeDeckLanguage($deck['deck_front_language'] ?? 'pt-BR', 'pt-BR'),
        'deck_back_language' => normalizeDeckLanguage($deck['deck_back_language'] ?? 'en-GB', 'en-GB'),
        'deck_structure' => normalizeDeckStructure($deck['deck_structure'] ?? 'traducoes', 'traducoes'),
        'generation_base_prompt_default' => $stored_base_prompt,
        'deck_percentage' => $deck_percentage,
        'book_completed_reads' => $book_completed_reads,
        'book_completed_reads_max' => 3,
        'book_next_review_at' => $book_next_review_at,
        'random_next_review_at' => $random_next_review_at,
        'total_cards' => $total_cards_in_deck,
        'current_index' => $current_index,
        'data' => $response
    ]);
}

// ==== Streaming de áudio do card via conteúdo criptografado no banco ====
elseif ($action === 'get_audio') {
    $card_id = (int)($_GET['card_id'] ?? 0);
    $side = ($_GET['side'] ?? '') === 'back' ? 'back' : 'front';

    if ($card_id === 0) {
        http_response_code(400);
        die('Card inválido');
    }

    if (!verifyCardOwnership($pdo, $card_id, $user_id)) {
        http_response_code(403);
        die('Acesso negado');
    }

    $audioCol = $side === 'front' ? 'audio_front_encrypted' : 'audio_back_encrypted';
    $hasAudioCol = $side === 'front' ? 'has_audio_front' : 'has_audio_back';
    $stmt = $pdo->prepare("SELECT $audioCol AS audio_encrypted, $hasAudioCol AS has_audio FROM flashcards WHERE id = ? LIMIT 1");
    $stmt->execute([$card_id]);
    $card = $stmt->fetch();

    if (!$card || (int)$card['has_audio'] !== 1 || empty($card['audio_encrypted'])) {
        http_response_code(404);
        die('Áudio não encontrado');
    }

    $audio_decrypted = Security::decryptData($card['audio_encrypted']);
    $audio_binary = normalizeStoredAudioToBinary($audio_decrypted);

    if ($audio_binary === null || $audio_binary === '') {
        http_response_code(500);
        die('Falha ao ler áudio');
    }

    header('Content-Type: audio/mpeg');
    header('Content-Length: ' . strlen($audio_binary));
    echo $audio_binary;
    exit;
}

// ==== Exportar todos os cards para Excel/CSV ====
elseif ($action === 'get_all_cards') {
    $deck_id = (int)($input['deck_id'] ?? 0);
    if ($deck_id === 0) die(json_encode(['status' => 'error', 'message' => 'ID do deck inválido.']));

    // Verifica posse do deck usando a sua função de segurança
    $deck = verifyDeckOwnership($pdo, $deck_id, $user_id);
    if (!$deck) {
        die(json_encode(['status' => 'error', 'message' => 'Deck não encontrado ou sem permissão.']));
    }
    $unlockError = validatePhaseDeckUnlock($pdo, $deck_id, $user_id);
    if ($unlockError !== null) {
        die(json_encode(['status' => 'error', 'message' => $unlockError]));
    }

    // Busca ABSOLUTAMENTE TODOS os cards do deck
    $stmt = $pdo->prepare("
        SELECT id, front_encrypted, back_encrypted 
        FROM flashcards 
        WHERE directory_id = ? 
        ORDER BY sort_order ASC, id ASC
    ");
    $stmt->execute([$deck_id]);
    
    $cards = $stmt->fetchAll();
    $response = [];
    
    foreach ($cards as $card) {
        $response[] = [
            'id' => $card['id'],
            'front' => !empty($card['front_encrypted']) ? Security::decryptData($card['front_encrypted']) : '',
            'back' => !empty($card['back_encrypted']) ? Security::decryptData($card['back_encrypted']) : ''
        ];
    }

    echo json_encode([
        'status' => 'success',
        'data' => $response
    ]);
}

elseif ($action === 'generate_audio') {
    $card_id = (int)($input['card_id'] ?? 0);
    $side = $input['side'] ?? 'back'; // 'front' ou 'back'

    if ($card_id === 0 || !in_array($side, ['front', 'back'])) {
        die(json_encode(['status' => 'error', 'message' => 'Parâmetros inválidos.']));
    }

    $stmt = $pdo->prepare("SELECT f.front_encrypted, f.back_encrypted, d.user_id, d.deck_front_language, d.deck_back_language, d.deck_structure FROM flashcards f JOIN directories d ON f.directory_id = d.id WHERE f.id = ?");
    $stmt->execute([$card_id]);
    $card = $stmt->fetch();

    if (!$card || $card['user_id'] != $user_id) {
        die(json_encode(['status' => 'error', 'message' => 'Acesso negado.']));
    }

    $text_encrypted = $side === 'front' ? $card['front_encrypted'] : $card['back_encrypted'];
    $clean_text = trim(strip_tags(Security::decryptData($text_encrypted)));

    if (empty($clean_text)) {
        die(json_encode(['status' => 'error', 'message' => 'O lado selecionado deste card não possui texto.']));
    }

    if (cardTextContainsMathNotation($clean_text)) {
        die(json_encode(['status' => 'error', 'message' => 'Este conteúdo possui notação matemática e não pode ter áudio gerado.']));
    }

    $front_language = normalizeDeckLanguage($card['deck_front_language'] ?? 'pt-BR', 'pt-BR');
    $back_language = normalizeDeckLanguage($card['deck_back_language'] ?? 'en-GB', 'en-GB');
    $deck_structure = normalizeDeckStructure($card['deck_structure'] ?? 'traducoes', 'traducoes');
    $side_language = $side === 'front' ? $front_language : $back_language;

    $tts_error_details = null;
    $ok = generateAndPersistCardAudio($pdo, $user_id, $card_id, $side, $clean_text, $side_language, $deck_structure, $front_language, $back_language, $tts_error_details);
    if (!$ok) {
        die(json_encode([
            'status' => 'error',
            'message' => 'Erro ao comunicar com a API de voz. O serviço pode estar indisponível.',
            'details' => $tts_error_details ?: 'Sem detalhes adicionais retornados pelo provider.'
        ]));
    }

    echo json_encode(['status' => 'success', 'message' => 'Áudio gerado e salvo com sucesso!']);
}

elseif ($action === 'generate_missing_audios_from_directory') {
    $directory_id = (int)($input['directory_id'] ?? 0);
    if ($directory_id === 0) {
        die(json_encode(['status' => 'error', 'message' => 'ID do diretório inválido.']));
    }

    $directory = verifyDirectoryOwnership($pdo, $directory_id, $user_id);
    if (!$directory) {
        die(json_encode(['status' => 'error', 'message' => 'Deck não encontrado ou sem permissão.']));
    }

    $decks = collectDecksFromDirectoryTree($pdo, $directory_id, $user_id);

    $generated_count = 0;
    $skipped_count = 0;
    $failed_count = 0;

    $stmtCards = $pdo->prepare("SELECT id, front_encrypted, back_encrypted, has_audio_front, has_audio_back FROM flashcards WHERE directory_id = ? ORDER BY sort_order ASC, id ASC");
    foreach ($decks as $deck) {
        $deck_id = (int)$deck['id'];
        $front_language = normalizeDeckLanguage($deck['deck_front_language'] ?? 'pt-BR', 'pt-BR');
        $back_language = normalizeDeckLanguage($deck['deck_back_language'] ?? 'en-GB', 'en-GB');
        $deck_structure = normalizeDeckStructure($deck['deck_structure'] ?? 'traducoes', 'traducoes');

        $stmtCards->execute([$deck_id]);
        $cards = $stmtCards->fetchAll();

        foreach ($cards as $card) {
            $front_text = !empty($card['front_encrypted']) ? trim(strip_tags(Security::decryptData($card['front_encrypted']))) : '';
            $back_text = !empty($card['back_encrypted']) ? trim(strip_tags(Security::decryptData($card['back_encrypted']))) : '';

            $jobs = [
                [
                    'side' => 'front',
                    'has_audio' => (int)$card['has_audio_front'] === 1,
                    'text' => $front_text,
                    'language' => $front_language
                ],
                [
                    'side' => 'back',
                    'has_audio' => (int)$card['has_audio_back'] === 1,
                    'text' => $back_text,
                    'language' => $back_language
                ]
            ];

            foreach ($jobs as $job) {
                if ($job['has_audio']) {
                    $skipped_count++;
                    continue;
                }

                if ($job['text'] === '') {
                    $skipped_count++;
                    continue;
                }

                if (cardTextContainsMathNotation($job['text'])) {
                    $skipped_count++;
                    continue;
                }

                $ok = generateAndPersistCardAudio($pdo, $user_id, (int)$card['id'], $job['side'], $job['text'], $job['language'], $deck_structure, $front_language, $back_language);
                if ($ok) {
                    $generated_count++;
                } else {
                    $failed_count++;
                }
            }
        }
    }

    $baseMessage = 'Geração em fila concluída.';
    if ($generated_count === 0 && $failed_count === 0) {
        $baseMessage = 'Nenhum áudio pendente com texto encontrado neste diretório e subdiretórios.';
    } elseif ($failed_count > 0) {
        $baseMessage = 'Processo concluído com falhas em alguns cards.';
    }

    echo json_encode([
        'status' => 'success',
        'message' => $baseMessage,
        'data' => [
            'generated_count' => $generated_count,
            'skipped_count' => $skipped_count,
            'failed_count' => $failed_count
        ]
    ]);
}

elseif ($action === 'count_missing_audios_from_directory') {
    $directory_id = (int)($input['directory_id'] ?? 0);
    if ($directory_id === 0) {
        die(json_encode(['status' => 'error', 'message' => 'ID do diretório inválido.']));
    }

    $directory = verifyDirectoryOwnership($pdo, $directory_id, $user_id);
    if (!$directory) {
        die(json_encode(['status' => 'error', 'message' => 'Deck não encontrado ou sem permissão.']));
    }

    $decks = collectDecksFromDirectoryTree($pdo, $directory_id, $user_id);
    $total_pending = 0;

    foreach ($decks as $deck) {
        $total_pending += countPendingAudiosForDeck($pdo, (int)$deck['id']);
    }

    echo json_encode([
        'status' => 'success',
        'message' => $total_pending > 0 ? 'Áudios pendentes encontrados.' : 'Nenhum áudio pendente encontrado.',
        'data' => [
            'total_pending' => $total_pending
        ]
    ]);
}

elseif ($action === 'count_missing_audios_from_deck') {
    $deck_id = (int)($input['deck_id'] ?? 0);
    if ($deck_id === 0) {
        die(json_encode(['status' => 'error', 'message' => 'ID do deck inválido.']));
    }

    $deck = verifyDeckOwnership($pdo, $deck_id, $user_id);
    if (!$deck) {
        die(json_encode(['status' => 'error', 'message' => 'Deck não encontrado ou sem permissão.']));
    }

    $unlockError = validatePhaseDeckUnlock($pdo, $deck_id, $user_id);
    if ($unlockError !== null) {
        die(json_encode(['status' => 'error', 'message' => $unlockError]));
    }

    $total_pending = countPendingAudiosForDeck($pdo, $deck_id);

    echo json_encode([
        'status' => 'success',
        'message' => $total_pending > 0 ? 'Áudios pendentes encontrados.' : 'Nenhum áudio pendente encontrado.',
        'data' => [
            'total_pending' => $total_pending
        ]
    ]);
}

elseif ($action === 'generate_next_missing_audio_from_directory') {
    $directory_id = (int)($input['directory_id'] ?? 0);
    if ($directory_id === 0) {
        die(json_encode(['status' => 'error', 'message' => 'ID do diretório inválido.']));
    }

    $directory = verifyDirectoryOwnership($pdo, $directory_id, $user_id);
    if (!$directory) {
        die(json_encode(['status' => 'error', 'message' => 'Deck não encontrado ou sem permissão.']));
    }

    $decks = collectDecksFromDirectoryTree($pdo, $directory_id, $user_id);
    $next_job = null;

    foreach ($decks as $deck) {
        $front_language = normalizeDeckLanguage($deck['deck_front_language'] ?? 'pt-BR', 'pt-BR');
        $back_language = normalizeDeckLanguage($deck['deck_back_language'] ?? 'en-GB', 'en-GB');
        $deck_structure = normalizeDeckStructure($deck['deck_structure'] ?? 'traducoes', 'traducoes');
        $next_job = findNextPendingAudioJobForDeck($pdo, (int)$deck['id'], $front_language, $back_language);
        if ($next_job) {
            $next_job['deck_structure'] = $deck_structure;
            $next_job['front_language'] = $front_language;
            $next_job['back_language'] = $back_language;
            break;
        }
    }

    if (!$next_job) {
        echo json_encode([
            'status' => 'success',
            'message' => 'Nenhum áudio pendente restante.',
            'data' => [
                'done' => true,
                'generated_count' => 0,
                'failed_count' => 0,
                'remaining_pending' => 0
            ]
        ]);
        exit;
    }

    $ok = generateAndPersistCardAudio(
        $pdo,
        $user_id,
        $next_job['card_id'],
        $next_job['side'],
        $next_job['text'],
        $next_job['language'],
        $next_job['deck_structure'] ?? 'traducoes',
        $next_job['front_language'] ?? 'pt-BR',
        $next_job['back_language'] ?? 'en-GB'
    );
    $remaining_pending = 0;
    foreach ($decks as $deck) {
        $remaining_pending += countPendingAudiosForDeck($pdo, (int)$deck['id']);
    }

    echo json_encode([
        'status' => 'success',
        'message' => $ok ? 'Áudio gerado com sucesso.' : 'Falha ao gerar áudio do card atual.',
        'data' => [
            'done' => $remaining_pending === 0,
            'generated_count' => $ok ? 1 : 0,
            'failed_count' => $ok ? 0 : 1,
            'remaining_pending' => $remaining_pending,
            'card_id' => $next_job['card_id'],
            'side' => $next_job['side']
        ]
    ]);
}

elseif ($action === 'generate_next_missing_audio_from_deck') {
    $deck_id = (int)($input['deck_id'] ?? 0);
    if ($deck_id === 0) {
        die(json_encode(['status' => 'error', 'message' => 'ID do deck inválido.']));
    }

    $deck = verifyDeckOwnership($pdo, $deck_id, $user_id);
    if (!$deck) {
        die(json_encode(['status' => 'error', 'message' => 'Deck não encontrado ou sem permissão.']));
    }

    $unlockError = validatePhaseDeckUnlock($pdo, $deck_id, $user_id);
    if ($unlockError !== null) {
        die(json_encode(['status' => 'error', 'message' => $unlockError]));
    }

    $front_language = normalizeDeckLanguage($deck['deck_front_language'] ?? 'pt-BR', 'pt-BR');
    $back_language = normalizeDeckLanguage($deck['deck_back_language'] ?? 'en-GB', 'en-GB');
    $deck_structure = normalizeDeckStructure($deck['deck_structure'] ?? 'traducoes', 'traducoes');

    $next_job = findNextPendingAudioJobForDeck($pdo, $deck_id, $front_language, $back_language);
    if (!$next_job) {
        echo json_encode([
            'status' => 'success',
            'message' => 'Nenhum áudio pendente restante.',
            'data' => [
                'done' => true,
                'generated_count' => 0,
                'failed_count' => 0,
                'remaining_pending' => 0
            ]
        ]);
        exit;
    }

    $ok = generateAndPersistCardAudio(
        $pdo,
        $user_id,
        $next_job['card_id'],
        $next_job['side'],
        $next_job['text'],
        $next_job['language'],
        $deck_structure,
        $front_language,
        $back_language
    );

    $remaining_pending = countPendingAudiosForDeck($pdo, $deck_id);

    echo json_encode([
        'status' => 'success',
        'message' => $ok ? 'Áudio gerado com sucesso.' : 'Falha ao gerar áudio do card atual.',
        'data' => [
            'done' => $remaining_pending === 0,
            'generated_count' => $ok ? 1 : 0,
            'failed_count' => $ok ? 0 : 1,
            'remaining_pending' => $remaining_pending,
            'card_id' => $next_job['card_id'],
            'side' => $next_job['side']
        ]
    ]);
}

elseif ($action === 'translate_text') {
    $text = trim($input['text'] ?? '');
    $source_language = normalizeDeckLanguage($input['source_language'] ?? 'pt-BR', 'pt-BR');
    $target_language = normalizeDeckLanguage($input['target_language'] ?? 'en-GB', 'en-GB');

    if ($text === '') {
        die(json_encode(['status' => 'error', 'message' => 'Texto inválido para tradução.']));
    }

    if ($source_language === $target_language) {
        echo json_encode(['status' => 'success', 'translation' => $text]);
        exit;
    }

    if (OPENAI_API_KEY === '') {
        die(json_encode(['status' => 'error', 'message' => 'OPENAI_API_KEY não configurada no .env.']));
    }

    $systemPrompt = sprintf(
        'Você é um tradutor automático direto e focado. Traduza de %s para %s e retorne EXCLUSIVAMENTE a tradução.',
        getLanguageLabel($source_language),
        getLanguageLabel($target_language)
    );

    $payload = json_encode([
        'model' => 'gpt-5.4',
        'messages' => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $text]
        ],
        'temperature' => 0.3
    ]);

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . OPENAI_API_KEY
    ]);

    $response = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpcode !== 200 || !$response) {
        die(json_encode(['status' => 'error', 'message' => 'Erro ao traduzir com a OpenAI.']));
    }

    $decoded = json_decode($response, true);
    $translation = trim($decoded['choices'][0]['message']['content'] ?? '');

    if ($translation === '') {
        die(json_encode(['status' => 'error', 'message' => 'A API não retornou tradução válida.']));
    }

    echo json_encode(['status' => 'success', 'translation' => $translation]);
}


elseif ($action === 'sync_back_phrase_dictionary') {
    $text = trim((string)($input['text'] ?? ''));
    $dictionary_parent_id = (int)($input['dictionary_parent_id'] ?? 6223);

    if ($text === '') {
        die(json_encode(['status' => 'error', 'message' => 'Texto do verso está vazio.']));
    }

    $stmtParent = $pdo->prepare("SELECT id FROM directories WHERE id = ? AND user_id = ? LIMIT 1");
    $stmtParent->execute([$dictionary_parent_id, $user_id]);
    if (!$stmtParent->fetch()) {
        die(json_encode(['status' => 'error', 'message' => 'Diretório base do dicionário não encontrado para este usuário.']));
    }

    $analysis = extractDictionaryCandidatesFromGpt($text);
    if (!$analysis['ok']) {
        die(json_encode(['status' => 'error', 'message' => $analysis['error'] ?: 'Falha ao analisar frase.']));
    }

    $candidates = $analysis['candidates'];
    if (empty($candidates)) {
        echo json_encode([
            'status' => 'success',
            'message' => 'Nenhum candidato novo foi retornado pelo GPT.',
            'candidate_count' => 0,
            'created_count' => 0,
            'skipped_count' => 0,
            'created_items' => []
        ]);
        exit;
    }

    try {
        $pdo->beginTransaction();

        $stmtChildren = $pdo->prepare("SELECT id, name_encrypted FROM directories WHERE user_id = ? AND parent_id = ?");
        $stmtChildren->execute([$user_id, $dictionary_parent_id]);
        $children = $stmtChildren->fetchAll();

        $existingByName = [];
        foreach ($children as $child) {
            $name = normalizeDictionarySentence(Security::decryptData($child['name_encrypted'] ?? ''));
            $nameKey = normalizeDictionarySentenceKey($name);
            if ($nameKey !== '') {
                $existingByName[$nameKey] = true;
            }
        }

        $stmtSort = $pdo->prepare("SELECT COALESCE(MAX(sort_order), -1) FROM directories WHERE user_id = ? AND parent_id = ?");
        $stmtSort->execute([$user_id, $dictionary_parent_id]);
        $nextSort = (int)$stmtSort->fetchColumn() + 1;

        $stmtInsert = $pdo->prepare("INSERT INTO directories (user_id, parent_id, type, name_encrypted, default_view, open_mode, new_item_position, sort_order, icon, icon_color_from, icon_color_to, deck_front_language, deck_back_language, deck_structure, child_default_type, child_default_view) VALUES (?, ?, 10, ?, 'grid', 'fullscreen', 'end', ?, 'fa-layer-group', '#3b82f6', '#6366f1', 'pt-BR', 'en-GB', 'traducoes', 0, 'grid')");

        $createdItems = [];
        $createdCount = 0;
        foreach ($candidates as $candidate) {
            $candidateSentence = normalizeDictionarySentence($candidate);
            $candidateKey = normalizeDictionarySentenceKey($candidateSentence);
            if ($candidateSentence === '' || $candidateKey === '' || isset($existingByName[$candidateKey])) {
                continue;
            }

            $stmtInsert->execute([$user_id, $dictionary_parent_id, Security::encryptData($candidateSentence), $nextSort]);
            $nextSort++;
            $createdCount++;
            $createdItems[] = $candidateSentence;
            $existingByName[$candidateKey] = true;
        }

        $pdo->commit();

        echo json_encode([
            'status' => 'success',
            'message' => 'Sincronização do dicionário concluída.',
            'candidate_count' => count($candidates),
            'created_count' => $createdCount,
            'skipped_count' => count($candidates) - $createdCount,
            'created_items' => $createdItems
        ]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        die(json_encode(['status' => 'error', 'message' => 'Erro ao sincronizar diretórios de dicionário.']));
    }
}



elseif ($action === 'update_score') {
    $card_id = (int)($input['card_id'] ?? 0);
    if ($card_id === 0) die(json_encode(['status' => 'error', 'message' => 'ID do card inválido.']));

    if (!verifyCardOwnership($pdo, $card_id, $user_id, true)) {
        die(json_encode(['status' => 'error', 'message' => 'Acesso negado.']));
    }

    $stmt = $pdo->prepare("
        INSERT INTO flashcard_scores (user_id, flashcard_id, score, next_review_at) 
        VALUES (?, ?, 1, DATE_ADD(NOW(), INTERVAL 24 HOUR)) 
        ON DUPLICATE KEY UPDATE 
            score = LEAST(score + 1, 20), 
            last_reviewed_at = CURRENT_TIMESTAMP,
            next_review_at = DATE_ADD(NOW(), INTERVAL (LEAST(score + 1, 20) * 24) HOUR)
    ");
    
    if ($stmt->execute([$user_id, $card_id])) echo json_encode(['status' => 'success']);
    else echo json_encode(['status' => 'error']);
}

elseif ($action === 'update_progress') {
    $deck_id = (int)($input['deck_id'] ?? 0);
    $index = (int)($input['index'] ?? 0);

    $stmt = $pdo->prepare("
        INSERT INTO flashcard_book_progress (user_id, directory_id, current_index) 
        VALUES (?, ?, ?) 
        ON DUPLICATE KEY UPDATE current_index = ?
    ");
    $stmt->execute([$user_id, $deck_id, $index, $index]);
    echo json_encode(['status' => 'success']);
}

elseif ($action === 'increment_book_score') {
    $deck_id = (int)($input['deck_id'] ?? 0);
    if ($deck_id === 0) die(json_encode(['status' => 'error', 'message' => 'ID do deck inválido.']));

    $deck = verifyDeckOwnership($pdo, $deck_id, $user_id);
    if (!$deck || ($deck['deck_mode'] ?? 'aleatorio') !== 'livro') {
        die(json_encode(['status' => 'error', 'message' => 'Pontuação disponível apenas para decks no modo livro.']));
    }

    $stmt = $pdo->prepare("
        INSERT INTO flashcard_book_progress (user_id, directory_id, current_index, completed_reads, next_review_at) 
        VALUES (?, ?, 0, 1, DATE_ADD(NOW(), INTERVAL 24 HOUR)) 
        ON DUPLICATE KEY UPDATE
            current_index = 0,
            completed_reads = LEAST(completed_reads + 1, 3),
            next_review_at = DATE_ADD(NOW(), INTERVAL (LEAST(completed_reads + 1, 20) * 24) HOUR)
    ");
    $stmt->execute([$user_id, $deck_id]);
    echo json_encode(['status' => 'success']);
}

elseif ($action === 'reset_book_score') {
    $deck_id = (int)($input['deck_id'] ?? 0);
    if ($deck_id === 0) die(json_encode(['status' => 'error', 'message' => 'ID do deck inválido.']));

    $deck = verifyDeckOwnership($pdo, $deck_id, $user_id);
    if (!$deck) {
        die(json_encode(['status' => 'error', 'message' => 'Deck não encontrado.']));
    }

    $isBookMode = ($deck['deck_mode'] ?? 'aleatorio') === 'livro';

    if ($isBookMode) {
        $stmt = $pdo->prepare("
            INSERT INTO flashcard_book_progress (user_id, directory_id, current_index, completed_reads, next_review_at) 
            VALUES (?, ?, 0, 0, NULL) 
            ON DUPLICATE KEY UPDATE current_index = 0, completed_reads = 0, next_review_at = NULL
        ");
        $stmt->execute([$user_id, $deck_id]);
        echo json_encode(['status' => 'success', 'message' => 'Pontuação do livro zerada.']);
    } else {
        $stmt = $pdo->prepare("
            DELETE fs FROM flashcard_scores fs
            INNER JOIN flashcards f ON f.id = fs.flashcard_id
            WHERE fs.user_id = ? AND f.directory_id = ?
        ");
        $stmt->execute([$user_id, $deck_id]);
        echo json_encode(['status' => 'success', 'message' => 'Pontuação do deck zerada.']);
    }
}

elseif ($action === 'update_settings') {
    $deck_id = (int)($input['deck_id'] ?? 0);
    $allowed_modes = ['aleatorio', 'livro', 'grafo'];
    $mode = in_array(($input['deck_mode'] ?? ''), $allowed_modes, true) ? $input['deck_mode'] : 'aleatorio';
    $front_language = normalizeDeckLanguage($input['deck_front_language'] ?? 'pt-BR', 'pt-BR');
    $back_language = normalizeDeckLanguage($input['deck_back_language'] ?? 'en-GB', 'en-GB');
    $deck_structure = normalizeDeckStructure($input['deck_structure'] ?? 'traducoes', 'traducoes');

    if (!verifyDeckOwnership($pdo, $deck_id, $user_id)) die(json_encode(['status' => 'error', 'message' => 'Acesso negado.']));

    $stmt = $pdo->prepare("UPDATE directories SET deck_mode = ?, deck_front_language = ?, deck_back_language = ?, deck_structure = ? WHERE id = ?");
    if ($stmt->execute([$mode, $front_language, $back_language, $deck_structure, $deck_id])) echo json_encode(['status' => 'success', 'message' => 'Configurações atualizadas.']);
    else echo json_encode(['status' => 'error', 'message' => 'Erro ao salvar.']);
}

// ==== Adicionar Novo Card ====
elseif ($action === 'add_single') {
    $deck_id = (int)($input['deck_id'] ?? 0);
    $front = trim($input['front'] ?? '');
    $back = trim($input['back'] ?? '');
    $image_front = $input['image_front'] ?? null;
    $image_back = $input['image_back'] ?? null; 
    $tag_ids = sanitizeTagIds($input['tag_ids'] ?? []);
    $subject_tag_ids = sanitizeTagIds($input['subject_tag_ids'] ?? []);
    $object_tag_ids = sanitizeTagIds($input['object_tag_ids'] ?? []);
    $tipo_frasal_tag_ids = sanitizeTagIds($input['tipo_frasal_tag_ids'] ?? []);
    $tense_tag_ids = sanitizeTagIds($input['tense_tag_ids'] ?? []);
    $lexical_chunks_tag_ids = sanitizeTagIds($input['lexical_chunks_tag_ids'] ?? []);
    $relation_tag_ids = sanitizeTagIds($input['relation_tag_ids'] ?? []);
    $words_tag_ids = sanitizeTagIds($input['words_tag_ids'] ?? []);
    $idioma_principal_tag_ids = sanitizeTagIds($input['idioma_principal_tag_ids'] ?? ($input['idiomas_tag_ids'] ?? []));
    $idioma_secundario_tag_ids = sanitizeTagIds($input['idioma_secundario_tag_ids'] ?? []);

    $should_use_words_tags_as_back = !empty($input['use_words_tags_as_back']);
    $words_back_text = '';
    if ($should_use_words_tags_as_back && !empty($words_tag_ids)) {
        try {
            $placeholders = implode(',', array_fill(0, count($words_tag_ids), '?'));
            $stmtWordsTags = $pdo->prepare("
                SELECT id, name_encrypted, name_pt_br_encrypted
                FROM flashcard_tags
                WHERE user_id = ?
                  AND id IN ($placeholders)
            ");
            $stmtWordsTags->execute(array_merge([$user_id], $words_tag_ids));

            $wordsTagDataById = [];
            foreach ($stmtWordsTags->fetchAll(PDO::FETCH_ASSOC) as $tag) {
                $tagId = (int)($tag['id'] ?? 0);
                if ($tagId <= 0) {
                    continue;
                }
                $namePtBrRaw = !empty($tag['name_pt_br_encrypted']) ? Security::decryptData((string)$tag['name_pt_br_encrypted']) : '';
                $nameFallbackRaw = !empty($tag['name_encrypted']) ? Security::decryptData((string)$tag['name_encrypted']) : '';
                $namePtBr = trim((string)($namePtBrRaw !== false ? $namePtBrRaw : ''));
                $nameFallback = trim((string)($nameFallbackRaw !== false ? $nameFallbackRaw : ''));
                $wordsTagDataById[$tagId] = [
                    'name_pt_br' => $namePtBr,
                    'name_fallback' => $nameFallback,
                ];
            }

            $valid_words_tag_ids = [];
            $words_back_parts = [];
            foreach ($words_tag_ids as $selectedTagId) {
                $selectedTagId = (int)$selectedTagId;
                if ($selectedTagId <= 0 || !isset($wordsTagDataById[$selectedTagId])) {
                    continue;
                }
                $valid_words_tag_ids[] = $selectedTagId;
                $namePtBr = $wordsTagDataById[$selectedTagId]['name_pt_br'] ?? '';
                $nameFallback = $wordsTagDataById[$selectedTagId]['name_fallback'] ?? '';
                $textPiece = $namePtBr !== '' ? $namePtBr : $nameFallback;
                if ($textPiece !== '') {
                    $words_back_parts[] = $textPiece;
                }
            }

            $words_tag_ids = array_values(array_unique($valid_words_tag_ids));
            $words_back_text = trim(implode(' ', $words_back_parts));
            if ($words_back_text !== '') {
                $back = $words_back_text;
            }
        } catch (Throwable $e) {
            error_log('[flashcards][add_single][words_tags] ' . $e->getMessage());
            $words_tag_ids = [];
        }
    }

    $has_front = !empty($front) || !empty($image_front);
    $has_back = !empty($back) || !empty($image_back);

    if ($deck_id === 0 || (!$has_front && !$has_back)) {
        die(json_encode(['status' => 'error', 'message' => 'Preencha pelo menos um lado do card com texto ou imagem.']));
    }
    $deck = verifyDeckOwnership($pdo, $deck_id, $user_id);
    if (!$deck) {
        die(json_encode(['status' => 'error', 'message' => 'Deck não encontrado.']));
    }

    $front_enc = !empty($front) ? Security::encryptData($front) : null;
    $back_enc = !empty($back) ? Security::encryptData($back) : null;
    $img_front_enc = !empty($image_front) ? Security::encryptData($image_front) : null;
    $img_back_enc = !empty($image_back) ? Security::encryptData($image_back) : null;

    $stmt = $pdo->prepare("INSERT INTO flashcards (directory_id, front_encrypted, back_encrypted, image_front_encrypted, image_back_encrypted, has_audio_front, has_audio_back) VALUES (?, ?, ?, ?, ?, 0, 0)");
    
    if ($stmt->execute([$deck_id, $front_enc, $back_enc, $img_front_enc, $img_back_enc])) {
        $new_card_id = (int)$pdo->lastInsertId();
        syncCardTagLinks($pdo, 'flashcard_tag_links', $new_card_id, $tag_ids, $user_id);
        syncCardTagLinks($pdo, 'subjects_links', $new_card_id, $subject_tag_ids, $user_id);
        syncCardTagLinks($pdo, 'objects_links', $new_card_id, $object_tag_ids, $user_id);
        syncCardTagLinks($pdo, 'tipo_frasal_links', $new_card_id, $tipo_frasal_tag_ids, $user_id);
        syncCardTagLinks($pdo, 'tense_links', $new_card_id, $tense_tag_ids, $user_id);
        syncCardTagLinks($pdo, 'lexical_chunks_links', $new_card_id, $lexical_chunks_tag_ids, $user_id);
        syncCardTagLinks($pdo, 'relation_links', $new_card_id, $relation_tag_ids, $user_id);
        syncCardTagLinks($pdo, 'words_links', $new_card_id, $words_tag_ids, $user_id);
        syncCardIdiomaLinks($pdo, $new_card_id, $idioma_principal_tag_ids, $idioma_secundario_tag_ids, $user_id);
        $front_language = normalizeDeckLanguage($deck['deck_front_language'] ?? 'pt-BR', 'pt-BR');
        $back_language = normalizeDeckLanguage($deck['deck_back_language'] ?? 'en-GB', 'en-GB');
        $deck_structure = normalizeDeckStructure($deck['deck_structure'] ?? 'traducoes', 'traducoes');

        $generated_sides = [];
        $failed_sides = [];

        $front_clean = trim(strip_tags($front));
        if ($front_clean !== '' && !cardTextContainsMathNotation($front_clean)) {
            try {
                $tts_error_details = null;
                $ok_front = generateAndPersistCardAudio(
                    $pdo,
                    $user_id,
                    $new_card_id,
                    'front',
                    $front_clean,
                    $front_language,
                    $deck_structure,
                    $front_language,
                    $back_language,
                    $tts_error_details
                );
                if ($ok_front) $generated_sides[] = 'front';
                else $failed_sides[] = 'front';
            } catch (Throwable $e) {
                $failed_sides[] = 'front';
                error_log('[flashcards][add_single][front_audio] ' . $e->getMessage());
            }
        }

        $back_clean = trim(strip_tags($back));
        $back_audio_language = $words_back_text !== '' ? 'pt-BR' : $back_language;
        if ($back_clean !== '' && !cardTextContainsMathNotation($back_clean)) {
            try {
                $tts_error_details = null;
                $ok_back = generateAndPersistCardAudio(
                    $pdo,
                    $user_id,
                    $new_card_id,
                    'back',
                    $back_clean,
                    $back_audio_language,
                    $deck_structure,
                    $front_language,
                    $back_language,
                    $tts_error_details
                );
                if ($ok_back) $generated_sides[] = 'back';
                else $failed_sides[] = 'back';
            } catch (Throwable $e) {
                $failed_sides[] = 'back';
                error_log('[flashcards][add_single][back_audio] ' . $e->getMessage());
            }
        }

        $message = 'Card adicionado.';
        if (!empty($generated_sides) && empty($failed_sides)) {
            $message .= ' Áudio gerado automaticamente.';
        } elseif (!empty($generated_sides) && !empty($failed_sides)) {
            $message .= ' Áudio parcial gerado automaticamente.';
        } elseif (empty($generated_sides) && !empty($failed_sides)) {
            $message .= ' Não foi possível gerar o áudio automaticamente.';
        }

        echo json_encode([
            'status' => 'success',
            'message' => $message,
            'card_id' => $new_card_id,
            'audio_generated_sides' => $generated_sides,
            'audio_failed_sides' => $failed_sides
        ]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Erro ao adicionar card.']);
    }
}

// ==== Editar Card Existente ====
elseif ($action === 'update_card') {
    $card_id = (int)($input['card_id'] ?? 0);
    $front = trim($input['front'] ?? '');
    $back = trim($input['back'] ?? '');
    $image_front = $input['image_front'] ?? null;
    $image_back = $input['image_back'] ?? null;
    $tag_ids = sanitizeTagIds($input['tag_ids'] ?? []);
    $subject_tag_ids = sanitizeTagIds($input['subject_tag_ids'] ?? []);
    $object_tag_ids = sanitizeTagIds($input['object_tag_ids'] ?? []);
    $tipo_frasal_tag_ids = sanitizeTagIds($input['tipo_frasal_tag_ids'] ?? []);
    $tense_tag_ids = sanitizeTagIds($input['tense_tag_ids'] ?? []);
    $lexical_chunks_tag_ids = sanitizeTagIds($input['lexical_chunks_tag_ids'] ?? []);
    $relation_tag_ids = sanitizeTagIds($input['relation_tag_ids'] ?? []);
    $words_tag_ids = sanitizeTagIds($input['words_tag_ids'] ?? []);
    $idioma_principal_tag_ids = sanitizeTagIds($input['idioma_principal_tag_ids'] ?? ($input['idiomas_tag_ids'] ?? []));
    $idioma_secundario_tag_ids = sanitizeTagIds($input['idioma_secundario_tag_ids'] ?? []);

    $has_front = !empty($front) || !empty($image_front);
    $has_back = !empty($back) || !empty($image_back);

    if ($card_id === 0 || (!$has_front && !$has_back)) {
        die(json_encode(['status' => 'error', 'message' => 'Dados inválidos. Preencha pelo menos um lado do card com texto ou imagem.']));
    }

    if (!verifyCardOwnership($pdo, $card_id, $user_id)) {
        die(json_encode(['status' => 'error', 'message' => 'Acesso negado.']));
    }

    $front_enc = !empty($front) ? Security::encryptData($front) : null;
    $back_enc = !empty($back) ? Security::encryptData($back) : null;
    $img_front_enc = !empty($image_front) ? Security::encryptData($image_front) : null;
    $img_back_enc = !empty($image_back) ? Security::encryptData($image_back) : null;

    // Mantém os áudios existentes. Eles só devem ser alterados quando o usuário solicitar nova geração.
    $stmt = $pdo->prepare("UPDATE flashcards SET front_encrypted = ?, back_encrypted = ?, image_front_encrypted = ?, image_back_encrypted = ? WHERE id = ?");
    
    if ($stmt->execute([$front_enc, $back_enc, $img_front_enc, $img_back_enc, $card_id])) {
        syncCardTagLinks($pdo, 'flashcard_tag_links', $card_id, $tag_ids, $user_id);
        syncCardTagLinks($pdo, 'subjects_links', $card_id, $subject_tag_ids, $user_id);
        syncCardTagLinks($pdo, 'objects_links', $card_id, $object_tag_ids, $user_id);
        syncCardTagLinks($pdo, 'tipo_frasal_links', $card_id, $tipo_frasal_tag_ids, $user_id);
        syncCardTagLinks($pdo, 'tense_links', $card_id, $tense_tag_ids, $user_id);
        syncCardTagLinks($pdo, 'lexical_chunks_links', $card_id, $lexical_chunks_tag_ids, $user_id);
        syncCardTagLinks($pdo, 'relation_links', $card_id, $relation_tag_ids, $user_id);
        syncCardTagLinks($pdo, 'words_links', $card_id, $words_tag_ids, $user_id);
        syncCardIdiomaLinks($pdo, $card_id, $idioma_principal_tag_ids, $idioma_secundario_tag_ids, $user_id);
        echo json_encode(['status' => 'success', 'message' => 'Card atualizado.']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Erro ao atualizar card.']);
    }
}

// ==== Deletar Card ====
elseif ($action === 'delete_card') {
    $card_id = (int)($input['card_id'] ?? 0);

    if ($card_id === 0) {
        die(json_encode(['status' => 'error', 'message' => 'ID do card inválido.']));
    }

    if (!verifyCardOwnership($pdo, $card_id, $user_id)) {
        die(json_encode(['status' => 'error', 'message' => 'Acesso negado.']));
    }

    $stmt = $pdo->prepare("DELETE FROM flashcards WHERE id = ?");
    
    if ($stmt->execute([$card_id])) {
        echo json_encode(['status' => 'success', 'message' => 'Card excluído com sucesso.']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Erro interno ao excluir card.']);
    }
}

elseif ($action === 'list_tags') {
    $stmt = $pdo->prepare("SELECT id, user_id, name_encrypted, name_pt_br_encrypted, numero, color, is_book, is_verb_tense, is_sentence_type, is_lexical_chunk, is_relation_type, is_word, is_month, is_day, is_year FROM flashcard_tags WHERE user_id IN (?, 5) ORDER BY id ASC");
    $stmt->execute([$user_id]);
    $tags = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $parsed = [];
    foreach ($tags as $tag) {
        $tag['name'] = !empty($tag['name_encrypted']) ? Security::decryptData($tag['name_encrypted']) : '';
        $tag['name_pt_br'] = !empty($tag['name_pt_br_encrypted']) ? Security::decryptData($tag['name_pt_br_encrypted']) : null;
        unset($tag['name_encrypted'], $tag['name_pt_br_encrypted']);
        $parsed[] = $tag;
    }
    echo json_encode(['status' => 'success', 'data' => $parsed]);
}

elseif ($action === 'create_tag') {
    $name = trim((string)($input['name'] ?? ''));
    $name_pt_br = trim((string)($input['name_pt_br'] ?? ''));
    $numeroRaw = $input['numero'] ?? null;
    $numero = ($numeroRaw === '' || $numeroRaw === null) ? null : (int)$numeroRaw;
    $is_book = !empty($input['is_book']) ? 1 : 0;
    $is_verb_tense = !empty($input['is_verb_tense']) ? 1 : 0;
    $is_sentence_type = !empty($input['is_sentence_type']) ? 1 : 0;
    $is_lexical_chunk = !empty($input['is_lexical_chunk']) ? 1 : 0;
    $is_relation_type = !empty($input['is_relation_type']) ? 1 : 0;
    $is_word = !empty($input['is_word']) ? 1 : 0;
    $is_month = !empty($input['is_month']) ? 1 : 0;
    $is_day = !empty($input['is_day']) ? 1 : 0;
    $is_year = !empty($input['is_year']) ? 1 : 0;
    if ($name === '') die(json_encode(['status' => 'error', 'message' => 'Nome da tag é obrigatório.']));
    if ($name_pt_br === '') $name_pt_br = null;
    if (tagCombinationAlreadyExists($pdo, $user_id, $name, $name_pt_br, $numero)) {
        die(json_encode(['status' => 'error', 'message' => 'Já existe uma tag com essa combinação de nome, nome pt-br e número.']));
    }

    $color = resolveTagColorByCategory([
        'is_book' => $is_book,
        'is_verb_tense' => $is_verb_tense,
        'is_sentence_type' => $is_sentence_type,
        'is_lexical_chunk' => $is_lexical_chunk,
        'is_relation_type' => $is_relation_type,
        'is_word' => $is_word,
        'is_month' => $is_month,
        'is_day' => $is_day,
        'is_year' => $is_year,
    ]);

    $name_enc = Security::encryptData($name);
    $name_pt_br_enc = $name_pt_br !== null ? Security::encryptData($name_pt_br) : null;
    $stmt = $pdo->prepare("INSERT INTO flashcard_tags (user_id, name_encrypted, name_pt_br_encrypted, numero, color, is_book, is_verb_tense, is_sentence_type, is_lexical_chunk, is_relation_type, is_word, is_month, is_day, is_year) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    try {
        $stmt->execute([$user_id, $name_enc, $name_pt_br_enc, $numero, $color, $is_book, $is_verb_tense, $is_sentence_type, $is_lexical_chunk, $is_relation_type, $is_word, $is_month, $is_day, $is_year]);
    } catch (PDOException $e) {
        die(json_encode(['status' => 'error', 'message' => 'Já existe uma tag com esse nome.']));
    }
    echo json_encode(['status' => 'success', 'message' => 'Tag criada com sucesso.', 'tag_id' => (int)$pdo->lastInsertId()]);
}

elseif ($action === 'update_tag') {
    $tag_id = (int)($input['id'] ?? 0);
    $name = trim((string)($input['name'] ?? ''));
    $name_pt_br = trim((string)($input['name_pt_br'] ?? ''));
    $numeroRaw = $input['numero'] ?? null;
    $numero = ($numeroRaw === '' || $numeroRaw === null) ? null : (int)$numeroRaw;
    $name = preg_replace('/\s+/u', ' ', $name);
    $name_pt_br = preg_replace('/\s+/u', ' ', $name_pt_br);
    $is_book = !empty($input['is_book']) ? 1 : 0;
    $is_verb_tense = !empty($input['is_verb_tense']) ? 1 : 0;
    $is_sentence_type = !empty($input['is_sentence_type']) ? 1 : 0;
    $is_lexical_chunk = !empty($input['is_lexical_chunk']) ? 1 : 0;
    $is_relation_type = !empty($input['is_relation_type']) ? 1 : 0;
    $is_word = !empty($input['is_word']) ? 1 : 0;
    $is_month = !empty($input['is_month']) ? 1 : 0;
    $is_day = !empty($input['is_day']) ? 1 : 0;
    $is_year = !empty($input['is_year']) ? 1 : 0;

    if ($tag_id <= 0 || $name === '') {
        die(json_encode(['status' => 'error', 'message' => 'Dados da tag inválidos.']));
    }
    if ($name_pt_br === '') $name_pt_br = null;
    if (tagCombinationAlreadyExists($pdo, $user_id, $name, $name_pt_br, $numero, $tag_id)) {
        die(json_encode(['status' => 'error', 'message' => 'Já existe uma tag com essa combinação de nome, nome pt-br e número.']));
    }

    $name_enc = Security::encryptData($name);
    $name_pt_br_enc = $name_pt_br !== null ? Security::encryptData($name_pt_br) : null;
    $color = resolveTagColorByCategory([
        'is_book' => $is_book,
        'is_verb_tense' => $is_verb_tense,
        'is_sentence_type' => $is_sentence_type,
        'is_lexical_chunk' => $is_lexical_chunk,
        'is_relation_type' => $is_relation_type,
        'is_word' => $is_word,
        'is_month' => $is_month,
        'is_day' => $is_day,
        'is_year' => $is_year
    ]);
    $stmt = $pdo->prepare("UPDATE flashcard_tags SET name_encrypted = ?, name_pt_br_encrypted = ?, numero = ?, color = ?, is_book = ?, is_verb_tense = ?, is_sentence_type = ?, is_lexical_chunk = ?, is_relation_type = ?, is_word = ?, is_month = ?, is_day = ?, is_year = ? WHERE id = ? AND user_id = ?");
    try {
        $stmt->execute([$name_enc, $name_pt_br_enc, $numero, $color, $is_book, $is_verb_tense, $is_sentence_type, $is_lexical_chunk, $is_relation_type, $is_word, $is_month, $is_day, $is_year, $tag_id, $user_id]);
    } catch (PDOException $e) {
        die(json_encode(['status' => 'error', 'message' => 'Já existe uma tag com esse nome.']));
    }

    if ($stmt->rowCount() === 0) {
        $checkStmt = $pdo->prepare("SELECT id FROM flashcard_tags WHERE id = ? AND user_id = ? LIMIT 1");
        $checkStmt->execute([$tag_id, $user_id]);
        if (!$checkStmt->fetchColumn()) {
            die(json_encode(['status' => 'error', 'message' => 'Tag não encontrada.']));
        }
    }

    echo json_encode(['status' => 'success', 'message' => 'Tag atualizada com sucesso.']);
}


elseif ($action === 'create_batch_generation') {
    $deck_id = (int)($input['deck_id'] ?? 0);
    if ($deck_id === 0) die(json_encode(['status' => 'error', 'message' => 'ID do deck inválido.']));

    $deck = verifyDeckOwnership($pdo, $deck_id, $user_id);
    if (!$deck) die(json_encode(['status' => 'error', 'message' => 'Deck não encontrado ou sem permissão.']));
    if (OPENAI_API_KEY === '') die(json_encode(['status' => 'error', 'message' => 'OPENAI_API_KEY não configurada no .env.']));

    $deck_name = Security::decryptData($deck['name_encrypted']);
    $topic_input = trim((string)($input['topic'] ?? ''));
    if ($topic_input !== '') {
        $deck_name = function_exists('mb_substr') ? mb_substr($topic_input, 0, 200) : substr($topic_input, 0, 200);
    }
    $deck_structure = normalizeDeckStructure($deck['deck_structure'] ?? 'traducoes', 'traducoes');
    $custom_base_prompt = normalizeGenerationBasePromptInput($input['base_prompt'] ?? '');
    if ($custom_base_prompt !== '') {
        $savePromptStmt = $pdo->prepare("UPDATE directories SET deck_generation_base_prompt = ? WHERE id = ? AND user_id = ? LIMIT 1");
        $savePromptStmt->execute([$custom_base_prompt, $deck_id, $user_id]);
    }
    $historyText = $deck_structure === 'parafrases' ? '' : fetchDeckHistoryText($pdo, $deck_id);
    $chatPayload = buildFlashcardsGenerationPayload($deck_name, $deck_structure, $historyText, 'gpt-5.4', $custom_base_prompt);

    $jsonlLine = json_encode([
        'custom_id' => 'deck_' . $deck_id . '_user_' . $user_id . '_' . time(),
        'method' => 'POST',
        'url' => '/v1/chat/completions',
        'body' => $chatPayload
    ], JSON_UNESCAPED_UNICODE);

    if ($jsonlLine === false) {
        die(json_encode(['status' => 'error', 'message' => 'Falha ao montar payload JSONL.']));
    }

    $tmpFile = tempnam(sys_get_temp_dir(), 'gluon_batch_');
    file_put_contents($tmpFile, $jsonlLine . "
");

    $ch = curl_init('https://api.openai.com/v1/files');
    $postFields = [
        'purpose' => 'batch',
        'file' => new CURLFile($tmpFile, 'application/jsonl', 'flashcards_batch.jsonl')
    ];
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . OPENAI_API_KEY]);
    $uploadResponse = curl_exec($ch);
    $uploadCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $uploadErr = curl_error($ch);
    curl_close($ch);
    @unlink($tmpFile);

    if ($uploadCode !== 200 || !$uploadResponse) {
        $details = trim($uploadErr);
        $decodedErr = json_decode((string)$uploadResponse, true);
        if (!$details && is_array($decodedErr)) $details = (string)($decodedErr['error']['message'] ?? '');
        die(json_encode(['status' => 'error', 'message' => 'Erro ao enviar arquivo batch para OpenAI.' . ($details ? (' Detalhes: ' . $details) : '')]));
    }

    $uploadDecoded = json_decode($uploadResponse, true);
    $inputFileId = trim((string)($uploadDecoded['id'] ?? ''));
    if ($inputFileId === '') die(json_encode(['status' => 'error', 'message' => 'OpenAI não retornou input_file_id.']));

    list($batchCode, $batchResponse, $batchErr) = openaiJsonRequest('https://api.openai.com/v1/batches', [
        'input_file_id' => $inputFileId,
        'endpoint' => '/v1/chat/completions',
        'completion_window' => '24h',
        'metadata' => [
            'app' => 'gluon',
            'feature' => 'flashcards_batch',
            'user_id' => (string)$user_id,
            'deck_id' => (string)$deck_id
        ]
    ]);

    if ($batchCode !== 200 || !$batchResponse) {
        $details = trim($batchErr);
        $decodedErr = json_decode((string)$batchResponse, true);
        if (!$details && is_array($decodedErr)) $details = (string)($decodedErr['error']['message'] ?? '');
        die(json_encode(['status' => 'error', 'message' => 'Erro ao criar job batch na OpenAI.' . ($details ? (' Detalhes: ' . $details) : '')]));
    }

    $batchDecoded = json_decode($batchResponse, true);
    $openaiBatchId = trim((string)($batchDecoded['id'] ?? ''));
    $status = trim((string)($batchDecoded['status'] ?? 'submitted'));
    if ($openaiBatchId === '') die(json_encode(['status' => 'error', 'message' => 'OpenAI não retornou batch_id.']));

    $stmt = $pdo->prepare("INSERT INTO flashcard_batch_jobs (user_id, directory_id, topic, deck_structure, openai_input_file_id, openai_batch_id, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$user_id, $deck_id, $topic_input !== '' ? $topic_input : null, $deck_structure, $inputFileId, $openaiBatchId, $status]);
    $jobId = (int)$pdo->lastInsertId();

    echo json_encode([
        'status' => 'success',
        'message' => 'Batch enviado com sucesso para OpenAI.',
        'job' => [
            'id' => $jobId,
            'openai_batch_id' => $openaiBatchId,
            'openai_input_file_id' => $inputFileId,
            'status' => $status
        ]
    ]);
}

elseif ($action === 'list_batch_generations') {
    $deck_id = (int)($input['deck_id'] ?? 0);
    if ($deck_id === 0) die(json_encode(['status' => 'error', 'message' => 'ID do deck inválido.']));
    if (!verifyDeckOwnership($pdo, $deck_id, $user_id)) die(json_encode(['status' => 'error', 'message' => 'Deck não encontrado ou sem permissão.']));

    $stmt = $pdo->prepare("SELECT id, topic, deck_structure, openai_batch_id, openai_input_file_id, openai_output_file_id, status, error_message, result_cards_json, created_at, updated_at, completed_at FROM flashcard_batch_jobs WHERE user_id = ? AND directory_id = ? ORDER BY id DESC LIMIT 30");
    $stmt->execute([$user_id, $deck_id]);
    $rows = $stmt->fetchAll();

    if (OPENAI_API_KEY !== '') {
        foreach ($rows as $idx => $row) {
            if (!in_array($row['status'], ['submitted', 'validating', 'in_progress', 'finalizing'], true)) continue;
            $synced = syncBatchJobWithOpenAI($pdo, $row);
            if (!$synced['ok'] || empty($synced['job'])) continue;

            $rows[$idx]['status'] = $synced['job']['status'];
            $rows[$idx]['openai_output_file_id'] = $synced['job']['openai_output_file_id'] ?: null;
            $rows[$idx]['error_message'] = $synced['job']['error_message'];
            if ($synced['job']['has_result']) {
                $rows[$idx]['result_cards_json'] = '__HAS_RESULT__';
            }
        }
    }

    $jobs = [];
    foreach ($rows as $r) {
        $jobs[] = [
            'id' => (int)$r['id'],
            'topic' => $r['topic'] ?? '',
            'deck_structure' => $r['deck_structure'],
            'openai_batch_id' => $r['openai_batch_id'],
            'openai_input_file_id' => $r['openai_input_file_id'],
            'openai_output_file_id' => $r['openai_output_file_id'],
            'status' => $r['status'],
            'error_message' => $r['error_message'],
            'has_result' => !empty($r['result_cards_json']),
            'created_at' => $r['created_at'],
            'updated_at' => $r['updated_at'],
            'completed_at' => $r['completed_at']
        ];
    }

    echo json_encode(['status' => 'success', 'jobs' => $jobs]);
}

elseif ($action === 'delete_batch_generation') {
    $job_id = (int)($input['job_id'] ?? 0);
    if ($job_id === 0) die(json_encode(['status' => 'error', 'message' => 'ID do job inválido.']));

    $stmt = $pdo->prepare("SELECT j.id, j.directory_id, d.user_id as owner_id FROM flashcard_batch_jobs j JOIN directories d ON d.id = j.directory_id WHERE j.id = ? LIMIT 1");
    $stmt->execute([$job_id]);
    $job = $stmt->fetch();
    if (!$job || (int)$job['owner_id'] !== (int)$user_id) die(json_encode(['status' => 'error', 'message' => 'Job não encontrado ou sem permissão.']));

    $deleteStmt = $pdo->prepare("DELETE FROM flashcard_batch_jobs WHERE id = ? LIMIT 1");
    if (!$deleteStmt->execute([$job_id])) {
        die(json_encode(['status' => 'error', 'message' => 'Erro ao excluir batch.']));
    }

    echo json_encode(['status' => 'success', 'message' => 'Batch excluído do histórico com sucesso.']);
}

elseif ($action === 'refresh_batch_generation') {
    $job_id = (int)($input['job_id'] ?? 0);
    if ($job_id === 0) die(json_encode(['status' => 'error', 'message' => 'ID do job inválido.']));
    if (OPENAI_API_KEY === '') die(json_encode(['status' => 'error', 'message' => 'OPENAI_API_KEY não configurada no .env.']));

    $stmt = $pdo->prepare("SELECT j.*, d.user_id as owner_id FROM flashcard_batch_jobs j JOIN directories d ON d.id = j.directory_id WHERE j.id = ? LIMIT 1");
    $stmt->execute([$job_id]);
    $job = $stmt->fetch();
    if (!$job || (int)$job['owner_id'] !== (int)$user_id) die(json_encode(['status' => 'error', 'message' => 'Job não encontrado ou sem permissão.']));

    $synced = syncBatchJobWithOpenAI($pdo, $job);
    if (!$synced['ok']) {
        die(json_encode(['status' => 'error', 'message' => $synced['error'] ?: 'Falha ao sincronizar batch.']));
    }

    echo json_encode(['status' => 'success', 'job' => $synced['job']]);
}

elseif ($action === 'get_batch_generation_result') {
    $job_id = (int)($input['job_id'] ?? 0);
    if ($job_id === 0) die(json_encode(['status' => 'error', 'message' => 'ID do job inválido.']));

    $stmt = $pdo->prepare("SELECT j.result_cards_json, j.status, j.error_message, d.user_id as owner_id FROM flashcard_batch_jobs j JOIN directories d ON d.id = j.directory_id WHERE j.id = ? LIMIT 1");
    $stmt->execute([$job_id]);
    $job = $stmt->fetch();
    if (!$job || (int)$job['owner_id'] !== (int)$user_id) die(json_encode(['status' => 'error', 'message' => 'Job não encontrado ou sem permissão.']));

    $cards = json_decode((string)($job['result_cards_json'] ?? ''), true);
    if (!is_array($cards) || empty($cards)) {
        die(json_encode(['status' => 'error', 'message' => 'Este job ainda não possui resultado pronto. Status atual: ' . ($job['status'] ?? 'desconhecido')]));
    }

    echo json_encode(['status' => 'success', 'cards' => $cards]);
}

elseif ($action === 'generate_cards_preview') {
    $deck_id = (int)($input['deck_id'] ?? 0);
    if ($deck_id === 0) die(json_encode(['status' => 'error', 'message' => 'ID do deck inválido.']));

    $deck = verifyDeckOwnership($pdo, $deck_id, $user_id);
    if (!$deck) die(json_encode(['status' => 'error', 'message' => 'Deck não encontrado ou sem permissão.']));
    if (OPENAI_API_KEY === '') die(json_encode(['status' => 'error', 'message' => 'OPENAI_API_KEY não configurada no .env.']));

    $deck_name = Security::decryptData($deck['name_encrypted']);
    $topic_input = trim((string)($input['topic'] ?? ''));
    if ($topic_input !== '') {
        $deck_name = function_exists('mb_substr') ? mb_substr($topic_input, 0, 200) : substr($topic_input, 0, 200);
    }
    $deck_structure = normalizeDeckStructure($deck['deck_structure'] ?? 'traducoes', 'traducoes');
    $custom_base_prompt = normalizeGenerationBasePromptInput($input['base_prompt'] ?? '');

    $historyText = $deck_structure === 'parafrases' ? '' : fetchDeckHistoryText($pdo, $deck_id);
    $payloadBase = buildFlashcardsGenerationPayload($deck_name, $deck_structure, $historyText, 'gpt-5.4', $custom_base_prompt);

    $cards = [];
    $openai_debug_response = null;
    $lastErrorMessage = '';

    for ($attempt = 1; $attempt <= 3; $attempt++) {
        $payloadData = $payloadBase;
        if ($attempt > 1) {
            $payloadData['messages'][] = [
                'role' => 'user',
                'content' => 'Sua resposta anterior não seguiu o formato esperado. Regere e valide internamente antes de responder. Nunca deixe campos vazios que sejam obrigatórios para esta estrutura.'
            ];
        }

        list($httpcode, $response, $curlError) = openaiJsonRequest('https://api.openai.com/v1/chat/completions', $payloadData);

        if ($httpcode !== 200 || !$response) {
            $apiError = '';
            if (!empty($response)) {
                $errorDecoded = json_decode($response, true);
                $apiError = trim((string)($errorDecoded['error']['message'] ?? ''));
            }
            $details = trim($apiError !== '' ? $apiError : $curlError);
            $lastErrorMessage = 'Erro ao gerar cards com a OpenAI.' . ($details !== '' ? (' Detalhes: ' . $details) : '');
            continue;
        }

        $decoded = json_decode($response, true);
        $openai_debug_response = $decoded;
        $raw = (string)($decoded['choices'][0]['message']['content'] ?? '');
        $cards = sanitizeGeneratedCards($raw, $deck_structure);
        if (!empty($cards)) break;
        $lastErrorMessage = 'A API retornou cards sem preenchimento obrigatório.';
    }

    if (empty($cards)) {
        $message = $lastErrorMessage !== '' ? $lastErrorMessage : 'Não foi possível gerar cards válidos no formato esperado.';
        die(json_encode(['status' => 'error', 'message' => $message]));
    }

    echo json_encode([
        'status' => 'success',
        'mode' => 'realtime',
        'cards' => $cards,
        'debug_openai_response' => $openai_debug_response
    ]);
}

elseif ($action === 'create_generated_cards') {
    $deck_id = (int)($input['deck_id'] ?? 0);
    $cards = $input['cards'] ?? [];
    $batch_job_id = (int)($input['batch_job_id'] ?? 0);

    if ($deck_id === 0 || !is_array($cards) || count($cards) === 0) die(json_encode(['status' => 'error', 'message' => 'Dados inválidos.']));
    if (!verifyDeckOwnership($pdo, $deck_id, $user_id)) die(json_encode(['status' => 'error', 'message' => 'Deck não encontrado.']));

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("INSERT INTO flashcards (directory_id, front_encrypted, back_encrypted, has_audio_front, has_audio_back) VALUES (?, ?, ?, 0, 0)");
        $count = 0;
        foreach ($cards as $card) {
            $front = trim((string)($card['front'] ?? ''));
            $back = trim((string)($card['back'] ?? ''));
            if ($front === '') continue;
            $stmt->execute([$deck_id, Security::encryptData($front), $back !== '' ? Security::encryptData($back) : null]);
            $count++;
        }

        if ($batch_job_id > 0) {
            $delStmt = $pdo->prepare("DELETE FROM flashcard_batch_jobs WHERE id = ? AND user_id = ? AND directory_id = ? AND result_cards_json IS NOT NULL");
            $delStmt->execute([$batch_job_id, $user_id, $deck_id]);
        }

        $pdo->commit();
        echo json_encode(['status' => 'success', 'message' => "$count cards criados com sucesso!"]);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['status' => 'error', 'message' => 'Erro interno ao criar cards.']);
    }
}

elseif ($action === 'add_bulk') {
    $deck_id = (int)($input['deck_id'] ?? 0);
    $cards = $input['cards'] ?? [];

    if ($deck_id === 0 || !is_array($cards) || count($cards) === 0) die(json_encode(['status' => 'error', 'message' => 'Dados inválidos.']));
    if (!verifyDeckOwnership($pdo, $deck_id, $user_id)) die(json_encode(['status' => 'error', 'message' => 'Deck não encontrado.']));

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("INSERT INTO flashcards (directory_id, front_encrypted, back_encrypted, has_audio_front, has_audio_back) VALUES (?, ?, ?, 0, 0)");
        
        $count = 0;
        foreach ($cards as $card) {
            $front = trim($card['front'] ?? '');
            $back = trim($card['back'] ?? '');
            
            if (!empty($front)) {
                $front_enc = Security::encryptData($front);
                $back_enc = !empty($back) ? Security::encryptData($back) : null;
                $stmt->execute([$deck_id, $front_enc, $back_enc]);
                $count++;
            }
        }
        
        $pdo->commit();
        echo json_encode(['status' => 'success', 'message' => "$count cards importados!"]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['status' => 'error', 'message' => 'Erro interno ao importar cards.']);
    }
}
?>
