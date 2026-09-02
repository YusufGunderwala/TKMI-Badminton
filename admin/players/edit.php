<?php
// ============================================================
// Players - Edit Profile
// ============================================================
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireLogin();

$pdo = db();
$playerId = (int)($_GET['id'] ?? 0);

if ($playerId <= 0) {
    flash_set('players', 'Invalid player ID.', 'error');
    header('Location: index.php');
    exit;
}

// Fetch player
$stmt = $pdo->prepare('SELECT * FROM players WHERE id = ?');
$stmt->execute([$playerId]);
$player = $stmt->fetch();

if (!$player) {
    flash_set('players', 'Player not found.', 'error');
    header('Location: index.php');
    exit;
}

$pageTitle = 'Edit Player - ' . $player['display_name'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        flash_set('player_edit', 'Invalid security token.', 'error');
        header('Location: edit.php?id=' . $playerId);
        exit;
    }

    $its = trim($_POST['its_id'] ?? '');
    $full = trim($_POST['full_name'] ?? '');
    $disp = trim($_POST['display_name'] ?? '');
    $moh = trim($_POST['mohallah'] ?? '');
    $gen = $_POST['gender'] ?? '';
    $wa = trim($_POST['whatsapp'] ?? '');
    $removePhoto = !empty($_POST['remove_photo']);
    
    // Basic validation
    if (empty($its) || empty($full) || empty($disp) || empty($moh) || empty($gen) || empty($wa)) {
        flash_set('player_edit', 'All fields except photo are required.', 'error');
        header('Location: edit.php?id=' . $playerId);
        exit;
    }
    if (!in_array($gen, ['Boys', 'Girls'])) {
        flash_set('player_edit', 'Invalid gender selection.', 'error');
        header('Location: edit.php?id=' . $playerId);
        exit;
    }

    $photoName = $player['photo_path'];

    // Handle Photo Removal
    if ($removePhoto && !empty($photoName)) {
        deleteUpload(__DIR__ . '/../../uploads/players/' . $photoName);
        $photoName = null;
    }

    // Handle New Photo Upload
    if (!empty($_FILES['photo']['name'])) {
        $uploadedPhoto = uploadImage($_FILES['photo'], __DIR__ . '/../../uploads/players', 'p_' . $its . '_' . time());
        if ($uploadedPhoto === false) {
            flash_set('player_edit', 'Failed to upload photo. Ensure file is an image under 2MB.', 'error');
            header('Location: edit.php?id=' . $playerId);
            exit;
        }
        // Remove old photo if replaced
        if (!empty($player['photo_path']) && $player['photo_path'] !== $uploadedPhoto) {
            deleteUpload(__DIR__ . '/../../uploads/players/' . $player['photo_path']);
        }
        $photoName = $uploadedPhoto;
    }

    try {
        $updateStmt = $pdo->prepare('
            UPDATE players 
            SET its_id = ?, full_name = ?, display_name = ?, mohallah = ?, gender = ?, whatsapp = ?, photo_path = ?
            WHERE id = ?
        ');
        $updateStmt->execute([$its, $full, $disp, $moh, $gen, $wa, $photoName, $playerId]);
        AppCache::flush();
        flash_set('players', "Player \"{$full}\" updated successfully!", 'success');
        header('Location: index.php');
        exit;
    } catch (PDOException $e) {
        if ($e->getCode() == 23505) {
            flash_set('player_edit', 'Another player with this ITS ID already exists.', 'error');
        } else {
            flash_set('player_edit', 'Database error: ' . $e->getMessage(), 'error');
        }
        header('Location: edit.php?id=' . $playerId);
        exit;
    }
}

include __DIR__ . '/../includes/header.php';
?>

<div class="mb-8">
    <a href="<?= BASE_URL ?>/admin/players/index.php" class="text-sm font-bold text-slate-500 hover:text-[#0f2044] flex items-center gap-1 mb-2 transition-colors">
        <i class="ph-bold ph-arrow-left"></i> Back to Players Directory
    </a>
    <div class="flex items-center gap-4">
        <div class="w-14 h-14 rounded-2xl bg-[#0f2044] text-[#c9a84c] flex items-center justify-center font-black text-xl shadow-md border-2 border-slate-100 overflow-hidden">
            <?php if (!empty($player['photo_path'])): ?>
                <img src="<?= BASE_URL ?>/uploads/players/<?= e($player['photo_path']) ?>" alt="Avatar" class="w-full h-full object-cover">
            <?php else: ?>
                <i class="ph-fill ph-user text-2xl text-slate-300"></i>
            <?php endif; ?>
        </div>
        <div>
            <h2 class="text-3xl font-black font-display text-[#0f2044]">Edit Player Profile</h2>
            <p class="text-slate-500 text-sm font-medium">Update community details, display name, and player photo for <strong><?= e($player['full_name']) ?></strong></p>
        </div>
    </div>
</div>

<?= flash_html('player_edit') ?>

<!-- Alpine Form Controller with Interactive Dropzone & 3D Smash Engine -->
<div x-data="playerEditHandler()" class="space-y-6">

    <div class="bg-white border border-slate-200 rounded-3xl shadow-[0_4px_20px_rgba(0,0,0,0.03)] overflow-hidden max-w-3xl">
        <form action="edit.php?id=<?= $playerId ?>" method="POST" enctype="multipart/form-data" @submit="handleSubmit($event)" class="p-8" id="playerEditForm">
            <?= csrf_field() ?>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                
                <div class="col-span-full md:col-span-1">
                    <label class="block text-sm font-bold text-slate-700 mb-2">ITS ID <span class="text-red-500">*</span></label>
                    <input type="text" name="its_id" required pattern="[0-9]{8}" maxlength="8" value="<?= e($player['its_id']) ?>" placeholder="e.g. 12345678" class="w-full bg-slate-50 border border-slate-200 text-slate-800 text-sm rounded-xl focus:ring-blue-500 focus:border-blue-500 block p-3 transition-colors font-mono">
                    <p class="text-[10px] text-slate-400 mt-1 uppercase tracking-wider font-bold">8-Digit Dawoodi Bohra Community ID</p>
                </div>

                <div class="col-span-full md:col-span-1">
                    <label class="block text-sm font-bold text-slate-700 mb-2">Gender <span class="text-red-500">*</span></label>
                    <div class="grid grid-cols-2 gap-3">
                        <label class="relative flex cursor-pointer rounded-xl border bg-white p-3 shadow-sm hover:bg-slate-50 focus:outline-none transition-all has-[:checked]:border-blue-500 has-[:checked]:ring-1 has-[:checked]:ring-blue-500 has-[:checked]:bg-blue-50 group items-center">
                            <input type="radio" name="gender" value="Boys" <?= $player['gender'] === 'Boys' ? 'checked' : '' ?> class="sr-only" required>
                            <div class="mr-3 flex-shrink-0 h-4 w-4 rounded-full border-2 border-slate-300 flex items-center justify-center group-has-[:checked]:border-blue-600 group-has-[:checked]:bg-blue-600 transition-colors">
                                <div class="h-1.5 w-1.5 rounded-full bg-white opacity-0 group-has-[:checked]:opacity-100 transition-opacity"></div>
                            </div>
                            <span class="flex flex-1 items-center gap-2">
                                <i class="ph-bold ph-gender-male text-blue-500 text-lg group-has-[:checked]:text-blue-700"></i>
                                <span class="block text-sm font-bold text-slate-700 group-has-[:checked]:text-blue-700">Boys</span>
                            </span>
                        </label>
                        <label class="relative flex cursor-pointer rounded-xl border bg-white p-3 shadow-sm hover:bg-slate-50 focus:outline-none transition-all has-[:checked]:border-pink-500 has-[:checked]:ring-1 has-[:checked]:ring-pink-500 has-[:checked]:bg-pink-50 group items-center">
                            <input type="radio" name="gender" value="Girls" <?= $player['gender'] === 'Girls' ? 'checked' : '' ?> class="sr-only" required>
                            <div class="mr-3 flex-shrink-0 h-4 w-4 rounded-full border-2 border-slate-300 flex items-center justify-center group-has-[:checked]:border-pink-600 group-has-[:checked]:bg-pink-600 transition-colors">
                                <div class="h-1.5 w-1.5 rounded-full bg-white opacity-0 group-has-[:checked]:opacity-100 transition-opacity"></div>
                            </div>
                            <span class="flex flex-1 items-center gap-2">
                                <i class="ph-bold ph-gender-female text-pink-500 text-lg group-has-[:checked]:text-pink-700"></i>
                                <span class="block text-sm font-bold text-slate-700 group-has-[:checked]:text-pink-700">Girls</span>
                            </span>
                        </label>
                    </div>
                </div>
                
                <div class="col-span-full">
                    <label class="block text-sm font-bold text-slate-700 mb-2">Full Name <span class="text-red-500">*</span></label>
                    <input type="text" name="full_name" required value="<?= e($player['full_name']) ?>" placeholder="e.g. Hussain Ali" class="w-full bg-slate-50 border border-slate-200 text-slate-800 text-sm rounded-xl focus:ring-blue-500 focus:border-blue-500 block p-3 transition-colors">
                </div>

                <div class="col-span-full md:col-span-1">
                    <label class="block text-sm font-bold text-slate-700 mb-2">Display Name <span class="text-red-500">*</span></label>
                    <input type="text" name="display_name" required value="<?= e($player['display_name']) ?>" placeholder="e.g. Hussain A." class="w-full bg-slate-50 border border-slate-200 text-slate-800 text-sm rounded-xl focus:ring-blue-500 focus:border-blue-500 block p-3 transition-colors">
                    <p class="text-[10px] text-slate-400 mt-1 uppercase tracking-wider font-bold">Shown on Live Scoreboard & Brackets</p>
                </div>

                <div class="col-span-full md:col-span-1">
                    <label class="block text-sm font-bold text-slate-700 mb-2">WhatsApp Contact <span class="text-red-500">*</span></label>
                    <input type="text" name="whatsapp" required value="<?= e($player['whatsapp']) ?>" placeholder="e.g. +91 9876543210" class="w-full bg-slate-50 border border-slate-200 text-slate-800 text-sm rounded-xl focus:ring-blue-500 focus:border-blue-500 block p-3 transition-colors">
                    <p class="text-[10px] text-slate-400 mt-1 uppercase tracking-wider font-bold">Admin-Only &bull; Never Shown Publicly</p>
                </div>

                <div class="col-span-full">
                    <label class="block text-sm font-bold text-slate-700 mb-2">Mohallah <span class="text-red-500">*</span></label>
                    <input type="text" name="mohallah" required value="<?= e($player['mohallah']) ?>" placeholder="e.g. Saifee Park" class="w-full bg-slate-50 border border-slate-200 text-slate-800 text-sm rounded-xl focus:ring-blue-500 focus:border-blue-500 block p-3 transition-colors">
                </div>

                <!-- ============================================================ -->
                <!-- CURRENT PHOTO / UPLOAD NEW PHOTO DROPZONE                    -->
                <!-- ============================================================ -->
                <div class="col-span-full">
                    <label class="block text-sm font-bold text-slate-700 mb-2">Player Photo</label>
                    
                    <!-- Hidden native file input -->
                    <input type="file" 
                           name="photo" 
                           id="photoInput" 
                           accept="image/png, image/jpeg, image/jpg, image/webp" 
                           class="hidden" 
                           @change="handleFileSelect($event)" />

                    <input type="hidden" name="remove_photo" :value="isPhotoRemoved ? '1' : '0'">

                    <!-- Error Alert State -->
                    <div x-show="errorMessage" 
                         x-transition.opacity.duration.200ms
                         class="mb-3 p-3.5 rounded-2xl bg-red-50 border border-red-200 text-red-700 text-xs font-bold flex items-center justify-between shadow-xs">
                        <div class="flex items-center gap-2">
                            <i class="ph-fill ph-warning-circle text-red-500 text-lg"></i>
                            <span x-text="errorMessage"></span>
                        </div>
                        <button type="button" @click="errorMessage = ''" class="text-red-400 hover:text-red-600">
                            <i class="ph-bold ph-x text-sm"></i>
                        </button>
                    </div>

                    <!-- Dropzone Container -->
                    <div @dragover.prevent="isDragging = true"
                         @dragleave.prevent="isDragging = false"
                         @drop.prevent="handleDrop($event)"
                         :class="{
                             'border-blue-500 bg-blue-50/60 ring-4 ring-blue-500/10 scale-[1.01]': isDragging,
                             'border-red-300 bg-red-50/30': errorMessage,
                             'border-emerald-300 bg-emerald-50/20': (imagePreview || existingPhoto) && !errorMessage && !isPhotoRemoved,
                             'border-slate-200 bg-slate-50/50 hover:bg-slate-100/70 hover:border-slate-300': !isDragging && !imagePreview && (!existingPhoto || isPhotoRemoved) && !errorMessage
                         }"
                         class="relative border-2 border-dashed rounded-3xl p-6 transition-all duration-200 text-center">
                        
                        <!-- State A: Has Existing Server Photo (and hasn't chosen new one) -->
                        <div x-show="existingPhoto && !imagePreview && !isPhotoRemoved" class="flex flex-col sm:flex-row items-center justify-between gap-4 p-2">
                            <div class="flex items-center gap-4">
                                <div class="relative w-20 h-20 rounded-2xl overflow-hidden shadow-md border-2 border-white bg-slate-900 flex-shrink-0">
                                    <img :src="existingPhoto" alt="Current Photo" class="w-full h-full object-cover">
                                </div>
                                <div class="text-left">
                                    <div class="flex items-center gap-2">
                                        <span class="text-sm font-black text-slate-900">Current Photo Active</span>
                                        <span class="px-2 py-0.5 rounded-full text-[10px] font-black uppercase tracking-wider bg-blue-100 text-blue-800">Saved</span>
                                    </div>
                                    <p class="text-xs text-slate-400 mt-0.5 font-medium">Uploaded profile picture</p>
                                    <p class="text-[11px] text-blue-600 font-bold mt-1 flex items-center gap-1">
                                        <i class="ph-bold ph-shield-check"></i> Displayed on live scoreboards
                                    </p>
                                </div>
                            </div>

                            <div class="flex items-center gap-2">
                                <button type="button" 
                                        @click="document.getElementById('photoInput').click()" 
                                        class="px-3.5 py-2 rounded-xl text-xs font-bold bg-slate-100 hover:bg-slate-200 text-slate-700 transition flex items-center gap-1.5">
                                    <i class="ph-bold ph-upload-simple"></i> Replace Photo
                                </button>
                                <button type="button" 
                                        @click="markRemoveExistingPhoto()" 
                                        class="px-3.5 py-2 rounded-xl text-xs font-bold bg-red-50 hover:bg-red-600 hover:text-white text-red-600 transition flex items-center gap-1.5">
                                    <i class="ph-bold ph-trash"></i> Delete Photo
                                </button>
                            </div>
                        </div>

                        <!-- State B: Selected New File (Preview active) -->
                        <div x-show="imagePreview" x-transition.opacity.duration.300ms class="flex flex-col sm:flex-row items-center justify-between gap-4 p-2">
                            <div class="flex items-center gap-4">
                                <div class="relative w-20 h-20 rounded-2xl overflow-hidden shadow-md border-2 border-white bg-slate-900 flex-shrink-0 group">
                                    <img :src="imagePreview" alt="Preview" class="w-full h-full object-cover">
                                    <div class="absolute inset-0 bg-black/40 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center">
                                        <i class="ph-bold ph-eye text-white text-lg"></i>
                                    </div>
                                </div>
                                <div class="text-left">
                                    <div class="flex items-center gap-2">
                                        <span class="text-sm font-black text-slate-900 max-w-[220px] truncate" x-text="fileName"></span>
                                        <span class="px-2 py-0.5 rounded-full text-[10px] font-black uppercase tracking-wider bg-emerald-100 text-emerald-800">New File</span>
                                    </div>
                                    <p class="text-xs text-slate-400 mt-0.5 font-medium" x-text="fileSize"></p>
                                    <p class="text-[11px] text-emerald-600 font-bold mt-1 flex items-center gap-1">
                                        <i class="ph-bold ph-check-circle"></i> Ready to replace photo on save
                                    </p>
                                </div>
                            </div>

                            <div class="flex items-center gap-2">
                                <button type="button" 
                                        @click="document.getElementById('photoInput').click()" 
                                        class="px-3.5 py-2 rounded-xl text-xs font-bold bg-slate-100 hover:bg-slate-200 text-slate-700 transition flex items-center gap-1.5">
                                    <i class="ph-bold ph-arrows-clockwise"></i> Change
                                </button>
                                <button type="button" 
                                        @click="cancelNewFile()" 
                                        class="px-3.5 py-2 rounded-xl text-xs font-bold bg-red-50 hover:bg-red-600 hover:text-white text-red-600 transition flex items-center gap-1.5">
                                    <i class="ph-bold ph-trash"></i> Cancel
                                </button>
                            </div>
                        </div>

                        <!-- State C: No Photo & None Selected (Or marked for deletion) -->
                        <div x-show="!imagePreview && (!existingPhoto || isPhotoRemoved)" class="py-4">
                            <div class="w-14 h-14 rounded-2xl bg-blue-50 text-blue-600 flex items-center justify-center mx-auto mb-3 border border-blue-100 shadow-sm transition-transform group-hover:scale-110">
                                <i class="ph-bold ph-cloud-arrow-up text-2xl"></i>
                            </div>
                            <p class="text-sm font-bold text-slate-700 mb-1">
                                <button type="button" @click="document.getElementById('photoInput').click()" class="text-blue-600 hover:text-blue-700 hover:underline">
                                    Click to upload new photo
                                </button> 
                                or drag and drop
                            </p>
                            <p class="text-xs text-slate-400 font-bold uppercase tracking-wider">
                                PNG, JPG, JPEG, WEBP &bull; Max 2MB
                            </p>
                            <template x-if="isPhotoRemoved && existingPhoto">
                                <p class="text-xs text-amber-600 font-bold mt-2">
                                    ⚠️ Photo will be removed upon saving. 
                                    <button type="button" @click="isPhotoRemoved = false" class="underline text-blue-600 ml-1">Undo</button>
                                </p>
                            </template>
                        </div>

                    </div>
                </div>
                
            </div>
            
            <div class="mt-8 pt-6 border-t border-slate-100 flex items-center justify-between">
                <a href="<?= BASE_URL ?>/admin/players/index.php" class="bg-white border border-slate-200 hover:bg-slate-50 text-slate-700 font-bold py-3 px-6 rounded-xl transition text-sm">
                    Cancel
                </a>
                <button type="submit" class="bg-[#0f2044] hover:bg-blue-900 text-white font-black py-3.5 px-8 rounded-xl shadow-md hover:shadow-xl transition-all flex items-center gap-2 text-sm">
                    <i class="ph-bold ph-floppy-disk text-lg"></i>
                    <span>Save Changes</span>
                </button>
            </div>
        </form>
    </div>

    <!-- ============================================================ -->
    <!-- 3D STADIUM BADMINTON SMASH SAVING OVERLAY                   -->
    <!-- ============================================================ -->
    <div x-show="isUploading" 
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="opacity-0 scale-95"
         x-transition:enter-end="opacity-100 scale-100"
         class="fixed inset-0 z-50 bg-[#020612]/92 backdrop-blur-2xl flex items-center justify-center p-4 sm:p-6"
         style="display: none;">
        
        <!-- Solid High-Contrast Luxury Container Card -->
        <div class="bg-gradient-to-b from-[#0a1733] to-[#060e22] border-2 border-[#c9a84c]/40 rounded-3xl p-6 sm:p-8 max-w-2xl w-full shadow-[0_30px_90px_rgba(0,0,0,0.9)] text-center relative overflow-hidden ring-1 ring-white/20">
            
            <!-- Glowing ambient backdrop lights inside card -->
            <div class="absolute -top-24 left-1/4 w-60 h-60 bg-blue-500/20 rounded-full blur-[80px] pointer-events-none"></div>
            <div class="absolute -bottom-24 right-1/4 w-60 h-60 bg-[#c9a84c]/20 rounded-full blur-[80px] pointer-events-none"></div>

            <!-- 3D Stadium Canvas Viewport -->
            <div class="relative w-full h-56 sm:h-64 rounded-2xl overflow-hidden shadow-2xl border border-white/15 bg-[#030914] mb-5">
                <canvas id="playerEditSmashCanvas" class="w-full h-full block"></canvas>
                
                <div class="absolute top-3 left-3 flex items-center gap-2 px-3 py-1 rounded-full bg-[#0f2044]/95 border border-white/20 text-[10px] font-black uppercase tracking-wider text-slate-100 shadow-md">
                    <span class="w-2 h-2 rounded-full bg-emerald-400 animate-ping"></span>
                    <span>TKMI 3D Match Engine</span>
                </div>

                <div class="absolute bottom-3 right-3 flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-black/80 border border-white/10 text-[9px] font-mono text-slate-300">
                    <span>60 FPS &bull; Ballistic Physics</span>
                </div>
            </div>

            <!-- High-Contrast Status Badge -->
            <div class="inline-flex items-center gap-2 px-4 py-1.5 rounded-full bg-[#c9a84c]/20 border border-[#c9a84c]/50 text-[#c9a84c] text-xs font-black uppercase tracking-widest mb-2 shadow-sm">
                <span class="w-2 h-2 rounded-full bg-[#c9a84c] animate-ping"></span>
                <span x-text="smashStatus">SAVING PLAYER DETAILS...</span>
            </div>

            <!-- High-Contrast Title & Subtitle -->
            <h3 class="text-2xl sm:text-3xl font-black font-display text-white mb-1.5 tracking-tight drop-shadow-sm">Updating Player Profile</h3>
            <p class="text-xs sm:text-sm text-slate-300 mb-5 font-medium max-w-md mx-auto leading-relaxed">Syncing community credentials, updating scoreboard display names, and saving photo assets.</p>

            <!-- High-Contrast Neon Progress Bar -->
            <div class="w-full max-w-lg mx-auto bg-slate-950 h-4 rounded-full overflow-hidden p-0.5 border border-white/20 shadow-inner">
                <div class="bg-gradient-to-r from-blue-500 via-[#c9a84c] to-emerald-400 h-full rounded-full transition-all duration-300 shadow-[0_0_15px_rgba(201,168,76,0.9)]"
                     :style="'width: ' + uploadProgress + '%'"></div>
            </div>
            
            <div class="max-w-lg mx-auto flex items-center justify-between text-xs text-slate-300 font-mono font-bold mt-2 px-1">
                <span>DATABASE SYNCHRONIZATION</span>
                <span x-text="uploadProgress + '%'" class="text-[#c9a84c] font-black text-sm"></span>
            </div>
        </div>
    </div>

</div>

<script src="<?= BASE_URL ?>/assets/vendor/badminton-smash-engine.js"></script>
<script>
function playerEditHandler() {
    return {
        existingPhoto: '<?= !empty($player['photo_path']) ? BASE_URL . '/uploads/players/' . e($player['photo_path']) : '' ?>',
        isPhotoRemoved: false,
        imagePreview: null,
        fileName: '',
        fileSize: '',
        errorMessage: '',
        isDragging: false,
        isUploading: false,
        uploadProgress: 0,
        smashStatus: 'SAVING PLAYER DETAILS...',
        smashEngine: null,

        validateAndPreview(file) {
            this.errorMessage = '';
            if (!file) return;

            const validTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'];
            if (!validTypes.includes(file.type)) {
                this.errorMessage = 'Invalid file type. Only JPG, PNG, and WebP images are allowed.';
                this.cancelNewFile();
                return;
            }

            const maxSize = 2 * 1024 * 1024;
            if (file.size > maxSize) {
                const actualMb = (file.size / (1024 * 1024)).toFixed(2);
                this.errorMessage = `File is too large (${actualMb} MB). Maximum allowed size is 2 MB.`;
                this.cancelNewFile();
                return;
            }

            this.fileName = file.name;
            if (file.size < 1024 * 1024) {
                this.fileSize = (file.size / 1024).toFixed(1) + ' KB';
            } else {
                this.fileSize = (file.size / (1024 * 1024)).toFixed(2) + ' MB';
            }

            const reader = new FileReader();
            reader.onload = (e) => {
                this.imagePreview = e.target.result;
                this.isPhotoRemoved = false;
            };
            reader.readAsDataURL(file);
        },

        handleFileSelect(event) {
            const file = event.target.files[0];
            if (file) this.validateAndPreview(file);
        },

        handleDrop(event) {
            this.isDragging = false;
            const file = event.dataTransfer.files[0];
            if (file) {
                const dataTransfer = new DataTransfer();
                dataTransfer.items.add(file);
                document.getElementById('photoInput').files = dataTransfer.files;
                this.validateAndPreview(file);
            }
        },

        cancelNewFile() {
            this.imagePreview = null;
            this.fileName = '';
            this.fileSize = '';
            document.getElementById('photoInput').value = '';
        },

        markRemoveExistingPhoto() {
            this.isPhotoRemoved = true;
            this.cancelNewFile();
        },

        handleSubmit(event) {
            if (this.isUploading) {
                event.preventDefault();
                return;
            }

            const form = document.getElementById('playerEditForm');
            if (!form.checkValidity()) return;

            this.isUploading = true;
            this.uploadProgress = 15;

            // Start 3D Canvas Engine
            this.$nextTick(() => {
                setTimeout(() => {
                    if (typeof BadmintonSmashEngine !== 'undefined') {
                        this.smashEngine = new BadmintonSmashEngine('playerEditSmashCanvas');
                        this.smashEngine.start();
                    }
                }, 50);
            });

            const statuses = [
                'UPDATING PLAYER CREDENTIALS...',
                'OPTIMIZING AVATAR RESOLUTION...',
                'UPDATING SCOREBOARDS & BRACKETS...',
                'SAVING TO DATABASE...'
            ];
            let statusIdx = 0;

            setInterval(() => {
                if (this.uploadProgress < 92) {
                    this.uploadProgress += Math.floor(Math.random() * 16) + 5;
                    if (this.uploadProgress > 92) this.uploadProgress = 92;
                    statusIdx = (statusIdx + 1) % statuses.length;
                    this.smashStatus = statuses[statusIdx];
                }
            }, 280);
        }
    };
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
