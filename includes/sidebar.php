<?php
/**
 * KETAN M/A B COMPLEX - Sidebar Component
 */
$currentPage = basename($_SERVER['SCRIPT_NAME'] ?? '');
$role = currentUserRole();
$myAssignedClass = ($role === 'teacher') ? getTeacherAssignedClass(currentUserId()) : null;
?>
<aside class="app-sidebar no-print">
    <!-- Sidebar Header -->
    <div class="sidebar-header">
        <img src="<?= getSchoolLogoUrl() ?>" alt="KETAN M/A B COMPLEX Crest" class="sidebar-logo">
        <div class="sidebar-brand-text">
            <span class="sidebar-brand-title" style="font-size: 0.88rem; letter-spacing: 0.5px;">KETAN M/A B COMPLEX</span>
            <span class="sidebar-brand-subtitle">BASIC SCHOOL SBA</span>
        </div>
    </div>

    <!-- Navigation Menu -->
    <div class="sidebar-menu">
        <!-- Dashboard -->
        <a href="<?= url('dashboard.php') ?>" class="nav-link-custom <?= $currentPage === 'dashboard.php' ? 'active' : '' ?>">
            <i class="bi bi-grid-1x2-fill"></i>
            <span>Dashboard</span>
        </a>

        <?php if ($role === 'admin'): ?>
            <!-- Administration (Requirement 13) -->
            <div class="menu-category">Administration</div>
            <a href="<?= url('teachers.php') ?>" class="nav-link-custom <?= $currentPage === 'teachers.php' ? 'active' : '' ?>">
                <i class="bi bi-person-badge"></i>
                <span>Teachers</span>
            </a>
            <a href="<?= url('classes.php') ?>" class="nav-link-custom <?= $currentPage === 'classes.php' ? 'active' : '' ?>">
                <i class="bi bi-building"></i>
                <span>Classes</span>
            </a>
            <a href="<?= url('class_teachers.php') ?>" class="nav-link-custom <?= $currentPage === 'class_teachers.php' ? 'active' : '' ?>">
                <i class="bi bi-person-check-fill"></i>
                <span>Class Teacher Assignment</span>
            </a>
            <a href="<?= url('teacher_signatures.php') ?>" class="nav-link-custom <?= $currentPage === 'teacher_signatures.php' ? 'active' : '' ?>">
                <i class="bi bi-pen-fill"></i>
                <span>Teacher Signatures</span>
            </a>
            <a href="<?= url('students.php') ?>" class="nav-link-custom <?= in_array($currentPage, ['students.php', 'student_profile.php']) ? 'active' : '' ?>">
                <i class="bi bi-mortarboard"></i>
                <span>Students</span>
            </a>
            <a href="<?= url('settings.php') ?>" class="nav-link-custom <?= $currentPage === 'settings.php' ? 'active' : '' ?>">
                <i class="bi bi-sliders"></i>
                <span>System Settings</span>
            </a>

            <!-- Academic Structure -->
            <div class="menu-category">Academic Structure</div>
            <a href="<?= url('academic_terms.php') ?>" class="nav-link-custom <?= $currentPage === 'academic_terms.php' ? 'active' : '' ?>">
                <i class="bi bi-calendar3"></i>
                <span>Years & Terms</span>
            </a>
            <a href="<?= url('subjects.php') ?>" class="nav-link-custom <?= $currentPage === 'subjects.php' ? 'active' : '' ?>">
                <i class="bi bi-book"></i>
                <span>Subjects</span>
            </a>
            <a href="<?= url('assignments.php') ?>" class="nav-link-custom <?= $currentPage === 'assignments.php' ? 'active' : '' ?>">
                <i class="bi bi-person-workspace"></i>
                <span>Subject Allocation</span>
            </a>

        <?php elseif ($role === 'headteacher'): ?>
            <div class="menu-category">School Management</div>
            <a href="<?= url('class_teachers.php') ?>" class="nav-link-custom <?= $currentPage === 'class_teachers.php' ? 'active' : '' ?>">
                <i class="bi bi-person-check-fill"></i>
                <span>Class Teachers</span>
            </a>
            <a href="<?= url('students.php') ?>" class="nav-link-custom <?= in_array($currentPage, ['students.php', 'student_profile.php']) ? 'active' : '' ?>">
                <i class="bi bi-mortarboard"></i>
                <span>Students</span>
            </a>
            <a href="<?= url('teachers.php') ?>" class="nav-link-custom <?= $currentPage === 'teachers.php' ? 'active' : '' ?>">
                <i class="bi bi-person-badge"></i>
                <span>Teachers</span>
            </a>
            <a href="<?= url('classes.php') ?>" class="nav-link-custom <?= $currentPage === 'classes.php' ? 'active' : '' ?>">
                <i class="bi bi-building"></i>
                <span>Classes</span>
            </a>
        <?php else: /* Teacher */ ?>
            <?php if ($myAssignedClass): ?>
                <div class="menu-category">My Class (<?= htmlspecialchars($myAssignedClass['class_name']) ?>)</div>
                <a href="<?= url('students.php?class_id=' . $myAssignedClass['id']) ?>" class="nav-link-custom <?= (in_array($currentPage, ['students.php', 'student_profile.php']) && ($_GET['class_id'] ?? '') == $myAssignedClass['id']) ? 'active' : '' ?>">
                    <i class="bi bi-people-fill text-warning"></i>
                    <span>My Class Students</span>
                </a>
            <?php else: ?>
                <div class="menu-category">My Classes</div>
                <a href="<?= url('students.php') ?>" class="nav-link-custom <?= in_array($currentPage, ['students.php', 'student_profile.php']) ? 'active' : '' ?>">
                    <i class="bi bi-people"></i>
                    <span>My Students</span>
                </a>
            <?php endif; ?>
        <?php endif; ?>

        <!-- SBA & Marks Entry -->
        <div class="menu-category">SBA & Assessments</div>
        <a href="<?= url('marks.php') ?>" class="nav-link-custom <?= $currentPage === 'marks.php' ? 'active' : '' ?>">
            <i class="bi bi-pencil-square"></i>
            <span>Mark Entry</span>
        </a>
        <a href="<?= url('bulk_upload.php') ?>" class="nav-link-custom <?= $currentPage === 'bulk_upload.php' ? 'active' : '' ?>">
            <i class="bi bi-file-earmark-spreadsheet"></i>
            <span>Bulk Upload</span>
        </a>

        <?php if (in_array($role, ['admin', 'headteacher'])): ?>
            <a href="<?= url('approvals.php') ?>" class="nav-link-custom <?= $currentPage === 'approvals.php' ? 'active' : '' ?>">
                <i class="bi bi-check2-circle"></i>
                <span>Approvals</span>
                <?php
                // Pending approvals badge
                try {
                    $db = getDB();
                    $pendingCount = $db->query("SELECT COUNT(*) FROM assessment_submissions WHERE status = 'submitted'")->fetchColumn();
                    if ($pendingCount > 0):
                ?>
                    <span class="badge rounded-pill bg-warning text-dark ms-auto"><?= $pendingCount ?></span>
                <?php
                    endif;
                } catch (Exception $e) {}
                ?>
            </a>
        <?php endif; ?>

        <!-- Reports & Performance -->
        <div class="menu-category">Reports & Analytics</div>
        <a href="<?= url('broadsheet.php') ?>" class="nav-link-custom <?= $currentPage === 'broadsheet.php' ? 'active' : '' ?>">
            <i class="bi bi-table"></i>
            <span>Class Broadsheet</span>
        </a>
        <a href="<?= url('report_card.php') ?>" class="nav-link-custom <?= $currentPage === 'report_card.php' ? 'active' : '' ?>">
            <i class="bi bi-award"></i>
            <span>Report Cards</span>
        </a>
        <a href="<?= url('analytics.php') ?>" class="nav-link-custom <?= $currentPage === 'analytics.php' ? 'active' : '' ?>">
            <i class="bi bi-graph-up-arrow"></i>
            <span>Performance Analytics</span>
        </a>


    </div>

    <!-- Sidebar Footer -->
    <div class="sidebar-footer">
        <div>
            <div style="font-size: 0.8rem; font-weight: 600; color: #f8fafc; line-height: 1.2;">
                <?= htmlspecialchars($user['full_name'] ?? 'User') ?>
            </div>
            <span class="user-badge-role"><?= strtoupper($role ?? 'GUEST') ?></span>
        </div>
        <a href="<?= url('logout.php') ?>" title="Log Out" class="text-danger" style="font-size: 1.2rem;">
            <i class="bi bi-box-arrow-right"></i>
        </a>
    </div>
</aside>
