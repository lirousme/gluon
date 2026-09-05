<?php

require_once BASE_PATH . '/config/database.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Não autorizado.']);
    exit;
}

$userId = (int)$_SESSION['user_id'];
$pdo = Database::getConnection();
branchChatsEnsureAudioSchema($pdo);
branchChatsEnsureReferenceAudioSchema($pdo);
branchChatsEnsureChatOpenSchema($pdo);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

function branchChatsRespond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function branchChatsEnsureAudioSchema(PDO $pdo): void
{
    $columns = [
        'audio_encrypted' => 'ALTER TABLE mensagens ADD COLUMN audio_encrypted LONGTEXT NULL AFTER imagem_encrypted',
        'has_audio' => 'ALTER TABLE mensagens ADD COLUMN has_audio TINYINT(1) NOT NULL DEFAULT 0 AFTER audio_encrypted',
        'audio_language' => 'ALTER TABLE mensagens ADD COLUMN audio_language VARCHAR(10) NULL AFTER has_audio',
        'audio_variant' => 'ALTER TABLE mensagens ADD COLUMN audio_variant VARCHAR(12) NULL AFTER audio_language',
        'color_variant' => "ALTER TABLE mensagens ADD COLUMN color_variant VARCHAR(12) NOT NULL DEFAULT 'green' AFTER is_recipient",
    ];
    foreach ($columns as $column => $sql) {
        $stmt = $pdo->query("SHOW COLUMNS FROM mensagens LIKE " . $pdo->quote($column));
        if (!$stmt->fetchColumn()) $pdo->exec($sql);
    }
}

function branchChatsEnsureReferenceAudioSchema(PDO $pdo): void
{
    $columns = [
        'reference_audio_encrypted' => 'ALTER TABLE chat_references ADD COLUMN reference_audio_encrypted LONGTEXT NULL AFTER reference_encrypted',
        'reference_audio_language' => "ALTER TABLE chat_references ADD COLUMN reference_audio_language VARCHAR(10) NOT NULL DEFAULT 'pt-BR' AFTER reference_audio_encrypted",
    ];
    foreach ($columns as $column => $sql) {
        $stmt = $pdo->query("SHOW COLUMNS FROM chat_references LIKE " . $pdo->quote($column));
        if (!$stmt->fetchColumn()) $pdo->exec($sql);
    }
}


function branchChatsEnsureChatOpenSchema(PDO $pdo): void
{
    $stmt = $pdo->query("SHOW COLUMNS FROM chats LIKE " . $pdo->quote('is_open'));
    if (!$stmt->fetchColumn()) {
        $pdo->exec('ALTER TABLE chats ADD COLUMN is_open INT NOT NULL DEFAULT 1 CHECK (is_open IN (0, 1)) AFTER read_marker_message_id');
        return;
    }

    $pdo->exec('ALTER TABLE chats MODIFY COLUMN is_open INT NOT NULL DEFAULT 1');
}

function branchChatsNormalizeReferenceLanguage($value): string
{
    return in_array($value, ['pt-BR', 'en-GB'], true) ? $value : 'pt-BR';
}

function branchChatsReferenceVariant(string $language): string
{
    return $language === 'en-GB' ? 'orange' : 'green';
}

function branchChatsNormalizeVariant($value, int $isRecipient): string
{
    $variant = trim((string)$value);
    $allowed = $isRecipient ? ['blue', 'purple'] : ['green', 'orange'];
    return in_array($variant, $allowed, true) ? $variant : ($isRecipient ? 'blue' : 'green');
}

function branchChatsAudioContext(string $variant): array
{
    return match ($variant) {
        'blue' => ['side' => 'back', 'language' => 'pt-BR'],
        'purple' => ['side' => 'back', 'language' => 'en-GB'],
        'orange' => ['side' => 'front', 'language' => 'en-GB'],
        default => ['side' => 'front', 'language' => 'pt-BR'],
    };
}

function branchChatsDecodeTtsAudioBinaryFromJsonPayload($payload): ?string
{
    if (!is_array($payload)) return null;
    foreach (['audio', 'audio_base64', 'audioContent', 'base64', 'data', 'result'] as $key) {
        if (!array_key_exists($key, $payload)) continue;
        $value = $payload[$key];
        if (is_array($value)) {
            $nested = branchChatsDecodeTtsAudioBinaryFromJsonPayload($value);
            if ($nested !== null) return $nested;
            continue;
        }
        if (!is_string($value)) continue;
        $raw = trim($value);
        if (preg_match('#^data:audio/[^;]+;base64,#i', $raw)) $raw = explode(',', $raw, 2)[1] ?? '';
        $decoded = base64_decode($raw, true);
        if ($decoded !== false && $decoded !== '') return $decoded;
    }
    return null;
}

function branchChatsBuildTtsProviderErrorDetails($provider, $httpcode, $curlError, $responseBody = null): string
{
    $parts = ['Provider ' . strtoupper((string)$provider)];
    if ($httpcode > 0) $parts[] = 'HTTP ' . (int)$httpcode;
    if (is_string($curlError) && trim($curlError) !== '') $parts[] = 'cURL: ' . trim($curlError);
    if (is_string($responseBody) && trim($responseBody) !== '') {
        $decoded = json_decode($responseBody, true);
        $message = is_array($decoded) ? ($decoded['error']['message'] ?? $decoded['message'] ?? null) : null;
        if (is_string($message) && trim($message) !== '') $parts[] = 'API: ' . trim($message);
    }
    return implode(' | ', $parts);
}

function branchChatsGetUserTtsProvider(PDO $pdo, int $userId): string
{
    $stmt = $pdo->prepare('SELECT tts_provider FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $provider = (string)$stmt->fetchColumn();
    return in_array($provider, ['fishaudio', 'openai', 'google'], true) ? $provider : 'fishaudio';
}

function branchChatsFishReferenceIdByVariant(string $variant): string
{
    return match ($variant) {
        'blue'   => FISH_REFERENCE_ID_BLUE,
        'purple' => FISH_REFERENCE_ID_PURPLE,
        'orange' => FISH_REFERENCE_ID_ORANGE,
        'green'  => FISH_REFERENCE_ID_GREEN,
        default  => FISH_REFERENCE_ID_GREEN,
    };
}

function branchChatsGoogleVoice(string $variant): string
{
    return match ($variant) {
        'blue'   => 'pt-BR-Chirp3-HD-Rasalgethi', 
        'purple' => 'en-GB-Chirp3-HD-Rasalgethi',
        'orange' => 'en-GB-Chirp3-HD-Puck', 
        'green'  => 'pt-BR-Chirp3-HD-Puck',
        default  => 'pt-BR-Chirp3-HD-Algieba',
    };
}

function branchChatsAdjustPronunciationForTts(PDO $pdo, string $text, string $language): string
{
    if (!in_array($language, ['pt-BR', 'en-US', 'en-GB', 'es-ES', 'fr-FR', 'cmn-CN'], true)) return $text;
    $stmt = $pdo->prepare('SELECT source_text, target_text FROM pronuncias WHERE language = ? ORDER BY CHAR_LENGTH(source_text) DESC');
    $stmt->execute([$language]);
    foreach ($stmt->fetchAll() as $item) {
        $source = trim((string)$item['source_text']);
        if ($source !== '') $text = preg_replace('/\b' . preg_quote($source, '/') . '\b/iu', (string)$item['target_text'], $text);
    }
    return $text;
}

function branchChatsRequestTts(PDO $pdo, int $userId, string $text, string $variant, string $language, ?string &$errorDetails): ?string
{
    $text = branchChatsAdjustPronunciationForTts($pdo, $text, $language);
    $provider = branchChatsGetUserTtsProvider($pdo, $userId);
    
    if ($provider === 'openai') {
        if (trim((string)OPENAI_API_KEY) === '') { $errorDetails = 'Chave OPENAI_API_KEY não configurada.'; return null; }
        $ch = curl_init('https://api.openai.com/v1/audio/speech');
        $payload = json_encode(['model' => 'gpt-4o-audio-preview', 'voice' => OPENAI_TTS_VOICE_DEFAULT, 'input' => $text, 'format' => 'mp3']);
        $headers = ['Authorization: Bearer ' . OPENAI_API_KEY, 'Content-Type: application/json'];
    } elseif ($provider === 'google') {
        if (trim((string)GOOGLE_CLOUD_API_KEY) === '') { $errorDetails = 'Chave GOOGLE_CLOUD_API_KEY não configurada.'; return null; }
        $ch = curl_init('https://texttospeech.googleapis.com/v1/text:synthesize?key=' . rawurlencode(GOOGLE_CLOUD_API_KEY));
        
        $audioConfig = ['audioEncoding' => 'MP3'];
        if ($language === 'en-GB') {
            $audioConfig['volumeGainDb'] = 6.0; 
        }
        
        $payload = json_encode(['input' => ['text' => $text], 'voice' => ['languageCode' => $language, 'name' => branchChatsGoogleVoice($variant)], 'audioConfig' => $audioConfig]);
        $headers = ['Content-Type: application/json'];
    } else {
        if (trim((string)FISH_API_KEY) === '') { $errorDetails = 'Chave FISH_API_KEY não configurada.'; return null; }
        $ch = curl_init('https://api.fish.audio/v1/tts');
        $payload = json_encode(['text' => $text, 'reference_id' => branchChatsFishReferenceIdByVariant($variant), 'format' => 'mp3']);
        $headers = ['Authorization: Bearer ' . FISH_API_KEY, 'Content-Type: application/json', 'model: s2'];
    }
    
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, $payload); curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $response = curl_exec($ch); $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE); $curlError = curl_error($ch); curl_close($ch);
    
    if ($httpcode !== 200 || !$response) { $errorDetails = branchChatsBuildTtsProviderErrorDetails($provider, (int)$httpcode, $curlError, is_string($response) ? $response : null); return null; }
    
    $decoded = json_decode($response, true);
    $audio = json_last_error() === JSON_ERROR_NONE ? branchChatsDecodeTtsAudioBinaryFromJsonPayload($decoded) : null;
    $errorDetails = null;
    return $audio ?? (is_string($response) ? $response : null);
}

function branchChatsGenerateAndPersistMessageAudio(PDO $pdo, int $messageId, int $userId, ?string &$errorDetails, bool $skipExisting = true): bool
{
    $stmt = $pdo->prepare('SELECT id, texto_encrypted, is_recipient, color_variant, audio_encrypted FROM mensagens WHERE id = ? AND user_id = ? LIMIT 1');
    $stmt->execute([$messageId, $userId]);
    $message = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$message) { $errorDetails = 'Mensagem não encontrada.'; return false; }
    
    if ($skipExisting && branchChatsMessageAudioDataUri($message['audio_encrypted'] ?? null) !== null) {
        $errorDetails = null;
        return true;
    }
    
    $text = trim(strip_tags((string)branchChatsDecryptMessageText($message['texto_encrypted'] ?? null)));
    if ($text === '') { $errorDetails = 'A mensagem não possui texto para gerar áudio.'; return false; }
    
    $variant = branchChatsNormalizeVariant($message['color_variant'] ?? null, (int)$message['is_recipient']);
    $context = branchChatsAudioContext($variant);
    
    $audio = branchChatsRequestTts($pdo, $userId, $text, $variant, $context['language'], $errorDetails);
    
    if (!is_string($audio) || $audio === '') return false;
    
    $pdo->prepare('UPDATE mensagens SET audio_encrypted = ?, has_audio = 1, audio_language = ?, audio_variant = ? WHERE id = ? AND user_id = ?')
        ->execute([Security::encryptData(base64_encode($audio)), $context['language'], $variant, $messageId, $userId]);
        
    return true;
}

function branchChatsGenerateAndPersistReferenceAudio(PDO $pdo, int $chatId, int $userId, ?string &$errorDetails, bool $skipExisting = true): bool
{
    $chat = branchChatsFind($pdo, $chatId, $userId);
    if (!in_array((int)$chat['chat_type'], [2, 3], true) || trim((string)($chat['reference'] ?? '')) === '') {
        $errorDetails = 'O chat não possui texto de referência para gerar áudio.';
        return false;
    }
    if ($skipExisting && !empty($chat['reference_audio_base64'])) {
        $errorDetails = null;
        return true;
    }

    $referenceOwnerId = (int)$chat['chat_type'] === 3 && !empty($chat['parent_chat_id']) ? (int)$chat['parent_chat_id'] : $chatId;
    $language = branchChatsNormalizeReferenceLanguage($chat['reference_audio_language'] ?? null);
    $audio = branchChatsRequestTts($pdo, $userId, trim((string)$chat['reference']), branchChatsReferenceVariant($language), $language, $errorDetails);
    if (!is_string($audio) || $audio === '') return false;

    $pdo->prepare('UPDATE chat_references SET reference_audio_encrypted = :audio, reference_audio_language = :language WHERE chat_id = :chat_id')
        ->execute([':audio' => Security::encryptData(base64_encode($audio)), ':language' => $language, ':chat_id' => $referenceOwnerId]);
    return true;
}

function branchChatsMessageAudioDataUri(?string $audioEncrypted): ?string
{
    if (!$audioEncrypted) return null;
    $audio = Security::decryptData($audioEncrypted);
    return is_string($audio) && $audio !== '' ? 'data:audio/mpeg;base64,' . $audio : null;
}

// Removida a conversão de timezone da next view calculation; usa diretamente o UTC já gravado
function branchChatsNextViewExpression(string $lastViewedColumn = 'cv.last_viewed_at', string $viewCountColumn = 'cv.view_count'): string
{
    return "DATE_ADD($lastViewedColumn, INTERVAL $viewCountColumn DAY)";
}

function branchChatsCanReviewEarly(PDO $pdo, int $chatId, int $userId): bool
{
    $stmt = $pdo->prepare(
        'SELECT c.id
         FROM chats c
         INNER JOIN chat_views cv ON cv.chat_id = c.id AND cv.user_id = :view_user_id
         WHERE c.user_id = :user_id
           AND cv.last_viewed_at IS NOT NULL
           AND CURRENT_TIMESTAMP < ' . branchChatsNextViewExpression('cv.last_viewed_at', 'cv.view_count') . '
           AND NOT EXISTS (
               SELECT 1
               FROM chats due_chat
               LEFT JOIN chat_views due_view ON due_view.chat_id = due_chat.id AND due_view.user_id = :due_view_user_id
               WHERE due_chat.user_id = :due_user_id
                 AND (due_view.last_viewed_at IS NULL OR CURRENT_TIMESTAMP >= ' . branchChatsNextViewExpression('due_view.last_viewed_at', 'due_view.view_count') . ')
           )
         ORDER BY ' . branchChatsNextViewExpression('cv.last_viewed_at', 'cv.view_count') . ' ASC, c.id ASC
         LIMIT 1'
    );
    $stmt->execute([
        ':view_user_id' => $userId,
        ':user_id' => $userId,
        ':due_view_user_id' => $userId,
        ':due_user_id' => $userId,
    ]);

    return (int)$stmt->fetchColumn() === $chatId;
}

function branchChatsFind(PDO $pdo, int $chatId, int $userId): array
{
    $stmt = $pdo->prepare(
        'SELECT c.id, c.parent_chat_id, c.titulo, c.chat_type, c.`max`, c.read_marker_message_id, c.is_open, c.created_at, c.updated_at,
                COALESCE(cr.reference_encrypted, parent_cr.reference_encrypted) AS reference_encrypted,
                COALESCE(cr.reference_audio_encrypted, parent_cr.reference_audio_encrypted) AS reference_audio_encrypted,
                COALESCE(cr.reference_audio_language, parent_cr.reference_audio_language, \'pt-BR\') AS reference_audio_language,
                COALESCE(cv.view_count, 0) AS view_count, cv.last_viewed_at,
                CASE WHEN cv.last_viewed_at IS NULL THEN NULL
                     ELSE ' . branchChatsNextViewExpression('cv.last_viewed_at', 'cv.view_count') . ' END AS next_view_at,
                CASE WHEN cv.last_viewed_at IS NULL OR CURRENT_TIMESTAMP >= ' . branchChatsNextViewExpression('cv.last_viewed_at', 'cv.view_count') . '
                     THEN 1 ELSE 0 END AS can_mark_viewed,
                (SELECT COUNT(*) FROM chats child WHERE child.parent_chat_id = c.id AND child.user_id = c.user_id) AS total_branches
         FROM chats c
         LEFT JOIN chat_views cv ON cv.chat_id = c.id AND cv.user_id = :view_user_id
         LEFT JOIN chat_references cr ON cr.chat_id = c.id
         LEFT JOIN chats parent_chat ON parent_chat.id = c.parent_chat_id AND parent_chat.user_id = c.user_id
         LEFT JOIN chat_references parent_cr ON parent_cr.chat_id = parent_chat.id
         WHERE c.id = :id AND c.user_id = :user_id LIMIT 1'
    );
    $stmt->execute([':id' => $chatId, ':user_id' => $userId, ':view_user_id' => $userId]);
    $chat = $stmt->fetch();
    if (!$chat) {
        branchChatsRespond(['status' => 'error', 'message' => 'Chat não encontrado.'], 404);
    }
    $chat['reference'] = branchChatsDecryptMessageText($chat['reference_encrypted'] ?? null);
    $chat['reference_audio_base64'] = branchChatsMessageAudioDataUri($chat['reference_audio_encrypted'] ?? null);
    $chat['reference_audio_language'] = branchChatsNormalizeReferenceLanguage($chat['reference_audio_language'] ?? null);
    unset($chat['reference_encrypted'], $chat['reference_audio_encrypted']);
    $chat['early_review'] = false;
    if (!(bool)$chat['can_mark_viewed'] && branchChatsCanReviewEarly($pdo, $chatId, $userId)) {
        $chat['can_mark_viewed'] = 1;
        $chat['early_review'] = true;
    }
    return $chat;
}

function branchChatsTimezoneOffset(array $input): int
{
    return max(-840, min(840, (int)($input['timezone_offset_minutes'] ?? 0)));
}

function branchChatsLocalDate(int $timezoneOffsetMinutes): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('UTC')))
        ->modify(($timezoneOffsetMinutes >= 0 ? '+' : '') . $timezoneOffsetMinutes . ' minutes')
        ->format('Y-m-d');
}

function branchChatsReadCycleStatus(PDO $pdo, int $userId, int $timezoneOffsetMinutes): array
{
    $localDate = branchChatsLocalDate($timezoneOffsetMinutes);
    $stmt = $pdo->prepare('SELECT read_count FROM branch_chat_daily_reads WHERE user_id = :user_id AND local_date = :local_date');
    $stmt->execute([':user_id' => $userId, ':local_date' => $localDate]);

    return [
        'read_cycle_count' => (int)($stmt->fetchColumn() ?: 0),
        'read_cycle_limit' => 999, // Limite ajustado para 999 no backend
        'read_cycle_date' => $localDate,
    ];
}

function branchChatsIncrementReadCycle(PDO $pdo, int $userId, int $timezoneOffsetMinutes): array
{
    $localDate = branchChatsLocalDate($timezoneOffsetMinutes);
    $stmt = $pdo->prepare(
        'INSERT INTO branch_chat_daily_reads (user_id, local_date, read_count, updated_at)
         VALUES (:user_id, :local_date, 1, CURRENT_TIMESTAMP)
         ON DUPLICATE KEY UPDATE read_count = LEAST(read_count + 1, 999), updated_at = CURRENT_TIMESTAMP'
    );
    $stmt->execute([':user_id' => $userId, ':local_date' => $localDate]);

    return branchChatsReadCycleStatus($pdo, $userId, $timezoneOffsetMinutes);
}

function branchChatsDailyReadLimitReached(PDO $pdo, int $userId, int $timezoneOffsetMinutes): bool
{
    return branchChatsReadCycleStatus($pdo, $userId, $timezoneOffsetMinutes)['read_cycle_count'] >= 999;
}

function branchChatsDefaultTitle(int $timezoneOffsetMinutes): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('UTC')))
        ->modify(($timezoneOffsetMinutes >= 0 ? '+' : '') . $timezoneOffsetMinutes . ' minutes')
        ->format('d/m/Y H:i');
}

function branchChatsStoreImage(array $image): string
{
    if (($image['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || ($image['size'] ?? 0) > 8 * 1024 * 1024) {
        branchChatsRespond(['status' => 'error', 'message' => 'Não foi possível enviar a imagem (limite de 8 MB).'], 422);
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($image['tmp_name']);
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
        branchChatsRespond(['status' => 'error', 'message' => 'Formato inválido. Use JPG, PNG, GIF ou WebP.'], 422);
    }
    $contents = file_get_contents($image['tmp_name']);
    if ($contents === false) {
        throw new RuntimeException('Não foi possível ler a imagem.');
    }
    return Security::encryptData('data:' . $mime . ';base64,' . base64_encode($contents));
}

function branchChatsDecryptMessageText(?string $encryptedText): ?string
{
    if ($encryptedText === null) {
        return null;
    }

    $decrypted = Security::decryptData($encryptedText);
    return $decrypted !== false ? $decrypted : $encryptedText;
}

function branchChatsTextLength(?string $text): int
{
    if ($text === null) {
        return 0;
    }

    return function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
}

function branchChatsAttachMessageCharacterTotals(PDO $pdo, array &$chats, int $userId): void
{
    if (!$chats) {
        return;
    }

    $chatIds = array_values(array_unique(array_map('intval', array_column($chats, 'id'))));
    if (!$chatIds) {
        return;
    }

    $totals = array_fill_keys($chatIds, 0);
    $placeholders = implode(',', array_fill(0, count($chatIds), '?'));
    $stmt = $pdo->prepare(
        'SELECT cm.chat_id, m.texto_encrypted
         FROM chat_mensagens cm
         INNER JOIN mensagens m ON m.id = cm.mensagem_id
         WHERE cm.chat_id IN (' . $placeholders . ') AND m.user_id = ?'
    );
    $stmt->execute([...$chatIds, $userId]);

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $message) {
        $chatId = (int)$message['chat_id'];
        $totals[$chatId] = ($totals[$chatId] ?? 0) + branchChatsTextLength(branchChatsDecryptMessageText($message['texto_encrypted'] ?? null));
    }

    foreach ($chats as &$chat) {
        $chat['total_message_characters'] = $totals[(int)$chat['id']] ?? 0;
    }
    unset($chat);
}

function branchChatsDecryptMessageImage(PDO $pdo, array $message): ?string
{
    $storedImage = $message['imagem_encrypted'] ?? null;
    if ($storedImage === null || $storedImage === '') {
        return null;
    }

    $decrypted = Security::decryptData($storedImage);
    if ($decrypted !== false) {
        return $decrypted;
    }

    $dataUri = null;
    $legacyFile = null;
    if (str_starts_with($storedImage, 'data:image/')) {
        $dataUri = $storedImage;
    } elseif (str_starts_with($storedImage, '/uploads/branch_chats/')) {
        $legacyFile = BASE_PATH . $storedImage;
        if (is_file($legacyFile)) {
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($legacyFile);
            if (is_string($mime) && str_starts_with($mime, 'image/')) {
                $contents = file_get_contents($legacyFile);
                if ($contents !== false) {
                    $dataUri = 'data:' . $mime . ';base64,' . base64_encode($contents);
                }
            }
        }
    }

    if ($dataUri !== null) {
        $update = $pdo->prepare(
            'UPDATE mensagens SET imagem_encrypted = :encrypted WHERE id = :id AND imagem_encrypted = :legacy'
        );
        $update->execute([
            ':encrypted' => Security::encryptData($dataUri),
            ':id' => $message['id'],
            ':legacy' => $storedImage,
        ]);
        if ($update->rowCount() === 1 && $legacyFile !== null) {
            @unlink($legacyFile);
        }
    }

    return $dataUri;
}

function branchChatsDeleteSingleChat(PDO $pdo, int $chatId, int $userId): void
{
    $stmt = $pdo->prepare(
        'SELECT DISTINCT cm.mensagem_id
         FROM chat_mensagens cm
         INNER JOIN mensagens m ON m.id = cm.mensagem_id
         WHERE cm.chat_id = :chat_id AND m.user_id = :user_id'
    );
    $stmt->execute([':chat_id' => $chatId, ':user_id' => $userId]);
    $messageIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

    $pdo->prepare('DELETE FROM chats WHERE id = :id AND user_id = :user_id')->execute([':id' => $chatId, ':user_id' => $userId]);

    if ($messageIds === []) {
        return;
    }

    $messagePlaceholders = implode(',', array_fill(0, count($messageIds), '?'));
    $stmt = $pdo->prepare(
        "DELETE m
         FROM mensagens m
         LEFT JOIN chat_mensagens cm ON cm.mensagem_id = m.id
         WHERE m.user_id = ? AND m.id IN ($messagePlaceholders) AND cm.mensagem_id IS NULL"
    );
    $stmt->execute(array_merge([$userId], $messageIds));
}

function branchChatsDeleteChat(PDO $pdo, int $chatId, int $userId): void
{
    $chat = branchChatsFind($pdo, $chatId, $userId);
    $stmt = $pdo->prepare(
        'WITH RECURSIVE chat_tree AS (
             SELECT id
             FROM chats
             WHERE id = :chat_id AND user_id = :user_id
             UNION ALL
             SELECT child.id
             FROM chats child
             INNER JOIN chat_tree parent ON parent.id = child.parent_chat_id
             WHERE child.user_id = :child_user_id
         )
         SELECT id FROM chat_tree'
    );
    $stmt->execute([':chat_id' => $chatId, ':user_id' => $userId, ':child_user_id' => $userId]);
    $chatIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    if ($chatIds === []) {
        return;
    }

    $descendantIds = array_values(array_filter($chatIds, static fn (int $id): bool => $id !== $chatId));
    if ($descendantIds !== []) {
        $descendantPlaceholders = implode(',', array_fill(0, count($descendantIds), '?'));
        $stmt = $pdo->prepare(
            "SELECT id
             FROM chats
             WHERE user_id = ? AND chat_type = 3 AND id IN ($descendantPlaceholders)
             ORDER BY id ASC"
        );
        $stmt->execute(array_merge([$userId], $descendantIds));
        $referenceChildIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

        if ($referenceChildIds !== []) {
            $promotedChatId = $referenceChildIds[0];
            $reparentIds = array_values(array_filter($descendantIds, static fn (int $id): bool => $id !== $promotedChatId));

            $pdo->prepare('DELETE FROM chat_references WHERE chat_id = :chat_id')->execute([':chat_id' => $promotedChatId]);
            $pdo->prepare('UPDATE chat_references SET chat_id = :new_chat_id WHERE chat_id = :old_chat_id')->execute([
                ':new_chat_id' => $promotedChatId,
                ':old_chat_id' => $chatId,
            ]);
            $pdo->prepare('UPDATE chats SET parent_chat_id = :new_parent_id WHERE id = :promoted_chat_id AND user_id = :user_id')->execute([
                ':new_parent_id' => $chat['parent_chat_id'],
                ':promoted_chat_id' => $promotedChatId,
                ':user_id' => $userId,
            ]);
            if ($reparentIds !== []) {
                $reparentPlaceholders = implode(',', array_fill(0, count($reparentIds), '?'));
                $stmt = $pdo->prepare("UPDATE chats SET parent_chat_id = ? WHERE user_id = ? AND id IN ($reparentPlaceholders)");
                $stmt->execute(array_merge([$promotedChatId, $userId], $reparentIds));
            }

            branchChatsDeleteSingleChat($pdo, $chatId, $userId);
            return;
        }
    }

    $chatPlaceholders = implode(',', array_fill(0, count($chatIds), '?'));
    $stmt = $pdo->prepare(
        "SELECT DISTINCT cm.mensagem_id
         FROM chat_mensagens cm
         INNER JOIN mensagens m ON m.id = cm.mensagem_id
         WHERE cm.chat_id IN ($chatPlaceholders) AND m.user_id = ?"
    );
    $stmt->execute(array_merge($chatIds, [$userId]));
    $messageIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

    $pdo->prepare('DELETE FROM chats WHERE id = :id AND user_id = :user_id')->execute([':id' => $chatId, ':user_id' => $userId]);

    if ($messageIds === []) {
        return;
    }

    $messagePlaceholders = implode(',', array_fill(0, count($messageIds), '?'));
    $stmt = $pdo->prepare(
        "DELETE m
         FROM mensagens m
         LEFT JOIN chat_mensagens cm ON cm.mensagem_id = m.id
         WHERE m.user_id = ? AND m.id IN ($messagePlaceholders) AND cm.mensagem_id IS NULL"
    );
    $stmt->execute(array_merge([$userId], $messageIds));
}

function branchChatsDecryptMessages(PDO $pdo, array $messages): array
{
    $update = null;
    foreach ($messages as &$message) {
        $storedText = $message['texto_encrypted'] ?? null;
        $message['texto'] = branchChatsDecryptMessageText($storedText);
        $message['imagem_base64'] = branchChatsDecryptMessageImage($pdo, $message);
        $message['audio_base64'] = branchChatsMessageAudioDataUri($message['audio_encrypted'] ?? null);
        $message['color_variant'] = branchChatsNormalizeVariant($message['color_variant'] ?? null, (int)($message['is_recipient'] ?? 0));
        unset($message['texto_encrypted']);
        unset($message['imagem_encrypted']);
        unset($message['audio_encrypted']);

        if ($storedText !== null && Security::decryptData($storedText) === false) {
            $update ??= $pdo->prepare(
                'UPDATE mensagens SET texto_encrypted = :texto_encrypted WHERE id = :id AND texto_encrypted = :texto_plain'
            );
            $update->execute([
                ':texto_encrypted' => Security::encryptData($storedText),
                ':id' => $message['id'],
                ':texto_plain' => $storedText,
            ]);
        }
    }
    unset($message);

    return $messages;
}

try {
    if ($method === 'GET') {
        $timezoneOffsetMinutes = branchChatsTimezoneOffset($_GET);
        $chatId = isset($_GET['chat_id']) ? (int)$_GET['chat_id'] : 0;
        if ($chatId > 0) {
            $chat = branchChatsFind($pdo, $chatId, $userId); // timezoneOffsetMinutes removido do Find
            $stmt = $pdo->prepare(
                'SELECT m.id, m.texto_encrypted, m.imagem_encrypted, m.audio_encrypted, m.has_audio, m.audio_language, m.audio_variant, m.color_variant, m.is_recipient, m.created_at, cm.is_response
                 FROM chat_mensagens cm
                 INNER JOIN mensagens m ON m.id = cm.mensagem_id
                 WHERE cm.chat_id = :chat_id AND m.user_id = :user_id
                 ORDER BY cm.position ASC'
            );
            $stmt->execute([':chat_id' => $chatId, ':user_id' => $userId]);
            $messages = branchChatsDecryptMessages($pdo, $stmt->fetchAll());
            branchChatsRespond(['status' => 'success', 'data' => ['chat' => $chat, 'mensagens' => $messages]]);
        }

        $stmt = $pdo->prepare(
            'SELECT c.id, c.parent_chat_id, c.titulo, c.chat_type, c.is_open, c.created_at, c.updated_at,
                    COALESCE(cv.view_count, 0) AS view_count,
                    1 AS can_mark_viewed,
                    (SELECT m.texto_encrypted FROM chat_mensagens cm INNER JOIN mensagens m ON m.id = cm.mensagem_id WHERE cm.chat_id = c.id ORDER BY cm.position DESC LIMIT 1) AS ultima_mensagem_encrypted,
                    (SELECT COUNT(*) FROM chat_mensagens cm WHERE cm.chat_id = c.id) AS total_mensagens,
                    (SELECT COUNT(*) FROM chats child WHERE child.parent_chat_id = c.id AND child.user_id = c.user_id) AS total_branches
             FROM chats c
             LEFT JOIN chat_views cv ON cv.chat_id = c.id AND cv.user_id = :view_user_id
             WHERE c.user_id = :user_id
               AND (cv.last_viewed_at IS NULL OR CURRENT_TIMESTAMP >= ' . branchChatsNextViewExpression('cv.last_viewed_at', 'cv.view_count') . ')
             ORDER BY c.updated_at DESC, c.id DESC'
        );
        $stmt->execute([':user_id' => $userId, ':view_user_id' => $userId]);
        $chats = $stmt->fetchAll();
        if (!$chats) {
            $stmt = $pdo->prepare(
                'SELECT c.id, c.parent_chat_id, c.titulo, c.chat_type, c.is_open, c.created_at, c.updated_at,
                        COALESCE(cv.view_count, 0) AS view_count,
                        0 AS can_mark_viewed,
                        (SELECT m.texto_encrypted FROM chat_mensagens cm INNER JOIN mensagens m ON m.id = cm.mensagem_id WHERE cm.chat_id = c.id ORDER BY cm.position DESC LIMIT 1) AS ultima_mensagem_encrypted,
                        (SELECT COUNT(*) FROM chat_mensagens cm WHERE cm.chat_id = c.id) AS total_mensagens,
                        (SELECT COUNT(*) FROM chats child WHERE child.parent_chat_id = c.id AND child.user_id = c.user_id) AS total_branches
                 FROM chats c
                 INNER JOIN chat_views cv ON cv.chat_id = c.id AND cv.user_id = :view_user_id
                 WHERE c.user_id = :user_id
                   AND cv.last_viewed_at IS NOT NULL
                   AND CURRENT_TIMESTAMP < ' . branchChatsNextViewExpression('cv.last_viewed_at', 'cv.view_count') . '
                 ORDER BY ' . branchChatsNextViewExpression('cv.last_viewed_at', 'cv.view_count') . ' ASC, c.id ASC
                 LIMIT 1'
            );
            $stmt->execute([':user_id' => $userId, ':view_user_id' => $userId]);
            $chats = $stmt->fetchAll();
        }
        branchChatsAttachMessageCharacterTotals($pdo, $chats, $userId);
        usort($chats, static function (array $a, array $b): int {
            $createdAtComparison = strcmp((string)($a['created_at'] ?? ''), (string)($b['created_at'] ?? ''));
            if ($createdAtComparison !== 0) return $createdAtComparison;

            $characterComparison = ((int)($b['total_message_characters'] ?? 0)) <=> ((int)($a['total_message_characters'] ?? 0));
            if ($characterComparison !== 0) return $characterComparison;

            return ((int)($a['id'] ?? 0)) <=> ((int)($b['id'] ?? 0));
        });

        foreach ($chats as &$chat) {
            $chat['ultima_mensagem'] = branchChatsDecryptMessageText($chat['ultima_mensagem_encrypted'] ?? null);
            unset($chat['ultima_mensagem_encrypted']);
        }
        unset($chat);
        branchChatsRespond(['status' => 'success', 'data' => $chats]);
    }

    if ($method !== 'POST') {
        branchChatsRespond(['status' => 'error', 'message' => 'Método não permitido.'], 405);
    }

    $contentType = $_SERVER['CONTENT_TYPE'] ?? ($_SERVER['HTTP_CONTENT_TYPE'] ?? '');
    $rawInput = file_get_contents('php://input');
    $jsonInput = null;
    if (is_string($rawInput) && trim($rawInput) !== '' && (
        stripos($contentType, 'application/json') !== false ||
        str_starts_with(ltrim($rawInput), '{') ||
        str_starts_with(ltrim($rawInput), '[')
    )) {
        $decodedInput = json_decode($rawInput, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decodedInput)) {
            $jsonInput = $decodedInput;
        }
    }
    $input = $jsonInput ?? $_POST;
    $action = trim((string)($input['action'] ?? ''));

    if ($action === 'create_chat') {
        $stmt = $pdo->prepare('INSERT INTO chats (user_id, titulo, is_open) VALUES (:user_id, :titulo, 1)');
        $stmt->execute([':user_id' => $userId, ':titulo' => branchChatsDefaultTitle(branchChatsTimezoneOffset($input))]);
        $chatId = (int)$pdo->lastInsertId();
        $stmt = $pdo->prepare('SELECT titulo FROM chats WHERE id = :id');
        $stmt->execute([':id' => $chatId]);
        branchChatsRespond(['status' => 'success', 'data' => ['id' => $chatId, 'titulo' => $stmt->fetchColumn()]], 201);
    }

    if ($action === 'update_chat') {
        $chatId = (int)($input['chat_id'] ?? 0);
        $currentChat = branchChatsFind($pdo, $chatId, $userId);
        $title = trim((string)($input['titulo'] ?? ''));
        if ($title === '' || mb_strlen($title) > 120) {
            branchChatsRespond(['status' => 'error', 'message' => 'Informe um nome de até 120 caracteres.'], 422);
        }
        $chatType = (int)($input['chat_type'] ?? 0);
        if ((int)$currentChat['chat_type'] === 3) {
            $chatType = 3;
        } elseif (!in_array($chatType, [0, 1, 2], true)) {
            branchChatsRespond(['status' => 'error', 'message' => 'Tipo de chat inválido.'], 422);
        }
        $reference = trim((string)($input['reference'] ?? ''));
        $referenceLanguage = branchChatsNormalizeReferenceLanguage($input['reference_language'] ?? ($currentChat['reference_audio_language'] ?? null));
        if (in_array($chatType, [2, 3], true) && ($reference === '' || mb_strlen($reference) > 10000)) {
            branchChatsRespond(['status' => 'error', 'message' => 'Informe uma referência de até 10.000 caracteres.'], 422);
        }
        $maxInput = array_key_exists('max', $input) ? $input['max'] : $currentChat['max'];
        $max = $maxInput === null || $maxInput === '' ? null : filter_var($maxInput, FILTER_VALIDATE_INT);
        if ($max !== null && ($max === false || $max < 1)) {
            branchChatsRespond(['status' => 'error', 'message' => 'O máximo de leituras deve ser um inteiro maior que zero ou ficar vazio.'], 422);
        }
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE chats SET titulo = :titulo, chat_type = :chat_type, `max` = :max WHERE id = :id AND user_id = :user_id')->execute([':titulo' => $title, ':chat_type' => $chatType, ':max' => $max, ':id' => $chatId, ':user_id' => $userId]);
        if (in_array($chatType, [2, 3], true)) {
            $referenceChatId = $chatType === 3 && !empty($currentChat['parent_chat_id']) ? (int)$currentChat['parent_chat_id'] : $chatId;
            $pdo->prepare('INSERT INTO chat_references (chat_id, reference_encrypted, reference_audio_language, reference_audio_encrypted) VALUES (:chat_id, :reference, :language, NULL) ON DUPLICATE KEY UPDATE reference_audio_encrypted = IF(reference_encrypted = VALUES(reference_encrypted) AND reference_audio_language = VALUES(reference_audio_language), reference_audio_encrypted, NULL), reference_encrypted = VALUES(reference_encrypted), reference_audio_language = VALUES(reference_audio_language)')->execute([
                ':chat_id' => $referenceChatId,
                ':reference' => Security::encryptData($reference),
                ':language' => $referenceLanguage,
            ]);
        } elseif ($chatType !== 3) {
            $pdo->prepare('DELETE FROM chat_references WHERE chat_id = :chat_id')->execute([':chat_id' => $chatId]);
        }
        $pdo->commit();
        branchChatsRespond(['status' => 'success', 'data' => ['id' => $chatId, 'titulo' => $title, 'chat_type' => $chatType, 'max' => $max, 'reference' => in_array($chatType, [2, 3], true) ? $reference : ($currentChat['reference'] ?? null), 'reference_audio_language' => in_array($chatType, [2, 3], true) ? $referenceLanguage : ($currentChat['reference_audio_language'] ?? 'pt-BR'), 'reference_audio_base64' => null]]);
    }

    if ($action === 'delete_chat') {
        $chatId = (int)($input['chat_id'] ?? 0);
        branchChatsFind($pdo, $chatId, $userId);
        $pdo->beginTransaction();
        branchChatsDeleteChat($pdo, $chatId, $userId);
        $pdo->commit();
        branchChatsRespond(['status' => 'success', 'data' => ['id' => $chatId]]);
    }

    if ($action === 'update_message') {
        $chatId = (int)($input['chat_id'] ?? 0);
        $messageId = (int)($input['message_id'] ?? 0);
        branchChatsFind($pdo, $chatId, $userId);
        $stmt = $pdo->prepare('SELECT m.imagem_encrypted FROM mensagens m INNER JOIN chat_mensagens cm ON cm.mensagem_id = m.id WHERE m.id = :message_id AND cm.chat_id = :chat_id AND m.user_id = :user_id LIMIT 1');
        $stmt->execute([':message_id' => $messageId, ':chat_id' => $chatId, ':user_id' => $userId]);
        $currentImage = $stmt->fetchColumn();
        if ($currentImage === false) {
            branchChatsRespond(['status' => 'error', 'message' => 'Mensagem não encontrada.'], 404);
        }
        $text = trim((string)($input['texto'] ?? ''));
        if (mb_strlen($text) > 10000) {
            branchChatsRespond(['status' => 'error', 'message' => 'A mensagem deve ter no máximo 10.000 caracteres.'], 422);
        }
        $encryptedImage = filter_var($input['remove_image'] ?? false, FILTER_VALIDATE_BOOLEAN) ? null : $currentImage;
        if (isset($_FILES['imagem']) && ($_FILES['imagem']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $encryptedImage = branchChatsStoreImage($_FILES['imagem']);
        }
        if ($text === '' && ($encryptedImage === null || $encryptedImage === '')) {
            branchChatsRespond(['status' => 'error', 'message' => 'A mensagem precisa ter texto ou imagem.'], 422);
        }
        $pdo->prepare('UPDATE mensagens SET texto_encrypted = :texto, imagem_encrypted = :imagem, audio_encrypted = NULL, has_audio = 0 WHERE id = :id AND user_id = :user_id')->execute([
            ':texto' => $text === '' ? null : Security::encryptData($text), ':imagem' => $encryptedImage, ':id' => $messageId, ':user_id' => $userId,
        ]);
        branchChatsRespond(['status' => 'success', 'data' => ['id' => $messageId]]);
    }

    if ($action === 'generate_message_audio') {
        $chatId = (int)($input['chat_id'] ?? 0);
        $messageId = (int)($input['message_id'] ?? 0);
        branchChatsFind($pdo, $chatId, $userId);
        $stmt = $pdo->prepare('SELECT 1 FROM chat_mensagens cm INNER JOIN mensagens m ON m.id = cm.mensagem_id WHERE cm.chat_id = ? AND cm.mensagem_id = ? AND m.user_id = ? LIMIT 1');
        $stmt->execute([$chatId, $messageId, $userId]);
        if (!$stmt->fetchColumn()) branchChatsRespond(['status' => 'error', 'message' => 'Mensagem não encontrada neste chat.'], 404);
        $details = null;
        if (!branchChatsGenerateAndPersistMessageAudio($pdo, $messageId, $userId, $details)) branchChatsRespond(['status' => 'error', 'message' => 'Erro ao gerar áudio da mensagem.', 'details' => $details], 500);
        $stmt = $pdo->prepare('SELECT audio_encrypted FROM mensagens WHERE id = ? AND user_id = ? LIMIT 1');
        $stmt->execute([$messageId, $userId]);
        branchChatsRespond(['status' => 'success', 'data' => ['message_id' => $messageId, 'audio_base64' => branchChatsMessageAudioDataUri($stmt->fetchColumn())]]);
    }

    if ($action === 'generate_reference_audio') {
        $chatId = (int)($input['chat_id'] ?? 0);
        $details = null;
        if (!branchChatsGenerateAndPersistReferenceAudio($pdo, $chatId, $userId, $details, false)) branchChatsRespond(['status' => 'error', 'message' => 'Erro ao gerar áudio da referência.', 'details' => $details], 500);
        $chat = branchChatsFind($pdo, $chatId, $userId);
        branchChatsRespond(['status' => 'success', 'data' => ['chat_id' => $chatId, 'reference_audio_base64' => $chat['reference_audio_base64'] ?? null]]);
    }

    if ($action === 'generate_chat_audio') {
        $chatId = (int)($input['chat_id'] ?? 0);
        $chat = branchChatsFind($pdo, $chatId, $userId);
        $referenceGenerated = 0; $referenceSkipped = 0; $referenceFailed = 0; $lastDetails = null;
        if (in_array((int)$chat['chat_type'], [2, 3], true) && trim((string)($chat['reference'] ?? '')) !== '') {
            if (!empty($chat['reference_audio_base64'])) $referenceSkipped = 1;
            else { $details = null; if (branchChatsGenerateAndPersistReferenceAudio($pdo, $chatId, $userId, $details)) $referenceGenerated = 1; else { $referenceFailed = 1; $lastDetails = $details; } }
        }
        $stmt = $pdo->prepare('SELECT m.id, m.audio_encrypted FROM chat_mensagens cm INNER JOIN mensagens m ON m.id = cm.mensagem_id WHERE cm.chat_id = ? AND m.user_id = ? AND m.texto_encrypted IS NOT NULL ORDER BY cm.position ASC');
        $stmt->execute([$chatId, $userId]);
        $generated = 0; $skipped = 0; $failed = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $message) {
            $messageId = (int)$message['id'];
            if (branchChatsMessageAudioDataUri($message['audio_encrypted'] ?? null) !== null) { $skipped++; continue; }
            $details = null;
            if (branchChatsGenerateAndPersistMessageAudio($pdo, $messageId, $userId, $details)) $generated++; else { $failed++; $lastDetails = $details; }
        }
        branchChatsRespond(['status' => 'success', 'data' => ['generated_count' => $generated + $referenceGenerated, 'skipped_count' => $skipped + $referenceSkipped, 'failed_count' => $failed + $referenceFailed, 'details' => $lastDetails]]);
    }

    if ($action === 'read_cycle_status') {
        branchChatsRespond(['status' => 'success', 'data' => branchChatsReadCycleStatus($pdo, $userId, branchChatsTimezoneOffset($input))]);
    }

    if ($action === 'mark_viewed') {
        $chatId = (int)($input['chat_id'] ?? 0);
        $timezoneOffsetMinutes = branchChatsTimezoneOffset($input);
        $chatForView = branchChatsFind($pdo, $chatId, $userId);
        if ((int)($chatForView['is_open'] ?? 0) === 1) {
            $pdo->prepare('UPDATE chats SET is_open = 0 WHERE id = :id AND user_id = :user_id AND is_open = 1')->execute([':id' => $chatId, ':user_id' => $userId]);
            $chat = branchChatsFind($pdo, $chatId, $userId);
            branchChatsRespond(['status' => 'success', 'data' => [
                'closed' => true,
                'is_open' => (int)$chat['is_open'],
                'view_count' => (int)$chat['view_count'],
                'last_viewed_at' => $chat['last_viewed_at'],
                'next_view_at' => $chat['next_view_at'],
                'can_mark_viewed' => (bool)$chat['can_mark_viewed'],
                'early_review' => (bool)$chat['early_review'],
            ]]);
        }
        
        if (branchChatsDailyReadLimitReached($pdo, $userId, $timezoneOffsetMinutes)) {
            branchChatsRespond(['status' => 'error', 'message' => 'Você atingiu o limite de 999 leituras para hoje neste fuso horário.'], 429);
        }

        $pdo->beginTransaction();
        $stmt = $pdo->prepare(
            'INSERT IGNORE INTO chat_views (chat_id, user_id, view_count, last_viewed_at)
             VALUES (:chat_id, :user_id, 0, NULL)'
        );
        $stmt->execute([':chat_id' => $chatId, ':user_id' => $userId]);
        
        $stmt = $pdo->prepare(
            'SELECT cv.view_count, cv.last_viewed_at, c.`max`,
                    CASE WHEN last_viewed_at IS NULL OR CURRENT_TIMESTAMP >= ' . branchChatsNextViewExpression('last_viewed_at', 'view_count') . '
                         THEN 1 ELSE 0 END AS can_mark_viewed
             FROM chat_views cv
             INNER JOIN chats c ON c.id = cv.chat_id AND c.user_id = cv.user_id
             WHERE cv.chat_id = :chat_id AND cv.user_id = :user_id FOR UPDATE'
        );
        $stmt->execute([':chat_id' => $chatId, ':user_id' => $userId]);
        $view = $stmt->fetch();
        
        if (!(bool)$view['can_mark_viewed'] && !branchChatsCanReviewEarly($pdo, $chatId, $userId)) {
            $pdo->rollBack();
            branchChatsRespond(['status' => 'error', 'message' => 'A próxima leitura ainda não está disponível.'], 409);
        }

        $stmt = $pdo->prepare(
            'UPDATE chat_views
             SET view_count = view_count + 1, last_viewed_at = CURRENT_TIMESTAMP
             WHERE chat_id = :chat_id AND user_id = :user_id'
        );
        $stmt->execute([':chat_id' => $chatId, ':user_id' => $userId]);
        $cycle = branchChatsIncrementReadCycle($pdo, $userId, $timezoneOffsetMinutes);
        $pdo->prepare('UPDATE chats SET read_marker_message_id = NULL WHERE id = :id AND user_id = :user_id')->execute([':id' => $chatId, ':user_id' => $userId]);
        
        $viewCount = (int)$view['view_count'] + 1;
        if ($view['max'] !== null && $viewCount >= (int)$view['max']) {
            branchChatsDeleteChat($pdo, $chatId, $userId);
            $pdo->commit();
            branchChatsRespond(['status' => 'success', 'data' => [
                'deleted' => true,
                'view_count' => $viewCount,
                'read_cycle_count' => $cycle['read_cycle_count'],
                'read_cycle_limit' => $cycle['read_cycle_limit'],
                'read_cycle_date' => $cycle['read_cycle_date'],
            ]]);
        }
        $pdo->commit();
        
        $chat = branchChatsFind($pdo, $chatId, $userId);
        branchChatsRespond(['status' => 'success', 'data' => [
            'view_count' => (int)$chat['view_count'],
            'last_viewed_at' => $chat['last_viewed_at'],
            'next_view_at' => $chat['next_view_at'],
            'can_mark_viewed' => (bool)$chat['can_mark_viewed'],
            'early_review' => (bool)$chat['early_review'],
            'read_cycle_count' => $cycle['read_cycle_count'],
            'read_cycle_limit' => $cycle['read_cycle_limit'],
            'read_cycle_date' => $cycle['read_cycle_date'],
        ]]);
    }

    if ($action === 'set_read_marker') {
        $chatId = (int)($input['chat_id'] ?? 0);
        $messageId = (int)($input['message_id'] ?? 0);
        branchChatsFind($pdo, $chatId, $userId);

        if ($messageId < 1) {
            branchChatsRespond(['status' => 'error', 'message' => 'Mensagem inválida.'], 422);
        }

        $stmt = $pdo->prepare(
            'SELECT 1
             FROM chat_mensagens cm
             INNER JOIN mensagens m ON m.id = cm.mensagem_id
             WHERE cm.chat_id = :chat_id AND cm.mensagem_id = :message_id AND m.user_id = :user_id
             LIMIT 1'
        );
        $stmt->execute([':chat_id' => $chatId, ':message_id' => $messageId, ':user_id' => $userId]);
        if (!$stmt->fetchColumn()) {
            branchChatsRespond(['status' => 'error', 'message' => 'Mensagem não encontrada neste chat.'], 404);
        }

        $pdo->prepare('UPDATE chats SET read_marker_message_id = :message_id WHERE id = :id AND user_id = :user_id')->execute([
            ':message_id' => $messageId,
            ':id' => $chatId,
            ':user_id' => $userId,
        ]);
        branchChatsRespond(['status' => 'success', 'data' => ['read_marker_message_id' => $messageId]]);
    }

    if ($action === 'branch_message') {
        $sourceChatId = (int)($input['chat_id'] ?? 0);
        $messageId = (int)($input['message_id'] ?? 0);
        $branchMode = (string)($input['branch_mode'] ?? '');
        branchChatsFind($pdo, $sourceChatId, $userId);

        if (!in_array($branchMode, ['single', 'through'], true) || $messageId < 1) {
            branchChatsRespond(['status' => 'error', 'message' => 'Opção de branch inválida.'], 422);
        }

        $stmt = $pdo->prepare(
            'SELECT cm.position
             FROM chat_mensagens cm
             INNER JOIN mensagens m ON m.id = cm.mensagem_id
             WHERE cm.chat_id = :chat_id AND cm.mensagem_id = :message_id AND m.user_id = :user_id
             LIMIT 1'
        );
        $stmt->execute([':chat_id' => $sourceChatId, ':message_id' => $messageId, ':user_id' => $userId]);
        $selectedPosition = $stmt->fetchColumn();
        if ($selectedPosition === false) {
            branchChatsRespond(['status' => 'error', 'message' => 'Mensagem não encontrada neste chat.'], 404);
        }

        $pdo->beginTransaction();
        $stmt = $pdo->prepare('INSERT INTO chats (user_id, parent_chat_id, titulo, is_open) VALUES (:user_id, :parent_id, :titulo, 1)');
        $stmt->execute([':user_id' => $userId, ':parent_id' => $sourceChatId, ':titulo' => branchChatsDefaultTitle(branchChatsTimezoneOffset($input))]);
        $targetChatId = (int)$pdo->lastInsertId();

        if ($branchMode === 'single') {
            $stmt = $pdo->prepare('INSERT INTO chat_mensagens (chat_id, mensagem_id, position) VALUES (:chat_id, :message_id, 1)');
            $stmt->execute([':chat_id' => $targetChatId, ':message_id' => $messageId]);
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO chat_mensagens (chat_id, mensagem_id, position)
                 SELECT :target_id, mensagem_id, position
                 FROM chat_mensagens
                 WHERE chat_id = :source_id AND position <= :selected_position
                 ORDER BY position'
            );
            $stmt->execute([':target_id' => $targetChatId, ':source_id' => $sourceChatId, ':selected_position' => $selectedPosition]);
        }

        $pdo->commit();
        branchChatsRespond(['status' => 'success', 'data' => ['chat_id' => $targetChatId, 'branch_mode' => $branchMode]], 201);
    }

    if ($action === 'branch_selected_messages') {
        $sourceChatId = (int)($input['chat_id'] ?? 0);
        $messageIds = array_values(array_unique(array_filter(
            array_map('intval', is_array($input['message_ids'] ?? null) ? $input['message_ids'] : []),
            static fn (int $id): bool => $id > 0
        )));
        branchChatsFind($pdo, $sourceChatId, $userId);
        if ($messageIds === []) {
            branchChatsRespond(['status' => 'error', 'message' => 'Selecione pelo menos uma mensagem.'], 422);
        }

        $placeholders = implode(',', array_fill(0, count($messageIds), '?'));
        $stmt = $pdo->prepare(
            "SELECT cm.mensagem_id
             FROM chat_mensagens cm
             INNER JOIN mensagens m ON m.id = cm.mensagem_id
             WHERE cm.chat_id = ? AND m.user_id = ? AND cm.mensagem_id IN ($placeholders)
             ORDER BY cm.position"
        );
        $stmt->execute(array_merge([$sourceChatId, $userId], $messageIds));
        $validMessageIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        if (count($validMessageIds) !== count($messageIds)) {
            branchChatsRespond(['status' => 'error', 'message' => 'Uma ou mais mensagens não pertencem a este chat.'], 422);
        }

        $pdo->beginTransaction();
        $stmt = $pdo->prepare('INSERT INTO chats (user_id, parent_chat_id, titulo, is_open) VALUES (:user_id, :parent_id, :titulo, 1)');
        $stmt->execute([':user_id' => $userId, ':parent_id' => $sourceChatId, ':titulo' => branchChatsDefaultTitle(branchChatsTimezoneOffset($input))]);
        $targetChatId = (int)$pdo->lastInsertId();
        $insert = $pdo->prepare(
            'INSERT INTO chat_mensagens (chat_id, mensagem_id, position, is_response)
             SELECT :target_id, mensagem_id, :position, is_response
             FROM chat_mensagens
             WHERE chat_id = :source_id AND mensagem_id = :message_id'
        );
        foreach ($validMessageIds as $position => $validMessageId) {
            $insert->execute([
                ':target_id' => $targetChatId,
                ':position' => $position + 1,
                ':source_id' => $sourceChatId,
                ':message_id' => $validMessageId,
            ]);
        }
        $pdo->commit();
        branchChatsRespond(['status' => 'success', 'data' => ['chat_id' => $targetChatId]], 201);
    }

    if ($action === 'delete_message') {
        $chatId = (int)($input['chat_id'] ?? 0);
        $messageId = (int)($input['message_id'] ?? 0);
        branchChatsFind($pdo, $chatId, $userId);
        $stmt = $pdo->prepare('DELETE m FROM mensagens m INNER JOIN chat_mensagens cm ON cm.mensagem_id = m.id WHERE m.id = :message_id AND cm.chat_id = :chat_id AND m.user_id = :user_id');
        $stmt->execute([':message_id' => $messageId, ':chat_id' => $chatId, ':user_id' => $userId]);
        if ($stmt->rowCount() === 0) branchChatsRespond(['status' => 'error', 'message' => 'Mensagem não encontrada.'], 404);
        branchChatsRespond(['status' => 'success', 'data' => ['id' => $messageId]]);
    }

    if ($action !== 'send_message') {
        branchChatsRespond(['status' => 'error', 'message' => 'Ação inválida.'], 422);
    }

    $sourceChatId = (int)($input['chat_id'] ?? 0);
    $sourceChat = branchChatsFind($pdo, $sourceChatId, $userId);
    $sourceChatType = (int)$sourceChat['chat_type'];
    $answerInNewChat = in_array($sourceChatType, [1, 2, 3], true);
    $referenceChat = in_array($sourceChatType, [2, 3], true);
    $createBranch = $answerInNewChat || filter_var($input['create_branch'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $text = trim((string)($input['texto'] ?? ''));
    $isRecipient = filter_var($input['is_recipient'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
    
    $colorVariant = branchChatsNormalizeVariant($input['color_variant'] ?? null, $isRecipient);
    $audioLanguage = in_array($colorVariant, ['orange', 'purple'], true) ? 'en-GB' : 'pt-BR';
    
    if (mb_strlen($text) > 10000) {
        branchChatsRespond(['status' => 'error', 'message' => 'A mensagem deve ter no máximo 10.000 caracteres.'], 422);
    }

    $encryptedImage = null;
    $image = $_FILES['imagem'] ?? null;
    if ($image && ($image['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $encryptedImage = branchChatsStoreImage($image);
    }

    if ($text === '' && $encryptedImage === null) {
        branchChatsRespond(['status' => 'error', 'message' => 'Escreva uma mensagem ou adicione uma imagem.'], 422);
    }

    $pdo->beginTransaction();
    $targetChatId = $sourceChatId;
    if ($createBranch) {
        $parentChatId = $sourceChatType === 3 && !empty($sourceChat['parent_chat_id']) ? (int)$sourceChat['parent_chat_id'] : $sourceChatId;
        $stmt = $pdo->prepare('INSERT INTO chats (user_id, parent_chat_id, titulo, chat_type, is_open) VALUES (:user_id, :parent_id, :titulo, :chat_type, 1)');
        $stmt->execute([':user_id' => $userId, ':parent_id' => $parentChatId, ':titulo' => branchChatsDefaultTitle(branchChatsTimezoneOffset($input)), ':chat_type' => $referenceChat ? 3 : 0]);
        $targetChatId = (int)$pdo->lastInsertId();
        if (!$referenceChat) {
            $stmt = $pdo->prepare('INSERT INTO chat_mensagens (chat_id, mensagem_id, position) SELECT :target_id, mensagem_id, position FROM chat_mensagens WHERE chat_id = :source_id');
            $stmt->execute([':target_id' => $targetChatId, ':source_id' => $sourceChatId]);
        }
    }

    $encryptedText = $text !== '' ? Security::encryptData($text) : null;
    
    $stmt = $pdo->prepare('INSERT INTO mensagens (user_id, texto_encrypted, imagem_encrypted, is_recipient, color_variant, audio_language) VALUES (:user_id, :texto_encrypted, :imagem_encrypted, :is_recipient, :color_variant, :audio_language)');
    $stmt->execute([
        ':user_id' => $userId,
        ':texto_encrypted' => $encryptedText,
        ':imagem_encrypted' => $encryptedImage,
        ':is_recipient' => $isRecipient,
        ':color_variant' => $colorVariant,
        ':audio_language' => $audioLanguage
    ]);
    $messageId = (int)$pdo->lastInsertId();
    
    $stmt = $pdo->prepare('INSERT INTO chat_mensagens (chat_id, mensagem_id, position, is_response) SELECT :chat_id, :message_id, COALESCE(MAX(position), 0) + 1, :is_response FROM chat_mensagens WHERE chat_id = :position_chat_id');
    $stmt->execute([':chat_id' => $targetChatId, ':message_id' => $messageId, ':is_response' => $answerInNewChat && !$referenceChat ? 1 : 0, ':position_chat_id' => $targetChatId]);
    
    $pdo->prepare('UPDATE chats SET updated_at = CURRENT_TIMESTAMP WHERE id = :id')->execute([':id' => $targetChatId]);
    
    $stmt = $pdo->prepare('SELECT id, created_at FROM mensagens WHERE id = :id');
    $stmt->execute([':id' => $messageId]);
    $message = $stmt->fetch();
    
    $message['texto'] = $text !== '' ? $text : null;
    $message['imagem_base64'] = $encryptedImage !== null ? Security::decryptData($encryptedImage) : null;
    $message['is_recipient'] = $isRecipient;
    $message['color_variant'] = $colorVariant;
    $message['has_audio'] = 0;
    $message['audio_base64'] = null;
    $message['audio_language'] = $audioLanguage; 
    
    $pdo->commit();
    branchChatsRespond(['status' => 'success', 'data' => ['message' => $message, 'chat_id' => $targetChatId, 'branched' => $createBranch, 'answered_in_new_chat' => $answerInNewChat]], 201);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    branchChatsRespond(['status' => 'error', 'message' => 'Erro interno ao processar o chat.'], 500);
}
