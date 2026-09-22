<?php
/**
 * KETAN M/A B COMPLEX - Teacher Signatures Management
 * Super Admin interface to upload, update, remove, and preview teacher signatures.
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/session.php';

// Super Admin Only
requireRole('admin');

$db = getDB();

// Handle Signature Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        flash('danger', 'Security validation failed. Please try again.');
        header('Location: teacher_signatures.php');
        exit;
    }

    $action = $_POST['action'];

    if ($action === 'upload') {
        $teacherId = (int)($_POST['teacher_id'] ?? 0);

        // Fetch teacher
        $teacherStmt = $db->prepare("SELECT id, full_name, signature FROM users WHERE id = ? AND role IN ('teacher', 'headteacher') AND status = 'active'");
        $teacherStmt->execute([$teacherId]);
        $teacher = $teacherStmt->fetch();

        if (!$teacher) {
            flash('danger', 'Target teacher account not found or is inactive.');
        } elseif (empty($_FILES['signature']['name']) || $_FILES['signature']['error'] !== UPLOAD_ERR_OK) {
            flash('danger', 'Please select a valid image file to upload.');
        } else {
            $file = $_FILES['signature'];
            $maxBytes = 2 * 1024 * 1024; // 2MB
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $allowedExts = ['png', 'jpg', 'jpeg'];

            // Validation 1: Extension
            if (!in_array($ext, $allowedExts, true)) {
                flash('danger', 'Invalid file extension. Please upload a PNG, JPG, or JPEG image.');
            }
            // Validation 2: File Size
            elseif ($file['size'] > $maxBytes) {
                flash('danger', 'File size exceeds the 2MB limit. Please compress the signature image.');
            } else {
                // Validation 3: MIME Type
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mimeType = finfo_file($finfo, $file['tmp_name']);
                finfo_close($finfo);

                $allowedMimes = ['image/png', 'image/jpeg', 'image/pjpeg'];
                if (!in_array($mimeType, $allowedMimes, true)) {
                    flash('danger', 'Invalid file format detected (' . htmlspecialchars($mimeType) . '). Only valid images are allowed.');
                } else {
                    // Validation 4: Image Dimensions & Legitimacy
                    $imgInfo = @getimagesize($file['tmp_name']);
                    if (!$imgInfo) {
                        flash('danger', 'The uploaded file is not a valid image.');
                    } elseif ($imgInfo[0] > 2500 || $imgInfo[1] > 1500) {
                        flash('danger', 'Image dimensions too large (' . $imgInfo[0] . 'x' . $imgInfo[1] . 'px). Maximum allowed is 2500x1500px.');
                    } else {
                        // All checks passed!
                        if (!is_dir(SIGNATURE_UPLOAD_DIR)) {
                            mkdir(SIGNATURE_UPLOAD_DIR, 0777, true);
                        }

                        // Remove existing signature file if present
                        if (!empty($teacher['signature'])) {
                            $oldPath = SIGNATURE_UPLOAD_DIR . DIRECTORY_SEPARATOR . basename($teacher['signature']);
                            if (file_exists($oldPath)) {
                                @unlink($oldPath);
                            }
                        }

                        // Save new signature
                        $filename = 'sig_' . $teacherId . '_' . time() . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
                        $destination = SIGNATURE_UPLOAD_DIR . DIRECTORY_SEPARATOR . $filename;

                        if (move_uploaded_file($file['tmp_name'], $destination)) {
                            $updateStmt = $db->prepare("UPDATE users SET signature = ? WHERE id = ?");
                            $updateStmt->execute([$filename, $teacherId]);

                            logActivity('UPLOAD_SIGNATURE', "Uploaded official signature for {$teacher['full_name']}", 'users', $teacherId);
                            flash('success', "Signature for <strong>{$teacher['full_name']}</strong> uploaded successfully! It will now automatically appear on student report cards.");
                        } else {
                            flash('danger', 'Failed to save signature file on the server. Please check folder write permissions.');
                        }
                    }
                }
            }
        }
        header('Location: teacher_signatures.php');
        exit;

    } elseif ($action === 'remove') {
        $teacherId = (int)($_POST['teacher_id'] ?? 0);

        $teacherStmt = $db->prepare("SELECT id, full_name, signature FROM users WHERE id = ?");
        $teacherStmt->execute([$teacherId]);
        $teacher = $teacherStmt->fetch();

        if ($teacher && !empty($teacher['signature'])) {
            $filePath = SIGNATURE_UPLOAD_DIR . DIRECTORY_SEPARATOR . basename($teacher['signature']);
            if (file_exists($filePath)) {
                @unlink($filePath);
            }
            $updateStmt = $db->prepare("UPDATE users SET signature = NULL WHERE id = ?");
            $updateStmt->execute([$teacherId]);

            logActivity('REMOVE_SIGNATURE', "Removed signature for {$teacher['full_name']}", 'users', $teacherId);
            flash('success', "Signature for <strong>{$teacher['full_name']}</strong> has been removed. Report cards will display a clean blank line.");
        }
        header('Location: teacher_signatures.php');
        exit;
    }
}

// Fetch Filters
$search = trim($_GET['q'] ?? '');
$sigFilter = $_GET['sig_status'] ?? 'all'; // all, has_sig, no_sig

$query = "
    SELECT u.*, 
           c.id as assigned_class_id, c.class_name as assigned_class_name
    FROM users u
    LEFT JOIN classes c ON c.class_teacher_id = u.id AND c.status = 'active'
    WHERE u.role IN ('teacher', 'headteacher') AND u.status = 'active'
";
$params = [];

if ($sigFilter === 'has_sig') {
    $query .= " AND u.signature IS NOT NULL AND u.signature != ''";
} elseif ($sigFilter === 'no_sig') {
    $query .= " AND (u.signature IS NULL OR u.signature = '')";
}

if (!empty($search)) {
    $query .= " AND (u.full_name LIKE ? OR u.username LIKE ? OR u.email LIKE ? OR c.class_name LIKE ?)";
    $like = "%{$search}%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$query .= " ORDER BY (c.class_name IS NOT NULL) DESC, u.full_name ASC";
$stmt = $db->prepare($query);
$stmt->execute($params);
$teachers = $stmt->fetchAll();

// Statistics
$totalTeachersCount = $db->query("SELECT COUNT(*) FROM users WHERE role IN ('teacher', 'headteacher') AND status = 'active'")->fetchColumn() ?: 0;
$withSigCount = $db->query("SELECT COUNT(*) FROM users WHERE role IN ('teacher', 'headteacher') AND status = 'active' AND signature IS NOT NULL AND signature != ''")->fetchColumn() ?: 0;
$withoutSigCount = $totalTeachersCount - $withSigCount;
$classTeachersWithSig = $db->query("
    SELECT COUNT(*) FROM classes c 
    JOIN users u ON c.class_teacher_id = u.id 
    WHERE c.status = 'active' AND u.signature IS NOT NULL AND u.signature != ''
")->fetchColumn() ?: 0;

$pageTitle = 'Teacher Signatures Management';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
require_once __DIR__ . '/includes/navbar.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h2 class="h3 fw-bold mb-1" style="font-family: 'Outfit';">Teacher Signatures</h2>
        <div class="text-muted small">Upload and maintain official signatures used for automatic terminal report card generation</div>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= url('class_teachers.php') ?>" class="btn btn-outline-secondary">
            <i class="bi bi-person-check-fill me-1"></i> Class Teacher Assignments
        </a>
    </div>
</div>

<!-- Stats Row -->
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card h-100">
            <div>
                <div class="stat-title">Teaching Staff</div>
                <div class="stat-value text-dark"><?= $totalTeachersCount ?></div>
                <div class="stat-meta text-muted">Teachers & Headteacher</div>
            </div>
            <div class="stat-icon-wrapper stat-icon-primary">
                <i class="bi bi-people-fill"></i>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card h-100">
            <div>
                <div class="stat-title">Signatures Active</div>
                <div class="stat-value text-success"><?= $withSigCount ?></div>
                <div class="stat-meta text-success"><i class="bi bi-check-circle-fill me-1"></i> Ready for report cards</div>
            </div>
            <div class="stat-icon-wrapper stat-icon-success">
                <i class="bi bi-pen-fill"></i>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card h-100">
            <div>
                <div class="stat-title">Signatures Missing</div>
                <div class="stat-value text-warning"><?= $withoutSigCount ?></div>
                <div class="stat-meta text-muted">Awaiting upload</div>
            </div>
            <div class="stat-icon-wrapper stat-icon-warning">
                <i class="bi bi-exclamation-triangle-fill"></i>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card h-100">
            <div>
                <div class="stat-title">Class Master Signatures</div>
                <div class="stat-value text-info"><?= $classTeachersWithSig ?></div>
                <div class="stat-meta text-muted">Assigned Class Teachers</div>
            </div>
            <div class="stat-icon-wrapper stat-icon-info">
                <i class="bi bi-award-fill"></i>
            </div>
        </div>
    </div>
</div>

<!-- Filter Bar -->
<div class="card-custom mb-4 p-3">
    <form method="GET" action="teacher_signatures.php" class="row g-2 align-items-center">
        <div class="col-md-6">
            <div class="input-group">
                <span class="input-group-text bg-white"><i class="bi bi-search text-muted"></i></span>
                <input type="text" name="q" class="form-control" placeholder="Search teacher name, username, or assigned class..." value="<?= htmlspecialchars($search) ?>">
            </div>
        </div>
        <div class="col-md-4">
            <select name="sig_status" class="form-select" onchange="this.form.submit()">
                <option value="all" <?= $sigFilter === 'all' ? 'selected' : '' ?>>All Staff (<?= $totalTeachersCount ?>)</option>
                <option value="has_sig" <?= $sigFilter === 'has_sig' ? 'selected' : '' ?>>Signature Uploaded (<?= $withSigCount ?>)</option>
                <option value="no_sig" <?= $sigFilter === 'no_sig' ? 'selected' : '' ?>>Signature Missing (<?= $withoutSigCount ?>)</option>
            </select>
        </div>
        <div class="col-md-2 d-flex gap-1">
            <button type="submit" class="btn btn-primary w-100"><i class="bi bi-funnel"></i> Filter</button>
            <a href="teacher_signatures.php" class="btn btn-outline-secondary" title="Reset Filters"><i class="bi bi-arrow-counterclockwise"></i></a>
        </div>
    </form>
</div>

<!-- Signatures Table -->
<div class="card-custom">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <span><i class="bi bi-pen text-primary me-2"></i>Staff Signatures Directory (<?= count($teachers) ?>)</span>
        <span class="small text-muted">Supports transparent PNG, JPG, and JPEG</span>
    </div>
    <div class="table-responsive">
        <table class="table table-custom align-middle mb-0">
            <thead>
                <tr>
                    <th style="width: 25%;">Teacher / Staff</th>
                    <th style="width: 20%;">Designation & Class</th>
                    <th style="width: 25%;">Signature Preview</th>
                    <th style="width: 15%;">Status</th>
                    <th class="text-end" style="width: 15%;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($teachers)): ?>
                    <tr>
                        <td colspan="5" class="text-center py-5 text-muted">
                            <i class="bi bi-person-x fs-1 opacity-50 d-block mb-2"></i>
                            <h6>No teachers found matching your search</h6>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($teachers as $t): 
                        $sigUrl = getTeacherSignatureUrl($t['signature']);
                    ?>
                        <tr>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <div class="rounded-circle bg-primary-subtle text-primary d-flex align-items-center justify-content-center fw-bold" style="width: 40px; height: 40px; font-size: 0.95rem;">
                                        <?= strtoupper(substr($t['full_name'], 0, 1)) ?>
                                    </div>
                                    <div>
                                        <div class="fw-bold text-dark"><?= htmlspecialchars($t['full_name']) ?></div>
                                        <div class="small text-muted">
                                            <code><?= htmlspecialchars($t['username']) ?></code> &bull; <?= htmlspecialchars($t['email']) ?>
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <span class="badge <?= $t['role'] === 'headteacher' ? 'bg-primary' : 'bg-secondary' ?> mb-1">
                                    <?= strtoupper($t['role']) ?>
                                </span>
                                <?php if (!empty($t['assigned_class_name'])): ?>
                                    <div>
                                        <span class="badge bg-success-subtle text-success border border-success-subtle">
                                            <i class="bi bi-award me-1"></i> Class Teacher: <?= htmlspecialchars($t['assigned_class_name']) ?>
                                        </span>
                                    </div>
                                <?php else: ?>
                                    <div class="small text-muted">No class assigned</div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($sigUrl): ?>
                                    <div class="p-1 border rounded bg-light d-inline-block text-center" style="min-width: 130px; background: repeating-conic-gradient(#f1f5f9 0% 25%, #ffffff 0% 50%) 50% / 12px 12px;">
                                        <img src="<?= $sigUrl ?>" alt="Signature for <?= htmlspecialchars($t['full_name']) ?>" style="max-height: 48px; max-width: 160px; object-fit: contain;">
                                    </div>
                                <?php else: ?>
                                    <span class="text-muted fst-italic small">
                                        <i class="bi bi-dash-circle me-1"></i> Blank signature line
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($sigUrl): ?>
                                    <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1">
                                        <i class="bi bi-check-circle-fill me-1"></i> Active
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-warning-subtle text-warning border border-warning-subtle px-2 py-1">
                                        <i class="bi bi-clock-history me-1"></i> Pending
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <div class="btn-group btn-group-sm">
                                    <button type="button" class="btn btn-outline-primary" 
                                            onclick='openUploadSigModal(<?= htmlspecialchars(json_encode([
                                                "id" => $t["id"],
                                                "full_name" => $t["full_name"],
                                                "has_sig" => !empty($sigUrl),
                                                "sig_url" => $sigUrl
                                            ], JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, "UTF-8") ?>)'
                                            title="<?= $sigUrl ? 'Replace Signature' : 'Upload Signature' ?>">
                                        <i class="bi bi-upload"></i> <?= $sigUrl ? 'Replace' : 'Upload' ?>
                                    </button>
                                    <?php if ($sigUrl): ?>
                                        <form method="POST" action="teacher_signatures.php" class="d-inline" onsubmit="return confirm('Remove the signature for <?= htmlspecialchars($t['full_name']) ?>? The report card will show a blank line.');">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="remove">
                                            <input type="hidden" name="teacher_id" value="<?= $t['id'] ?>">
                                            <button type="submit" class="btn btn-outline-danger" title="Remove Signature">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Upload / Replace Signature Modal -->
<div class="modal fade" id="uploadSignatureModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="teacher_signatures.php" enctype="multipart/form-data" id="sigUploadForm">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="upload">
                <input type="hidden" name="teacher_id" id="sigModalTeacherId" value="">

                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title fw-bold" style="font-family: 'Outfit';">
                        <i class="bi bi-pen-fill me-2"></i><span id="sigModalTitle">Upload Teacher Signature</span>
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Teacher / Staff Member</label>
                        <div class="form-control bg-light fw-bold text-dark" id="sigModalTeacherName">—</div>
                    </div>

                    <!-- Existing Signature Preview if present -->
                    <div id="existingSigContainer" class="mb-3 d-none">
                        <label class="form-label small fw-bold text-muted">Current Saved Signature</label>
                        <div class="p-2 border rounded text-center bg-light">
                            <img id="existingSigImg" src="" alt="Current Signature" style="max-height: 50px; max-width: 100%; object-fit: contain;">
                        </div>
                    </div>

                    <!-- File Input -->
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Choose Signature Image <span class="text-danger">*</span></label>
                        <input type="file" name="signature" id="signatureFileInput" class="form-control" accept="image/png,image/jpeg,image/jpg" required>
                        <div class="form-text small">
                            Recommended: <strong>Transparent PNG</strong> or high-contrast scan. Max file size: <strong>2MB</strong>.
                        </div>
                    </div>

                    <!-- Live Image Preview Box -->
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted">Preview Before Saving</label>
                        <div id="livePreviewContainer" class="p-3 border rounded text-center" style="min-height: 100px; display: flex; align-items: center; justify-content: center; background: repeating-conic-gradient(#f8fafc 0% 25%, #ffffff 0% 50%) 50% / 14px 14px;">
                            <div id="previewPlaceholder" class="text-muted small">
                                <i class="bi bi-image fs-4 d-block opacity-50 mb-1"></i>
                                Select an image to see the live signature preview
                            </div>
                            <img id="livePreviewImg" src="" alt="Signature Preview" class="d-none" style="max-height: 75px; max-width: 90%; object-fit: contain;">
                        </div>
                        <div id="fileInfoBadge" class="small text-muted mt-1 d-none"></div>
                    </div>

                    <div class="alert alert-info small mb-0">
                        <i class="bi bi-shield-check me-1"></i>
                        Once uploaded, this signature is associated with the teacher and will automatically print on report cards for their assigned class.
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary-custom" id="sigSubmitBtn">
                        <i class="bi bi-check2-circle me-1"></i> Save Signature
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openUploadSigModal(data) {
    document.getElementById('sigModalTeacherId').value = data.id;
    document.getElementById('sigModalTeacherName').textContent = data.full_name;
    document.getElementById('sigModalTitle').textContent = data.has_sig ? 'Replace Teacher Signature' : 'Upload Teacher Signature';
    
    // Reset file input & live preview
    const fileInput = document.getElementById('signatureFileInput');
    fileInput.value = '';
    const liveImg = document.getElementById('livePreviewImg');
    const placeholder = document.getElementById('previewPlaceholder');
    const fileInfo = document.getElementById('fileInfoBadge');
    
    liveImg.src = '';
    liveImg.classList.add('d-none');
    placeholder.classList.remove('d-none');
    fileInfo.classList.add('d-none');

    // Existing signature box
    const existingBox = document.getElementById('existingSigContainer');
    const existingImg = document.getElementById('existingSigImg');
    if (data.has_sig && data.sig_url) {
        existingImg.src = data.sig_url;
        existingBox.classList.remove('d-none');
    } else {
        existingBox.classList.add('d-none');
    }

    const modal = new bootstrap.Modal(document.getElementById('uploadSignatureModal'));
    modal.show();
}

// Live client-side FileReader preview
document.getElementById('signatureFileInput').addEventListener('change', function(e) {
    const file = e.target.files[0];
    const liveImg = document.getElementById('livePreviewImg');
    const placeholder = document.getElementById('previewPlaceholder');
    const fileInfo = document.getElementById('fileInfoBadge');

    if (!file) {
        liveImg.classList.add('d-none');
        placeholder.classList.remove('d-none');
        fileInfo.classList.add('d-none');
        return;
    }

    if (file.size > 2 * 1024 * 1024) {
        alert('File size exceeds 2MB limit. Please choose a smaller file.');
        this.value = '';
        return;
    }

    const reader = new FileReader();
    reader.onload = function(evt) {
        liveImg.src = evt.target.result;
        liveImg.classList.remove('d-none');
        placeholder.classList.add('d-none');

        const sizeKb = Math.round(file.size / 1024);
        fileInfo.textContent = 'Selected: ' + file.name + ' (' + sizeKb + ' KB, ' + file.type + ')';
        fileInfo.classList.remove('d-none');
    };
    reader.readAsDataURL(file);
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
