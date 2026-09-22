<?php
/**
 * KETAN M/A B COMPLEX - Class Performance Broadsheet & Ranking Engine
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/session.php';

requireLogin();

$db = getDB();
$activeYear = getActiveAcademicYear();
$activeTerm = getActiveTerm();

$yearId = isset($_GET['year_id']) ? (int)$_GET['year_id'] : ($activeYear['id'] ?? 1);
$termId = isset($_GET['term_id']) ? (int)$_GET['term_id'] : ($activeTerm['id'] ?? 1);

$allClasses = $db->query("SELECT id, class_name FROM classes WHERE status = 'active' ORDER BY display_order ASC")->fetchAll();
$selectedClassId = isset($_GET['class_id']) ? (int)$_GET['class_id'] : ($allClasses[0]['id'] ?? 0);

// Fetch Class Details
$classInfo = $db->prepare("SELECT * FROM classes WHERE id = ?");
$classInfo->execute([$selectedClassId]);
$currentClass = $classInfo->fetch();

// Fetch Subjects taken by this class or all active subjects
$subjectsStmt = $db->query("SELECT id, subject_name, subject_code FROM subjects WHERE status = 'active' ORDER BY display_order ASC");
$subjects = $subjectsStmt->fetchAll();

// Fetch Students in Class
$studentsStmt = $db->prepare("SELECT id, student_id, full_name, gender FROM students WHERE class_id = ? AND status = 'active' ORDER BY full_name ASC");
$studentsStmt->execute([$selectedClassId]);
$students = $studentsStmt->fetchAll();

// Fetch all marks for this class, year, and term
$marksStmt = $db->prepare("
    SELECT student_id, subject_id, sba_score, exam_score, total_score, grade
    FROM marks
    WHERE class_id = ? AND academic_year_id = ? AND term_id = ?
");
$marksStmt->execute([$selectedClassId, $yearId, $termId]);
$rawMarks = $marksStmt->fetchAll();

$studentMarks = [];
foreach ($rawMarks as $rm) {
    $studentMarks[$rm['student_id']][$rm['subject_id']] = $rm;
}

// Compute Aggregates and Ranking for each student
$studentRanks = [];
foreach ($students as $st) {
    $stId = $st['id'];
    $stScores = $studentMarks[$stId] ?? [];
    
    $total = 0;
    $count = 0;
    foreach ($subjects as $sub) {
        if (isset($stScores[$sub['id']])) {
            $total += (float)$stScores[$sub['id']]['total_score'];
            $count++;
        }
    }
    $avg = $count > 0 ? round($total / $count, 2) : 0;
    $grade = calculateGradeAndRemark($avg);

    $studentRanks[] = [
        'student' => $st,
        'scores'  => $stScores,
        'total'   => $total,
        'count'   => $count,
        'average' => $avg,
        'grade'   => $grade['grade'],
        'status'  => $avg >= 50 ? 'Pass' : 'Fail'
    ];
}

// Sort by Average Descending
usort($studentRanks, function($a, $b) {
    return $b['average'] <=> $a['average'];
});

// Assign Rankings (Handling ties)
$currentRank = 1;
$previousAvg = null;
$skip = 0;
foreach ($studentRanks as $idx => &$item) {
    if ($previousAvg !== null && $item['average'] === $previousAvg) {
        $item['rank'] = $currentRank;
        $skip++;
    } else {
        $currentRank += $skip;
        $item['rank'] = $currentRank;
        $skip = 1;
        $previousAvg = $item['average'];
    }
}
unset($item);

// Class Statistics
$classTotalAvg = 0;
$highestScore = 0;
$lowestScore = 100;
$totalPass = 0;
$studentCount = count($studentRanks);

if ($studentCount > 0) {
    $sumAvg = 0;
    foreach ($studentRanks as $sr) {
        $sumAvg += $sr['average'];
        if ($sr['average'] > $highestScore) $highestScore = $sr['average'];
        if ($sr['average'] < $lowestScore) $lowestScore = $sr['average'];
        if ($sr['status'] === 'Pass') $totalPass++;
    }
    $classTotalAvg = round($sumAvg / $studentCount, 1);
    $passRate = round(($totalPass / $studentCount) * 100, 1);
} else {
    $lowestScore = 0;
    $passRate = 0;
}

// ----------------------------------------------------
// Export Broadsheet to CSV
// ----------------------------------------------------
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $filename = "Broadsheet_" . preg_replace('/[^A-Za-z0-9]/', '_', ($currentClass['class_name'] ?? 'Class')) . ".csv";
    header('Content-Type: text/csv; charset=utf-8');
    header("Content-Disposition: attachment; filename=\"$filename\"");

    $out = fopen('php://output', 'w');
    // Header Line 1
    $header1 = ['Rank', 'Student ID', 'Student Name', 'Gender'];
    foreach ($subjects as $sub) {
        $header1[] = $sub['subject_code'];
    }
    $header1 = array_merge($header1, ['Total', 'Average (%)', 'Grade', 'Status']);
    fputcsv($out, $header1);

    foreach ($studentRanks as $sr) {
        $row = [
            $sr['rank'],
            $sr['student']['student_id'],
            $sr['student']['full_name'],
            $sr['student']['gender']
        ];
        foreach ($subjects as $sub) {
            $m = $sr['scores'][$sub['id']] ?? null;
            $row[] = $m ? $m['total_score'] : '';
        }
        $row[] = $sr['total'];
        $row[] = $sr['average'];
        $row[] = $sr['grade'];
        $row[] = $sr['status'];
        fputcsv($out, $row);
    }
    fclose($out);
    exit;
}

$pageTitle = ($currentClass['class_name'] ?? 'Class') . ' Broadsheet';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
require_once __DIR__ . '/includes/navbar.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2 no-print">
    <div>
        <h2 class="h3 fw-bold mb-1" style="font-family: 'Outfit';">Class Broadsheet & Performance Ranking</h2>
        <div class="text-muted small">
            Master terminal assessment sheet for <strong><?= htmlspecialchars($currentClass['class_name'] ?? '') ?></strong> | 
            <?= htmlspecialchars($activeYear['year_name'] ?? '') ?> — <?= htmlspecialchars($activeTerm['term_name'] ?? '') ?>
        </div>
    </div>
    <div class="d-flex gap-2">
        <button type="button" class="btn btn-warning fw-bold text-dark" onclick="window.print()">
            <i class="bi bi-printer-fill me-1"></i> Print Broadsheet
        </button>
        <a href="<?= url("broadsheet.php?class_id={$selectedClassId}&year_id={$yearId}&term_id={$termId}&export=csv") ?>" class="btn btn-success">
            <i class="bi bi-file-earmark-excel-fill me-1"></i> Export Excel (CSV)
        </a>
    </div>
</div>

<!-- Class Filter Selector (No Print) -->
<div class="card-custom mb-4 p-3 bg-white no-print">
    <form method="GET" action="broadsheet.php" class="row g-2 align-items-center">
        <div class="col-md-5">
            <label class="form-label small fw-bold text-muted text-uppercase">Select Class</label>
            <select name="class_id" class="form-select" onchange="this.form.submit()">
                <?php foreach ($allClasses as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= $selectedClassId === (int)$c['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($c['class_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2 d-flex align-items-end">
            <button type="submit" class="btn btn-primary w-100"><i class="bi bi-arrow-repeat"></i> Load</button>
        </div>
    </form>
</div>

<!-- Class Statistics KPI Cards (No Print) -->
<div class="row g-3 mb-4 no-print">
    <div class="col-sm-6 col-lg-3">
        <div class="stat-card">
            <div>
                <div class="stat-title">Class Average</div>
                <div class="stat-value text-primary"><?= $classTotalAvg ?>%</div>
                <div class="stat-meta text-muted">Across all subjects</div>
            </div>
            <div class="stat-icon-wrapper stat-icon-primary"><i class="bi bi-calculator"></i></div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="stat-card">
            <div>
                <div class="stat-title">Pass Rate</div>
                <div class="stat-value text-success"><?= $passRate ?>%</div>
                <div class="stat-meta text-muted"><?= $totalPass ?> of <?= $studentCount ?> students</div>
            </div>
            <div class="stat-icon-wrapper stat-icon-success"><i class="bi bi-check-circle"></i></div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="stat-card">
            <div>
                <div class="stat-title">Highest Average</div>
                <div class="stat-value text-warning"><?= $highestScore ?>%</div>
                <div class="stat-meta text-muted">Rank 1 Student</div>
            </div>
            <div class="stat-icon-wrapper stat-icon-warning"><i class="bi bi-trophy-fill"></i></div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="stat-card">
            <div>
                <div class="stat-title">Lowest Average</div>
                <div class="stat-value text-danger"><?= $lowestScore ?>%</div>
                <div class="stat-meta text-muted">Needs Intervention</div>
            </div>
            <div class="stat-icon-wrapper bg-danger-subtle text-danger"><i class="bi bi-exclamation-triangle-fill"></i></div>
        </div>
    </div>
</div>

<!-- Print Header (Only in Print) -->
<div class="d-none d-print-block text-center mb-3">
    <h3 class="fw-bold mb-1"><?= strtoupper(htmlspecialchars(getSetting('school_name', 'KETAN M/A B COMPLEX'))) ?></h3>
    <h5 class="mb-1 text-uppercase">Terminal Assessment Broadsheet — <?= htmlspecialchars($currentClass['class_name'] ?? '') ?></h5>
    <div class="small text-muted"><?= htmlspecialchars($activeYear['year_name'] ?? '') ?> | <?= htmlspecialchars($activeTerm['term_name'] ?? '') ?></div>
    <hr class="my-2">
</div>

<!-- Broadsheet Matrix Table -->
<div class="card-custom">
    <div class="table-responsive">
        <table class="table table-bordered table-sm table-hover mb-0" style="font-size: 0.85rem;">
            <thead class="table-dark align-middle text-center">
                <tr>
                    <th style="width: 50px;">Pos</th>
                    <th style="width: 120px;">ID</th>
                    <th class="text-start" style="min-width: 170px;">Student Name</th>
                    <?php foreach ($subjects as $sub): ?>
                        <th title="<?= htmlspecialchars($sub['subject_name']) ?>" style="width: 60px;">
                            <?= htmlspecialchars($sub['subject_code']) ?>
                        </th>
                    <?php endforeach; ?>
                    <th style="width: 80px;">Total</th>
                    <th style="width: 75px;">Avg %</th>
                    <th style="width: 55px;">Grd</th>
                    <th style="width: 70px;">Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($studentRanks)): ?>
                    <tr><td colspan="<?= 7 + count($subjects) ?>" class="text-center py-5 text-muted">No students or assessment marks recorded for this class.</td></tr>
                <?php else: ?>
                    <?php foreach ($studentRanks as $sr): 
                        $st = $sr['student'];
                    ?>
                        <tr class="align-middle">
                            <td class="text-center fw-bold">
                                <?php if ($sr['rank'] <= 3): ?>
                                    <span class="badge <?= $sr['rank'] === 1 ? 'bg-warning text-dark' : ($sr['rank'] === 2 ? 'bg-secondary' : 'bg-danger') ?>">
                                        <?= $sr['rank'] ?>
                                    </span>
                                <?php else: ?>
                                    <?= $sr['rank'] ?>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge bg-light text-dark border font-monospace"><?= htmlspecialchars($st['student_id']) ?></span></td>
                            <td>
                                <a href="<?= url('student_profile.php?id=' . $st['id']) ?>" class="fw-bold text-dark text-decoration-none">
                                    <?= htmlspecialchars($st['full_name']) ?>
                                </a>
                            </td>
                            <?php foreach ($subjects as $sub): 
                                $scoreObj = $sr['scores'][$sub['id']] ?? null;
                            ?>
                                <td class="text-center font-monospace small <?= $scoreObj && (float)$scoreObj['total_score'] < 50 ? 'text-danger fw-bold' : '' ?>">
                                    <?= $scoreObj ? number_format($scoreObj['total_score'], 0) : '—' ?>
                                </td>
                            <?php endforeach; ?>
                            <td class="text-center font-monospace fw-bold"><?= number_format($sr['total'], 0) ?></td>
                            <td class="text-center font-monospace fw-bold text-primary fs-6"><?= $sr['average'] ?></td>
                            <td class="text-center"><span class="grade-badge grade-<?= $sr['grade'] ?>"><?= $sr['grade'] ?></span></td>
                            <td class="text-center">
                                <span class="badge <?= $sr['status'] === 'Pass' ? 'bg-success' : 'bg-danger' ?>">
                                    <?= $sr['status'] ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
