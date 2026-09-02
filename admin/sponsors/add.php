<?php
// ============================================================
// Sponsors - Add (with Live Preview & Badminton Smash Upload)
// ============================================================
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireLogin();

$pageTitle = 'Add Sponsor';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        flash_set('sponsor', 'Invalid security token.', 'error');
        header('Location: add.php');
        exit;
    }

    $name = trim($_POST['name'] ?? '');
    
    if (empty($name)) {
        flash_set('sponsor', 'Sponsor name is required.', 'error');
        header('Location: add.php');
        exit;
    }

    if (empty($_FILES['logo']['name'])) {
        flash_set('sponsor', 'Sponsor logo is required.', 'error');
        header('Location: add.php');
        exit;
    }

    // Photo Upload
    $photoName = uploadImage($_FILES['logo'], __DIR__ . '/../../uploads/sponsors', 'sp_' . time());
    if ($photoName === false) {
        flash_set('sponsor', 'Failed to upload logo image.', 'error');
        header('Location: add.php');
        exit;
    }

    try {
        $stmt = db()->prepare('INSERT INTO sponsors (name, image_path) VALUES (?, ?)');
        $stmt->execute([$name, $photoName]);
        AppCache::flush();
        flash_set('sponsor', 'Sponsor added successfully!', 'success');
        header('Location: index.php');
        exit;
    } catch (PDOException $e) {
        if ($photoName) deleteUpload(__DIR__ . '/../../uploads/sponsors/' . $photoName);
        flash_set('sponsor', 'Database error: ' . $e->getMessage(), 'error');
        header('Location: add.php');
        exit;
    }
}

include __DIR__ . '/../includes/header.php';
?>

<div class="mb-8">
    <a href="<?= BASE_URL ?>/admin/sponsors/index.php" class="text-sm font-bold text-slate-500 hover:text-[#0f2044] flex items-center gap-1 mb-2 transition-colors">
        <i class="ph-bold ph-arrow-left"></i> Back to Sponsors
    </a>
    <h2 class="text-3xl font-black font-display text-[#0f2044]">Add New Sponsor</h2>
</div>

<?= flash_html('sponsor') ?>

<!-- Alpine Container with Live Preview & Smash Animation -->
<div x-data="sponsorFormHandler()" class="space-y-6">

    <div class="bg-white border border-slate-200 rounded-3xl shadow-[0_4px_20px_rgba(0,0,0,0.03)] overflow-hidden max-w-2xl">
        <form action="add.php" method="POST" enctype="multipart/form-data" @submit="handleSubmit($event)" class="p-8" id="sponsorForm">
            <?= csrf_field() ?>
            
            <div class="space-y-6">
                
                <div>
                    <label class="block text-sm font-bold text-slate-700 mb-2">Sponsor Name <span class="text-red-500">*</span></label>
                    <input type="text" name="name" required placeholder="e.g. Al-Manar Travels" class="w-full bg-slate-50 border border-slate-200 text-slate-800 text-sm rounded-xl focus:ring-blue-500 focus:border-blue-500 block p-3 transition-colors">
                </div>

                <!-- ============================================================ -->
                <!-- NEXT-GEN INTERACTIVE DROPZONE WITH LIVE PREVIEW & ERRORS     -->
                <!-- ============================================================ -->
                <div>
                    <label class="block text-sm font-bold text-slate-700 mb-2">Logo Image <span class="text-red-500">*</span></label>
                    
                    <!-- Hidden native file input -->
                    <input type="file" 
                           name="logo" 
                           id="logoInput" 
                           required 
                           accept="image/png, image/jpeg, image/jpg, image/webp, image/svg+xml" 
                           class="hidden" 
                           @change="handleFileSelect($event)" />

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
                             'border-emerald-300 bg-emerald-50/20': imagePreview && !errorMessage,
                             'border-slate-200 bg-slate-50/50 hover:bg-slate-100/70 hover:border-slate-300': !isDragging && !imagePreview && !errorMessage
                         }"
                         class="relative border-2 border-dashed rounded-3xl p-6 transition-all duration-200 text-center">
                        
                        <!-- State A: Empty -->
                        <div x-show="!imagePreview" class="py-4">
                            <div class="w-14 h-14 rounded-2xl bg-amber-50 text-[#c9a84c] flex items-center justify-center mx-auto mb-3 border border-amber-100 shadow-sm transition-transform group-hover:scale-110">
                                <i class="ph-bold ph-image text-2xl"></i>
                            </div>
                            <p class="text-sm font-bold text-slate-700 mb-1">
                                <button type="button" @click="document.getElementById('logoInput').click()" class="text-blue-600 hover:text-blue-700 hover:underline">
                                    Click to upload logo
                                </button> 
                                or drag and drop
                            </p>
                            <p class="text-xs text-slate-400 font-bold uppercase tracking-wider">
                                PNG, SVG, JPG, WEBP &bull; Max 2MB
                            </p>
                        </div>

                        <!-- State B: Image Preview Card Active -->
                        <div x-show="imagePreview" x-transition.opacity.duration.300ms class="flex flex-col sm:flex-row items-center justify-between gap-4 p-2">
                            <div class="flex items-center gap-4">
                                <div class="relative w-20 h-20 rounded-2xl overflow-hidden shadow-md border-2 border-white bg-white p-2 flex items-center justify-center flex-shrink-0">
                                    <img :src="imagePreview" alt="Preview" class="max-w-full max-h-full object-contain">
                                </div>
                                <div class="text-left">
                                    <div class="flex items-center gap-2">
                                        <span class="text-sm font-black text-slate-900 max-w-[200px] truncate" x-text="fileName"></span>
                                        <span class="px-2 py-0.5 rounded-full text-[10px] font-black uppercase tracking-wider bg-emerald-100 text-emerald-800">Ready</span>
                                    </div>
                                    <p class="text-xs text-slate-400 mt-0.5 font-medium" x-text="fileSize"></p>
                                    <p class="text-[11px] text-emerald-600 font-bold mt-1 flex items-center gap-1">
                                        <i class="ph-bold ph-check-circle"></i> Logo validated
                                    </p>
                                </div>
                            </div>

                            <div class="flex items-center gap-2">
                                <button type="button" 
                                        @click="document.getElementById('logoInput').click()" 
                                        class="px-3.5 py-2 rounded-xl text-xs font-bold bg-slate-100 hover:bg-slate-200 text-slate-700 transition flex items-center gap-1.5">
                                    <i class="ph-bold ph-arrows-clockwise"></i> Change
                                </button>
                                <button type="button" 
                                        @click="removeFile()" 
                                        class="px-3.5 py-2 rounded-xl text-xs font-bold bg-red-50 hover:bg-red-600 hover:text-white text-red-600 transition flex items-center gap-1.5">
                                    <i class="ph-bold ph-trash"></i> Remove
                                </button>
                            </div>
                        </div>

                    </div>
                </div>
                
            </div>
            
            <div class="mt-8 pt-6 border-t border-slate-100 flex items-center justify-between">
                <a href="<?= BASE_URL ?>/admin/sponsors/index.php" class="bg-white border border-slate-200 hover:bg-slate-50 text-slate-700 font-bold py-3 px-6 rounded-xl transition text-sm">
                    Cancel
                </a>
                <button type="submit" class="bg-[#0f2044] hover:bg-blue-900 text-white font-black py-3.5 px-8 rounded-xl shadow-md hover:shadow-xl transition-all flex items-center gap-2 text-sm">
                    <i class="ph-bold ph-floppy-disk text-lg"></i>
                    <span>Save Sponsor</span>
                </button>
            </div>
        </form>
    </div>

<script src="<?= BASE_URL ?>/assets/vendor/badminton-smash-engine.js"></script>

    <!-- ============================================================ -->
    <!-- CRAZY 3D STADIUM BADMINTON SMASH UPLOADING OVERLAY          -->
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
                <canvas id="sponsorSmashCanvas" class="w-full h-full block"></canvas>
                
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
                <span x-text="smashStatus">OPTIMIZING LOGO...</span>
            </div>

            <!-- High-Contrast Title & Subtitle -->
            <h3 class="text-2xl sm:text-3xl font-black font-display text-white mb-1.5 tracking-tight drop-shadow-sm">Uploading Sponsor Logo</h3>
            <p class="text-xs sm:text-sm text-slate-300 mb-5 font-medium max-w-md mx-auto leading-relaxed">Please wait while the logo is processed and linked to platform footers and scoreboards.</p>

            <!-- High-Contrast Neon Progress Bar -->
            <div class="w-full max-w-lg mx-auto bg-slate-950 h-4 rounded-full overflow-hidden p-0.5 border border-white/20 shadow-inner">
                <div class="bg-gradient-to-r from-blue-500 via-[#c9a84c] to-emerald-400 h-full rounded-full transition-all duration-300 shadow-[0_0_15px_rgba(201,168,76,0.9)]"
                     :style="'width: ' + uploadProgress + '%'"></div>
            </div>
            
            <div class="max-w-lg mx-auto flex items-center justify-between text-xs text-slate-300 font-mono font-bold mt-2 px-1">
                <span>PROCESSING ASSETS</span>
                <span x-text="uploadProgress + '%'" class="text-[#c9a84c] font-black text-sm"></span>
            </div>
        </div>
    </div>

</div>

<script>
function sponsorFormHandler() {
    return {
        imagePreview: null,
        fileName: '',
        fileSize: '',
        errorMessage: '',
        isDragging: false,
        isUploading: false,
        uploadProgress: 0,
        smashStatus: 'OPTIMIZING LOGO...',
        smashEngine: null,

        validateAndPreview(file) {
            this.errorMessage = '';
            if (!file) return;

            const validTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp', 'image/svg+xml'];
            if (!validTypes.includes(file.type)) {
                this.errorMessage = 'Invalid file type. Only PNG, SVG, JPG, and WebP logos are allowed.';
                this.removeFile();
                return;
            }

            const maxSize = 2 * 1024 * 1024;
            if (file.size > maxSize) {
                const actualMb = (file.size / (1024 * 1024)).toFixed(2);
                this.errorMessage = `File is too large (${actualMb} MB). Maximum allowed size is 2 MB.`;
                this.removeFile();
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
                document.getElementById('logoInput').files = dataTransfer.files;
                this.validateAndPreview(file);
            }
        },

        removeFile() {
            this.imagePreview = null;
            this.fileName = '';
            this.fileSize = '';
            document.getElementById('logoInput').value = '';
        },

        handleSubmit(event) {
            if (this.isUploading) {
                event.preventDefault();
                return;
            }

            const form = document.getElementById('sponsorForm');
            if (!form.checkValidity()) return;

            this.isUploading = true;
            this.uploadProgress = 15;

            // Start 3D Canvas Engine
            this.$nextTick(() => {
                setTimeout(() => {
                    if (typeof BadmintonSmashEngine !== 'undefined') {
                        this.smashEngine = new BadmintonSmashEngine('sponsorSmashCanvas');
                        this.smashEngine.start();
                    }
                }, 50);
            });

            const statuses = [
                'OPTIMIZING LOGO ASSETS...',
                'GENERATING SCOREBOARD BADGES...',
                'UPDATING SPONSOR DIRECTORY...'
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
