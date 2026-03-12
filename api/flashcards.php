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


try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS flashcard_batch_jobs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        directory_id INT UNSIGNED NOT NULL,
        topic VARCHAR(200) DEFAULT NULL,
        deck_structure VARCHAR(20) NOT NULL DEFAULT 'fatos',
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

// Função auxiliar para verificar se o usuário é dono do deck (Segurança IDOR)
function verifyDeckOwnership($pdo, $deck_id, $user_id) {
    $stmt = $pdo->prepare("SELECT id, name_encrypted, deck_mode, deck_front_language, deck_back_language, deck_structure FROM directories WHERE id = ? AND user_id = ? AND type = 4");
    $stmt->execute([$deck_id, $user_id]);
    return $stmt->fetch();
}

function verifyDirectoryOwnership($pdo, $directory_id, $user_id) {
    $stmt = $pdo->prepare("SELECT id, type FROM directories WHERE id = ? AND user_id = ?");
    $stmt->execute([$directory_id, $user_id]);
    return $stmt->fetch();
}

function collectDecksFromDirectoryTree($pdo, $root_directory_id, $user_id) {
    $decks = [];
    $visited = [];

    $stmtRoot = $pdo->prepare("SELECT id, type, deck_front_language, deck_back_language FROM directories WHERE id = ? AND user_id = ?");
    $stmtRoot->execute([$root_directory_id, $user_id]);
    $root = $stmtRoot->fetch();
    if (!$root) {
        return $decks;
    }

    $queue = [(int)$root['id']];
    if ((int)$root['type'] === 4) {
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
        $stmtChildren = $pdo->prepare("SELECT id, type, deck_front_language, deck_back_language FROM directories WHERE user_id = ? AND parent_id IN ($placeholders)");
        $stmtChildren->execute($params);
        $children = $stmtChildren->fetchAll();

        $queue = [];
        foreach ($children as $child) {
            $child_id = (int)$child['id'];
            $queue[] = $child_id;
            if ((int)$child['type'] === 4) {
                $decks[] = $child;
            }
        }
    }

    return $decks;
}

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
    $allowed = ['fatos', 'perguntas', 'traducoes', 'parafrases'];
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




function generateAndPersistCardAudio($pdo, $card_id, $side, $text, $language) {
    $text_to_speech = adjustPronunciationForTTS($pdo, $text, $language);
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
    curl_close($ch);

    if ($httpcode !== 200 || !$response) {
        return false;
    }

    $audio_binary = null;
    $decodedResponse = json_decode($response, true);

    if (json_last_error() === JSON_ERROR_NONE && is_array($decodedResponse)) {
        $audio_binary = decodeTtsAudioBinaryFromJsonPayload($decodedResponse);
    }

    if ($audio_binary === null) {
        $audio_binary = $response;
    }

    if (!is_string($audio_binary) || $audio_binary === '') {
        return false;
    }

    $audio_b64 = base64_encode($audio_binary);
    $audio_encrypted = Security::encryptData($audio_b64);

    $audioCol = $side === 'front' ? 'audio_front_encrypted' : 'audio_back_encrypted';
    $col = $side === 'front' ? 'has_audio_front' : 'has_audio_back';
    $stmt = $pdo->prepare("UPDATE flashcards SET $col = 1, $audioCol = ? WHERE id = ?");
    $stmt->execute([$audio_encrypted, $card_id]);

    return true;
}

function buildFlashcardsGenerationPayload($deck_name, $deck_structure, $historyText, $model = 'gpt-5-nano') {
    $basePrompt = '';
    if ($deck_structure === 'fatos') {
        $basePrompt = 'Me dê informações sobre o assunto "' . $deck_name . '", em uma tabela de uma única coluna, onde cada linha é uma informação. As informações devem ser de fácil compreensão. As informações devem ser óbvias evidentes e de rápida assimilação e de preferência curtas. Nenhuma informação pode ser igual as informações anteriores (salvo em paráfrases, uso de sinônimos e oposição ex. frase negativa e frase afirmativa) que já fiz. A ideia é conseguir vencer o paradoxo de Mênon, conseguir saber tudo sobre esse assunto, do nível para leigos ao nível expert. Caso não tenha muitas perguntas anteriores, vá pelo nível para leigos, e só aumente o nível se as perguntas anteriores já tiverem abrangido todo nível de conhecimento para leigos no assunto. Frases curtas.';
    } elseif ($deck_structure === 'perguntas') {
        $basePrompt = 'Me dê perguntas que induzam conhecimento fundamental, elementar, essencial, indispensável sobre o assunto "' . $deck_name . '" coisas elementares e ontológicas sobre, hermenêutica, em uma tabela de duas colunas, onde cada linha é uma pergunta, a primeira coluna é a pergunta, e a segunda é a resposta. As perguntas e respostas devem ser óbvias evidentes e de rápida assimilação. Use linguagem simples e de fácil assimilação para pessoas de qualquer nível intelectual. As pessoas devem conseguir decodificar a informação codificada nas perguntas e respostas de forma assustadoramente fácil. As perguntas devem ser simples e de preferência curtas. Nenhuma pergunta pode ser igual as informações anteriores (salvo em paráfrases, uso de sinônimos e oposição ex. frase negativa e frase afirmativa) que já fiz. O objetivo é construir aprendizado progressivo sem redundância. Público alvo são crianças de 10 à 17 anos.';
    } elseif ($deck_structure === 'parafrases') {
        $basePrompt = 'gere 15 paráfrases dessa: "' . $deck_name . '" (Num tom de escrita de José Saramago)';
    } else {
        $basePrompt = 'Crie pares de tradução sobre o assunto "' . $deck_name . '" com frases curtas e úteis para memorização. Primeira coluna na língua da frente do deck, segunda coluna na língua do verso. Sem repetições e sem conteúdo de interface.';
    }

    $systemPrompt = 'Você é um gerador de flashcards para estudo. Retorne APENAS JSON válido no formato {"cards":[{"front":"...","back":"..."}]}. Não use markdown. Nunca deixe "front" vazio. Para estruturas perguntas e traducoes, nunca deixe "back" vazio. Para estruturas fatos e parafrases, deixe back vazio. Preserve exatamente caracteres Unicode.';

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

Gere 15 novos cards sem repetição de conteúdo com o histórico.";
    }

    $requiresBack = in_array($deck_structure, ['perguntas', 'traducoes'], true);
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

    if (cardTextContainsMathNotation($clean_text)) {
        die(json_encode(['status' => 'error', 'message' => 'Este conteúdo possui notação matemática e não pode ter áudio gerado.']));
    }

    $front_language = normalizeDeckLanguage($card['deck_front_language'] ?? 'pt-BR', 'pt-BR');
    $back_language = normalizeDeckLanguage($card['deck_back_language'] ?? 'en-GB', 'en-GB');
    $side_language = $side === 'front' ? $front_language : $back_language;

    $ok = generateAndPersistCardAudio($pdo, $card_id, $side, $clean_text, $side_language);
    if (!$ok) {
        die(json_encode(['status' => 'error', 'message' => 'Erro ao comunicar com a API de voz. O serviço pode estar indisponível.']));
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

                $ok = generateAndPersistCardAudio($pdo, (int)$card['id'], $job['side'], $job['text'], $job['language']);
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
        $next_job = findNextPendingAudioJobForDeck($pdo, (int)$deck['id'], $front_language, $back_language);
        if ($next_job) {
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

    $ok = generateAndPersistCardAudio($pdo, $next_job['card_id'], $next_job['side'], $next_job['text'], $next_job['language']);
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
    if (!$deck) {
        die(json_encode(['status' => 'error', 'message' => 'Deck não encontrado.']));
    }

    $isBookMode = ($deck['deck_mode'] ?? 'aleatorio') === 'livro';

    if ($isBookMode) {
        $stmt = $pdo->prepare("
            INSERT INTO flashcard_book_progress (user_id, directory_id, current_index, completed_reads) 
            VALUES (?, ?, 0, 0) 
            ON DUPLICATE KEY UPDATE completed_reads = 0
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

    $has_front = !empty($front) || !empty($image_front);
    $has_back = !empty($back) || !empty($image_back);

    if ($deck_id === 0 || (!$has_front && !$has_back)) {
        die(json_encode(['status' => 'error', 'message' => 'Preencha pelo menos um lado do card com texto ou imagem.']));
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

    // Zera as flags de áudio porque o texto mudou e o áudio antigo não bate mais com a descrição
    $stmt = $pdo->prepare("UPDATE flashcards SET front_encrypted = ?, back_encrypted = ?, image_front_encrypted = ?, image_back_encrypted = ?, has_audio_front = 0, has_audio_back = 0, audio_front_encrypted = NULL, audio_back_encrypted = NULL WHERE id = ?");
    
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
        echo json_encode(['status' => 'success', 'message' => 'Card excluído com sucesso.']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Erro interno ao excluir card.']);
    }
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
    $deck_structure = normalizeDeckStructure($deck['deck_structure'] ?? 'fatos', 'fatos');
    $historyText = fetchDeckHistoryText($pdo, $deck_id);
    $chatPayload = buildFlashcardsGenerationPayload($deck_name, $deck_structure, $historyText, 'gpt-5.4');

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
    $deck_structure = normalizeDeckStructure($deck['deck_structure'] ?? 'fatos', 'fatos');

    $historyText = fetchDeckHistoryText($pdo, $deck_id);
    $payloadBase = buildFlashcardsGenerationPayload($deck_name, $deck_structure, $historyText);

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
