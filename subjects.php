<?php
/**
 * KETAN M/A B COMPLEX - Subject Management
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/session.php';

requireRole(['admin', 'headteacher']);

$db = getDB();

// Handle Form Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        flash('danger', 'Security token invalid.');
        header('Location: subjects.php');
        exit;
    }

    $action = $_POST['action'];

    if ($action === 'create') {
        $name = trim($_POST['subject_name'] ?? '');
        $code = strtoupper(trim($_POST['subject_code'] ?? ''));
        $category = $_POST['category'] ?? 'core';
        $order = (int)($_POST['display_order'] ?? 0);

        if (empty($name) || empty($code)) {
            flash('danger', 'Subject name and code are required.');
        } else {
            try {
                $stmt = $db->prepare("INSERT INTO subjects (subject_name, subject_code, category, display_order, status) VALUES (?, ?, ?, ?, 'active')");
                $stmt->execute([$name, $code, $category, $order]);
                logActivity('CREATE_SUBJECT', "Created subject: {$name} ({$code})", 'subjects', $db->lastInsertId());
                flash('success', "Subject '{$name}' created successfully.");
            } catch (Exception $e) {
                flash('danger', 'Error adding subject: ' . $e->getMessage());
            }
        }
        header('Location: subjects.php');
        exit;

    } elseif ($action === 'update') {
        $id = (int)$_POST['id'];
        $name = trim($_POST['subject_name'] ?? '');
        $code = strtoupper(trim($_POST['subject_code'] ?? ''));
        $category = $_POST['category'] ?? 'core';
        $order = (int)($_POST['display_order'] ?? 0);
        $status = $_POST['status'] ?? 'active';

        try {
            $stmt = $db->prepare("UPDATE subjects SET subject_name = ?, subject_code = ?, category = ?, display_order = ?, status = ? WHERE id = ?");
            $stmt->execute([$name, $code, $category, $order, $status, $id]);
            logActivity('UPDATE_SUBJECT', "Updated subject ID: {$id} ({$name})", 'subjects', $id);
            flash('success', "Subject '{$name}' updated successfully.");
        } catch (Exception $e) {
            flash('danger', 'Error updating subject: ' . $e->getMessage());
        }
        header('Location: subjects.php');
        exit;

    } elseif ($action === 'delete') {
        if (!isAdmin()) {
            flash('danger', 'Only administrators have permission to delete subjects.');
            header('Location: subjects.php');
            exit;
        }
        $id = (int)$_POST['id'];

        $sStmt = $db->prepare("SELECT subject_name, subject_code FROM subjects WHERE id = ?");
        $sStmt->execute([$id]);
        $sub = $sStmt->fetch();

        if (!$sub) {
            flash('warning', 'Subject not found.');
        } else {
            try {
                $db->beginTransaction();

                // Clean up related allocations, submissions, and marks
                $db->prepare("DELETE FROM teacher_assignments WHERE subject_id = ?")->execute([$id]);
                $db->prepare("DELETE FROM marks WHERE subject_id = ?")->execute([$id]);
                $db->prepare("DELETE FROM assessment_submissions WHERE subject_id = ?")->execute([$id]);

                $stmt = $db->prepare("DELETE FROM subjects WHERE id = ?");
                $stmt->execute([$id]);

                $db->commit();

                logActivity('DELETE_SUBJECT', "Deleted subject '{$sub['subject_name']}' ({$sub['subject_code']})", 'subjects', $id);
                flash('success', "Subject '{$sub['subject_name']}' deleted successfully.");
            } catch (Exception $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                flash('danger', 'Error deleting subject: ' . $e->getMessage());
            }
        }
        header('Location: subjects.php');
        exit;
    }
}

// Fetch all subjects
$subjects = $db->query("SELECT * FROM subjects ORDER BY display_order ASC, subject_name ASC")->fetchAll();

$pageTitle = 'Subject Management';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
require_once __DIR__ . '/includes/navbar.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h2 class="h3 fw-bold mb-1" style="font-family: 'Outfit';">Subject Management</h2>
        <div class="text-muted small">Configure curriculum subjects, codes, and core/elective classifications</div>
    </div>
    <?php if (isAdmin()): ?>
        <button type="button" class="btn btn-primary-custom" data-bs-toggle="modal" data-bs-target="#addSubjectModal">
            <i class="bi bi-journal-plus me-1"></i> Add New Subject
        </button>
    <?php endif; ?>
</div>

<div class="card-custom">
    <div class="card-header bg-white">
        <span><i class="bi bi-book text-primary me-2"></i>Curriculum Subjects (<?= count($subjects) ?>)</span>
    </div>
    <div class="table-responsive">
        <table class="table table-custom mb-0">
            <thead>
                <tr>
                    <th>Order</th>
                    <th>Code</th>
                    <th>Subject Name</th>
                    <th>Category</th>
                    <th>Status</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($subjects as $s): ?>
                    <tr>
                        <td class="text-muted fw-bold"><?= $s['display_order'] ?></td>
                        <td><span class="badge bg-secondary font-monospace"><?= htmlspecialchars($s['subject_code']) ?></span></td>
                        <td><strong><?= htmlspecialchars($s['subject_name']) ?></strong></td>
                        <td>
                            <span class="badge <?= $s['category'] === 'core' ? 'bg-primary' : 'bg-info text-dark' ?>">
                                <?= strtoupper($s['category']) ?>
                            </span>
                        </td>
                        <td>
                            <span class="status-badge status-<?= $s['status'] ?>"><?= ucfirst($s['status']) ?></span>
                        </td>
                        <td class="text-end">
                            <?php if (isAdmin()): ?>
                                <div class="btn-group btn-group-sm">
                                    <button type="button" class="btn btn-outline-secondary" onclick='openEditSubjectModal(<?= htmlspecialchars(json_encode($s, JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, "UTF-8") ?>)'>
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <button type="button" class="btn btn-outline-danger" 
                                            onclick="confirmDeleteSubject(<?= $s['id'] ?>, '<?= htmlspecialchars(addslashes($s['subject_name']), ENT_QUOTES) ?>', '<?= htmlspecialchars(addslashes($s['subject_code']), ENT_QUOTES) ?>')" 
                                            title="Delete Subject">
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

<!-- Add Subject Modal -->
<div class="modal fade" id="addSubjectModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="subjects.php">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="create">

                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title font-weight-bold" style="font-family: 'Outfit';">Add New Subject</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Subject Name <span class="text-danger">*</span></label>
                        <input type="text" name="subject_name" class="form-control" placeholder="e.g. Mathematics, Career Technology" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Subject Code (Short) <span class="text-danger">*</span></label>
                        <input type="text" name="subject_code" class="form-control font-monospace text-uppercase" placeholder="e.g. MTH, CTE" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Category</label>
                        <select name="category" class="form-select">
                            <option value="core">Core Subject</option>
                            <option value="elective">Elective Subject</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Display Order</label>
                        <input type="number" name="display_order" class="form-control" value="<?= count($subjects) + 1 ?>">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary-custom">Save Subject</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Subject Modal -->
<div class="modal fade" id="editSubjectModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="subjects.php">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" id="edit_sub_id">

                <div class="modal-header bg-dark text-white">
                    <h5 class="modal-title font-weight-bold" style="font-family: 'Outfit';">Edit Subject</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Subject Name</label>
                        <input type="text" name="subject_name" id="edit_sub_name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Subject Code</label>
                        <input type="text" name="subject_code" id="edit_sub_code" class="form-control font-monospace text-uppercase" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Category</label>
                        <select name="category" id="edit_sub_category" class="form-select">
                            <option value="core">Core Subject</option>
                            <option value="elective">Elective Subject</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Display Order</label>
                        <input type="number" name="display_order" id="edit_sub_order" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Status</label>
                        <select name="status" id="edit_sub_status" class="form-select">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary-custom">Update Subject</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Subject Confirmation Modal -->
<div class="modal fade" id="deleteSubjectModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="POST" action="subjects.php">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" id="delete_subject_id" value="">
                
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title font-weight-bold" style="font-family: 'Outfit';">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i> Confirm Subject Deletion
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4 text-center">
                    <div class="bg-danger-subtle text-danger rounded-circle d-inline-flex align-items-center justify-content-center mb-3" style="width: 64px; height: 64px; font-size: 30px;">
                        <i class="bi bi-trash"></i>
                    </div>
                    <h5 class="fw-bold mb-2">Permanently Delete Subject?</h5>
                    <p class="text-muted mb-0">
                        Are you sure you want to permanently delete subject <strong id="delete_subject_name" class="text-dark"></strong> (<code id="delete_subject_code"></code>)?
                    </p>
                    <div class="alert alert-warning text-start small mt-3 mb-0">
                        <i class="bi bi-info-circle me-1"></i> All teacher allocations, assessment submissions, and marks recorded under this subject will be cleared from the system.
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
function confirmDeleteSubject(id, name, code) {
    document.getElementById('delete_subject_id').value = id;
    document.getElementById('delete_subject_name').textContent = name;
    document.getElementById('delete_subject_code').textContent = code;
    new bootstrap.Modal(document.getElementById('deleteSubjectModal')).show();
}

function openEditSubjectModal(sub) {
    document.getElementById('edit_sub_id').value = sub.id;
    document.getElementById('edit_sub_name').value = sub.subject_name;
    document.getElementById('edit_sub_code').value = sub.subject_code;
    document.getElementById('edit_sub_category').value = sub.category;
    document.getElementById('edit_sub_order').value = sub.display_order;
    document.getElementById('edit_sub_status').value = sub.status;

    new bootstrap.Modal(document.getElementById('editSubjectModal')).show();
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
