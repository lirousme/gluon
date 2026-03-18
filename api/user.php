<?php
// Arquivo: user.php
// Diretório: public_html/gluon/api/user.php

/**
 * API DE USUÁRIO
 * Pilar: Seguro e Fácil Manutenção.
 * Gerencia configurações da conta, perfil, dados, relacionamentos e deleção de segurança.
 */

require_once BASE_PATH . '/config/database.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    die(json_encode(['status' => 'error', 'message' => 'Não autorizado. Faça login.']));
}

$pdo = Database::getConnection();
$user_id = (int)$_SESSION['user_id'];
$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';

// Migrações fail-safe para recursos sociais
try { $pdo->exec("ALTER TABLE directories ADD COLUMN is_public TINYINT(1) NOT NULL DEFAULT 0 AFTER is_recurring"); } catch (PDOException $e) {}
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_follows (
        follower_id INT UNSIGNED NOT NULL,
        followed_id INT UNSIGNED NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (follower_id, followed_id),
        CONSTRAINT fk_follows_follower FOREIGN KEY (follower_id) REFERENCES users(id) ON DELETE CASCADE,
        CONSTRAINT fk_follows_followed FOREIGN KEY (followed_id) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_followed (followed_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (PDOException $e) {}

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS saved_directories (
        user_id INT UNSIGNED NOT NULL,
        directory_id INT UNSIGNED NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id, directory_id),
        CONSTRAINT fk_saved_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        CONSTRAINT fk_saved_directory FOREIGN KEY (directory_id) REFERENCES directories(id) ON DELETE CASCADE,
        INDEX idx_saved_directory (directory_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (PDOException $e) {}

try { $pdo->exec("ALTER TABLE users ADD COLUMN home_directory_id INT UNSIGNED NULL DEFAULT NULL AFTER copied_directory_id"); } catch (PDOException $e) {}
try { $pdo->exec("ALTER TABLE users ADD COLUMN source_directory_id INT UNSIGNED NULL DEFAULT NULL AFTER home_directory_id"); } catch (PDOException $e) {}

function ensureSourceDirectory(PDO $pdo, int $user_id): int {
    $stmtUser = $pdo->prepare("SELECT source_directory_id FROM users WHERE id = ? LIMIT 1");
    $stmtUser->execute([$user_id]);
    $source_directory_id = $stmtUser->fetchColumn();

    if ($source_directory_id) {
        $stmtDir = $pdo->prepare("SELECT id FROM directories WHERE id = ? AND user_id = ? LIMIT 1");
        $stmtDir->execute([(int)$source_directory_id, $user_id]);
        if ($stmtDir->fetchColumn()) {
            return (int)$source_directory_id;
        }
    }

    $stmtRootOrder = $pdo->prepare("SELECT COALESCE(MAX(sort_order), -1) + 1 FROM directories WHERE user_id = ? AND parent_id IS NULL");
    $stmtRootOrder->execute([$user_id]);
    $nextSortOrder = (int)$stmtRootOrder->fetchColumn();

    $sourceNameEncrypted = Security::encryptData('Anotações');
    $stmtCreate = $pdo->prepare("
        INSERT INTO directories (
            user_id, parent_id, type, name_encrypted, default_view,
            new_item_position, sort_order, icon, icon_color_from, icon_color_to
        ) VALUES (?, NULL, 1, ?, 'grid', 'end', ?, 'fa-note-sticky', '#0ea5e9', '#2563eb')
    ");
    $stmtCreate->execute([$user_id, $sourceNameEncrypted, $nextSortOrder]);
    $newDirectoryId = (int)$pdo->lastInsertId();

    $stmtUpdateUser = $pdo->prepare("UPDATE users SET source_directory_id = ? WHERE id = ?");
    $stmtUpdateUser->execute([$newDirectoryId, $user_id]);

    return $newDirectoryId;
}

if ($action === 'get_session') {
    echo json_encode(['status' => 'success', 'data' => [
        'id' => $user_id,
        'is_admin' => ($user_id === 1)
    ]]);
}

// === PREFERÊNCIAS DO DASHBOARD ===

else if ($action === 'get_prefs') {
    $stmt = $pdo->prepare("SELECT root_view, root_new_item_position, copied_directory_id, home_directory_id, source_directory_id FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();

    if ($user) {
        $source_directory_id = $user['source_directory_id'] !== null ? (int)$user['source_directory_id'] : ensureSourceDirectory($pdo, $user_id);
        echo json_encode(['status' => 'success', 'data' => [
            'root_view' => $user['root_view'] ?? 'grid',
            'root_new_item_position' => $user['root_new_item_position'] ?? 'end',
            'copied_directory_id' => $user['copied_directory_id'],
            'home_directory_id' => $user['home_directory_id'] !== null ? (int)$user['home_directory_id'] : null,
            'source_directory_id' => $source_directory_id
        ]]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Usuário não encontrado.']);
    }
}

elseif ($action === 'get_source_directory') {
    $source_directory_id = ensureSourceDirectory($pdo, $user_id);
    echo json_encode(['status' => 'success', 'data' => [
        'source_directory_id' => $source_directory_id
    ]]);
}

elseif ($action === 'update_root_prefs') {
    $view = in_array($input['view'] ?? '', ['grid', 'list', 'kanban']) ? $input['view'] : 'grid';
    $position = in_array($input['new_item_position'] ?? '', ['start', 'end']) ? $input['new_item_position'] : 'end';

    $stmt = $pdo->prepare("UPDATE users SET root_view = ?, root_new_item_position = ? WHERE id = ?");
    if ($stmt->execute([$view, $position, $user_id])) {
        echo json_encode(['status' => 'success', 'message' => 'Preferências da raiz atualizadas.']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Erro ao atualizar preferência.']);
    }
}

elseif ($action === 'copy_directory') {
    $dir_id = isset($input['dir_id']) && $input['dir_id'] !== null ? (int)$input['dir_id'] : null;

    if ($dir_id !== null) {
        $stmt = $pdo->prepare("SELECT id FROM directories WHERE id = ? AND user_id = ?");
        $stmt->execute([$dir_id, $user_id]);
        if (!$stmt->fetch()) {
            die(json_encode(['status' => 'error', 'message' => 'Diretório inválido ou sem permissão.']));
        }
    }

    $stmt = $pdo->prepare("UPDATE users SET copied_directory_id = ? WHERE id = ?");
    if ($stmt->execute([$dir_id, $user_id])) {
        echo json_encode(['status' => 'success', 'message' => 'Diretório copiado com sucesso!']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Erro ao copiar diretório.']);
    }
}

elseif ($action === 'set_home_directory') {
    $dir_id = isset($input['dir_id']) && $input['dir_id'] !== null ? (int)$input['dir_id'] : null;

    if ($dir_id === null) {
        $stmt = $pdo->prepare("UPDATE users SET home_directory_id = NULL WHERE id = ?");
        if ($stmt->execute([$user_id])) {
            echo json_encode(['status' => 'success', 'message' => 'Página inicial redefinida para o cofre raiz.', 'data' => ['home_directory_id' => null]]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Erro ao redefinir página inicial.']);
        }
        exit;
    }

    $stmtDir = $pdo->prepare("SELECT id FROM directories WHERE id = ? AND user_id = ? LIMIT 1");
    $stmtDir->execute([$dir_id, $user_id]);
    if (!$stmtDir->fetch()) {
        die(json_encode(['status' => 'error', 'message' => 'Diretório inválido ou sem permissão para definir como página inicial.']));
    }

    $stmt = $pdo->prepare("UPDATE users SET home_directory_id = ? WHERE id = ?");
    if ($stmt->execute([$dir_id, $user_id])) {
        echo json_encode(['status' => 'success', 'message' => 'Diretório definido como página inicial!', 'data' => ['home_directory_id' => $dir_id]]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Erro ao atualizar página inicial.']);
    }
}

// === PERFIL PÚBLICO / SOCIAL ===

elseif ($action === 'get_public_profile') {
    $target_user_id = (int)($input['target_user_id'] ?? 0);
    if ($target_user_id <= 0) {
        die(json_encode(['status' => 'error', 'message' => 'Usuário alvo inválido.']));
    }

    $hasDisplayName = false;
    $hasBio = false;
    $hasProfileImage = false;
    $possibleDisplayNameColumns = ['name', 'display_name', 'full_name'];
    $possibleBioColumns = ['bio', 'biography'];
    $possibleImageColumns = ['profile_image_url', 'avatar_url', 'photo_url'];

    $displayNameColumn = null;
    $bioColumn = null;
    $profileImageColumn = null;

    try {
        $columnStmt = $pdo->prepare("SHOW COLUMNS FROM users LIKE ?");

        foreach ($possibleDisplayNameColumns as $column) {
            $columnStmt->execute([$column]);
            if ($columnStmt->fetch()) {
                $hasDisplayName = true;
                $displayNameColumn = $column;
                break;
            }
        }

        foreach ($possibleBioColumns as $column) {
            $columnStmt->execute([$column]);
            if ($columnStmt->fetch()) {
                $hasBio = true;
                $bioColumn = $column;
                break;
            }
        }

        foreach ($possibleImageColumns as $column) {
            $columnStmt->execute([$column]);
            if ($columnStmt->fetch()) {
                $hasProfileImage = true;
                $profileImageColumn = $column;
                break;
            }
        }
    } catch (PDOException $e) {
        // Segue com campos básicos caso o banco não permita metadados (ex.: SHOW COLUMNS).
    }

    $selectParts = ['id', 'username'];
    if ($hasDisplayName && $displayNameColumn) $selectParts[] = "$displayNameColumn AS display_name";
    if ($hasBio && $bioColumn) $selectParts[] = "$bioColumn AS bio";
    if ($hasProfileImage && $profileImageColumn) $selectParts[] = "$profileImageColumn AS profile_image_url";

    $stmt = $pdo->prepare("SELECT " . implode(', ', $selectParts) . " FROM users WHERE id = ?");
    $stmt->execute([$target_user_id]);
    $target = $stmt->fetch();
    if (!$target) {
        die(json_encode(['status' => 'error', 'message' => 'Usuário não encontrado.']));
    }

    $is_following = 0;
    if ($target_user_id !== $user_id) {
        $stmtFollow = $pdo->prepare("SELECT 1 FROM user_follows WHERE follower_id = ? AND followed_id = ?");
        $stmtFollow->execute([$user_id, $target_user_id]);
        $is_following = $stmtFollow->fetchColumn() ? 1 : 0;
    }

    $stmtFollowers = $pdo->prepare("SELECT COUNT(*) FROM user_follows WHERE followed_id = ?");
    $stmtFollowers->execute([$target_user_id]);
    $followersCount = (int)$stmtFollowers->fetchColumn();

    $stmtFollowing = $pdo->prepare("SELECT COUNT(*) FROM user_follows WHERE follower_id = ?");
    $stmtFollowing->execute([$target_user_id]);
    $followingCount = (int)$stmtFollowing->fetchColumn();

    $stmtPublicDirectories = $pdo->prepare("SELECT COUNT(*) FROM directories WHERE user_id = ? AND is_public = 1");
    $stmtPublicDirectories->execute([$target_user_id]);
    $publicDirectoriesCount = (int)$stmtPublicDirectories->fetchColumn();

    echo json_encode(['status' => 'success', 'data' => [
        'id' => (int)$target['id'],
        'username' => $target['username'],
        'display_name' => $target['display_name'] ?? $target['username'],
        'bio' => $target['bio'] ?? '',
        'profile_image_url' => $target['profile_image_url'] ?? '',
        'followers_count' => $followersCount,
        'following_count' => $followingCount,
        'public_directories_count' => $publicDirectoriesCount,
        'is_following' => (int)$is_following
    ]]);
}

elseif ($action === 'toggle_follow') {
    $target_user_id = (int)($input['target_user_id'] ?? 0);
    if ($target_user_id <= 0 || $target_user_id === $user_id) {
        die(json_encode(['status' => 'error', 'message' => 'Não é possível seguir este usuário.']));
    }

    $stmtUser = $pdo->prepare("SELECT id FROM users WHERE id = ?");
    $stmtUser->execute([$target_user_id]);
    if (!$stmtUser->fetch()) {
        die(json_encode(['status' => 'error', 'message' => 'Usuário não encontrado.']));
    }

    $stmtCheck = $pdo->prepare("SELECT 1 FROM user_follows WHERE follower_id = ? AND followed_id = ?");
    $stmtCheck->execute([$user_id, $target_user_id]);

    if ($stmtCheck->fetchColumn()) {
        $pdo->prepare("DELETE FROM user_follows WHERE follower_id = ? AND followed_id = ?")->execute([$user_id, $target_user_id]);
        echo json_encode(['status' => 'success', 'data' => ['is_following' => 0], 'message' => 'Você deixou de seguir este usuário.']);
    } else {
        $pdo->prepare("INSERT INTO user_follows (follower_id, followed_id) VALUES (?, ?)")->execute([$user_id, $target_user_id]);
        echo json_encode(['status' => 'success', 'data' => ['is_following' => 1], 'message' => 'Agora você está seguindo este usuário.']);
    }
}

elseif ($action === 'get_following') {
    $target_user_id = isset($input['target_user_id']) ? (int)$input['target_user_id'] : (int)$user_id;
    if ($target_user_id <= 0) {
        die(json_encode(['status' => 'error', 'message' => 'Usuário alvo inválido.']));
    }

    $stmtTarget = $pdo->prepare("SELECT id FROM users WHERE id = ?");
    $stmtTarget->execute([$target_user_id]);
    if (!$stmtTarget->fetch()) {
        die(json_encode(['status' => 'error', 'message' => 'Usuário não encontrado.']));
    }

    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'profile_image_url'");
    $hasProfileImage = (bool)$stmt->fetch();
    $profileSelect = $hasProfileImage ? ', u.profile_image_url' : ", '' AS profile_image_url";

    $stmtFollowing = $pdo->prepare("\n        SELECT u.id, u.username{$profileSelect}\n        FROM user_follows uf\n        INNER JOIN users u ON u.id = uf.followed_id\n        WHERE uf.follower_id = ?\n        ORDER BY uf.created_at DESC, u.username ASC\n    ");
    $stmtFollowing->execute([$target_user_id]);

    echo json_encode(['status' => 'success', 'data' => $stmtFollowing->fetchAll()]);
}

elseif ($action === 'get_saved_status') {
    $directory_id = (int)($input['directory_id'] ?? 0);
    if ($directory_id <= 0) {
        die(json_encode(['status' => 'error', 'message' => 'Diretório inválido.']));
    }

    $stmtDir = $pdo->prepare("SELECT user_id, is_public FROM directories WHERE id = ?");
    $stmtDir->execute([$directory_id]);
    $dir = $stmtDir->fetch();
    if (!$dir || (int)$dir['is_public'] !== 1) {
        die(json_encode(['status' => 'error', 'message' => 'Diretório não disponível para salvar.']));
    }

    $stmtSaved = $pdo->prepare("SELECT 1 FROM saved_directories WHERE user_id = ? AND directory_id = ?");
    $stmtSaved->execute([$user_id, $directory_id]);
    echo json_encode(['status' => 'success', 'data' => ['is_saved' => $stmtSaved->fetchColumn() ? 1 : 0]]);
}

elseif ($action === 'toggle_save') {
    $directory_id = (int)($input['directory_id'] ?? 0);
    if ($directory_id <= 0) {
        die(json_encode(['status' => 'error', 'message' => 'Diretório inválido.']));
    }

    $stmtDir = $pdo->prepare("SELECT user_id, is_public FROM directories WHERE id = ?");
    $stmtDir->execute([$directory_id]);
    $dir = $stmtDir->fetch();

    if (!$dir || (int)$dir['is_public'] !== 1 || (int)$dir['user_id'] === $user_id) {
        die(json_encode(['status' => 'error', 'message' => 'Você só pode salvar diretórios públicos de outros usuários.']));
    }

    $stmtCheck = $pdo->prepare("SELECT 1 FROM saved_directories WHERE user_id = ? AND directory_id = ?");
    $stmtCheck->execute([$user_id, $directory_id]);

    if ($stmtCheck->fetchColumn()) {
        $pdo->prepare("DELETE FROM saved_directories WHERE user_id = ? AND directory_id = ?")->execute([$user_id, $directory_id]);
        echo json_encode(['status' => 'success', 'data' => ['is_saved' => 0], 'message' => 'Diretório removido dos salvos.']);
    } else {
        $pdo->prepare("INSERT INTO saved_directories (user_id, directory_id) VALUES (?, ?)")->execute([$user_id, $directory_id]);
        echo json_encode(['status' => 'success', 'data' => ['is_saved' => 1], 'message' => 'Diretório salvo com sucesso.']);
    }
}

// === GERENCIAMENTO DE PERFIL E CONTA ===

elseif ($action === 'get_profile') {
    $stmt = $pdo->prepare("SELECT username, email FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();

    if ($user) {
        echo json_encode(['status' => 'success', 'data' => $user]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Usuário não encontrado.']);
    }
}

elseif ($action === 'update_profile') {
    $username = trim($input['username'] ?? '');
    $email = trim($input['email'] ?? '');
    $current_password = $input['current_password'] ?? '';
    $new_password = $input['new_password'] ?? '';

    if (empty($username) || empty($email) || empty($current_password)) {
        die(json_encode(['status' => 'error', 'message' => 'Campos de username, e-mail e senha atual são obrigatórios.']));
    }

    $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($current_password, $user['password_hash'])) {
        die(json_encode(['status' => 'error', 'message' => 'A senha atual está incorreta.']));
    }

    $stmt = $pdo->prepare("SELECT id FROM users WHERE (username = ? OR email = ?) AND id != ?");
    $stmt->execute([$username, $email, $user_id]);
    if ($stmt->fetch()) {
        die(json_encode(['status' => 'error', 'message' => 'Username ou E-mail já está em uso por outra pessoa.']));
    }

    if (!empty($new_password)) {
        $hash = password_hash($new_password, PASSWORD_ARGON2ID);
        $stmt = $pdo->prepare("UPDATE users SET username = ?, email = ?, password_hash = ? WHERE id = ?");
        $stmt->execute([$username, $email, $hash, $user_id]);
    } else {
        $stmt = $pdo->prepare("UPDATE users SET username = ?, email = ? WHERE id = ?");
        $stmt->execute([$username, $email, $user_id]);
    }

    $_SESSION['username'] = $username;
    echo json_encode(['status' => 'success', 'message' => 'Perfil atualizado com sucesso!']);
}

elseif ($action === 'delete_account') {
    $password = $input['password'] ?? '';

    if (empty($password)) {
        die(json_encode(['status' => 'error', 'message' => 'A senha é obrigatória para excluir a conta.']));
    }

    $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        die(json_encode(['status' => 'error', 'message' => 'Senha incorreta. Ação de segurança cancelada.']));
    }

    $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");

    if ($stmt->execute([$user_id])) {
        session_unset();
        session_destroy();
        setcookie('gluon_remember', '', time() - 3600, "/", "", isset($_SERVER['HTTPS']), true);

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 3600, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }

        echo json_encode(['status' => 'success', 'message' => 'Conta e dados excluídos permanentemente.']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Erro interno ao excluir a conta.']);
    }
}

else {
    echo json_encode(['status' => 'error', 'message' => 'Ação inválida.']);
}
?>
