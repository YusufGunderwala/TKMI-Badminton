/**
 * ============================================================
 * Server-Sent Events (SSE) Client + Real-time Hub Poller
 * Ultra-responsive dual-channel sync (sub-second latency)
 * ============================================================
 */

document.addEventListener('DOMContentLoaded', () => {
    const liveScoreContainer = document.getElementById('live-matches-container');
    const heroScoreEl = document.getElementById('hero-match-score');

    let evtSource = null;
    let sseConnected = false;
    let lastSseMessageAt = 0;

    function getBaseUrl() {
        if (window.BASE_URL) return window.BASE_URL;
        if (window.location.pathname.includes('/Badminton')) return '/Badminton';
        return '';
    }

    function getSseUrl() {
        return getBaseUrl() + '/sse/live.php';
    }

    function getApiUrl() {
        return getBaseUrl() + '/api/matches.php';
    }

    function connectSSE() {
        if (evtSource) {
            try { evtSource.close(); } catch(e) {}
        }

        try {
            const url = getSseUrl();
            evtSource = new EventSource(url);

            evtSource.onopen = function() {
                sseConnected = true;
            };

            evtSource.onmessage = function(event) {
                try {
                    const data = JSON.parse(event.data);
                    const matches = data ? (data.live_matches || data.matches) : null;
                    if (matches) {
                        updateLiveScores(matches);
                    }
                    sseConnected = true;
                    lastSseMessageAt = Date.now();
                } catch (err) {
                    console.error("SSE parse error", err);
                }
            };

            evtSource.onerror = function() {
                sseConnected = false;
                try { evtSource.close(); } catch(e) {}
                setTimeout(connectSSE, 1500);
            };
        } catch(e) {
            sseConnected = false;
            setTimeout(connectSSE, 2000);
        }
    }

    // HTTP poller — only used as a fallback when SSE is down or has gone quiet,
    // so we don't double-query the DB while SSE is healthy
    async function pollLiveMatches() {
        const sseStale = Date.now() - lastSseMessageAt > 8000;
        if (sseConnected && !sseStale) return;
        try {
            const res = await fetch(getApiUrl(), { cache: 'no-store' });
            if (!res.ok) return;
            const data = await res.json();
            if (data && data.success && data.matches) {
                const liveList = data.live_matches || data.matches.filter(m => m.status === 'in_progress');
                updateLiveScores(liveList);
            }
        } catch(e) {}
    }

    function updateLiveScores(matches) {
        // 1. Update Hero match tile if exists
        if (heroScoreEl && matches && matches.length > 0) {
            const cur = matches[0];
            const scoreText = (cur.score_a ?? 0) + ' - ' + (cur.score_b ?? 0);
            if (heroScoreEl.innerText.trim() !== scoreText) {
                heroScoreEl.innerText = scoreText;
            }
            const gA = document.getElementById('hero-games-a');
            const gB = document.getElementById('hero-games-b');
            if (gA) gA.innerText = 'Games: ' + (cur.games_a ?? 0);
            if (gB) gB.innerText = 'Games: ' + (cur.games_b ?? 0);
            const nA = document.getElementById('hero-name-a');
            const nB = document.getElementById('hero-name-b');
            if (nA && (cur.display_a || cur.player_a)) nA.innerText = cur.display_a || cur.player_a;
            if (nB && (cur.display_b || cur.player_b)) nB.innerText = cur.display_b || cur.player_b;
        }

        // 2. Update Live matches cards container
        if (!liveScoreContainer) return;

        // Track completed matches so they linger for 15 seconds with celebration banner
        const now = Date.now();
        if (!window._completedMatchTimers) window._completedMatchTimers = {};

        // Filter active matches: in_progress, OR completed within last 15s
        const displayMatches = [];
        const liveIds = new Set();

        (matches || []).forEach(m => {
            const isCompleted = m.status === 'completed' || m.status === 'walkover' || m.status === 'retired' || m.is_completed;
            if (!isCompleted) {
                displayMatches.push(m);
                liveIds.add(m.id);
            } else {
                // If it just completed, record timestamp if not recorded yet
                if (!window._completedMatchTimers[m.id]) {
                    window._completedMatchTimers[m.id] = now;
                }
                const elapsed = now - window._completedMatchTimers[m.id];
                if (elapsed < 15000) { // keep for 15 seconds
                    displayMatches.push(m);
                    liveIds.add(m.id);
                }
            }
        });

        if (displayMatches.length === 0) {
            liveScoreContainer.innerHTML = `
                <div class="col-span-full py-16 text-center bg-white rounded-2xl border border-dashed border-slate-300 p-8 shadow-sm">
                    <div class="w-14 h-14 rounded-full bg-slate-50 flex items-center justify-center mx-auto mb-3 text-slate-400">
                        <i class="ph-fill ph-broadcast text-3xl"></i>
                    </div>
                    <h4 class="text-base font-bold text-slate-700">No Matches Live Right Now</h4>
                    <p class="text-slate-400 text-xs mt-1">Live scores will appear instantly as matches begin on court.</p>
                </div>
            `;
            return;
        }

        // Clean up empty placeholder if exists
        const placeholder = liveScoreContainer.querySelector('.border-dashed');
        if (placeholder) placeholder.remove();

        // IN-PLACE UPDATE / INSERT (preserves fixed positions!)
        displayMatches.forEach(match => {
            let card = document.getElementById('live-card-' + match.id);
            if (!card) {
                // Insert new match card at the end without rearranging existing ones
                const tempDiv = document.createElement('div');
                tempDiv.innerHTML = buildMatchCard(match);
                card = tempDiv.firstElementChild;
                liveScoreContainer.appendChild(card);
            }

            // Update scores in place
            const sA = document.getElementById('score_a_' + match.id);
            const sB = document.getElementById('score_b_' + match.id);
            const scoreAVal = match.score_a ?? match.game_score_a ?? 0;
            const scoreBVal = match.score_b ?? match.game_score_b ?? 0;

            if (sA) {
                const curValA = parseInt(sA.innerText.trim()) || 0;
                if (curValA !== scoreAVal) {
                    sA.innerText = scoreAVal;
                    sA.classList.add('scale-125', 'text-amber-400');
                    setTimeout(() => sA.classList.remove('scale-125', 'text-amber-400'), 400);
                }
            }
            if (sB) {
                const curValB = parseInt(sB.innerText.trim()) || 0;
                if (curValB !== scoreBVal) {
                    sB.innerText = scoreBVal;
                    sB.classList.add('scale-125', 'text-amber-400');
                    setTimeout(() => sB.classList.remove('scale-125', 'text-amber-400'), 400);
                }
            }

            // Update games won dots in place
            const dotsContainer = card.querySelector('.game-dots-' + match.id);
            if (dotsContainer) {
                const gamesToWin = Math.ceil((match.best_of || 3) / 2);
                dotsContainer.innerHTML = buildGameDots(match.games_a || 0, gamesToWin);
            }
            const dotsContainerB = card.querySelector('.game-dots-b-' + match.id);
            if (dotsContainerB) {
                const gamesToWin = Math.ceil((match.best_of || 3) / 2);
                dotsContainerB.innerHTML = buildGameDots(match.games_b || 0, gamesToWin);
            }

            // Update completed sets breakdown in place
            const cardGamesEl = document.getElementById('live-card-games-' + match.id);
            const cardGamesListEl = document.getElementById('live-card-games-list-' + match.id);
            if (cardGamesEl && cardGamesListEl) {
                if (match.games && match.games.length > 0) {
                    cardGamesEl.classList.remove('hidden');
                    cardGamesEl.classList.add('flex');
                    cardGamesListEl.innerHTML = match.games.map(g => {
                        const wSide = g.winner_side || (g.score_a > g.score_b ? 'A' : (g.score_b > g.score_a ? 'B' : null));
                        const wName = g.winner_name || (wSide === 'A' ? (match.display_a || match.player_a) : (wSide === 'B' ? (match.display_b || match.player_b) : ''));
                        return `
                            <span class="inline-flex items-center gap-1 bg-white px-2 py-0.5 rounded-md border border-slate-200 text-slate-700 font-mono text-[10px] font-bold shadow-2xs">
                                <span>Set ${g.game_number}: ${g.score_a}-${g.score_b}</span>
                                ${wName ? `<span class="text-emerald-700 font-black">(${escapeHtml(wName)})</span>` : ''}
                            </span>
                        `;
                    }).join('');
                } else {
                    cardGamesEl.classList.add('hidden');
                    cardGamesEl.classList.remove('flex');
                    cardGamesListEl.innerHTML = '';
                }
            }

            // Check completion / Winner celebration banner
            const isCompleted = match.status === 'completed' || match.status === 'walkover' || match.status === 'retired' || match.is_completed;
            const headerEl = document.getElementById('live-card-header-' + match.id);
            const statusEl = document.getElementById('live-card-status-' + match.id);

            if (isCompleted && headerEl && statusEl) {
                // Determine winner name
                const winnerName = match.winner_name || (match.score_a > match.score_b ? (match.display_a || match.player_a) : (match.display_b || match.player_b));
                headerEl.className = 'bg-gradient-to-r from-emerald-600 via-emerald-500 to-teal-600 text-white text-[11px] font-black uppercase tracking-widest text-center py-2 px-4 flex items-center justify-between shadow-md transition-all duration-500';
                statusEl.innerHTML = `
                    <span class="flex items-center gap-1.5 animate-bounce">
                        <i class="ph-fill ph-trophy text-amber-300 text-sm"></i>
                        <span>WINNER: ${escapeHtml(winnerName || 'VICTORY')}</span>
                    </span>
                `;
                card.style.borderColor = '#10b981';
                card.classList.add('ring-2', 'ring-emerald-300');
            }
        });

        // Remove cards that are no longer in displayMatches
        Array.from(liveScoreContainer.children).forEach(child => {
            const idMatch = child.id ? child.id.match(/^live-card-(\d+)$/) : null;
            if (idMatch) {
                const id = parseInt(idMatch[1]);
                if (!liveIds.has(id)) {
                    child.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
                    child.style.opacity = '0';
                    child.style.transform = 'scale(0.95)';
                    setTimeout(() => child.remove(), 600);
                }
            }
        });
    }

    function buildGameDots(gamesWon, gamesToWin) {
        let dots = '';
        for (let i = 0; i < gamesToWin; i++) {
            if (gamesWon > i) {
                dots += `<div class="w-2.5 h-2.5 sm:w-3 sm:h-3 rounded-full bg-[#c9a84c] shadow-[0_0_8px_rgba(201,168,76,0.6)]"></div>`;
            } else {
                dots += `<div class="w-2.5 h-2.5 sm:w-3 sm:h-3 rounded-full bg-slate-200 border border-slate-300"></div>`;
            }
        }
        return dots;
    }

    function buildMatchCard(match) {
        const gamesToWin = Math.ceil((match.best_of || 3) / 2);
        const isCompleted = match.status === 'completed' || match.status === 'walkover' || match.status === 'retired' || match.is_completed;
        const winnerName = match.winner_name || (match.score_a > match.score_b ? (match.display_a || match.player_a) : (match.display_b || match.player_b));

        const headerBg = isCompleted 
            ? 'bg-gradient-to-r from-emerald-600 via-emerald-500 to-teal-600' 
            : 'bg-gradient-to-r from-red-600 to-red-700';

        const statusHtml = isCompleted
            ? `<span class="flex items-center gap-1.5 animate-bounce"><i class="ph-fill ph-trophy text-amber-300 text-sm"></i><span>WINNER: ${escapeHtml(winnerName || 'VICTORY')}</span></span>`
            : `<span class="flex items-center gap-1.5"><span class="w-2 h-2 bg-white rounded-full animate-ping"></span><span>● LIVE ON COURT</span></span>`;

        return `
            <div id="live-card-${match.id}" class="bg-white rounded-2xl shadow-md border ${isCompleted ? 'border-emerald-500 ring-2 ring-emerald-300' : 'border-slate-200'} flex flex-col justify-between overflow-hidden hover-lift transition-all duration-500">
                <!-- Live Header -->
                <div id="live-card-header-${match.id}" class="${headerBg} text-white text-[10px] font-black uppercase tracking-widest text-center py-2 px-4 flex items-center justify-between shadow-inner transition-colors duration-500">
                    <span id="live-card-status-${match.id}">
                        ${statusHtml}
                    </span>
                    <span class="opacity-90 font-mono text-[9px]">${escapeHtml(match.tournament_name || 'Tournament')}</span>
                </div>
                
                <div class="p-3 sm:p-6 grid grid-cols-[1fr_auto_1fr] items-center gap-2 sm:gap-4 flex-1 bg-white">
                    <!-- Player A -->
                    <div class="text-center flex flex-col justify-center h-full">
                        <div class="font-black text-slate-800 text-sm sm:text-base leading-snug line-clamp-2" title="${escapeHtml(match.display_a || match.player_a || 'Player A')}">${escapeHtml(match.display_a || match.player_a || 'Player A')}</div>
                        <div class="game-dots-${match.id} flex gap-1 sm:gap-1.5 justify-center mt-1.5 sm:mt-2.5">
                            ${buildGameDots(match.games_a || 0, gamesToWin)}
                        </div>
                    </div>

                    <!-- Live Scores -->
                    <div class="flex items-center justify-center gap-1.5 sm:gap-3 bg-[#0f2044] text-white px-3 sm:px-5 py-2 sm:py-3 rounded-xl sm:rounded-2xl shadow-lg border border-slate-700">
                        <div class="text-2xl sm:text-3xl font-black w-8 sm:w-10 text-center font-display text-white transition-all duration-300" id="score_a_${match.id}">${match.score_a ?? match.game_score_a ?? 0}</div>
                        <div class="text-[#c9a84c] font-black text-lg sm:text-xl mb-0.5">:</div>
                        <div class="text-2xl sm:text-3xl font-black w-8 sm:w-10 text-center font-display text-white transition-all duration-300" id="score_b_${match.id}">${match.score_b ?? match.game_score_b ?? 0}</div>
                    </div>

                    <!-- Player B -->
                    <div class="text-center flex flex-col justify-center h-full">
                        <div class="font-black text-slate-800 text-sm sm:text-base leading-snug line-clamp-2" title="${escapeHtml(match.display_b || match.player_b || 'Player B')}">${escapeHtml(match.display_b || match.player_b || 'Player B')}</div>
                        <div class="game-dots-b-${match.id} flex gap-1 sm:gap-1.5 justify-center mt-1.5 sm:mt-2.5">
                            ${buildGameDots(match.games_b || 0, gamesToWin)}
                        </div>
                    </div>
                </div>
                
                <!-- Completed Sets Breakdown -->
                <div id="live-card-games-${match.id}" class="${(match.games && match.games.length > 0) ? 'flex' : 'hidden'} bg-slate-50 border-t border-slate-100 px-4 py-2 flex-wrap items-center gap-1.5 text-[11px]">
                    <span class="text-[10px] font-black uppercase tracking-wider text-slate-400 mr-1">Previous Sets:</span>
                    <div class="flex flex-wrap items-center gap-1.5" id="live-card-games-list-${match.id}">
                        ${(match.games || []).map(g => {
                            const wSide = g.winner_side || (g.score_a > g.score_b ? 'A' : (g.score_b > g.score_a ? 'B' : null));
                            const wName = g.winner_name || (wSide === 'A' ? (match.display_a || match.player_a) : (wSide === 'B' ? (match.display_b || match.player_b) : ''));
                            return `
                                <span class="inline-flex items-center gap-1 bg-white px-2 py-0.5 rounded-md border border-slate-200 text-slate-700 font-mono text-[10px] font-bold shadow-2xs">
                                    <span>Set ${g.game_number}: ${g.score_a}-${g.score_b}</span>
                                    ${wName ? `<span class="text-emerald-700 font-black">(${escapeHtml(wName)})</span>` : ''}
                                </span>
                            `;
                        }).join('')}
                    </div>
                </div>

                <div class="bg-slate-50 border-t border-slate-100 px-4 py-2 flex items-center justify-between text-[11px] font-bold text-slate-500">
                    <span class="uppercase tracking-wider">Race to ${match.points_per_game || 11} Pts ${match.round_label ? `&bull; ${escapeHtml(match.round_label)}` : ''}</span>
                    <span class="text-blue-600 font-bold">Best of ${match.best_of || 3} Sets</span>
                </div>
            </div>
        `;
    }

    function escapeHtml(unsafe) {
        if (!unsafe) return '';
        return String(unsafe)
             .replace(/&/g, "&amp;")
             .replace(/</g, "&lt;")
             .replace(/>/g, "&gt;")
             .replace(/"/g, "&quot;")
             .replace(/'/g, "&#039;");
    }

    // Start SSE stream immediately
    connectSSE();

    // Watchdog: checks every 5s, but only actually hits the API when SSE is
    // down or has gone quiet (see pollLiveMatches) — avoids double-polling the DB
    setInterval(pollLiveMatches, 5000);
});

