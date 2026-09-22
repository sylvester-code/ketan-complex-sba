<?php
/**
 * KETAN M/A B COMPLEX - System Settings & Grading Scale Configuration
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/session.php';

requireRole(['admin']);

$db = getDB();

// Handle Settings Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        flash('danger', 'Security verification failed.');
        header('Location: settings.php');
        exit;
    }

    $action = $_POST['action'];

    if ($action === 'update_school_settings') {
        $settingsToSave = [
            'school_name'       => trim($_POST['school_name'] ?? 'KETAN M/A B COMPLEX'),
            'school_motto'      => trim($_POST['school_motto'] ?? ''),
            'school_tagline'    => trim($_POST['school_tagline'] ?? ''),
            'school_address'    => trim($_POST['school_address'] ?? ''),
            'school_phone'      => trim($_POST['school_phone'] ?? ''),
            'school_email'      => trim($_POST['school_email'] ?? ''),
            'head_teacher_name' => trim($_POST['head_teacher_name'] ?? ''),
            'max_sba_score'     => (float)($_POST['max_sba_score'] ?? 50),
            'max_exam_score'    => (float)($_POST['max_exam_score'] ?? 50),
            'default_pass_mark' => (float)($_POST['default_pass_mark'] ?? 50),
        ];

        // Ensure SBA + Exam = 100
        if (($settingsToSave['max_sba_score'] + $settingsToSave['max_exam_score']) != 100) {
            flash('danger', 'Maximum SBA Score + Maximum Exam Score must equal 100% total.');
        } else {
            try {
                $stmt = $db->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
                foreach ($settingsToSave as $k => $v) {
                    $stmt->execute([$k, (string)$v]);
                }
                logActivity('UPDATE_SETTINGS', 'Updated school profile and assessment weight settings (50/50)', 'system_settings');
                flash('success', 'School configuration updated successfully.');
            } catch (Exception $e) {
                flash('danger', 'Error updating settings: ' . $e->getMessage());
            }
        }
        header('Location: settings.php');
        exit;

    } elseif ($action === 'clear_data') {
        if (!isAdmin()) {
            flash('danger', 'Only administrators can perform system data reset.');
            header('Location: settings.php');
            exit;
        }

        $type = $_POST['clear_type'] ?? '';
        try {
            $db->beginTransaction();

            if ($type === 'students_only') {
                $db->exec("DELETE FROM marks");
                $db->exec("DELETE FROM student_term_reports");
                $db->exec("DELETE FROM assessment_submissions");
                $db->exec("DELETE FROM students");

                // Clean up student photos
                if (is_dir(UPLOAD_DIR)) {
                    $files = glob(UPLOAD_DIR . '/*');
                    foreach ($files as $f) {
                        if (is_file($f)) @unlink($f);
                    }
                }

                $db->commit();
                logActivity('CLEAR_DATA', 'Cleared all student records, reports, photos, and assessment marks', 'students');
                flash('success', 'All student records, submissions, terminal reports, and marks have been completely cleared.');
            } elseif ($type === 'teachers_only') {
                $db->exec("UPDATE classes SET class_teacher_id = NULL, class_teacher_assigned_at = NULL");
                $db->exec("DELETE FROM class_teachers");
                $db->exec("DELETE FROM teacher_assignments");
                $db->exec("DELETE FROM users WHERE role = 'teacher'");

                // Clean up teacher signatures
                if (is_dir(SIGNATURE_UPLOAD_DIR)) {
                    $sigs = glob(SIGNATURE_UPLOAD_DIR . '/*');
                    foreach ($sigs as $s) {
                        if (is_file($s)) @unlink($s);
                    }
                }

                $db->commit();
                logActivity('CLEAR_DATA', 'Cleared all teacher accounts and assignments', 'users');
                flash('success', 'All teacher accounts and subject allocations have been cleared.');
            } elseif ($type === 'all_records') {
                $db->exec("DELETE FROM marks");
                $db->exec("DELETE FROM student_term_reports");
                $db->exec("DELETE FROM assessment_submissions");
                $db->exec("DELETE FROM students");
                $db->exec("UPDATE classes SET class_teacher_id = NULL, class_teacher_assigned_at = NULL");
                $db->exec("DELETE FROM class_teachers");
                $db->exec("DELETE FROM teacher_assignments");
                
                $currId = (int)currentUserId();
                $db->prepare("DELETE FROM users WHERE role != 'admin' AND id != ?")->execute([$currId]);

                if (is_dir(UPLOAD_DIR)) {
                    $files = glob(UPLOAD_DIR . '/*');
                    foreach ($files as $f) {
                        if (is_file($f)) @unlink($f);
                    }
                }
                if (is_dir(SIGNATURE_UPLOAD_DIR)) {
                    $sigs = glob(SIGNATURE_UPLOAD_DIR . '/*');
                    foreach ($sigs as $s) {
                        if (is_file($s)) @unlink($s);
                    }
                }

                $db->commit();
                logActivity('RESET_SYSTEM', 'Cleared all students, teachers, marks, and assignments', 'system');
                flash('success', 'All student and teacher data has been completely cleared. Administrator account preserved.');
            }
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            flash('danger', 'Error clearing data: ' . $e->getMessage());
        }
        header('Location: settings.php');
        exit;
    } elseif ($action === 'upload_school_logo') {
        if (!isAdmin()) {
            flash('danger', 'Unauthorized action.');
            header('Location: settings.php');
            exit;
        }

        if (isset($_FILES['school_logo']) && $_FILES['school_logo']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['school_logo'];
            $allowedExts = ['png', 'jpg', 'jpeg', 'svg', 'webp', 'gif'];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

            if (!in_array($ext, $allowedExts)) {
                flash('danger', 'Invalid image format. Allowed formats: PNG, JPG, JPEG, SVG, WebP, GIF.');
            } elseif ($file['size'] > 5 * 1024 * 1024) {
                flash('danger', 'Image size must not exceed 5MB.');
            } else {
                if (!is_dir(LOGO_UPLOAD_DIR)) {
                    mkdir(LOGO_UPLOAD_DIR, 0777, true);
                }
                $filename = 'school_logo_' . time() . '.' . $ext;
                $target = LOGO_UPLOAD_DIR . DIRECTORY_SEPARATOR . $filename;

                if (move_uploaded_file($file['tmp_name'], $target)) {
                    $stmt = $db->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('school_logo', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
                    $stmt->execute([$filename]);
                    logActivity('UPLOAD_LOGO', 'Uploaded new school logo', 'system_settings');
                    flash('success', 'School logo successfully uploaded and updated across all portals, report cards, and broadsheets!');
                } else {
                    flash('danger', 'Failed to save logo image file. Check directory permissions.');
                }
            }
        } else {
            flash('danger', 'Please select a valid image file to upload.');
        }

        $redirect = !empty($_POST['redirect_to']) ? $_POST['redirect_to'] : 'settings.php';
        header('Location: ' . $redirect);
        exit;

    } elseif ($action === 'reset_school_logo') {
        if (!isAdmin()) {
            flash('danger', 'Unauthorized action.');
            header('Location: settings.php');
            exit;
        }

        $stmt = $db->prepare("DELETE FROM system_settings WHERE setting_key = 'school_logo'");
        $stmt->execute();
        logActivity('RESET_LOGO', 'Reset school logo to default vector crest', 'system_settings');
        flash('success', 'School logo has been reset to default vector crest.');

        $redirect = !empty($_POST['redirect_to']) ? $_POST['redirect_to'] : 'settings.php';
        header('Location: ' . $redirect);
        exit;

    } elseif ($action === 'update_grading_scale') {
        $tiers = $_POST['scale'] ?? [];
        try {
            $update = $db->prepare("UPDATE grading_scale SET min_score = ?, max_score = ?, remark = ? WHERE grade = ?");
            foreach ($tiers as $grade => $t) {
                $min = (float)($t['min'] ?? 0);
                $max = (float)($t['max'] ?? 100);
                $remark = trim($t['remark'] ?? '');
                $update->execute([$min, $max, $remark, $grade]);
            }
            logActivity('UPDATE_GRADING_SCALE', 'Updated terminal grading scale boundaries', 'grading_scale');
            flash('success', 'Grading scale updated successfully.');
        } catch (Exception $e) {
            flash('danger', 'Error updating scale: ' . $e->getMessage());
        }
        header('Location: settings.php');
        exit;
    }
}

// Fetch Current Settings & Scale
$gradingScale = getGradingScale();

$pageTitle = 'System Settings';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
require_once __DIR__ . '/includes/navbar.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h2 class="h3 fw-bold mb-1" style="font-family: 'Outfit';">System Settings & Policies</h2>
        <div class="text-muted small">Configure school details, assessment weightings, and grading scale parameters</div>
    </div>
</div>

<div class="row g-4">
    <!-- School Logo Customization Card (Full Width or Top of Left Column) -->
    <div class="col-12">
        <div class="card-custom mb-2 border shadow-sm">
            <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span class="fw-bold fs-6"><i class="bi bi-image-fill text-primary me-2"></i>Official School Crest / Logo Customization</span>
                <span class="badge bg-light text-muted border">Supported: PNG, JPG, JPEG, SVG, WebP (Max 5MB)</span>
            </div>
            <div class="card-body p-4">
                <div class="row align-items-center g-4">
                    <div class="col-md-3 text-center">
                        <div class="p-3 border rounded-3 bg-light d-inline-block shadow-sm" style="min-width: 150px; min-height: 150px;">
                            <img src="<?= getSchoolLogoUrl() ?>" alt="Current School Logo" id="logoPreview" style="max-width: 130px; max-height: 130px; object-fit: contain;">
                        </div>
                        <div class="small text-muted mt-2 fw-bold">Active System Crest</div>
                    </div>
                    <div class="col-md-9">
                        <form method="POST" action="settings.php" enctype="multipart/form-data">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="upload_school_logo">

                            <div class="mb-3">
                                <label class="form-label small fw-bold">Upload Custom School Logo File <span class="text-danger">*</span></label>
                                <input type="file" name="school_logo" id="logoInput" class="form-control" accept="image/png,image/jpeg,image/webp,image/svg+xml" required onchange="previewLogo(this)">
                                <div class="form-text small">
                                    <i class="bi bi-info-circle me-1 text-primary"></i> Uploading a custom picture here automatically updates the school crest across the <strong>Top Navbar</strong>, <strong>Sidebar Menu</strong>, <strong>Login Page</strong>, and all <strong>Printable Student Terminal Report Cards</strong> & <strong>Broadsheets</strong>.
                                </div>
                            </div>

                            <div class="d-flex flex-wrap gap-2">
                                <button type="submit" class="btn btn-primary-custom px-4">
                                    <i class="bi bi-cloud-arrow-up-fill me-1"></i> Upload & Apply School Logo
                                </button>
                                <?php if (!empty(getSetting('school_logo'))): ?>
                                    <button type="submit" name="action" value="reset_school_logo" class="btn btn-outline-secondary px-3" onclick="return confirm('Reset the school logo back to the default vector crest?');">
                                        <i class="bi bi-arrow-counterclockwise me-1"></i> Reset to Default Logo
                                    </button>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- School Profile & Assessment Rules -->
    <div class="col-lg-7">
        <div class="card-custom mb-4">
            <div class="card-header bg-white">
                <span><i class="bi bi-gear-fill text-primary me-2"></i>School Details & Assessment Weights</span>
            </div>
            <div class="card-body p-4">
                <form method="POST" action="settings.php">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="update_school_settings">

                    <div class="mb-3">
                        <label class="form-label small fw-bold">School Name <span class="text-danger">*</span></label>
                        <input type="text" name="school_name" class="form-control fw-bold" value="<?= htmlspecialchars(getSetting('school_name', 'KETAN M/A B COMPLEX')) ?>" required>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">School Motto</label>
                            <input type="text" name="school_motto" class="form-control fst-italic" value="<?= htmlspecialchars(getSetting('school_motto', 'Knowledge, Discipline and Excellence')) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">System Tagline</label>
                            <input type="text" name="school_tagline" class="form-control" value="<?= htmlspecialchars(getSetting('school_tagline', 'School-Based Assessment & Performance Management System')) ?>">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-bold">Official Address</label>
                        <input type="text" name="school_address" class="form-control" value="<?= htmlspecialchars(getSetting('school_address', 'P.O. Box 450, Ketan, Sekondi-Takoradi, Western Region, Ghana')) ?>">
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Telephone Contact</label>
                            <input type="tel" name="school_phone" class="form-control" value="<?= htmlspecialchars(getSetting('school_phone', '+233 (0) 31 204 5678')) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Official Email</label>
                            <input type="email" name="school_email" class="form-control" value="<?= htmlspecialchars(getSetting('school_email', 'info@ketancomplexmjhs.edu.gh')) ?>">
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label small fw-bold">Head Teacher's Full Name (for Report Cards)</label>
                        <input type="text" name="head_teacher_name" class="form-control" value="<?= htmlspecialchars(getSetting('head_teacher_name', 'Mr. Emmanuel K. Mensah (B.Ed, M.Ed)')) ?>">
                    </div>

                    <h6 class="fw-bold text-primary mb-3 pb-2 border-bottom" style="font-family: 'Outfit';">
                        <i class="bi bi-percent me-1"></i> Assessment Score Weighting Structure
                    </h6>

                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <label class="form-label small fw-bold">Maximum SBA / Class Score (%)</label>
                            <input type="number" step="1" min="10" max="90" name="max_sba_score" class="form-control text-center fw-bold" value="<?= htmlspecialchars(getSetting('max_sba_score', '50')) ?>" required>
                            <div class="form-text small">Continuous assessment ratio. Default: 50.</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold">Maximum Exam Score (%)</label>
                            <input type="number" step="1" min="10" max="90" name="max_exam_score" class="form-control text-center fw-bold" value="<?= htmlspecialchars(getSetting('max_exam_score', '50')) ?>" required>
                            <div class="form-text small">Terminal exam ratio. Default: 50.</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold">Default Pass Mark (%)</label>
                            <input type="number" step="1" min="1" max="100" name="default_pass_mark" class="form-control text-center fw-bold" value="<?= htmlspecialchars(getSetting('default_pass_mark', '50')) ?>" required>
                            <div class="form-text small">Benchmark for pass status.</div>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary-custom px-4">
                        <i class="bi bi-save me-1"></i> Save System Settings
                    </button>
                </form>
            </div>
        </div>

        <!-- Danger Zone: Clear Data / Factory Reset -->
        <div class="card border-danger mb-4 shadow-sm">
            <div class="card-header bg-danger text-white d-flex justify-content-between align-items-center">
                <span class="fw-bold"><i class="bi bi-exclamation-triangle-fill me-2"></i>System Reset & Data Management</span>
                <span class="badge bg-white text-danger font-monospace">Admin Only</span>
            </div>
            <div class="card-body p-4">
                <p class="small text-muted mb-3">
                    Use these options to clear existing records or reset data when preparing for a new academic cycle or testing. The primary administrator account (<code>COMPLEX</code>) will remain intact.
                </p>
                <div class="d-flex flex-wrap gap-2">
                    <form method="POST" action="settings.php" onsubmit="return confirm('WARNING: This will permanently delete all student profiles, marks, and assessment submissions. Continue?');">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="clear_data">
                        <input type="hidden" name="clear_type" value="students_only">
                        <button type="submit" class="btn btn-sm btn-outline-danger">
                            <i class="bi bi-trash3 me-1"></i> Clear All Students & Marks
                        </button>
                    </form>

                    <form method="POST" action="settings.php" onsubmit="return confirm('WARNING: This will delete all teacher accounts and their class assignments. Continue?');">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="clear_data">
                        <input type="hidden" name="clear_type" value="teachers_only">
                        <button type="submit" class="btn btn-sm btn-outline-danger">
                            <i class="bi bi-person-x me-1"></i> Clear All Teachers
                        </button>
                    </form>

                    <form method="POST" action="settings.php" onsubmit="return confirm('CRITICAL ACTION: This will completely wipe all student records, marks, and teacher accounts, leaving only the COMPLEX administrator account. Continue?');">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="clear_data">
                        <input type="hidden" name="clear_type" value="all_records">
                        <button type="submit" class="btn btn-sm btn-danger">
                            <i class="bi bi-arrow-repeat me-1"></i> Clear All Student & Teacher Data
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Configurable Grading Scale -->
    <div class="col-lg-5">
        <div class="card-custom">
            <div class="card-header bg-white">
                <span><i class="bi bi-award-fill text-warning me-2"></i>Grading Scale & Remarks</span>
            </div>
            <div class="card-body p-4">
                <p class="small text-muted mb-3">
                    Configure the minimum and maximum numeric mark boundaries for each academic grade and its corresponding remark.
                </p>

                <form method="POST" action="settings.php">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="update_grading_scale">

                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-4">
                            <thead>
                                <tr>
                                    <th>Grade</th>
                                    <th>Min %</th>
                                    <th>Max %</th>
                                    <th>Remark</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($gradingScale as $g): 
                                    $grd = $g['grade'];
                                ?>
                                    <tr>
                                        <td><span class="grade-badge grade-<?= $grd ?>"><?= $grd ?></span></td>
                                        <td>
                                            <input type="number" step="0.01" min="0" max="100" name="scale[<?= $grd ?>][min]" class="form-control form-control-sm text-center font-monospace" value="<?= (float)$g['min_score'] ?>" style="width: 70px;">
                                        </td>
                                        <td>
                                            <input type="number" step="0.01" min="0" max="100" name="scale[<?= $grd ?>][max]" class="form-control form-control-sm text-center font-monospace" value="<?= (float)$g['max_score'] ?>" style="width: 70px;">
                                        </td>
                                        <td>
                                            <input type="text" name="scale[<?= $grd ?>][remark]" class="form-control form-control-sm" value="<?= htmlspecialchars($g['remark']) ?>">
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <button type="submit" class="btn btn-primary-custom w-100">
                        <i class="bi bi-check2-circle me-1"></i> Update Grading Scale
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function previewLogo(input) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            const preview = document.getElementById('logoPreview');
            if (preview) preview.src = e.target.result;
        }
        reader.readAsDataURL(input.files[0]);
    }
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
