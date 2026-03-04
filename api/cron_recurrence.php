<?php
// Arquivo: cron_recurrence.php
// Diretório: public_html/gluon/api/cron_recurrence.php

/**
 * MOTOR DE RECORRÊNCIA AUTÓNOMO
 * Pilar: Escalabilidade e Rápido.
 * * Este ficheiro deve ser chamado via CRON Job do servidor (ex: a cada 5 minutos ou de hora a hora).
 * Ele varre as tarefas que atingiram a data de repetição, guarda um histórico e avança a tarefa original.
 * ATUALIZADO: Suporte a repetição contínua por hora dentro de uma janela limite diária.
 */

require_once __DIR__ . '/../config/database.php';

// =========================================================================
// 1. SEGURANÇA (Evitar execução pública acidental)
// =========================================================================
$secureToken = 'GLUON_CRON_SECURE_123'; 
$isCli = php_sapi_name() === 'cli';
$providedToken = $_GET['token'] ?? '';

if (!$isCli && $providedToken !== $secureToken) {
    http_response_code(403);
    die("Acesso não autorizado. Token inválido.");
}

$pdo = Database::getConnection();

// =========================================================================
// 2. FUNÇÕES AUXILIARES
// =========================================================================

/**
 * Calcula a próxima data de execução baseado no intervalo, tipo e limites.
 */
function calculateNextRunDateCron($type, $interval, $custom_dates, $base_date, $time_start = null, $time_end = null) {
    $date = $base_date ? new DateTime($base_date) : new DateTime();
    $interval = (int)$interval > 0 ? (int)$interval : 1;

    if ($type === 'hourly') {
        $date->modify("+$interval hour");

        // Checa se ultrapassou a janela diária do usuário
        if ($time_start && $time_end) {
            $currentTimeStr = $date->format('H:i:s');
            
            // Joga para o dia seguinte de manhã se ultrapassou a noite
            if ($currentTimeStr >= $time_end) {
                $date->modify('+1 day');
                $date->setTime((int)substr($time_start, 0, 2), (int)substr($time_start, 3, 2), 0);
            } 
            // Se de alguma forma caiu muito antes, puxa para frente (Apenas fallback)
            elseif ($currentTimeStr < $time_start) {
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
        $dates = json_decode($custom_dates, true);
        if (is_array($dates) && count($dates) > 0) {
            sort($dates);
            $now = new DateTime();
            foreach ($dates as $d) {
                $cd = new DateTime($d);
                if ($cd > $now) return $cd->format('Y-m-d H:i:s');
            }
            return null; // Acabaram as datas personalizadas
        }
    }
    return $date->format('Y-m-d H:i:s');
}

/**
 * Duplica uma diretoria (e as suas subdiretorias) como um item de HISTÓRICO.
 */
function duplicateAsHistory($source_id, $target_parent_id, $pdo) {
    $stmt = $pdo->prepare("SELECT * FROM directories WHERE id = ?");
    $stmt->execute([$source_id]);
    $sourceDir = $stmt->fetch();
    
    if (!$sourceDir) return false;

    $is_recurring = 0; 
    
    $stmtInsert = $pdo->prepare("INSERT INTO directories (user_id, parent_id, target_id, type, name_encrypted, default_view, new_item_position, sort_order, icon, icon_color_from, icon_color_to, cover_url_encrypted, start_date, end_date, is_recurring) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    
    $stmtInsert->execute([
        $sourceDir['user_id'],
        $target_parent_id,
        $sourceDir['target_id'],
        $sourceDir['type'],
        $sourceDir['name_encrypted'],
        $sourceDir['default_view'],
        $sourceDir['new_item_position'],
        $sourceDir['sort_order'],
        $sourceDir['icon'],
        $sourceDir['icon_color_from'],
        $sourceDir['icon_color_to'],
        $sourceDir['cover_url_encrypted'],
        $sourceDir['start_date'],
        $sourceDir['end_date'],
        $is_recurring
    ]);
    
    $newDirId = $pdo->lastInsertId();

    if ((int)$sourceDir['type'] === 1) {
        $stmtCode = $pdo->prepare("SELECT language, content_encrypted FROM files_code WHERE directory_id = ?");
        $stmtCode->execute([$source_id]);
        $codeData = $stmtCode->fetch();
        if ($codeData) {
            $stmtInsertCode = $pdo->prepare("INSERT INTO files_code (directory_id, language, content_encrypted) VALUES (?, ?, ?)");
            $stmtInsertCode->execute([$newDirId, $codeData['language'], $codeData['content_encrypted']]);
        }
    }

    $stmtChildren = $pdo->prepare("SELECT id FROM directories WHERE parent_id = ?");
    $stmtChildren->execute([$source_id]);
    $children = $stmtChildren->fetchAll();
    
    foreach ($children as $child) {
        duplicateAsHistory($child['id'], $newDirId, $pdo);
    }

    return $newDirId;
}

// =========================================================================
// 3. PROCESSAMENTO PRINCIPAL
// =========================================================================

echo "Iniciando processamento de recorrências...\n";

$stmt = $pdo->query("SELECT dr.*, d.parent_id, d.start_date as orig_start, d.end_date as orig_end 
                     FROM directory_recurrences dr 
                     JOIN directories d ON dr.directory_id = d.id 
                     WHERE dr.next_run_date <= NOW() 
                     LIMIT 100");
$dueTasks = $stmt->fetchAll();

$processedCount = 0;

foreach ($dueTasks as $task) {
    try {
        $pdo->beginTransaction();

        $origId = $task['directory_id'];
        $parentId = $task['parent_id'];
        $new_start_str = $task['next_run_date']; 
        
        // 1. Criar um clone da tarefa na data antiga (Histórico)
        duplicateAsHistory($origId, $parentId, $pdo);

        // 2. Calcular as novas datas da tarefa original
        $new_end_str = null;
        if ($task['orig_start'] && $task['orig_end']) {
            $startObj = new DateTime($task['orig_start']);
            $endObj = new DateTime($task['orig_end']);
            
            $diff = $startObj->diff($endObj);
            
            $newStartObj = new DateTime($new_start_str);
            $newEndObj = clone $newStartObj;
            $newEndObj->add($diff);
            $new_end_str = $newEndObj->format('Y-m-d H:i:s');
        }

        // 3. Atualizar a tarefa original com as novas datas
        $updDir = $pdo->prepare("UPDATE directories SET start_date = ?, end_date = ? WHERE id = ?");
        $updDir->execute([$new_start_str, $new_end_str, $origId]);

        // 4. Calcular qual será a próxima data
        $next_run = calculateNextRunDateCron(
            $task['type'], 
            $task['interval_value'], 
            $task['custom_dates'], 
            $new_start_str,
            $task['time_start'] ?? null,
            $task['time_end'] ?? null
        );

        // 5. Verificar se a recorrência chegou ao fim
        $stopRecurrence = false;
        if (!$next_run) {
            $stopRecurrence = true;
        } elseif ($task['end_date']) {
            $nextRunObj = new DateTime($next_run);
            $recEndObj = new DateTime($task['end_date']);
            if ($nextRunObj > $recEndObj) {
                $stopRecurrence = true;
            }
        }

        // 6. Atualizar ou remover a regra
        if ($stopRecurrence) {
            $pdo->prepare("DELETE FROM directory_recurrences WHERE directory_id = ?")->execute([$origId]);
            $pdo->prepare("UPDATE directories SET is_recurring = 0 WHERE id = ?")->execute([$origId]);
            echo "Recorrência terminada para a tarefa ID: {$origId}\n";
        } else {
            $pdo->prepare("UPDATE directory_recurrences SET next_run_date = ? WHERE directory_id = ?")->execute([$next_run, $origId]);
            echo "Tarefa ID: {$origId} avançada. Próxima execução: {$next_run}\n";
        }

        $pdo->commit();
        $processedCount++;

    } catch (Exception $e) {
        $pdo->rollBack();
        echo "ERRO ao processar a tarefa ID: {$task['directory_id']} - " . $e->getMessage() . "\n";
    }
}

echo "Processamento concluído. Tarefas avançadas: {$processedCount}\n";
?>
