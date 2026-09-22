<?php
/**
 * KETAN M/A B COMPLEX - Marks / SBA Entry Module
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/session.php';

requireLogin();

$db = getDB();
$role = currentUserRole();
$userId = currentUserId();
$activeYear = getActiveAcademicYear();
$activeTerm = getActiveTerm();
$yearId = $activeYear['id'] ?? 1;
$termId = $activeTerm['id'] ?? 1;

$maxSba = (float)getSetting('max_sba_score', '50');
$maxExam = (float)getSetting('max_exam_score', '50');
$gradingScale = getGradingScale();

// Fetch authorized classes and subjects
if ($role === 'teacher') {
    $assignedStmt = $db->prepare("
        SELECT DISTINCT c.id as class_id, c.class_name, s.id as subject_id, s.subject_name, s.subject_code
        FROM teacher_assignments ta
        JOIN classes c ON ta.class_id = c.id
        JOIN subjects s ON ta.subject_id = s.id
        WHERE ta.teacher_id = ? AND ta.academic_year_id = ?
        ORDER BY c.display_order ASC, s.subject_name ASC
    ");
    $assignedStmt->execute([$userId, $yearId]);
    $teacherAssignments = $assignedStmt->fetchAll();

    $availableClassIds = array_unique(array_column($teacherAssignments, 'class_id'));
    $availableSubjectIds = array_unique(array_column($teacherAssignments, 'subject_id'));
} else {
    // Admin & Headteacher have access to all classes and subjects
    $allClasses = $db->query("SELECT id, class_name FROM classes WHERE status = 'active' ORDER BY display_order ASC")->fetchAll();
    $allSubjects = $db->query("SELECT id, subject_name, subject_code FROM subjects WHERE status = 'active' ORDER BY display_order ASC")->fetchAll();
}

// Current selection
$selectedClassId = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;
$selectedSubjectId = isset($_GET['subject_id']) ? (int)$_GET['subject_id'] : 0;

// Default to first assigned if teacher and not selected
if ($role === 'teacher' && !$selectedClassId && !empty($teacherAssignments)) {
    $selectedClassId = (int)$teacherAssignments[0]['class_id'];
    $selectedSubjectId = (int)$teacherAssignments[0]['subject_id'];
}

// Teacher Authorization Guard
if ($role === 'teacher' && $selectedClassId && $selectedSubjectId) {
    $isAuthorized = false;
    foreach ($teacherAssignments as $ta) {
        if ((int)$ta['class_id'] === $selectedClassId && (int)$ta['subject_id'] === $selectedSubjectId) {
            $isAuthorized = true;
            break;
        }
    }
    if (!$isAuthorized) {
        flash('danger', 'You are not authorized to enter marks for this class/subject.');
        header('Location: marks.php');
        exit;
    }
}

// Check Submission Status & Lock Rules
$submission = null;
$isLocked = false;
$hasMarks = false;

if ($selectedClassId && $selectedSubjectId) {
    $subStmt = $db->prepare("
        SELECT * FROM assessment_submissions 
        WHERE class_id = ? AND subject_id = ? AND academic_year_id = ? AND term_id = ?
    ");
    $subStmt->execute([$selectedClassId, $selectedSubjectId, $yearId, $termId]);
    $submission = $subStmt->fetch();

    $cntStmt = $db->prepare("SELECT COUNT(*) FROM marks WHERE class_id = ? AND subject_id = ? AND academic_year_id = ? AND term_id = ?");
    $cntStmt->execute([$selectedClassId, $selectedSubjectId, $yearId, $termId]);
    $hasMarks = ((int)$cntStmt->fetchColumn() > 0);

    // Rule: Once marks are uploaded or approved, ONLY Super Admin (isAdmin()) can make changes.
    if (($hasMarks || ($submission && in_array($submission['status'], ['approved', 'submitted']))) && !isAdmin()) {
        $isLocked = true;
    }
}

// Handle Form Submission (Automatic Approval & Admin Override)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        flash('danger', 'Security verification failed.');
        header("Location: marks.php?class_id={$selectedClassId}&subject_id={$selectedSubjectId}");
        exit;
    }

    if ($_POST['action'] === 'clear_marks') {
        if (!isAdmin()) {
            flash('danger', 'Only the Super Administrator has permission to delete or reset uploaded assessment marks.');
            header("Location: marks.php?class_id={$selectedClassId}&subject_id={$selectedSubjectId}");
            exit;
        }

        try {
            $db->beginTransaction();

            $delMarks = $db->prepare("DELETE FROM marks WHERE class_id = ? AND subject_id = ? AND academic_year_id = ? AND term_id = ?");
            $delMarks->execute([$selectedClassId, $selectedSubjectId, $yearId, $termId]);

            $delSub = $db->prepare("DELETE FROM assessment_submissions WHERE class_id = ? AND subject_id = ? AND academic_year_id = ? AND term_id = ?");
            $delSub->execute([$selectedClassId, $selectedSubjectId, $yearId, $termId]);

            $db->commit();

            logActivity('CLEAR_MARKS', "Cleared assessment marks for Class {$selectedClassId}, Subject {$selectedSubjectId}", 'marks');
            flash('success', "Assessment marks for this subject and class were successfully deleted/reset.");
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            flash('danger', 'Error resetting marks: ' . $e->getMessage());
        }

        header("Location: marks.php?class_id={$selectedClassId}&subject_id={$selectedSubjectId}");
        exit;
    }

    // Only Super Admin can modify existing uploaded marks
    if ($hasMarks && !isAdmin()) {
        flash('danger', 'Only the Super Administrator has permission to edit or modify uploaded assessment marks.');
        header("Location: marks.php?class_id={$selectedClassId}&subject_id={$selectedSubjectId}");
        exit;
    }

    $scores = $_POST['marks'] ?? [];
    $newStatus = 'approved'; // Automatically approved upon upload

    try {
        $db->beginTransaction();

        // 1. Create or update assessment submission record (Status: approved)
        $subId = null;
        $now = date('Y-m-d H:i:s');
        if ($submission) {
            $subId = $submission['id'];
            $submittedAt = !empty($submission['submitted_at']) ? $submission['submitted_at'] : $now;
            $updateSub = $db->prepare("
                UPDATE assessment_submissions 
                SET status = 'approved', 
                    submitted_at = ?,
                    reviewed_by = ?,
                    reviewed_at = ?,
                    review_comments = 'Auto-approved on upload'
                WHERE id = ?
            ");
            $updateSub->execute([$submittedAt, $userId, $now, $subId]);
        } else {
            $insertSub = $db->prepare("
                INSERT INTO assessment_submissions (teacher_id, class_id, subject_id, academic_year_id, term_id, status, submitted_at, reviewed_by, reviewed_at, review_comments)
                VALUES (?, ?, ?, ?, ?, 'approved', ?, ?, ?, 'Auto-approved on upload')
            ");
            $insertSub->execute([$userId, $selectedClassId, $selectedSubjectId, $yearId, $termId, $now, $userId, $now]);
            $subId = $db->lastInsertId();
        }

        // 2. Save or update individual student marks (Status: approved)
        $checkExistingMark = $db->prepare("SELECT id FROM marks WHERE student_id = ? AND subject_id = ? AND academic_year_id = ? AND term_id = ?");
        $updateMarkStmt = $db->prepare("
            UPDATE marks 
            SET submission_id = ?, class_id = ?, sba_score = ?, exam_score = ?, total_score = ?, grade = ?, remark = ?, status = 'approved', entered_by = ?
            WHERE id = ?
        ");
        $insertMarkStmt = $db->prepare("
            INSERT INTO marks (submission_id, student_id, subject_id, class_id, academic_year_id, term_id, sba_score, exam_score, total_score, grade, remark, status, entered_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'approved', ?)
        ");

        $savedCount = 0;
        $firstSavedStudentId = null;
        foreach ($scores as $studentId => $m) {
            $sbaRaw = trim((string)($m['sba'] ?? ''));
            $examRaw = trim((string)($m['exam'] ?? ''));

            // If neither score is provided, skip to avoid creating blank 0 records
            if ($sbaRaw === '' && $examRaw === '') {
                continue;
            }

            $sba = min($maxSba, max(0, (float)$sbaRaw));
            $exam = min($maxExam, max(0, (float)$examRaw));
            $total = round($sba + $exam, 2);
            $gradeInfo = calculateGradeAndRemark($total, $gradingScale);

            $checkExistingMark->execute([(int)$studentId, $selectedSubjectId, $yearId, $termId]);
            $existingId = $checkExistingMark->fetchColumn();

            if ($existingId) {
                $updateMarkStmt->execute([
                    $subId, $selectedClassId, $sba, $exam, $total, $gradeInfo['grade'], $gradeInfo['remark'], $userId, $existingId
                ]);
            } else {
                $insertMarkStmt->execute([
                    $subId, (int)$studentId, $selectedSubjectId, $selectedClassId, $yearId, $termId,
                    $sba, $exam, $total, $gradeInfo['grade'], $gradeInfo['remark'], $userId
                ]);
            }
            $savedCount++;
            if (!$firstSavedStudentId) {
                $firstSavedStudentId = (int)$studentId;
            }

            // Ensure student term report record exists for this student
            $checkRep = $db->prepare("SELECT id FROM student_term_reports WHERE student_id = ? AND academic_year_id = ? AND term_id = ?");
            $checkRep->execute([(int)$studentId, $yearId, $termId]);
            if (!$checkRep->fetchColumn()) {
                $initRep = $db->prepare("
                    INSERT INTO student_term_reports 
                    (student_id, class_id, academic_year_id, term_id, attendance_present, attendance_total, conduct, attitude, interest)
                    VALUES (?, ?, ?, ?, 58, 60, 'Satisfactory and respectful', 'Hardworking and focused', 'Reading, Sports and Art')
                ");
                $initRep->execute([(int)$studentId, $selectedClassId, $yearId, $termId]);
            }
        }

        $db->commit();

        logActivity('MARKS_UPLOAD', "Assessment marks uploaded and auto-approved for Class {$selectedClassId}, Subject {$selectedSubjectId} ({$savedCount} students)", 'marks');
        
        if (isAdmin() && $hasMarks) {
            flash('success', "Marks updated and approved successfully under Super Administrator override! All scores are live on student report cards.");
        } else {
            flash('success', "Marks submitted and automatically approved for {$savedCount} students! These marks now show live on student report cards.");
        }
    } catch (Exception $e) {
        $db->rollBack();
        flash('danger', 'Error saving marks: ' . $e->getMessage());
    }

    header("Location: marks.php?class_id={$selectedClassId}&subject_id={$selectedSubjectId}");
    exit;
}

// Fetch Students in selected Class
$students = [];
$existingMarks = [];
if ($selectedClassId && $selectedSubjectId) {
    $stuStmt = $db->prepare("SELECT id, student_id, full_name FROM students WHERE class_id = ? AND status = 'active' ORDER BY full_name ASC");
    $stuStmt->execute([$selectedClassId]);
    $students = $stuStmt->fetchAll();

    // Fetch existing marks
    $mStmt = $db->prepare("SELECT * FROM marks WHERE class_id = ? AND subject_id = ? AND academic_year_id = ? AND term_id = ?");
    $mStmt->execute([$selectedClassId, $selectedSubjectId, $yearId, $termId]);
    while ($row = $mStmt->fetch()) {
        $existingMarks[$row['student_id']] = $row;
    }
}

$pageTitle = 'Mark Entry (SBA)';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
require_once __DIR__ . '/includes/navbar.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h2 class="h3 fw-bold mb-1" style="font-family: 'Outfit';">Assessment & SBA Entry</h2>
        <div class="text-muted small">
            Academic Term: <strong><?= htmlspecialchars($activeYear['year_name'] ?? '') ?> — <?= htmlspecialchars($activeTerm['term_name'] ?? '') ?></strong>
            | SBA Ratio: <strong><?= $maxSba ?>% SBA + <?= $maxExam ?>% Exam</strong>
        </div>
    </div>
    <div class="d-flex gap-2">
        <?php if ($selectedClassId && $selectedSubjectId): ?>
            <a href="<?= url("bulk_upload.php?class_id={$selectedClassId}&subject_id={$selectedSubjectId}") ?>" class="btn btn-outline-secondary">
                <i class="bi bi-file-earmark-spreadsheet me-1"></i> Bulk Excel/CSV Upload
            </a>
        <?php endif; ?>
    </div>
</div>

<!-- Class & Subject Selection Card -->
<div class="card-custom mb-4 p-3 bg-white">
    <form method="GET" action="marks.php" class="row g-3 align-items-end">
        <div class="col-md-5">
            <label class="form-label small fw-bold text-muted text-uppercase">Class</label>
            <select name="class_id" class="form-select" required onchange="this.form.submit()">
                <option value="">-- Choose Class --</option>
                <?php if ($role === 'teacher'): ?>
                    <?php
                    $seenClasses = [];
                    foreach ($teacherAssignments as $ta):
                        if (in_array($ta['class_id'], $seenClasses)) continue;
                        $seenClasses[] = $ta['class_id'];
                    ?>
                        <option value="<?= $ta['class_id'] ?>" <?= $selectedClassId === (int)$ta['class_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($ta['class_name']) ?>
                        </option>
                    <?php endforeach; ?>
                <?php else: ?>
                    <?php foreach ($allClasses as $c): ?>
                        <option value="<?= $c['id'] ?>" <?= $selectedClassId === (int)$c['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($c['class_name']) ?>
                        </option>
                    <?php endforeach; ?>
                <?php endif; ?>
            </select>
        </div>
        <div class="col-md-5">
            <label class="form-label small fw-bold text-muted text-uppercase">Subject</label>
            <select name="subject_id" class="form-select" required onchange="this.form.submit()">
                <option value="">-- Choose Subject --</option>
                <?php if ($role === 'teacher'): ?>
                    <?php foreach ($teacherAssignments as $ta): 
                        if ($selectedClassId && (int)$ta['class_id'] !== $selectedClassId) continue;
                    ?>
                        <option value="<?= $ta['subject_id'] ?>" <?= $selectedSubjectId === (int)$ta['subject_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($ta['subject_name']) ?> (<?= htmlspecialchars($ta['subject_code']) ?>)
                        </option>
                    <?php endforeach; ?>
                <?php else: ?>
                    <?php foreach ($allSubjects as $s): ?>
                        <option value="<?= $s['id'] ?>" <?= $selectedSubjectId === (int)$s['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($s['subject_name']) ?> (<?= htmlspecialchars($s['subject_code']) ?>)
                        </option>
                    <?php endforeach; ?>
                <?php endif; ?>
            </select>
        </div>
        <div class="col-md-2">
            <button type="submit" class="btn btn-primary w-100"><i class="bi bi-arrow-repeat me-1"></i> Load Sheet</button>
        </div>
    </form>
</div>

<?php if (!$selectedClassId || !$selectedSubjectId): ?>
    <div class="alert alert-info py-4 text-center">
        <i class="bi bi-hand-index-thumb fs-2 d-block mb-2"></i>
        <h5>Please select a Class and Subject above to begin mark entry.</h5>
        <p class="text-muted small mb-0">You can enter marks inline or download the pre-filled template for bulk upload.</p>
    </div>
<?php elseif (empty($students)): ?>
    <div class="alert alert-warning py-4 text-center">
        <i class="bi bi-people fs-2 d-block mb-2"></i>
        <h5>No active students are currently registered in this class.</h5>
        <a href="<?= url('students.php') ?>" class="btn btn-primary btn-sm mt-2">Register Students</a>
    </div>
<?php else: ?>

    <!-- Status Banner -->
    <?php if ($hasMarks || $submission): ?>
        <?php if (isAdmin()): ?>
            <div class="d-flex align-items-center justify-content-between p-3 rounded-3 mb-3 border bg-primary-subtle border-primary">
                <div class="d-flex align-items-center gap-2">
                    <i class="bi bi-shield-lock-fill text-primary fs-4"></i>
                    <div>
                        <strong class="text-primary text-uppercase">Status: Approved (Super Administrator Override Active)</strong>
                        <div class="small text-muted">Marks are auto-approved for report cards. As Super Admin, you have exclusive privilege to update, recalculate, or overwrite these marks.</div>
                    </div>
                </div>
                <span class="badge bg-primary text-white font-monospace px-3 py-2"><i class="bi bi-unlock-fill me-1"></i> Admin Edit Mode</span>
            </div>
        <?php else: ?>
            <div class="d-flex align-items-center justify-content-between p-3 rounded-3 mb-3 border bg-success-subtle border-success">
                <div class="d-flex align-items-center gap-2">
                    <i class="bi bi-check-circle-fill text-success fs-4"></i>
                    <div>
                        <strong class="text-success text-uppercase">Status: Approved & Finalized</strong>
                        <div class="small text-muted">Marks have been uploaded and automatically approved. Only the Super Administrator has permission to make changes or updates.</div>
                    </div>
                </div>
                <span class="badge bg-success text-white font-monospace px-3 py-2"><i class="bi bi-lock-fill me-1"></i> Locked for Teachers</span>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <!-- Marks Entry Table Form -->
    <form method="POST" action="marks.php?class_id=<?= $selectedClassId ?>&subject_id=<?= $selectedSubjectId ?>">
        <?= csrfField() ?>

        <div class="card-custom mb-4">
            <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span>
                    <i class="bi bi-pencil-fill text-primary me-2"></i>
                    Student Marks Sheet (<?= count($students) ?> Students)
                </span>
                <div class="d-flex align-items-center gap-2">
                    <span class="badge bg-light text-dark border">Navigation: Arrow Up / Down, Enter</span>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-custom mb-0" id="marksEntryTable">
                    <thead>
                        <tr>
                            <th style="width: 50px;">#</th>
                            <th style="width: 140px;">Student ID</th>
                            <th>Student Name</th>
                            <th class="text-center" style="width: 120px;">SBA Score (Max <?= $maxSba ?>)</th>
                            <th class="text-center" style="width: 120px;">Exam Score (Max <?= $maxExam ?>)</th>
                            <th class="text-center" style="width: 110px;">Total (100)</th>
                            <th class="text-center" style="width: 80px;">Grade</th>
                            <th>Teacher Remark</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($students as $index => $st): 
                            $m = $existingMarks[$st['id']] ?? null;
                            $sbaVal = $m ? (float)$m['sba_score'] : '';
                            $examVal = $m ? (float)$m['exam_score'] : '';
                            $totalVal = $m ? (float)$m['total_score'] : '';
                            $gradeVal = $m ? $m['grade'] : '';
                            $remarkVal = $m ? $m['remark'] : '';
                        ?>
                            <tr data-student-id="<?= $st['id'] ?>">
                                <td class="text-muted fw-bold"><?= $index + 1 ?></td>
                                <td>
                                    <span class="badge bg-light text-dark border font-monospace"><?= htmlspecialchars($st['student_id']) ?></span>
                                </td>
                                <td>
                                    <strong><?= htmlspecialchars($st['full_name']) ?></strong>
                                </td>
                                <td class="text-center">
                                    <input type="number" step="0.5" min="0" max="<?= $maxSba ?>" 
                                           name="marks[<?= $st['id'] ?>][sba]" 
                                           class="form-control form-control-sm mark-input sba-input" 
                                           value="<?= $sbaVal !== '' ? $sbaVal : '' ?>"
                                           placeholder="—"
                                           <?= $isLocked ? 'readonly disabled' : '' ?>>
                                </td>
                                <td class="text-center">
                                    <input type="number" step="0.5" min="0" max="<?= $maxExam ?>" 
                                           name="marks[<?= $st['id'] ?>][exam]" 
                                           class="form-control form-control-sm mark-input exam-input" 
                                           value="<?= $examVal !== '' ? $examVal : '' ?>"
                                           placeholder="—"
                                           <?= $isLocked ? 'readonly disabled' : '' ?>>
                                </td>
                                <td class="text-center">
                                    <span class="total-score-display total-display"><?= $totalVal !== '' ? number_format($totalVal, 1) : '—' ?></span>
                                    <input type="hidden" name="marks[<?= $st['id'] ?>][total]" class="total-hidden" value="<?= $totalVal ?>">
                                </td>
                                <td class="text-center">
                                    <span class="grade-display">
                                        <?php if ($gradeVal): ?>
                                             <span class="grade-badge grade-<?= $gradeVal ?>"><?= $gradeVal ?></span>
                                        <?php else: ?>
                                            —
                                        <?php endif; ?>
                                    </span>
                                    <input type="hidden" name="marks[<?= $st['id'] ?>][grade]" class="grade-hidden" value="<?= $gradeVal ?>">
                                </td>
                                <td>
                                    <span class="remark-display small text-muted"><?= htmlspecialchars($remarkVal ?: '—') ?></span>
                                    <input type="hidden" name="marks[<?= $st['id'] ?>][remark]" class="remark-hidden" value="<?= htmlspecialchars($remarkVal) ?>">
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php if (!$isLocked || isAdmin()): ?>
            <div class="d-flex justify-content-between align-items-center p-3 bg-white rounded-3 border shadow-sm flex-wrap gap-2">
                <div class="text-muted small">
                    <i class="bi bi-shield-check me-1 text-success"></i> Totals and grades calculate automatically in real-time. Marks are automatically approved upon saving.
                </div>
                <div class="d-flex gap-2">
                    <?php if (isAdmin() && $hasMarks): ?>
                        <button type="button" class="btn btn-outline-danger btn-sm px-3" data-bs-toggle="modal" data-bs-target="#resetMarksModal">
                            <i class="bi bi-trash3-fill me-1"></i> Reset / Clear Marks
                        </button>
                    <?php endif; ?>
                    <button type="submit" name="action" value="save_and_approve" class="btn btn-primary-custom px-4">
                        <i class="bi bi-check2-all me-1"></i> <?= (isAdmin() && $hasMarks) ? 'Update Marks (Super Admin Override)' : 'Upload & Auto-Approve Marks' ?>
                    </button>
                </div>
            </div>
        <?php else: ?>
            <div class="alert alert-secondary text-center py-3">
                <i class="bi bi-shield-lock-fill me-2 text-primary"></i> These assessment marks have been uploaded and automatically approved. <strong>Only the Super Administrator</strong> has permission to make changes or updates to uploaded marks.
            </div>
        <?php endif; ?>
    </form>

    <?php if (isAdmin() && $hasMarks): ?>
        <!-- Reset Marks Modal -->
        <div class="modal fade" id="resetMarksModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content border-0 shadow">
                    <form method="POST" action="marks.php?class_id=<?= $selectedClassId ?>&subject_id=<?= $selectedSubjectId ?>">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="clear_marks">
                        
                        <div class="modal-header bg-danger text-white">
                            <h5 class="modal-title font-weight-bold" style="font-family: 'Outfit';">
                                <i class="bi bi-exclamation-triangle-fill me-2"></i> Confirm Marks Deletion
                            </h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body p-4 text-center">
                            <div class="bg-danger-subtle text-danger rounded-circle d-inline-flex align-items-center justify-content-center mb-3" style="width: 64px; height: 64px; font-size: 30px;">
                                <i class="bi bi-trash3"></i>
                            </div>
                            <h5 class="fw-bold mb-2">Clear All Uploaded Marks?</h5>
                            <p class="text-muted mb-0">
                                Are you sure you want to permanently delete and reset all marks for this class and subject in <strong><?= htmlspecialchars($activeYear['year_name'] ?? '') ?> — <?= htmlspecialchars($activeTerm['term_name'] ?? '') ?></strong>?
                            </p>
                        </div>
                        <div class="modal-footer bg-light">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-danger px-4">
                                <i class="bi bi-trash-fill me-1"></i> Confirm & Reset
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Interactive Spreadsheet Engine Script -->
    <script src="<?= asset('js/marks.js') ?>"></script>
    <script>
    document.addEventListener('DOMContentLoaded', () => {
        new MarksSheetEngine('marksEntryTable', {
            maxSba: <?= $maxSba ?>,
            maxExam: <?= $maxExam ?>,
            gradingScale: <?= json_encode(array_map(fn($g) => [
                'min' => (float)$g['min_score'],
                'grade' => $g['grade'],
                'remark' => $g['remark']
            ], $gradingScale)) ?>
        });
    });
    </script>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
