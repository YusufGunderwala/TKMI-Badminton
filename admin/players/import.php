<?php
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireLogin();

$results = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        $error = 'Invalid security token.';
    } else {
        $csv_data = trim($_POST['csv_data'] ?? '');
        if (empty($csv_data)) {
            $error = 'Please paste some data to import.';
        } else {
            $lines = explode("\n", $csv_data);
            $success_count = 0;
            $duplicate_count = 0;
            $error_rows = [];
            
            $pdo = db();
            $stmt = $pdo->prepare('
                INSERT INTO players (its_id, full_name, display_name, mohallah, gender, whatsapp)
                VALUES (?, ?, ?, ?, ?, ?)
            ');
            
            // Expected columns: ITS ID, Full Name, Display Name, Mohallah, Gender, WhatsApp
            $rowNum = 0;
            foreach ($lines as $line) {
                $rowNum++;
                $line = trim($line);
                if (empty($line)) continue;
                
                // Determine delimiter (tab or comma)
                $delimiter = strpos($line, "\t") !== false ? "\t" : ",";
                $cols = str_getcsv($line, $delimiter);
                
                if (count($cols) < 6) {
                    // Skip header rows or malformed rows if they clearly don't match
                    if ($rowNum === 1 && stripos($cols[0], 'its') !== false) continue;
                    $error_rows[] = "Row $rowNum: Missing columns. Found " . count($cols) . " columns instead of 6.";
                    continue;
                }
                
                // Clean data
                $its_id = preg_replace('/[^0-9]/', '', $cols[0]);
                $full_name = trim($cols[1]);
                $display_name = trim($cols[2]);
                $mohallah = trim($cols[3]);
                $gender = ucfirst(strtolower(trim($cols[4]))); // Boys or Girls
                $whatsapp = trim($cols[5]);
                
                // Validation
                if (strlen($its_id) !== 8) {
                    if ($rowNum === 1 && stripos($cols[0], 'its') !== false) continue; // It was a header
                    $error_rows[] = "Row $rowNum ($full_name): ITS ID must be exactly 8 digits.";
                    continue;
                }
                if (!in_array($gender, ['Boys', 'Girls'])) {
                    $error_rows[] = "Row $rowNum ($full_name): Gender must be 'Boys' or 'Girls'. Found: $gender";
                    continue;
                }
                
                try {
                    $stmt->execute([$its_id, $full_name, $display_name, $mohallah, $gender, $whatsapp]);
                    $success_count++;
                } catch (PDOException $e) {
                    if ($e->getCode() == 23505) { // Postgres unique violation
                        $duplicate_count++;
                    } else {
                        $error_rows[] = "Row $rowNum ($full_name): Database error - " . $e->getMessage();
                    }
                }
            }
            AppCache::flush();
            $results = [
                'success' => $success_count,
                'duplicates' => $duplicate_count,
                'errors' => $error_rows
            ];
            
            if (empty($error_rows) && $success_count > 0) {
                flash_set('players', "$success_count players imported successfully! ($duplicate_count skipped)", 'success');
                header('Location: ' . BASE_URL . '/admin/players/');
                exit;
            }
        }
    }
}

$pageTitle = 'Bulk Import Players';
include __DIR__ . '/../includes/header.php';
?>

<div class="mb-8 flex items-center justify-between">
    <div>
        <a href="<?= BASE_URL ?>/admin/players/" class="text-sm font-bold text-slate-500 hover:text-[#0f2044] flex items-center gap-1 mb-2 transition-colors">
            <i class="ph-bold ph-arrow-left"></i> Back to Directory
        </a>
        <div class="flex items-center gap-4">
            <div class="w-12 h-12 bg-indigo-100 rounded-xl flex items-center justify-center text-indigo-600 shadow-inner">
                <i class="ph-fill ph-upload-simple text-2xl"></i>
            </div>
            <h2 class="text-3xl font-black font-display text-[#0f2044]">Bulk Import Players</h2>
        </div>
    </div>
</div>

<?php if (isset($error)): ?>
    <div class="bg-red-50 text-red-700 p-4 rounded-xl mb-6 text-sm font-bold border border-red-200 flex items-center gap-2">
        <i class="ph-fill ph-warning-circle text-lg"></i>
        <?= e($error) ?>
    </div>
<?php endif; ?>

<?php if ($results): ?>
    <div class="bg-white border-2 <?= empty($results['errors']) ? 'border-emerald-200' : 'border-amber-200' ?> rounded-2xl p-6 mb-8 shadow-sm">
        <h3 class="font-black text-lg text-slate-800 mb-4 flex items-center gap-2">
            <i class="ph-fill ph-list-checks text-xl <?= empty($results['errors']) ? 'text-emerald-500' : 'text-amber-500' ?>"></i>
            Import Results
        </h3>
        
        <div class="flex gap-4 mb-6">
            <div class="bg-emerald-50 text-emerald-800 px-4 py-3 rounded-lg border border-emerald-100 flex-1 flex flex-col items-center justify-center">
                <span class="text-3xl font-black"><?= $results['success'] ?></span>
                <span class="text-xs font-bold uppercase tracking-wider opacity-80">Imported</span>
            </div>
            <div class="bg-slate-50 text-slate-600 px-4 py-3 rounded-lg border border-slate-200 flex-1 flex flex-col items-center justify-center">
                <span class="text-3xl font-black"><?= $results['duplicates'] ?></span>
                <span class="text-xs font-bold uppercase tracking-wider opacity-80">Skipped (Exists)</span>
            </div>
            <div class="bg-red-50 text-red-700 px-4 py-3 rounded-lg border border-red-100 flex-1 flex flex-col items-center justify-center">
                <span class="text-3xl font-black"><?= count($results['errors']) ?></span>
                <span class="text-xs font-bold uppercase tracking-wider opacity-80">Errors</span>
            </div>
        </div>
        
        <?php if (!empty($results['errors'])): ?>
            <div class="bg-red-50/50 border border-red-100 rounded-xl p-4">
                <h4 class="text-sm font-bold text-red-800 mb-2">Errors to fix:</h4>
                <ul class="list-disc list-inside text-sm text-red-600 space-y-1">
                    <?php foreach ($results['errors'] as $err): ?>
                        <li><?= e($err) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<script src="<?= BASE_URL ?>/assets/vendor/badminton-smash-engine.js"></script>

<div x-data="{
    isProcessing: false,
    progress: 15,
    statusText: 'PARSING CSV DATA...',
    smashEngine: null,
    handleSubmit(event) {
        if (this.isProcessing) {
            event.preventDefault();
            return;
        }
        const textarea = document.querySelector('textarea[name=csv_data]');
        if (!textarea || !textarea.value.trim()) return;

        this.isProcessing = true;

        // Initialize 3D Canvas
        this.$nextTick(() => {
            setTimeout(() => {
                if (typeof BadmintonSmashEngine !== 'undefined') {
                    this.smashEngine = new BadmintonSmashEngine('importSmashCanvas');
                    this.smashEngine.start();
                }
            }, 50);
        });

        const statuses = [
            'PARSING PLAYER RECORDS...',
            'VALIDATING 8-DIGIT ITS IDENTIFIERS...',
            'CALCULATING SEEDING BRACKETS...',
            'INDEXING MOHALLAH CLUSTERS...',
            'SYNCHRONIZING ROSTER MATRIX...'
        ];
        let idx = 0;
        setInterval(() => {
            if (this.progress < 92) {
                this.progress += Math.floor(Math.random() * 15) + 6;
                if (this.progress > 92) this.progress = 92;
                idx = (idx + 1) % statuses.length;
                this.statusText = statuses[idx];
            }
        }, 260);
    }
}">

<div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
    <div class="lg:col-span-2">
        <form action="" method="POST" @submit="handleSubmit($event)" class="bg-white rounded-3xl shadow-sm border border-slate-200 overflow-hidden">
            <?= csrf_field() ?>
            <div class="px-6 py-5 border-b border-slate-100 bg-slate-50 flex items-center justify-between">
                <h3 class="font-black text-[#0f2044] flex items-center gap-2">
                    <i class="ph-bold ph-clipboard-text text-blue-600"></i>
                    Paste Data
                </h3>
                <button type="button" 
                        @click="
                            document.querySelector('textarea[name=csv_data]').value = `30410101, Burhanuddin Badri, Burhanuddin B., Saifee Park, Boys, +91 98201 11001\n30410102, Mustafa Jamali, Mustafa J., Najmi Nagar, Boys, +91 98201 11002\n30410103, Taha Merchant, Taha M., Badri Mohallah, Boys, +91 98201 11003\n30410104, Hussain Ezzi, Hussain E., Ezzi Bagh, Boys, +91 98201 11004\n30410105, Ali Asgar Saifee, Ali Asgar S., Husami Mohallah, Boys, +91 98201 11005\n30410106, Shabbir Kothari, Shabbir K., Qutbi Colony, Boys, +91 98201 11006\n30410107, Taher Lokhandwala, Taher L., Burhani Complex, Boys, +91 98201 11007\n30410108, Mohammed Vajihi, Mohammed V., Fakhri Mohallah, Boys, +91 98201 11008\n30410109, Hatim Electricwala, Hatim E., Imadi Mohallah, Boys, +91 98201 11009\n30410110, Moiz Rampurawala, Moiz R., Taiyebi Mohallah, Boys, +91 98201 11010\n30410111, Qasim Poonawala, Qasim P., Saifee Park, Boys, +91 98201 11011\n30410112, Kumail Contractor, Kumail C., Najmi Nagar, Boys, +91 98201 11012\n30410113, Idris Master, Idris M., Badri Mohallah, Boys, +91 98201 11013\n30410114, Murtaza Shakir, Murtaza S., Ezzi Bagh, Boys, +91 98201 11014\n30410115, Abbas Gandhi, Abbas G., Husami Mohallah, Boys, +91 98201 11015\n30410116, Saifuddin Bhabrawala, Saifuddin B., Qutbi Colony, Boys, +91 98201 11016\n30410117, Hakimuddin Kapadia, Hakimuddin K., Burhani Complex, Boys, +91 98201 11017\n30410118, Zahiruddin Indorewala, Zahiruddin I., Fakhri Mohallah, Boys, +91 98201 11018\n30410119, Mansoor Godhrawala, Mansoor G., Imadi Mohallah, Boys, +91 98201 11019\n30410120, Zoeb Ratlamwala, Zoeb R., Taiyebi Mohallah, Boys, +91 98201 11020\n30410121, Huzaifa Dahodwala, Huzaifa D., Saifee Park, Boys, +91 98201 11021\n30410122, Mufaddal Ujjainwala, Mufaddal U., Najmi Nagar, Boys, +91 98201 11022\n30410123, Farooq Suratwala, Farooq S., Badri Mohallah, Boys, +91 98201 11023\n30410124, Ibrahim Banswara, Ibrahim B., Ezzi Bagh, Boys, +91 98201 11024\n30410125, Zohair Shajapurwala, Zohair S., Husami Mohallah, Boys, +91 98201 11025\n30410126, Fakhruddin Nagri, Fakhruddin N., Qutbi Colony, Boys, +91 98201 11026\n30410127, Aliasgar Khandwawala, Aliasgar K., Burhani Complex, Boys, +91 98201 11027\n30410128, Taizoon Marolwala, Taizoon M., Fakhri Mohallah, Boys, +91 98201 11028\n30410129, Ammar Kurlawala, Ammar K., Imadi Mohallah, Boys, +91 98201 11029\n30410130, Hamza Mandviwala, Hamza M., Taiyebi Mohallah, Boys, +91 98201 11030`
                        "
                        class="text-xs font-bold text-[#c9a84c] hover:text-[#b08e36] bg-[#0f2044] px-3 py-1.5 rounded-xl transition flex items-center gap-1 shadow-xs">
                    <i class="ph-bold ph-lightning"></i> Fill 30 Boys Sample Data
                </button>
            </div>
            <div class="p-6">
                <p class="text-sm text-slate-500 font-medium mb-4">
                    Copy and paste your data directly from Excel or Google Sheets. The system supports both Tab-separated and Comma-separated (CSV) formats.
                </p>
                <textarea name="csv_data" rows="12" class="w-full font-mono text-xs border-2 border-slate-200 rounded-2xl p-4 focus:ring-4 focus:ring-blue-500/20 focus:border-blue-500 transition-all custom-scrollbar bg-slate-50 leading-relaxed" placeholder="ITS, Full Name, Display Name, Mohallah, Gender, WhatsApp&#10;30918869, Yusuf Gundarwala, YG, ABC, Boys, 90976554379&#10;..."></textarea>
            </div>
            <div class="px-6 py-5 border-t border-slate-100 bg-slate-50/80 flex justify-end">
                <button type="submit" class="bg-[#0f2044] hover:bg-blue-900 text-white font-black py-3.5 px-8 rounded-xl shadow-md transition-all flex items-center gap-2 text-sm">
                    <i class="ph-bold ph-magic-wand text-lg"></i> Process Import
                </button>
            </div>
        </form>
    </div>
    
    <div class="lg:col-span-1">
        <div class="bg-blue-50/70 border-2 border-blue-100 rounded-3xl p-6 relative overflow-hidden">
            <div class="absolute -right-4 -top-4 opacity-5">
                <i class="ph-fill ph-info text-9xl text-blue-900"></i>
            </div>
            <h3 class="font-black text-blue-900 mb-4 flex items-center gap-2 relative z-10">
                <i class="ph-fill ph-info"></i> Column Format
            </h3>
            <p class="text-sm text-blue-800 font-medium mb-4 relative z-10">
                Ensure your columns are exactly in this order. You can include a header row, it will be skipped automatically.
            </p>
            
            <ol class="space-y-3 relative z-10">
                <li class="flex items-start gap-3">
                    <span class="bg-[#0f2044] text-[#c9a84c] w-6 h-6 rounded flex items-center justify-center text-xs font-black shrink-0">1</span>
                    <div>
                        <div class="font-bold text-slate-900 text-sm">ITS ID</div>
                        <div class="text-xs text-slate-500">Must be exactly 8 digits</div>
                    </div>
                </li>
                <li class="flex items-start gap-3">
                    <span class="bg-[#0f2044] text-[#c9a84c] w-6 h-6 rounded flex items-center justify-center text-xs font-black shrink-0">2</span>
                    <div>
                        <div class="font-bold text-slate-900 text-sm">Full Name</div>
                        <div class="text-xs text-slate-500">e.g. Yusuf Gundarwala</div>
                    </div>
                </li>
                <li class="flex items-start gap-3">
                    <span class="bg-[#0f2044] text-[#c9a84c] w-6 h-6 rounded flex items-center justify-center text-xs font-black shrink-0">3</span>
                    <div>
                        <div class="font-bold text-slate-900 text-sm">Display Name</div>
                        <div class="text-xs text-slate-500">Short name for scoreboards</div>
                    </div>
                </li>
                <li class="flex items-start gap-3">
                    <span class="bg-[#0f2044] text-[#c9a84c] w-6 h-6 rounded flex items-center justify-center text-xs font-black shrink-0">4</span>
                    <div>
                        <div class="font-bold text-slate-900 text-sm">Mohallah</div>
                        <div class="text-xs text-slate-500">e.g. ABC, Najmi</div>
                    </div>
                </li>
                <li class="flex items-start gap-3">
                    <span class="bg-[#0f2044] text-[#c9a84c] w-6 h-6 rounded flex items-center justify-center text-xs font-black shrink-0">5</span>
                    <div>
                        <div class="font-bold text-slate-900 text-sm">Gender</div>
                        <div class="text-xs text-slate-500">Must be exactly 'Boys' or 'Girls'</div>
                    </div>
                </li>
                <li class="flex items-start gap-3">
                    <span class="bg-[#0f2044] text-[#c9a84c] w-6 h-6 rounded flex items-center justify-center text-xs font-black shrink-0">6</span>
                    <div>
                        <div class="font-bold text-slate-900 text-sm">WhatsApp</div>
                        <div class="text-xs text-slate-500">Full number for updates</div>
                    </div>
                </li>
            </ol>
        </div>
    </div>
</div>

<!-- ============================================================ -->
<!-- NEXT-LEVEL 3D STADIUM SMASH ANIMATION OVERLAY               -->
<!-- ============================================================ -->
<div x-show="isProcessing" 
     x-transition:enter="transition ease-out duration-300"
     x-transition:enter-start="opacity-0 scale-95"
     x-transition:enter-end="opacity-100 scale-100"
     class="fixed inset-0 z-50 bg-[#020612]/92 backdrop-blur-2xl flex items-center justify-center p-4 sm:p-6"
     style="display: none;">
    
    <!-- Solid High-Contrast Luxury Container Card -->
    <div class="bg-gradient-to-b from-[#0a1733] to-[#060e22] border-2 border-[#c9a84c]/40 rounded-3xl p-6 sm:p-8 max-w-2xl w-full shadow-[0_30px_90px_rgba(0,0,0,0.9)] text-center relative overflow-hidden ring-1 ring-white/20">
        
        <!-- Ambient Stadium Lights Inside Card -->
        <div class="absolute -top-24 left-1/4 w-60 h-60 bg-blue-500/20 rounded-full blur-[80px] pointer-events-none"></div>
        <div class="absolute -bottom-24 right-1/4 w-60 h-60 bg-[#c9a84c]/20 rounded-full blur-[80px] pointer-events-none"></div>

        <!-- 3D Stadium Canvas Viewport -->
        <div class="relative w-full h-56 sm:h-64 rounded-2xl overflow-hidden shadow-2xl border border-white/15 bg-[#030914] mb-5">
            <canvas id="importSmashCanvas" class="w-full h-full block"></canvas>
            
            <div class="absolute top-3 left-3 flex items-center gap-2 px-3 py-1 rounded-full bg-[#0f2044]/95 border border-white/20 text-[10px] font-black uppercase tracking-wider text-slate-100 shadow-md">
                <span class="w-2 h-2 rounded-full bg-emerald-400 animate-ping"></span>
                <span>TKMI 3D Stadium Match Engine</span>
            </div>

            <div class="absolute bottom-3 right-3 flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-black/80 border border-white/10 text-[9px] font-mono text-slate-300">
                <span>60 FPS &bull; Ballistic Physics</span>
            </div>
        </div>

        <!-- High-Contrast Status Badge -->
        <div class="inline-flex items-center gap-2 px-4 py-1.5 rounded-full bg-[#c9a84c]/20 border border-[#c9a84c]/50 text-[#c9a84c] text-xs font-black uppercase tracking-widest mb-2 shadow-sm">
            <span class="w-2 h-2 rounded-full bg-[#c9a84c] animate-ping"></span>
            <span x-text="statusText">PARSING CSV DATA...</span>
        </div>

        <!-- High-Contrast Title & Subtitle -->
        <h3 class="text-2xl sm:text-3xl font-black font-display text-white mb-1.5 tracking-tight drop-shadow-sm">Processing Community Roster</h3>
        <p class="text-xs sm:text-sm text-slate-300 mb-5 font-medium max-w-md mx-auto leading-relaxed">Validating Dawoodi Bohra ITS IDs, creating individual profiles, and generating tournament seeds.</p>

        <!-- High-Contrast Neon Progress Bar -->
        <div class="w-full max-w-lg mx-auto bg-slate-950 h-4 rounded-full overflow-hidden p-0.5 border border-white/20 shadow-inner relative">
            <div class="bg-gradient-to-r from-blue-500 via-[#c9a84c] to-emerald-400 h-full rounded-full transition-all duration-300 shadow-[0_0_15px_rgba(201,168,76,0.9)]"
                 :style="'width: ' + progress + '%'"></div>
        </div>
        
        <div class="max-w-lg mx-auto flex items-center justify-between text-xs text-slate-300 font-mono font-bold mt-2 px-1">
            <span>BATCH PROCESSING</span>
            <span x-text="progress + '%'" class="text-[#c9a84c] font-black text-sm"></span>
        </div>
    </div>
</div>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
