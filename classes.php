<?php
/**
 * KETAN M/A B COMPLEX - Class Management
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/session.php';

requireRole(['admin', 'headteacher']);

$db = getDB();

// Handle Form Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        flash('danger', 'Security validation failed.');
        header('Location: classes.php');
        exit;
    }

    $action = $_POST['action'];

    if ($action === 'create') {
        $className = trim($_POST['class_name'] ?? '');
        $classCode = trim($_POST['class_code'] ?? '');
        $order = (int)($_POST['display_order'] ?? 0);

        if (empty($className)) {
            flash('danger', 'Class name cannot be empty.');
        } else {
            try {
                $stmt = $db->prepare("INSERT INTO classes (class_name, class_code, display_order, status) VALUES (?, ?, ?, 'active')");
                $stmt->execute([$className, $classCode, $order]);
                logActivity('CREATE_CLASS', "Created class: {$className}", 'classes', $db->lastInsertId());
                flash('success', "Class '{$className}' added successfully.");
            } catch (Exception $e) {
                flash('danger', 'Failed to add class: ' . $e->getMessage());
            }
        }
        header('Location: classes.php');
        exit;

    } elseif ($action === 'update') {
        $id = (int)$_POST['id'];
        $className = trim($_POST['class_name'] ?? '');
        $classCode = trim($_POST['class_code'] ?? '');
        $order = (int)($_POST['display_order'] ?? 0);
        $status = $_POST['status'] ?? 'active';

        try {
            $stmt = $db->prepare("UPDATE classes SET class_name = ?, class_code = ?, display_order = ?, status = ? WHERE id = ?");
            $stmt->execute([$className, $classCode, $order, $status, $id]);
            logActivity('UPDATE_CLASS', "Updated class ID: {$id} ({$className})", 'classes', $id);
            flash('success', "Class '{$className}' updated successfully.");
        } catch (Exception $e) {
            flash('danger', 'Failed to update class: ' . $e->getMessage());
        }
        header('Location: classes.php');
        exit;

    } elseif ($action === 'delete') {
        if (!isAdmin()) {
            flash('danger', 'Only administrators have permission to delete classes.');
            header('Location: classes.php');
            exit;
        }
        $id = (int)$_POST['id'];

        $clsStmt = $db->prepare("SELECT class_name FROM classes WHERE id = ?");
        $clsStmt->execute([$id]);
        $clsName = $clsStmt->fetchColumn();

        try {
            $db->beginTransaction();

            // Find all students in this class to remove their photos
            $stPhotos = $db->prepare("SELECT photo FROM students WHERE class_id = ? AND photo IS NOT NULL AND photo != ''");
            $stPhotos->execute([$id]);
            while ($pRow = $stPhotos->fetch()) {
                $pPath = UPLOAD_DIR . DIRECTORY_SEPARATOR . $pRow['photo'];
                if (file_exists($pPath)) {
                    @unlink($pPath);
                }
            }

            // Clean up related foreign key tables and data
            $db->prepare("DELETE FROM marks WHERE class_id = ?")->execute([$id]);
            $db->prepare("DELETE FROM student_term_reports WHERE class_id = ?")->execute([$id]);
            $db->prepare("DELETE FROM assessment_submissions WHERE class_id = ?")->execute([$id]);
            $db->prepare("DELETE FROM teacher_assignments WHERE class_id = ?")->execute([$id]);
            $db->prepare("DELETE FROM class_teachers WHERE class_id = ?")->execute([$id]);
            $db->prepare("DELETE FROM students WHERE class_id = ?")->execute([$id]);

            $stmt = $db->prepare("DELETE FROM classes WHERE id = ?");
            $stmt->execute([$id]);

            $db->commit();

            logActivity('DELETE_CLASS', "Deleted class '{$clsName}' and all associated records (ID: {$id})", 'classes', $id);
            flash('success', "Class '{$clsName}' and all associated student records, marks, and allocations were permanently deleted from the system.");
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            flash('danger', 'Error deleting class: ' . $e->getMessage());
        }
        header('Location: classes.php');
        exit;
    }
}

// Fetch all classes with student counts
$classes = $db->query("
    SELECT c.*, COUNT(s.id) as student_count
    FROM classes c
    LEFT JOIN students s ON c.id = s.class_id AND s.status = 'active'
    GROUP BY c.id
    ORDER BY c.display_order ASC, c.class_name ASC
")->fetchAll();

$pageTitle = 'Class Management';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
require_once __DIR__ . '/includes/navbar.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h2 class="h3 fw-bold mb-1" style="font-family: 'Outfit';">Class Management</h2>
        <div class="text-muted small">Configure grade levels and classes from KG through JHS 3</div>
    </div>
    <?php if (isAdmin()): ?>
        <button type="button" class="btn btn-primary-custom" data-bs-toggle="modal" data-bs-target="#addClassModal">
            <i class="bi bi-plus-circle me-1"></i> Add New Class
        </button>
    <?php endif; ?>
</div>

<div class="card-custom">
    <div class="card-header bg-white">
        <span><i class="bi bi-building text-primary me-2"></i>All School Classes (<?= count($classes) ?>)</span>
    </div>
    <div class="table-responsive">
        <table class="table table-custom mb-0">
            <thead>
                <tr>
                    <th>Order</th>
                    <th>Class Name</th>
                    <th>Class Code</th>
                    <th>Enrolled Students</th>
                    <th>Status</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($classes as $c): ?>
                    <tr>
                        <td class="text-muted fw-bold"><?= $c['display_order'] ?></td>
                        <td>
                            <strong><?= htmlspecialchars($c['class_name']) ?></strong>
                        </td>
                        <td><code><?= htmlspecialchars($c['class_code'] ?: '—') ?></code></td>
                        <td>
                            <a href="<?= url('students.php?class_id=' . $c['id']) ?>" class="badge bg-primary-subtle text-primary text-decoration-none">
                                <i class="bi bi-people me-1"></i> <?= $c['student_count'] ?> Students
                            </a>
                        </td>
                        <td>
                            <span class="status-badge status-<?= $c['status'] ?>"><?= ucfirst($c['status']) ?></span>
                        </td>
                        <td class="text-end">
                            <div class="btn-group btn-group-sm">
                                <a href="<?= url('broadsheet.php?class_id=' . $c['id']) ?>" class="btn btn-outline-info" title="Class Broadsheet">
                                    <i class="bi bi-table"></i>
                                </a>
                                <?php if (isAdmin()): ?>
                                    <button type="button" class="btn btn-outline-secondary" onclick='openEditClassModal(<?= htmlspecialchars(json_encode($c, JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, "UTF-8") ?>)'>
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <button type="button" class="btn btn-outline-danger" 
                                            onclick="confirmDeleteClass(<?= $c['id'] ?>, '<?= htmlspecialchars(addslashes($c['class_name']), ENT_QUOTES) ?>')" 
                                            title="Delete Class">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Add Class Modal -->
<div class="modal fade" id="addClassModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="classes.php">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="create">

                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title font-weight-bold" style="font-family: 'Outfit';">Add New Class</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Class Name <span class="text-danger">*</span></label>
                        <input type="text" name="class_name" class="form-control" placeholder="e.g. JHS 1, Basic 4" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Class Short Code</label>
                        <input type="text" name="class_code" class="form-control" placeholder="e.g. JHS1, B4">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Display Order</label>
                        <input type="number" name="display_order" class="form-control" value="<?= count($classes) + 1 ?>">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary-custom">Save Class</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Class Modal -->
<div class="modal fade" id="editClassModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="classes.php">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" id="edit_class_id">

                <div class="modal-header bg-dark text-white">
                    <h5 class="modal-title font-weight-bold" style="font-family: 'Outfit';">Edit Class</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Class Name</label>
                        <input type="text" name="class_name" id="edit_class_name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Class Code</label>
                        <input type="text" name="class_code" id="edit_class_code" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Display Order</label>
                        <input type="number" name="display_order" id="edit_display_order" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Status</label>
                        <select name="status" id="edit_status" class="form-select">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary-custom">Update Class</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Class Confirmation Modal -->
<div class="modal fade" id="deleteClassModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="POST" action="classes.php">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" id="delete_class_id" value="">
                
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title font-weight-bold" style="font-family: 'Outfit';">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i> Confirm Class Deletion
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4 text-center">
                    <div class="bg-danger-subtle text-danger rounded-circle d-inline-flex align-items-center justify-content-center mb-3" style="width: 64px; height: 64px; font-size: 30px;">
                        <i class="bi bi-trash"></i>
                    </div>
                    <h5 class="fw-bold mb-2">Permanently Delete Class?</h5>
                    <p class="text-muted mb-0">
                        Are you sure you want to permanently delete class <strong id="delete_class_name" class="text-dark"></strong> from the system?
                    </p>
                    <div class="alert alert-warning text-start small mt-3 mb-0">
                        <i class="bi bi-info-circle me-1"></i> All student records, passport photos, marks, allocations, and terminal reports associated with this class will be permanently removed.
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
function confirmDeleteClass(id, name) {
    document.getElementById('delete_class_id').value = id;
    document.getElementById('delete_class_name').textContent = name;
    new bootstrap.Modal(document.getElementById('deleteClassModal')).show();
}

function openEditClassModal(cls) {
    document.getElementById('edit_class_id').value = cls.id;
    document.getElementById('edit_class_name').value = cls.class_name;
    document.getElementById('edit_class_code').value = cls.class_code || '';
    document.getElementById('edit_display_order').value = cls.display_order;
    document.getElementById('edit_status').value = cls.status;

    new bootstrap.Modal(document.getElementById('editClassModal')).show();
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
