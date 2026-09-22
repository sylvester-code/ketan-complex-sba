<?php
/**
 * KETAN M/A B COMPLEX - Academic Years & Terms Management
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/session.php';

requireRole(['admin', 'headteacher']);

$db = getDB();

// Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        flash('danger', 'Security token invalid.');
        header('Location: academic_terms.php');
        exit;
    }

    $action = $_POST['action'];

    if ($action === 'create_year') {
        $yearName = trim($_POST['year_name'] ?? '');
        $startDate = $_POST['start_date'] ?: null;
        $endDate   = $_POST['end_date'] ?: null;
        $makeActive = !empty($_POST['make_active']) ? 1 : 0;

        if (empty($yearName)) {
            flash('danger', 'Academic Year name is required (e.g. 2025/2026).');
        } else {
            try {
                if ($makeActive) {
                    $db->exec("UPDATE academic_years SET is_active = 0");
                }
                $stmt = $db->prepare("INSERT INTO academic_years (year_name, is_active, start_date, end_date) VALUES (?, ?, ?, ?)");
                $stmt->execute([$yearName, $makeActive, $startDate, $endDate]);
                $yearId = $db->lastInsertId();

                // Automatically generate default terms for this new year
                $terms = ['Term 1', 'Term 2', 'Term 3'];
                $termStmt = $db->prepare("INSERT INTO terms (academic_year_id, term_name, is_active) VALUES (?, ?, ?)");
                foreach ($terms as $idx => $tName) {
                    $termStmt->execute([$yearId, $tName, ($makeActive && $idx === 0) ? 1 : 0]);
                }

                logActivity('CREATE_ACADEMIC_YEAR', "Created academic year: {$yearName}", 'academic_years', $yearId);
                flash('success', "Academic year '{$yearName}' and its 3 terms created successfully.");
            } catch (Exception $e) {
                flash('danger', 'Error adding academic year: ' . $e->getMessage());
            }
        }
        header('Location: academic_terms.php');
        exit;

    } elseif ($action === 'set_active_year') {
        $id = (int)$_POST['id'];
        try {
            $db->exec("UPDATE academic_years SET is_active = 0");
            $stmt = $db->prepare("UPDATE academic_years SET is_active = 1 WHERE id = ?");
            $stmt->execute([$id]);

            // Set Term 1 of that year active as default if none active
            $db->exec("UPDATE terms SET is_active = 0");
            $termStmt = $db->prepare("UPDATE terms SET is_active = 1 WHERE academic_year_id = ? ORDER BY id ASC LIMIT 1");
            $termStmt->execute([$id]);

            logActivity('SET_ACTIVE_YEAR', "Activated academic year ID {$id}", 'academic_years', $id);
            flash('success', 'Active academic session switched successfully.');
        } catch (Exception $e) {
            flash('danger', 'Error activating year: ' . $e->getMessage());
        }
        header('Location: academic_terms.php');
        exit;

    } elseif ($action === 'set_active_term') {
        $termId = (int)$_POST['term_id'];
        try {
            // Find parent year
            $parentYear = $db->prepare("SELECT academic_year_id FROM terms WHERE id = ?");
            $parentYear->execute([$termId]);
            $yearId = $parentYear->fetchColumn();

            if ($yearId) {
                // Ensure parent year is active
                $db->exec("UPDATE academic_years SET is_active = 0");
                $db->prepare("UPDATE academic_years SET is_active = 1 WHERE id = ?")->execute([$yearId]);

                // Ensure only this term is active
                $db->exec("UPDATE terms SET is_active = 0");
                $db->prepare("UPDATE terms SET is_active = 1 WHERE id = ?")->execute([$termId]);

                logActivity('SET_ACTIVE_TERM', "Activated term ID {$termId}", 'terms', $termId);
                flash('success', 'Active term switched successfully.');
            }
        } catch (Exception $e) {
            flash('danger', 'Error activating term: ' . $e->getMessage());
        }
        header('Location: academic_terms.php');
        exit;

    } elseif ($action === 'update_term_dates') {
        $termId = (int)$_POST['term_id'];
        $nextStart = $_POST['next_term_start_date'] ?: null;
        try {
            $stmt = $db->prepare("UPDATE terms SET next_term_start_date = ? WHERE id = ?");
            $stmt->execute([$nextStart, $termId]);
            flash('success', 'Term start date saved.');
        } catch (Exception $e) {
            flash('danger', 'Error updating date: ' . $e->getMessage());
        }
        header('Location: academic_terms.php');
        exit;

    } elseif ($action === 'delete_year') {
        if (!isAdmin()) {
            flash('danger', 'Only administrators have permission to delete academic years.');
            header('Location: academic_terms.php');
            exit;
        }

        $id = (int)$_POST['id'];

        $yStmt = $db->prepare("SELECT year_name, is_active FROM academic_years WHERE id = ?");
        $yStmt->execute([$id]);
        $targetYear = $yStmt->fetch();

        if (!$targetYear) {
            flash('warning', 'Academic year not found.');
        } elseif ($targetYear['is_active']) {
            flash('danger', 'Cannot delete the currently ACTIVE academic year. Please activate another academic year first.');
        } else {
            try {
                $db->beginTransaction();

                $db->prepare("DELETE FROM marks WHERE academic_year_id = ?")->execute([$id]);
                $db->prepare("DELETE FROM student_term_reports WHERE academic_year_id = ?")->execute([$id]);
                $db->prepare("DELETE FROM assessment_submissions WHERE academic_year_id = ?")->execute([$id]);
                $db->prepare("DELETE FROM teacher_assignments WHERE academic_year_id = ?")->execute([$id]);
                $db->prepare("DELETE FROM class_teachers WHERE academic_year_id = ?")->execute([$id]);
                $db->prepare("DELETE FROM terms WHERE academic_year_id = ?")->execute([$id]);
                $db->prepare("DELETE FROM academic_years WHERE id = ?")->execute([$id]);

                $db->commit();

                logActivity('DELETE_ACADEMIC_YEAR', "Deleted academic year '{$targetYear['year_name']}' (ID: {$id})", 'academic_years', $id);
                flash('success', "Academic year '{$targetYear['year_name']}' deleted successfully.");
            } catch (Exception $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                flash('danger', 'Error deleting academic year: ' . $e->getMessage());
            }
        }
        header('Location: academic_terms.php');
        exit;
    }
}

// Fetch all years and terms
$years = $db->query("SELECT * FROM academic_years ORDER BY id DESC")->fetchAll();
$allTerms = $db->query("
    SELECT t.*, y.year_name, y.is_active as year_active 
    FROM terms t 
    JOIN academic_years y ON t.academic_year_id = y.id 
    ORDER BY y.id DESC, t.id ASC
")->fetchAll();

$pageTitle = 'Academic Years & Terms';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
require_once __DIR__ . '/includes/navbar.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h2 class="h3 fw-bold mb-1" style="font-family: 'Outfit';">Academic Sessions & Terms</h2>
        <div class="text-muted small">Manage school calendar, active academic periods, and terminal dates</div>
    </div>
    <?php if (isAdmin()): ?>
        <button type="button" class="btn btn-primary-custom" data-bs-toggle="modal" data-bs-target="#addYearModal">
            <i class="bi bi-calendar-plus me-1"></i> New Academic Year
        </button>
    <?php endif; ?>
</div>

<div class="row g-4">
    <!-- Academic Years Column -->
    <div class="col-lg-5">
        <div class="card-custom">
            <div class="card-header bg-white">
                <span><i class="bi bi-calendar-range text-primary me-2"></i>Academic Years</span>
            </div>
            <div class="table-responsive">
                <table class="table table-custom mb-0">
                    <thead>
                        <tr>
                            <th>Academic Year</th>
                            <th>Status</th>
                            <th class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($years as $y): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($y['year_name']) ?></strong></td>
                                <td>
                                    <?php if ($y['is_active']): ?>
                                        <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i> Current</span>
                                    <?php else: ?>
                                        <span class="badge bg-light text-muted border">Archived</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <?php if (!$y['is_active'] && isAdmin()): ?>
                                        <div class="btn-group btn-group-sm">
                                            <form method="POST" action="academic_terms.php" class="d-inline">
                                                <?= csrfField() ?>
                                                <input type="hidden" name="action" value="set_active_year">
                                                <input type="hidden" name="id" value="<?= $y['id'] ?>">
                                                <button type="submit" class="btn btn-outline-primary" title="Set Active">
                                                    Set Active
                                                </button>
                                            </form>
                                            <button type="button" class="btn btn-outline-danger" 
                                                    onclick="confirmDeleteYear(<?= $y['id'] ?>, '<?= htmlspecialchars(addslashes($y['year_name']), ENT_QUOTES) ?>')" 
                                                    title="Delete Year">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Terms Column -->
    <div class="col-lg-7">
        <div class="card-custom">
            <div class="card-header bg-white">
                <span><i class="bi bi-clock-history text-primary me-2"></i>School Terms (Enforces 1 Active Term)</span>
            </div>
            <div class="table-responsive">
                <table class="table table-custom mb-0">
                    <thead>
                        <tr>
                            <th>Year</th>
                            <th>Term</th>
                            <th>Next Term Resumes</th>
                            <th>Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($allTerms as $t): ?>
                            <tr class="<?= $t['is_active'] ? 'table-warning bg-opacity-25' : '' ?>">
                                <td><?= htmlspecialchars($t['year_name']) ?></td>
                                <td><strong><?= htmlspecialchars($t['term_name']) ?></strong></td>
                                <td>
                                    <form method="POST" action="academic_terms.php" class="d-flex align-items-center gap-1">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="update_term_dates">
                                        <input type="hidden" name="term_id" value="<?= $t['id'] ?>">
                                        <input type="date" name="next_term_start_date" class="form-control form-control-sm" value="<?= htmlspecialchars($t['next_term_start_date'] ?? '') ?>" style="width: 140px;">
                                        <button type="submit" class="btn btn-sm btn-outline-secondary py-0 px-2" title="Save Date"><i class="bi bi-check"></i></button>
                                    </form>
                                </td>
                                <td>
                                    <?php if ($t['is_active']): ?>
                                        <span class="badge bg-success"><i class="bi bi-broadcast me-1"></i> ACTIVE</span>
                                    <?php else: ?>
                                        <span class="badge bg-light text-muted border">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <?php if (!$t['is_active'] && isAdmin()): ?>
                                        <form method="POST" action="academic_terms.php" class="d-inline">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="set_active_term">
                                            <input type="hidden" name="term_id" value="<?= $t['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-success">
                                                Make Active
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Add Year Modal -->
<div class="modal fade" id="addYearModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="academic_terms.php">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="create_year">

                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title font-weight-bold" style="font-family: 'Outfit';">Create Academic Session</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Academic Year Name <span class="text-danger">*</span></label>
                        <input type="text" name="year_name" class="form-control" placeholder="e.g. 2026/2027" required>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label small fw-bold">Session Start Date</label>
                            <input type="date" name="start_date" class="form-control">
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-bold">Session End Date</label>
                            <input type="date" name="end_date" class="form-control">
                        </div>
                    </div>
                    <div class="form-check mt-3">
                        <input class="form-check-input" type="checkbox" name="make_active" id="makeActiveCheck" value="1">
                        <label class="form-check-label fw-bold small" for="makeActiveCheck">
                            Set as current active academic session immediately
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary-custom">Create Session</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Year Confirmation Modal -->
<div class="modal fade" id="deleteYearModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="POST" action="academic_terms.php">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete_year">
                <input type="hidden" name="id" id="delete_year_id" value="">
                
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title font-weight-bold" style="font-family: 'Outfit';">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i> Confirm Year Deletion
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4 text-center">
                    <div class="bg-danger-subtle text-danger rounded-circle d-inline-flex align-items-center justify-content-center mb-3" style="width: 64px; height: 64px; font-size: 30px;">
                        <i class="bi bi-trash"></i>
                    </div>
                    <h5 class="fw-bold mb-2">Delete Academic Session?</h5>
                    <p class="text-muted mb-0">
                        Are you sure you want to permanently delete academic session <strong id="delete_year_name" class="text-dark"></strong> and all its associated terms from the system?
                    </p>
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
function confirmDeleteYear(id, name) {
    document.getElementById('delete_year_id').value = id;
    document.getElementById('delete_year_name').textContent = name;
    new bootstrap.Modal(document.getElementById('deleteYearModal')).show();
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
