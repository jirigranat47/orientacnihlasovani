<?php
declare(strict_types=1);

define('DB_DIR', __DIR__ . '/data');
define('DB_PATH', DB_DIR . '/voting.sqlite');
define('ADMIN_PIN', '2027'); // Výchozí PIN do administrace (lze změnit v nastavení)

function getDb(): PDO {
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    if (!is_dir(DB_DIR)) {
        mkdir(DB_DIR, 0777, true);
        // Protect data dir from direct web access
        file_put_contents(DB_DIR . '/.htaccess', "Deny from all\n");
    }

    $needsSeed = !file_exists(DB_PATH);

    $pdo = new PDO('sqlite:' . DB_PATH, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 5,
    ]);

    // Performance and concurrency settings for 100+ simultaneous users
    $pdo->exec('PRAGMA journal_mode = WAL;');
    $pdo->exec('PRAGMA synchronous = NORMAL;');
    $pdo->exec('PRAGMA busy_timeout = 5000;');
    $pdo->exec('PRAGMA foreign_keys = ON;');

    initSchema($pdo);

    if ($needsSeed) {
        seedInitialPolls($pdo);
    }

    return $pdo;
}

function initSchema(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS settings (
            key TEXT PRIMARY KEY,
            value TEXT
        );

        CREATE TABLE IF NOT EXISTS polls (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            description TEXT NOT NULL DEFAULT '',
            type TEXT NOT NULL DEFAULT 'single', -- 'single', 'multi', 'ranking', 'ranking_partial'
            options_json TEXT NOT NULL,
            resolution_template TEXT NOT NULL DEFAULT '',
            status TEXT NOT NULL DEFAULT 'draft', -- 'draft', 'active', 'closed'
            show_results_to_users INTEGER NOT NULL DEFAULT 1,
            sort_order INTEGER NOT NULL DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS votes (
            poll_id INTEGER NOT NULL,
            voter_id TEXT NOT NULL,
            payload_json TEXT NOT NULL,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (poll_id, voter_id),
            FOREIGN KEY (poll_id) REFERENCES polls(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS voters (
            voter_id TEXT PRIMARY KEY,
            last_seen DATETIME DEFAULT CURRENT_TIMESTAMP
        );
    ");

    // Ensure default settings exist
    $stmt = $pdo->prepare("INSERT OR IGNORE INTO settings (key, value) VALUES (?, ?)");
    $stmt->execute(['active_poll_id', '0']);
    $stmt->execute(['admin_pin', ADMIN_PIN]);
    $stmt->execute(['unit_name', 'Středisko / Jednotka']);

    // Migration for existing database: ensure resolution_template column exists
    $cols = array_column($pdo->query("PRAGMA table_info(polls)")->fetchAll(), 'name');
    if (!in_array('resolution_template', $cols, true)) {
        $pdo->exec("ALTER TABLE polls ADD COLUMN resolution_template TEXT NOT NULL DEFAULT ''");
        $defaultTpl = '„Sněm jednotky ({jednotka}) se zabýval otázkou volby delegátů na budoucí Valné sněmy Junáka – českého skauta a došel k následujícímu závěru: Sněm se seznámil s možnostmi uvedenými v předsněmových materiálech a z uvedených variant souhlasí s potenciálním schválením následujících variant (v uvedeném pořadí dle preferencí): {vysledek}.“';
        $up = $pdo->prepare("UPDATE polls SET resolution_template = ? WHERE type IN ('ranking', 'ranking_partial')");
        $up->execute([$defaultTpl]);
    }
}

function seedInitialPolls(PDO $pdo): void {
    $count = (int)$pdo->query("SELECT COUNT(*) FROM polls")->fetchColumn();
    if ($count > 0) {
        return;
    }

    $variantsFull = [
        [
            'id' => 'v1',
            'label' => '1) Nulová varianta (současný stav)',
            'desc' => 'Delegát za každých X členů (155 členů pro VSJ 2027). Střediska pod kvótou nevolí vlastního delegáta, jejich členové přechází na VOJ.'
        ],
        [
            'id' => 'v2',
            'label' => '2) Strop (max. 1 delegát ze střediska)',
            'desc' => 'Každé středisko volí přímo max. jednoho delegáta, zbylí členové přecházejí na sněm vyšší organizační jednotky.'
        ],
        [
            'id' => 'v3',
            'label' => '3) Více volebních lístků na VOJ',
            'desc' => 'Na sněmu VOJ mají delegáti různý počet hlasovacích lístků podle počtu členů jejich střediska, který ještě nebyl využit.'
        ],
        [
            'id' => 'v4',
            'label' => '4) Progresivní kvóta',
            'desc' => 'Postupně zvyšuje potřebný počet členů pro získání dalšího delegáta (na 1. delegáta X1 členů, na 2. více X2, na 3. X3...).'
        ],
        [
            'id' => 'v5',
            'label' => '5) Dánská metoda',
            'desc' => 'Matematický dělitelový výpočet rozděluje pevně daný celkový počet delegátů přímo střediskům. VOJ již delegáty nevolí.'
        ],
        [
            'id' => 'v6',
            'label' => '6) Každé středisko má delegáta',
            'desc' => 'Každé středisko má právě 1 delegáta, jehož síla hlasu na VSJ odráží velikost střediska (vyžaduje el. hlasování).'
        ]
    ];

    $defaultVsjTemplate = '„Sněm jednotky ({jednotka}) se zabýval otázkou volby delegátů na budoucí Valné sněmy Junáka – českého skauta a došel k následujícímu závěru: Sněm se seznámil s možnostmi uvedenými v předsněmových materiálech a z uvedených variant souhlasí s potenciálním schválením následujících variant (v uvedeném pořadí dle preferencí): {vysledek}.“';

    $starostaVariants = [
        [
            'id' => 's1',
            'label' => '1) Stávající stav (Náčelnictvo, nepravidelně, bez předepsané formy)',
            'desc' => 'Změna starosty probíhá na základě rozhodnutí Náčelnictva ad hoc. Není pevně stanovena délka období ani forma výběru.'
        ],
        [
            'id' => 's2',
            'label' => '2) Pravidelně po 6 letech, volná forma (vybírá Náčelnictvo)',
            'desc' => 'Starostu vybírá Náčelnictvo pravidelně v šestiletém cyklu, konkrétní forma či proces výběru nejsou pevně předepsány.'
        ],
        [
            'id' => 's3',
            'label' => '3) Pravidelně po 6 letech, výběrové řízení (vybírá Náčelnictvo)',
            'desc' => 'Starostu vybírá Náčelnictvo pravidelně v šestiletém cyklu na základě otevřeného výběrového řízení.'
        ],
        [
            'id' => 's4_info',
            'label' => 'Informativní varianta: Přímá volba Valným sněmem po 6 letech (nedoporučeno)',
            'desc' => 'Starosta je volen přímo delegáty Valného sněmu na 6 let. Pracovní skupina tuto variantu nedoporučuje (ruší nadřízenost Náčelnictva).'
        ]
    ];

    $defaultStarostaTemplate = '„Sněm jednotky ({jednotka}) shledává jako nejvhodnější pro jmenování starosty variantu: {vitez} (celkové pořadí preferencí: {vysledek}).“';

    $polls = [
        [
            'title' => 'Pořadí preferencí: 6 variant stanovení delegátů VSJ',
            'description' => 'Seřaďte všech 6 navržených variant podle vašich preferencí od nejvhodnější (1. místo) po nejméně vhodnou (6. místo). Pořadí můžete libovolně měnit šipkami nebo přetažením.',
            'type' => 'ranking',
            'options' => $variantsFull,
            'resolution_template' => $defaultVsjTemplate,
            'sort_order' => 1
        ],
        [
            'title' => 'Výběr a pořadí přijatelných variant – Delegáti VSJ (do usnesení sněmu)',
            'description' => 'Vyberte pouze ty varianty stanovení delegátů, s jejichž potenciálním schválením souhlasíte, a seřaďte je dle preferencí (odpovídá části B vzorového usnesení).',
            'type' => 'ranking_partial',
            'options' => $variantsFull,
            'resolution_template' => $defaultVsjTemplate,
            'sort_order' => 2
        ],
        [
            'title' => 'Pravidelné jmenování starosty: Výběr nejvhodnější varianty',
            'description' => 'Kterou z předložených variant pravidelného jmenování starosty Junáka – českého skauta považujete za nejvhodnější?',
            'type' => 'single',
            'options' => $starostaVariants,
            'resolution_template' => '„Sněm jednotky ({jednotka}) shledává jako nejvhodnější pro jmenování starosty variantu: {vitez}.“',
            'sort_order' => 3
        ],
        [
            'title' => 'Pravidelné jmenování starosty: Pořadí preferencí variant',
            'description' => 'Seřaďte varianty způsobu jmenování starosty podle vašich preferencí od nejvhodnější (1. místo) po nejméně vhodnou.',
            'type' => 'ranking',
            'options' => $starostaVariants,
            'resolution_template' => $defaultStarostaTemplate,
            'sort_order' => 4
        ],
        [
            'title' => 'Základní postoj: Vyhovuje nám současný stav volby delegátů (Nulová varianta)?',
            'description' => 'Rychlé orientační hlasování k části A vzorového usnesení: Považujeme současný způsob volby delegátů Valného sněmu za vyhovující?',
            'type' => 'single',
            'options' => [
                ['id' => 'opt1', 'label' => 'Ano – současný stav (1. Nulová varianta) je vyhovující, netřeba měnit', 'desc' => 'Preferuji zachování stávajícího systému bez úprav.'],
                ['id' => 'opt2', 'label' => 'Spíše ano, ale souhlasím i s mírnými úpravami (např. Strop, Více lístků, Progresivní kvóta)', 'desc' => 'Současný stav funguje, ale dílčí posílení menších středisek nevadí.'],
                ['id' => 'opt3', 'label' => 'Ne – současný stav nevyhovuje, chceme zásadnější změnu', 'desc' => 'Preferuji přechod na novou variantu (např. Dánská metoda nebo Každé středisko delegát).'],
                ['id' => 'opt4', 'label' => 'Zdržuji se / Nemám vyhraněný názor', 'desc' => '']
            ],
            'sort_order' => 5
        ],
        [
            'title' => 'Kterou podvariantu preferujete u Varianty 6 (Každé středisko má delegáta)?',
            'description' => 'Pokud by byla vybrána Varianta 6 (každé středisko = 1 delegát s různou silou hlasu), jak by měla být stanovena síla hlasu delegáta?',
            'type' => 'single',
            'options' => [
                ['id' => 'v6a', 'label' => 'Podvarianta 6A: Síla hlasu určená kvótou (1 hlas za X členů)', 'desc' => 'Rozložení síly hlasu stanoví Výkonná rada směrnicí.'],
                ['id' => 'v6b', 'label' => 'Podvarianta 6B: Síla hlasu přímo rovna počtu členů střediska', 'desc' => 'Nevyžaduje žádné parametry, vyžaduje výhradně elektronické hlasování propojené se skautISem.'],
                ['id' => 'v6no', 'label' => 'Variantu 6 nepodporuji v žádné podobě', 'desc' => '']
            ],
            'sort_order' => 6
        ]
    ];

    $stmt = $pdo->prepare("
        INSERT INTO polls (title, description, type, options_json, resolution_template, status, show_results_to_users, sort_order)
        VALUES (:title, :description, :type, :options_json, :resolution_template, 'draft', 1, :sort_order)
    ");

    foreach ($polls as $p) {
        $stmt->execute([
            ':title' => $p['title'],
            ':description' => $p['description'],
            ':type' => $p['type'],
            ':options_json' => json_encode($p['options'], JSON_UNESCAPED_UNICODE),
            ':resolution_template' => $p['resolution_template'] ?? '',
            ':sort_order' => $p['sort_order']
        ]);
    }
}

function getSetting(PDO $pdo, string $key, string $default = ''): string {
    $stmt = $pdo->prepare("SELECT value FROM settings WHERE key = ?");
    $stmt->execute([$key]);
    $val = $stmt->fetchColumn();
    return $val !== false ? (string)$val : $default;
}

function setSetting(PDO $pdo, string $key, string $value): void {
    $stmt = $pdo->prepare("INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value");
    $stmt->execute([$key, $value]);
}

function computePollResults(PDO $pdo, array $poll): array {
    $pollId = (int)$poll['id'];
    $options = json_decode($poll['options_json'], true) ?: [];
    $type = $poll['type'];

    $stmt = $pdo->prepare("SELECT payload_json, updated_at FROM votes WHERE poll_id = ?");
    $stmt->execute([$pollId]);
    $rows = $stmt->fetchAll();

    $totalVoters = count($rows);
    $nOptions = count($options);

    // Map options by ID
    $optMap = [];
    foreach ($options as $idx => $opt) {
        $id = (string)$opt['id'];
        $optMap[$id] = [
            'id' => $id,
            'label' => $opt['label'],
            'desc' => $opt['desc'] ?? '',
            'original_index' => $idx,
            // Metrics for single / multi
            'votes' => 0,
            'percentage' => 0.0,
            // Metrics for ranking / ranking_partial
            'borda_points' => 0,
            'borda_max_possible' => $totalVoters * $nOptions,
            'avg_rank' => null,
            'rank_sum' => 0,
            'ranked_count' => 0,
            'approval_pct' => 0.0,
            'rank_counts' => array_fill(1, max(1, $nOptions), 0), // 1st place..Nth place
        ];
    }

    foreach ($rows as $row) {
        $payload = json_decode($row['payload_json'], true);
        if (!$payload) continue;

        if ($type === 'single') {
            $choice = (string)($payload['choice'] ?? '');
            if (isset($optMap[$choice])) {
                $optMap[$choice]['votes']++;
            }
        } elseif ($type === 'multi') {
            $choices = $payload['choices'] ?? [];
            if (is_array($choices)) {
                $seen = [];
                foreach ($choices as $c) {
                    $c = (string)$c;
                    if (isset($optMap[$c]) && !isset($seen[$c])) {
                        $optMap[$c]['votes']++;
                        $seen[$c] = true;
                    }
                }
            }
        } elseif ($type === 'ranking' || $type === 'ranking_partial') {
            $ranking = $payload['ranking'] ?? [];
            if (is_array($ranking)) {
                $seenRank = [];
                $pos = 1;
                foreach ($ranking as $optId) {
                    $optId = (string)$optId;
                    if (isset($optMap[$optId]) && !isset($seenRank[$optId]) && $pos <= $nOptions) {
                        $seenRank[$optId] = $pos;
                        $points = $nOptions - $pos + 1; // 1st = N pts, 2nd = N-1 pts...
                        $optMap[$optId]['borda_points'] += $points;
                        $optMap[$optId]['rank_sum'] += $pos;
                        $optMap[$optId]['ranked_count']++;
                        $optMap[$optId]['rank_counts'][$pos]++;
                        $pos++;
                    }
                }
            }
        }
    }

    // Finalize percentages and averages
    foreach ($optMap as $id => &$item) {
        if ($type === 'single' || $type === 'multi') {
            $item['percentage'] = $totalVoters > 0 ? round(($item['votes'] / $totalVoters) * 100, 1) : 0.0;
        } else {
            $item['avg_rank'] = $item['ranked_count'] > 0 ? round($item['rank_sum'] / $item['ranked_count'], 2) : null;
            $item['approval_pct'] = $totalVoters > 0 ? round(($item['ranked_count'] / $totalVoters) * 100, 1) : 0.0;
            $item['borda_pct'] = ($totalVoters * $nOptions) > 0
                ? round(($item['borda_points'] / ($totalVoters * $nOptions)) * 100, 1)
                : 0.0;
            // Convert rank_counts to indexed array 0..N-1 for clean JSON serialization
            $item['rank_counts_list'] = array_values($item['rank_counts']);
        }
    }
    unset($item);

    $itemsList = array_values($optMap);

    // Sort results copy by winning score
    $sortedItems = $itemsList;
    if ($type === 'single' || $type === 'multi') {
        usort($sortedItems, function ($a, $b) {
            if ($b['votes'] !== $a['votes']) {
                return $b['votes'] <=> $a['votes'];
            }
            return $a['original_index'] <=> $b['original_index'];
        });
    } else {
        usort($sortedItems, function ($a, $b) {
            if ($b['borda_points'] !== $a['borda_points']) {
                return $b['borda_points'] <=> $a['borda_points'];
            }
            $firstA = $a['rank_counts'][1] ?? 0;
            $firstB = $b['rank_counts'][1] ?? 0;
            if ($firstB !== $firstA) {
                return $firstB <=> $firstA;
            }
            return $a['original_index'] <=> $b['original_index'];
        });
    }

    // Generate resolution text from poll's resolution_template (if configured)
    $template = trim((string)($poll['resolution_template'] ?? ''));
    $resolutionText = '';
    if ($template !== '') {
        $unitName = getSetting($pdo, 'unit_name', 'XYZ');
        $topLabels = [];
        foreach ($sortedItems as $si) {
            if ($type === 'ranking' || $type === 'ranking_partial') {
                if ($si['borda_points'] > 0) {
                    if ($type === 'ranking_partial' && $si['approval_pct'] < 50 && count($topLabels) >= 2) {
                        continue;
                    }
                    $topLabels[] = $si['label'];
                }
            } else {
                if ($si['votes'] > 0) {
                    $topLabels[] = $si['label'];
                }
            }
        }
        $winnerLabel = !empty($topLabels) ? $topLabels[0] : '—';
        $resultListStr = !empty($topLabels) ? implode(', ', $topLabels) : '—';

        $resolutionText = strtr($template, [
            '{jednotka}' => $unitName,
            '{vysledek}' => $resultListStr,
            '{vitez}' => $winnerLabel,
        ]);
    }

    return [
        'total_voters' => $totalVoters,
        'options_count' => $nOptions,
        'items' => $itemsList,
        'sorted_items' => $sortedItems,
        'resolution_text' => $resolutionText
    ];
}
