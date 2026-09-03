<?php
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireLogin();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$tournament = getTournament($id);

if (!$tournament) {
    flash_set('tournaments', 'Tournament not found.', 'error');
    header('Location: ' . BASE_URL . '/admin/tournaments/');
    exit;
}

if ($tournament['match_type'] !== 'doubles') {
    flash_set('tournaments', 'This is a singles tournament.', 'error');
    header('Location: view.php?id=' . $id);
    exit;
}

$pdo = db();

// Cannot modify teams if tournament enrollment is locked or started
$hasMatches = ($tournament['status'] !== 'draft');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$hasMatches) {
    if (!verify_csrf()) {
        flash_set('tournament_teams', 'Invalid security token.', 'error');
    } else {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'create_team') {
            $p1 = (int)($_POST['player1_id'] ?? 0);
            $p2 = (int)($_POST['player2_id'] ?? 0);
            
            if ($p1 > 0 && $p2 > 0 && $p1 !== $p2) {
                // Ensure order
                if ($p1 > $p2) {
                    $temp = $p1;
                    $p1 = $p2;
                    $p2 = $temp;
                }
                
                // Get names
                $stmt = $pdo->prepare('SELECT id, display_name FROM players WHERE id IN (?, ?)');
                $stmt->execute([$p1, $p2]);
                $players = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
                
                if (count($players) === 2) {
                    $teamName = $players[$p1] . ' & ' . $players[$p2];
                    
                    try {
                        $stmt = $pdo->prepare('INSERT INTO teams (tournament_id, player1_id, player2_id, display_name) VALUES (?, ?, ?, ?)');
                        $stmt->execute([$id, $p1, $p2, $teamName]);
                        flash_set('tournament_teams', "Team \"$teamName\" created successfully.", 'success');
                    } catch (PDOException $e) {
                        flash_set('tournament_teams', 'Error creating team. Maybe they are already paired?', 'error');
                    }
                } else {
                    flash_set('tournament_teams', 'Invalid players selected.', 'error');
                }
            } else {
                flash_set('tournament_teams', 'Please select two different players.', 'error');
            }
        }
        elseif ($action === 'delete_team') {
            $team_id = (int)($_POST['team_id'] ?? 0);
            $pdo->prepare('DELETE FROM teams WHERE id = ? AND tournament_id = ?')->execute([$team_id, $id]);
            flash_set('tournament_teams', 'Team removed successfully.', 'success');
        }
    }
    header("Location: teams.php?id=$id");
    exit;
}

// Fetch enrolled players
$stmt = $pdo->prepare('
    SELECT p.id, p.display_name, p.mohallah 
    FROM tournament_players tp 
    JOIN players p ON tp.player_id = p.id 
    WHERE tp.tournament_id = ? 
    ORDER BY p.display_name ASC
');
$stmt->execute([$id]);
$enrolledPlayers = $stmt->fetchAll();

// Fetch existing teams
$stmt = $pdo->prepare('
    SELECT t.*, 
           p1.display_name as p1_name, p1.photo_path as p1_photo,
           p2.display_name as p2_name, p2.photo_path as p2_photo
    FROM teams t
    JOIN players p1 ON t.player1_id = p1.id
    JOIN players p2 ON t.player2_id = p2.id
    WHERE t.tournament_id = ?
    ORDER BY t.created_at DESC
');
$stmt->execute([$id]);
$teams = $stmt->fetchAll();

// Get player IDs that are already in a team (to disable them in dropdown)
$pairedPlayerIds = [];
foreach ($teams as $t) {
    $pairedPlayerIds[] = $t['player1_id'];
    $pairedPlayerIds[] = $t['player2_id'];
}

require_once __DIR__ . '/../../admin/includes/header.php';
?>

<div class="px-6 py-8 w-full max-w-6xl mx-auto">
    <div class="flex items-center justify-between mb-8">
        <div>
            <div class="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-1">
                <?= e($tournament['name']) ?>
            </div>
            <h1 class="text-3xl font-black text-[#0f2044] tracking-tight flex items-center gap-3">
                <i class="ph-fill ph-users-three text-[#c9a84c]"></i> Manage Doubles Teams
            </h1>
        </div>
        <a href="view.php?id=<?= $id ?>" class="bg-white border-2 border-slate-200 hover:border-slate-300 text-slate-600 font-bold py-2.5 px-5 rounded-xl transition-all flex items-center gap-2">
            <i class="ph-bold ph-arrow-left"></i> Back to Tournament
        </a>
    </div>

    <?= flash_show('tournament_teams') ?>

    <?php if ($hasMatches): ?>
        <div class="bg-amber-50 border border-amber-200 rounded-2xl p-4 mb-8 text-amber-800 text-sm font-bold flex items-center gap-3">
            <i class="ph-fill ph-lock-key text-xl"></i>
            Teams are locked because the tournament has started.
        </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
        
        <!-- Left: Create Team Form -->
        <div class="lg:col-span-1">
            <div class="bg-white rounded-3xl border border-slate-100 shadow-xl shadow-slate-200/40 p-6 sticky top-6">
                <h3 class="font-black text-[#0f2044] mb-4 text-lg flex items-center gap-2">
                    <i class="ph-bold ph-plus-circle text-blue-500"></i> Create New Team
                </h3>
                
                <form method="POST" action="teams.php?id=<?= $id ?>" class="space-y-4" x-data="{ 
                    player1: '', 
                    player2: '',
                    players: <?= json_encode(array_values(array_map(function($p) use ($pairedPlayerIds) {
                        return [
                            'id' => (string)$p['id'],
                            'name' => $p['display_name'],
                            'mohallah' => $p['mohallah'],
                            'disabled' => in_array($p['id'], $pairedPlayerIds)
                        ];
                    }, $enrolledPlayers))) ?>,
                    
                    validate(e) {
                        if (!this.player1 || !this.player2) {
                            e.preventDefault();
                            alert('Please select both Player 1 and Player 2.');
                        }
                    }
                }" @submit="validate">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="create_team">
                    
                    <div>
                        <label class="block text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2">Player 1</label>
                        <div x-data="{ open: false }" @click.away="open = false" class="relative">
                            <input type="hidden" name="player1_id" :value="player1">
                            <button type="button" @click="open = !open" 
                                    class="w-full flex items-center justify-between bg-slate-50 border border-slate-200 text-[#0f2044] text-sm font-bold rounded-xl px-4 py-3 shadow-sm hover:border-blue-400 transition-colors"
                                    :class="open ? 'border-blue-500 ring-2 ring-blue-500/20' : ''">
                                <span class="truncate" :class="player1 == '' ? 'text-slate-400' : ''" x-text="player1 != '' ? (players.find(p => p.id == player1)?.name || '') : 'Select a player...'"></span>
                                <i class="ph-bold ph-caret-down text-slate-400 text-sm transition-transform" :class="open ? 'rotate-180 text-blue-500' : ''"></i>
                            </button>
                            <div x-show="open" x-transition.opacity.duration.200ms
                                 class="absolute left-0 right-0 top-[calc(100%+8px)] bg-white border border-slate-200 rounded-xl shadow-xl z-50 overflow-hidden"
                                 style="display: none;">
                                <div class="max-h-60 overflow-y-auto custom-scrollbar">
                                    <div @click="player1 = ''; open = false;" class="px-4 py-3 border-b border-slate-100 hover:bg-slate-50 cursor-pointer text-xs font-bold text-slate-400">
                                        Select a player...
                                    </div>
                                    <template x-for="p in players" :key="p.id">
                                        <div @click.stop="if(!p.disabled) { player1 = p.id; open = false; }"
                                             class="px-4 py-3 border-b border-slate-50 transition-colors flex items-center justify-between"
                                             :class="p.disabled ? 'opacity-50 cursor-not-allowed bg-slate-50' : 'hover:bg-blue-50 cursor-pointer ' + (player1 == p.id ? 'bg-blue-50/60 border-l-2 border-l-blue-500' : 'border-l-2 border-l-transparent')">
                                            <div>
                                                <div class="text-sm font-bold text-slate-800" :class="p.disabled ? 'line-through text-slate-400' : ''" x-text="p.name"></div>
                                                <div class="text-[10px] font-bold text-slate-500" x-text="p.mohallah + (p.disabled ? ' (Already Paired)' : '')"></div>
                                            </div>
                                            <div x-show="player1 == p.id" class="text-blue-600">
                                                <i class="ph-bold ph-check-circle text-lg"></i>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div>
                        <label class="block text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2">Player 2</label>
                        <div x-data="{ open: false }" @click.away="open = false" class="relative">
                            <input type="hidden" name="player2_id" :value="player2">
                            <button type="button" @click="open = !open" 
                                    class="w-full flex items-center justify-between bg-slate-50 border border-slate-200 text-[#0f2044] text-sm font-bold rounded-xl px-4 py-3 shadow-sm hover:border-blue-400 transition-colors"
                                    :class="open ? 'border-blue-500 ring-2 ring-blue-500/20' : ''">
                                <span class="truncate" :class="player2 == '' ? 'text-slate-400' : ''" x-text="player2 != '' ? (players.find(p => p.id == player2)?.name || '') : 'Select a player...'"></span>
                                <i class="ph-bold ph-caret-down text-slate-400 text-sm transition-transform" :class="open ? 'rotate-180 text-blue-500' : ''"></i>
                            </button>
                            <div x-show="open" x-transition.opacity.duration.200ms
                                 class="absolute left-0 right-0 top-[calc(100%+8px)] bg-white border border-slate-200 rounded-xl shadow-xl z-50 overflow-hidden"
                                 style="display: none;">
                                <div class="max-h-60 overflow-y-auto custom-scrollbar">
                                    <div @click="player2 = ''; open = false;" class="px-4 py-3 border-b border-slate-100 hover:bg-slate-50 cursor-pointer text-xs font-bold text-slate-400">
                                        Select a player...
                                    </div>
                                    <template x-for="p in players" :key="p.id">
                                        <div @click.stop="if(!p.disabled) { player2 = p.id; open = false; }"
                                             class="px-4 py-3 border-b border-slate-50 transition-colors flex items-center justify-between"
                                             :class="p.disabled ? 'opacity-50 cursor-not-allowed bg-slate-50' : 'hover:bg-blue-50 cursor-pointer ' + (player2 == p.id ? 'bg-blue-50/60 border-l-2 border-l-blue-500' : 'border-l-2 border-l-transparent')">
                                            <div>
                                                <div class="text-sm font-bold text-slate-800" :class="p.disabled ? 'line-through text-slate-400' : ''" x-text="p.name"></div>
                                                <div class="text-[10px] font-bold text-slate-500" x-text="p.mohallah + (p.disabled ? ' (Already Paired)' : '')"></div>
                                            </div>
                                            <div x-show="player2 == p.id" class="text-blue-600">
                                                <i class="ph-bold ph-check-circle text-lg"></i>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <button type="submit" <?= $hasMatches ? 'disabled' : '' ?> class="w-full bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-700 hover:to-indigo-700 text-white font-bold py-3.5 px-4 rounded-xl shadow-lg transition-all flex items-center justify-center gap-2 mt-2 disabled:opacity-50">
                        <i class="ph-bold ph-plus"></i> Create Team
                    </button>
                </form>
            </div>
        </div>

        <!-- Right: Teams List -->
        <div class="lg:col-span-2">
            <div class="bg-white rounded-3xl border border-slate-100 shadow-xl shadow-slate-200/40 p-1">
                <div class="p-5 border-b border-slate-100 flex items-center justify-between">
                    <h3 class="font-black text-[#0f2044] text-lg">Created Teams <span class="text-slate-400 font-medium ml-2">(<?= count($teams) ?>)</span></h3>
                </div>
                
                <div class="divide-y divide-slate-100">
                    <?php if (empty($teams)): ?>
                        <div class="p-10 text-center text-slate-400">
                            <i class="ph-fill ph-users-three text-5xl text-slate-200 mb-3"></i>
                            <div class="font-bold text-lg">No teams created yet.</div>
                            <div class="text-sm font-medium mt-1">Select two players from the enrolled list to pair them.</div>
                        </div>
                    <?php else: ?>
                        <?php foreach ($teams as $t): ?>
                            <div class="p-5 flex items-center justify-between hover:bg-slate-50 transition-colors">
                                <div class="flex items-center gap-4">
                                    <div class="flex -space-x-4 relative z-0">
                                        <div class="w-12 h-12 rounded-full border-2 border-white bg-slate-200 overflow-hidden relative z-10 shadow-sm">
                                            <?php if ($t['p1_photo']): ?>
                                                <img src="<?= BASE_URL ?>/uploads/players/<?= e($t['p1_photo']) ?>" class="w-full h-full object-cover">
                                            <?php else: ?>
                                                <div class="w-full h-full flex items-center justify-center text-slate-400 bg-slate-100 font-bold"><i class="ph-bold ph-user"></i></div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="w-12 h-12 rounded-full border-2 border-white bg-slate-200 overflow-hidden relative z-0 shadow-sm">
                                            <?php if ($t['p2_photo']): ?>
                                                <img src="<?= BASE_URL ?>/uploads/players/<?= e($t['p2_photo']) ?>" class="w-full h-full object-cover">
                                            <?php else: ?>
                                                <div class="w-full h-full flex items-center justify-center text-slate-400 bg-slate-100 font-bold"><i class="ph-bold ph-user"></i></div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div>
                                        <div class="font-black text-[#0f2044] text-base"><?= e($t['display_name']) ?></div>
                                        <div class="text-xs font-bold text-slate-400 mt-0.5"><?= e($t['p1_name']) ?> &amp; <?= e($t['p2_name']) ?></div>
                                    </div>
                                </div>
                                
                                <?php if (!$hasMatches): ?>
                                    <form method="POST" action="teams.php?id=<?= $id ?>" onsubmit="return confirm('Remove this team?');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete_team">
                                        <input type="hidden" name="team_id" value="<?= $t['id'] ?>">
                                        <button type="submit" class="w-10 h-10 rounded-xl bg-red-50 text-red-500 hover:bg-red-500 hover:text-white flex items-center justify-center transition-colors">
                                            <i class="ph-bold ph-trash"></i>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

    </div>
</div>

<?php require_once __DIR__ . '/../../admin/includes/footer.php'; ?>
