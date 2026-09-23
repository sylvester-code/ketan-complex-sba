<?php
/**
 * KETAN M/A B COMPLEX - Bulk Mark Upload (Excel / CSV)
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

// Authorized Classes & Subjects
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
} else {
    $allClasses = $db->query("SELECT id, class_name FROM classes WHERE status = 'active' ORDER BY display_order ASC")->fetchAll();
    $allSubjects = $db->query("SELECT id, subject_name, subject_code FROM subjects WHERE status = 'active' ORDER BY display_order ASC")->fetchAll();
}

$selectedClassId = isset($_REQUEST['class_id']) ? (int)$_REQUEST['class_id'] : 0;
$selectedSubjectId = isset($_REQUEST['subject_id']) ? (int)$_REQUEST['subject_id'] : 0;

// Default if teacher
if ($role === 'teacher' && !$selectedClassId && !empty($teacherAssignments)) {
    $selectedClassId = (int)$teacherAssignments[0]['class_id'];
    $selectedSubjectId = (int)$teacherAssignments[0]['subject_id'];
}

// ----------------------------------------------------
// Action 1: Download CSV Template
// ----------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'download_template') {
    if (!$selectedClassId || !$selectedSubjectId) {
        die('Class and Subject must be selected to download a pre-filled template.');
    }

    $cStmt = $db->prepare("SELECT class_name FROM classes WHERE id = ?");
    $cStmt->execute([$selectedClassId]);
    $className = $cStmt->fetchColumn() ?: 'Class';

    $sStmt = $db->prepare("SELECT subject_name FROM subjects WHERE id = ?");
    $sStmt->execute([$selectedSubjectId]);
    $subjectName = $sStmt->fetchColumn() ?: 'Subject';

    $stuStmt = $db->prepare("SELECT id, full_name FROM students WHERE class_id = ? AND status = 'active' ORDER BY full_name ASC");
    $stuStmt->execute([$selectedClassId]);
    $students = $stuStmt->fetchAll();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="Marks_Template_' . preg_replace('/[^A-Za-z0-9]/', '_', $className . '_' . $subjectName) . '.csv"');

    $output = fopen('php://output', 'w');
    // Header Row
    fputcsv($output, ['#', 'Student Name', 'Subject', 'SBA Score (Max ' . $maxSba . ')', 'Examination Score (Max ' . $maxExam . ')']);

    $idx = 1;
    foreach ($students as $st) {
        fputcsv($output, [$idx++, $st['full_name'], $subjectName, '', '']);
    }

    fclose($output);
    exit;
}

// ----------------------------------------------------
// Action 2: Process Upload File & Preview Validation
// ----------------------------------------------------
$uploadErrors = [];
$previewData = [];
$hasExistingMarks = false;

// Check if marks already exist for selected class & subject
if ($selectedClassId && $selectedSubjectId) {
    $existingCheck = $db->prepare("SELECT COUNT(*) FROM marks WHERE class_id = ? AND subject_id = ? AND academic_year_id = ? AND term_id = ?");
    $existingCheck->execute([$selectedClassId, $selectedSubjectId, $yearId, $termId]);
    if ((int)$existingCheck->fetchColumn() > 0) {
        $hasExistingMarks = true;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_marks') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        flash('danger', 'Security verification token invalid.');
        header("Location: bulk_upload.php?class_id={$selectedClassId}&subject_id={$selectedSubjectId}");
        exit;
    }

    // Only Super Admin can overwrite existing marks
    if ($hasExistingMarks && !isAdmin()) {
        flash('danger', 'Marks for this subject have already been uploaded and approved. Only the Super Administrator has permission to overwrite or update uploaded marks.');
        header("Location: bulk_upload.php?class_id={$selectedClassId}&subject_id={$selectedSubjectId}");
        exit;
    }

    if (empty($_FILES['csv_file']['tmp_name']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $uploadErrors[] = 'Please select a valid CSV or Excel file to upload.';
    } else {
        $file = $_FILES['csv_file']['tmp_name'];
        $handle = fopen($file, 'r');

        if ($handle !== false) {
            // Read header
            $header = fgetcsv($handle);

            // Fetch students registered in this class
            $stuStmt = $db->prepare("SELECT id, full_name FROM students WHERE class_id = ? AND status = 'active'");
            $stuStmt->execute([$selectedClassId]);
            $validStudents = [];
            while ($row = $stuStmt->fetch()) {
                $normName = mb_strtolower(trim(preg_replace('/\s+/', ' ', $row['full_name'])));
                $validStudents[$normName] = $row;
            }

            $seenNames = [];
            $rowNum = 1;

            while (($row = fgetcsv($handle)) !== false) {
                $rowNum++;
                if (empty(array_filter($row))) continue; // skip blank rows

                $num = trim($row[0] ?? '');
                $name = trim($row[1] ?? '');
                $sbaRaw = trim($row[3] ?? '');
                $examRaw = trim($row[4] ?? '');

                $rowErrors = [];
                $normName = mb_strtolower(trim(preg_replace('/\s+/', ' ', $name)));

                if (empty($name)) {
                    $rowErrors[] = "Row {$rowNum}: Missing Student Name";
                } elseif (!isset($validStudents[$normName])) {
                    $rowErrors[] = "Row {$rowNum}: Student '{$name}' does not belong to this class or does not exist";
                }

                if (in_array($normName, $seenNames)) {
                    $rowErrors[] = "Row {$rowNum}: Duplicate Student '{$name}' detected in uploaded file";
                }
                $seenNames[] = $normName;

                if ($sbaRaw === '' || !is_numeric($sbaRaw)) {
                    $rowErrors[] = "Row {$rowNum}: SBA score must be a number";
                } else {
                    $sba = (float)$sbaRaw;
                    if ($sba < 0 || $sba > $maxSba) {
                        $rowErrors[] = "Row {$rowNum}: SBA score {$sba} is out of bounds (0 - {$maxSba})";
                    }
                }

                if ($examRaw === '' || !is_numeric($examRaw)) {
                    $rowErrors[] = "Row {$rowNum}: Exam score must be a number";
                } else {
                    $exam = (float)$examRaw;
                    if ($exam < 0 || $exam > $maxExam) {
                        $rowErrors[] = "Row {$rowNum}: Exam score {$exam} is out of bounds (0 - {$maxExam})";
                    }
                }

                if (!empty($rowErrors)) {
                    $uploadErrors = array_merge($uploadErrors, $rowErrors);
                } else {
                    $student = $validStudents[$normName];
                    $total = (float)$sbaRaw + (float)$examRaw;
                    $grade = calculateGradeAndRemark($total, $gradingScale);

                    $previewData[] = [
                        'student_db_id' => $student['id'],
                        'name'          => $student['full_name'],
                        'sba'           => (float)$sbaRaw,
                        'exam'          => (float)$examRaw,
                        'total'         => $total,
                        'grade'         => $grade['grade'],
                        'remark'        => $grade['remark']
                    ];
                }
            }
            fclose($handle);
        }
    }
}

// ----------------------------------------------------
// Action 3: Confirm and Commit Uploaded Marks (Auto-Approved)
// ----------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'confirm_import') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        flash('danger', 'Security validation failed.');
        header("Location: bulk_upload.php?class_id={$selectedClassId}&subject_id={$selectedSubjectId}");
        exit;
    }

    if ($hasExistingMarks && !isAdmin()) {
        flash('danger', 'Only the Super Administrator has permission to overwrite or update uploaded marks.');
        header("Location: bulk_upload.php?class_id={$selectedClassId}&subject_id={$selectedSubjectId}");
        exit;
    }

    $importRows = json_decode($_POST['import_data'] ?? '[]', true);

    if (empty($importRows)) {
        flash('danger', 'No valid marks data found to import.');
    } else {
        try {
            $db->beginTransaction();

            // 1. Get or Create Submission (Status: approved)
            $now = date('Y-m-d H:i:s');
            $subStmt = $db->prepare("SELECT id, submitted_at FROM assessment_submissions WHERE class_id = ? AND subject_id = ? AND academic_year_id = ? AND term_id = ?");
            $subStmt->execute([$selectedClassId, $selectedSubjectId, $yearId, $termId]);
            $existingSub = $subStmt->fetch();

            if ($existingSub) {
                $subId = $existingSub['id'];
                $submittedAt = !empty($existingSub['submitted_at']) ? $existingSub['submitted_at'] : $now;
                $updSub = $db->prepare("
                    UPDATE assessment_submissions 
                    SET status = 'approved',
                        submitted_at = ?,
                        reviewed_by = ?,
                        reviewed_at = ?,
                        review_comments = 'Auto-approved on bulk import'
                    WHERE id = ?
                ");
                $updSub->execute([$submittedAt, $userId, $now, $subId]);
            } else {
                $newSub = $db->prepare("
                    INSERT INTO assessment_submissions (teacher_id, class_id, subject_id, academic_year_id, term_id, status, submitted_at, reviewed_by, reviewed_at, review_comments)
                    VALUES (?, ?, ?, ?, ?, 'approved', ?, ?, ?, 'Auto-approved on bulk import')
                ");
                $newSub->execute([$userId, $selectedClassId, $selectedSubjectId, $yearId, $termId, $now, $userId, $now]);
                $subId = $db->lastInsertId();
            }

            // 2. Insert or Update Marks (Status: approved)
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

            $imported = 0;
            foreach ($importRows as $row) {
                $checkExistingMark->execute([(int)$row['student_db_id'], $selectedSubjectId, $yearId, $termId]);
                $existingId = $checkExistingMark->fetchColumn();

                if ($existingId) {
                    $updateMarkStmt->execute([
                        $subId, $selectedClassId, $row['sba'], $row['exam'], $row['total'], $row['grade'], $row['remark'], $userId, $existingId
                    ]);
                } else {
                    $insertMarkStmt->execute([
                        $subId, (int)$row['student_db_id'], $selectedSubjectId, $selectedClassId, $yearId, $termId,
                        $row['sba'], $row['exam'], $row['total'], $row['grade'], $row['remark'], $userId
                    ]);
                }
                $imported++;

                // Ensure student term report record exists for this student
                $checkRep = $db->prepare("SELECT id FROM student_term_reports WHERE student_id = ? AND academic_year_id = ? AND term_id = ?");
                $checkRep->execute([(int)$row['student_db_id'], $yearId, $termId]);
                if (!$checkRep->fetchColumn()) {
                    $initRep = $db->prepare("
                        INSERT INTO student_term_reports 
                        (student_id, class_id, academic_year_id, term_id, attendance_present, attendance_total, conduct, attitude, interest)
                        VALUES (?, ?, ?, ?, 58, 60, 'Satisfactory and respectful', 'Hardworking and focused', 'Reading, Sports and Art')
                    ");
                    $initRep->execute([(int)$row['student_db_id'], $selectedClassId, $yearId, $termId]);
                }
            }

            $db->commit();
            logActivity('BULK_MARK_UPLOAD', "Imported and auto-approved marks for {$imported} students via CSV in Class {$selectedClassId}, Subject {$selectedSubjectId}", 'marks');
            flash('success', "Successfully imported and automatically approved marks for {$imported} students! These marks are now finalized and live on report cards.");
            header("Location: marks.php?class_id={$selectedClassId}&subject_id={$selectedSubjectId}");
            exit;
        } catch (Exception $e) {
            $db->rollBack();
            flash('danger', 'Import failed: ' . $e->getMessage());
        }
    }
}

$pageTitle = 'Bulk Mark Upload';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
require_once __DIR__ . '/includes/navbar.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h2 class="h3 fw-bold mb-1" style="font-family: 'Outfit';">Bulk Mark Upload (Excel / CSV)</h2>
        <div class="text-muted small">Upload terminal assessment marks in bulk using downloadable spreadsheets</div>
    </div>
    <?php if ($selectedClassId && $selectedSubjectId): ?>
        <a href="<?= url("bulk_upload.php?action=download_template&class_id={$selectedClassId}&subject_id={$selectedSubjectId}") ?>" class="btn btn-primary-custom">
            <i class="bi bi-download me-1"></i> Download Pre-filled Template
        </a>
    <?php endif; ?>
</div>

<!-- Class & Subject Filter Bar -->
<div class="card-custom mb-4 p-3 bg-white">
    <form method="GET" action="bulk_upload.php" class="row g-3 align-items-end">
        <div class="col-md-5">
            <label class="form-label small fw-bold text-muted text-uppercase">Target Class</label>
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
            <label class="form-label small fw-bold text-muted text-uppercase">Target Subject</label>
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
            <button type="submit" class="btn btn-primary w-100">Select</button>
        </div>
    </form>
</div>

<?php if (!$selectedClassId || !$selectedSubjectId): ?>
    <div class="alert alert-info py-4 text-center">
        <i class="bi bi-file-earmark-arrow-up fs-2 d-block mb-2"></i>
        <h5>Please select a Class and Subject above to initiate bulk mark upload.</h5>
    </div>
<?php else: ?>

    <!-- Upload Instructions & Step Cards -->
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="p-3 bg-white rounded-3 border h-100">
                <div class="badge bg-primary text-white mb-2">Step 1</div>
                <h6 class="fw-bold">Download Class Template</h6>
                <p class="small text-muted mb-2">Get the pre-filled template containing active students and Student IDs for this class.</p>
                <a href="<?= url("bulk_upload.php?action=download_template&class_id={$selectedClassId}&subject_id={$selectedSubjectId}") ?>" class="btn btn-sm btn-outline-primary">
                    <i class="bi bi-download me-1"></i> Download CSV Template
                </a>
            </div>
        </div>
        <div class="col-md-4">
            <div class="p-3 bg-white rounded-3 border h-100">
                <div class="badge bg-secondary text-white mb-2">Step 2</div>
                <h6 class="fw-bold">Fill SBA & Exam Marks</h6>
                <p class="small text-muted mb-0">Enter scores into Excel or Google Sheets. SBA maximum is <strong><?= $maxSba ?></strong>, Exam maximum is <strong><?= $maxExam ?></strong>.</p>
            </div>
        </div>
        <div class="col-md-4">
            <div class="p-3 bg-white rounded-3 border h-100">
                <div class="badge bg-success text-white mb-2">Step 3</div>
                <h6 class="fw-bold">Upload & Validate</h6>
                <p class="small text-muted mb-0">Upload the CSV file below. The system checks Student IDs, score boundaries, and displays a preview.</p>
            </div>
        </div>
    </div>

    <?php if ($hasExistingMarks): ?>
        <?php if (isAdmin()): ?>
            <div class="alert alert-primary d-flex align-items-center justify-content-between p-3 mb-4">
                <div class="d-flex align-items-center gap-2">
                    <i class="bi bi-shield-lock-fill fs-3 text-primary"></i>
                    <div>
                        <strong class="text-primary text-uppercase">Super Administrator Re-Upload Privilege</strong>
                        <div class="small text-muted">Marks already exist for this subject. As Super Admin, uploading a new file will overwrite and auto-approve the replacement marks.</div>
                    </div>
                </div>
                <span class="badge bg-primary text-white">Admin Override</span>
            </div>
        <?php else: ?>
            <div class="alert alert-warning d-flex align-items-center justify-content-between p-3 mb-4">
                <div class="d-flex align-items-center gap-2">
                    <i class="bi bi-lock-fill fs-3 text-warning"></i>
                    <div>
                        <strong class="text-dark text-uppercase">Marks Uploaded & Approved</strong>
                        <div class="small text-muted">Assessment marks for this class and subject have already been uploaded and finalized. <strong>Only the Super Administrator</strong> has permission to overwrite or update uploaded marks.</div>
                    </div>
                </div>
                <span class="badge bg-warning text-dark">Locked for Teachers</span>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <!-- Upload Form -->
    <div class="card-custom mb-4">
        <div class="card-header bg-white">
            <span><i class="bi bi-upload text-primary me-2"></i>Upload Filled CSV File</span>
        </div>
        <div class="card-body">
            <?php if (!$hasExistingMarks || isAdmin()): ?>
                <form method="POST" action="bulk_upload.php" enctype="multipart/form-data">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="upload_marks">
                    <input type="hidden" name="class_id" value="<?= $selectedClassId ?>">
                    <input type="hidden" name="subject_id" value="<?= $selectedSubjectId ?>">

                    <div class="mb-3">
                        <label class="form-label small fw-bold">Select CSV File</label>
                        <input type="file" name="csv_file" class="form-control" accept=".csv, text/csv, application/vnd.ms-excel" required>
                        <div class="form-text small">Accepted formats: .csv (Comma Separated Values) exported from Excel or Sheets.</div>
                    </div>

                    <button type="submit" class="btn btn-primary-custom">
                        <i class="bi bi-search me-1"></i> Upload and Validate Marks
                    </button>
                </form>
            <?php else: ?>
                <div class="text-center py-3 text-muted">
                    <i class="bi bi-shield-lock fs-1 d-block mb-2 text-warning"></i>
                    <h6>Upload Disabled</h6>
                    <p class="small mb-0">Marks have already been submitted and approved. Please contact the Super Administrator if modifications are required.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Upload Validation Errors Display -->
    <?php if (!empty($uploadErrors)): ?>
        <div class="alert alert-danger mb-4">
            <h6 class="fw-bold"><i class="bi bi-exclamation-triangle-fill me-2"></i>Validation Errors Detected in Uploaded File:</h6>
            <ul class="mb-0 small">
                <?php foreach ($uploadErrors as $err): ?>
                    <li><?= htmlspecialchars($err) ?></li>
                <?php endforeach; ?>
            </ul>
            <div class="mt-2 small text-muted">Please correct these errors in your spreadsheet and upload again.</div>
        </div>
    <?php endif; ?>

    <!-- Validation Preview Table -->
    <?php if (!empty($previewData) && empty($uploadErrors)): ?>
        <div class="card-custom mb-4 border-success">
            <div class="card-header bg-success text-white d-flex justify-content-between align-items-center">
                <span><i class="bi bi-check-circle-fill me-2"></i>File Validated Successfully — Preview (<?= count($previewData) ?> Students)</span>
            </div>

            <?php if ($hasExistingMarks): ?>
                <div class="alert alert-warning m-3 mb-0 d-flex align-items-center gap-2">
                    <i class="bi bi-exclamation-triangle-fill fs-4 text-warning"></i>
                    <div>
                        <strong>Notice:</strong> Marks already exist for some students in this assessment. Importing will update those records with the new values.
                    </div>
                </div>
            <?php endif; ?>

            <div class="table-responsive">
                <table class="table table-custom mb-0">
                    <thead>
                        <tr>
                            <th style="width: 50px;">#</th>
                            <th>Student Name</th>
                            <th class="text-center">SBA Score</th>
                            <th class="text-center">Exam Score</th>
                            <th class="text-center">Calculated Total</th>
                            <th class="text-center">Calculated Grade</th>
                            <th>Remark</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($previewData as $idx => $p): ?>
                            <tr>
                                <td class="text-muted fw-bold"><?= $idx + 1 ?></td>
                                <td><strong><?= htmlspecialchars($p['name']) ?></strong></td>
                                <td class="text-center fw-bold"><?= number_format($p['sba'], 1) ?></td>
                                <td class="text-center fw-bold"><?= number_format($p['exam'], 1) ?></td>
                                <td class="text-center fw-bold text-primary"><?= number_format($p['total'], 1) ?></td>
                                <td class="text-center"><span class="grade-badge grade-<?= $p['grade'] ?>"><?= $p['grade'] ?></span></td>
                                <td><span class="small text-muted"><?= htmlspecialchars($p['remark']) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="card-footer bg-white p-3 text-end">
                <form method="POST" action="bulk_upload.php">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="confirm_import">
                    <input type="hidden" name="class_id" value="<?= $selectedClassId ?>">
                    <input type="hidden" name="subject_id" value="<?= $selectedSubjectId ?>">
                    <input type="hidden" name="import_data" value="<?= htmlspecialchars(json_encode($previewData)) ?>">

                    <a href="<?= url("bulk_upload.php?class_id={$selectedClassId}&subject_id={$selectedSubjectId}") ?>" class="btn btn-secondary me-2">Cancel</a>
                    <button type="submit" class="btn btn-success px-4" onclick="return confirm('Proceed with importing these marks?');">
                        <i class="bi bi-check2-all me-1"></i> Confirm & Import Valid Marks
                    </button>
                </form>
            </div>
        </div>
    <?php endif; ?>

<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
