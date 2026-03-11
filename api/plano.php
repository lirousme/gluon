<?php
// Arquivo: plano.php
// Diretório: public_html/gluon/api/plano.php

require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    die(json_encode(['status' => 'error', 'message' => 'Não autorizado.']));
}

$pdo = Database::getConnection();
$user_id = $_SESSION['user_id'];
$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS plano_meta (
        directory_id INT UNSIGNED PRIMARY KEY,
        current_phase TINYINT UNSIGNED NOT NULL DEFAULT 1,
        phases_data JSON DEFAULT NULL,
        recurrence_rules JSON DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT fk_plano_directory FOREIGN KEY (directory_id) REFERENCES directories(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Exception $e) {
}

function verifyPlanoOwnership($pdo, $dir_id, $user_id) {
    $stmt = $pdo->prepare("SELECT id, name_encrypted, start_date, end_date, is_recurring FROM directories WHERE id = ? AND user_id = ? AND type = 7");
    $stmt->execute([$dir_id, $user_id]);
    return $stmt->fetch();
}

function moveDirectoryToNextRoundHour($pdo, $directory_id, $currentStartDate, $currentEndDate) {
    $brasiliaTz = new DateTimeZone('America/Sao_Paulo');
    $nextStart = new DateTime('now', $brasiliaTz);
    $nextStart->modify('+1 hour');
    $nextStart->setTime((int)$nextStart->format('H'), 0, 0);

    $durationSeconds = 3600;
    if (!empty($currentStartDate) && !empty($currentEndDate)) {
        try {
            $start = new DateTime($currentStartDate, $brasiliaTz);
            $end = new DateTime($currentEndDate, $brasiliaTz);
            $delta = $end->getTimestamp() - $start->getTimestamp();
            if ($delta > 0) {
                $durationSeconds = $delta;
            }
        } catch (Exception $e) {
        }
    }

    $nextEnd = clone $nextStart;
    $nextEnd->modify("+{$durationSeconds} seconds");

    $pdo->prepare("UPDATE directories SET start_date = ?, end_date = ? WHERE id = ?")
        ->execute([$nextStart->format('Y-m-d H:i:s'), $nextEnd->format('Y-m-d H:i:s'), $directory_id]);
}

function defaultPlanoPhases() {
    $defaults = [];
    for ($i = 1; $i <= 5; $i++) {
        $defaults[(string)$i] = ['brainstorm' => '', 'conclusion' => '', 'completed_at' => null];
    }
    return $defaults;
}

function defaultRecurrenceRules() {
    $defaults = [];
    for ($i = 1; $i <= 5; $i++) {
        $defaults[(string)$i] = [
            'is_recurring' => 0,
            'type' => '',
            'interval_value' => 1,
            'custom_dates' => '',
            'time_start' => '',
            'time_end' => '',
            'end_date' => ''
        ];
    }
    return $defaults;
}

function mergeWithDefaults($data, $defaults) {
    if (!is_array($data)) {
        return $defaults;
    }

    foreach ($defaults as $key => $value) {
        if (!array_key_exists($key, $data) || !is_array($data[$key])) {
            $data[$key] = $value;
        } else {
            $data[$key] = array_merge($value, $data[$key]);
        }
    }

    return $data;
}

function loadPlanoMeta($pdo, $directory_id) {
    $stmt = $pdo->prepare("SELECT current_phase, phases_data, recurrence_rules FROM plano_meta WHERE directory_id = ?");
    $stmt->execute([$directory_id]);
    $meta = $stmt->fetch();

    $phases = defaultPlanoPhases();
    $rules = defaultRecurrenceRules();
    $current_phase = 1;

    if ($meta) {
        $current_phase = max(1, min(5, (int)$meta['current_phase']));
        $decodedPhases = !empty($meta['phases_data']) ? json_decode($meta['phases_data'], true) : [];
        $decodedRules = !empty($meta['recurrence_rules']) ? json_decode($meta['recurrence_rules'], true) : [];
        $phases = mergeWithDefaults($decodedPhases, $phases);
        $rules = mergeWithDefaults($decodedRules, $rules);
    }

    return ['current_phase' => $current_phase, 'phases' => $phases, 'rules' => $rules];
}

function persistPlanoMeta($pdo, $directory_id, $current_phase, $phases, $rules) {
    $stmt = $pdo->prepare("INSERT INTO plano_meta (directory_id, current_phase, phases_data, recurrence_rules)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE current_phase = VALUES(current_phase), phases_data = VALUES(phases_data), recurrence_rules = VALUES(recurrence_rules)");
    $stmt->execute([$directory_id, $current_phase, json_encode($phases), json_encode($rules)]);
}

function calculateNextRunDatePlano($type, $interval, $custom_dates, $base_date, $time_start = null, $time_end = null) {
    $date = $base_date ? new DateTime($base_date) : new DateTime();
    $interval = (int)$interval > 0 ? (int)$interval : 1;

    if ($type === 'hourly') {
        $date->modify("+$interval hour");
        if ($time_start && $time_end) {
            $currentTimeStr = $date->format('H:i:s');
            if ($currentTimeStr >= $time_end) {
                $date->modify('+1 day');
                $date->setTime((int)substr($time_start, 0, 2), (int)substr($time_start, 3, 2), 0);
            } elseif ($currentTimeStr < $time_start) {
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
            foreach ($dates as $d) {
                $cd = new DateTime($d);
                if ($cd > $date) {
                    return $cd->format('Y-m-d H:i:s');
                }
            }
            return null;
        }
    }

    return $date->format('Y-m-d H:i:s');
}

function applyPhaseRecurrenceToDirectory($pdo, $directory_id, $rule, $fallbackStartDate) {
    $isRecurring = (int)($rule['is_recurring'] ?? 0) === 1;
    $type = trim($rule['type'] ?? '');
    if (!$isRecurring || $type === '') {
        $pdo->prepare("DELETE FROM directory_recurrences WHERE directory_id = ?")->execute([$directory_id]);
        $pdo->prepare("UPDATE directories SET is_recurring = 0 WHERE id = ?")->execute([$directory_id]);
        return;
    }

    $start_date = $fallbackStartDate ?: date('Y-m-d H:i:s');
    $interval = max(1, (int)($rule['interval_value'] ?? 1));
    $custom = trim($rule['custom_dates'] ?? '');
    $time_start = !empty($rule['time_start']) ? $rule['time_start'] . ':00' : null;
    $time_end = !empty($rule['time_end']) ? $rule['time_end'] . ':00' : null;
    $end_date = !empty($rule['end_date']) ? $rule['end_date'] . ' 23:59:59' : null;

    $next_run = calculateNextRunDatePlano($type, $interval, $custom, $start_date, $time_start, $time_end);

    $stmtEx = $pdo->prepare("SELECT exceptions FROM directory_recurrences WHERE directory_id = ?");
    $stmtEx->execute([$directory_id]);
    $existing = $stmtEx->fetch();
    $exceptions = $existing ? ($existing['exceptions'] ?? null) : null;

    $stmtRec = $pdo->prepare("INSERT INTO directory_recurrences (
            directory_id, type, interval_value, days_of_week, custom_dates, exceptions, time_start, time_end, end_date, next_run_date
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            type = VALUES(type), interval_value = VALUES(interval_value), days_of_week = VALUES(days_of_week),
            custom_dates = VALUES(custom_dates), exceptions = VALUES(exceptions), time_start = VALUES(time_start),
            time_end = VALUES(time_end), end_date = VALUES(end_date), next_run_date = VALUES(next_run_date)");

    $stmtRec->execute([$directory_id, $type, $interval, null, $custom ?: null, $exceptions, $time_start, $time_end, $end_date, $next_run]);
    $pdo->prepare("UPDATE directories SET is_recurring = 1, start_date = ? WHERE id = ?")->execute([$start_date, $directory_id]);
}

function mapDirectoryRecurrenceToPlanoRule($directoryRecurrence, $isRecurring) {
    return [
        'is_recurring' => $isRecurring ? 1 : 0,
        'type' => $directoryRecurrence['type'] ?? '',
        'interval_value' => isset($directoryRecurrence['interval_value']) ? max(1, (int)$directoryRecurrence['interval_value']) : 1,
        'custom_dates' => $directoryRecurrence['custom_dates'] ?? '',
        'time_start' => !empty($directoryRecurrence['time_start']) ? substr((string)$directoryRecurrence['time_start'], 0, 5) : '',
        'time_end' => !empty($directoryRecurrence['time_end']) ? substr((string)$directoryRecurrence['time_end'], 0, 5) : '',
        'end_date' => !empty($directoryRecurrence['end_date']) ? substr((string)$directoryRecurrence['end_date'], 0, 10) : ''
    ];
}

if ($action === 'fetch') {
    $directory_id = (int)($input['directory_id'] ?? 0);
    $dir = verifyPlanoOwnership($pdo, $directory_id, $user_id);

    if (!$dir) {
        die(json_encode(['status' => 'error', 'message' => 'Plano não encontrado.']));
    }

    $meta = loadPlanoMeta($pdo, $directory_id);

    if ((int)($dir['is_recurring'] ?? 0) === 1 && empty($meta['rules']['1']['type'])) {
        $stmtRule = $pdo->prepare("SELECT type, interval_value, custom_dates, time_start, time_end, end_date FROM directory_recurrences WHERE directory_id = ?");
        $stmtRule->execute([$directory_id]);
        $directoryRule = $stmtRule->fetch();
        if ($directoryRule) {
            $meta['rules']['1'] = mapDirectoryRecurrenceToPlanoRule($directoryRule, true);
            persistPlanoMeta($pdo, $directory_id, $meta['current_phase'], $meta['phases'], $meta['rules']);
        }
    }

    echo json_encode([
        'status' => 'success',
        'name' => Security::decryptData($dir['name_encrypted']),
        'current_phase' => $meta['current_phase'],
        'phases_data' => $meta['phases'],
        'recurrence_rules' => $meta['rules']
    ]);
}

elseif ($action === 'save_phase') {
    $directory_id = (int)($input['directory_id'] ?? 0);
    $phase = max(1, min(5, (int)($input['phase'] ?? 1)));
    $brainstorm = trim($input['brainstorm'] ?? '');
    $conclusion = trim($input['conclusion'] ?? '');

    $dir = verifyPlanoOwnership($pdo, $directory_id, $user_id);
    if (!$dir) {
        die(json_encode(['status' => 'error', 'message' => 'Plano não encontrado.']));
    }

    $meta = loadPlanoMeta($pdo, $directory_id);
    $meta['phases'][(string)$phase]['brainstorm'] = $brainstorm;
    $meta['phases'][(string)$phase]['conclusion'] = $conclusion;

    persistPlanoMeta($pdo, $directory_id, $meta['current_phase'], $meta['phases'], $meta['rules']);
    echo json_encode(['status' => 'success', 'message' => 'Fase salva.']);
}

elseif ($action === 'set_phase_recurrence') {
    $directory_id = (int)($input['directory_id'] ?? 0);
    $phase = max(1, min(5, (int)($input['phase'] ?? 1)));
    $rule = $input['rule'] ?? [];

    $dir = verifyPlanoOwnership($pdo, $directory_id, $user_id);
    if (!$dir) {
        die(json_encode(['status' => 'error', 'message' => 'Plano não encontrado.']));
    }

    $meta = loadPlanoMeta($pdo, $directory_id);
    $meta['rules'][(string)$phase] = array_merge(defaultRecurrenceRules()[(string)$phase], is_array($rule) ? $rule : []);

    if ($meta['current_phase'] === $phase) {
        applyPhaseRecurrenceToDirectory($pdo, $directory_id, $meta['rules'][(string)$phase], $dir['start_date']);
    }

    persistPlanoMeta($pdo, $directory_id, $meta['current_phase'], $meta['phases'], $meta['rules']);
    echo json_encode(['status' => 'success', 'message' => 'Repetição da fase salva.']);
}

elseif ($action === 'complete_phase') {
    $directory_id = (int)($input['directory_id'] ?? 0);
    $phase = max(1, min(5, (int)($input['phase'] ?? 1)));

    $dir = verifyPlanoOwnership($pdo, $directory_id, $user_id);
    if (!$dir) {
        die(json_encode(['status' => 'error', 'message' => 'Plano não encontrado.']));
    }

    try {
        $pdo->beginTransaction();

        $meta = loadPlanoMeta($pdo, $directory_id);
        $meta['phases'][(string)$phase]['completed_at'] = date('Y-m-d H:i:s');

        $nextPhase = min(5, $phase + 1);
        $meta['current_phase'] = $nextPhase;

        $rawName = Security::decryptData($dir['name_encrypted']);
        $cleanName = preg_replace('/^\[Fase\s+\d+\/5\]\s*/u', '', $rawName);
        $newName = "[Fase {$nextPhase}/5] " . $cleanName;

        $pdo->prepare("UPDATE directories SET name_encrypted = ? WHERE id = ? AND user_id = ?")
            ->execute([Security::encryptData($newName), $directory_id, $user_id]);

        $rule = $meta['rules'][(string)$nextPhase] ?? defaultRecurrenceRules()[(string)$nextPhase];
        $nextPhaseIsRecurring = (int)($rule['is_recurring'] ?? 0) === 1 && trim((string)($rule['type'] ?? '')) !== '';
        applyPhaseRecurrenceToDirectory($pdo, $directory_id, $rule, $dir['start_date']);

        if (!$nextPhaseIsRecurring) {
            moveDirectoryToNextRoundHour($pdo, $directory_id, $dir['start_date'] ?? null, $dir['end_date'] ?? null);
        }

        persistPlanoMeta($pdo, $directory_id, $meta['current_phase'], $meta['phases'], $meta['rules']);

        $pdo->commit();
        echo json_encode(['status' => 'success', 'message' => "Fase {$phase} concluída. Plano avançou para a fase {$nextPhase}.", 'current_phase' => $nextPhase, 'name' => $newName]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo json_encode(['status' => 'error', 'message' => 'Erro ao concluir fase.']);
    }
}

?>
