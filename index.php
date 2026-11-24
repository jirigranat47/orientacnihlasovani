<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Orientační hlasování sněmu</title>
    <link rel="stylesheet" href="style.css">
</head>
<body class="voter-page">

<header>
    <div class="header-inner">
        <div class="brand">
            <div class="brand-icon">⚜</div>
            <div>
                <div class="brand-title" id="unitNameTitle">Orientační hlasování</div>
            </div>
        </div>
        <div class="voter-badge" title="Váš unikátní anonymní kód uložený v prohlížeči (localStorage)">
            <span class="status-dot"></span>
            <span id="voterCodeDisplay">Kód: ...</span>
        </div>
    </div>
</header>

<main id="appContainer">
    <div class="card waiting-box">
        <div class="pulse-ring">⏳</div>
        <h2>Načítání hlasování…</h2>
        <p style="color: var(--text-muted);">Spojuji se se serverem…</p>
    </div>
</main>

<footer>
    Váš hlas je anonymně spárován s kódem v tomto zařízení (<code id="footerVoterId"></code>). Dokud hlasování běží, můžete svou volbu kdykoliv upravit.
    <br><br>
    <a href="admin.php">Administrace a prezentace výsledků →</a>
</footer>

<script>
(function() {
    const STORAGE_KEY = 'orientacni_hlasovani_voter_id';
    const DRAFT_PREFIX = 'orientacni_hlasovani_draft_';

    function getOrCreateVoterId() {
        let id = localStorage.getItem(STORAGE_KEY);
        if (!id) {
            if (window.crypto && crypto.randomUUID) {
                id = crypto.randomUUID();
            } else {
                id = 'v_' + Math.random().toString(36).substring(2, 10) + '_' + Date.now().toString(36);
            }
            localStorage.setItem(STORAGE_KEY, id);
        }
        return id;
    }

    const voterId = getOrCreateVoterId();
    const shortCode = voterId.substring(0, 6).toUpperCase();
    document.getElementById('voterCodeDisplay').textContent = 'ID: ' + shortCode;
    document.getElementById('footerVoterId').textContent = shortCode;

    let currentPollId = null;
    let currentPollStatus = null;
    let currentPollData = null;
    let mySavedVote = null;

    // Local interactive state
    let localChoice = null; // for single
    let localChoices = [];  // for multi
    let localRanking = [];  // for ranking & ranking_partial
    let lastMovedId = null; // track recently moved item for highlight animation
    let isDirty = false;
    let isSubmitting = false;

    async function fetchState() {
        if (isSubmitting) return;
        try {
            const res = await fetch(`api.php?action=voter_state&voter_id=${encodeURIComponent(voterId)}`);
            const data = await res.json();
            if (!data.ok) return;

            if (data.unit_name) {
                document.getElementById('unitNameTitle').textContent = data.unit_name;
            }

            const poll = data.poll;
            const newPollId = poll ? poll.id : 0;
            const newStatus = poll ? poll.status : 'none';
            const pollChanged = (newPollId !== currentPollId) || (newStatus !== currentPollStatus);

            currentPollId = newPollId;
            currentPollStatus = newStatus;
            currentPollData = poll;
            mySavedVote = data.my_vote;

            if (pollChanged) {
                isDirty = false;
                initLocalSelection(poll, mySavedVote);
                renderApp(data);
            } else {
                // Update live counters or results without wiping user's unsaved interaction
                const countEl = document.getElementById('liveVoteCount');
                if (countEl) countEl.textContent = data.vote_count;
                if (newStatus === 'closed') {
                    renderApp(data);
                }
            }
        } catch (e) {
            console.error('Chyba synchronizace:', e);
        }
    }

    function initLocalSelection(poll, savedVote) {
        if (!poll) return;
        const allIds = poll.options.map(o => o.id);

        if (savedVote && savedVote.payload) {
            const p = savedVote.payload;
            if (poll.type === 'single') {
                localChoice = p.choice || null;
            } else if (poll.type === 'multi') {
                localChoices = Array.isArray(p.choices) ? [...p.choices] : [];
            } else if (poll.type === 'ranking' || poll.type === 'ranking_partial') {
                localRanking = Array.isArray(p.ranking) ? [...p.ranking] : [];
            }
            return;
        }

        // Check if we have a local draft
        const draftRaw = localStorage.getItem(DRAFT_PREFIX + poll.id);
        if (draftRaw) {
            try {
                const d = JSON.parse(draftRaw);
                if (poll.type === 'single') localChoice = d.choice || null;
                if (poll.type === 'multi') localChoices = d.choices || [];
                if (poll.type === 'ranking' || poll.type === 'ranking_partial') localRanking = d.ranking || [];
                return;
            } catch (_) {}
        }

        // Default initial state
        localChoice = null;
        localChoices = [];
        if (poll.type === 'ranking') {
            localRanking = [...allIds];
        } else if (poll.type === 'ranking_partial') {
            localRanking = [];
        }
    }

    function saveDraft() {
        if (!currentPollData) return;
        const payload = buildPayload();
        localStorage.setItem(DRAFT_PREFIX + currentPollData.id, JSON.stringify(payload));
    }

    function buildPayload() {
        if (!currentPollData) return {};
        if (currentPollData.type === 'single') return { choice: localChoice };
        if (currentPollData.type === 'multi') return { choices: localChoices };
        if (currentPollData.type === 'ranking' || currentPollData.type === 'ranking_partial') {
            return { ranking: localRanking };
        }
        return {};
    }

    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str || '';
        return div.innerHTML;
    }

    function renderApp(data) {
        const container = document.getElementById('appContainer');
        const poll = data.poll;

        if (!poll || poll.status === 'draft') {
            container.innerHTML = `
                <div class="card waiting-box">
                    <div class="pulse-ring">⚜</div>
                    <h2 style="color: var(--skaut-dark); margin-bottom: 8px;">Čeká se na zahájení hlasování</h2>
                    <p style="color: var(--text-muted); max-width: 440px; margin: 0 auto;">
                        Jakmile administrátor vybere a spustí otázku, automaticky se vám zde zobrazí bez nutnosti obnovovat stránku.
                    </p>
                </div>
            `;
            return;
        }

        if (poll.status === 'closed') {
            renderClosedPoll(container, poll, data.results, data.my_vote, data.vote_count);
            return;
        }

        // Active poll
        let html = `<div class="card">`;
        html += `
            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
                <span class="poll-badge badge-active">● Probíhá hlasování</span>
                <span style="font-size:13px; color:var(--text-muted);">Odevzdáno hlasů: <strong id="liveVoteCount">${data.vote_count}</strong></span>
            </div>
            <h1 class="poll-title">${escapeHtml(poll.title)}</h1>
            ${poll.description ? `<p class="poll-desc">${escapeHtml(poll.description)}</p>` : ''}
        `;

        if (mySavedVote) {
            html += `
                <div class="vote-status-banner" id="savedBanner">
                    <span>✓ Váš hlas je zaznamenán (${isDirty ? 'máte neuloženou změnu níže' : 'můžete jej kdykoliv upravit'})</span>
                </div>
            `;
        }

        if (poll.type === 'single') {
            html += `<div class="option-list">`;
            for (const opt of poll.options) {
                const isSel = localChoice === opt.id;
                html += `
                    <button type="button" class="option-btn ${isSel ? 'selected' : ''}" data-action="pick-single" data-id="${escapeHtml(opt.id)}">
                        <div class="option-indicator">${isSel ? '✓' : ''}</div>
                        <div class="option-content">
                            <div class="option-label">${escapeHtml(opt.label)}</div>
                            ${opt.desc ? `<div class="option-desc">${escapeHtml(opt.desc)}</div>` : ''}
                        </div>
                    </button>
                `;
            }
            html += `</div>`;
        } else if (poll.type === 'multi') {
            html += `<div class="option-list">`;
            for (const opt of poll.options) {
                const isSel = localChoices.includes(opt.id);
                html += `
                    <button type="button" class="option-btn ${isSel ? 'selected' : ''}" data-action="pick-multi" data-id="${escapeHtml(opt.id)}">
                        <div class="option-indicator" style="border-radius:6px;">${isSel ? '✓' : ''}</div>
                        <div class="option-content">
                            <div class="option-label">${escapeHtml(opt.label)}</div>
                            ${opt.desc ? `<div class="option-desc">${escapeHtml(opt.desc)}</div>` : ''}
                        </div>
                    </button>
                `;
            }
            html += `</div>`;
        } else if (poll.type === 'ranking' || poll.type === 'ranking_partial') {
            const optById = {};
            poll.options.forEach(o => optById[o.id] = o);

            // Ensure valid IDs
            localRanking = localRanking.filter(id => optById[id]);
            if (poll.type === 'ranking') {
                poll.options.forEach(o => {
                    if (!localRanking.includes(o.id)) localRanking.push(o.id);
                });
            }

            html += `
                <div class="ranking-section-title">
                    <span>Vaše pořadí preferencí (1. = nejvyšší preference)</span>
                    <span style="font-size:11px; font-weight:normal; color:var(--text-muted);">Přetáhněte za ⠿ nebo použijte šipky ▲▼</span>
                </div>
            `;

            if (localRanking.length === 0) {
                html += `
                    <div style="padding:18px; border:2px dashed #cbbba6; border-radius:12px; text-align:center; color:var(--text-muted); font-size:14px; margin-bottom:12px;">
                        Zatím jste nevybrali žádnou variantu. Klikněte na přijatelné varianty v seznamu níže v pořadí, jak je preferujete.
                    </div>
                `;
            } else {
                html += `<div class="ranking-list" id="rankingList">`;
                localRanking.forEach((id, idx) => {
                    const opt = optById[id];
                    if (!opt) return;
                    const rankNum = idx + 1;
                    const movedClass = (lastMovedId === opt.id) ? 'just-moved' : '';
                    html += `
                        <div class="rank-item active-rank ${movedClass}" draggable="true" data-id="${escapeHtml(opt.id)}" data-idx="${idx}">
                            <div class="rank-drag-handle" title="Chytněte a přetáhněte na jinou pozici">⠿</div>
                            <div class="rank-number">${rankNum}.</div>
                            <div class="option-content">
                                <div class="option-label">${escapeHtml(opt.label)}</div>
                                ${opt.desc ? `<div class="option-desc">${escapeHtml(opt.desc)}</div>` : ''}
                            </div>
                            <div class="rank-controls">
                                <button type="button" class="rank-arrow" data-action="move-up" data-idx="${idx}" ${idx === 0 ? 'disabled' : ''} title="Posunout výše">▲</button>
                                <button type="button" class="rank-arrow" data-action="move-down" data-idx="${idx}" ${idx === localRanking.length - 1 ? 'disabled' : ''} title="Posunout níže">▼</button>
                            </div>
                            ${poll.type === 'ranking_partial' ? `
                                <button type="button" class="rank-remove-btn" data-action="remove-rank" data-id="${escapeHtml(opt.id)}" title="Odebrat z vybraných">✕</button>
                            ` : ''}
                        </div>
                    `;
                });
                html += `</div>`;
            }

            if (poll.type === 'ranking_partial') {
                const unranked = poll.options.filter(o => !localRanking.includes(o.id));
                if (unranked.length > 0) {
                    html += `
                        <div class="ranking-section-title" style="margin-top:22px; color:var(--text-muted);">
                            <span>Nezařazené varianty (kliknutím přidáte do svého výběru)</span>
                        </div>
                        <div class="ranking-list">
                    `;
                    unranked.forEach(opt => {
                        html += `
                            <div class="pool-item" data-action="add-rank" data-id="${escapeHtml(opt.id)}">
                                <div class="pool-add-icon">+</div>
                                <div class="option-content">
                                    <div class="option-label">${escapeHtml(opt.label)}</div>
                                    ${opt.desc ? `<div class="option-desc">${escapeHtml(opt.desc)}</div>` : ''}
                                </div>
                            </div>
                        `;
                    });
                    html += `</div>`;
                }
            }
        }

        const btnLabel = mySavedVote
            ? (isDirty ? '💾 Uložit změnu mého hlasu' : '✓ Hlas je odeslán (Klikněte pro opětné uložení)')
            : '🗳️ Odeslat můj hlas';

        html += `
            <div class="submit-bar">
                <button type="button" class="btn-primary ${mySavedVote && !isDirty ? 'saved' : ''}" id="submitVoteBtn">
                    ${btnLabel}
                </button>
            </div>
        `;
        html += `</div>`;

        container.innerHTML = html;
        bindInteractiveEvents();
    }

    function renderClosedPoll(container, poll, results, myVote, voteCount) {
        const optById = {};
        poll.options.forEach(o => optById[o.id] = o);

        let html = `<div class="card">`;
        html += `
            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
                <span class="poll-badge badge-closed">■ Hlasování ukončeno</span>
                <span style="font-size:13px; color:var(--text-muted);">Celkem hlasovalo: <strong>${voteCount}</strong> osob</span>
            </div>
            <h1 class="poll-title">${escapeHtml(poll.title)}</h1>
            ${poll.description ? `<p class="poll-desc">${escapeHtml(poll.description)}</p>` : ''}
        `;

        if (myVote && myVote.payload) {
            let mySummary = '';
            if (poll.type === 'single' && optById[myVote.payload.choice]) {
                mySummary = escapeHtml(optById[myVote.payload.choice].label);
            } else if (poll.type === 'multi' && Array.isArray(myVote.payload.choices)) {
                mySummary = myVote.payload.choices.map(id => optById[id] ? escapeHtml(optById[id].label) : '').join(', ');
            } else if ((poll.type === 'ranking' || poll.type === 'ranking_partial') && Array.isArray(myVote.payload.ranking)) {
                mySummary = myVote.payload.ranking.map((id, i) => `${i+1}. ${optById[id] ? escapeHtml(optById[id].label) : ''}`).join(' → ');
            }
            if (mySummary) {
                html += `
                    <div class="vote-status-banner" style="background:#f1f6fc; border-color:#c2d4e8; color:var(--skaut-dark);">
                        <div><strong>Vaše odeslaná volba:</strong><br><span style="font-weight:normal; font-size:13px;">${mySummary}</span></div>
                    </div>
                `;
            }
        }

        if (results && results.sorted_items) {
            html += `<h3 style="margin: 20px 0 12px; color: var(--skaut-dark); font-size: 17px;">Celkové výsledky hlasování</h3>`;
            results.sorted_items.forEach((item, idx) => {
                if (poll.type === 'single' || poll.type === 'multi') {
                    html += `
                        <div class="result-row">
                            <div class="result-header">
                                <span class="result-title">${idx + 1}. ${escapeHtml(item.label)}</span>
                                <span class="result-score">${item.votes} hlasů (${item.percentage} %)</span>
                            </div>
                            <div class="progress-track">
                                <div class="progress-fill" style="width: ${item.percentage}%;"></div>
                            </div>
                        </div>
                    `;
                } else {
                    const firstPlaces = item.rank_counts ? (item.rank_counts[1] || 0) : 0;
                    html += `
                        <div class="result-row">
                            <div class="result-header">
                                <span class="result-title">${idx + 1}. ${escapeHtml(item.label)}</span>
                                <span class="result-score">${item.borda_points} b. (${item.borda_pct} %)</span>
                            </div>
                            <div class="progress-track">
                                <div class="progress-fill" style="width: ${item.borda_pct}%;"></div>
                            </div>
                            <div class="result-meta">
                                Průměrné pořadí: <strong>${item.avg_rank !== null ? item.avg_rank + '.' : '—'}</strong> ·
                                1. míst: <strong>${firstPlaces}×</strong> ·
                                Zařadilo: <strong>${item.ranked_count}</strong> hlasujících (${item.approval_pct} %)
                            </div>
                        </div>
                    `;
                }
            });
        } else {
            html += `<p style="color:var(--text-muted); text-align:center; padding:20px 0;">Výsledky jsou prezentovány administrátorem na plátně.</p>`;
        }

        html += `</div>`;
        container.innerHTML = html;
    }

    function bindInteractiveEvents() {
        const container = document.getElementById('appContainer');

        container.querySelectorAll('[data-action="pick-single"]').forEach(btn => {
            btn.addEventListener('click', () => {
                localChoice = btn.getAttribute('data-id');
                isDirty = true;
                saveDraft();
                renderApp({ poll: currentPollData, my_vote: mySavedVote, vote_count: document.getElementById('liveVoteCount')?.textContent || 0 });
                // For single choice, immediately submit on tap for snappy UX
                submitVote();
            });
        });

        container.querySelectorAll('[data-action="pick-multi"]').forEach(btn => {
            btn.addEventListener('click', () => {
                const id = btn.getAttribute('data-id');
                if (localChoices.includes(id)) {
                    localChoices = localChoices.filter(x => x !== id);
                } else {
                    localChoices.push(id);
                }
                isDirty = true;
                saveDraft();
                renderApp({ poll: currentPollData, my_vote: mySavedVote, vote_count: document.getElementById('liveVoteCount')?.textContent || 0 });
            });
        });

        container.querySelectorAll('[data-action="move-up"]').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                const idx = parseInt(btn.getAttribute('data-idx'), 10);
                if (idx > 0) {
                    lastMovedId = localRanking[idx];
                    const tmp = localRanking[idx - 1];
                    localRanking[idx - 1] = localRanking[idx];
                    localRanking[idx] = tmp;
                    isDirty = true;
                    saveDraft();
                    renderApp({ poll: currentPollData, my_vote: mySavedVote, vote_count: document.getElementById('liveVoteCount')?.textContent || 0 });
                }
            });
        });

        container.querySelectorAll('[data-action="move-down"]').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                const idx = parseInt(btn.getAttribute('data-idx'), 10);
                if (idx < localRanking.length - 1) {
                    lastMovedId = localRanking[idx];
                    const tmp = localRanking[idx + 1];
                    localRanking[idx + 1] = localRanking[idx];
                    localRanking[idx] = tmp;
                    isDirty = true;
                    saveDraft();
                    renderApp({ poll: currentPollData, my_vote: mySavedVote, vote_count: document.getElementById('liveVoteCount')?.textContent || 0 });
                }
            });
        });

        container.querySelectorAll('[data-action="add-rank"]').forEach(el => {
            el.addEventListener('click', () => {
                const id = el.getAttribute('data-id');
                if (!localRanking.includes(id)) {
                    lastMovedId = id;
                    localRanking.push(id);
                    isDirty = true;
                    saveDraft();
                    renderApp({ poll: currentPollData, my_vote: mySavedVote, vote_count: document.getElementById('liveVoteCount')?.textContent || 0 });
                }
            });
        });

        container.querySelectorAll('[data-action="remove-rank"]').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                const id = btn.getAttribute('data-id');
                lastMovedId = null;
                localRanking = localRanking.filter(x => x !== id);
                isDirty = true;
                saveDraft();
                renderApp({ poll: currentPollData, my_vote: mySavedVote, vote_count: document.getElementById('liveVoteCount')?.textContent || 0 });
            });
        });

        // Interactive Drag and Drop with visual target placeholder (Desktop + Touch)
        const rankingList = document.getElementById('rankingList');
        if (rankingList) {
            let dragSrcIdx = null;
            let currentDropIdx = null;
            let draggedLabel = '';

            const placeholder = document.createElement('div');
            placeholder.className = 'drop-placeholder';

            function updatePlaceholderPosition(clientY) {
                if (dragSrcIdx === null) return;
                const items = Array.from(rankingList.querySelectorAll('.rank-item'));
                let insertBeforeItem = null;
                let targetSlot = items.length; // 0 .. items.length

                for (let i = 0; i < items.length; i++) {
                    const rect = items[i].getBoundingClientRect();
                    const midY = rect.top + rect.height / 2;
                    if (clientY < midY) {
                        insertBeforeItem = items[i];
                        targetSlot = i;
                        break;
                    }
                }

                // Compute effective final index after removing dragSrcIdx
                let effectiveIdx = targetSlot > dragSrcIdx ? targetSlot - 1 : targetSlot;
                if (effectiveIdx < 0) effectiveIdx = 0;
                if (effectiveIdx >= items.length) effectiveIdx = items.length - 1;
                currentDropIdx = effectiveIdx;

                placeholder.innerHTML = `
                    <span class="drop-placeholder-badge">➜ Umístit na ${effectiveIdx + 1}. místo</span>
                    <span style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:260px;">${escapeHtml(draggedLabel)}</span>
                `;

                if (insertBeforeItem) {
                    rankingList.insertBefore(placeholder, insertBeforeItem);
                } else {
                    rankingList.appendChild(placeholder);
                }
            }

            function finishDrop() {
                if (placeholder.parentNode) {
                    placeholder.parentNode.removeChild(placeholder);
                }
                if (dragSrcIdx !== null && currentDropIdx !== null && dragSrcIdx !== currentDropIdx) {
                    const moved = localRanking.splice(dragSrcIdx, 1)[0];
                    localRanking.splice(currentDropIdx, 0, moved);
                    lastMovedId = moved;
                    isDirty = true;
                    saveDraft();
                    dragSrcIdx = null;
                    currentDropIdx = null;
                    renderApp({ poll: currentPollData, my_vote: mySavedVote, vote_count: document.getElementById('liveVoteCount')?.textContent || 0 });
                } else {
                    dragSrcIdx = null;
                    currentDropIdx = null;
                }
            }

            rankingList.querySelectorAll('.rank-item').forEach(item => {
                // HTML5 Desktop Drag
                item.addEventListener('dragstart', (e) => {
                    dragSrcIdx = parseInt(item.getAttribute('data-idx'), 10);
                    currentDropIdx = dragSrcIdx;
                    draggedLabel = item.querySelector('.option-label')?.textContent || '';
                    e.dataTransfer.effectAllowed = 'move';
                    e.dataTransfer.setData('text/plain', String(dragSrcIdx));
                    setTimeout(() => item.classList.add('dragging'), 0);
                });

                item.addEventListener('dragend', () => {
                    item.classList.remove('dragging');
                    finishDrop();
                });

                // Mobile Touch Drag via handle (.rank-drag-handle)
                const handle = item.querySelector('.rank-drag-handle');
                if (handle) {
                    handle.addEventListener('touchstart', (e) => {
                        dragSrcIdx = parseInt(item.getAttribute('data-idx'), 10);
                        currentDropIdx = dragSrcIdx;
                        draggedLabel = item.querySelector('.option-label')?.textContent || '';
                        item.classList.add('dragging');
                    }, { passive: true });

                    handle.addEventListener('touchmove', (e) => {
                        if (dragSrcIdx === null) return;
                        e.preventDefault();
                        const touch = e.touches[0];
                        updatePlaceholderPosition(touch.clientY);
                    }, { passive: false });

                    handle.addEventListener('touchend', () => {
                        item.classList.remove('dragging');
                        finishDrop();
                    });
                }
            });

            rankingList.addEventListener('dragover', (e) => {
                e.preventDefault();
                e.dataTransfer.dropEffect = 'move';
                updatePlaceholderPosition(e.clientY);
            });

            rankingList.addEventListener('drop', (e) => {
                e.preventDefault();
                finishDrop();
            });
        }

        const submitBtn = document.getElementById('submitVoteBtn');
        if (submitBtn) {
            submitBtn.addEventListener('click', submitVote);
        }
    }

    async function submitVote() {
        if (!currentPollData || isSubmitting) return;
        const payload = buildPayload();

        if (currentPollData.type === 'single' && !payload.choice) {
            alert('Vyberte prosím jednu z možností.');
            return;
        }
        if (currentPollData.type === 'multi' && (!payload.choices || payload.choices.length === 0)) {
            alert('Vyberte prosím alespoň jednu možnost.');
            return;
        }
        if (currentPollData.type === 'ranking_partial' && (!payload.ranking || payload.ranking.length === 0)) {
            alert('Vyberte prosím alespoň jednu přijatelnou variantu kliknutím na +.');
            return;
        }

        isSubmitting = true;
        const submitBtn = document.getElementById('submitVoteBtn');
        if (submitBtn) {
            submitBtn.textContent = '⏳ Ukládám hlas…';
            submitBtn.disabled = true;
        }

        try {
            const res = await fetch('api.php?action=submit_vote', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    voter_id: voterId,
                    poll_id: currentPollData.id,
                    payload: payload
                })
            });
            const data = await res.json();
            if (!data.ok) {
                alert(data.error || 'Nepodařilo se uložit hlas.');
            } else {
                mySavedVote = data.my_vote;
                isDirty = false;
                saveDraft();
                renderApp({
                    poll: currentPollData,
                    my_vote: mySavedVote,
                    vote_count: data.vote_count
                });
            }
        } catch (err) {
            alert('Chyba připojení k serveru při ukládání hlasu.');
        } finally {
            isSubmitting = false;
        }
    }

    fetchState();
    setInterval(fetchState, 2000);
})();
</script>
</body>
</html>
