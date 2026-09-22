<?php
/**
 * KETAN M/A B COMPLEX - 360 Degree Student Performance Profile
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/session.php';

requireLogin();

$db = getDB();
$studentId = (int)($_GET['id'] ?? 0);

if (!$studentId) {
    flash('danger', 'Student not specified.');
    header('Location: students.php');
    exit;
}

$activeYear = getActiveAcademicYear();
$activeTerm = getActiveTerm();
$yearId = isset($_GET['year_id']) ? (int)$_GET['year_id'] : ($activeYear['id'] ?? 1);
$termId = isset($_GET['term_id']) ? (int)$_GET['term_id'] : ($activeTerm['id'] ?? 1);

// Fetch student details
$stmt = $db->prepare("
    SELECT s.*, c.class_name, COALESCE(y.year_name, ?) as year_name
    FROM students s
    JOIN classes c ON s.class_id = c.id
    LEFT JOIN academic_years y ON s.academic_year_id = y.id
    WHERE s.id = ?
");
$stmt->execute([$activeYear['year_name'] ?? '2025/2026', $studentId]);
$student = $stmt->fetch();

if (!$student) {
    flash('danger', 'Student not found.');
    header('Location: students.php');
    exit;
}

// Handle Student Deletion from Profile (Admin only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_student') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        flash('danger', 'Security validation failed.');
        header("Location: student_profile.php?id={$studentId}");
        exit;
    }
    if (!isAdmin()) {
        flash('danger', 'Only administrators have permission to delete student records.');
        header("Location: student_profile.php?id={$studentId}");
        exit;
    }

    try {
        $db->beginTransaction();

        if (!empty($student['photo'])) {
            $photoPath = UPLOAD_DIR . DIRECTORY_SEPARATOR . $student['photo'];
            if (file_exists($photoPath)) {
                @unlink($photoPath);
            }
        }

        $db->prepare("DELETE FROM marks WHERE student_id = ?")->execute([$studentId]);
        $db->prepare("DELETE FROM student_term_reports WHERE student_id = ?")->execute([$studentId]);
        $db->prepare("DELETE FROM students WHERE id = ?")->execute([$studentId]);

        $db->commit();

        logActivity('STUDENT_DELETION', "Deleted student: {$student['full_name']} ({$student['student_id']})", 'students', $studentId);
        flash('success', "Student record for '{$student['full_name']}' was permanently deleted.");
        header('Location: students.php');
        exit;
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        flash('danger', 'Error deleting student: ' . $e->getMessage());
        header("Location: student_profile.php?id={$studentId}");
        exit;
    }
}

// Handle Terminal Remarks & Attendance Form Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_remarks') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        flash('danger', 'Security token invalid.');
    } else {
        $attendancePresent = (int)($_POST['attendance_present'] ?? 0);
        $attendanceTotal   = (int)($_POST['attendance_total'] ?? 60);
        $conduct           = trim($_POST['conduct'] ?? 'Good');
        $attitude          = trim($_POST['attitude'] ?? 'Hardworking');
        $interest          = trim($_POST['interest'] ?? 'Reading & Sports');
        $teacherRemark     = trim($_POST['class_teacher_remark'] ?? '');
        $headRemark        = trim($_POST['head_teacher_remark'] ?? '');

        try {
            $checkRep = $db->prepare("SELECT id FROM student_term_reports WHERE student_id = ? AND academic_year_id = ? AND term_id = ?");
            $checkRep->execute([$studentId, $yearId, $termId]);
            $repId = $checkRep->fetchColumn();

            if ($repId) {
                $repStmt = $db->prepare("
                    UPDATE student_term_reports 
                    SET class_id = ?, attendance_present = ?, attendance_total = ?, conduct = ?, attitude = ?, interest = ?, class_teacher_remark = ?, head_teacher_remark = ?
                    WHERE id = ?
                ");
                $repStmt->execute([
                    $student['class_id'], $attendancePresent, $attendanceTotal, $conduct, $attitude, $interest,
                    $teacherRemark, $headRemark, $repId
                ]);
            } else {
                $repStmt = $db->prepare("
                    INSERT INTO student_term_reports 
                    (student_id, class_id, academic_year_id, term_id, attendance_present, attendance_total, conduct, attitude, interest, class_teacher_remark, head_teacher_remark)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $repStmt->execute([
                    $studentId, $student['class_id'], $yearId, $termId,
                    $attendancePresent, $attendanceTotal, $conduct, $attitude, $interest,
                    $teacherRemark, $headRemark
                ]);
            }

            logActivity('UPDATE_REMARKS', "Updated terminal remarks for {$student['full_name']}", 'students', $studentId);
            flash('success', 'Terminal report comments and attendance updated successfully.');
        } catch (Exception $e) {
            flash('danger', 'Failed to update comments: ' . $e->getMessage());
        }
        header("Location: student_profile.php?id={$studentId}&year_id={$yearId}&term_id={$termId}");
        exit;
    }
}

// Fetch Terminal Performance Marks
$marksStmt = $db->prepare("
    SELECT m.*, sub.subject_name, sub.subject_code, sub.category, u.full_name as teacher_name
    FROM marks m
    JOIN subjects sub ON m.subject_id = sub.id
    LEFT JOIN users u ON m.entered_by = u.id
    WHERE m.student_id = ? AND m.academic_year_id = ? AND m.term_id = ?
    ORDER BY sub.display_order ASC, sub.subject_name ASC
");
$marksStmt->execute([$studentId, $yearId, $termId]);
$marks = $marksStmt->fetchAll();

// Fetch Term Report Remarks
$termReportStmt = $db->prepare("
    SELECT * FROM student_term_reports 
    WHERE student_id = ? AND academic_year_id = ? AND term_id = ?
");
$termReportStmt->execute([$studentId, $yearId, $termId]);
$termReport = $termReportStmt->fetch() ?: [];

// Compute Performance Aggregates
$totalMarks = 0;
$subjectCount = count($marks);
$passes = 0;
$failures = 0;

foreach ($marks as $m) {
    $totalMarks += (float)$m['total_score'];
    if ((float)$m['total_score'] >= 50) {
        $passes++;
    } else {
        $failures++;
    }
}

$averageMark = $subjectCount > 0 ? round($totalMarks / $subjectCount, 1) : 0;
$overallGradeInfo = calculateGradeAndRemark($averageMark);

// Calculate Class Position (Standard competition ranking among peers in same class)
$classRankStmt = $db->prepare("
    SELECT s.id, AVG(m.total_score) as class_avg
    FROM students s
    JOIN marks m ON s.id = m.student_id
    WHERE s.class_id = ? AND m.academic_year_id = ? AND m.term_id = ?
    GROUP BY s.id
    ORDER BY class_avg DESC
");
$classRankStmt->execute([$student['class_id'], $yearId, $termId]);
$peerAverages = $classRankStmt->fetchAll();

$classPosition = '—';
$classSize = count($peerAverages);
$rank = 1;
foreach ($peerAverages as $peer) {
    if ((int)$peer['id'] === $studentId) {
        $classPosition = $rank;
        break;
    }
    $rank++;
}

// Available terms for switcher
$allTerms = $db->query("SELECT t.*, y.year_name FROM terms t JOIN academic_years y ON t.academic_year_id = y.id ORDER BY y.id DESC, t.id ASC")->fetchAll();

$photoSrc = $student['photo'] ? url('uploads/students/' . $student['photo']) : asset('images/default-avatar.svg');

$pageTitle = $student['full_name'] . ' - Performance Profile';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
require_once __DIR__ . '/includes/navbar.php';
?>

<!-- Header Banner -->
<div class="card-custom mb-4 overflow-hidden">
    <div class="p-4" style="background: linear-gradient(135deg, #0f2942 0%, #1e40af 100%); color: #ffffff;">
        <div class="row align-items-center g-3">
            <div class="col-auto">
                <img src="<?= $photoSrc ?>" alt="Student Photo" class="rounded-circle border border-3 border-warning shadow" style="width: 95px; height: 95px; object-fit: cover; background: #ffffff;">
            </div>
            <div class="col">
                <div class="badge bg-warning text-dark font-monospace mb-1"><?= htmlspecialchars($student['student_id']) ?></div>
                <h2 class="h3 fw-bold mb-1 text-white" style="font-family: 'Outfit';"><?= htmlspecialchars($student['full_name']) ?></h2>
                <div class="d-flex flex-wrap gap-3 small text-light opacity-90">
                    <span><i class="bi bi-building me-1"></i> Class: <strong><?= htmlspecialchars($student['class_name']) ?></strong></span>
                    <span><i class="bi bi-gender-ambiguous me-1"></i> Gender: <?= htmlspecialchars($student['gender']) ?></span>
                    <span><i class="bi bi-calendar-event me-1"></i> DOB: <?= htmlspecialchars($student['date_of_birth']) ?></span>
                    <span><i class="bi bi-telephone me-1"></i> Parent: <?= htmlspecialchars($student['parent_name'] ?? 'N/A') ?> (<?= htmlspecialchars($student['parent_phone'] ?? '—') ?>)</span>
                </div>
            </div>
            <div class="col-md-auto text-md-end d-flex flex-wrap gap-2 justify-content-end align-items-center">
                <a href="<?= url("report_card.php?student_id={$student['id']}&year_id={$yearId}&term_id={$termId}") ?>" target="_blank" class="btn btn-warning fw-bold text-dark px-3 py-2 shadow-sm">
                    <i class="bi bi-printer-fill me-1"></i> Generate Report Card
                </a>
                <?php if (isAdmin()): ?>
                    <button type="button" class="btn btn-danger btn-sm py-2 px-3 shadow-sm" data-bs-toggle="modal" data-bs-target="#deleteProfileStudentModal">
                        <i class="bi bi-trash-fill me-1"></i> Delete Student
                    </button>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Term Switcher -->
    <div class="bg-light p-3 border-top d-flex align-items-center justify-content-between flex-wrap gap-2">
        <div class="d-flex align-items-center gap-2">
            <span class="small fw-bold text-muted text-uppercase">Academic Term:</span>
            <form method="GET" action="student_profile.php" class="d-flex align-items-center gap-2">
                <input type="hidden" name="id" value="<?= $studentId ?>">
                <select name="term_id" class="form-select form-select-sm" onchange="this.form.submit()">
                    <?php foreach ($allTerms as $t): ?>
                        <option value="<?= $t['id'] ?>" <?= $t['id'] == $termId ? 'selected' : '' ?>>
                            <?= htmlspecialchars($t['year_name']) ?> — <?= htmlspecialchars($t['term_name']) ?> <?= $t['is_active'] ? '(Active)' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>
        <div class="small text-muted">
            Status: <span class="status-badge status-<?= $student['status'] ?>"><?= ucfirst($student['status']) ?></span>
        </div>
    </div>
</div>

<!-- Performance Summary KPI Cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div>
                <div class="stat-title">Overall Average</div>
                <div class="stat-value text-primary"><?= $averageMark ?>%</div>
                <div class="stat-meta text-muted">Grade: <strong><?= $overallGradeInfo['grade'] ?></strong> (<?= $overallGradeInfo['remark'] ?>)</div>
            </div>
            <div class="stat-icon-wrapper stat-icon-primary">
                <i class="bi bi-calculator"></i>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div>
                <div class="stat-title">Class Position</div>
                <div class="stat-value text-warning">
                    <?= $classPosition !== '—' ? $classPosition . '<span class="fs-6 text-muted"> / ' . $classSize . '</span>' : '—' ?>
                </div>
                <div class="stat-meta text-muted">In <?= htmlspecialchars($student['class_name']) ?></div>
            </div>
            <div class="stat-icon-wrapper stat-icon-warning">
                <i class="bi bi-trophy-fill"></i>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div>
                <div class="stat-title">Total Marks</div>
                <div class="stat-value text-dark"><?= number_format($totalMarks, 1) ?></div>
                <div class="stat-meta text-muted">Across <?= $subjectCount ?> subjects</div>
            </div>
            <div class="stat-icon-wrapper stat-icon-info">
                <i class="bi bi-file-earmark-bar-graph"></i>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div>
                <div class="stat-title">Passes / Fails</div>
                <div class="stat-value">
                    <span class="text-success"><?= $passes ?></span> <span class="text-muted fs-6">/</span> <span class="text-danger"><?= $failures ?></span>
                </div>
                <div class="stat-meta text-muted">Pass Mark: 50%</div>
            </div>
            <div class="stat-icon-wrapper stat-icon-success">
                <i class="bi bi-check2-all"></i>
            </div>
        </div>
    </div>
</div>

<!-- Academic Performance Table -->
<div class="card-custom mb-4">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <span><i class="bi bi-table text-primary me-2"></i>Subject Performance Breakdown</span>
        <span class="small text-muted">Max SBA: 40 | Max Exam: 60 | Total: 100</span>
    </div>
    <div class="table-responsive">
        <table class="table table-custom mb-0">
            <thead>
                <tr>
                    <th>Subject</th>
                    <th>Category</th>
                    <th class="text-center">SBA (40%)</th>
                    <th class="text-center">Exam (60%)</th>
                    <th class="text-center">Total (100%)</th>
                    <th class="text-center">Grade</th>
                    <th>Remarks</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($marks)): ?>
                    <tr>
                        <td colspan="8" class="text-center py-4 text-muted">
                            <i class="bi bi-journal-x fs-2 d-block mb-1"></i>
                            No assessment marks entered for this student in this term.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($marks as $m): ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars($m['subject_name']) ?></strong>
                                <span class="text-muted small ms-1">(<?= htmlspecialchars($m['subject_code']) ?>)</span>
                            </td>
                            <td><span class="badge bg-light text-dark border"><?= ucfirst($m['category']) ?></span></td>
                            <td class="text-center font-monospace fw-bold"><?= number_format($m['sba_score'], 1) ?></td>
                            <td class="text-center font-monospace fw-bold"><?= number_format($m['exam_score'], 1) ?></td>
                            <td class="text-center font-monospace fw-bold fs-6 text-primary"><?= number_format($m['total_score'], 1) ?></td>
                            <td class="text-center">
                                <span class="grade-badge grade-<?= $m['grade'] ?>"><?= $m['grade'] ?></span>
                            </td>
                            <td><?= htmlspecialchars($m['remark'] ?? '—') ?></td>
                            <td>
                                <span class="status-badge status-<?= $m['status'] ?>">
                                    <?= ucfirst($m['status']) ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Terminal Remarks & Conduct Section -->
<div class="card-custom mb-4">
    <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div class="d-flex align-items-center gap-2">
            <i class="bi bi-chat-quote-fill text-primary"></i>
            <span class="fw-bold">Terminal Report Remarks & Attendance</span>
            <span class="badge bg-primary-subtle text-primary border border-primary-subtle font-monospace ms-2">
                Avg: <?= $averageMark ?>% • Grade: <?= $overallGradeInfo['grade'] ?> (<?= $overallGradeInfo['remark'] ?>)
            </span>
        </div>
        <button type="button" class="btn btn-outline-primary btn-sm" onclick="autoFillRemarks()">
            <i class="bi bi-magic me-1"></i> Auto-Generate Standard Remarks
        </button>
    </div>
    <div class="card-body">
        <form method="POST" action="student_profile.php?id=<?= $studentId ?>&year_id=<?= $yearId ?>&term_id=<?= $termId ?>">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="update_remarks">

            <div class="row g-3 mb-3">
                <div class="col-md-3">
                    <label class="form-label small fw-bold">Attendance (Days Present)</label>
                    <input type="number" name="attendance_present" class="form-control" value="<?= htmlspecialchars($termReport['attendance_present'] ?? 55) ?>" min="0" max="100">
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold">Total School Days</label>
                    <input type="number" name="attendance_total" class="form-control" value="<?= htmlspecialchars($termReport['attendance_total'] ?? 60) ?>" min="1" max="100">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold">Conduct</label>
                    <input type="text" name="conduct" id="conduct_input" class="form-control" value="<?= htmlspecialchars($termReport['conduct'] ?? 'Satisfactory and respectful') ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold">Attitude</label>
                    <input type="text" name="attitude" id="attitude_input" class="form-control" value="<?= htmlspecialchars($termReport['attitude'] ?? 'Hardworking and focused') ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold">Interests</label>
                    <input type="text" name="interest" id="interest_input" class="form-control" value="<?= htmlspecialchars($termReport['interest'] ?? 'Reading, Sports and Art') ?>">
                </div>
                <div class="col-md-6">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <label class="form-label small fw-bold mb-0">Class Teacher's Remark</label>
                        <span class="text-muted small" style="font-size: 0.72rem;">Official Class Teacher Comment</span>
                    </div>
                    <textarea name="class_teacher_remark" id="class_teacher_remark" class="form-control" rows="3" placeholder="Enter teacher's assessment of student progress..."><?= htmlspecialchars($termReport['class_teacher_remark'] ?? '') ?></textarea>
                </div>
                <div class="col-md-6">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <label class="form-label small fw-bold mb-0">Head Teacher's Remark</label>
                        <span class="text-muted small" style="font-size: 0.72rem;">Head Teacher Endorsement</span>
                    </div>
                    <textarea name="head_teacher_remark" id="head_teacher_remark" class="form-control" rows="3" placeholder="Enter headteacher's endorsement comment..."><?= htmlspecialchars($termReport['head_teacher_remark'] ?? '') ?></textarea>
                </div>
            </div>

            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <a href="<?= url('report_card.php?student_id=' . $studentId . '&year_id=' . $yearId . '&term_id=' . $termId) ?>" class="btn btn-outline-secondary btn-sm" target="_blank">
                    <i class="bi bi-award me-1"></i> Preview Report Card
                </a>
                <button type="submit" class="btn btn-primary-custom">
                    <i class="bi bi-check-circle me-1"></i> Save Terminal Remarks
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Student Modal -->
<div class="modal fade" id="deleteProfileStudentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="POST" action="student_profile.php?id=<?= $studentId ?>">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete_student">
                
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title font-weight-bold" style="font-family: 'Outfit';">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i> Confirm Student Deletion
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4 text-center">
                    <div class="bg-danger-subtle text-danger rounded-circle d-inline-flex align-items-center justify-content-center mb-3" style="width: 64px; height: 64px; font-size: 30px;">
                        <i class="bi bi-trash"></i>
                    </div>
                    <h5 class="fw-bold mb-2">Permanently Delete Student?</h5>
                    <p class="text-muted mb-0">
                        Are you sure you want to permanently delete <strong><?= htmlspecialchars($student['full_name']) ?></strong> (<code><?= htmlspecialchars($student['student_id']) ?></code>)?
                    </p>
                    <div class="alert alert-warning text-start small mt-3 mb-0">
                        <i class="bi bi-info-circle me-1"></i> All marks, attendance records, terminal reports, and uploaded photos for this student will be completely removed from the system.
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger px-4">
                        <i class="bi bi-trash-fill me-1"></i> Confirm & Delete
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function autoFillRemarks() {
    const suggestedTeacherRemark = "<?= addslashes(getDefaultClassTeacherRemark($averageMark, $overallGradeInfo['grade'])) ?>";
    const suggestedHeadRemark = "<?= addslashes(getDefaultHeadTeacherRemark($averageMark, $overallGradeInfo['grade'])) ?>";
    
    document.getElementById('class_teacher_remark').value = suggestedTeacherRemark;
    document.getElementById('head_teacher_remark').value = suggestedHeadRemark;
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
