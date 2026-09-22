<?php
/**
 * KETAN M/A B COMPLEX - School-wide Performance Analytics
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/session.php';

requireLogin();

$db = getDB();
$activeYear = getActiveAcademicYear();
$activeTerm = getActiveTerm();
$yearId = $activeYear['id'] ?? 1;
$termId = $activeTerm['id'] ?? 1;

// 1. School High-level Aggregates
$overallAvgStmt = $db->prepare("SELECT AVG(total_score) FROM marks WHERE academic_year_id = ? AND term_id = ?");
$overallAvgStmt->execute([$yearId, $termId]);
$schoolAvg = round((float)($overallAvgStmt->fetchColumn() ?: 0), 1);

$totalEntriesStmt = $db->prepare("SELECT COUNT(*) FROM marks WHERE academic_year_id = ? AND term_id = ?");
$totalEntriesStmt->execute([$yearId, $termId]);
$totalEntries = (int)($totalEntriesStmt->fetchColumn() ?: 0);

$passCountStmt = $db->prepare("SELECT COUNT(*) FROM marks WHERE academic_year_id = ? AND term_id = ? AND total_score >= 50");
$passCountStmt->execute([$yearId, $termId]);
$passCount = (int)($passCountStmt->fetchColumn() ?: 0);
$failCount = $totalEntries - $passCount;
$overallPassRate = $totalEntries > 0 ? round(($passCount / $totalEntries) * 100, 1) : 0;
$overallFailRate = $totalEntries > 0 ? round(($failCount / $totalEntries) * 100, 1) : 0;

// 2. Subject Performance & Difficulty (Ranked from lowest average = most difficult)
$subDiffStmt = $db->prepare("
    SELECT s.subject_name, s.subject_code, s.category,
           AVG(m.total_score) as avg_score,
           MIN(m.total_score) as min_score,
           MAX(m.total_score) as max_score,
           COUNT(m.id) as assessed_students
    FROM subjects s
    JOIN marks m ON s.id = m.subject_id
    WHERE m.academic_year_id = ? AND m.term_id = ?
    GROUP BY s.id
    ORDER BY avg_score ASC
");
$subDiffStmt->execute([$yearId, $termId]);
$subjectStats = $subDiffStmt->fetchAll();

// 3. Top 10 Students School-wide
$top10Stmt = $db->prepare("
    SELECT s.id, s.student_id, s.full_name, s.gender, c.class_name,
           AVG(m.total_score) as avg_score,
           SUM(m.total_score) as total_marks,
           COUNT(m.id) as subject_count
    FROM students s
    JOIN classes c ON s.class_id = c.id
    JOIN marks m ON s.id = m.student_id
    WHERE m.academic_year_id = ? AND m.term_id = ?
    GROUP BY s.id
    ORDER BY avg_score DESC
    LIMIT 10
");
$top10Stmt->execute([$yearId, $termId]);
$top10Students = $top10Stmt->fetchAll();

// 4. Students Requiring Academic Support (Intervention List: Avg < 50%)
$supportStmt = $db->prepare("
    SELECT s.id, s.student_id, s.full_name, s.gender, c.class_name, s.parent_name, s.parent_phone,
           AVG(m.total_score) as avg_score,
           SUM(CASE WHEN m.total_score < 50 THEN 1 ELSE 0 END) as failed_subjects,
           COUNT(m.id) as total_subjects
    FROM students s
    JOIN classes c ON s.class_id = c.id
    JOIN marks m ON s.id = m.student_id
    WHERE m.academic_year_id = ? AND m.term_id = ?
    GROUP BY s.id
    HAVING avg_score < 50 OR failed_subjects >= 2
    ORDER BY avg_score ASC
");
$supportStmt->execute([$yearId, $termId]);
$supportStudents = $supportStmt->fetchAll();

$pageTitle = 'Performance Analytics';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
require_once __DIR__ . '/includes/navbar.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h2 class="h3 fw-bold mb-1" style="font-family: 'Outfit';">Performance Analytics & Intervention</h2>
        <div class="text-muted small">
            School-wide academic metrics for <strong><?= htmlspecialchars($activeYear['year_name'] ?? '') ?> — <?= htmlspecialchars($activeTerm['term_name'] ?? '') ?></strong>
        </div>
    </div>
</div>

<!-- Key Performance Indicators -->
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-lg-3">
        <div class="stat-card">
            <div>
                <div class="stat-title">School Average</div>
                <div class="stat-value text-primary"><?= $schoolAvg ?>%</div>
                <div class="stat-meta text-muted">Across all departments</div>
            </div>
            <div class="stat-icon-wrapper stat-icon-primary"><i class="bi bi-mortarboard-fill"></i></div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="stat-card">
            <div>
                <div class="stat-title">Overall Pass Rate</div>
                <div class="stat-value text-success"><?= $overallPassRate ?>%</div>
                <div class="stat-meta text-success"><i class="bi bi-arrow-up"></i> Passing grade (&gt;= 50%)</div>
            </div>
            <div class="stat-icon-wrapper stat-icon-success"><i class="bi bi-check-circle-fill"></i></div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="stat-card">
            <div>
                <div class="stat-title">Failure Rate</div>
                <div class="stat-value text-danger"><?= $overallFailRate ?>%</div>
                <div class="stat-meta text-danger"><i class="bi bi-exclamation-circle"></i> Requires intervention</div>
            </div>
            <div class="stat-icon-wrapper bg-danger-subtle text-danger"><i class="bi bi-x-circle-fill"></i></div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="stat-card">
            <div>
                <div class="stat-title">Support Cases</div>
                <div class="stat-value text-warning"><?= count($supportStudents) ?></div>
                <div class="stat-meta text-muted">Students identified</div>
            </div>
            <div class="stat-icon-wrapper stat-icon-warning"><i class="bi bi-person-exclamation"></i></div>
        </div>
    </div>
</div>

<!-- Subject Difficulty Breakdown Chart & Table -->
<div class="row g-4 mb-4">
    <div class="col-lg-6">
        <div class="card-custom h-100">
            <div class="card-header bg-white">
                <span><i class="bi bi-bar-chart-steps text-primary me-2"></i>Subject Average Breakdown</span>
            </div>
            <div class="card-body">
                <div style="height: 320px;">
                    <canvas id="subjectAvgChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card-custom h-100">
            <div class="card-header bg-white">
                <span><i class="bi bi-lightning-charge-fill text-warning me-2"></i>Subject Difficulty Ranking (Lowest Average First)</span>
            </div>
            <div class="table-responsive">
                <table class="table table-custom mb-0">
                    <thead>
                        <tr>
                            <th>Subject</th>
                            <th class="text-center">Lowest</th>
                            <th class="text-center">Highest</th>
                            <th class="text-end">Average</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($subjectStats as $ss): ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($ss['subject_name']) ?></strong>
                                    <span class="badge bg-light text-dark border ms-1"><?= htmlspecialchars($ss['subject_code']) ?></span>
                                </td>
                                <td class="text-center text-danger font-monospace"><?= round($ss['min_score'], 0) ?></td>
                                <td class="text-center text-success font-monospace"><?= round($ss['max_score'], 0) ?></td>
                                <td class="text-end fw-bold font-monospace <?= $ss['avg_score'] < 50 ? 'text-danger' : 'text-primary' ?>">
                                    <?= round($ss['avg_score'], 1) ?>%
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Top 10 Students Section -->
<div class="card-custom mb-4">
    <div class="card-header bg-white">
        <span><i class="bi bi-trophy-fill text-warning me-2"></i>Top 10 Students Across KETAN M/A B COMPLEX</span>
    </div>
    <div class="table-responsive">
        <table class="table table-custom mb-0">
            <thead>
                <tr>
                    <th style="width: 50px;">Rank</th>
                    <th>Student ID</th>
                    <th>Full Name</th>
                    <th>Gender</th>
                    <th>Class</th>
                    <th>Total Marks</th>
                    <th class="text-end">Terminal Average</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($top10Students)): ?>
                    <tr><td colspan="7" class="text-center py-4 text-muted">No assessment results available.</td></tr>
                <?php else: ?>
                    <?php foreach ($top10Students as $idx => $st): ?>
                        <tr>
                            <td class="text-center fw-bold">
                                <?php if ($idx === 0): ?>
                                    <span class="badge bg-warning text-dark"><i class="bi bi-trophy-fill"></i> 1st</span>
                                <?php elseif ($idx === 1): ?>
                                    <span class="badge bg-secondary">2nd</span>
                                <?php elseif ($idx === 2): ?>
                                    <span class="badge bg-danger">3rd</span>
                                <?php else: ?>
                                    <?= $idx + 1 ?>th
                                <?php endif; ?>
                            </td>
                            <td><span class="badge bg-light text-dark border font-monospace"><?= htmlspecialchars($st['student_id']) ?></span></td>
                            <td>
                                <a href="<?= url('student_profile.php?id=' . $st['id']) ?>" class="fw-bold text-dark text-decoration-none">
                                    <?= htmlspecialchars($st['full_name']) ?>
                                </a>
                            </td>
                            <td><?= htmlspecialchars($st['gender']) ?></td>
                            <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($st['class_name']) ?></span></td>
                            <td class="font-monospace"><?= number_format($st['total_marks'], 1) ?></td>
                            <td class="text-end font-monospace fw-bold text-success fs-6"><?= round($st['avg_score'], 1) ?>%</td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Academic Support / Intervention List -->
<div class="card-custom mb-4 border-danger">
    <div class="card-header bg-danger text-white">
        <span><i class="bi bi-shield-exclamation me-2"></i>Intervention List: Students Requiring Academic Support</span>
    </div>
    <div class="table-responsive">
        <table class="table table-custom mb-0">
            <thead>
                <tr>
                    <th>Student ID</th>
                    <th>Full Name</th>
                    <th>Class</th>
                    <th>Parent / Guardian</th>
                    <th>Phone Contact</th>
                    <th>Failed Subjects</th>
                    <th class="text-end">Average Score</th>
                    <th class="text-center">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($supportStudents)): ?>
                    <tr><td colspan="8" class="text-center py-4 text-success"><i class="bi bi-check2-circle me-1"></i> No students currently falling behind academically.</td></tr>
                <?php else: ?>
                    <?php foreach ($supportStudents as $su): ?>
                        <tr>
                            <td><span class="badge bg-light text-dark border font-monospace"><?= htmlspecialchars($su['student_id']) ?></span></td>
                            <td>
                                <a href="<?= url('student_profile.php?id=' . $su['id']) ?>" class="fw-bold text-danger text-decoration-none">
                                    <?= htmlspecialchars($su['full_name']) ?>
                                </a>
                            </td>
                            <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($su['class_name']) ?></span></td>
                            <td><?= htmlspecialchars($su['parent_name'] ?? 'N/A') ?></td>
                            <td><a href="tel:<?= htmlspecialchars($su['parent_phone'] ?? '') ?>"><?= htmlspecialchars($su['parent_phone'] ?? '—') ?></a></td>
                            <td class="text-danger fw-bold"><?= $su['failed_subjects'] ?> / <?= $su['total_subjects'] ?></td>
                            <td class="text-end font-monospace fw-bold text-danger fs-6"><?= round($su['avg_score'], 1) ?>%</td>
                            <td class="text-center">
                                <a href="<?= url('student_profile.php?id=' . $su['id']) ?>" class="btn btn-sm btn-outline-danger">
                                    Profile &amp; Remarks
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script src="<?= asset('js/charts.js') ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const subLabels = <?= json_encode(array_column($subjectStats, 'subject_name')) ?>;
    const subAverages = <?= json_encode(array_map(fn($v) => round((float)$v['avg_score'], 1), $subjectStats)) ?>;
    SchoolCharts.renderHorizontalBar('subjectAvgChart', subLabels, subAverages);
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
