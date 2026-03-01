<?php
// Arquivo: flashcards.php
// Diretório: public_html/gluon/api/flashcards.php

/**
 * MICRO-API DE FLASHCARDS
 * Pilar: Seguro, Rápido e Separação de Responsabilidades.
 * Gerencia a inserção, leitura e criptografia dos Flashcards.
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

    $stmt = $pdo->prepare("SELECT id, front_encrypted, back_encrypted FROM flashcards WHERE directory_id = ? ORDER BY sort_order ASC, id ASC");
    $stmt->execute([$deck_id]);
    $cards = $stmt->fetchAll();

    $response = [];
    foreach ($cards as $card) {
        $response[] = [
            'id' => $card['id'],
            'front' => Security::decryptData($card['front_encrypted']),
            'back' => Security::decryptData($card['back_encrypted'])
        ];
    }

    echo json_encode([
        'status' => 'success', 
        'deck_name' => Security::decryptData($deck['name_encrypted']),
        'data' => $response
    ]);
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
