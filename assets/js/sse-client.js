/**
 * ============================================================
 * Server-Sent Events (SSE) Client with Magic UI Live Scoring
 * ============================================================
 */

document.addEventListener('DOMContentLoaded', () => {
    const liveScoreContainer = document.getElementById('live-matches-container');
    if (!liveScoreContainer) return;

    let evtSource = null;

    function getSseUrl() {
        const basePath = window.BASE_URL || (window.location.pathname.includes('/Badminton') ? '/Badminton' : '');
        return basePath + '/sse/live.php';
    }

    function connectSSE() {
        if (evtSource) {
            evtSource.close();
        }

        const url = getSseUrl();
        evtSource = new EventSource(url);

        evtSource.onmessage = function(event) {
            try {
                const data = JSON.parse(event.data);
                updateLiveScores(data.live_matches);
            } catch (err) {
                console.error("SSE parse error", err);
            }
        };

        evtSource.onerror = function() {
            evtSource.close();
            setTimeout(connectSSE, 4000);
        };
    }

    function updateLiveScores(matches) {
        if (!matches || matches.length === 0) {
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

        let html = '';
        matches.forEach(match => {
            html += buildMatchCard(match);
        });

        liveScoreContainer.innerHTML = html;
    }

    function buildMatchCard(match) {
        const gamesToWin = Math.ceil((match.best_of || 3) / 2);
        
        const buildGameDots = (gamesWon) => {
            let dots = '';
            for (let i = 0; i < gamesToWin; i++) {
                if (gamesWon > i) {
                    dots += `<div class="w-3 h-3 rounded-full bg-[#c9a84c] shadow-[0_0_8px_rgba(201,168,76,0.6)]"></div>`;
                } else {
                    dots += `<div class="w-3 h-3 rounded-full bg-slate-200 border border-slate-300"></div>`;
                }
            }
            return `<div class="flex gap-1.5 justify-center mt-2.5">${dots}</div>`;
        };

        return `
            <div class="border-beam-container bg-white rounded-2xl shadow-md border border-slate-200 flex flex-col justify-between overflow-hidden hover-lift" style="--color-from:#ef4444; --color-to:#c9a84c;">
                <div class="border-beam"></div>
                
                <!-- Live Header -->
                <div class="bg-gradient-to-r from-red-600 to-red-700 text-white text-[10px] font-black uppercase tracking-widest text-center py-2 px-4 flex items-center justify-between shadow-inner">
                    <span class="flex items-center gap-1.5">
                        <span class="w-2 h-2 bg-white rounded-full animate-ping"></span>
                        <span>● LIVE ON COURT</span>
                    </span>
                    <span class="opacity-90 font-mono text-[9px]">${escapeHtml(match.tournament_name || 'Tournament')}</span>
                </div>
                
                <div class="p-6 grid grid-cols-[1fr_auto_1fr] items-center gap-4 flex-1 bg-white">
                    
                    <!-- Player A -->
                    <div class="text-center flex flex-col justify-center h-full">
                        <div class="font-black text-slate-800 text-base leading-snug line-clamp-2" title="${escapeHtml(match.player_a)}">${escapeHtml(match.player_a)}</div>
                        ${buildGameDots(match.games_a || 0)}
                    </div>

                    <!-- Live Scores -->
                    <div class="flex items-center justify-center gap-3 bg-[#0f2044] text-white px-5 py-3 rounded-2xl shadow-lg border border-slate-700">
                        <div class="text-3xl font-black w-10 text-center font-display text-white" id="score_a_${match.id}">${match.score_a || 0}</div>
                        <div class="text-[#c9a84c] font-black text-xl mb-0.5">:</div>
                        <div class="text-3xl font-black w-10 text-center font-display text-white" id="score_b_${match.id}">${match.score_b || 0}</div>
                    </div>

                    <!-- Player B -->
                    <div class="text-center flex flex-col justify-center h-full">
                        <div class="font-black text-slate-800 text-base leading-snug line-clamp-2" title="${escapeHtml(match.player_b)}">${escapeHtml(match.player_b)}</div>
                        ${buildGameDots(match.games_b || 0)}
                    </div>
                </div>
                
                <div class="bg-slate-50 border-t border-slate-100 px-4 py-2 flex items-center justify-between text-[11px] font-bold text-slate-500">
                    <span class="uppercase tracking-wider">Race to ${match.points_per_game || 11} Pts</span>
                    <span class="text-blue-600 font-bold">Best of ${match.best_of || 3}</span>
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

    connectSSE();
});
