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

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    die(json_encode(['status' => 'error', 'message' => 'Não autorizado. Faça login.']));
}

$pdo = Database::getConnection();
$user_id = $_SESSION['user_id'];
$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';

// =========================================================================
// FAIL-SAFE MIGRATION: Garante que as colunas de imagens existam sem stress
// =========================================================================
try {
    $pdo->exec("ALTER TABLE flashcards ADD COLUMN image_front_encrypted LONGTEXT DEFAULT NULL AFTER back_encrypted");
} catch (PDOException $e) {}

try {
    $pdo->exec("ALTER TABLE flashcards ADD COLUMN image_back_encrypted LONGTEXT DEFAULT NULL AFTER image_front_encrypted");
} catch (PDOException $e) {}

try {
    $pdo->exec("ALTER TABLE directories ADD COLUMN deck_front_language VARCHAR(10) NOT NULL DEFAULT 'pt-BR' AFTER deck_mode");
} catch (PDOException $e) {}

try {
    $pdo->exec("ALTER TABLE directories ADD COLUMN deck_back_language VARCHAR(10) NOT NULL DEFAULT 'en-GB' AFTER deck_front_language");
} catch (PDOException $e) {}

try {
    $pdo->exec("ALTER TABLE directories ADD COLUMN deck_structure VARCHAR(20) NOT NULL DEFAULT 'fatos' AFTER deck_back_language");
} catch (PDOException $e) {}

try {
    $pdo->exec("ALTER TABLE flashcard_book_progress ADD COLUMN completed_reads TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER current_index");
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

// Função auxiliar para verificar se o usuário é dono do deck (Segurança IDOR)
function verifyDeckOwnership($pdo, $deck_id, $user_id) {
    $stmt = $pdo->prepare("SELECT id, name_encrypted, deck_mode, deck_front_language, deck_back_language, deck_structure FROM directories WHERE id = ? AND user_id = ? AND type = 4");
    $stmt->execute([$deck_id, $user_id]);
    return $stmt->fetch();
}

// Função auxiliar para verificar a propriedade de um card unitário
function verifyCardOwnership($pdo, $card_id, $user_id) {
    $stmt = $pdo->prepare("SELECT f.id, f.directory_id FROM flashcards f JOIN directories d ON f.directory_id = d.id WHERE f.id = ? AND d.user_id = ?");
    $stmt->execute([$card_id, $user_id]);
    return $stmt->fetch();
}

/**
 * Função para ajustar a pronúncia do TTS (Text-to-Speech).
 * Substitui siglas e palavras estrangeiras pela sua fonética correspondente em português.
 * Pilar: Fácil Manutenção (Basta adicionar novas siglas no array $replacements).
 */
function normalizeDeckLanguage($value, $default = 'pt-BR') {
    $allowed = ['pt-BR', 'en-US', 'en-GB'];
    return in_array($value, $allowed, true) ? $value : $default;
}

function normalizeDeckStructure($value, $default = 'fatos') {
    $allowed = ['fatos', 'perguntas', 'traducoes'];
    return in_array($value, $allowed, true) ? $value : $default;
}

function getFishReferenceIdByLanguage($language) {
    switch ($language) {
        case 'pt-BR': return FISH_REFERENCE_ID_PT_BR;
        case 'en-US': return FISH_REFERENCE_ID_EN_US;
        case 'en-GB': return FISH_REFERENCE_ID_EN_GB;
        default: return FISH_REFERENCE_ID_BACK;
    }
}

function getLanguageLabel($language) {
    $map = [
        'pt-BR' => 'Português Brasileiro',
        'en-US' => 'Inglês Americano',
        'en-GB' => 'Inglês Britânico'
    ];
    return $map[$language] ?? $language;
}

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


if ($action === 'fetch') {
    $deck_id = (int)($input['deck_id'] ?? 0);
    if ($deck_id === 0) die(json_encode(['status' => 'error', 'message' => 'ID do deck inválido.']));

    $deck = verifyDeckOwnership($pdo, $deck_id, $user_id);
    if (!$deck) {
        die(json_encode(['status' => 'error', 'message' => 'Deck não encontrado ou sem permissão.']));
    }

    $deck_mode = $deck['deck_mode'] ?? 'aleatorio';
    $current_index = 0;
    $book_completed_reads = 0;

    if ($deck_mode === 'aleatorio') {
        $stmt = $pdo->prepare("
            SELECT f.id, f.front_encrypted, f.back_encrypted, f.image_front_encrypted, f.image_back_encrypted, f.has_audio_front, f.has_audio_back, COALESCE(fs.score, 0) as score 
            FROM flashcards f
            LEFT JOIN flashcard_scores fs ON fs.flashcard_id = f.id AND fs.user_id = ?
            WHERE f.directory_id = ? AND (fs.next_review_at IS NULL OR fs.next_review_at <= NOW())
            ORDER BY RAND()
        ");
        $stmt->execute([$user_id, $deck_id]);
    } else {
        $stmt = $pdo->prepare("
            SELECT f.id, f.front_encrypted, f.back_encrypted, f.image_front_encrypted, f.image_back_encrypted, f.has_audio_front, f.has_audio_back, 0 as score 
            FROM flashcards f
            WHERE f.directory_id = ? 
            ORDER BY f.sort_order ASC, f.id ASC
        ");
        $stmt->execute([$deck_id]);

        $stmtProg = $pdo->prepare("SELECT current_index, completed_reads FROM flashcard_book_progress WHERE user_id = ? AND directory_id = ?");
        $stmtProg->execute([$user_id, $deck_id]);
        $progressData = $stmtProg->fetch();
        if ($progressData) {
            $current_index = (int)($progressData['current_index'] ?? 0);
            $book_completed_reads = min(3, (int)($progressData['completed_reads'] ?? 0));
        }
    }
    
    $cards = $stmt->fetchAll();

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
            'score' => (int)$card['score']
        ];
    }

    echo json_encode([
        'status' => 'success', 
        'deck_name' => Security::decryptData($deck['name_encrypted']),
        'deck_mode' => $deck_mode,
        'deck_front_language' => normalizeDeckLanguage($deck['deck_front_language'] ?? 'pt-BR', 'pt-BR'),
        'deck_back_language' => normalizeDeckLanguage($deck['deck_back_language'] ?? 'en-GB', 'en-GB'),
        'deck_structure' => normalizeDeckStructure($deck['deck_structure'] ?? 'fatos', 'fatos'),
        'deck_percentage' => $deck_percentage,
        'book_completed_reads' => $book_completed_reads,
        'book_completed_reads_max' => 3,
        'total_cards' => $total_cards_in_deck,
        'current_index' => $current_index,
        'data' => $response
    ]);
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

    $stmt = $pdo->prepare("SELECT f.front_encrypted, f.back_encrypted, d.user_id, d.deck_front_language, d.deck_back_language FROM flashcards f JOIN directories d ON f.directory_id = d.id WHERE f.id = ?");
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

    $front_language = normalizeDeckLanguage($card['deck_front_language'] ?? 'pt-BR', 'pt-BR');
    $back_language = normalizeDeckLanguage($card['deck_back_language'] ?? 'en-GB', 'en-GB');
    $side_language = $side === 'front' ? $front_language : $back_language;

    // Ajuste de pronúncia atualmente otimizado para PT-BR
    $text_to_speech = adjustPronunciationForTTS($pdo, $clean_text, $side_language);
    $reference_id = getFishReferenceIdByLanguage($side_language);

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
        "model: s1"
    ]);

    $response = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpcode !== 200 || !$response) {
        die(json_encode(['status' => 'error', 'message' => 'Erro ao comunicar com a API de voz. O serviço pode estar indisponível.']));
    }

    $audio_dir = __DIR__ . '/../assets/audio/';
    if (!is_dir($audio_dir)) {
        mkdir($audio_dir, 0755, true);
    }

    $file_path = $audio_dir . 'card_' . $card_id . '_' . $side . '.mp3';
    file_put_contents($file_path, $response);

    $col = $side === 'front' ? 'has_audio_front' : 'has_audio_back';
    $stmt = $pdo->prepare("UPDATE flashcards SET $col = 1 WHERE id = ?");
    $stmt->execute([$card_id]);

    echo json_encode(['status' => 'success', 'message' => 'Áudio gerado e salvo com sucesso!']);
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
        'model' => 'gpt-4o-mini',
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


elseif ($action === 'update_score') {
    $card_id = (int)($input['card_id'] ?? 0);
    if ($card_id === 0) die(json_encode(['status' => 'error', 'message' => 'ID do card inválido.']));

    if (!verifyCardOwnership($pdo, $card_id, $user_id)) {
        die(json_encode(['status' => 'error', 'message' => 'Acesso negado.']));
    }

    $stmt = $pdo->prepare("
        INSERT INTO flashcard_scores (user_id, flashcard_id, score, next_review_at) 
        VALUES (?, ?, 1, DATE_ADD(NOW(), INTERVAL 24 HOUR)) 
        ON DUPLICATE KEY UPDATE 
            score = IF(next_review_at IS NULL OR next_review_at <= NOW(), LEAST(score + 1, 20), score), 
            last_reviewed_at = IF(next_review_at IS NULL OR next_review_at <= NOW(), CURRENT_TIMESTAMP, last_reviewed_at),
            next_review_at = IF(next_review_at IS NULL OR next_review_at <= NOW(), DATE_ADD(NOW(), INTERVAL (LEAST(score + 1, 20) * 24) HOUR), next_review_at)
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
        INSERT INTO flashcard_book_progress (user_id, directory_id, current_index, completed_reads) 
        VALUES (?, ?, 0, 1) 
        ON DUPLICATE KEY UPDATE completed_reads = LEAST(completed_reads + 1, 3)
    ");
    $stmt->execute([$user_id, $deck_id]);
    echo json_encode(['status' => 'success']);
}

elseif ($action === 'reset_book_score') {
    $deck_id = (int)($input['deck_id'] ?? 0);
    if ($deck_id === 0) die(json_encode(['status' => 'error', 'message' => 'ID do deck inválido.']));

    $deck = verifyDeckOwnership($pdo, $deck_id, $user_id);
    if (!$deck || ($deck['deck_mode'] ?? 'aleatorio') !== 'livro') {
        die(json_encode(['status' => 'error', 'message' => 'Reset disponível apenas para decks no modo livro.']));
    }

    $stmt = $pdo->prepare("
        INSERT INTO flashcard_book_progress (user_id, directory_id, current_index, completed_reads) 
        VALUES (?, ?, 0, 0) 
        ON DUPLICATE KEY UPDATE completed_reads = 0
    ");
    $stmt->execute([$user_id, $deck_id]);
    echo json_encode(['status' => 'success', 'message' => 'Pontuação do livro zerada.']);
}

elseif ($action === 'update_settings') {
    $deck_id = (int)($input['deck_id'] ?? 0);
    $mode = $input['deck_mode'] === 'livro' ? 'livro' : 'aleatorio';
    $front_language = normalizeDeckLanguage($input['deck_front_language'] ?? 'pt-BR', 'pt-BR');
    $back_language = normalizeDeckLanguage($input['deck_back_language'] ?? 'en-GB', 'en-GB');
    $deck_structure = normalizeDeckStructure($input['deck_structure'] ?? 'fatos', 'fatos');

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

    if ($deck_id === 0 || (empty($front) && empty($image_front))) {
        die(json_encode(['status' => 'error', 'message' => 'A frente do card precisa ter texto ou imagem.']));
    }
    if (!verifyDeckOwnership($pdo, $deck_id, $user_id)) {
        die(json_encode(['status' => 'error', 'message' => 'Deck não encontrado.']));
    }

    $front_enc = !empty($front) ? Security::encryptData($front) : null;
    $back_enc = !empty($back) ? Security::encryptData($back) : null;
    $img_front_enc = !empty($image_front) ? Security::encryptData($image_front) : null;
    $img_back_enc = !empty($image_back) ? Security::encryptData($image_back) : null;

    $stmt = $pdo->prepare("INSERT INTO flashcards (directory_id, front_encrypted, back_encrypted, image_front_encrypted, image_back_encrypted, has_audio_front, has_audio_back) VALUES (?, ?, ?, ?, ?, 0, 0)");
    
    if ($stmt->execute([$deck_id, $front_enc, $back_enc, $img_front_enc, $img_back_enc])) {
        echo json_encode(['status' => 'success', 'message' => 'Card adicionado.']);
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

    if ($card_id === 0 || (empty($front) && empty($image_front))) {
        die(json_encode(['status' => 'error', 'message' => 'Dados inválidos. A frente do card precisa ter texto ou imagem.']));
    }

    if (!verifyCardOwnership($pdo, $card_id, $user_id)) {
        die(json_encode(['status' => 'error', 'message' => 'Acesso negado.']));
    }

    $front_enc = !empty($front) ? Security::encryptData($front) : null;
    $back_enc = !empty($back) ? Security::encryptData($back) : null;
    $img_front_enc = !empty($image_front) ? Security::encryptData($image_front) : null;
    $img_back_enc = !empty($image_back) ? Security::encryptData($image_back) : null;

    // Zera as flags de áudio porque o texto mudou e o áudio antigo não bate mais com a descrição
    $stmt = $pdo->prepare("UPDATE flashcards SET front_encrypted = ?, back_encrypted = ?, image_front_encrypted = ?, image_back_encrypted = ?, has_audio_front = 0, has_audio_back = 0 WHERE id = ?");
    
    if ($stmt->execute([$front_enc, $back_enc, $img_front_enc, $img_back_enc, $card_id])) {
        echo json_encode(['status' => 'success', 'message' => 'Card atualizado. Áudios redefinidos.']);
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
        // Remove arquivos de áudio associados ao card para economizar espaço
        $audio_dir = __DIR__ . '/../assets/audio/';
        $file_front = $audio_dir . 'card_' . $card_id . '_front.mp3';
        $file_back = $audio_dir . 'card_' . $card_id . '_back.mp3';
        
        if (file_exists($file_front)) @unlink($file_front);
        if (file_exists($file_back)) @unlink($file_back);

        echo json_encode(['status' => 'success', 'message' => 'Card excluído com sucesso.']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Erro interno ao excluir card.']);
    }
}


elseif ($action === 'generate_cards_preview') {
    $deck_id = (int)($input['deck_id'] ?? 0);
    $allow_math_notation = (bool)($input['allow_math_notation'] ?? true);
    if ($deck_id === 0) die(json_encode(['status' => 'error', 'message' => 'ID do deck inválido.']));

    $deck = verifyDeckOwnership($pdo, $deck_id, $user_id);
    if (!$deck) die(json_encode(['status' => 'error', 'message' => 'Deck não encontrado ou sem permissão.']));

    if (OPENAI_API_KEY === '') {
        die(json_encode(['status' => 'error', 'message' => 'OPENAI_API_KEY não configurada no .env.']));
    }

    $deck_name = Security::decryptData($deck['name_encrypted']);
    $deck_structure = normalizeDeckStructure($deck['deck_structure'] ?? 'fatos', 'fatos');

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

    $basePrompt = '';
    if ($deck_structure === 'fatos') {
        $basePrompt = 'Me dê informações sobre o assunto "' . $deck_name . '", em uma tabela de uma única coluna, onde cada linha é uma informação. As informações devem ser de fácil compreensão. As informações devem ser simples e de preferência curtas. Nenhuma informação pode ser igual as informações anteriores (salvo em paráfrases, uso de sinônimos e oposição ex. frase negativa e frase afirmativa) que já fiz. A ideia é conseguir vencer o paradoxo de Mênon, conseguir saber tudo sobre esse assunto, do conhecimento zero ao avançado.';
    } elseif ($deck_structure === 'perguntas') {
        $basePrompt = 'Me dê perguntas que propiciem um conhecimento linear sobre o assunto "' . $deck_name . '", em uma tabela de duas colunas, onde cada linha é uma pergunta, a primeira coluna é a pergunta, e a segunda é a resposta. As perguntas e respostas devem ser de fácil compreensão. As perguntas devem ser simples e de preferência curtas. Nenhuma pergunta pode ser igual as informações anteriores (salvo em paráfrases, uso de sinônimos e oposição ex. frase negativa e frase afirmativa) que já fiz. A ideia é conseguir vencer o paradoxo de Mênon, conseguir saber tudo sobre esse assunto, do conhecimento zero ao avançado.';
    } else {
        $basePrompt = 'Me dê frases em inglês com o termo "' . $deck_name . '", em uma tabela de duas colunas, onde cada linha é uma frase, a primeira coluna é a frase em português brasileiro, e a segunda é a frase em inglês. As frases devem ser de fácil compreensão. Nenhuma frase pode ser igual as frases anteriores que já fiz. Faça variações em múltiplos tempos verbais, variações com todos os pronomes, variações de número e grau. Frases positivas, negativas, interrogativas, voz passiva, voz ativa, voz reflexiva, voz recíproca, com diferentes estruturas sintáticas. O objetivo é que o aluno ao estudar as frases consiga se familiarizar com esse termo em diferentes contextos. Por favor não coloque numeração nas frases para eu não precisa remover elas manualmente depois.';
    }

    $historyText = count($history_lines) > 0 ? implode("\n", $history_lines) : '(deck sem cards anteriores)';

    $mathPrompt = $allow_math_notation
        ? 'Quando o tema envolver matemática/física, preserve a notação correta e didática. Regras obrigatórias: (1) toda expressão matemática deve vir em LaTeX inline com delimitadores \\( ... \\); (2) use expoentes/subscritos em LaTeX, nunca com texto cru: x^{2}, s^{-2}, E_{k}; (3) use frações reais com \\frac{...}{...}, nunca "1/2" em texto; (4) vetores devem usar seta com \\vec{...}; (5) não simplifique fórmulas perdendo símbolos. Exemplos esperados: "\\(1\,J = 1\,kg\\cdot m^{2}\\cdot s^{-2}\\)"; "\\(\\vec{F}=m\\vec{a}\\)"; "\\(i\\hbar\\frac{\\partial \\psi}{\\partial t}=\\hat{H}\\psi\\)"; "\\(E_{k}=\\frac{1}{2}mv^{2}\\)".'
        : 'Evite notação matemática avançada e prefira linguagem textual simples.';

    $systemPrompt = 'Você gera linhas de flashcards para estudo. Retorne APENAS JSON válido no formato {"cards":[{"front":"...","back":"..."}]}. Não use markdown. Nunca deixe "front" vazio. Para estruturas perguntas e traducoes, nunca deixe "back" vazio. Para estrutura fatos, deixe back vazio. Preserve exatamente caracteres Unicode e símbolos matemáticos sem remover ou trocar. Quando houver conteúdo matemático/físico, use SEMPRE LaTeX inline com delimitadores \\( ... \\), incluindo expoentes, subscritos, vetores e frações, sem converter para texto simplificado.';
    $userPrompt = $basePrompt
        . "\n\nCARDS JÁ EXISTENTES NESTE DECK:\n" . $historyText
        . "\n\nREGRAS DE NOTAÇÃO:\n" . $mathPrompt
        . "\n\nREGRAS DE LIMPEZA DE SAÍDA:\nNunca inclua menus, botões, placeholders, atalhos de teclado, instruções de editor matemático, termos de interface (como Álgebra/Trigonometria/Cálculo/AC/placeholder) ou listas de símbolos soltas. Retorne apenas conteúdo pedagógico dos cards."
        . "\n\nGere 15 novos cards sem repetição de conteúdo com o histórico.";

    $requiresBack = in_array($deck_structure, ['perguntas', 'traducoes'], true);
    $backSchema = $requiresBack ? ['type' => 'string', 'minLength' => 1] : ['type' => 'string'];

    $payloadBase = [
        'model' => 'gpt-5-nano',
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

        $payload = json_encode($payloadData);

        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . OPENAI_API_KEY
        ]);

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpcode !== 200 || !$response) {
            $apiError = '';
            if (!empty($response)) {
                $errorDecoded = json_decode($response, true);
                $apiError = trim((string)($errorDecoded['error']['message'] ?? ''));
            }

            $details = trim($apiError !== '' ? $apiError : $curlError);
            $lastErrorMessage = 'Erro ao gerar cards com a OpenAI.';
            if ($details !== '') {
                $lastErrorMessage .= ' Detalhes: ' . $details;
            }
            continue;
        }

        $decoded = json_decode($response, true);
        $openai_debug_response = $decoded;
        $raw = trim((string)($decoded['choices'][0]['message']['content'] ?? ''));

        if ($raw !== '' && str_starts_with($raw, '```')) {
            $raw = preg_replace('/^```(?:json)?\s*/i', '', $raw);
            $raw = preg_replace('/\s*```$/', '', $raw);
            $raw = trim((string)$raw);
        }

        $json = json_decode($raw, true);

        if (!is_array($json) || !isset($json['cards']) || !is_array($json['cards'])) {
            $lastErrorMessage = 'A API retornou conteúdo em formato inválido.';
            continue;
        }

        $cards = [];
        foreach ($json['cards'] as $card) {
            $front = trim((string)($card['front'] ?? ''));
            $back = trim((string)($card['back'] ?? ''));

            if ($front === '') continue;

            if ($deck_structure === 'fatos') {
                $back = '';
            } elseif ($back === '') {
                continue;
            }

            $cards[] = ['front' => $front, 'back' => $back];
        }

        if (!empty($cards)) {
            break;
        }

        $lastErrorMessage = 'A API retornou cards sem preenchimento obrigatório.';
    }

    if (empty($cards)) {
        $message = $lastErrorMessage !== '' ? $lastErrorMessage : 'Não foi possível gerar cards válidos no formato esperado.';
        die(json_encode(['status' => 'error', 'message' => $message]));
    }

    echo json_encode([
        'status' => 'success',
        'cards' => $cards,
        'debug_openai_response' => $openai_debug_response
    ]);
}

elseif ($action === 'create_generated_cards') {
    $deck_id = (int)($input['deck_id'] ?? 0);
    $cards = $input['cards'] ?? [];

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
