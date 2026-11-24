<?php
declare(strict_types=1);

session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/db.php';

try {
    $pdo = getDb();
    $action = $_GET['action'] ?? '';
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $input = [];
    if ($method === 'POST') {
        $raw = file_get_contents('php://input');
        if ($raw) {
            $input = json_decode($raw, true) ?: [];
        }
        if (empty($input) && !empty($_POST)) {
            $input = $_POST;
        }
    }

    if (!$action && isset($input['action'])) {
        $action = (string)$input['action'];
    }

    switch ($action) {
        case 'voter_state': {
            $voterId = trim((string)($_GET['voter_id'] ?? $input['voter_id'] ?? ''));
            if ($voterId !== '' && strlen($voterId) <= 128) {
                $stmt = $pdo->prepare("
                    INSERT INTO voters (voter_id, last_seen) VALUES (?, CURRENT_TIMESTAMP)
                    ON CONFLICT(voter_id) DO UPDATE SET last_seen = CURRENT_TIMESTAMP
                ");
                $stmt->execute([$voterId]);
            }

            $activePollId = (int)getSetting($pdo, 'active_poll_id', '0');
            $unitName = getSetting($pdo, 'unit_name', 'Středisko / Jednotka');

            $poll = null;
            $myVote = null;
            $results = null;
            $voteCount = 0;

            if ($activePollId > 0) {
                $stmt = $pdo->prepare("SELECT * FROM polls WHERE id = ?");
                $stmt->execute([$activePollId]);
                $pollRow = $stmt->fetch();

                if ($pollRow) {
                    $poll = [
                        'id' => (int)$pollRow['id'],
                        'title' => $pollRow['title'],
                        'description' => $pollRow['description'],
                        'type' => $pollRow['type'],
                        'options' => json_decode($pollRow['options_json'], true) ?: [],
                        'status' => $pollRow['status'],
                        'show_results_to_users' => (bool)$pollRow['show_results_to_users']
                    ];

                    $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM votes WHERE poll_id = ?");
                    $cntStmt->execute([$activePollId]);
                    $voteCount = (int)$cntStmt->fetchColumn();

                    if ($voterId !== '') {
                        $vStmt = $pdo->prepare("SELECT payload_json, updated_at FROM votes WHERE poll_id = ? AND voter_id = ?");
                        $vStmt->execute([$activePollId, $voterId]);
                        $vRow = $vStmt->fetch();
                        if ($vRow) {
                            $myVote = [
                                'payload' => json_decode($vRow['payload_json'], true),
                                'updated_at' => $vRow['updated_at']
                            ];
                        }
                    }

                    if ($poll['status'] === 'closed' && $poll['show_results_to_users']) {
                        $results = computePollResults($pdo, $pollRow);
                    }
                }
            }

            $onlineVoters = (int)$pdo->query("SELECT COUNT(*) FROM voters WHERE last_seen >= datetime('now', '-25 seconds')")->fetchColumn();

            echo json_encode([
                'ok' => true,
                'unit_name' => $unitName,
                'active_poll_id' => $activePollId,
                'poll' => $poll,
                'my_vote' => $myVote,
                'vote_count' => $voteCount,
                'online_voters' => $onlineVoters,
                'results' => $results
            ], JSON_UNESCAPED_UNICODE);
            break;
        }

        case 'submit_vote': {
            $voterId = trim((string)($input['voter_id'] ?? ''));
            $pollId = (int)($input['poll_id'] ?? 0);
            $payload = $input['payload'] ?? null;

            if ($voterId === '' || strlen($voterId) > 128) {
                throw new RuntimeException('Chybí platný identifikátor hlasujícího (voter_id).');
            }
            if ($pollId <= 0 || !is_array($payload)) {
                throw new RuntimeException('Neplatná data hlasování.');
            }

            $activePollId = (int)getSetting($pdo, 'active_poll_id', '0');
            if ($pollId !== $activePollId) {
                throw new RuntimeException('Toto hlasování již není aktuálně vybrané.');
            }

            $stmt = $pdo->prepare("SELECT * FROM polls WHERE id = ?");
            $stmt->execute([$pollId]);
            $pollRow = $stmt->fetch();
            if (!$pollRow) {
                throw new RuntimeException('Hlasování nebylo nalezeno.');
            }
            if ($pollRow['status'] !== 'active') {
                throw new RuntimeException('Hlasování již bylo ukončeno nebo ještě nezačalo.');
            }

            $options = json_decode($pollRow['options_json'], true) ?: [];
            $validIds = array_column($options, 'id');
            $type = $pollRow['type'];

            $cleanPayload = [];
            if ($type === 'single') {
                $choice = (string)($payload['choice'] ?? '');
                if (!in_array($choice, $validIds, true)) {
                    throw new RuntimeException('Vyberte platnou možnost.');
                }
                $cleanPayload = ['choice' => $choice];
            } elseif ($type === 'multi') {
                $choices = $payload['choices'] ?? [];
                if (!is_array($choices) || empty($choices)) {
                    throw new RuntimeException('Vyberte alespoň jednu možnost.');
                }
                $filtered = [];
                foreach ($choices as $c) {
                    $c = (string)$c;
                    if (in_array($c, $validIds, true) && !in_array($c, $filtered, true)) {
                        $filtered[] = $c;
                    }
                }
                if (empty($filtered)) {
                    throw new RuntimeException('Vyberte alespoň jednu platnou možnost.');
                }
                $cleanPayload = ['choices' => $filtered];
            } elseif ($type === 'ranking' || $type === 'ranking_partial') {
                $ranking = $payload['ranking'] ?? [];
                if (!is_array($ranking)) {
                    throw new RuntimeException('Neplatné pořadí možností.');
                }
                $filtered = [];
                foreach ($ranking as $r) {
                    $r = (string)$r;
                    if (in_array($r, $validIds, true) && !in_array($r, $filtered, true)) {
                        $filtered[] = $r;
                    }
                }
                if ($type === 'ranking' && count($filtered) !== count($validIds)) {
                    throw new RuntimeException('Seřaďte prosím všechny možnosti.');
                }
                if ($type === 'ranking_partial' && empty($filtered)) {
                    throw new RuntimeException('Vyberte a seřaďte alespoň jednu přijatelnou variantu.');
                }
                $cleanPayload = ['ranking' => $filtered];
            }

            $upsert = $pdo->prepare("
                INSERT INTO votes (poll_id, voter_id, payload_json, updated_at)
                VALUES (:poll_id, :voter_id, :payload_json, CURRENT_TIMESTAMP)
                ON CONFLICT(poll_id, voter_id) DO UPDATE SET
                    payload_json = excluded.payload_json,
                    updated_at = CURRENT_TIMESTAMP
            ");
            $upsert->execute([
                ':poll_id' => $pollId,
                ':voter_id' => $voterId,
                ':payload_json' => json_encode($cleanPayload, JSON_UNESCAPED_UNICODE)
            ]);

            $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM votes WHERE poll_id = ?");
            $cntStmt->execute([$pollId]);
            $voteCount = (int)$cntStmt->fetchColumn();

            echo json_encode([
                'ok' => true,
                'message' => 'Hlas byl úspěšně uložen.',
                'my_vote' => [
                    'payload' => $cleanPayload,
                    'updated_at' => date('Y-m-d H:i:s')
                ],
                'vote_count' => $voteCount
            ], JSON_UNESCAPED_UNICODE);
            break;
        }

        case 'admin_login': {
            $pin = trim((string)($input['pin'] ?? ''));
            $correctPin = getSetting($pdo, 'admin_pin', ADMIN_PIN);
            if ($pin === $correctPin || $correctPin === '') {
                $_SESSION['is_admin'] = true;
                echo json_encode(['ok' => true]);
            } else {
                http_response_code(401);
                echo json_encode(['ok' => false, 'error' => 'Nesprávný PIN kód.']);
            }
            break;
        }

        case 'admin_logout': {
            $_SESSION['is_admin'] = false;
            session_destroy();
            echo json_encode(['ok' => true]);
            break;
        }

        case 'admin_state': {
            requireAdmin($pdo);
            $activePollId = (int)getSetting($pdo, 'active_poll_id', '0');
            $unitName = getSetting($pdo, 'unit_name', 'Středisko / Jednotka');
            $onlineVoters = (int)$pdo->query("SELECT COUNT(*) FROM voters WHERE last_seen >= datetime('now', '-25 seconds')")->fetchColumn();
            $totalKnownVoters = (int)$pdo->query("SELECT COUNT(*) FROM voters")->fetchColumn();

            $pollsRows = $pdo->query("
                SELECT p.*, (SELECT COUNT(*) FROM votes v WHERE v.poll_id = p.id) AS vote_count
                FROM polls p
                ORDER BY p.sort_order ASC, p.id ASC
            ")->fetchAll();

            $polls = [];
            $selectedPollId = (int)($_GET['poll_id'] ?? $activePollId);
            $selectedResults = null;

            foreach ($pollsRows as $r) {
                $pid = (int)$r['id'];
                $pItem = [
                    'id' => $pid,
                    'title' => $r['title'],
                    'description' => $r['description'],
                    'type' => $r['type'],
                    'options' => json_decode($r['options_json'], true) ?: [],
                    'resolution_template' => (string)($r['resolution_template'] ?? ''),
                    'status' => $r['status'],
                    'show_results_to_users' => (bool)$r['show_results_to_users'],
                    'sort_order' => (int)$r['sort_order'],
                    'vote_count' => (int)$r['vote_count']
                ];
                $polls[] = $pItem;

                if ($pid === $selectedPollId) {
                    $selectedResults = computePollResults($pdo, $r);
                }
            }

            if ($selectedResults === null && !empty($pollsRows)) {
                $first = $pollsRows[0];
                $selectedPollId = (int)$first['id'];
                $selectedResults = computePollResults($pdo, $first);
            }

            echo json_encode([
                'ok' => true,
                'unit_name' => $unitName,
                'active_poll_id' => $activePollId,
                'selected_poll_id' => $selectedPollId,
                'online_voters' => $onlineVoters,
                'total_known_voters' => $totalKnownVoters,
                'polls' => $polls,
                'selected_results' => $selectedResults
            ], JSON_UNESCAPED_UNICODE);
            break;
        }

        case 'admin_set_status': {
            requireAdmin($pdo);
            $pollId = (int)($input['poll_id'] ?? 0);
            $newStatus = (string)($input['status'] ?? ''); // 'active', 'closed', 'draft', 'hide'

            if ($newStatus === 'hide') {
                setSetting($pdo, 'active_poll_id', '0');
                $pdo->exec("UPDATE polls SET status = 'closed' WHERE status = 'active'");
                echo json_encode(['ok' => true]);
                break;
            }

            if ($pollId <= 0 || !in_array($newStatus, ['active', 'closed', 'draft'], true)) {
                throw new RuntimeException('Neplatné parametry stavu hlasování.');
            }

            if ($newStatus === 'active') {
                // Close any other currently active poll
                $pdo->exec("UPDATE polls SET status = 'closed' WHERE status = 'active' AND id != " . $pollId);
                $stmt = $pdo->prepare("UPDATE polls SET status = 'active' WHERE id = ?");
                $stmt->execute([$pollId]);
                setSetting($pdo, 'active_poll_id', (string)$pollId);
            } elseif ($newStatus === 'closed') {
                $stmt = $pdo->prepare("UPDATE polls SET status = 'closed' WHERE id = ?");
                $stmt->execute([$pollId]);
                setSetting($pdo, 'active_poll_id', (string)$pollId);
            } elseif ($newStatus === 'draft') {
                $stmt = $pdo->prepare("UPDATE polls SET status = 'draft' WHERE id = ?");
                $stmt->execute([$pollId]);
                if ((int)getSetting($pdo, 'active_poll_id', '0') === $pollId) {
                    setSetting($pdo, 'active_poll_id', '0');
                }
            }

            echo json_encode(['ok' => true]);
            break;
        }

        case 'admin_toggle_user_results': {
            requireAdmin($pdo);
            $pollId = (int)($input['poll_id'] ?? 0);
            $show = !empty($input['show']) ? 1 : 0;
            $stmt = $pdo->prepare("UPDATE polls SET show_results_to_users = ? WHERE id = ?");
            $stmt->execute([$show, $pollId]);
            echo json_encode(['ok' => true]);
            break;
        }

        case 'admin_reset_votes': {
            requireAdmin($pdo);
            $pollId = (int)($input['poll_id'] ?? 0);
            $stmt = $pdo->prepare("DELETE FROM votes WHERE poll_id = ?");
            $stmt->execute([$pollId]);

            // If the poll was closed, switch it back to 'draft' (Připraveno)
            $stStmt = $pdo->prepare("SELECT status FROM polls WHERE id = ?");
            $stStmt->execute([$pollId]);
            $currentStatus = $stStmt->fetchColumn();
            if ($currentStatus === 'closed') {
                $upStmt = $pdo->prepare("UPDATE polls SET status = 'draft' WHERE id = ?");
                $upStmt->execute([$pollId]);
                if ((int)getSetting($pdo, 'active_poll_id', '0') === $pollId) {
                    setSetting($pdo, 'active_poll_id', '0');
                }
            }

            echo json_encode(['ok' => true]);
            break;
        }

        case 'admin_save_poll': {
            requireAdmin($pdo);
            $pollId = (int)($input['id'] ?? 0);
            $title = trim((string)($input['title'] ?? ''));
            $description = trim((string)($input['description'] ?? ''));
            $type = (string)($input['type'] ?? 'single');
            $optionsRaw = $input['options'] ?? [];
            $resolutionTemplate = trim((string)($input['resolution_template'] ?? ''));
            $showResults = isset($input['show_results_to_users']) ? (!empty($input['show_results_to_users']) ? 1 : 0) : 1;

            if ($title === '') {
                throw new RuntimeException('Zadejte název otázky.');
            }
            if (!in_array($type, ['single', 'multi', 'ranking', 'ranking_partial'], true)) {
                throw new RuntimeException('Neplatný typ otázky.');
            }
            if (!is_array($optionsRaw) || count($optionsRaw) < 2) {
                throw new RuntimeException('Zadejte alespoň 2 možnosti odpovědi.');
            }

            $cleanOptions = [];
            foreach ($optionsRaw as $idx => $opt) {
                $label = trim((string)($opt['label'] ?? ''));
                if ($label === '') continue;
                $id = trim((string)($opt['id'] ?? ''));
                if ($id === '') {
                    $id = 'opt_' . ($idx + 1) . '_' . substr(md5($label . $idx), 0, 5);
                }
                $cleanOptions[] = [
                    'id' => $id,
                    'label' => $label,
                    'desc' => trim((string)($opt['desc'] ?? ''))
                ];
            }

            if (count($cleanOptions) < 2) {
                throw new RuntimeException('Zadejte alespoň 2 neprázdné možnosti.');
            }

            if ($pollId > 0) {
                $stmt = $pdo->prepare("
                    UPDATE polls
                    SET title = ?, description = ?, type = ?, options_json = ?, resolution_template = ?, show_results_to_users = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $title,
                    $description,
                    $type,
                    json_encode($cleanOptions, JSON_UNESCAPED_UNICODE),
                    $resolutionTemplate,
                    $showResults,
                    $pollId
                ]);
            } else {
                $maxOrder = (int)$pdo->query("SELECT COALESCE(MAX(sort_order), 0) FROM polls")->fetchColumn();
                $stmt = $pdo->prepare("
                    INSERT INTO polls (title, description, type, options_json, resolution_template, status, show_results_to_users, sort_order)
                    VALUES (?, ?, ?, ?, ?, 'draft', ?, ?)
                ");
                $stmt->execute([
                    $title,
                    $description,
                    $type,
                    json_encode($cleanOptions, JSON_UNESCAPED_UNICODE),
                    $resolutionTemplate,
                    $showResults,
                    $maxOrder + 1
                ]);
                $pollId = (int)$pdo->lastInsertId();
            }

            echo json_encode(['ok' => true, 'poll_id' => $pollId]);
            break;
        }

        case 'admin_delete_poll': {
            requireAdmin($pdo);
            $pollId = (int)($input['poll_id'] ?? 0);
            if ($pollId <= 0) {
                throw new RuntimeException('Neplatné ID otázky.');
            }
            if ((int)getSetting($pdo, 'active_poll_id', '0') === $pollId) {
                setSetting($pdo, 'active_poll_id', '0');
            }
            $stmt = $pdo->prepare("DELETE FROM polls WHERE id = ?");
            $stmt->execute([$pollId]);
            echo json_encode(['ok' => true]);
            break;
        }

        case 'admin_save_settings': {
            requireAdmin($pdo);
            if (isset($input['unit_name'])) {
                setSetting($pdo, 'unit_name', trim((string)$input['unit_name']));
            }
            if (isset($input['admin_pin']) && trim((string)$input['admin_pin']) !== '') {
                setSetting($pdo, 'admin_pin', trim((string)$input['admin_pin']));
            }
            echo json_encode(['ok' => true]);
            break;
        }

        case 'admin_simulate_votes': {
            requireAdmin($pdo);
            $pollId = (int)($input['poll_id'] ?? 0);
            $count = min(200, max(1, (int)($input['count'] ?? 35)));

            $stmt = $pdo->prepare("SELECT * FROM polls WHERE id = ?");
            $stmt->execute([$pollId]);
            $pollRow = $stmt->fetch();
            if (!$pollRow) {
                throw new RuntimeException('Hlasování nenalezeno.');
            }

            $options = json_decode($pollRow['options_json'], true) ?: [];
            $ids = array_column($options, 'id');
            $type = $pollRow['type'];

            $pdo->beginTransaction();
            $upsert = $pdo->prepare("
                INSERT INTO votes (poll_id, voter_id, payload_json, updated_at)
                VALUES (?, ?, ?, CURRENT_TIMESTAMP)
                ON CONFLICT(poll_id, voter_id) DO UPDATE SET
                    payload_json = excluded.payload_json,
                    updated_at = CURRENT_TIMESTAMP
            ");

            for ($i = 1; $i <= $count; $i++) {
                $simVoterId = 'sim_voter_' . str_pad((string)$i, 3, '0', STR_PAD_LEFT);
                $payload = [];

                if ($type === 'single') {
                    // Slight realistic weighting towards first few options
                    $pick = $ids[array_rand($ids)];
                    $payload = ['choice' => $pick];
                } elseif ($type === 'multi') {
                    $shuffled = $ids;
                    shuffle($shuffled);
                    $take = random_int(1, max(1, count($ids) - 1));
                    $payload = ['choices' => array_slice($shuffled, 0, $take)];
                } elseif ($type === 'ranking') {
                    $shuffled = $ids;
                    shuffle($shuffled);
                    $payload = ['ranking' => $shuffled];
                } elseif ($type === 'ranking_partial') {
                    $shuffled = $ids;
                    shuffle($shuffled);
                    $take = random_int(2, count($ids));
                    $payload = ['ranking' => array_slice($shuffled, 0, $take)];
                }

                $upsert->execute([$pollId, $simVoterId, json_encode($payload, JSON_UNESCAPED_UNICODE)]);
            }
            $pdo->commit();

            echo json_encode(['ok' => true, 'simulated' => $count]);
            break;
        }

        default:
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Neznámá akce API: ' . $action]);
    }
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

function requireAdmin(PDO $pdo): void {
    if (empty($_SESSION['is_admin'])) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Nejste přihlášen jako administrátor.', 'need_auth' => true]);
        exit;
    }
}
