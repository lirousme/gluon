<?php
// Arquivo: schedule.php
// Diretório: public_html/gluon/api/schedule.php

/**
 * MICRO-API DA AGENDA / SCHEDULE
 * Pilar: Rápido e Fácil Manutenção.
 * Separa a responsabilidade de atualizar tempos na linha do tempo e visualizações.
 */

require_once BASE_PATH . '/config/database.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    die(json_encode(['status' => 'error', 'message' => 'Não autorizado. Faça login.']));
}

$pdo = Database::getConnection();
$user_id = $_SESSION['user_id'];
$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';


$brasiliaTz = new DateTimeZone('America/Sao_Paulo');

function nowInBrasilia(?DateTimeZone $tz = null): DateTime {
    $timezone = $tz ?: new DateTimeZone('America/Sao_Paulo');
    return new DateTime('now', $timezone);
}

function shiftTimeKeepingWindow(?string $oldStart, ?string $oldEnd, string $newStart): ?string {
    if (!$oldStart || !$oldEnd) return null;

    $oldStartObj = DateTime::createFromFormat('H:i:s', substr($oldStart, 0, 8));
    $oldEndObj = DateTime::createFromFormat('H:i:s', substr($oldEnd, 0, 8));
    $newStartObj = DateTime::createFromFormat('H:i:s', substr($newStart, 0, 8));

    if (!$oldStartObj || !$oldEndObj || !$newStartObj) return null;

    $windowSeconds = $oldEndObj->getTimestamp() - $oldStartObj->getTimestamp();
    if ($windowSeconds <= 0) $windowSeconds = 3600;

    $newEndObj = clone $newStartObj;
    $newEndObj->modify("+{$windowSeconds} seconds");
    return $newEndObj->format('H:i:s');
}

function normalizeExceptionValue(string $recType, ?string $contextStart): ?string {
    if (!$contextStart) return null;
    try {
        $ctx = new DateTime($contextStart);
        return in_array($recType, ['hourly', 'minutely'], true) ? $ctx->format('Y-m-d H:i:s') : $ctx->format('Y-m-d');
    } catch (Throwable $e) {
        return null;
    }
}

function appendRecurrenceException(PDO $pdo, int $directoryId, string $exceptionValue): void {
    $stmtEx = $pdo->prepare("SELECT exceptions FROM directory_recurrences WHERE directory_id = ? FOR UPDATE");
    $stmtEx->execute([$directoryId]);
    $existingRaw = $stmtEx->fetchColumn();

    $exceptions = [];
    if (!empty($existingRaw)) {
        $decoded = json_decode($existingRaw, true);
        if (is_array($decoded)) $exceptions = $decoded;
    }

    if (!in_array($exceptionValue, $exceptions, true)) {
        $exceptions[] = $exceptionValue;
        $stmtUpd = $pdo->prepare("UPDATE directory_recurrences SET exceptions = ? WHERE directory_id = ?");
        $stmtUpd->execute([json_encode(array_values($exceptions)), $directoryId]);
    }
}

function createDetachedOccurrence(PDO $pdo, int $directoryId, int $userId, string $startDate, ?string $endDate): void {
    $stmtClone = $pdo->prepare(
        "INSERT INTO directories (
            user_id, parent_id, target_id, type, name_encrypted, default_view,
            deck_mode, new_item_position, sort_order, icon, icon_color_from, icon_color_to,
            cover_url_encrypted, start_date, end_date, is_recurring, is_public
        )
        SELECT
            user_id, parent_id, target_id, type, name_encrypted, default_view,
            deck_mode, new_item_position, sort_order, icon, icon_color_from, icon_color_to,
            cover_url_encrypted, ?, ?, 0, is_public
        FROM directories
        WHERE id = ? AND user_id = ?"
    );
    $stmtClone->execute([$startDate, $endDate, $directoryId, $userId]);
}

function addIntervalByType(DateTime $date, string $type, int $interval): DateTime {
    $next = clone $date;
    if ($type === 'daily') $next->modify("+{$interval} day");
    elseif ($type === 'weekly') $next->modify("+{$interval} week");
    elseif ($type === 'monthly') $next->modify("+{$interval} month");
    elseif ($type === 'yearly') $next->modify("+{$interval} year");
    return $next;
}

function appendUniqueExceptions(PDO $pdo, int $directoryId, array $newExceptions): int {
    if (empty($newExceptions)) return 0;

    $stmt = $pdo->prepare("SELECT exceptions FROM directory_recurrences WHERE directory_id = ? FOR UPDATE");
    $stmt->execute([$directoryId]);
    $raw = $stmt->fetchColumn();

    $existing = [];
    if (!empty($raw)) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) $existing = $decoded;
    }

    $added = 0;
    foreach ($newExceptions as $exceptionValue) {
        if (!in_array($exceptionValue, $existing, true)) {
            $existing[] = $exceptionValue;
            $added++;
        }
    }

    if ($added > 0) {
        $pdo->prepare("UPDATE directory_recurrences SET exceptions = ? WHERE directory_id = ?")
            ->execute([json_encode(array_values($existing)), $directoryId]);
    }

    return $added;
}

function collectOverdueRecurrenceExceptions(array $task, DateTime $now): array {
    $exceptions = [];
    $tz = new DateTimeZone('America/Sao_Paulo');

    if (empty($task['start_date'])) return $exceptions;

    $start = new DateTime($task['start_date'], $tz);
    $end = !empty($task['end_date']) ? new DateTime($task['end_date'], $tz) : clone $start;
    $durationSeconds = max(0, $end->getTimestamp() - $start->getTimestamp());

    $type = $task['rec_type'] ?? '';
    $interval = max(1, (int)($task['rec_interval'] ?? 1));
    $recurrenceEnd = !empty($task['rec_end']) ? new DateTime($task['rec_end'], $tz) : null;

    if ($type === 'hourly' || $type === 'minutely') {
        $timeStart = !empty($task['rec_time_start']) ? substr($task['rec_time_start'], 0, 5) : $start->format('H:i');
        $timeEnd = !empty($task['rec_time_end']) ? substr($task['rec_time_end'], 0, 5) : '23:59';

        [$sHour, $sMin] = array_map('intval', explode(':', $timeStart));
        [$eHour, $eMin] = array_map('intval', explode(':', $timeEnd));
        $windowStartMin = ($sHour * 60) + $sMin;
        $windowEndMin = ($eHour * 60) + $eMin;

        $dayCursor = new DateTime($start->format('Y-m-d') . ' 00:00:00', $tz);
        $maxLoops = 2000;
        $loops = 0;

        while ($dayCursor <= $now && $loops < $maxLoops) {
            if ($recurrenceEnd && $dayCursor > $recurrenceEnd) break;

            $isPastDay = $dayCursor->format('Y-m-d') < $now->format('Y-m-d');
            if ($isPastDay) {
                $exceptions[] = $dayCursor->format('Y-m-d');
            } else {
                $stepMinutes = $type === 'minutely' ? $interval : $interval * 60;
                for ($mins = $windowStartMin; $mins <= $windowEndMin; $mins += $stepMinutes) {
                    $hour = (int)floor($mins / 60);
                    $min = $mins % 60;
                    $occurrenceStart = new DateTime($dayCursor->format('Y-m-d') . sprintf(' %02d:%02d:00', $hour, $min), $tz);
                    $occurrenceEnd = (clone $occurrenceStart)->modify("+{$durationSeconds} seconds");

                    if ($occurrenceEnd < $now) {
                        $exceptions[] = $occurrenceStart->format('Y-m-d H:i:s');
                    }
                }
            }

            $dayCursor->modify('+1 day');
            $loops++;
        }

        return array_values(array_unique($exceptions));
    }

    if ($type === 'custom') {
        $customDates = json_decode($task['rec_custom'] ?? '[]', true);
        if (!is_array($customDates)) return $exceptions;

        foreach ($customDates as $dateStr) {
            try {
                $occurrenceStart = new DateTime($dateStr . ' ' . $start->format('H:i:s'), $tz);
                if ($recurrenceEnd && $occurrenceStart > $recurrenceEnd) continue;

                $occurrenceEnd = (clone $occurrenceStart)->modify("+{$durationSeconds} seconds");
                if ($occurrenceEnd < $now) {
                    $exceptions[] = $occurrenceStart->format('Y-m-d');
                }
            } catch (Throwable $e) {
                continue;
            }
        }

        return array_values(array_unique($exceptions));
    }

    if (!in_array($type, ['daily', 'weekly', 'monthly', 'yearly'], true)) {
        return $exceptions;
    }

    $cursor = clone $start;
    $maxLoops = 5000;
    $loops = 0;
    while ($cursor <= $now && $loops < $maxLoops) {
        if ($recurrenceEnd && $cursor > $recurrenceEnd) break;

        $occurrenceEnd = (clone $cursor)->modify("+{$durationSeconds} seconds");
        if ($occurrenceEnd < $now) {
            $exceptions[] = $cursor->format('Y-m-d');
        }

        $cursor = addIntervalByType($cursor, $type, $interval);
        $loops++;
    }

    return array_values(array_unique($exceptions));
}

if ($action === 'update_times') {
    $id = (int)($input['id'] ?? 0);
    $start_date = !empty($input['start_date']) ? $input['start_date'] : null;
    $end_date = !empty($input['end_date']) ? $input['end_date'] : null;
    $context_start = !empty($input['context_start']) ? $input['context_start'] : null;
    $context_end = !empty($input['context_end']) ? $input['context_end'] : null;

    if ($id === 0) {
        die(json_encode(['status' => 'error', 'message' => 'ID de tarefa inválido.']));
    }

    try {
        $pdo->beginTransaction();

        $stmtBefore = $pdo->prepare("SELECT start_date, end_date, is_recurring FROM directories WHERE id = ? AND user_id = ? FOR UPDATE");
        $stmtBefore->execute([$id, $user_id]);
        $before = $stmtBefore->fetch(PDO::FETCH_ASSOC);

        if (!$before) {
            $pdo->rollBack();
            die(json_encode(['status' => 'error', 'message' => 'Tarefa não encontrada.']));
        }

        $stmtRec = $pdo->prepare("SELECT type, time_start, time_end FROM directory_recurrences WHERE directory_id = ? FOR UPDATE");
        $stmtRec->execute([$id]);
        $rec = $stmtRec->fetch(PDO::FETCH_ASSOC);

        $isRecurring = ((int)($before['is_recurring'] ?? 0) === 1) && $rec;
        $contextIsDifferent = $context_start && $before['start_date'] && ($context_start !== $before['start_date'] || ($context_end && $before['end_date'] && $context_end !== $before['end_date']));

        // Ao mover uma ocorrência projetada de tarefa recorrente, destacamos a ocorrência.
        if ($isRecurring && $contextIsDifferent && $start_date) {
            $exceptionValue = normalizeExceptionValue($rec['type'] ?? '', $context_start);
            if ($exceptionValue) {
                appendRecurrenceException($pdo, $id, $exceptionValue);
            }

            createDetachedOccurrence($pdo, $id, $user_id, $start_date, $end_date);
            $pdo->commit();
            echo json_encode(['status' => 'success', 'message' => 'Ocorrência recorrente destacada e atualizada com sucesso.']);
            exit;
        }

        $stmt = $pdo->prepare("UPDATE directories SET start_date = ?, end_date = ? WHERE id = ? AND user_id = ?");
        $stmt->execute([$start_date, $end_date, $id, $user_id]);

        if ($rec && $start_date) {
            $newStartTime = (new DateTime($start_date))->format('H:i:s');
            $newEndTime = $end_date ? (new DateTime($end_date))->format('H:i:s') : null;

            if (in_array($rec['type'], ['hourly', 'minutely'], true)) {
                $newEndTime = shiftTimeKeepingWindow($rec['time_start'], $rec['time_end'], $newStartTime);
                if (!$newEndTime && $end_date) {
                    $newEndTime = (new DateTime($end_date))->format('H:i:s');
                }
            }

            $stmtUpdRec = $pdo->prepare("UPDATE directory_recurrences SET time_start = ?, time_end = COALESCE(?, time_end) WHERE directory_id = ?");
            $stmtUpdRec->execute([$newStartTime, $newEndTime, $id]);
        }

        $pdo->commit();
        echo json_encode(['status' => 'success', 'message' => 'Horário atualizado com sucesso.']);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['status' => 'error', 'message' => 'Erro ao atualizar horário.']);
    }
} 
else if ($action === 'update_view') {
    $id = (int)($input['id'] ?? 0);
    // Aceita as 3 opções de view da Agenda
    $view = in_array($input['view'] ?? '', ['timeline', 'kanban', 'list']) ? $input['view'] : 'timeline';

    if ($id === 0) {
        die(json_encode(['status' => 'error', 'message' => 'ID de agenda inválido.']));
    }

    // Atualiza a view salva diretamente na pasta da agenda (Type 2)
    $stmt = $pdo->prepare("UPDATE directories SET default_view = ? WHERE id = ? AND user_id = ? AND type = 2");
    
    if ($stmt->execute([$view, $id, $user_id])) {
        echo json_encode(['status' => 'success', 'message' => 'Preferência de visualização salva.']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Erro ao atualizar visualização.']);
    }
}
else if ($action === 'delete_overdue_tasks') {
    $agenda_id = (int)($input['id'] ?? 0);

    if ($agenda_id === 0) {
        die(json_encode(['status' => 'error', 'message' => 'ID de agenda inválido.']));
    }

    $stmtAgenda = $pdo->prepare("SELECT id FROM directories WHERE id = ? AND user_id = ? AND type = 2");
    $stmtAgenda->execute([$agenda_id, $user_id]);
    if (!$stmtAgenda->fetchColumn()) {
        die(json_encode(['status' => 'error', 'message' => 'Agenda não encontrada.']));
    }

    $deleted = 0;
    $recurrenceOccurrencesRemoved = 0;
    $now = nowInBrasilia($brasiliaTz);

    try {
        $pdo->beginTransaction();

        $stmtDelete = $pdo->prepare(
            "DELETE FROM directories
             WHERE user_id = ?
               AND parent_id = ?
               AND is_recurring = 0
               AND COALESCE(end_date, start_date) IS NOT NULL
               AND COALESCE(end_date, start_date) < ?"
        );
        $stmtDelete->execute([$user_id, $agenda_id, $now->format('Y-m-d H:i:s')]);
        $deleted = $stmtDelete->rowCount();

        $stmtRecurring = $pdo->prepare(
            "SELECT d.id, d.start_date, d.end_date,
                    dr.type as rec_type, dr.interval_value as rec_interval,
                    dr.custom_dates as rec_custom, dr.time_start as rec_time_start,
                    dr.time_end as rec_time_end, dr.end_date as rec_end
             FROM directories d
             INNER JOIN directory_recurrences dr ON dr.directory_id = d.id
             WHERE d.user_id = ?
               AND d.parent_id = ?
               AND d.is_recurring = 1"
        );
        $stmtRecurring->execute([$user_id, $agenda_id]);
        $recurringTasks = $stmtRecurring->fetchAll(PDO::FETCH_ASSOC);

        foreach ($recurringTasks as $task) {
            $newExceptions = collectOverdueRecurrenceExceptions($task, $now);
            $recurrenceOccurrencesRemoved += appendUniqueExceptions($pdo, (int)$task['id'], $newExceptions);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        die(json_encode(['status' => 'error', 'message' => 'Erro ao apagar tarefas vencidas.']));
    }

    echo json_encode([
        'status' => 'success',
        'message' => ($deleted + $recurrenceOccurrencesRemoved) > 0
            ? "{$deleted} tarefa(s) vencida(s) apagada(s) e {$recurrenceOccurrencesRemoved} ocorrência(s) recorrente(s) vencida(s) removida(s)."
            : 'Nenhuma tarefa vencida para apagar.'
    ]);
}

else if ($action === 'get_flashcard_due_directories') {
    $stmt = $pdo->prepare(
        "SELECT d.id, d.type, d.name_encrypted, d.icon, d.icon_color_from, d.icon_color_to, d.cover_url_encrypted,
                MIN(fs.next_review_at) AS oldest_review_at,
                COUNT(fs.flashcard_id) AS due_cards
         FROM directories d
         INNER JOIN flashcards f ON f.directory_id = d.id
         INNER JOIN flashcard_scores fs ON fs.flashcard_id = f.id
         WHERE d.user_id = ?
           AND d.type = 4
           AND fs.user_id = ?
           AND fs.next_review_at IS NOT NULL
           AND fs.next_review_at <= NOW()
         GROUP BY d.id, d.type, d.name_encrypted, d.icon, d.icon_color_from, d.icon_color_to, d.cover_url_encrypted
         HAVING due_cards > 0
         ORDER BY oldest_review_at ASC"
    );
    $stmt->execute([$user_id, $user_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $response = array_map(function ($row) {
        return [
            'id' => (int)$row['id'],
            'type' => (int)$row['type'],
            'name' => Security::decryptData($row['name_encrypted']),
            'icon' => $row['icon'] ?? 'fa-book',
            'color_from' => $row['icon_color_from'] ?? '#3b82f6',
            'color_to' => $row['icon_color_to'] ?? '#6366f1',
            'cover_url' => !empty($row['cover_url_encrypted']) ? Security::decryptData($row['cover_url_encrypted']) : '',
            'oldest_review_at' => $row['oldest_review_at'],
            'due_cards' => (int)$row['due_cards']
        ];
    }, $rows);

    echo json_encode(['status' => 'success', 'data' => $response]);
}

else if ($action === 'get_agenda_info') {
    // Busca informações básicas da pasta Agenda atual (Nome, Capa e View Preferida)
    $id = (int)($input['id'] ?? 0);
    $stmt = $pdo->prepare("SELECT id, type, name_encrypted, icon, icon_color_from, icon_color_to, default_view, cover_url_encrypted FROM directories WHERE id = ? AND user_id = ? AND type = 2");
    $stmt->execute([$id, $user_id]);
    $agenda = $stmt->fetch();

    if($agenda) {
        echo json_encode([
            'status' => 'success', 
            'data' => [
                'id' => $agenda['id'],
                'type' => (int)$agenda['type'],
                'name' => Security::decryptData($agenda['name_encrypted']),
                'icon' => $agenda['icon'],
                'color_from' => $agenda['icon_color_from'],
                'color_to' => $agenda['icon_color_to'],
                'view' => $agenda['default_view'] ?? 'timeline',
                'cover_url' => !empty($agenda['cover_url_encrypted']) ? Security::decryptData($agenda['cover_url_encrypted']) : ''
            ]
        ]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Agenda não encontrada.']);
    }
}
else {
    echo json_encode(['status' => 'error', 'message' => 'Ação inválida.']);
}
?>
