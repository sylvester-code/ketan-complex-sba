<?php
/**
 * KETAN M/A B COMPLEX - Teacher Allocation (Class & Subject Assignments)
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/session.php';

requireRole(['admin', 'headteacher']);

$db = getDB();
$activeYear = getActiveAcademicYear();
$yearId = $activeYear['id'] ?? 1;

// Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        flash('danger', 'Security validation failed.');
        header('Location: assignments.php');
        exit;
    }

    $action = $_POST['action'];

    if ($action === 'assign') {
        $teacherId = (int)$_POST['teacher_id'];
        $classId   = (int)$_POST['class_id'];
        $subjectId = (int)$_POST['subject_id'];
        $isClassTeacher = !empty($_POST['is_class_teacher']) ? 1 : 0;

        if (!$teacherId || !$classId || !$subjectId) {
            flash('danger', 'Teacher, Class, and Subject must all be selected.');
        } else {
            try {
                $checkTa = $db->prepare("SELECT id FROM teacher_assignments WHERE teacher_id = ? AND class_id = ? AND subject_id = ? AND academic_year_id = ?");
                $checkTa->execute([$teacherId, $classId, $subjectId, $yearId]);
                $existingTaId = $checkTa->fetchColumn();

                if ($existingTaId) {
                    $stmt = $db->prepare("UPDATE teacher_assignments SET is_class_teacher = ? WHERE id = ?");
                    $stmt->execute([$isClassTeacher, $existingTaId]);
                } else {
                    $stmt = $db->prepare("INSERT INTO teacher_assignments (teacher_id, class_id, subject_id, academic_year_id, is_class_teacher) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$teacherId, $classId, $subjectId, $yearId, $isClassTeacher]);
                }
                logActivity('TEACHER_ASSIGNMENT', "Assigned teacher ID {$teacherId} to class {$classId} / subject {$subjectId}", 'teacher_assignments');
                flash('success', 'Teacher allocation saved successfully.');
            } catch (Exception $e) {
                flash('danger', 'Assignment error: ' . $e->getMessage());
            }
        }
        header('Location: assignments.php');
        exit;

    } elseif ($action === 'delete') {
        $id = (int)$_POST['id'];
        try {
            $stmt = $db->prepare("DELETE FROM teacher_assignments WHERE id = ?");
            $stmt->execute([$id]);
            logActivity('TEACHER_UNASSIGN', "Removed teacher assignment ID {$id}", 'teacher_assignments', $id);
            flash('success', 'Assignment removed successfully.');
        } catch (Exception $e) {
            flash('danger', 'Error removing assignment: ' . $e->getMessage());
        }
        header('Location: assignments.php');
        exit;
    }
}

// Fetch Filters
$teacherFilter = isset($_GET['teacher_id']) && $_GET['teacher_id'] !== '' ? (int)$_GET['teacher_id'] : null;
$classFilter   = isset($_GET['class_id']) && $_GET['class_id'] !== '' ? (int)$_GET['class_id'] : null;

$sql = "
    SELECT ta.*, u.full_name as teacher_name, u.email as teacher_email,
           c.class_name, s.subject_name, s.subject_code, y.year_name
    FROM teacher_assignments ta
    JOIN users u ON ta.teacher_id = u.id
    JOIN classes c ON ta.class_id = c.id
    JOIN subjects s ON ta.subject_id = s.id
    JOIN academic_years y ON ta.academic_year_id = y.id
    WHERE ta.academic_year_id = ?
";
$params = [$yearId];

if ($teacherFilter) {
    $sql .= " AND ta.teacher_id = ?";
    $params[] = $teacherFilter;
}
if ($classFilter) {
    $sql .= " AND ta.class_id = ?";
    $params[] = $classFilter;
}

$sql .= " ORDER BY c.display_order ASC, s.subject_name ASC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$assignments = $stmt->fetchAll();

// Select options
$allTeachers = $db->query("SELECT id, full_name, role FROM users WHERE role IN ('teacher', 'headteacher') AND status = 'active' ORDER BY full_name ASC")->fetchAll();
$allClasses  = $db->query("SELECT id, class_name FROM classes WHERE status = 'active' ORDER BY display_order ASC")->fetchAll();
$allSubjects = $db->query("SELECT id, subject_name, subject_code FROM subjects WHERE status = 'active' ORDER BY display_order ASC")->fetchAll();

$pageTitle = 'Teacher Allocation';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
require_once __DIR__ . '/includes/navbar.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h2 class="h3 fw-bold mb-1" style="font-family: 'Outfit';">Teacher Allocation</h2>
        <div class="text-muted small">Assign subject teachers and class masters for <?= htmlspecialchars($activeYear['year_name'] ?? '') ?></div>
    </div>
    <button type="button" class="btn btn-primary-custom" data-bs-toggle="modal" data-bs-target="#assignModal">
        <i class="bi bi-person-plus-fill me-1"></i> New Allocation
    </button>
</div>

<!-- Filter Bar -->
<div class="card-custom mb-4 p-3">
    <form method="GET" action="assignments.php" class="row g-2 align-items-center">
        <div class="col-md-5">
            <select name="teacher_id" class="form-select">
                <option value="">-- All Teachers --</option>
                <?php foreach ($allTeachers as $t): ?>
                    <option value="<?= $t['id'] ?>" <?= $teacherFilter === (int)$t['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($t['full_name']) ?> (<?= ucfirst($t['role']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-5">
            <select name="class_id" class="form-select">
                <option value="">-- All Classes --</option>
                <?php foreach ($allClasses as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= $classFilter === (int)$c['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($c['class_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2 d-flex gap-1">
            <button type="submit" class="btn btn-primary w-100"><i class="bi bi-funnel"></i> Filter</button>
            <a href="assignments.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-counterclockwise"></i></a>
        </div>
    </form>
</div>

<!-- Assignments Table -->
<div class="card-custom">
    <div class="card-header bg-white">
        <span><i class="bi bi-person-workspace text-primary me-2"></i>Active Allocations (<?= count($assignments) ?>)</span>
    </div>
    <div class="table-responsive">
        <table class="table table-custom mb-0">
            <thead>
                <tr>
                    <th>Teacher</th>
                    <th>Class</th>
                    <th>Subject</th>
                    <th>Class Master</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($assignments)): ?>
                    <tr><td colspan="5" class="text-center py-4 text-muted">No allocations found for this selection.</td></tr>
                <?php else: ?>
                    <?php foreach ($assignments as $a): ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars($a['teacher_name']) ?></strong>
                                <div class="small text-muted"><?= htmlspecialchars($a['teacher_email']) ?></div>
                            </td>
                            <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($a['class_name']) ?></span></td>
                            <td>
                                <strong><?= htmlspecialchars($a['subject_name']) ?></strong>
                                <span class="text-muted small ms-1">(<?= htmlspecialchars($a['subject_code']) ?>)</span>
                            </td>
                            <td>
                                <?php if ($a['is_class_teacher']): ?>
                                    <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i> Class Teacher</span>
                                <?php else: ?>
                                    <span class="text-muted small">Subject Teacher</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <form method="POST" action="assignments.php" class="d-inline" onsubmit="return confirm('Remove this teaching assignment?');">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= $a['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Remove Assignment">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- New Assignment Modal -->
<div class="modal fade" id="assignModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="assignments.php">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="assign">

                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title font-weight-bold" style="font-family: 'Outfit';">Assign Teacher</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Select Teacher <span class="text-danger">*</span></label>
                        <select name="teacher_id" class="form-select" required>
                            <option value="">Choose Teacher</option>
                            <?php foreach ($allTeachers as $t): ?>
                                <option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['full_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Select Class <span class="text-danger">*</span></label>
                        <select name="class_id" class="form-select" required>
                            <option value="">Choose Class</option>
                            <?php foreach ($allClasses as $c): ?>
                                <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['class_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Select Subject <span class="text-danger">*</span></label>
                        <select name="subject_id" class="form-select" required>
                            <option value="">Choose Subject</option>
                            <?php foreach ($allSubjects as $s): ?>
                                <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['subject_name']) ?> (<?= htmlspecialchars($s['subject_code']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-check mt-3">
                        <input class="form-check-input" type="checkbox" name="is_class_teacher" id="isClassTeacherCheck" value="1">
                        <label class="form-check-label fw-bold small" for="isClassTeacherCheck">
                            Designate as Class Master / Form Teacher for this class
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary-custom">Save Assignment</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
