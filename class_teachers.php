<?php
/**
 * KETAN M/A B COMPLEX - Super Admin Class Teacher Management
 * Assign, reassign, and manage designated Class Teachers for each grade/class.
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/session.php';

// Super Admin Only
requireRole('admin');

$db = getDB();
$activeYear = getActiveAcademicYear();
$yearId = $activeYear['id'] ?? 1;

// Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        flash('danger', 'Security validation failed. Please try again.');
        header('Location: class_teachers.php');
        exit;
    }

    $action = $_POST['action'];

    if ($action === 'assign') {
        $classId   = (int)($_POST['class_id'] ?? 0);
        $teacherId = (int)($_POST['teacher_id'] ?? 0);

        if (!$classId || !$teacherId) {
            flash('danger', 'Please select both a Class and a Teacher.');
        } else {
            // Verify Class exists
            $classCheck = $db->prepare("SELECT id, class_name, class_teacher_id FROM classes WHERE id = ?");
            $classCheck->execute([$classId]);
            $targetClass = $classCheck->fetch();

            // Verify Teacher exists and is active
            $teacherCheck = $db->prepare("SELECT id, full_name, role FROM users WHERE id = ? AND role IN ('teacher', 'headteacher') AND status = 'active'");
            $teacherCheck->execute([$teacherId]);
            $targetTeacher = $teacherCheck->fetch();

            if (!$targetClass) {
                flash('danger', 'Selected class does not exist.');
            } elseif (!$targetTeacher) {
                flash('danger', 'Selected teacher does not exist or is inactive.');
            } else {
                try {
                    $now = date('Y-m-d H:i:s');

                    // Update classes table
                    $stmt = $db->prepare("UPDATE classes SET class_teacher_id = ?, class_teacher_assigned_at = ? WHERE id = ?");
                    $stmt->execute([$teacherId, $now, $classId]);

                    // Sync class_teachers table
                    $checkCt = $db->prepare("SELECT id FROM class_teachers WHERE class_id = ? AND academic_year_id = ?");
                    $checkCt->execute([$classId, $yearId]);
                    $existingCtId = $checkCt->fetchColumn();

                    if ($existingCtId) {
                        $ctStmt = $db->prepare("UPDATE class_teachers SET teacher_id = ?, assigned_at = ? WHERE id = ?");
                        $ctStmt->execute([$teacherId, $now, $existingCtId]);
                    } else {
                        $ctStmt = $db->prepare("INSERT INTO class_teachers (class_id, teacher_id, academic_year_id, assigned_at) VALUES (?, ?, ?, ?)");
                        $ctStmt->execute([$classId, $teacherId, $yearId, $now]);
                    }

                    logActivity('ASSIGN_CLASS_TEACHER', "Assigned {$targetTeacher['full_name']} as Class Teacher for {$targetClass['class_name']}", 'classes', $classId);
                    flash('success', "Successfully assigned <strong>{$targetTeacher['full_name']}</strong> as Class Teacher for <strong>{$targetClass['class_name']}</strong>.");
                } catch (Exception $e) {
                    flash('danger', 'Assignment failed: ' . $e->getMessage());
                }
            }
        }
        header('Location: class_teachers.php');
        exit;

    } elseif ($action === 'remove') {
        $classId = (int)($_POST['class_id'] ?? 0);
        try {
            $classCheck = $db->prepare("SELECT id, class_name, class_teacher_id FROM classes WHERE id = ?");
            $classCheck->execute([$classId]);
            $targetClass = $classCheck->fetch();

            if ($targetClass) {
                $stmt = $db->prepare("UPDATE classes SET class_teacher_id = NULL, class_teacher_assigned_at = NULL WHERE id = ?");
                $stmt->execute([$classId]);

                $ctDel = $db->prepare("DELETE FROM class_teachers WHERE class_id = ?");
                $ctDel->execute([$classId]);

                logActivity('REMOVE_CLASS_TEACHER', "Removed Class Teacher from {$targetClass['class_name']}", 'classes', $classId);
                flash('success', "Removed Class Teacher assignment for <strong>{$targetClass['class_name']}</strong>.");
            }
        } catch (Exception $e) {
            flash('danger', 'Failed to remove class teacher: ' . $e->getMessage());
        }
        header('Location: class_teachers.php');
        exit;
    }
}

// Fetch Filter Inputs
$search = trim($_GET['q'] ?? '');
$statusFilter = $_GET['status'] ?? 'all'; // all, assigned, unassigned

// Query all classes with their assigned teacher and teacher signature status
$query = "
    SELECT c.*, 
           u.id as teacher_id, u.full_name as teacher_name, u.email as teacher_email, 
           u.phone as teacher_phone, u.role as teacher_role, u.signature as teacher_signature,
           (SELECT COUNT(*) FROM students s WHERE s.class_id = c.id AND s.status = 'active') as student_count
    FROM classes c
    LEFT JOIN users u ON c.class_teacher_id = u.id
    WHERE c.status = 'active'
";
$params = [];

if ($statusFilter === 'assigned') {
    $query .= " AND c.class_teacher_id IS NOT NULL";
} elseif ($statusFilter === 'unassigned') {
    $query .= " AND c.class_teacher_id IS NULL";
}

if (!empty($search)) {
    $query .= " AND (c.class_name LIKE ? OR u.full_name LIKE ? OR u.email LIKE ?)";
    $like = "%{$search}%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$query .= " ORDER BY c.display_order ASC, c.class_name ASC";
$stmt = $db->prepare($query);
$stmt->execute($params);
$classes = $stmt->fetchAll();

// All active teachers for dropdown
$allTeachers = $db->query("
    SELECT u.id, u.full_name, u.role, u.signature,
           (SELECT class_name FROM classes c WHERE c.class_teacher_id = u.id LIMIT 1) as already_assigned_class
    FROM users u
    WHERE u.role IN ('teacher', 'headteacher') AND u.status = 'active'
    ORDER BY u.full_name ASC
")->fetchAll();

// Metric counts
$totalClassesCount = $db->query("SELECT COUNT(*) FROM classes WHERE status = 'active'")->fetchColumn() ?: 0;
$assignedCount = $db->query("SELECT COUNT(*) FROM classes WHERE status = 'active' AND class_teacher_id IS NOT NULL")->fetchColumn() ?: 0;
$unassignedCount = $totalClassesCount - $assignedCount;
$teachersWithSig = $db->query("SELECT COUNT(*) FROM users WHERE role IN ('teacher', 'headteacher') AND status = 'active' AND signature IS NOT NULL AND signature != ''")->fetchColumn() ?: 0;

$pageTitle = 'Class Teacher Management';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
require_once __DIR__ . '/includes/navbar.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h2 class="h3 fw-bold mb-1" style="font-family: 'Outfit';">Class Teacher Management</h2>
        <div class="text-muted small">Designate primary Class Teachers responsible for student remarks and report card signatures</div>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= url('teacher_signatures.php') ?>" class="btn btn-outline-secondary">
            <i class="bi bi-pen-fill me-1"></i> Teacher Signatures
        </a>
        <button type="button" class="btn btn-primary-custom" data-bs-toggle="modal" data-bs-target="#assignClassTeacherModal">
            <i class="bi bi-person-check-fill me-1"></i> Assign Class Teacher
        </button>
    </div>
</div>

<!-- Summary Metric Cards -->
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card h-100">
            <div>
                <div class="stat-title">Total Classes</div>
                <div class="stat-value text-dark"><?= $totalClassesCount ?></div>
                <div class="stat-meta text-muted">KG through JHS 3</div>
            </div>
            <div class="stat-icon-wrapper stat-icon-primary">
                <i class="bi bi-building"></i>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card h-100">
            <div>
                <div class="stat-title">Assigned Classes</div>
                <div class="stat-value text-success"><?= $assignedCount ?></div>
                <div class="stat-meta text-success"><i class="bi bi-check-circle-fill me-1"></i> With Class Teacher</div>
            </div>
            <div class="stat-icon-wrapper stat-icon-success">
                <i class="bi bi-person-check-fill"></i>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card h-100">
            <div>
                <div class="stat-title">Unassigned Classes</div>
                <div class="stat-value text-warning"><?= $unassignedCount ?></div>
                <div class="stat-meta text-muted">Awaiting designation</div>
            </div>
            <div class="stat-icon-wrapper stat-icon-warning">
                <i class="bi bi-exclamation-circle-fill"></i>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card h-100">
            <div>
                <div class="stat-title">Signatures on File</div>
                <div class="stat-value text-primary"><?= $teachersWithSig ?></div>
                <div class="stat-meta">
                    <a href="<?= url('teacher_signatures.php') ?>" class="text-decoration-none">Manage signatures &rarr;</a>
                </div>
            </div>
            <div class="stat-icon-wrapper stat-icon-info">
                <i class="bi bi-pen-fill"></i>
            </div>
        </div>
    </div>
</div>

<!-- Search & Filter Controls -->
<div class="card-custom mb-4 p-3">
    <form method="GET" action="class_teachers.php" class="row g-2 align-items-center">
        <div class="col-md-6">
            <div class="input-group">
                <span class="input-group-text bg-white"><i class="bi bi-search text-muted"></i></span>
                <input type="text" name="q" class="form-control" placeholder="Search class name, teacher name, or email..." value="<?= htmlspecialchars($search) ?>">
            </div>
        </div>
        <div class="col-md-4">
            <select name="status" class="form-select" onchange="this.form.submit()">
                <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All Classes (<?= $totalClassesCount ?>)</option>
                <option value="assigned" <?= $statusFilter === 'assigned' ? 'selected' : '' ?>>Assigned Classes Only (<?= $assignedCount ?>)</option>
                <option value="unassigned" <?= $statusFilter === 'unassigned' ? 'selected' : '' ?>>Unassigned Classes Only (<?= $unassignedCount ?>)</option>
            </select>
        </div>
        <div class="col-md-2 d-flex gap-1">
            <button type="submit" class="btn btn-primary w-100"><i class="bi bi-funnel"></i> Filter</button>
            <a href="class_teachers.php" class="btn btn-outline-secondary" title="Reset Filters"><i class="bi bi-arrow-counterclockwise"></i></a>
        </div>
    </form>
</div>

<!-- Class Teachers Table -->
<div class="card-custom">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <span><i class="bi bi-person-lines-fill text-primary me-2"></i>Class Teacher Allocations (<?= count($classes) ?>)</span>
        <span class="small text-muted">Report cards automatically pull the assigned teacher's signature</span>
    </div>
    <div class="table-responsive">
        <table class="table table-custom align-middle mb-0">
            <thead>
                <tr>
                    <th style="width: 20%;">Class</th>
                    <th style="width: 25%;">Assigned Class Teacher</th>
                    <th style="width: 20%;">Teacher Contact</th>
                    <th style="width: 15%;">Signature Status</th>
                    <th style="width: 10%;">Enrolled</th>
                    <th class="text-end" style="width: 10%;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($classes)): ?>
                    <tr>
                        <td colspan="6" class="text-center py-5 text-muted">
                            <i class="bi bi-person-x fs-1 opacity-50 d-block mb-2"></i>
                            <h6>No classes matching your criteria</h6>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($classes as $c): 
                        $hasTeacher = !empty($c['teacher_id']);
                        $hasSig = $hasTeacher && !empty($c['teacher_signature']) && getTeacherSignatureUrl($c['teacher_signature']) !== null;
                    ?>
                        <tr>
                            <td>
                                <div class="fw-bold fs-6 text-dark"><?= htmlspecialchars($c['class_name']) ?></div>
                                <span class="badge bg-light text-muted border"><?= htmlspecialchars($c['class_code'] ?: 'Code: —') ?></span>
                            </td>
                            <td>
                                <?php if ($hasTeacher): ?>
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="rounded-circle bg-primary-subtle text-primary d-flex align-items-center justify-content-center fw-bold" style="width: 36px; height: 36px; font-size: 0.85rem;">
                                            <?= strtoupper(substr($c['teacher_name'], 0, 1)) ?>
                                        </div>
                                        <div>
                                            <div class="fw-bold text-dark"><?= htmlspecialchars($c['teacher_name']) ?></div>
                                            <div class="small text-muted"><?= ucfirst($c['teacher_role']) ?></div>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <span class="badge bg-warning-subtle text-warning border border-warning-subtle px-2 py-1">
                                        <i class="bi bi-exclamation-triangle me-1"></i> Not Assigned
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($hasTeacher): ?>
                                    <div class="small text-muted"><i class="bi bi-envelope me-1"></i><?= htmlspecialchars($c['teacher_email']) ?></div>
                                    <?php if ($c['teacher_phone']): ?>
                                        <div class="small text-muted"><i class="bi bi-telephone me-1"></i><?= htmlspecialchars($c['teacher_phone']) ?></div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-muted small">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($hasTeacher): ?>
                                    <?php if ($hasSig): ?>
                                        <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1" title="Signature will print automatically on report cards">
                                            <i class="bi bi-check-circle-fill me-1"></i> Signature Active
                                        </span>
                                    <?php else: ?>
                                        <a href="<?= url('teacher_signatures.php?teacher_id=' . $c['teacher_id']) ?>" class="badge bg-warning-subtle text-warning border border-warning-subtle text-decoration-none px-2 py-1" title="Click to upload signature for this teacher">
                                            <i class="bi bi-upload me-1"></i> Missing (Upload)
                                        </a>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-muted small">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="<?= url('students.php?class_id=' . $c['id']) ?>" class="badge bg-light text-dark border text-decoration-none">
                                    <i class="bi bi-mortarboard me-1"></i> <?= $c['student_count'] ?> Students
                                </a>
                            </td>
                            <td class="text-end">
                                <div class="btn-group btn-group-sm">
                                    <button type="button" class="btn btn-outline-primary" 
                                            onclick='openAssignModal(<?= htmlspecialchars(json_encode([
                                                "class_id" => $c["id"],
                                                "class_name" => $c["class_name"],
                                                "teacher_id" => $c["teacher_id"] ?? ""
                                            ], JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, "UTF-8") ?>)'
                                            title="<?= $hasTeacher ? 'Reassign / Change Class Teacher' : 'Assign Class Teacher' ?>">
                                        <i class="bi bi-pencil-square"></i> <?= $hasTeacher ? 'Change' : 'Assign' ?>
                                    </button>
                                    <?php if ($hasTeacher): ?>
                                        <form method="POST" action="class_teachers.php" class="d-inline" onsubmit="return confirm('Remove Class Teacher assignment for <?= htmlspecialchars($c['class_name']) ?>?');">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="remove">
                                            <input type="hidden" name="class_id" value="<?= $c['id'] ?>">
                                            <button type="submit" class="btn btn-outline-danger" title="Remove Assignment">
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

<!-- Assign Class Teacher Modal -->
<div class="modal fade" id="assignClassTeacherModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="class_teachers.php">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="assign">

                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title fw-bold" style="font-family: 'Outfit';">
                        <i class="bi bi-person-check-fill me-2"></i>Assign Class Teacher
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Select Class <span class="text-danger">*</span></label>
                        <select name="class_id" id="modalClassSelect" class="form-select" required>
                            <option value="">-- Choose Class --</option>
                            <?php foreach ($classes as $cls): ?>
                                <option value="<?= $cls['id'] ?>">
                                    <?= htmlspecialchars($cls['class_name']) ?>
                                    <?= !empty($cls['teacher_name']) ? ' (Currently: ' . htmlspecialchars($cls['teacher_name']) . ')' : ' (Unassigned)' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-bold">Select Teacher to Assign <span class="text-danger">*</span></label>
                        <select name="teacher_id" id="modalTeacherSelect" class="form-select" required>
                            <option value="">-- Choose Teacher --</option>
                            <?php foreach ($allTeachers as $t): ?>
                                <option value="<?= $t['id'] ?>" data-assigned="<?= htmlspecialchars($t['already_assigned_class'] ?? '') ?>">
                                    <?= htmlspecialchars($t['full_name']) ?> (<?= ucfirst($t['role']) ?>)
                                    <?= !empty($t['already_assigned_class']) ? ' — Currently in ' . htmlspecialchars($t['already_assigned_class']) : '' ?>
                                    <?= !empty($t['signature']) ? ' [Signature Uploaded]' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div id="teacherWarningBox" class="alert alert-warning small mt-2 d-none">
                            <i class="bi bi-exclamation-triangle-fill me-1"></i>
                            <span id="teacherWarningText"></span>
                        </div>
                    </div>

                    <div class="bg-light p-3 rounded small text-muted">
                        <i class="bi bi-info-circle text-primary me-1"></i>
                        The assigned teacher will be designated as the official Class Teacher for this class. Their uploaded signature will automatically appear on all terminal report cards generated for students in this class.
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary-custom">
                        <i class="bi bi-save me-1"></i> Save Assignment
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openAssignModal(data) {
    const classSelect = document.getElementById('modalClassSelect');
    const teacherSelect = document.getElementById('modalTeacherSelect');
    
    if (data.class_id) {
        classSelect.value = data.class_id;
    }
    if (data.teacher_id) {
        teacherSelect.value = data.teacher_id;
    } else {
        teacherSelect.value = '';
    }
    checkTeacherWarning();
    
    const modal = new bootstrap.Modal(document.getElementById('assignClassTeacherModal'));
    modal.show();
}

function checkTeacherWarning() {
    const teacherSelect = document.getElementById('modalTeacherSelect');
    const warningBox = document.getElementById('teacherWarningBox');
    const warningText = document.getElementById('teacherWarningText');
    const selectedOption = teacherSelect.options[teacherSelect.selectedIndex];
    
    if (selectedOption && selectedOption.dataset.assigned) {
        warningText.textContent = 'Note: This teacher is already the assigned Class Teacher for ' + selectedOption.dataset.assigned + '. Assigning them here will make them the Class Teacher for this class as well.';
        warningBox.classList.remove('d-none');
    } else {
        warningBox.classList.add('d-none');
    }
}

document.getElementById('modalTeacherSelect').addEventListener('change', checkTeacherWarning);
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
