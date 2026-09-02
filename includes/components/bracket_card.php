<?php
// Shared component for the Knockout Bracket Card
?>
<div class="bg-white border <?= $m['status'] === MATCH_COMPLETED ? 'border-gray-300 shadow-sm' : 'border-gray-200 border-dashed' ?> rounded-lg p-3 text-sm relative">
    
    <?php if ($m['status'] === MATCH_IN_PROGRESS): ?>
        <span class="absolute -top-1 -right-1 flex h-3 w-3">
          <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-red-400 opacity-75"></span>
          <span class="relative inline-flex rounded-full h-3 w-3 bg-red-500"></span>
        </span>
    <?php endif; ?>

    <!-- Player A -->
    <div class="flex items-center justify-between mb-1 pb-1 border-b border-gray-100">
        <span class="font-bold truncate <?= $m['winner_player_id'] == $m['participant_a_id'] ? 'text-tkmi-navy' : ($m['status'] === MATCH_COMPLETED ? 'text-gray-400' : 'text-gray-700') ?>">
            <?= e($m['pa_name'] ?: 'TBD') ?>
        </span>
        <span class="font-bold <?= $m['winner_player_id'] == $m['participant_a_id'] ? 'text-green-600' : 'text-gray-400' ?>">
            <?= $m['pa_name'] ? $m['games_a'] : '-' ?>
        </span>
    </div>
    
    <!-- Player B -->
    <div class="flex items-center justify-between">
        <span class="font-bold truncate <?= $m['winner_player_id'] == $m['participant_b_id'] ? 'text-tkmi-navy' : ($m['status'] === MATCH_COMPLETED ? 'text-gray-400' : 'text-gray-700') ?>">
            <?= e($m['pb_name'] ?: 'TBD') ?>
        </span>
        <span class="font-bold <?= $m['winner_player_id'] == $m['participant_b_id'] ? 'text-green-600' : 'text-gray-400' ?>">
            <?= $m['pb_name'] ? $m['games_b'] : '-' ?>
        </span>
    </div>

</div>
