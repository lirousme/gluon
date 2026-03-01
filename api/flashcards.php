<?php
// Arquivo: flashcards.php
// Diretório: public_html/gluon/api/flashcards.php

/**
 * MICRO-API DE FLASHCARDS
 * Pilar: Seguro, Rápido e Escalável.
 * Gerencia CRUD, Criptografia e Motor de Pontuação/Gameficação.
 */

require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    die(json_encode(['status' => 'error', 'message' => 'Não autorizado. Faça login.']));
}

$pdo = Database::getConnection();
$user_id = $_SESSION['user_id'];
$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';

// Função auxiliar para verificar se o usuário é dono do deck (Segurança IDOR)
function verifyDeckOwnership($pdo, $deck_id, $user_id) {
    $stmt = $pdo->prepare("SELECT id, name_encrypted FROM directories WHERE id = ? AND user_id = ? AND type = 4");
    $stmt->execute([$deck_id, $user_id]);
    return $stmt->fetch();
}

if ($action === 'fetch') {
    $deck_id = (int)($input['deck_id'] ?? 0);
    if ($deck_id === 0) die(json_encode(['status' => 'error', 'message' => 'ID do deck inválido.']));

    $deck = verifyDeckOwnership($pdo, $deck_id, $user_id);
    if (!$deck) {
        die(json_encode(['status' => 'error', 'message' => 'Deck não encontrado ou sem permissão.']));
    }

    // Traz os cards junto com a pontuação específica DO USUÁRIO ATUAL (LEFT JOIN)
    $stmt = $pdo->prepare("
        SELECT f.id, f.front_encrypted, f.back_encrypted, COALESCE(fs.score, 0) as score 
        FROM flashcards f
        LEFT JOIN flashcard_scores fs ON fs.flashcard_id = f.id AND fs.user_id = ?
        WHERE f.directory_id = ? 
        ORDER BY f.sort_order ASC, f.id ASC
    ");
    $stmt->execute([$user_id, $deck_id]);
    $cards = $stmt->fetchAll();

    $response = [];
    $total_score = 0;
    $max_possible_score = count($cards) * 10;

    foreach ($cards as $card) {
        $score = (int)$card['score'];
        $total_score += $score;
        
        $response[] = [
            'id' => $card['id'],
            'front' => Security::decryptData($card['front_encrypted']),
            'back' => Security::decryptData($card['back_encrypted']),
            'score' => $score
        ];
    }

    // Calcula a porcentagem de maestria do Deck
    $deck_percentage = $max_possible_score > 0 ? round(($total_score / $max_possible_score) * 100) : 0;

    echo json_encode([
        'status' => 'success', 
        'deck_name' => Security::decryptData($deck['name_encrypted']),
        'deck_percentage' => $deck_percentage,
        'data' => $response
    ]);
}

elseif ($action === 'update_score') {
    // Ação acionada em background quando o usuário clica em "Próximo"
    $card_id = (int)($input['card_id'] ?? 0);
    
    if ($card_id === 0) {
        die(json_encode(['status' => 'error', 'message' => 'ID do card inválido.']));
    }

    // Validação estrita de segurança: O card pertence a um deck do usuário logado?
    $stmtCheck = $pdo->prepare("
        SELECT d.user_id 
        FROM flashcards f 
        JOIN directories d ON f.directory_id = d.id 
        WHERE f.id = ?
    ");
    $stmtCheck->execute([$card_id]);
    $owner = $stmtCheck->fetchColumn();

    if ($owner != $user_id) {
        die(json_encode(['status' => 'error', 'message' => 'Acesso negado.']));
    }

    // Usa UPSERT (ON DUPLICATE KEY UPDATE) para performance extrema e evitar condições de corrida
    // A função LEAST(score + 1, 10) garante que nunca passe de 10.
    $stmt = $pdo->prepare("
        INSERT INTO flashcard_scores (user_id, flashcard_id, score) 
        VALUES (?, ?, 1) 
        ON DUPLICATE KEY UPDATE score = LEAST(score + 1, 10), last_reviewed_at = CURRENT_TIMESTAMP
    ");
    
    if ($stmt->execute([$user_id, $card_id])) {
        echo json_encode(['status' => 'success']);
    } else {
        echo json_encode(['status' => 'error']);
    }
}

elseif ($action === 'add_single') {
    $deck_id = (int)($input['deck_id'] ?? 0);
    $front = trim($input['front'] ?? '');
    $back = trim($input['back'] ?? '');

    if ($deck_id === 0 || empty($front) || empty($back)) {
        die(json_encode(['status' => 'error', 'message' => 'Dados inválidos. Preencha frente e verso.']));
    }

    if (!verifyDeckOwnership($pdo, $deck_id, $user_id)) {
        die(json_encode(['status' => 'error', 'message' => 'Deck não encontrado ou sem permissão.']));
    }

    $front_enc = Security::encryptData($front);
    $back_enc = Security::encryptData($back);

    $stmt = $pdo->prepare("INSERT INTO flashcards (directory_id, front_encrypted, back_encrypted) VALUES (?, ?, ?)");
    if ($stmt->execute([$deck_id, $front_enc, $back_enc])) {
        echo json_encode(['status' => 'success', 'message' => 'Card adicionado com segurança.']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Erro ao adicionar card.']);
    }
}

elseif ($action === 'add_bulk') {
    $deck_id = (int)($input['deck_id'] ?? 0);
    $cards = $input['cards'] ?? [];

    if ($deck_id === 0 || !is_array($cards) || count($cards) === 0) {
        die(json_encode(['status' => 'error', 'message' => 'Nenhum card válido enviado.']));
    }

    if (!verifyDeckOwnership($pdo, $deck_id, $user_id)) {
        die(json_encode(['status' => 'error', 'message' => 'Deck não encontrado ou sem permissão.']));
    }

    try {
        $pdo->beginTransaction();
        
        $stmt = $pdo->prepare("INSERT INTO flashcards (directory_id, front_encrypted, back_encrypted) VALUES (?, ?, ?)");
        
        $count = 0;
        foreach ($cards as $card) {
            $front = trim($card['front'] ?? '');
            $back = trim($card['back'] ?? '');
            
            if (!empty($front) && !empty($back)) {
                $front_enc = Security::encryptData($front);
                $back_enc = Security::encryptData($back);
                $stmt->execute([$deck_id, $front_enc, $back_enc]);
                $count++;
            }
        }
        
        $pdo->commit();
        echo json_encode(['status' => 'success', 'message' => "$count cards importados com segurança!"]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['status' => 'error', 'message' => 'Erro interno ao importar cards.']);
    }
}

else {
    echo json_encode(['status' => 'error', 'message' => 'Ação inválida.']);
}
?>
