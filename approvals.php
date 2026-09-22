<?php
/**
 * KETAN M/A B COMPLEX - Assessment Approvals Queue
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/session.php';

requireRole(['admin', 'headteacher']);

$db = getDB();
$userId = currentUserId();
$activeYear = getActiveAcademicYear();
$activeTerm = getActiveTerm();
$yearId = $activeYear['id'] ?? 1;
$termId = $activeTerm['id'] ?? 1;

// Handle Review Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        flash('danger', 'Security token invalid.');
        header('Location: approvals.php');
        exit;
    }

    $subId = (int)$_POST['submission_id'];
    $action = $_POST['action']; // 'approve' or 'reopen'
    $comments = trim($_POST['review_comments'] ?? '');

    try {
        $db->beginTransaction();

        $now = date('Y-m-d H:i:s');
        if ($action === 'approve') {
            // Update submission to approved
            $stmt = $db->prepare("
                UPDATE assessment_submissions 
                SET status = 'approved', reviewed_by = ?, reviewed_at = ?, review_comments = ?
                WHERE id = ?
            ");
            $stmt->execute([$userId, $now, $comments ?: 'Approved by School Management', $subId]);

            // Update associated marks to approved
            $markUpdate = $db->prepare("UPDATE marks SET status = 'approved' WHERE submission_id = ?");
            $markUpdate->execute([$subId]);

            logActivity('APPROVE_ASSESSMENT', "Approved assessment submission ID {$subId}", 'assessment_submissions', $subId);
            flash('success', 'Assessment marks approved successfully! They are now officially included in terminal report cards.');

        } elseif ($action === 'reopen') {
            // Reopen marks so teacher can edit
            $stmt = $db->prepare("
                UPDATE assessment_submissions 
                SET status = 'reopened', reviewed_by = ?, reviewed_at = ?, review_comments = ?
                WHERE id = ?
            ");
            $stmt->execute([$userId, $now, $comments ?: 'Reopened for correction', $subId]);

            $markUpdate = $db->prepare("UPDATE marks SET status = 'reopened' WHERE submission_id = ?");
            $markUpdate->execute([$subId]);

            logActivity('REOPEN_ASSESSMENT', "Reopened assessment submission ID {$subId} with comment: {$comments}", 'assessment_submissions', $subId);
            flash('warning', 'Assessment reopened and returned to teacher for modifications.');
        }

        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        flash('danger', 'Action failed: ' . $e->getMessage());
    }

    header('Location: approvals.php');
    exit;
}

// Fetch submissions
$statusFilter = $_GET['status'] ?? 'submitted';
$classFilter = isset($_GET['class_id']) && $_GET['class_id'] !== '' ? (int)$_GET['class_id'] : null;

$sql = "
    SELECT sub.*, 
           c.class_name, 
           s.subject_name, s.subject_code, 
           u.full_name as teacher_name, 
           rev.full_name as reviewer_name,
           (SELECT COUNT(*) FROM marks m WHERE m.submission_id = sub.id) as marks_count,
           (SELECT AVG(m.total_score) FROM marks m WHERE m.submission_id = sub.id) as class_avg
    FROM assessment_submissions sub
    JOIN classes c ON sub.class_id = c.id
    JOIN subjects s ON sub.subject_id = s.id
    JOIN users u ON sub.teacher_id = u.id
    LEFT JOIN users rev ON sub.reviewed_by = rev.id
    WHERE sub.academic_year_id = ? AND sub.term_id = ?
";
$params = [$yearId, $termId];

if ($statusFilter) {
    $sql .= " AND sub.status = ?";
    $params[] = $statusFilter;
}
if ($classFilter) {
    $sql .= " AND sub.class_id = ?";
    $params[] = $classFilter;
}

$sql .= " ORDER BY sub.submitted_at DESC, sub.id DESC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$submissions = $stmt->fetchAll();

$allClasses = $db->query("SELECT id, class_name FROM classes WHERE status = 'active' ORDER BY display_order ASC")->fetchAll();

$pageTitle = 'Assessment Approvals';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
require_once __DIR__ . '/includes/navbar.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h2 class="h3 fw-bold mb-1" style="font-family: 'Outfit';">Assessment Approvals Queue</h2>
        <div class="text-muted small">
            Review and approve submitted marks from teachers for <?= htmlspecialchars($activeYear['year_name'] ?? '') ?> — <?= htmlspecialchars($activeTerm['term_name'] ?? '') ?>
        </div>
    </div>
</div>

<!-- Filter Tabs -->
<div class="card-custom mb-4 p-3 bg-white">
    <form method="GET" action="approvals.php" class="row g-2 align-items-center">
        <div class="col-md-4">
            <div class="btn-group w-100">
                <a href="approvals.php?status=submitted" class="btn <?= $statusFilter === 'submitted' ? 'btn-warning fw-bold text-dark' : 'btn-outline-secondary' ?>">
                    Pending Review
                </a>
                <a href="approvals.php?status=approved" class="btn <?= $statusFilter === 'approved' ? 'btn-success' : 'btn-outline-secondary' ?>">
                    Approved
                </a>
                <a href="approvals.php?status=reopened" class="btn <?= $statusFilter === 'reopened' ? 'btn-danger' : 'btn-outline-secondary' ?>">
                    Reopened
                </a>
                <a href="approvals.php?status=" class="btn <?= $statusFilter === '' ? 'btn-primary' : 'btn-outline-secondary' ?>">
                    All
                </a>
            </div>
        </div>
        <div class="col-md-6">
            <select name="class_id" class="form-select" onchange="this.form.submit()">
                <option value="">-- All Classes --</option>
                <?php foreach ($allClasses as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= $classFilter === (int)$c['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($c['class_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <a href="approvals.php" class="btn btn-outline-secondary w-100">Reset</a>
        </div>
    </form>
</div>

<!-- Submissions Table -->
<div class="card-custom">
    <div class="card-header bg-white">
        <span><i class="bi bi-inbox text-primary me-2"></i>Assessment Submissions (<?= count($submissions) ?>)</span>
    </div>
    <div class="table-responsive">
        <table class="table table-custom mb-0">
            <thead>
                <tr>
                    <th>Class</th>
                    <th>Subject</th>
                    <th>Teacher</th>
                    <th>Submitted At</th>
                    <th>Students</th>
                    <th>Class Average</th>
                    <th>Status</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($submissions)): ?>
                    <tr>
                        <td colspan="8" class="text-center py-5 text-muted">
                            <i class="bi bi-check-circle text-success fs-1 d-block mb-2"></i>
                            No submissions in this category.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($submissions as $sub): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($sub['class_name']) ?></strong></td>
                            <td>
                                <strong><?= htmlspecialchars($sub['subject_name']) ?></strong>
                                <div class="small text-muted font-monospace"><?= htmlspecialchars($sub['subject_code']) ?></div>
                            </td>
                            <td><?= htmlspecialchars($sub['teacher_name']) ?></td>
                            <td><?= $sub['submitted_at'] ? date('M j, Y - g:ia', strtotime($sub['submitted_at'])) : 'Draft' ?></td>
                            <td><span class="badge bg-light text-dark border"><?= $sub['marks_count'] ?></span></td>
                            <td class="fw-bold text-primary"><?= $sub['class_avg'] ? round($sub['class_avg'], 1) . '%' : '—' ?></td>
                            <td>
                                <span class="status-badge status-<?= $sub['status'] ?>">
                                    <?= ucfirst($sub['status']) ?>
                                </span>
                            </td>
                            <td class="text-end">
                                <div class="btn-group btn-group-sm">
                                    <a href="<?= url("marks.php?class_id={$sub['class_id']}&subject_id={$sub['subject_id']}") ?>" class="btn btn-outline-primary" target="_blank" title="Inspect Marks Sheet">
                                        <i class="bi bi-eye me-1"></i> Inspect Sheet
                                    </a>

                                    <?php if ($sub['status'] === 'submitted'): ?>
                                        <form method="POST" action="approvals.php" class="d-inline" onsubmit="return confirm('Approve these assessment marks for official terminal reports?');">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="approve">
                                            <input type="hidden" name="submission_id" value="<?= $sub['id'] ?>">
                                            <button type="submit" class="btn btn-success" title="Approve">
                                                <i class="bi bi-check-lg"></i> Approve
                                            </button>
                                        </form>

                                        <button type="button" class="btn btn-outline-danger" onclick='openReopenModal(<?= htmlspecialchars(json_encode($sub, JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, "UTF-8") ?>)' title="Reopen / Reject">
                                            <i class="bi bi-arrow-counterclockwise"></i> Reject
                                        </button>
                                    <?php elseif ($sub['status'] === 'approved'): ?>
                                        <button type="button" class="btn btn-outline-warning" onclick='openReopenModal(<?= htmlspecialchars(json_encode($sub, JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, "UTF-8") ?>)' title="Reopen for Corrections">
                                            <i class="bi bi-unlock"></i> Reopen
                                        </button>
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

<!-- Reopen Modal -->
<div class="modal fade" id="reopenModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="approvals.php">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="reopen">
                <input type="hidden" name="submission_id" id="reopen_sub_id">

                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title font-weight-bold" style="font-family: 'Outfit';">
                        <i class="bi bi-arrow-counterclockwise me-1"></i> Reopen Assessment for Correction
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <p class="small text-muted">
                        Reopening this assessment will unlock the marks table for the teacher so they can make corrections and re-submit.
                    </p>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Reason / Review Comments <span class="text-danger">*</span></label>
                        <textarea name="review_comments" class="form-control" rows="3" placeholder="Explain what marks need revision..." required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Reopen Assessment</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openReopenModal(sub) {
    document.getElementById('reopen_sub_id').value = sub.id;
    new bootstrap.Modal(document.getElementById('reopenModal')).show();
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
