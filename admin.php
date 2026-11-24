<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/db.php';
$pdo = getDb();
$isAdmin = !empty($_SESSION['is_admin']);
?>
<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Administrace a prezentace · Orientační hlasování</title>
    <link rel="stylesheet" href="style.css">
</head>
<body class="admin-page">

<header>
    <div class="brand">
        <div class="brand-icon">⚜</div>
        <div>
            <div style="font-weight:800; font-size:18px;" id="headerUnitName">Administrace a prezentace hlasování</div>
        </div>
    </div>

    <?php if ($isAdmin): ?>
    <div class="header-stats">
        <div class="stat-pill" title="Počet zařízení s otevřenou hlasovací stránkou za posledních 25 vteřin">
            <span style="width:8px;height:8px;border-radius:50%;background:#4cd964;"></span>
            Připojeno online: <strong id="onlineVotersCount">0</strong>
        </div>
        <button type="button" class="btn btn-gold btn-sm" id="togglePresModeBtn">📽️ Režim prezentace (Plátno)</button>
        <button type="button" class="btn btn-outline btn-sm" id="showQrModalBtn">📱 QR kód a adresa pro účastníky</button>
        <button type="button" class="btn btn-outline btn-sm" id="showHelpModalBtn">❓ Nápověda</button>
        <a href="index.php" target="_blank" class="btn btn-outline btn-sm">↗ Otevřít hlasovací stránku</a>
        <button type="button" class="btn btn-outline btn-sm" id="logoutBtn">Odhlásit</button>
    </div>
    <?php endif; ?>
</header>

<?php if (!$isAdmin): ?>
<div class="login-wrap">
    <div class="card">
        <h2 style="margin-top:0; color:var(--skaut-dark);">Přihlášení administrátora</h2>
        <p style="color:var(--text-muted); font-size:14px;">Zadejte PIN kód pro ovládání hlasování a prezentaci výsledků (výchozí PIN je <code>2027</code>).</p>
        <form id="loginForm">
            <div class="form-group">
                <label for="pinInput">PIN kód</label>
                <input type="password" id="pinInput" class="form-control" placeholder="Zadejte PIN..." autofocus required>
            </div>
            <button type="submit" class="btn btn-blue" style="width:100%;">Přihlásit se do administrace</button>
        </form>
    </div>
</div>
<script>
document.getElementById('loginForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const pin = document.getElementById('pinInput').value;
    const res = await fetch('api.php?action=admin_login', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ pin })
    });
    const data = await res.json();
    if (data.ok) {
        location.reload();
    } else {
        alert(data.error || 'Chybný PIN');
    }
});
</script>
<?php else: ?>

<div class="layout">
    <!-- Left Column: Questions & Settings -->
    <div class="sidebar-col">
        <div class="card">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
                <h3 style="margin:0; color:var(--skaut-dark);">Připravené otázky</h3>
                <button type="button" class="btn btn-blue btn-sm" id="newPollBtn">+ Nová otázka</button>
            </div>

            <div id="pollsListContainer">Načítám otázky…</div>

            <div style="margin-top:14px; padding-top:14px; border-top:1px solid var(--border-color); display:flex; justify-content:space-between; align-items:center;">
                <span style="font-size:12px; color:var(--text-muted);">Stav obrazovky účastníků:</span>
                <button type="button" class="btn btn-outline btn-sm" id="hideActivePollBtn">⏸ Přepnout účastníky na „Čekání“</button>
            </div>
        </div>

        <div class="card">
            <h4 style="margin:0 0 10px 0; color:var(--skaut-dark);">Nastavení jednotky & PINu</h4>
            <form id="settingsForm">
                <div class="form-group">
                    <label>Název jednotky (propíše se do hlavičky a vzoru usnesení)</label>
                    <input type="text" id="unitNameInput" class="form-control" placeholder="např. Středisko Vatra Praha">
                </div>
                <div class="form-group">
                    <label>Změnit PIN administrace (nechte prázdné beze změny)</label>
                    <input type="password" id="newPinInput" class="form-control" placeholder="Nový PIN (výchozí: 2027)">
                </div>
                <button type="submit" class="btn btn-outline btn-sm">💾 Uložit nastavení</button>
            </form>
        </div>
    </div>

    <!-- Right Column: Active Control & Live Presentation Results -->
    <div class="main-col">
        <div class="card" id="detailContainer">
            Načítám detail hlasování…
        </div>
    </div>
</div>

<!-- Modal: Create/Edit Poll -->
<div class="modal-backdrop" id="pollModal">
    <div class="modal-card">
        <h3 style="margin-top:0; color:var(--skaut-dark);" id="pollModalTitle">Upravit otázku</h3>
        <form id="pollForm">
            <input type="hidden" id="editPollId" value="0">
            <div class="form-group">
                <label>Otázka / Název hlasování</label>
                <input type="text" id="editPollTitle" class="form-control" required>
            </div>
            <div class="form-group">
                <label>Popis / Instrukce pro hlasující</label>
                <textarea id="editPollDesc" class="form-control" rows="2"></textarea>
            </div>
            <div class="form-group">
                <label>Typ hlasování</label>
                <select id="editPollType" class="form-control">
                    <option value="ranking">Pořadí preferencí – seřadit všechny varianty (1. až N. místo)</option>
                    <option value="ranking_partial">Výběr a pořadí přijatelných variant (do usnesení sněmu)</option>
                    <option value="single">Klasická volba – výběr 1 možnosti</option>
                    <option value="multi">Více možností – zaškrtávací (více odpovědí)</option>
                </select>
            </div>
            <div class="form-group">
                <label>Možnosti / Varianty</label>
                <div id="editOptionsList"></div>
                <button type="button" class="btn btn-outline btn-sm" id="addOptionRowBtn" style="margin-top:8px;">+ Přidat možnost</button>
            </div>
            <div class="form-group">
                <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:6px; margin-bottom:5px;">
                    <label style="margin:0;">Šablona návrhu usnesení (nepovinné – prázdné = nezobrazí se)</label>
                    <div style="display:flex; gap:5px; flex-wrap:wrap;">
                        <button type="button" class="btn btn-outline btn-sm" id="insertTplVsjBtn" style="padding:3px 8px; font-size:11px;">+ Vzor: Delegáti VSJ</button>
                        <button type="button" class="btn btn-outline btn-sm" id="insertTplStarostaBtn" style="padding:3px 8px; font-size:11px;">+ Vzor: Jmenování starosty</button>
                        <button type="button" class="btn btn-outline btn-sm" id="insertTplSimpleBtn" style="padding:3px 8px; font-size:11px;">+ Obecný vzor</button>
                        <button type="button" class="btn btn-outline btn-sm" id="clearTplBtn" style="padding:3px 8px; font-size:11px; color:var(--danger);">Vymazat</button>
                    </div>
                </div>
                <textarea id="editPollResolutionTemplate" class="form-control" rows="3" placeholder="Např.: Sněm jednotky {jednotka} schvaluje varianty v pořadí: {vysledek}."></textarea>
                <div style="font-size:12px; color:var(--text-muted); margin-top:6px; background:#f8f5ef; padding:8px 10px; border-radius:8px; border:1px solid var(--border-color);">
                    <strong>Nápověda k zástupným značkám (kliknutím vložíte do textu):</strong><br>
                    <code class="tpl-tag-btn" data-tag="{jednotka}" style="cursor:pointer; color:var(--skaut-blue); font-weight:bold;">{jednotka}</code> = název jednotky z nastavení ·
                    <code class="tpl-tag-btn" data-tag="{vysledek}" style="cursor:pointer; color:var(--skaut-blue); font-weight:bold;">{vysledek}</code> = všechny vybrané možnosti seřazené dle výsledku hlasování ·
                    <code class="tpl-tag-btn" data-tag="{vitez}" style="cursor:pointer; color:var(--skaut-blue); font-weight:bold;">{vitez}</code> = pouze 1. vítězná možnost
                </div>
            </div>
            <div class="form-group">
                <label style="display:flex; align-items:center; gap:8px; font-weight:normal; cursor:pointer;">
                    <input type="checkbox" id="editPollShowResults" checked>
                    Po ukončení hlasování zobrazit výsledky i účastníkům na jejich telefonech
                </label>
            </div>
            <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:18px;">
                <button type="button" class="btn btn-outline" id="closePollModalBtn">Zrušit</button>
                <button type="submit" class="btn btn-blue">💾 Uložit otázku</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: QR & Join URL -->
<div class="modal-backdrop" id="qrModal">
    <div class="modal-card" style="text-align:center; max-width:520px;">
        <h2 style="margin-top:0; color:var(--skaut-dark);">Připojte se k hlasování</h2>
        <p style="color:var(--text-muted); font-size:15px;">Otevřete v mobilu nebo notebooku adresu:</p>
        <div style="background:#f8f5ef; padding:12px 16px; border-radius:10px; border:2px solid var(--skaut-blue); font-size:18px; font-weight:800; color:var(--skaut-dark); word-break:break-all; margin-bottom:16px;" id="joinUrlText"></div>
        <div id="qrImageWrap" style="margin:16px auto; min-height:220px; display:flex; align-items:center; justify-content:center;"></div>
        <p style="font-size:12px; color:var(--text-muted);">Pokud jste v lokální Wi-Fi síti, ujistěte se, že účastníci zadávají lokální IP adresu tohoto počítače.</p>
        <button type="button" class="btn btn-blue" id="closeQrModalBtn">Zavřít</button>
    </div>
</div>

<!-- Modal: Help & Documentation -->
<div class="modal-backdrop" id="helpModal">
    <div class="modal-card" style="max-width:760px; line-height:1.5;">
        <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid var(--border-color); padding-bottom:12px; margin-bottom:16px;">
            <h2 style="margin:0; color:var(--skaut-dark);">❓ Nápověda k aplikaci Orientační hlasování</h2>
            <button type="button" class="btn btn-outline btn-sm" id="closeHelpModalBtn">✕ Zavřít</button>
        </div>

        <h3 style="color:var(--skaut-blue); margin:16px 0 6px;">1. Jak probíhá řízení hlasování</h3>
        <ul style="margin:0 0 14px 0; padding-left:20px; font-size:14px;">
            <li><strong>Připraveno (Draft):</strong> Otázka je připravená v administraci, účastníci ji zatím nevidí.</li>
            <li><strong>▶️ Spustit hlasování:</strong> Otázka se okamžitě (do 2 vteřin) zobrazí všem účastníkům na jejich telefonech/noteboocích.</li>
            <li><strong>Úprava hlasu účastníkem:</strong> Každý prohlížeč má v <code>localStorage</code> svůj unikátní anonymní kód. Dokud hlasování běží, může účastník své pořadí nebo volbu kdykoliv změnit a znovu uložit – jeho předchozí hlas se přepíše (stále 1 člověk = 1 hlas).</li>
            <li><strong>⏹️ Ukončit hlasování:</strong> Uzamkne hlasování a zobrazí konečné výsledky.</li>
            <li><strong>⏸ Přepnout účastníky na „Čekání“:</strong> Skryje aktivní otázku z telefonů účastníků a zobrazí jim úvodní čekací obrazovku.</li>
        </ul>

        <h3 style="color:var(--skaut-blue); margin:16px 0 6px;">2. Typy otázek a vyhodnocení preferencí</h3>
        <ul style="margin:0 0 14px 0; padding-left:20px; font-size:14px;">
            <li><strong>🔢 Pořadí preferencí (1..N):</strong> Účastník seřadí všechny varianty od nejvhodnější (1. místo) po nejméně vhodnou.</li>
            <li><strong>☑️ Výběr + pořadí přijatelných:</strong> Účastník si tlačítkem <code>+</code> vybere pouze varianty, se kterými souhlasí, a ty seřadí podle preference.</li>
            <li><strong>🔘 Výběr 1 možnosti / ✅ Více možností:</strong> Klasické jednohlavé nebo vícehlavé hlasování.</li>
            <li><strong>Jak se počítají body u pořadí:</strong> Při 6 možnostech získává 1. místo <strong>6 bodů</strong>, 2. místo <strong>5 bodů</strong> … 6. místo <strong>1 bod</strong> (nezařazená možnost 0 bodů). Body od všech hlasujících se sečtou a určí výsledné pořadí. Zobrazuje se také průměrné pořadí a přehled, kolikrát byla každá možnost na 1., 2., 3. místě atd.</li>
        </ul>

        <h3 style="color:var(--skaut-blue); margin:16px 0 6px;">3. Šablona návrhu usnesení a zástupné značky</h3>
        <p style="margin:0 0 8px 0; font-size:14px;">
            U každé otázky můžete při úpravě (tlačítko <strong>✏️ Upravit otázku</strong>) vyplnit pole <strong>Šablona návrhu usnesení</strong>. Pokud ho necháte prázdné, tmavý box s návrhem usnesení se pod výsledky vůbec nezobrazí.
        </p>
        <div style="background:#f8f5ef; padding:12px 14px; border-radius:10px; border:1px solid var(--border-color); font-size:13px; margin-bottom:14px;">
            <strong>Zástupné značky, které můžete v šabloně použít:</strong>
            <ul style="margin:6px 0 0 0; padding-left:18px;">
                <li><code>{jednotka}</code> – automaticky dosadí název jednotky z levého panelu nastavení.</li>
                <li><code>{vysledek}</code> – dosadí všechny vybrané možnosti seřazené podle výsledku hlasování oddělené čárkou (u částečného výběru zařazuje varianty s nadpoloviční podporou, min. však první dvě).</li>
                <li><code>{vitez}</code> – dosadí pouze 1. vítěznou možnost.</li>
            </ul>
            <div style="margin-top:8px;">
                <strong>Příklad šablony:</strong><br>
                <em>„Sněm jednotky ({jednotka}) souhlasí s potenciálním schválením následujících variant (v uvedeném pořadí dle preferencí): {vysledek}.“</em>
            </div>
        </div>

        <h3 style="color:var(--skaut-blue); margin:16px 0 6px;">4. Režim prezentace a testování</h3>
        <ul style="margin:0 0 14px 0; padding-left:20px; font-size:14px;">
            <li><strong>📽️ Režim prezentace (Plátno):</strong> Skryje levý panel s otázkami a editační tlačítka, takže na projektoru jsou vidět pouze čisté grafy a počty hlasů.</li>
            <li><strong>🧪 Simulovat 35 hlasů:</strong> Vygeneruje testovací hlasy pro vyzkoušení grafů nanečisto. Před ostrým hlasováním je vymažete tlačítkem <strong>🗑️ Vynulovat hlasy</strong>.</li>
        </ul>

        <div style="text-align:right; margin-top:16px;">
            <button type="button" class="btn btn-blue" id="closeHelpModalBtnBottom">Rozumím, zavřít</button>
        </div>
    </div>
</div>

<script>
(function() {
    let state = null;
    let selectedPollId = 0;
    let lastPollsListHash = '';
    let lastHeaderKey = '';
    let lastResultsHash = '';
    let isActionPending = false;

    async function loadAdminState(forceRender = false) {
        if (isActionPending && !forceRender) return;
        try {
            const url = `api.php?action=admin_state` + (selectedPollId ? `&poll_id=${selectedPollId}` : '');
            const res = await fetch(url);
            const data = await res.json();
            if (!data.ok) {
                if (data.need_auth) location.reload();
                return;
            }
            state = data;
            selectedPollId = data.selected_poll_id;

            document.getElementById('onlineVotersCount').textContent = data.online_voters;
            document.getElementById('headerUnitName').textContent = data.unit_name + ' · Administrace a prezentace';
            const unitInput = document.getElementById('unitNameInput');
            if (document.activeElement !== unitInput) {
                unitInput.value = data.unit_name;
            }

            if (forceRender) {
                lastPollsListHash = '';
                lastHeaderKey = '';
                lastResultsHash = '';
            }

            renderPollsList();
            renderDetail();
        } catch (err) {
            console.error('Chyba načítání admin stavu:', err);
        }
    }

    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str || '';
        return div.innerHTML;
    }

    function getTypeLabel(type) {
        switch (type) {
            case 'ranking': return '🔢 Pořadí preferencí (1..N)';
            case 'ranking_partial': return '☑️ Výběr + pořadí přijatelných';
            case 'single': return '🔘 Výběr 1 možnosti';
            case 'multi': return '✅ Výběr více možností';
            default: return type;
        }
    }

    function renderPollsList() {
        const container = document.getElementById('pollsListContainer');
        if (!state || !state.polls) return;

        const currentHash = JSON.stringify({
            selectedPollId,
            active_poll_id: state.active_poll_id,
            polls: state.polls
        });
        if (currentHash === lastPollsListHash) {
            return;
        }
        lastPollsListHash = currentHash;

        let html = '';
        state.polls.forEach((p, idx) => {
            const isSelected = p.id === selectedPollId;
            const isLive = p.id === state.active_poll_id;

            let chip = `<span class="status-chip chip-draft">Připraveno</span>`;
            if (p.status === 'active' && isLive) {
                chip = `<span class="status-chip chip-active">● Právě se hlasuje</span>`;
            } else if (p.status === 'closed' && isLive) {
                chip = `<span class="status-chip chip-closed">■ Ukončeno (Na obrazovkách)</span>`;
            } else if (p.status === 'closed') {
                chip = `<span class="status-chip chip-closed">Ukončeno</span>`;
            }

            html += `
                <div class="poll-list-item ${isSelected ? 'selected' : ''} ${isLive && p.status === 'active' ? 'is-live' : ''}" data-select-poll="${p.id}">
                    <div style="display:flex; justify-content:space-between; align-items:center; gap:6px; margin-bottom:6px;">
                        ${chip}
                        <span style="font-size:12px; font-weight:700; color:var(--skaut-blue);">🗳️ ${p.vote_count} hlasů</span>
                    </div>
                    <div style="font-weight:700; font-size:15px; color:var(--skaut-dark); margin-bottom:4px;">
                        ${idx + 1}. ${escapeHtml(p.title)}
                    </div>
                    <div style="font-size:12px; color:var(--text-muted);">
                        ${getTypeLabel(p.type)} · ${p.options.length} možností
                    </div>
                </div>
            `;
        });

        container.innerHTML = html;
        container.querySelectorAll('[data-select-poll]').forEach(el => {
            el.addEventListener('click', () => {
                selectedPollId = parseInt(el.getAttribute('data-select-poll'), 10);
                loadAdminState();
            });
        });
    }

    function renderDetail() {
        const container = document.getElementById('detailContainer');
        if (!state || !state.polls) return;
        const poll = state.polls.find(p => p.id === selectedPollId);
        if (!poll) {
            container.innerHTML = `<p>Vyberte otázku v levém sloupci.</p>`;
            lastHeaderKey = '';
            lastResultsHash = '';
            return;
        }

        const results = state.selected_results;
        const isLive = (poll.id === state.active_poll_id);

        // Header + Control buttons only depend on poll metadata & active status (NOT vote_count)
        const headerKey = JSON.stringify({
            id: poll.id,
            title: poll.title,
            description: poll.description,
            type: poll.type,
            status: poll.status,
            isLive: isLive
        });

        if (headerKey !== lastHeaderKey) {
            lastHeaderKey = headerKey;
            lastResultsHash = ''; // force results re-render into fresh wrapper

            let statusBanner = '';
            if (poll.status === 'active' && isLive) {
                statusBanner = `<span class="status-chip chip-active" style="font-size:13px; padding:5px 12px;">● HLASOVÁNÍ PRÁVĚ PROBÍHÁ NA ZAŘÍZENÍCH ÚČASTNÍKŮ</span>`;
            } else if (poll.status === 'closed') {
                statusBanner = `<span class="status-chip chip-closed" style="font-size:13px; padding:5px 12px;">■ HLASOVÁNÍ UKONČENO – VÝSLEDKY</span>`;
            } else {
                statusBanner = `<span class="status-chip chip-draft" style="font-size:13px; padding:5px 12px;">PŘIPRAVENO KE SPUŠTĚNÍ</span>`;
            }

            container.innerHTML = `
                <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:12px; margin-bottom:12px;">
                    <div>
                        ${statusBanner}
                        <span style="font-size:13px; color:var(--text-muted); margin-left:8px;">${getTypeLabel(poll.type)}</span>
                    </div>
                    <div class="admin-only-bar" style="display:flex; gap:8px; flex-wrap:wrap;">
                        <button type="button" class="btn btn-outline btn-sm" id="editCurrentPollBtn">✏️ Upravit otázku</button>
                        <button type="button" class="btn btn-outline btn-sm" id="simulateVotesBtn" title="Vygeneruje testovací hlasy pro vyzkoušení grafů">🧪 Simulovat 35 hlasů</button>
                        <button type="button" class="btn btn-outline btn-sm" id="resetVotesBtn" style="color:var(--danger);">🗑️ Vynulovat hlasy</button>
                        <button type="button" class="btn btn-outline btn-sm" id="deletePollBtn" style="color:var(--danger);">✕ Smazat</button>
                    </div>
                </div>

                <h1 class="pres-title" style="margin:0 0 8px 0; font-size:24px; color:var(--skaut-dark);">${escapeHtml(poll.title)}</h1>
                ${poll.description ? `<p style="margin:0 0 18px 0; color:var(--text-muted); font-size:15px; line-height:1.45;">${escapeHtml(poll.description)}</p>` : ''}

                <div class="control-hero">
                    <div class="big-counter">
                        <span class="big-counter-num" id="detailVoteCountNum">${poll.vote_count}</span>
                        <span style="font-size:15px; font-weight:600; color:var(--text-muted);">odevzdaných hlasů</span>
                    </div>
                    <div style="display:flex; gap:10px; flex-wrap:wrap;">
                        ${poll.status !== 'active' || !isLive ? `
                            <button type="button" class="btn btn-green" id="startPollBtn">▶️ Spustit toto hlasování účastníkům</button>
                        ` : `
                            <button type="button" class="btn btn-red" id="stopPollBtn">⏹️ Ukončit hlasování a uzavřít hlasy</button>
                        `}
                        ${poll.status === 'closed' && !isLive ? `
                            <button type="button" class="btn btn-blue" id="showClosedToUsersBtn">👁️ Zobrazit výsledky této otázky účastníkům</button>
                        ` : ''}
                    </div>
                </div>
                <div id="detailResultsWrapper"></div>
            `;
            bindDetailActions(poll);
        } else {
            // Only update the vote counter number in-place without touching buttons!
            const countEl = document.getElementById('detailVoteCountNum');
            if (countEl) countEl.textContent = poll.vote_count;
        }

        // Render results section only when results actually changed
        const currentResultsHash = JSON.stringify(results);
        if (currentResultsHash === lastResultsHash) {
            return;
        }
        lastResultsHash = currentResultsHash;

        const resultsWrapper = document.getElementById('detailResultsWrapper');
        if (!resultsWrapper) return;

        let resHtml = '';
        if (results && results.sorted_items) {
            if (poll.type === 'ranking' || poll.type === 'ranking_partial') {
                resHtml += `<h3 style="margin:0 0 14px 0; color:var(--skaut-dark);">Výsledné pořadí dle preferencí</h3>`;

                results.sorted_items.forEach((item, idx) => {
                    const rankPos = idx + 1;
                    let pillsHtml = '';
                    if (item.rank_counts_list) {
                        item.rank_counts_list.forEach((cnt, rIdx) => {
                            const place = rIdx + 1;
                            if (cnt > 0 || place <= 3) {
                                pillsHtml += `<span class="rank-pill ${place === 1 ? 'first-place' : ''}">${place}. místo: <strong>${cnt}×</strong></span>`;
                            }
                        });
                    }

                    resHtml += `
                        <div class="result-card ${rankPos === 1 && item.borda_points > 0 ? 'rank-1' : ''}">
                            <div class="result-top">
                                <div style="display:flex; align-items:center; gap:12px;">
                                    <span class="medal-badge">${rankPos}.</span>
                                    <div>
                                        <div style="font-size:18px; font-weight:800; color:var(--skaut-dark);">${escapeHtml(item.label)}</div>
                                        ${item.desc ? `<div style="font-size:13px; color:var(--text-muted);">${escapeHtml(item.desc)}</div>` : ''}
                                    </div>
                                </div>
                                <div style="text-align:right; flex-shrink:0;">
                                    <div style="font-size:20px; font-weight:900; color:var(--skaut-blue);">${item.borda_points} bodů <span style="font-size:14px; font-weight:600;">(${item.borda_pct} %)</span></div>
                                    <div style="font-size:13px; color:var(--text-muted);">
                                        Průměrné pořadí: <strong>${item.avg_rank !== null ? item.avg_rank + '.' : '—'}</strong> ·
                                        Podpora: <strong>${item.ranked_count}</strong> z ${results.total_voters} (${item.approval_pct} %)
                                    </div>
                                </div>
                            </div>
                            <div class="bar-track">
                                <div class="bar-fill" style="width: ${item.borda_pct}%;"></div>
                            </div>
                            <div class="rank-pills-row">${pillsHtml}</div>
                        </div>
                    `;
                });
            } else {
                // Single or Multi choice results
                resHtml += `<h3 style="margin:0 0 14px 0; color:var(--skaut-dark);">Průběžné / Konečné výsledky hlasování</h3>`;
                results.sorted_items.forEach((item, idx) => {
                    const rankPos = idx + 1;
                    resHtml += `
                        <div class="result-card ${rankPos === 1 && item.votes > 0 ? 'rank-1' : ''}">
                            <div class="result-top">
                                <div style="display:flex; align-items:center; gap:12px;">
                                    <span class="medal-badge">${rankPos}.</span>
                                    <div>
                                        <div style="font-size:18px; font-weight:800; color:var(--skaut-dark);">${escapeHtml(item.label)}</div>
                                        ${item.desc ? `<div style="font-size:13px; color:var(--text-muted);">${escapeHtml(item.desc)}</div>` : ''}
                                    </div>
                                </div>
                                <div style="text-align:right; flex-shrink:0;">
                                    <div style="font-size:22px; font-weight:900; color:var(--skaut-blue);">${item.votes} hlasů <span style="font-size:15px;">(${item.percentage} %)</span></div>
                                </div>
                            </div>
                            <div class="bar-track">
                                <div class="bar-fill" style="width: ${item.percentage}%;"></div>
                            </div>
                        </div>
                    `;
                });
            }

            if (results.resolution_text) {
                resHtml += `
                    <div class="resolution-box">
                        <h4>📋 Automaticky vygenerovaný návrh usnesení</h4>
                        <p class="resolution-quote" id="resolutionTextQuote">${escapeHtml(results.resolution_text)}</p>
                        <button type="button" class="btn btn-gold btn-sm" id="copyResolutionBtn">📋 Zkopírovat text usnesení do schránky</button>
                    </div>
                `;
            }
        }

        resultsWrapper.innerHTML = resHtml;
        const copyResBtn = document.getElementById('copyResolutionBtn');
        if (copyResBtn) {
            copyResBtn.addEventListener('click', () => {
                const txt = document.getElementById('resolutionTextQuote')?.textContent || '';
                navigator.clipboard.writeText(txt);
                copyResBtn.textContent = '✓ Zkopírováno do schránky!';
                setTimeout(() => { copyResBtn.textContent = '📋 Zkopírovat text usnesení do schránky'; }, 2000);
            });
        }
    }

    function bindDetailActions(poll) {
        const startBtn = document.getElementById('startPollBtn');
        if (startBtn) {
            startBtn.addEventListener('click', async () => {
                isActionPending = true;
                startBtn.disabled = true;
                try {
                    await fetch('api.php?action=admin_set_status', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/json'},
                        body: JSON.stringify({ poll_id: poll.id, status: 'active' })
                    });
                } finally {
                    isActionPending = false;
                    loadAdminState(true);
                }
            });
        }

        const stopBtn = document.getElementById('stopPollBtn');
        if (stopBtn) {
            stopBtn.addEventListener('click', async () => {
                isActionPending = true;
                stopBtn.disabled = true;
                try {
                    await fetch('api.php?action=admin_set_status', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/json'},
                        body: JSON.stringify({ poll_id: poll.id, status: 'closed' })
                    });
                } finally {
                    isActionPending = false;
                    loadAdminState(true);
                }
            });
        }

        const showClosedBtn = document.getElementById('showClosedToUsersBtn');
        if (showClosedBtn) {
            showClosedBtn.addEventListener('click', async () => {
                isActionPending = true;
                showClosedBtn.disabled = true;
                try {
                    await fetch('api.php?action=admin_set_status', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/json'},
                        body: JSON.stringify({ poll_id: poll.id, status: 'closed' })
                    });
                } finally {
                    isActionPending = false;
                    loadAdminState(true);
                }
            });
        }

        const resetBtn = document.getElementById('resetVotesBtn');
        if (resetBtn) {
            resetBtn.addEventListener('click', async () => {
                const countNow = document.getElementById('detailVoteCountNum')?.textContent || poll.vote_count;
                if (!confirm(`Opravdu chcete smazat všechny odevzdané hlasy (${countNow}) u této otázky?`)) return;
                isActionPending = true;
                try {
                    await fetch('api.php?action=admin_reset_votes', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/json'},
                        body: JSON.stringify({ poll_id: poll.id })
                    });
                } finally {
                    isActionPending = false;
                    loadAdminState(true);
                }
            });
        }

        const simBtn = document.getElementById('simulateVotesBtn');
        if (simBtn) {
            simBtn.addEventListener('click', async () => {
                isActionPending = true;
                try {
                    await fetch('api.php?action=admin_simulate_votes', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/json'},
                        body: JSON.stringify({ poll_id: poll.id, count: 35 })
                    });
                } finally {
                    isActionPending = false;
                    loadAdminState(true);
                }
            });
        }

        const delBtn = document.getElementById('deletePollBtn');
        if (delBtn) {
            delBtn.addEventListener('click', async () => {
                if (!confirm(`Opravdu chcete smazat otázku "${poll.title}"?`)) return;
                isActionPending = true;
                try {
                    await fetch('api.php?action=admin_delete_poll', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/json'},
                        body: JSON.stringify({ poll_id: poll.id })
                    });
                    selectedPollId = 0;
                } finally {
                    isActionPending = false;
                    loadAdminState(true);
                }
            });
        }

        const editBtn = document.getElementById('editCurrentPollBtn');
        if (editBtn) {
            editBtn.addEventListener('click', () => {
                const currentPoll = (state && state.polls) ? (state.polls.find(p => p.id === poll.id) || poll) : poll;
                openPollModal(currentPoll);
            });
        }
    }

    // Top bar & sidebar actions
    document.getElementById('hideActivePollBtn').addEventListener('click', async () => {
        await fetch('api.php?action=admin_set_status', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ status: 'hide' })
        });
        loadAdminState();
    });

    document.getElementById('togglePresModeBtn').addEventListener('click', () => {
        document.body.classList.toggle('presentation-mode');
        const isPres = document.body.classList.contains('presentation-mode');
        document.getElementById('togglePresModeBtn').textContent = isPres
            ? '🛠️ Zpět do plné administrace'
            : '📽️ Režim prezentace (Plátno)';
    });

    document.getElementById('logoutBtn').addEventListener('click', async () => {
        await fetch('api.php?action=admin_logout', { method: 'POST' });
        location.reload();
    });

    document.getElementById('settingsForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        const unit_name = document.getElementById('unitNameInput').value;
        const admin_pin = document.getElementById('newPinInput').value;
        await fetch('api.php?action=admin_save_settings', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ unit_name, admin_pin })
        });
        document.getElementById('newPinInput').value = '';
        loadAdminState();
    });

    // Modal Create/Edit Poll
    function addOptionInputRow(id = '', label = '', desc = '') {
        const list = document.getElementById('editOptionsList');
        const row = document.createElement('div');
        row.style.cssText = 'display:grid; grid-template-columns: 1fr 1fr auto; gap:8px; margin-bottom:8px;';
        row.innerHTML = `
            <input type="hidden" class="opt-id" value="${escapeHtml(id)}">
            <input type="text" class="form-control opt-label" placeholder="Název možnosti / varianty" value="${escapeHtml(label)}" required>
            <input type="text" class="form-control opt-desc" placeholder="Stručný popis (volitelné)" value="${escapeHtml(desc)}">
            <button type="button" class="btn btn-outline btn-sm remove-opt-btn" style="color:var(--danger);">✕</button>
        `;
        row.querySelector('.remove-opt-btn').addEventListener('click', () => row.remove());
        list.appendChild(row);
    }

    function openPollModal(poll = null) {
        document.getElementById('pollModalTitle').textContent = poll ? 'Upravit otázku' : 'Nová otázka';
        document.getElementById('editPollId').value = poll ? poll.id : 0;
        document.getElementById('editPollTitle').value = poll ? poll.title : '';
        document.getElementById('editPollDesc').value = poll ? poll.description : '';
        document.getElementById('editPollType').value = poll ? poll.type : 'ranking';
        document.getElementById('editPollResolutionTemplate').value = poll ? (poll.resolution_template || '') : '';
        document.getElementById('editPollShowResults').checked = poll ? poll.show_results_to_users : true;

        const list = document.getElementById('editOptionsList');
        list.innerHTML = '';
        if (poll && poll.options && poll.options.length > 0) {
            poll.options.forEach(o => addOptionInputRow(o.id, o.label, o.desc || ''));
        } else {
            addOptionInputRow('', 'Možnost 1', '');
            addOptionInputRow('', 'Možnost 2', '');
            addOptionInputRow('', 'Možnost 3', '');
        }

        document.getElementById('pollModal').classList.add('open');
    }

    document.getElementById('newPollBtn').addEventListener('click', () => openPollModal(null));
    document.getElementById('addOptionRowBtn').addEventListener('click', () => addOptionInputRow());
    document.getElementById('closePollModalBtn').addEventListener('click', () => {
        document.getElementById('pollModal').classList.remove('open');
    });

    document.getElementById('pollForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        const id = parseInt(document.getElementById('editPollId').value, 10);
        const title = document.getElementById('editPollTitle').value;
        const description = document.getElementById('editPollDesc').value;
        const type = document.getElementById('editPollType').value;
        const resolution_template = document.getElementById('editPollResolutionTemplate').value;
        const show_results_to_users = document.getElementById('editPollShowResults').checked;

        const options = [];
        document.getElementById('editOptionsList').querySelectorAll('div').forEach(row => {
            const optId = row.querySelector('.opt-id').value;
            const label = row.querySelector('.opt-label').value.trim();
            const desc = row.querySelector('.opt-desc').value.trim();
            if (label) options.push({ id: optId, label, desc });
        });

        const res = await fetch('api.php?action=admin_save_poll', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ id, title, description, type, options, resolution_template, show_results_to_users })
        });
        const data = await res.json();
        if (!data.ok) {
            alert(data.error || 'Chyba při ukládání otázky');
            return;
        }
        document.getElementById('pollModal').classList.remove('open');
        selectedPollId = data.poll_id;
        loadAdminState();
    });

    // QR Modal
    document.getElementById('showQrModalBtn').addEventListener('click', () => {
        const voterUrl = new URL('index.php', window.location.href).href;
        document.getElementById('joinUrlText').textContent = voterUrl;
        const qrWrap = document.getElementById('qrImageWrap');
        const qrApiUrl = `https://api.qrserver.com/v1/create-qr-code/?size=240x240&data=${encodeURIComponent(voterUrl)}`;
        qrWrap.innerHTML = `<img src="${qrApiUrl}" alt="QR kód hlasování" style="border-radius:12px; border:4px solid #fff; box-shadow:0 4px 14px rgba(0,0,0,0.12);" onerror="this.outerHTML='<p style=color:var(--text-muted)>QR kód vyžaduje připojení k internetu, účastníci mohou zadat adresu výše přímo do prohlížeče.</p>'">`;
        document.getElementById('qrModal').classList.add('open');
    });

    document.getElementById('closeQrModalBtn').addEventListener('click', () => {
        document.getElementById('qrModal').classList.remove('open');
    });

    // Help Modal & Template Quick Actions
    document.getElementById('showHelpModalBtn').addEventListener('click', () => {
        document.getElementById('helpModal').classList.add('open');
    });
    document.getElementById('closeHelpModalBtn').addEventListener('click', () => {
        document.getElementById('helpModal').classList.remove('open');
    });
    document.getElementById('closeHelpModalBtnBottom').addEventListener('click', () => {
        document.getElementById('helpModal').classList.remove('open');
    });

    const tplTextarea = document.getElementById('editPollResolutionTemplate');
    document.getElementById('insertTplVsjBtn').addEventListener('click', () => {
        tplTextarea.value = '„Sněm jednotky ({jednotka}) se zabýval otázkou volby delegátů na budoucí Valné sněmy Junáka – českého skauta a došel k následujícímu závěru: Sněm se seznámil s možnostmi uvedenými v předsněmových materiálech a z uvedených variant souhlasí s potenciálním schválením následujících variant (v uvedeném pořadí dle preferencí): {vysledek}.“';
    });
    document.getElementById('insertTplStarostaBtn').addEventListener('click', () => {
        tplTextarea.value = '„Sněm jednotky ({jednotka}) shledává jako nejvhodnější pro jmenování starosty variantu: {vitez}.“';
    });
    document.getElementById('insertTplSimpleBtn').addEventListener('click', () => {
        tplTextarea.value = '„Sněm jednotky ({jednotka}) po projednání schvaluje variantu: {vitez} (celkové pořadí preferencí: {vysledek}).“';
    });
    document.getElementById('clearTplBtn').addEventListener('click', () => {
        tplTextarea.value = '';
    });
    document.querySelectorAll('.tpl-tag-btn').forEach(tagEl => {
        tagEl.addEventListener('click', () => {
            const tag = tagEl.getAttribute('data-tag');
            const start = tplTextarea.selectionStart || tplTextarea.value.length;
            const end = tplTextarea.selectionEnd || tplTextarea.value.length;
            tplTextarea.value = tplTextarea.value.substring(0, start) + tag + tplTextarea.value.substring(end);
            tplTextarea.focus();
        });
    });

    loadAdminState();
    setInterval(loadAdminState, 2000);
})();
</script>
<?php endif; ?>

</body>
</html>
