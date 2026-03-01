<?php
// Arquivo: flashcards.php
// Diretório: public_html/gluon/api/flashcards.php

/**
 * MICRO-API DE FLASHCARDS
 * Pilar: Seguro, Rápido e Escalável.
 * Gerencia CRUD, Criptografia, Repetição Espaçada e Modos de Deck.
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
    $stmt = $pdo->prepare("SELECT id, name_encrypted, deck_mode FROM directories WHERE id = ? AND user_id = ? AND type = 4");
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

    $deck_mode = $deck['deck_mode'] ?? 'aleatorio';
    $current_index = 0;

    // Se for modo aleatório (Estudo de Repetição Espaçada)
    if ($deck_mode === 'aleatorio') {
        // Traz cards novos (sem score) ou cards que já venceram a data de revisão
        $stmt = $pdo->prepare("
            SELECT f.id, f.front_encrypted, f.back_encrypted, COALESCE(fs.score, 0) as score 
            FROM flashcards f
            LEFT JOIN flashcard_scores fs ON fs.flashcard_id = f.id AND fs.user_id = ?
            WHERE f.directory_id = ? AND (fs.next_review_at IS NULL OR fs.next_review_at <= NOW())
            ORDER BY RAND()
        ");
        $stmt->execute([$user_id, $deck_id]);
    } 
    // Se for modo Livro (Leitura Sequencial)
    else {
        // Traz todos os cards na ordem correta
        $stmt = $pdo->prepare("
            SELECT f.id, f.front_encrypted, f.back_encrypted, 0 as score 
            FROM flashcards f
            WHERE f.directory_id = ? 
            ORDER BY f.sort_order ASC, f.id ASC
        ");
        $stmt->execute([$deck_id]);

        // Busca o progresso atual do usuário neste livro
        $stmtProg = $pdo->prepare("SELECT current_index FROM flashcard_book_progress WHERE user_id = ? AND directory_id = ?");
        $stmtProg->execute([$user_id, $deck_id]);
        $current_index = (int)$stmtProg->fetchColumn() ?: 0;
    }
    
    $cards = $stmt->fetchAll();

    // Calcula a maestria total do deck para mostrar no topo (apenas visual)
    $stmtTotal = $pdo->prepare("SELECT COUNT(id) FROM flashcards WHERE directory_id = ?");
    $stmtTotal->execute([$deck_id]);
    $total_cards_in_deck = (int)$stmtTotal->fetchColumn();

    $stmtScore = $pdo->prepare("
        SELECT SUM(score) FROM flashcard_scores fs 
        JOIN flashcards f ON fs.flashcard_id = f.id 
        WHERE f.directory_id = ? AND fs.user_id = ?
    ");
    $stmtScore->execute([$deck_id, $user_id]);
    $total_score_deck = (int)$stmtScore->fetchColumn();
    
    $max_possible_score = $total_cards_in_deck * 20;
    $deck_percentage = $max_possible_score > 0 ? round(($total_score_deck / $max_possible_score) * 100) : 0;

    $response = [];
    foreach ($cards as $card) {
        $response[] = [
            'id' => $card['id'],
            'front' => Security::decryptData($card['front_encrypted']),
            'back' => !empty($card['back_encrypted']) ? Security::decryptData($card['back_encrypted']) : '',
            'score' => (int)$card['score']
        ];
    }

    echo json_encode([
        'status' => 'success', 
        'deck_name' => Security::decryptData($deck['name_encrypted']),
        'deck_mode' => $deck_mode,
        'deck_percentage' => $deck_percentage,
        'total_cards' => $total_cards_in_deck,
        'current_index' => $current_index,
        'data' => $response
    ]);
}

elseif ($action === 'update_score') {
    $card_id = (int)($input['card_id'] ?? 0);
    
    if ($card_id === 0) die(json_encode(['status' => 'error', 'message' => 'ID do card inválido.']));

    $stmtCheck = $pdo->prepare("SELECT d.user_id FROM flashcards f JOIN directories d ON f.directory_id = d.id WHERE f.id = ?");
    $stmtCheck->execute([$card_id]);
    $owner = $stmtCheck->fetchColumn();

    if ($owner != $user_id) die(json_encode(['status' => 'error', 'message' => 'Acesso negado.']));

    // Repetição Espaçada Segura: Impede update no banco se a data de revisão daquele cartão não tiver chegado,
    // garantindo zero chance de fraudes via API.
    $stmt = $pdo->prepare("
        INSERT INTO flashcard_scores (user_id, flashcard_id, score, next_review_at) 
        VALUES (?, ?, 1, DATE_ADD(NOW(), INTERVAL 24 HOUR)) 
        ON DUPLICATE KEY UPDATE 
            score = IF(next_review_at IS NULL OR next_review_at <= NOW(), LEAST(score + 1, 20), score), 
            last_reviewed_at = IF(next_review_at IS NULL OR next_review_at <= NOW(), CURRENT_TIMESTAMP, last_reviewed_at),
            next_review_at = IF(next_review_at IS NULL OR next_review_at <= NOW(), DATE_ADD(NOW(), INTERVAL (LEAST(score + 1, 20) * 24) HOUR), next_review_at)
    ");
    
    if ($stmt->execute([$user_id, $card_id])) {
        echo json_encode(['status' => 'success']);
    } else {
        echo json_encode(['status' => 'error']);
    }
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

elseif ($action === 'update_settings') {
    $deck_id = (int)($input['deck_id'] ?? 0);
    $mode = $input['deck_mode'] === 'livro' ? 'livro' : 'aleatorio';

    if (!verifyDeckOwnership($pdo, $deck_id, $user_id)) {
        die(json_encode(['status' => 'error', 'message' => 'Acesso negado.']));
    }

    $stmt = $pdo->prepare("UPDATE directories SET deck_mode = ? WHERE id = ?");
    if ($stmt->execute([$mode, $deck_id])) {
         echo json_encode(['status' => 'success', 'message' => 'Configurações atualizadas.']);
    } else {
         echo json_encode(['status' => 'error', 'message' => 'Erro ao salvar.']);
    }
}

elseif ($action === 'add_single') {
    $deck_id = (int)($input['deck_id'] ?? 0);
    $front = trim($input['front'] ?? '');
    $back = trim($input['back'] ?? ''); 

    if ($deck_id === 0 || empty($front)) {
        die(json_encode(['status' => 'error', 'message' => 'Dados inválidos. A frente é obrigatória.']));
    }

    if (!verifyDeckOwnership($pdo, $deck_id, $user_id)) {
        die(json_encode(['status' => 'error', 'message' => 'Deck não encontrado ou sem permissão.']));
    }

    $front_enc = Security::encryptData($front);
    $back_enc = !empty($back) ? Security::encryptData($back) : null;

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
            
            if (!empty($front)) {
                $front_enc = Security::encryptData($front);
                $back_enc = !empty($back) ? Security::encryptData($back) : null;
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
