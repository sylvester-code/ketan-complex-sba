<?php
/**
 * KETAN M/A B COMPLEX - Official Printable Student Terminal Report Card (A4 Portrait)
 * Conforms to Ghanaian Basic Education SBA Standards (50% Class SBA + 50% Exam).
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/session.php';

requireLogin();

$db = getDB();
$studentId = (int)($_GET['student_id'] ?? 0);

// Default to first student if none specified
if (!$studentId) {
    $firstStudent = $db->query("SELECT id FROM students WHERE status = 'active' ORDER BY id ASC LIMIT 1")->fetchColumn();
    if ($firstStudent) {
        $studentId = (int)$firstStudent;
    }
}

$activeYear = getActiveAcademicYear();
$activeTerm = getActiveTerm();
$yearId = isset($_GET['year_id']) ? (int)$_GET['year_id'] : ($activeYear['id'] ?? 1);
$termId = isset($_GET['term_id']) ? (int)$_GET['term_id'] : ($activeTerm['id'] ?? 1);

$maxSba = (float)getSetting('max_sba_score', '50');
$maxExam = (float)getSetting('max_exam_score', '50');

// Fetch Student Information
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

// Fetch Term Info
$termStmt = $db->prepare("SELECT * FROM terms WHERE id = ?");
$termStmt->execute([$termId]);
$termData = $termStmt->fetch();

// Requirement 4 & 6: Fetch Assigned Class Teacher for this student's class
$classTeacher = getAssignedClassTeacher((int)$student['class_id']);
$classTeacherName = $classTeacher['full_name'] ?? 'Class Teacher';
$classTeacherSigUrl = !empty($classTeacher['signature']) ? getTeacherSignatureUrl($classTeacher['signature']) : null;

// Fetch Headteacher Signature & Name
$headTeacherName = getSetting('head_teacher_name', 'Mr. Emmanuel K. Mensah (B.Ed, M.Ed)');
$htSigStmt = $db->query("SELECT signature FROM users WHERE role = 'headteacher' AND status = 'active' AND signature IS NOT NULL AND signature != '' LIMIT 1");
$htSigFile = $htSigStmt ? $htSigStmt->fetchColumn() : null;
$headTeacherSigUrl = getTeacherSignatureUrl($htSigFile);

// Fetch Marks for Report Card
$marksStmt = $db->prepare("
    SELECT m.*, sub.subject_name, sub.subject_code, sub.category, sub.display_order
    FROM marks m
    JOIN subjects sub ON m.subject_id = sub.id
    WHERE m.student_id = ? AND m.academic_year_id = ? AND m.term_id = ?
    ORDER BY sub.display_order ASC, sub.subject_name ASC
");
$marksStmt->execute([$studentId, $yearId, $termId]);
$marks = $marksStmt->fetchAll();

// Calculate Subject Positions within Class
$subjectPositions = [];
foreach ($marks as $m) {
    $subRankStmt = $db->prepare("
        SELECT student_id, total_score 
        FROM marks 
        WHERE class_id = ? AND subject_id = ? AND academic_year_id = ? AND term_id = ?
        ORDER BY total_score DESC
    ");
    $subRankStmt->execute([$student['class_id'], $m['subject_id'], $yearId, $termId]);
    $rankedScores = $subRankStmt->fetchAll();
    
    $pos = 1;
    foreach ($rankedScores as $rs) {
        if ((int)$rs['student_id'] === $studentId) {
            $subjectPositions[$m['subject_id']] = formatOrdinal($pos);
            break;
        }
        $pos++;
    }
}

// Fetch Terminal Report Meta (Attendance, remarks, conduct)
$reportStmt = $db->prepare("
    SELECT * FROM student_term_reports 
    WHERE student_id = ? AND academic_year_id = ? AND term_id = ?
");
$reportStmt->execute([$studentId, $yearId, $termId]);
$reportMeta = $reportStmt->fetch() ?: [];

// Compute Totals and Overall Rank
$totalScore = 0;
$subjectCount = count($marks);
foreach ($marks as $m) {
    $totalScore += (float)$m['total_score'];
}
$averageScore = $subjectCount > 0 ? round($totalScore / $subjectCount, 1) : 0;
$gradeInfo = calculateGradeAndRemark($averageScore);

// Overall Class Position Calculation
$classRankStmt = $db->prepare("
    SELECT s.id, AVG(m.total_score) as student_avg
    FROM students s
    JOIN marks m ON s.id = m.student_id
    WHERE s.class_id = ? AND m.academic_year_id = ? AND m.term_id = ?
    GROUP BY s.id
    ORDER BY student_avg DESC
");
$classRankStmt->execute([$student['class_id'], $yearId, $termId]);
$allClassStudents = $classRankStmt->fetchAll();

$classSize = count($allClassStudents);
$overallPosition = '—';
$rank = 1;
foreach ($allClassStudents as $cs) {
    if ((int)$cs['id'] === $studentId) {
        $overallPosition = formatOrdinal($rank);
        break;
    }
    $rank++;
}

function formatOrdinal(int $n): string {
    $ends = ['th','st','nd','rd','th','th','th','th','th','th'];
    if ((($n % 100) >= 11) && (($n % 100) <= 13)) return $n . 'th';
    return $n . $ends[$n % 10];
}

// Fetch Class Peers for Quick Navigation Dropdown
$peerList = $db->prepare("SELECT id, full_name, student_id FROM students WHERE class_id = ? AND status = 'active' ORDER BY full_name ASC");
$peerList->execute([$student['class_id']]);
$peers = $peerList->fetchAll();

$photoSrc = $student['photo'] ? url('uploads/students/' . $student['photo']) : asset('images/default-avatar.svg');

$schoolName = getSetting('school_name', 'KETAN M/A B COMPLEX');
$schoolMotto = getSetting('school_motto', 'Knowledge, Discipline and Excellence');
$schoolAddress = getSetting('school_address', 'P.O. Box 450, Ketan, Sekondi-Takoradi, Western Region, Ghana');
$schoolPhone = getSetting('school_phone', '+233 (0) 31 204 5678 / +233 (0) 24 412 3456');
$schoolEmail = getSetting('school_email', 'info@ketancomplexmjhs.edu.gh');

$pageTitle = 'Report Card - ' . $student['full_name'];
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
require_once __DIR__ . '/includes/navbar.php';
?>

<!-- Action Bar (Not Printed, Centered in Viewport) -->
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2 no-print" style="max-width: 210mm; margin: 0 auto 1rem auto;">
    <div class="d-flex align-items-center gap-2">
        <label class="small fw-bold text-muted text-uppercase mb-0">Student:</label>
        <form method="GET" action="report_card.php" class="d-flex align-items-center gap-2">
            <input type="hidden" name="year_id" value="<?= $yearId ?>">
            <input type="hidden" name="term_id" value="<?= $termId ?>">
            <select name="student_id" class="form-select form-select-sm" onchange="this.form.submit()" style="min-width: 260px;">
                <?php foreach ($peers as $p): ?>
                    <option value="<?= $p['id'] ?>" <?= $p['id'] == $studentId ? 'selected' : '' ?>>
                        <?= htmlspecialchars($p['full_name']) ?> (<?= htmlspecialchars($p['student_id']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>
    <div class="d-flex gap-2">
        <button type="button" class="btn btn-warning fw-bold text-dark btn-sm shadow-sm" onclick="window.print()">
            <i class="bi bi-printer-fill me-1"></i> Print / Save A4 PDF
        </button>
        <a href="<?= url('student_profile.php?id=' . $studentId) ?>" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i> Student Profile
        </a>
    </div>
</div>

<!-- ========================================================= -->
<!-- OFFICIAL A4 PORTRAIT REPORT CARD (210mm x 297mm) -->
<!-- ========================================================= -->
<div class="report-card-container">
    <!-- Report Card Header -->
    <div class="report-header">
        <img src="<?= getSchoolLogoUrl() ?>" alt="School Crest" class="report-crest">
        <div class="report-school-title">
            <h2><?= strtoupper(htmlspecialchars($schoolName)) ?></h2>
            <div class="motto">"<?= htmlspecialchars($schoolMotto) ?>"</div>
            <div class="address"><?= htmlspecialchars($schoolAddress) ?></div>
            <div class="address small">Tel: <?= htmlspecialchars($schoolPhone) ?> | Email: <?= htmlspecialchars($schoolEmail) ?></div>
        </div>
        <img src="<?= $photoSrc ?>" alt="Student Photo" class="student-photo-card border border-dark rounded">
    </div>

    <!-- Title Banner -->
    <div class="report-title-banner">
        STUDENT TERMINAL PERFORMANCE REPORT
    </div>

    <!-- Student Metadata Grid -->
    <div class="student-meta-box">
        <div class="student-meta-item">
            <strong>Student's Full Name:</strong>
            <span class="fw-bold text-dark"><?= htmlspecialchars($student['full_name']) ?></span>
        </div>
        <div class="student-meta-item">
            <strong>Student ID / Index:</strong>
            <span class="font-monospace fw-bold text-dark"><?= htmlspecialchars($student['student_id']) ?></span>
        </div>
        <div class="student-meta-item">
            <strong>Class / Form:</strong>
            <span class="fw-bold text-primary"><?= htmlspecialchars($student['class_name']) ?></span>
        </div>
        <div class="student-meta-item">
            <strong>Gender:</strong>
            <span><?= htmlspecialchars($student['gender']) ?></span>
        </div>
        <div class="student-meta-item">
            <strong>Academic Year:</strong>
            <span><?= htmlspecialchars($activeYear['year_name'] ?? '') ?></span>
        </div>
        <div class="student-meta-item">
            <strong>Term:</strong>
            <span class="fw-bold"><?= htmlspecialchars($termData['term_name'] ?? '') ?></span>
        </div>
        <div class="student-meta-item">
            <strong>Attendance:</strong>
            <span><?= htmlspecialchars($reportMeta['attendance_present'] ?? '58') ?> / <?= htmlspecialchars($reportMeta['attendance_total'] ?? '60') ?> Days</span>
        </div>
        <div class="student-meta-item">
            <strong>Next Term Begins:</strong>
            <span class="fw-bold"><?= !empty($termData['next_term_start_date']) ? date('d M, Y', strtotime($termData['next_term_start_date'])) : 'To Be Announced' ?></span>
        </div>
    </div>

    <!-- Academic Performance Table (50% SBA + 50% Exam = 100%) -->
    <table class="table-report table-bordered table-sm mb-2" style="width: 100%; border-collapse: collapse; font-size: 0.78rem;">
        <thead style="background: #0f2942; color: #ffffff;">
            <tr class="text-center align-middle" style="background: #0f2942; color: #ffffff;">
                <th class="text-start" style="width: 25%; padding: 4px 6px;">Subject</th>
                <th style="width: 11%; padding: 4px 6px;">Class (<?= (int)$maxSba ?>%)</th>
                <th style="width: 11%; padding: 4px 6px;">Exam (<?= (int)$maxExam ?>%)</th>
                <th style="width: 12%; padding: 4px 6px;">Total (100%)</th>
                <th style="width: 8%; padding: 4px 6px;">Grade</th>
                <th style="width: 10%; padding: 4px 6px;">Position</th>
                <th class="text-start" style="width: 23%; padding: 4px 6px;">Teacher's Remark</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($marks)): ?>
                <tr>
                    <td colspan="7" class="text-center py-3 text-muted">
                        Assessment results for this term have not yet been approved or recorded.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($marks as $m): ?>
                    <tr class="align-middle">
                        <td class="fw-bold" style="padding: 2.5px 6px;"><?= htmlspecialchars($m['subject_name']) ?></td>
                        <td class="text-center font-monospace" style="padding: 2.5px 6px;"><?= number_format($m['sba_score'], 1) ?></td>
                        <td class="text-center font-monospace" style="padding: 2.5px 6px;"><?= number_format($m['exam_score'], 1) ?></td>
                        <td class="text-center font-monospace fw-bold" style="padding: 2.5px 6px;"><?= number_format($m['total_score'], 1) ?></td>
                        <td class="text-center fw-bold" style="padding: 2.5px 6px;">
                            <span class="grade-badge grade-<?= $m['grade'] ?>" style="width: 20px; height: 20px; font-size: 0.72rem; line-height: 20px;">
                                <?= $m['grade'] ?>
                            </span>
                        </td>
                        <td class="text-center small font-monospace" style="padding: 2.5px 6px;"><?= $subjectPositions[$m['subject_id']] ?? '—' ?></td>
                        <td class="small" style="padding: 2.5px 6px;"><?= htmlspecialchars($m['remark'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <!-- Terminal Aggregate Summary Grid -->
    <div class="summary-metrics-grid mb-2">
        <div class="summary-metric-card">
            <div class="label">Total Marks</div>
            <div class="val"><?= number_format($totalScore, 1) ?></div>
        </div>
        <div class="summary-metric-card">
            <div class="label">Overall Average</div>
            <div class="val text-primary"><?= $averageScore ?>%</div>
        </div>
        <div class="summary-metric-card">
            <div class="label">Overall Grade</div>
            <div class="val text-success"><?= $gradeInfo['grade'] ?> <span class="small text-muted" style="font-size: 0.75rem;">(<?= $gradeInfo['remark'] ?>)</span></div>
        </div>
        <div class="summary-metric-card">
            <div class="label">Class Position</div>
            <div class="val text-dark"><?= $overallPosition ?> <span class="small text-muted" style="font-size: 0.75rem;">of <?= $classSize ?></span></div>
        </div>
    </div>

    <!-- Conduct, Attitude & Interests -->
    <div class="conduct-box mb-2">
        <div class="row g-1">
            <div class="col-4"><strong>Conduct:</strong> <?= htmlspecialchars($reportMeta['conduct'] ?? 'Satisfactory and respectful') ?></div>
            <div class="col-4"><strong>Attitude:</strong> <?= htmlspecialchars($reportMeta['attitude'] ?? 'Hardworking and focused') ?></div>
            <div class="col-4"><strong>Interests:</strong> <?= htmlspecialchars($reportMeta['interest'] ?? 'Reading, Sports and Art') ?></div>
        </div>
    </div>

    <?php
    $finalClassTeacherRemark = !empty(trim($reportMeta['class_teacher_remark'] ?? '')) 
        ? trim($reportMeta['class_teacher_remark']) 
        : getDefaultClassTeacherRemark($averageScore, $gradeInfo['grade']);
        
    $finalHeadTeacherRemark = !empty(trim($reportMeta['head_teacher_remark'] ?? '')) 
        ? trim($reportMeta['head_teacher_remark']) 
        : getDefaultHeadTeacherRemark($averageScore, $gradeInfo['grade']);
    ?>
    <!-- Teacher & Head Teacher Comments -->
    <div class="remarks-box mb-2">
        <div class="mb-1 pb-1 border-bottom">
            <strong class="text-primary text-uppercase" style="font-size: 0.7rem;">Class Teacher's Remark:</strong>
            <p class="mb-0 fst-italic" style="font-size: 0.78rem; line-height: 1.35; color: #0f172a;">
                "<?= htmlspecialchars($finalClassTeacherRemark) ?>"
            </p>
        </div>
        <div>
            <strong class="text-primary text-uppercase" style="font-size: 0.7rem;">Head Teacher's Endorsement:</strong>
            <p class="mb-0 fst-italic" style="font-size: 0.78rem; line-height: 1.35; color: #0f172a;">
                "<?= htmlspecialchars($finalHeadTeacherRemark) ?>"
            </p>
        </div>
    </div>

    <!-- Official Authentication: Headteacher's Signature & School Seal Only -->
    <div class="signature-box" style="page-break-inside: avoid; break-inside: avoid; margin-top: 8px;">
        <div class="row align-items-end g-3" style="font-size: 0.8rem;">
            <!-- Left Info: Class Teacher & Issue Date -->
            <div class="col-6">
                <div class="p-2 rounded bg-light border border-light-subtle" style="font-size: 0.76rem; background-color: #f8fafc !important;">
                    <div class="mb-1">
                        <span class="text-muted text-uppercase" style="font-size: 0.65rem; font-weight: 700;">Class Teacher:</span>
                        <strong class="text-dark d-block"><?= htmlspecialchars($classTeacherName) ?></strong>
                    </div>
                    <div>
                        <span class="text-muted text-uppercase" style="font-size: 0.65rem; font-weight: 700;">Date of Issue:</span>
                        <strong class="font-monospace text-dark d-block"><?= date('d / m / Y') ?></strong>
                    </div>
                </div>
            </div>

            <!-- Right: Prominent Headteacher's Signature & Stamp Only -->
            <div class="col-6 text-center">
                <div class="signature-slot" style="height: 48px; display: flex; align-items: flex-end; justify-content: center; margin-bottom: 2px;">
                    <?php if (!empty($headTeacherSigUrl)): ?>
                        <img src="<?= $headTeacherSigUrl ?>" alt="Headteacher Signature & Stamp" style="max-height: 46px; max-width: 170px; object-fit: contain;">
                    <?php else: ?>
                        <!-- Blank signature space for manual signing/stamping if no digital signature uploaded -->
                        <div style="height: 46px;"></div>
                    <?php endif; ?>
                </div>
                <div style="border-top: 1.5px solid #0f2942; width: 90%; margin: 0 auto; padding-top: 3px;">
                    <strong style="font-size: 0.82rem; color: #0f2942;">Headteacher's Signature & Stamp</strong>
                    <div class="text-muted" style="font-size: 0.74rem; font-weight: 600;">
                        <?= htmlspecialchars($headTeacherName) ?>
                    </div>
                    <div class="text-muted" style="font-size: 0.65rem;">
                        Official School Endorsement
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
