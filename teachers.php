<?php
/**
 * KETAN M/A B COMPLEX - Teacher Account Management
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
        header('Location: teachers.php');
        exit;
    }

    $action = $_POST['action'];

    if ($action === 'create') {
        $fullName = trim($_POST['full_name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $phone    = trim($_POST['phone'] ?? '');
        $password = $_POST['password'] ?? '';
        $role     = $_POST['role'] ?? 'teacher';

        if (empty($fullName) || empty($username) || empty($email) || empty($password)) {
            flash('danger', 'All fields marked with an asterisk are required.');
        } else {
            // Check uniqueness
            $check = $db->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
            $check->execute([$username, $email]);
            if ($check->fetch()) {
                flash('danger', 'A user with that username or email already exists.');
            } else {
                try {
                    $hash = password_hash($password, PASSWORD_BCRYPT);
                    $stmt = $db->prepare("
                        INSERT INTO users (full_name, username, email, phone, password_hash, role, status)
                        VALUES (?, ?, ?, ?, ?, ?, 'active')
                    ");
                    $stmt->execute([$fullName, $username, $email, $phone, $hash, $role]);
                    logActivity('CREATE_TEACHER', "Created teacher account for {$fullName} ({$username})", 'users', $db->lastInsertId());
                    flash('success', "Teacher account '{$fullName}' created successfully.");
                } catch (Exception $e) {
                    flash('danger', 'Failed to create teacher: ' . $e->getMessage());
                }
            }
        }
        header('Location: teachers.php');
        exit;

    } elseif ($action === 'update') {
        $id       = (int)$_POST['id'];
        $fullName = trim($_POST['full_name'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $phone    = trim($_POST['phone'] ?? '');
        $role     = $_POST['role'] ?? 'teacher';
        $status   = $_POST['status'] ?? 'active';
        $newPass  = $_POST['password'] ?? '';

        try {
            if (!empty($newPass)) {
                $hash = password_hash($newPass, PASSWORD_BCRYPT);
                $stmt = $db->prepare("UPDATE users SET full_name = ?, email = ?, phone = ?, role = ?, status = ?, password_hash = ? WHERE id = ?");
                $stmt->execute([$fullName, $email, $phone, $role, $status, $hash, $id]);
            } else {
                $stmt = $db->prepare("UPDATE users SET full_name = ?, email = ?, phone = ?, role = ?, status = ? WHERE id = ?");
                $stmt->execute([$fullName, $email, $phone, $role, $status, $id]);
            }
            logActivity('UPDATE_TEACHER', "Updated teacher details for ID: {$id}", 'users', $id);
            flash('success', "Teacher account '{$fullName}' updated.");
        } catch (Exception $e) {
            flash('danger', 'Update error: ' . $e->getMessage());
        }
        header('Location: teachers.php');
        exit;

    } elseif ($action === 'delete') {
        if (!isAdmin()) {
            flash('danger', 'Only administrators have permission to delete accounts.');
            header('Location: teachers.php');
            exit;
        }
        $id = (int)($_POST['id'] ?? 0);

        $userStmt = $db->prepare("SELECT username, full_name, role, signature FROM users WHERE id = ?");
        $userStmt->execute([$id]);
        $targetUser = $userStmt->fetch();

        if (!$targetUser) {
            flash('warning', 'User account not found.');
        } elseif ($id === currentUserId()) {
            flash('danger', 'Security Restriction: You cannot delete your own active administrator account.');
        } elseif (strtolower($targetUser['username']) === 'complex' || ($targetUser['role'] === 'admin' && $id === 1)) {
            flash('danger', 'Security Restriction: The primary Super Administrator account cannot be deleted.');
        } else {
            try {
                $db->beginTransaction();

                // 1. Remove signature file if present
                if (!empty($targetUser['signature'])) {
                    $sigPath = SIGNATURE_UPLOAD_DIR . DIRECTORY_SEPARATOR . basename($targetUser['signature']);
                    if (file_exists($sigPath)) {
                        @unlink($sigPath);
                    }
                }

                // 2. Unassign from classes & allocations
                $db->prepare("UPDATE classes SET class_teacher_id = NULL, class_teacher_assigned_at = NULL WHERE class_teacher_id = ?")->execute([$id]);
                $db->prepare("DELETE FROM class_teachers WHERE teacher_id = ?")->execute([$id]);
                $db->prepare("DELETE FROM teacher_assignments WHERE teacher_id = ?")->execute([$id]);

                // 3. Resolve fallback admin ID for preserving historical mark records
                $adminId = currentUserId() ?: 1;
                $chkAdmin = $db->prepare("SELECT id FROM users WHERE id = ?");
                $chkAdmin->execute([$adminId]);
                if (!$chkAdmin->fetchColumn()) {
                    $adminId = (int)$db->query("SELECT id FROM users WHERE role = 'admin' ORDER BY id ASC LIMIT 1")->fetchColumn();
                }

                if ($adminId) {
                    $db->prepare("UPDATE marks SET entered_by = ? WHERE entered_by = ?")->execute([$adminId, $id]);
                    $db->prepare("UPDATE assessment_submissions SET teacher_id = ? WHERE teacher_id = ?")->execute([$adminId, $id]);
                    $db->prepare("UPDATE assessment_submissions SET reviewed_by = ? WHERE reviewed_by = ?")->execute([$adminId, $id]);
                } else {
                    $db->prepare("DELETE FROM marks WHERE entered_by = ?")->execute([$id]);
                    $db->prepare("DELETE FROM assessment_submissions WHERE teacher_id = ? OR reviewed_by = ?")->execute([$id, $id]);
                }

                // 4. Delete user record
                $stmt = $db->prepare("DELETE FROM users WHERE id = ?");
                $stmt->execute([$id]);

                $db->commit();

                logActivity('DELETE_TEACHER', "Deleted user account '{$targetUser['full_name']}' ({$targetUser['username']})", 'users', $id);
                flash('success', "Teacher account '{$targetUser['full_name']}' was permanently deleted from the system.");
            } catch (Exception $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                flash('danger', 'Error deleting account: ' . $e->getMessage());
            }
        }
        header('Location: teachers.php');
        exit;
    }
}

// Fetch all teachers and headteachers
$teachers = $db->query("
    SELECT u.*, 
        (SELECT COUNT(*) FROM teacher_assignments ta WHERE ta.teacher_id = u.id) as assignment_count,
        (SELECT class_name FROM classes c WHERE c.class_teacher_id = u.id LIMIT 1) as class_teacher_of
    FROM users u 
    WHERE u.role IN ('teacher', 'headteacher')
    ORDER BY u.role ASC, u.full_name ASC
")->fetchAll();

$pageTitle = 'Teacher Management';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
require_once __DIR__ . '/includes/navbar.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h2 class="h3 fw-bold mb-1" style="font-family: 'Outfit';">Teacher & Staff Management</h2>
        <div class="text-muted small">Manage teacher accounts, login credentials, assigned roles, and signatures</div>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= url('teacher_signatures.php') ?>" class="btn btn-outline-secondary">
            <i class="bi bi-pen-fill me-1"></i> Teacher Signatures
        </a>
        <a href="<?= url('class_teachers.php') ?>" class="btn btn-outline-secondary">
            <i class="bi bi-person-check-fill me-1"></i> Class Teacher Allocations
        </a>
        <?php if (isAdmin()): ?>
            <button type="button" class="btn btn-primary-custom" data-bs-toggle="modal" data-bs-target="#addTeacherModal">
                <i class="bi bi-person-plus-fill me-1"></i> Add New Teacher
            </button>
        <?php endif; ?>
    </div>
</div>

<div class="card-custom">
    <div class="card-header bg-white">
        <span><i class="bi bi-person-badge text-primary me-2"></i>School Teaching Staff (<?= count($teachers) ?>)</span>
    </div>
    <div class="table-responsive">
        <table class="table table-custom mb-0 align-middle">
            <thead>
                <tr>
                    <th>Staff Name</th>
                    <th>Username</th>
                    <th>Email / Phone</th>
                    <th>Role & Class</th>
                    <th>Signature</th>
                    <th>Assignments</th>
                    <th>Status</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($teachers)): ?>
                    <tr>
                        <td colspan="8" class="text-center py-5">
                            <div class="text-muted mb-2"><i class="bi bi-person-plus fs-1 text-primary opacity-50"></i></div>
                            <h6 class="fw-bold mb-1">No Teachers or Lecturers Added Yet</h6>
                            <p class="small text-muted mb-3">Begin by creating teacher accounts and assigning their classes and subjects.</p>
                            <?php if (isAdmin()): ?>
                                <button type="button" class="btn btn-primary-custom btn-sm" data-bs-toggle="modal" data-bs-target="#addTeacherModal">
                                    <i class="bi bi-person-plus-fill me-1"></i> Add First Teacher
                                </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($teachers as $t): 
                        $hasSig = !empty($t['signature']) && getTeacherSignatureUrl($t['signature']) !== null;
                    ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars($t['full_name']) ?></strong>
                            </td>
                            <td><code><?= htmlspecialchars($t['username']) ?></code></td>
                            <td>
                                <div><?= htmlspecialchars($t['email']) ?></div>
                                <?php if ($t['phone']): ?>
                                    <div class="small text-muted"><?= htmlspecialchars($t['phone']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge <?= $t['role'] === 'headteacher' ? 'bg-primary' : 'bg-secondary' ?> mb-1">
                                    <?= strtoupper($t['role']) ?>
                                </span>
                                <?php if (!empty($t['class_teacher_of'])): ?>
                                    <div>
                                        <span class="badge bg-success-subtle text-success border border-success-subtle">
                                            <i class="bi bi-award me-1"></i> Class Teacher: <?= htmlspecialchars($t['class_teacher_of']) ?>
                                        </span>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($hasSig): ?>
                                    <a href="<?= url('teacher_signatures.php?q=' . urlencode($t['username'])) ?>" class="badge bg-success-subtle text-success border border-success-subtle text-decoration-none">
                                        <i class="bi bi-check-circle-fill me-1"></i> Uploaded
                                    </a>
                                <?php else: ?>
                                    <a href="<?= url('teacher_signatures.php?q=' . urlencode($t['username'])) ?>" class="badge bg-warning-subtle text-warning border border-warning-subtle text-decoration-none">
                                        <i class="bi bi-upload me-1"></i> Missing
                                    </a>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="<?= url('assignments.php?teacher_id=' . $t['id']) ?>" class="badge bg-light text-dark border text-decoration-none">
                                    <i class="bi bi-book me-1"></i> <?= $t['assignment_count'] ?> Classes/Subjects
                                </a>
                            </td>
                            <td>
                                <span class="status-badge status-<?= $t['status'] ?>"><?= ucfirst($t['status']) ?></span>
                            </td>
                            <td class="text-end">
                                <?php if (isAdmin()): ?>
                                    <div class="btn-group btn-group-sm">
                                        <a href="<?= url('teacher_signatures.php?q=' . urlencode($t['username'])) ?>" class="btn btn-outline-primary" title="Manage Signature">
                                            <i class="bi bi-pen"></i>
                                        </a>
                                        <button type="button" class="btn btn-outline-secondary" onclick='openEditTeacherModal(<?= htmlspecialchars(json_encode($t, JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, "UTF-8") ?>)' title="Edit Details & Password">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <?php if ($t['id'] !== currentUserId() && strtolower($t['username']) !== 'complex' && ($t['role'] !== 'admin' || $t['id'] !== 1)): ?>
                                            <button type="button" class="btn btn-outline-danger" 
                                                    onclick="confirmDeleteTeacher(<?= $t['id'] ?>, '<?= htmlspecialchars(addslashes($t['full_name']), ENT_QUOTES) ?>', '<?= htmlspecialchars(addslashes($t['username']), ENT_QUOTES) ?>')" 
                                                    title="Delete Account">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        <?php endif; ?>
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

<!-- Add Teacher Modal -->
<div class="modal fade" id="addTeacherModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="teachers.php">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="create">

                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title font-weight-bold" style="font-family: 'Outfit';">Create Teacher Account</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Full Name <span class="text-danger">*</span></label>
                        <input type="text" name="full_name" class="form-control" placeholder="e.g. Mr. Kwame Mensah" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Username <span class="text-danger">*</span></label>
                        <input type="text" name="username" class="form-control" placeholder="e.g. kwame.mensah" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Email Address <span class="text-danger">*</span></label>
                        <input type="email" name="email" class="form-control" placeholder="k.mensah@ketancomplex.edu.gh" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Phone Number</label>
                        <input type="tel" name="phone" class="form-control" placeholder="+233 24 000 0000">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">System Role</label>
                        <select name="role" class="form-select">
                            <option value="teacher">Class / Subject Teacher</option>
                            <option value="headteacher">Head Teacher / School Management</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Initial Password <span class="text-danger">*</span></label>
                        <div class="input-group mb-1">
                            <input type="password" id="create_teacher_pass" name="password" class="form-control" placeholder="Create login password" required>
                            <button class="btn btn-outline-secondary" type="button" onclick="togglePassVisibility('create_teacher_pass')">
                                <i class="bi bi-eye" id="create_teacher_pass_icon"></i>
                            </button>
                        </div>
                        <button type="button" class="btn btn-sm btn-light border text-primary" onclick="generateRandomPass('create_teacher_pass')">
                            <i class="bi bi-magic me-1"></i> Generate Secure Password
                        </button>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary-custom">Create Account</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Teacher Modal -->
<div class="modal fade" id="editTeacherModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="teachers.php">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" id="edit_teacher_id">

                <div class="modal-header bg-dark text-white">
                    <h5 class="modal-title font-weight-bold" style="font-family: 'Outfit';">Edit Staff Account</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Full Name</label>
                        <input type="text" name="full_name" id="edit_teacher_name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Email Address</label>
                        <input type="email" name="email" id="edit_teacher_email" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Phone</label>
                        <input type="tel" name="phone" id="edit_teacher_phone" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Role</label>
                        <select name="role" id="edit_teacher_role" class="form-select">
                            <option value="teacher">Teacher</option>
                            <option value="headteacher">Head Teacher</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Status</label>
                        <select name="status" id="edit_teacher_status" class="form-select">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive / Deactivated</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Reset Password (Leave blank to keep current)</label>
                        <div class="input-group mb-1">
                            <input type="password" id="edit_teacher_pass" name="password" class="form-control" placeholder="Enter new password">
                            <button class="btn btn-outline-secondary" type="button" onclick="togglePassVisibility('edit_teacher_pass')">
                                <i class="bi bi-eye" id="edit_teacher_pass_icon"></i>
                            </button>
                        </div>
                        <button type="button" class="btn btn-sm btn-light border text-primary" onclick="generateRandomPass('edit_teacher_pass')">
                            <i class="bi bi-magic me-1"></i> Generate New Password
                        </button>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary-custom">Update Account</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Teacher Confirmation Modal -->
<div class="modal fade" id="deleteTeacherModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="POST" action="teachers.php">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" id="delete_teacher_id" value="">
                
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title font-weight-bold" style="font-family: 'Outfit';">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i> Confirm Account Deletion
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4 text-center">
                    <div class="bg-danger-subtle text-danger rounded-circle d-inline-flex align-items-center justify-content-center mb-3" style="width: 64px; height: 64px; font-size: 30px;">
                        <i class="bi bi-trash"></i>
                    </div>
                    <h5 class="fw-bold mb-2">Permanently Delete Teacher?</h5>
                    <p class="text-muted mb-0">
                        Are you sure you want to permanently delete <strong id="delete_teacher_name" class="text-dark"></strong> (<code id="delete_teacher_username"></code>) from the system?
                    </p>
                    <div class="alert alert-warning text-start small mt-3 mb-0">
                        <i class="bi bi-info-circle me-1"></i> Class teacher assignments and subject allocations for this teacher will be unlinked. Historical marks will be preserved and reassigned to the administrator.
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
function confirmDeleteTeacher(id, name, username) {
    document.getElementById('delete_teacher_id').value = id;
    document.getElementById('delete_teacher_name').textContent = name;
    document.getElementById('delete_teacher_username').textContent = username;
    new bootstrap.Modal(document.getElementById('deleteTeacherModal')).show();
}

function openEditTeacherModal(t) {
    document.getElementById('edit_teacher_id').value = t.id;
    document.getElementById('edit_teacher_name').value = t.full_name;
    document.getElementById('edit_teacher_email').value = t.email;
    document.getElementById('edit_teacher_phone').value = t.phone || '';
    document.getElementById('edit_teacher_role').value = t.role;
    document.getElementById('edit_teacher_status').value = t.status;
    document.getElementById('edit_teacher_pass').value = '';

    new bootstrap.Modal(document.getElementById('editTeacherModal')).show();
}

function togglePassVisibility(inputId) {
    const input = document.getElementById(inputId);
    const icon = document.getElementById(inputId + '_icon');
    if (input.type === 'password') {
        input.type = 'text';
        icon.classList.remove('bi-eye');
        icon.classList.add('bi-eye-slash');
    } else {
        input.type = 'password';
        icon.classList.remove('bi-eye-slash');
        icon.classList.add('bi-eye');
    }
}

function generateRandomPass(inputId) {
    const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#%';
    let pass = '';
    for (let i = 0; i < 10; i++) {
        pass += chars.charAt(Math.floor(Math.random() * chars.length));
    }
    const input = document.getElementById(inputId);
    input.value = pass;
    input.type = 'text';
    const icon = document.getElementById(inputId + '_icon');
    if (icon) {
        icon.classList.remove('bi-eye');
        icon.classList.add('bi-eye-slash');
    }
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
