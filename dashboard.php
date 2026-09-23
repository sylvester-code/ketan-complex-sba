<?php
/**
 * KETAN M/A B COMPLEX - Role-Aware Interactive Dashboard
 * School-Based Assessment & Performance Management System
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

$pageTitle = 'Dashboard';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
require_once __DIR__ . '/includes/navbar.php';

// Fetch role-specific statistics
if (in_array($role, ['admin', 'headteacher'])) {
    // Admin / Headteacher aggregates
    $totalStudents = $db->query("SELECT COUNT(*) FROM students WHERE status = 'active'")->fetchColumn() ?: 0;
    $totalTeachers = $db->query("SELECT COUNT(*) FROM users WHERE role = 'teacher' AND status = 'active'")->fetchColumn() ?: 0;
    $totalClasses  = $db->query("SELECT COUNT(*) FROM classes WHERE status = 'active'")->fetchColumn() ?: 0;
    $totalSubjects = $db->query("SELECT COUNT(*) FROM subjects WHERE status = 'active'")->fetchColumn() ?: 0;

    $pendingApprovals = $db->prepare("SELECT COUNT(*) FROM assessment_submissions WHERE status = 'submitted' AND academic_year_id = ? AND term_id = ?");
    $pendingApprovals->execute([$yearId, $termId]);
    $pendingCount = $pendingApprovals->fetchColumn() ?: 0;

    // Check if marks exist for current term
    $marksCountStmt = $db->prepare("SELECT COUNT(*) FROM marks WHERE academic_year_id = ? AND term_id = ?");
    $marksCountStmt->execute([$yearId, $termId]);
    $totalMarksCount = (int)($marksCountStmt->fetchColumn() ?: 0);

    // School Average
    $avgStmt = $db->prepare("SELECT AVG(total_score) FROM marks WHERE academic_year_id = ? AND term_id = ?");
    $avgStmt->execute([$yearId, $termId]);
    $schoolAverage = round((float)($avgStmt->fetchColumn() ?: 0), 1);

    // Best Performing Class
    $bestClassStmt = $db->prepare("
        SELECT c.class_name, AVG(m.total_score) as avg_score
        FROM marks m
        JOIN classes c ON m.class_id = c.id
        WHERE m.academic_year_id = ? AND m.term_id = ?
        GROUP BY c.id
        ORDER BY avg_score DESC LIMIT 1
    ");
    $bestClassStmt->execute([$yearId, $termId]);
    $bestClass = $bestClassStmt->fetch();

    // Best Performing Student
    $bestStudentStmt = $db->prepare("
        SELECT s.full_name, c.class_name, AVG(m.total_score) as avg_score
        FROM marks m
        JOIN students s ON m.student_id = s.id
        JOIN classes c ON s.class_id = c.id
        WHERE m.academic_year_id = ? AND m.term_id = ?
        GROUP BY s.id
        ORDER BY avg_score DESC LIMIT 1
    ");
    $bestStudentStmt->execute([$yearId, $termId]);
    $bestStudent = $bestStudentStmt->fetch();

    // Chart 1: Performance by Class
    $classPerfStmt = $db->prepare("
        SELECT c.class_name, COALESCE(AVG(m.total_score), 0) as avg_score
        FROM classes c
        LEFT JOIN marks m ON c.id = m.class_id AND m.academic_year_id = ? AND m.term_id = ?
        WHERE c.status = 'active'
        GROUP BY c.id
        ORDER BY c.display_order ASC
    ");
    $classPerfStmt->execute([$yearId, $termId]);
    $classPerfData = $classPerfStmt->fetchAll();

    // Chart 2: Grade Distribution
    $gradeDistStmt = $db->prepare("
        SELECT grade, COUNT(*) as count
        FROM marks
        WHERE academic_year_id = ? AND term_id = ? AND grade IS NOT NULL
        GROUP BY grade
    ");
    $gradeDistStmt->execute([$yearId, $termId]);
    $gradeDistRows = $gradeDistStmt->fetchAll(PDO::FETCH_KEY_PAIR);
    $gradeLabels = ['A', 'B', 'C', 'D', 'E', 'F'];
    $gradeCounts = [];
    foreach ($gradeLabels as $g) {
        $gradeCounts[] = (int)($gradeDistRows[$g] ?? 0);
    }

    // Top 5 Students
    $topStudentsStmt = $db->prepare("
        SELECT s.id, s.full_name, c.class_name, AVG(m.total_score) as avg_score, COUNT(m.id) as subjects_count
        FROM marks m
        JOIN students s ON m.student_id = s.id
        JOIN classes c ON s.class_id = c.id
        WHERE m.academic_year_id = ? AND m.term_id = ?
        GROUP BY s.id
        ORDER BY avg_score DESC
        LIMIT 5
    ");
    $topStudentsStmt->execute([$yearId, $termId]);
    $topStudents = $topStudentsStmt->fetchAll();

    // Students Requiring Academic Support (Avg < 50)
    $atRiskStmt = $db->prepare("
        SELECT s.id, s.full_name, c.class_name, AVG(m.total_score) as avg_score
        FROM marks m
        JOIN students s ON m.student_id = s.id
        JOIN classes c ON s.class_id = c.id
        WHERE m.academic_year_id = ? AND m.term_id = ?
        GROUP BY s.id
        HAVING avg_score < 50
        ORDER BY avg_score ASC
        LIMIT 5
    ");
    $atRiskStmt->execute([$yearId, $termId]);
    $atRiskStudents = $atRiskStmt->fetchAll();

    $gradingScale = getGradingScale();

} else {
    // Teacher specific statistics
    $assignedClass = getTeacherAssignedClass($userId);
    $classStudentCount = 0;
    $firstClassStudentId = 0;
    if ($assignedClass) {
        $cId = (int)$assignedClass['id'];
        $cntStmt = $db->prepare("SELECT COUNT(*) FROM students WHERE class_id = ? AND status = 'active'");
        $cntStmt->execute([$cId]);
        $classStudentCount = (int)($cntStmt->fetchColumn() ?: 0);

        $fstStmt = $db->prepare("SELECT id FROM students WHERE class_id = ? AND status = 'active' ORDER BY full_name ASC LIMIT 1");
        $fstStmt->execute([$cId]);
        $firstClassStudentId = (int)($fstStmt->fetchColumn() ?: 0);
    }

    $sigCheck = $db->prepare("SELECT signature FROM users WHERE id = ?");
    $sigCheck->execute([$userId]);
    $mySigFile = $sigCheck->fetchColumn();
    $teacherSigUrl = getTeacherSignatureUrl($mySigFile);

    $teacherClassesStmt = $db->prepare("
        SELECT DISTINCT c.id as class_id, c.class_name, s.id as subject_id, s.subject_name
        FROM teacher_assignments ta
        JOIN classes c ON ta.class_id = c.id
        JOIN subjects s ON ta.subject_id = s.id
        WHERE ta.teacher_id = ? AND ta.academic_year_id = ?
        ORDER BY c.class_name ASC, s.subject_name ASC
    ");
    $teacherClassesStmt->execute([$userId, $yearId]);
    $rawAssignments = $teacherClassesStmt->fetchAll();

    $teacherAssignments = [];
    $completedCount = 0;
    $pendingSubmitCount = 0;
    $totalAssessedMarksCount = 0;
    $totalStudentsCount = 0;

    foreach ($rawAssignments as $ta) {
        $cId = (int)$ta['class_id'];
        $sId = (int)$ta['subject_id'];

        // Class total active students
        $classStuStmt = $db->prepare("SELECT COUNT(*) FROM students WHERE class_id = ? AND status = 'active'");
        $classStuStmt->execute([$cId]);
        $totalClassStudents = (int)$classStuStmt->fetchColumn();

        // Marks count & subject average for this class/subject
        $mInfoStmt = $db->prepare("
            SELECT COUNT(*) as marks_count, AVG(total_score) as avg_score 
            FROM marks 
            WHERE class_id = ? AND subject_id = ? AND academic_year_id = ? AND term_id = ?
        ");
        $mInfoStmt->execute([$cId, $sId, $yearId, $termId]);
        $mInfo = $mInfoStmt->fetch();
        $marksCount = (int)($mInfo['marks_count'] ?? 0);
        $avgScore = ($mInfo['avg_score'] !== null) ? round((float)$mInfo['avg_score'], 1) : null;

        // Check assessment submission record
        $subCheck = $db->prepare("SELECT status, submitted_at, reviewed_at FROM assessment_submissions WHERE class_id = ? AND subject_id = ? AND academic_year_id = ? AND term_id = ?");
        $subCheck->execute([$cId, $sId, $yearId, $termId]);
        $subRow = $subCheck->fetch();
        $subStatus = $subRow['status'] ?? ($marksCount > 0 ? 'approved' : 'draft');

        $isUploaded = ($marksCount > 0 || $subStatus === 'approved');

        if ($isUploaded) {
            $completedCount++;
        } else {
            $pendingSubmitCount++;
        }

        $totalAssessedMarksCount += $marksCount;

        $teacherAssignments[] = [
            'class_id'       => $cId,
            'class_name'     => $ta['class_name'],
            'subject_id'     => $sId,
            'subject_name'   => $ta['subject_name'],
            'total_students' => $totalClassStudents,
            'marks_count'    => $marksCount,
            'avg_score'      => $avgScore,
            'status'         => $isUploaded ? 'approved' : 'draft',
            'is_uploaded'    => $isUploaded,
            'submitted_at'   => $subRow['submitted_at'] ?? null
        ];
    }

    $assignedClassIds = array_unique(array_column($rawAssignments, 'class_id'));
    if ($assignedClass) {
        $assignedClassIds[] = (int)$assignedClass['id'];
        $assignedClassIds = array_unique($assignedClassIds);
    }

    $totalTeacherStudents = 0;
    if (!empty($assignedClassIds)) {
        $inClause = implode(',', array_map('intval', $assignedClassIds));
        $totalTeacherStudents = (int)($db->query("SELECT COUNT(*) FROM students WHERE class_id IN ($inClause) AND status = 'active'")->fetchColumn() ?: 0);
    }

    // Teacher Average across all assigned subjects/classes
    $teacherAverage = 0;
    if (!empty($rawAssignments)) {
        $tAvgStmt = $db->prepare("
            SELECT AVG(m.total_score) 
            FROM marks m
            JOIN teacher_assignments ta ON m.class_id = ta.class_id AND m.subject_id = ta.subject_id
            WHERE ta.teacher_id = ? AND ta.academic_year_id = ? AND m.academic_year_id = ? AND m.term_id = ?
        ");
        $tAvgStmt->execute([$userId, $yearId, $yearId, $termId]);
        $teacherAverage = round((float)($tAvgStmt->fetchColumn() ?: 0), 1);
    }
}
?>

<!-- Top Welcome & Navigation Header -->
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
    <div>
        <div class="d-flex align-items-center gap-2 mb-1">
            <h2 class="h3 fw-bold mb-0" style="font-family: 'Outfit';">
                <?= $role === 'admin' ? 'Administrator Control Panel' : ($role === 'headteacher' ? 'Head Teacher Dashboard' : 'Teacher Portal') ?>
            </h2>
            <span class="badge bg-primary-subtle text-primary border border-primary-subtle font-monospace px-2 py-1">
                <?= htmlspecialchars($activeYear['year_name'] ?? '2025/2026') ?> • <?= htmlspecialchars($activeTerm['term_name'] ?? 'Term 1') ?>
            </span>
        </div>
        <div class="text-muted small">
            KETAN M/A B COMPLEX — School-Based Assessment & Student Performance Management System
        </div>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <?php if (in_array($role, ['admin', 'headteacher'])): ?>
            <a href="<?= url('class_teachers.php') ?>" class="btn btn-outline-primary btn-sm">
                <i class="bi bi-person-check-fill me-1"></i> Class Teachers
            </a>
            <a href="<?= url('teacher_signatures.php') ?>" class="btn btn-outline-primary btn-sm">
                <i class="bi bi-pen-fill me-1"></i> Signatures
            </a>
            <a href="<?= url('teachers.php') ?>" class="btn btn-outline-primary btn-sm">
                <i class="bi bi-person-badge me-1"></i> Staff
            </a>
            <a href="<?= url('students.php') ?>" class="btn btn-outline-primary btn-sm">
                <i class="bi bi-mortarboard me-1"></i> Students
            </a>
        <?php endif; ?>
        <a href="<?= url('marks.php') ?>" class="btn btn-primary-custom btn-sm">
            <i class="bi bi-pencil-square me-1"></i> Mark Entry (50/50)
        </a>
        <a href="<?= url('broadsheet.php') ?>" class="btn btn-accent-custom btn-sm">
            <i class="bi bi-table me-1"></i> Broadsheet
        </a>
    </div>
</div>

<?php if (in_array($role, ['admin', 'headteacher'])): ?>
    <!-- ADMIN STAT CARDS ROW -->
    <div class="row g-3 mb-4">
        <!-- Total Enrolled Students -->
        <div class="col-sm-6 col-xl-3">
            <div class="stat-card h-100">
                <div>
                    <div class="stat-title">Total Enrolled</div>
                    <div class="stat-value text-dark"><?= number_format($totalStudents) ?></div>
                    <div class="stat-meta">
                        <a href="<?= url('students.php') ?>" class="text-success text-decoration-none fw-semibold">
                            <i class="bi bi-people-fill me-1"></i> <?= $totalStudents > 0 ? 'View All Students' : 'Register Students' ?> &rarr;
                        </a>
                    </div>
                </div>
                <div class="stat-icon-wrapper stat-icon-primary">
                    <i class="bi bi-mortarboard-fill"></i>
                </div>
            </div>
        </div>

        <!-- Teaching Staff -->
        <div class="col-sm-6 col-xl-3">
            <div class="stat-card h-100">
                <div>
                    <div class="stat-title">Teaching Staff</div>
                    <div class="stat-value text-dark"><?= number_format($totalTeachers) ?></div>
                    <div class="stat-meta">
                        <a href="<?= url('teachers.php') ?>" class="text-primary text-decoration-none fw-semibold">
                            <i class="bi bi-person-badge-fill me-1"></i> <?= $totalTeachers > 0 ? 'Manage Teachers' : 'Add Teachers' ?> &rarr;
                        </a>
                    </div>
                </div>
                <div class="stat-icon-wrapper stat-icon-info">
                    <i class="bi bi-person-video3"></i>
                </div>
            </div>
        </div>

        <!-- Classes & Subjects -->
        <div class="col-sm-6 col-xl-3">
            <div class="stat-card h-100">
                <div>
                    <div class="stat-title">Academic Structure</div>
                    <div class="stat-value text-dark" style="font-size: 1.5rem;"><?= $totalClasses ?> <span class="fs-6 text-muted font-monospace">classes</span> • <?= $totalSubjects ?> <span class="fs-6 text-muted font-monospace">subjects</span></div>
                    <div class="stat-meta text-muted">
                        <a href="<?= url('assignments.php') ?>" class="text-decoration-none text-muted">
                            <i class="bi bi-diagram-3-fill me-1"></i> Subject Allocations &rarr;
                        </a>
                    </div>
                </div>
                <div class="stat-icon-wrapper stat-icon-warning">
                    <i class="bi bi-building"></i>
                </div>
            </div>
        </div>

        <!-- Assessment Weights -->
        <div class="col-sm-6 col-xl-3">
            <div class="stat-card h-100">
                <div>
                    <div class="stat-title">Assessment Weights</div>
                    <div class="stat-value text-primary" style="font-size: 1.5rem;">50% / 50%</div>
                    <div class="stat-meta text-muted">
                        <span class="badge bg-success-subtle text-success border border-success-subtle">SBA: <?= (int)$maxSba ?>%</span>
                        <span class="badge bg-info-subtle text-info border border-info-subtle">Exam: <?= (int)$maxExam ?>%</span>
                    </div>
                </div>
                <div class="stat-icon-wrapper stat-icon-success">
                    <i class="bi bi-percent"></i>
                </div>
            </div>
        </div>
    </div>

    <?php if ($totalMarksCount === 0): ?>
        <!-- EMPTY / FRESH STATE: Quick Setup Workflow Guide -->
        <div class="card-custom mb-4 border-primary border-opacity-25 shadow-sm">
            <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                <span class="fw-bold fs-6">
                    <i class="bi bi-compass me-2"></i> System Ready — School Assessment Workflow Guide
                </span>
                <span class="badge bg-white text-primary fw-bold font-monospace">50/50 Scheme Active</span>
            </div>
            <div class="card-body p-4">
                <p class="text-muted mb-4">
                    The student and teacher data has been cleared and the system is initialized with <strong>50% SBA (Class Score)</strong> and <strong>50% Examination Score</strong>. Follow the steps below to set up your school term:
                </p>

                <div class="row g-3">
                    <!-- Step 1 -->
                    <div class="col-md-6 col-lg-3">
                        <div class="p-3 border rounded-3 bg-light h-100 d-flex flex-column justify-content-between">
                            <div>
                                <div class="d-flex align-items-center gap-2 mb-2">
                                    <span class="badge bg-primary text-white rounded-pill px-2 py-1">Step 1</span>
                                    <h6 class="fw-bold mb-0">Create Teachers</h6>
                                </div>
                                <p class="small text-muted mb-3">
                                    Add teacher/lecturer accounts and generate their login passwords so they can access the portal.
                                </p>
                            </div>
                            <a href="<?= url('teachers.php') ?>" class="btn btn-sm btn-outline-primary w-100">
                                <i class="bi bi-person-plus me-1"></i> Add Teachers
                            </a>
                        </div>
                    </div>

                    <!-- Step 2 -->
                    <div class="col-md-6 col-lg-3">
                        <div class="p-3 border rounded-3 bg-light h-100 d-flex flex-column justify-content-between">
                            <div>
                                <div class="d-flex align-items-center gap-2 mb-2">
                                    <span class="badge bg-primary text-white rounded-pill px-2 py-1">Step 2</span>
                                    <h6 class="fw-bold mb-0">Enroll Students</h6>
                                </div>
                                <p class="small text-muted mb-3">
                                    Register students into their respective classes with their full names and basic details.
                                </p>
                            </div>
                            <a href="<?= url('students.php') ?>" class="btn btn-sm btn-outline-primary w-100">
                                <i class="bi bi-mortarboard me-1"></i> Enroll Students
                            </a>
                        </div>
                    </div>

                    <!-- Step 3 -->
                    <div class="col-md-6 col-lg-3">
                        <div class="p-3 border rounded-3 bg-light h-100 d-flex flex-column justify-content-between">
                            <div>
                                <div class="d-flex align-items-center gap-2 mb-2">
                                    <span class="badge bg-primary text-white rounded-pill px-2 py-1">Step 3</span>
                                    <h6 class="fw-bold mb-0">Assign Classes</h6>
                                </div>
                                <p class="small text-muted mb-3">
                                    Allocate teachers to the subjects and classes they teach so their mark entry sheets are unlocked.
                                </p>
                            </div>
                            <a href="<?= url('assignments.php') ?>" class="btn btn-sm btn-outline-primary w-100">
                                <i class="bi bi-link-45deg me-1"></i> Allocate Subjects
                            </a>
                        </div>
                    </div>

                    <!-- Step 4 -->
                    <div class="col-md-6 col-lg-3">
                        <div class="p-3 border rounded-3 bg-light h-100 d-flex flex-column justify-content-between">
                            <div>
                                <div class="d-flex align-items-center gap-2 mb-2">
                                    <span class="badge bg-success text-white rounded-pill px-2 py-1">Step 4</span>
                                    <h6 class="fw-bold mb-0">Record Marks</h6>
                                </div>
                                <p class="small text-muted mb-3">
                                    Enter 50% Class SBA and 50% Exams with live grade calculations and automatic broadsheet ranking.
                                </p>
                            </div>
                            <a href="<?= url('marks.php') ?>" class="btn btn-sm btn-success w-100">
                                <i class="bi bi-pencil-square me-1"></i> Mark Entry
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Academic Configuration Overview Grid -->
        <div class="row g-3 mb-4">
            <div class="col-lg-7">
                <div class="card-custom h-100">
                    <div class="card-header bg-white">
                        <span><i class="bi bi-info-circle-fill text-primary me-2"></i>Current Session & Grading Parameters</span>
                    </div>
                    <div class="card-body p-4">
                        <div class="row g-3">
                            <div class="col-sm-6">
                                <div class="p-3 bg-light rounded-3 border">
                                    <div class="small text-muted fw-bold text-uppercase">Academic Year</div>
                                    <div class="h5 fw-bold text-primary mb-1"><?= htmlspecialchars($activeYear['year_name'] ?? '2025/2026') ?></div>
                                    <small class="text-success"><i class="bi bi-check-circle-fill me-1"></i> Active Session</small>
                                </div>
                            </div>
                            <div class="col-sm-6">
                                <div class="p-3 bg-light rounded-3 border">
                                    <div class="small text-muted fw-bold text-uppercase">Current Term</div>
                                    <div class="h5 fw-bold text-dark mb-1"><?= htmlspecialchars($activeTerm['term_name'] ?? 'Term 1') ?></div>
                                    <small class="text-muted"><i class="bi bi-calendar-event me-1"></i> Next Term: <?= date('d M, Y', strtotime(getSetting('next_term_date', '2026-01-12'))) ?></small>
                                </div>
                            </div>
                            <div class="col-sm-6">
                                <div class="p-3 bg-light rounded-3 border">
                                    <div class="small text-muted fw-bold text-uppercase">Class Score (SBA) Ratio</div>
                                    <div class="h5 fw-bold text-success mb-1">50.0% Max</div>
                                    <small class="text-muted">Continuous classroom assessment</small>
                                </div>
                            </div>
                            <div class="col-sm-6">
                                <div class="p-3 bg-light rounded-3 border">
                                    <div class="small text-muted fw-bold text-uppercase">Examination Score Ratio</div>
                                    <div class="h5 fw-bold text-info mb-1">50.0% Max</div>
                                    <small class="text-muted">End-of-term terminal exam</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Terminal Grading Scale Legend -->
            <div class="col-lg-5">
                <div class="card-custom h-100">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-award-fill text-warning me-2"></i>Grading Scale Legend</span>
                        <a href="<?= url('settings.php') ?>" class="small text-decoration-none">Configure &rarr;</a>
                    </div>
                    <div class="card-body p-3">
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead>
                                    <tr class="text-muted small">
                                        <th>Grade</th>
                                        <th>Score Range</th>
                                        <th>Remark</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($gradingScale as $g): ?>
                                        <tr>
                                            <td><span class="grade-badge grade-<?= $g['grade'] ?>"><?= $g['grade'] ?></span></td>
                                            <td class="font-monospace small"><?= (float)$g['min_score'] ?>% – <?= (float)$g['max_score'] ?>%</td>
                                            <td class="small fw-semibold"><?= htmlspecialchars($g['remark']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    <?php else: ?>
        <!-- ACTIVE DATA STATE: Highlights, Charts, Top & At-Risk Tables -->
        <div class="row g-3 mb-4">
            <div class="col-md-6">
                <div class="p-3 bg-white rounded-3 border d-flex align-items-center gap-3 shadow-sm">
                    <div class="rounded-circle p-3 bg-primary bg-opacity-10 text-primary fs-3">
                        <i class="bi bi-trophy-fill text-warning"></i>
                    </div>
                    <div>
                        <div class="small text-muted text-uppercase fw-bold">Top Performing Class</div>
                        <div class="h5 mb-0 fw-bold"><?= htmlspecialchars($bestClass['class_name'] ?? 'N/A') ?></div>
                        <small class="text-success fw-bold">Average: <?= round((float)($bestClass['avg_score'] ?? 0), 1) ?>%</small>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="p-3 bg-white rounded-3 border d-flex align-items-center gap-3 shadow-sm">
                    <div class="rounded-circle p-3 bg-success bg-opacity-10 text-success fs-3">
                        <i class="bi bi-star-fill text-warning"></i>
                    </div>
                    <div>
                        <div class="small text-muted text-uppercase fw-bold">Overall Highest Student</div>
                        <div class="h5 mb-0 fw-bold"><?= htmlspecialchars($bestStudent['full_name'] ?? 'N/A') ?> (<?= htmlspecialchars($bestStudent['class_name'] ?? '') ?>)</div>
                        <small class="text-success fw-bold">Average: <?= round((float)($bestStudent['avg_score'] ?? 0), 1) ?>%</small>
                    </div>
                </div>
            </div>
        </div>

        <!-- Charts Row -->
        <div class="row g-3 mb-4">
            <div class="col-lg-8">
                <div class="card-custom h-100">
                    <div class="card-header">
                        <span><i class="bi bi-bar-chart-fill me-2 text-primary"></i>Class Average Performance</span>
                        <span class="badge bg-light text-dark">Term <?= htmlspecialchars($activeTerm['term_name'] ?? '') ?></span>
                    </div>
                    <div class="card-body">
                        <div style="height: 280px;">
                            <canvas id="classPerfChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="card-custom h-100">
                    <div class="card-header">
                        <span><i class="bi bi-pie-chart-fill me-2 text-primary"></i>Grade Distribution</span>
                    </div>
                    <div class="card-body">
                        <div style="height: 280px;">
                            <canvas id="gradeDistChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Top Students & At-Risk Tables -->
        <div class="row g-3">
            <div class="col-lg-6">
                <div class="card-custom">
                    <div class="card-header bg-white">
                        <span class="text-success"><i class="bi bi-award-fill me-2"></i>Top Performing Students</span>
                        <a href="<?= url('broadsheet.php') ?>" class="small text-decoration-none">View Broadsheet &rarr;</a>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-custom mb-0">
                            <thead>
                                <tr>
                                    <th>Student</th>
                                    <th>Class</th>
                                    <th>Subjects</th>
                                    <th class="text-end">Average</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($topStudents)): ?>
                                    <tr><td colspan="4" class="text-center text-muted py-3">No assessment data recorded yet.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($topStudents as $st): ?>
                                        <tr>
                                            <td>
                                                <a href="<?= url('student_profile.php?id=' . $st['id']) ?>" class="fw-bold text-dark text-decoration-none">
                                                    <?= htmlspecialchars($st['full_name']) ?>
                                                </a>
                                            </td>
                                            <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($st['class_name']) ?></span></td>
                                            <td><?= $st['subjects_count'] ?></td>
                                            <td class="text-end fw-bold text-success fs-6"><?= round($st['avg_score'], 1) ?>%</td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="card-custom">
                    <div class="card-header bg-white">
                        <span class="text-danger"><i class="bi bi-exclamation-octagon-fill me-2"></i>Students Requiring Academic Support (&lt; 50%)</span>
                        <a href="<?= url('analytics.php') ?>" class="small text-decoration-none">Intervention Plan &rarr;</a>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-custom mb-0">
                            <thead>
                                <tr>
                                    <th>Student</th>
                                    <th>Class</th>
                                    <th class="text-end">Current Average</th>
                                    <th class="text-center">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($atRiskStudents)): ?>
                                    <tr><td colspan="4" class="text-center text-success py-3"><i class="bi bi-check-circle me-1"></i> No students currently in at-risk band!</td></tr>
                                <?php else: ?>
                                    <?php foreach ($atRiskStudents as $at): ?>
                                        <tr>
                                            <td>
                                                <a href="<?= url('student_profile.php?id=' . $at['id']) ?>" class="fw-bold text-dark text-decoration-none">
                                                    <?= htmlspecialchars($at['full_name']) ?>
                                                </a>
                                            </td>
                                            <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($at['class_name']) ?></span></td>
                                            <td class="text-end fw-bold text-danger"><?= round($at['avg_score'], 1) ?>%</td>
                                            <td class="text-center">
                                                <a href="<?= url('student_profile.php?id=' . $at['id']) ?>" class="btn btn-sm btn-outline-primary py-0 px-2">Profile</a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

<?php else: /* TEACHER DASHBOARD */ ?>
    <!-- CLASS TEACHER DESIGNATION BANNER -->
    <?php if ($assignedClass): ?>
        <div class="card-custom mb-4 shadow-sm border-0" style="border-left: 5px solid #1a56db !important; background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);">
            <div class="p-4">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-3">
                    <div>
                        <div class="d-flex align-items-center gap-2 mb-1">
                            <span class="badge bg-primary px-3 py-1 font-monospace" style="letter-spacing: 0.5px;">DESIGNATED CLASS TEACHER</span>
                            <h3 class="h4 fw-bold mb-0 text-dark" style="font-family: 'Outfit';">
                                My Assigned Class: <span class="text-primary"><?= htmlspecialchars($assignedClass['class_name']) ?></span>
                            </h3>
                        </div>
                        <div class="text-muted small">
                            You are the official Class Teacher for <strong><?= htmlspecialchars($assignedClass['class_name']) ?></strong>. You can review student records, entry remarks, and terminal performance reports.
                        </div>
                    </div>
                    <div>
                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-3 py-2 font-monospace">
                            <i class="bi bi-people-fill me-1"></i> <?= $classStudentCount ?> Students Enrolled
                        </span>
                    </div>
                </div>

                <div class="row g-2 pt-2 border-top">
                    <div class="col-sm-6 col-md-3">
                        <a href="<?= url('students.php?class_id=' . $assignedClass['id']) ?>" class="btn btn-outline-primary w-100 text-start p-2 h-100 d-flex align-items-center gap-2">
                            <i class="bi bi-people-fill fs-3 text-primary"></i>
                            <div>
                                <div class="fw-bold text-dark small">My Class Students</div>
                                <div class="text-muted" style="font-size: 0.75rem;"><?= $classStudentCount ?> Enrolled Students &rarr;</div>
                            </div>
                        </a>
                    </div>
                    <div class="col-sm-6 col-md-3">
                        <a href="<?= url('broadsheet.php?class_id=' . $assignedClass['id']) ?>" class="btn btn-outline-primary w-100 text-start p-2 h-100 d-flex align-items-center gap-2">
                            <i class="bi bi-table fs-3 text-info"></i>
                            <div>
                                <div class="fw-bold text-dark small">Class Broadsheet</div>
                                <div class="text-muted" style="font-size: 0.75rem;">View Class Performance &rarr;</div>
                            </div>
                        </a>
                    </div>
                    <div class="col-sm-6 col-md-3">
                        <a href="<?= url('report_card.php' . ($firstClassStudentId ? '?student_id=' . $firstClassStudentId : '')) ?>" class="btn btn-outline-primary w-100 text-start p-2 h-100 d-flex align-items-center gap-2">
                            <i class="bi bi-award-fill fs-3 text-warning"></i>
                            <div>
                                <div class="fw-bold text-dark small">Terminal Report Cards</div>
                                <div class="text-muted" style="font-size: 0.75rem;">A4 Printable Reports &rarr;</div>
                            </div>
                        </a>
                    </div>
                    <div class="col-sm-6 col-md-3">
                        <a href="<?= url('marks.php?class_id=' . $assignedClass['id']) ?>" class="btn btn-outline-primary w-100 text-start p-2 h-100 d-flex align-items-center gap-2">
                            <i class="bi bi-pencil-square fs-3 text-success"></i>
                            <div>
                                <div class="fw-bold text-dark small">SBA & Exam Scores</div>
                                <div class="text-muted" style="font-size: 0.75rem;">Subject Assessment Marks &rarr;</div>
                            </div>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- TEACHER METRICS -->
    <div class="row g-3 mb-4">
        <div class="col-sm-6 col-xl-3">
            <div class="stat-card">
                <div>
                    <div class="stat-title">My Students</div>
                    <div class="stat-value"><?= number_format($totalTeacherStudents) ?></div>
                    <div class="stat-meta text-primary">In Assigned Classes</div>
                </div>
                <div class="stat-icon-wrapper stat-icon-primary">
                    <i class="bi bi-mortarboard-fill"></i>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="stat-card">
                <div>
                    <div class="stat-title">Assigned Allocations</div>
                    <div class="stat-value"><?= count($teacherAssignments) ?></div>
                    <div class="stat-meta text-muted">Class & Subject pairs</div>
                </div>
                <div class="stat-icon-wrapper stat-icon-info">
                    <i class="bi bi-book-half"></i>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="stat-card">
                <div>
                    <div class="stat-title">Marks Upload Status</div>
                    <div class="stat-value text-success"><?= $completedCount ?> <span class="fs-6 text-muted fw-normal">/ <?= count($teacherAssignments) ?></span></div>
                    <div class="stat-meta <?= $pendingSubmitCount > 0 ? 'text-warning fw-semibold' : 'text-success' ?>">
                        <?= $pendingSubmitCount > 0 ? $pendingSubmitCount . ' Pending Upload' : 'All Subjects Uploaded' ?>
                    </div>
                </div>
                <div class="stat-icon-wrapper stat-icon-success">
                    <i class="bi bi-cloud-check-fill"></i>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="stat-card">
                <div>
                    <div class="stat-title">Subject Average</div>
                    <div class="stat-value <?= $teacherAverage > 0 ? 'text-primary' : 'text-muted' ?>"><?= $teacherAverage > 0 ? $teacherAverage . '%' : '—' ?></div>
                    <div class="stat-meta text-muted">Assessed Students Average</div>
                </div>
                <div class="stat-icon-wrapper stat-icon-warning">
                    <i class="bi bi-graph-up-arrow"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Assigned Classes & Subject Marks Status List -->
    <div class="card-custom mb-4">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div class="d-flex align-items-center gap-2">
                <i class="bi bi-journals fs-5 text-primary"></i>
                <div>
                    <span class="fw-bold text-dark">My Assigned Subjects & Assessment Status</span>
                    <div class="small text-muted" style="font-size: 0.75rem;">Standard 50% SBA + 50% Exam Calculation</div>
                </div>
            </div>
            <div class="d-flex gap-2">
                <a href="<?= url('bulk_upload.php') ?>" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-file-earmark-spreadsheet me-1"></i> Bulk Upload
                </a>
                <a href="<?= url('marks.php') ?>" class="btn btn-sm btn-primary-custom">
                    <i class="bi bi-pencil-square me-1"></i> Enter Marks
                </a>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-custom align-middle mb-0">
                <thead>
                    <tr>
                        <th style="min-width: 140px;">Class</th>
                        <th style="min-width: 160px;">Subject</th>
                        <th style="min-width: 180px;">Marks Upload Status</th>
                        <th style="min-width: 160px;">Assessment Progress</th>
                        <th style="min-width: 100px;">Subject Average</th>
                        <th class="text-end" style="min-width: 180px;">Quick Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($teacherAssignments)): ?>
                        <tr>
                            <td colspan="6" class="text-center text-muted py-5">
                                <div class="mb-2"><i class="bi bi-calendar2-x fs-1 opacity-50"></i></div>
                                <h6 class="fw-bold">No Subject Allocations Found</h6>
                                <p class="small text-muted mb-0">You have not been allocated any subjects for the active term yet. Please contact the administrator (COMPLEX) to assign your classes.</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($teacherAssignments as $ta): ?>
                            <tr>
                                <td>
                                    <strong class="text-dark"><?= htmlspecialchars($ta['class_name']) ?></strong>
                                </td>
                                <td>
                                    <span class="fw-semibold text-primary"><?= htmlspecialchars($ta['subject_name']) ?></span>
                                </td>
                                <td>
                                    <?php if ($ta['is_uploaded']): ?>
                                        <span class="status-badge status-approved">
                                            <i class="bi bi-check-circle-fill me-1"></i> UPLOADED (APPROVED)
                                        </span>
                                    <?php else: ?>
                                        <span class="status-badge status-draft">
                                            <i class="bi bi-clock-history me-1"></i> PENDING UPLOAD
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php 
                                        $pct = ($ta['total_students'] > 0) ? round(($ta['marks_count'] / $ta['total_students']) * 100) : 0;
                                    ?>
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="progress flex-grow-1" style="height: 6px; min-width: 70px;">
                                            <div class="progress-bar <?= $pct >= 100 ? 'bg-success' : 'bg-primary' ?>" style="width: <?= min(100, $pct) ?>%"></div>
                                        </div>
                                        <span class="small font-monospace fw-bold <?= $ta['is_uploaded'] ? 'text-success' : 'text-muted' ?>" style="font-size: 0.76rem;">
                                            <?= $ta['marks_count'] ?>/<?= $ta['total_students'] ?>
                                        </span>
                                    </div>
                                </td>
                                <td>
                                    <?php if ($ta['avg_score'] !== null): ?>
                                        <span class="fw-bold font-monospace text-dark"><?= number_format($ta['avg_score'], 1) ?>%</span>
                                    <?php else: ?>
                                        <span class="text-muted small">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <?php if ($ta['is_uploaded']): ?>
                                        <div class="btn-group btn-group-sm">
                                            <a href="<?= url("marks.php?class_id={$ta['class_id']}&subject_id={$ta['subject_id']}") ?>" class="btn btn-outline-success" title="View Marks">
                                                <i class="bi bi-eye-fill me-1"></i> View Marks
                                            </a>
                                            <a href="<?= url("broadsheet.php?class_id={$ta['class_id']}") ?>" class="btn btn-outline-primary" title="Class Broadsheet">
                                                <i class="bi bi-table me-1"></i> Broadsheet
                                            </a>
                                            <?php if (isAdmin()): ?>
                                                <a href="<?= url("marks.php?class_id={$ta['class_id']}&subject_id={$ta['subject_id']}") ?>" class="btn btn-warning" title="Edit as Admin">
                                                    <i class="bi bi-shield-lock me-1"></i> Edit
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="btn-group btn-group-sm">
                                            <a href="<?= url("marks.php?class_id={$ta['class_id']}&subject_id={$ta['subject_id']}") ?>" class="btn btn-primary-custom" title="Enter Marks">
                                                <i class="bi bi-pencil-square me-1"></i> Enter Marks
                                            </a>
                                            <a href="<?= url("bulk_upload.php?class_id={$ta['class_id']}&subject_id={$ta['subject_id']}") ?>" class="btn btn-outline-secondary" title="Bulk Upload">
                                                <i class="bi bi-upload me-1"></i> Bulk Upload
                                            </a>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<!-- Chart Initialization Script (only when marks exist) -->
<?php if (in_array($role, ['admin', 'headteacher']) && ($totalMarksCount ?? 0) > 0): ?>
<script src="<?= asset('js/charts.js') ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    // 1. Class Performance Chart
    const classLabels = <?= json_encode(array_column($classPerfData, 'class_name')) ?>;
    const classAverages = <?= json_encode(array_map(fn($v) => round((float)$v['avg_score'], 1), $classPerfData)) ?>;
    SchoolCharts.renderBarChart('classPerfChart', classLabels, classAverages);

    // 2. Grade Distribution Chart
    const gradeLabels = <?= json_encode($gradeLabels) ?>;
    const gradeCounts = <?= json_encode($gradeCounts) ?>;
    SchoolCharts.renderDoughnutChart('gradeDistChart', gradeLabels, gradeCounts);
});
</script>
<?php endif; ?>

<?php
require_once __DIR__ . '/includes/footer.php';
?>
