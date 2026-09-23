<?php
/**
 * KETAN M/A B COMPLEX - Topbar & Main Wrapper Component
 */
$user = currentUser();
$term = getActiveTerm();
$year = getActiveAcademicYear();
?>
<main class="app-main">
    <header class="app-topbar no-print">
        <div class="topbar-left">
            <button type="button" class="topbar-toggler" id="sidebarToggle" aria-label="Toggle Sidebar">
                <i class="bi bi-list"></i>
            </button>

            <?php if ($term && $year): ?>
                <div class="term-pill-badge" title="Current Academic Period">
                    <span class="term-pill-dot"></span>
                    <span><?= htmlspecialchars($year['year_name']) ?> — <?= htmlspecialchars($term['term_name']) ?></span>
                </div>
            <?php else: ?>
                <span class="badge bg-danger">No Active Academic Term</span>
            <?php endif; ?>
        </div>

        <div class="topbar-right">
            <!-- Quick Global Student Search -->
            <form action="<?= url('students.php') ?>" method="GET" class="d-none d-md-flex align-items-center">
                <div class="input-group input-group-sm" style="width: 240px;">
                    <input type="text" name="q" class="form-control" placeholder="Search student name..." value="<?= htmlspecialchars($_GET['q'] ?? '') ?>">
                    <button class="btn btn-outline-secondary" type="submit"><i class="bi bi-search"></i></button>
                </div>
            </form>

            <!-- User Profile Dropdown -->
            <div class="dropdown">
                <button class="user-profile-btn dropdown-toggle border-0" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <img src="<?= asset('images/default-avatar.svg') ?>" alt="Avatar" class="user-avatar-sm">
                    <span class="d-none d-sm-inline font-weight-bold" style="font-size: 0.85rem; font-weight: 600;">
                        <?= htmlspecialchars($user['full_name'] ?? 'User') ?>
                    </span>
                </button>
                <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                    <li class="px-3 py-2 border-bottom">
                        <div style="font-size: 0.85rem; font-weight: 700;"><?= htmlspecialchars($user['full_name'] ?? '') ?></div>
                        <div style="font-size: 0.75rem; color: #64748b;"><?= htmlspecialchars($user['email'] ?? '') ?></div>
                        <span class="badge bg-primary text-uppercase mt-1" style="font-size: 0.65rem;"><?= htmlspecialchars($user['role'] ?? '') ?></span>
                    </li>
                    <li><a class="dropdown-item py-2" href="<?= url('dashboard.php') ?>"><i class="bi bi-speedometer2 me-2"></i>Dashboard</a></li>
                    <?php if (isAdmin()): ?>
                        <li><a class="dropdown-item py-2" href="<?= url('settings.php') ?>"><i class="bi bi-sliders me-2"></i>Settings</a></li>
                    <?php endif; ?>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item py-2 text-danger" href="<?= url('logout.php') ?>"><i class="bi bi-box-arrow-right me-2"></i>Sign Out</a></li>
                </ul>
            </div>
        </div>
    </header>
    <div class="app-content">
        <!-- Render Flash Messages -->
        <?php foreach (getFlashes() as $flash): ?>
            <div class="alert alert-<?= htmlspecialchars($flash['type']) ?> alert-dismissible fade show d-flex align-items-center gap-2 mb-3" role="alert">
                <i class="bi bi-info-circle-fill"></i>
                <div><?= htmlspecialchars($flash['message']) ?></div>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endforeach; ?>
