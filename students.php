<?php
/**
 * KETAN M/A B COMPLEX - Student Directory & Registration
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/session.php';

requireLogin();

$db = getDB();
$role = currentUserRole();
$userId = currentUserId();
$activeYear = getActiveAcademicYear();
$yearId = $activeYear['id'] ?? 1;

// Handle Student Registration or Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $csrf = $_POST['csrf_token'] ?? '';

    if (!verifyCsrfToken($csrf)) {
        flash('danger', 'Security verification failed. Please try again.');
        header('Location: students.php');
        exit;
    }

    if ($action === 'register') {
        $studentId   = trim($_POST['student_id'] ?? '');
        $fullName    = trim($_POST['full_name'] ?? '');
        $gender      = $_POST['gender'] ?? 'Male';
        $dob         = $_POST['date_of_birth'] ?? '';
        $classId     = (int)($_POST['class_id'] ?? 0);
        $academicYear= (int)($_POST['academic_year_id'] ?? $yearId);
        $parentName  = trim($_POST['parent_name'] ?? '');
        $parentPhone = trim($_POST['parent_phone'] ?? '');
        $address     = trim($_POST['address'] ?? '');
        $admissionDate = $_POST['admission_date'] ?: date('Y-m-d');
        $status      = $_POST['status'] ?? 'active';

        // Auto-generate student ID if not provided
        if (empty($studentId)) {
            $studentId = generateNextStudentId($activeYear ? (int)substr($activeYear['year_name'], 0, 4) : date('Y'));
        }

        // Duplicate check
        $check = $db->prepare("SELECT id FROM students WHERE student_id = ?");
        $check->execute([$studentId]);
        if ($check->fetch()) {
            flash('danger', "Duplicate registration error: Student ID '{$studentId}' already exists.");
            header('Location: students.php');
            exit;
        }

        // Handle Photo Upload
        $photoFilename = null;
        if (!empty($_FILES['photo']['name']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
                $photoFilename = 'student_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                if (!is_dir(UPLOAD_DIR)) {
                    mkdir(UPLOAD_DIR, 0777, true);
                }
                move_uploaded_file($_FILES['photo']['tmp_name'], UPLOAD_DIR . DIRECTORY_SEPARATOR . $photoFilename);
            }
        }

        try {
            $stmt = $db->prepare("
                INSERT INTO students (student_id, full_name, gender, date_of_birth, class_id, academic_year_id, parent_name, parent_phone, address, photo, admission_date, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $studentId, $fullName, $gender, $dob, $classId, $academicYear,
                $parentName, $parentPhone, $address, $photoFilename, $admissionDate, $status
            ]);
            $newId = $db->lastInsertId();

            logActivity('STUDENT_REGISTRATION', "Registered student: {$fullName} ({$studentId})", 'students', $newId);
            flash('success', "Student '{$fullName}' registered successfully with ID: {$studentId}!");
        } catch (Exception $e) {
            flash('danger', 'Registration error: ' . $e->getMessage());
        }
        header('Location: students.php');
        exit;

    } elseif ($action === 'update') {
        $id          = (int)$_POST['id'];
        $fullName    = trim($_POST['full_name'] ?? '');
        $gender      = $_POST['gender'] ?? 'Male';
        $dob         = $_POST['date_of_birth'] ?? '';
        $classId     = (int)($_POST['class_id'] ?? 0);
        $parentName  = trim($_POST['parent_name'] ?? '');
        $parentPhone = trim($_POST['parent_phone'] ?? '');
        $address     = trim($_POST['address'] ?? '');
        $status      = $_POST['status'] ?? 'active';

        // Photo replacement if provided
        $photoUpdateSql = "";
        $params = [$fullName, $gender, $dob, $classId, $parentName, $parentPhone, $address, $status];

        if (!empty($_FILES['photo']['name']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
                $photoFilename = 'student_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                if (!is_dir(UPLOAD_DIR)) {
                    mkdir(UPLOAD_DIR, 0777, true);
                }
                move_uploaded_file($_FILES['photo']['tmp_name'], UPLOAD_DIR . DIRECTORY_SEPARATOR . $photoFilename);
                $photoUpdateSql = ", photo = ?";
                $params[] = $photoFilename;
            }
        }
        $params[] = $id;

        try {
            $stmt = $db->prepare("
                UPDATE students 
                SET full_name = ?, gender = ?, date_of_birth = ?, class_id = ?, parent_name = ?, parent_phone = ?, address = ?, status = ? $photoUpdateSql
                WHERE id = ?
            ");
            $stmt->execute($params);

            logActivity('STUDENT_MODIFICATION', "Updated student details for ID: {$id}", 'students', $id);
            flash('success', 'Student details updated successfully.');
        } catch (Exception $e) {
            flash('danger', 'Update error: ' . $e->getMessage());
        }
        header('Location: students.php');
        exit;

    } elseif ($action === 'delete') {
        if (!isAdmin()) {
            flash('danger', 'Only administrators can delete students.');
            header('Location: students.php');
            exit;
        }
        $id = (int)$_POST['id'];
        try {
            $db->beginTransaction();

            // Fetch student info for cleanup and log
            $stInfo = $db->prepare("SELECT full_name, student_id, photo FROM students WHERE id = ?");
            $stInfo->execute([$id]);
            $stu = $stInfo->fetch();

            if ($stu) {
                // Remove photo if present
                if (!empty($stu['photo'])) {
                    $photoPath = UPLOAD_DIR . DIRECTORY_SEPARATOR . $stu['photo'];
                    if (file_exists($photoPath)) {
                        @unlink($photoPath);
                    }
                }

                // Delete related records
                $delMarks = $db->prepare("DELETE FROM marks WHERE student_id = ?");
                $delMarks->execute([$id]);

                $delReports = $db->prepare("DELETE FROM student_term_reports WHERE student_id = ?");
                $delReports->execute([$id]);

                $stmt = $db->prepare("DELETE FROM students WHERE id = ?");
                $stmt->execute([$id]);

                $db->commit();

                logActivity('STUDENT_DELETION', "Deleted student: {$stu['full_name']} ({$stu['student_id']})", 'students', $id);
                flash('success', "Student record for '{$stu['full_name']}' deleted successfully.");
            } else {
                $db->rollBack();
                flash('warning', 'Student not found.');
            }
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            flash('danger', 'Unable to delete student: ' . $e->getMessage());
        }
        header('Location: students.php');
        exit;
    }
}

// Fetch Filters
$classFilter = isset($_GET['class_id']) && $_GET['class_id'] !== '' ? (int)$_GET['class_id'] : null;
$genderFilter = $_GET['gender'] ?? null;
$statusFilter = $_GET['status'] ?? null;
$searchQuery = trim($_GET['q'] ?? '');

$sql = "
    SELECT s.*, c.class_name, COALESCE(y.year_name, ?) as year_name
    FROM students s
    JOIN classes c ON s.class_id = c.id
    LEFT JOIN academic_years y ON s.academic_year_id = y.id
    WHERE 1=1
";
$params = [$activeYear['year_name'] ?? '2025/2026'];

// If teacher, filter to assigned classes (subject allocation or class teacher designation)
if ($role === 'teacher') {
    $assignedClassesStmt = $db->prepare("
        SELECT DISTINCT class_id FROM teacher_assignments WHERE teacher_id = ? AND academic_year_id = ?
        UNION
        SELECT id as class_id FROM classes WHERE class_teacher_id = ?
        UNION
        SELECT class_id FROM class_teachers WHERE teacher_id = ?
    ");
    $assignedClassesStmt->execute([$userId, $yearId, $userId, $userId]);
    $assignedClassIds = $assignedClassesStmt->fetchAll(PDO::FETCH_COLUMN);
    if (!empty($assignedClassIds)) {
        $placeholders = implode(',', array_fill(0, count($assignedClassIds), '?'));
        $sql .= " AND s.class_id IN ($placeholders)";
        $params = array_merge($params, $assignedClassIds);
    }
}

if ($classFilter) {
    $sql .= " AND s.class_id = ?";
    $params[] = $classFilter;
}
if ($genderFilter) {
    $sql .= " AND s.gender = ?";
    $params[] = $genderFilter;
}
if ($statusFilter) {
    $sql .= " AND s.status = ?";
    $params[] = $statusFilter;
}
if (!empty($searchQuery)) {
    $sql .= " AND (s.full_name LIKE ? OR s.student_id LIKE ? OR s.parent_name LIKE ?)";
    $like = "%{$searchQuery}%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$sql .= " ORDER BY c.display_order ASC, s.full_name ASC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$students = $stmt->fetchAll();

// All classes for filter dropdown
$classes = $db->query("SELECT * FROM classes WHERE status = 'active' ORDER BY display_order ASC")->fetchAll();
$suggestedId = generateNextStudentId($activeYear ? (int)substr($activeYear['year_name'], 0, 4) : date('Y'));

$pageTitle = 'Student Directory';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
require_once __DIR__ . '/includes/navbar.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h2 class="h3 fw-bold mb-1" style="font-family: 'Outfit';">Student Management</h2>
        <div class="text-muted small">Manage student admissions, profiles, and class enrollments</div>
    </div>
    <button type="button" class="btn btn-primary-custom" data-bs-toggle="modal" data-bs-target="#registerStudentModal">
        <i class="bi bi-person-plus-fill"></i> Register New Student
    </button>
</div>

<!-- Filter Bar -->
<div class="card-custom mb-4 p-3">
    <form method="GET" action="students.php" class="row g-2 align-items-center">
        <div class="col-md-4">
            <div class="input-group">
                <span class="input-group-text bg-white border-end-0"><i class="bi bi-search"></i></span>
                <input type="text" name="q" class="form-control border-start-0" placeholder="Search name, student ID, parent..." value="<?= htmlspecialchars($searchQuery) ?>">
            </div>
        </div>
        <div class="col-md-3">
            <select name="class_id" class="form-select">
                <option value="">-- All Classes --</option>
                <?php foreach ($classes as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= $classFilter === (int)$c['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($c['class_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <select name="gender" class="form-select">
                <option value="">-- Gender --</option>
                <option value="Male" <?= $genderFilter === 'Male' ? 'selected' : '' ?>>Male</option>
                <option value="Female" <?= $genderFilter === 'Female' ? 'selected' : '' ?>>Female</option>
            </select>
        </div>
        <div class="col-md-2">
            <select name="status" class="form-select">
                <option value="">-- Status --</option>
                <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option>
                <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                <option value="transferred" <?= $statusFilter === 'transferred' ? 'selected' : '' ?>>Transferred</option>
                <option value="graduated" <?= $statusFilter === 'graduated' ? 'selected' : '' ?>>Graduated</option>
            </select>
        </div>
        <div class="col-md-1 d-flex gap-1">
            <button type="submit" class="btn btn-primary w-100"><i class="bi bi-funnel"></i></button>
            <a href="students.php" class="btn btn-outline-secondary" title="Reset Filters"><i class="bi bi-arrow-counterclockwise"></i></a>
        </div>
    </form>
</div>

<!-- Students List Table -->
<div class="card-custom">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <span><i class="bi bi-people-fill text-primary me-2"></i>Enrolled Students (<?= count($students) ?>)</span>
    </div>
    <div class="table-responsive">
        <table class="table table-custom mb-0" id="studentsTable">
            <thead>
                <tr>
                    <th>Photo</th>
                    <th>Student ID</th>
                    <th>Full Name</th>
                    <th>Gender</th>
                    <th>Class</th>
                    <th>Parent / Contact</th>
                    <th>Status</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($students)): ?>
                    <tr>
                        <td colspan="8" class="text-center py-4 text-muted">
                            <i class="bi bi-inbox fs-2 d-block mb-2 text-secondary"></i>
                            No students match your criteria.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($students as $s): 
                        $photoSrc = $s['photo'] ? url('uploads/students/' . $s['photo']) : asset('images/default-avatar.svg');
                    ?>
                        <tr>
                            <td>
                                <img src="<?= $photoSrc ?>" alt="Avatar" class="rounded-circle border" style="width: 38px; height: 38px; object-fit: cover;">
                            </td>
                            <td>
                                <span class="badge bg-light text-dark border font-monospace"><?= htmlspecialchars($s['student_id']) ?></span>
                            </td>
                            <td>
                                <a href="<?= url('student_profile.php?id=' . $s['id']) ?>" class="fw-bold text-dark text-decoration-none">
                                    <?= htmlspecialchars($s['full_name']) ?>
                                </a>
                            </td>
                            <td><?= htmlspecialchars($s['gender']) ?></td>
                            <td><span class="badge bg-primary-subtle text-primary fw-bold"><?= htmlspecialchars($s['class_name']) ?></span></td>
                            <td>
                                <div><?= htmlspecialchars($s['parent_name'] ?? 'N/A') ?></div>
                                <small class="text-muted"><i class="bi bi-telephone me-1"></i><?= htmlspecialchars($s['parent_phone'] ?? '—') ?></small>
                            </td>
                            <td>
                                <span class="status-badge status-<?= $s['status'] ?>">
                                    <?= ucfirst($s['status']) ?>
                                </span>
                            </td>
                            <td class="text-end">
                                <div class="btn-group btn-group-sm">
                                    <a href="<?= url('student_profile.php?id=' . $s['id']) ?>" class="btn btn-outline-primary" title="View Performance Profile">
                                        <i class="bi bi-person-lines-fill"></i> Profile
                                    </a>
                                    <button type="button" class="btn btn-outline-secondary" title="Edit Student"
                                            onclick='openEditStudentModal(<?= htmlspecialchars(json_encode($s, JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, "UTF-8") ?>)'>
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <?php if (isAdmin()): ?>
                                        <button type="button" class="btn btn-outline-danger" 
                                                onclick="confirmDeleteStudent(<?= $s['id'] ?>, '<?= htmlspecialchars(addslashes($s['full_name']), ENT_QUOTES) ?>', '<?= htmlspecialchars(addslashes($s['student_id']), ENT_QUOTES) ?>')" 
                                                title="Delete Student">
                                            <i class="bi bi-trash"></i>
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

<!-- Register Student Modal -->
<div class="modal fade" id="registerStudentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="students.php" enctype="multipart/form-data">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="register">

                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title font-weight-bold" style="font-family: 'Outfit';">
                        <i class="bi bi-person-plus me-2"></i> Register New Student
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Student ID / Index Number</label>
                            <input type="text" name="student_id" class="form-control font-monospace" value="<?= htmlspecialchars($suggestedId) ?>" placeholder="e.g. KCM-2026-0001" required>
                            <div class="form-text small">Auto-generated or input custom index number. Must be unique.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Full Name <span class="text-danger">*</span></label>
                            <input type="text" name="full_name" class="form-control" placeholder="e.g. Kwesi Appiah" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold">Gender <span class="text-danger">*</span></label>
                            <select name="gender" class="form-select" required>
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold">Date of Birth <span class="text-danger">*</span></label>
                            <input type="date" name="date_of_birth" class="form-control" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold">Enrollment Class <span class="text-danger">*</span></label>
                            <select name="class_id" class="form-select" required>
                                <option value="">Select Class</option>
                                <?php foreach ($classes as $c): ?>
                                    <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['class_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Parent / Guardian Name</label>
                            <input type="text" name="parent_name" class="form-control" placeholder="e.g. Mr. Samuel Appiah">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Parent / Guardian Phone</label>
                            <input type="tel" name="parent_phone" class="form-control" placeholder="+233 24 123 4567">
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-bold">Residential Address</label>
                            <input type="text" name="address" class="form-control" placeholder="House number, Street, Area">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Admission Date</label>
                            <input type="date" name="admission_date" class="form-control" value="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Student Photograph</label>
                            <input type="file" name="photo" class="form-control" accept="image/*">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary-custom">Save Student Registration</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Student Modal -->
<div class="modal fade" id="editStudentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="students.php" enctype="multipart/form-data">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" id="edit_id">

                <div class="modal-header bg-dark text-white">
                    <h5 class="modal-title font-weight-bold" style="font-family: 'Outfit';">
                        <i class="bi bi-pencil-square me-2"></i> Edit Student Profile
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Student ID</label>
                            <input type="text" id="edit_student_id" class="form-control font-monospace" readonly disabled>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Full Name <span class="text-danger">*</span></label>
                            <input type="text" name="full_name" id="edit_full_name" class="form-control" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold">Gender <span class="text-danger">*</span></label>
                            <select name="gender" id="edit_gender" class="form-select" required>
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold">Date of Birth</label>
                            <input type="date" name="date_of_birth" id="edit_dob" class="form-control" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold">Class</label>
                            <select name="class_id" id="edit_class_id" class="form-select" required>
                                <?php foreach ($classes as $c): ?>
                                    <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['class_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Parent / Guardian Name</label>
                            <input type="text" name="parent_name" id="edit_parent_name" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Parent Phone</label>
                            <input type="tel" name="parent_phone" id="edit_parent_phone" class="form-control">
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-bold">Residential Address</label>
                            <input type="text" name="address" id="edit_address" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Status</label>
                            <select name="status" id="edit_status" class="form-select">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                                <option value="transferred">Transferred</option>
                                <option value="graduated">Graduated</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Change Photograph</label>
                            <input type="file" name="photo" class="form-control" accept="image/*">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary-custom">Update Student Details</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Student Confirmation Modal -->
<div class="modal fade" id="deleteStudentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="POST" action="students.php">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" id="delete_student_id" value="">
                
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title font-weight-bold" style="font-family: 'Outfit';">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i> Confirm Student Deletion
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4 text-center">
                    <div class="bg-danger-subtle text-danger rounded-circle d-inline-flex align-items-center justify-content-center mb-3" style="width: 64px; height: 64px; font-size: 30px;">
                        <i class="bi bi-trash"></i>
                    </div>
                    <h5 class="fw-bold mb-2">Permanently Delete Student?</h5>
                    <p class="text-muted mb-0">
                        Are you sure you want to permanently delete student <strong id="delete_student_name" class="text-dark"></strong> (<code id="delete_student_code"></code>)?
                    </p>
                    <div class="alert alert-warning text-start small mt-3 mb-0">
                        <i class="bi bi-info-circle me-1"></i> All assessment marks, terminal remarks, and report cards associated with this student will be completely removed.
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
function confirmDeleteStudent(id, name, studentId) {
    document.getElementById('delete_student_id').value = id;
    document.getElementById('delete_student_name').textContent = name;
    document.getElementById('delete_student_code').textContent = studentId;
    new bootstrap.Modal(document.getElementById('deleteStudentModal')).show();
}

function openEditStudentModal(student) {
    document.getElementById('edit_id').value = student.id;
    document.getElementById('edit_student_id').value = student.student_id;
    document.getElementById('edit_full_name').value = student.full_name;
    document.getElementById('edit_gender').value = student.gender;
    document.getElementById('edit_dob').value = student.date_of_birth;
    document.getElementById('edit_class_id').value = student.class_id;
    document.getElementById('edit_parent_name').value = student.parent_name || '';
    document.getElementById('edit_parent_phone').value = student.parent_phone || '';
    document.getElementById('edit_address').value = student.address || '';
    document.getElementById('edit_status').value = student.status;

    new bootstrap.Modal(document.getElementById('editStudentModal')).show();
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
