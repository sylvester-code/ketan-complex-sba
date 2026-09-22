<?php
/**
 * KETAN M/A B COMPLEX - Audit & Activity Logs
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/session.php';

requireRole(['admin']);

$db = getDB();

// Filters
$actionFilter = $_GET['action'] ?? '';
$userFilter   = $_GET['user'] ?? '';
$searchQuery  = trim($_GET['q'] ?? '');

$sql = "SELECT * FROM audit_logs WHERE 1=1";
$params = [];

if ($actionFilter) {
    $sql .= " AND action = ?";
    $params[] = $actionFilter;
}
if ($userFilter) {
    $sql .= " AND username = ?";
    $params[] = $userFilter;
}
if (!empty($searchQuery)) {
    $sql .= " AND (description LIKE ? OR ip_address LIKE ?)";
    $like = "%{$searchQuery}%";
    $params[] = $like;
    $params[] = $like;
}

$sql .= " ORDER BY created_at DESC LIMIT 200";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll();

// Unique actions and users for filter dropdowns
$allActions = $db->query("SELECT DISTINCT action FROM audit_logs ORDER BY action ASC")->fetchAll(PDO::FETCH_COLUMN);
$allUsers   = $db->query("SELECT DISTINCT username FROM audit_logs WHERE username IS NOT NULL ORDER BY username ASC")->fetchAll(PDO::FETCH_COLUMN);

$pageTitle = 'Audit Logs';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
require_once __DIR__ . '/includes/navbar.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h2 class="h3 fw-bold mb-1" style="font-family: 'Outfit';">System Audit & Activity Logs</h2>
        <div class="text-muted small">Comprehensive security tracking of administrative actions, mark updates, and logins</div>
    </div>
</div>

<!-- Filters Bar -->
<div class="card-custom mb-4 p-3 bg-white">
    <form method="GET" action="audit_logs.php" class="row g-2 align-items-center">
        <div class="col-md-4">
            <input type="text" name="q" class="form-control" placeholder="Search description or IP..." value="<?= htmlspecialchars($searchQuery) ?>">
        </div>
        <div class="col-md-3">
            <select name="action" class="form-select">
                <option value="">-- All Actions --</option>
                <?php foreach ($allActions as $act): ?>
                    <option value="<?= htmlspecialchars($act) ?>" <?= $actionFilter === $act ? 'selected' : '' ?>>
                        <?= htmlspecialchars($act) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <select name="user" class="form-select">
                <option value="">-- All Users --</option>
                <?php foreach ($allUsers as $u): ?>
                    <option value="<?= htmlspecialchars($u) ?>" <?= $userFilter === $u ? 'selected' : '' ?>>
                        <?= htmlspecialchars($u) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2 d-flex gap-1">
            <button type="submit" class="btn btn-primary w-100"><i class="bi bi-funnel"></i> Filter</button>
            <a href="audit_logs.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-counterclockwise"></i></a>
        </div>
    </form>
</div>

<!-- Logs Table -->
<div class="card-custom">
    <div class="card-header bg-white">
        <span><i class="bi bi-shield-check text-primary me-2"></i>Recorded Audit Events (<?= count($logs) ?>)</span>
    </div>
    <div class="table-responsive">
        <table class="table table-custom mb-0" style="font-size: 0.85rem;">
            <thead>
                <tr>
                    <th>Timestamp</th>
                    <th>User</th>
                    <th>Role</th>
                    <th>Action</th>
                    <th>Description</th>
                    <th>IP Address</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logs)): ?>
                    <tr><td colspan="6" class="text-center py-4 text-muted">No audit events found.</td></tr>
                <?php else: ?>
                    <?php foreach ($logs as $l): ?>
                        <tr>
                            <td class="text-nowrap text-muted"><?= date('M j, Y H:i:s', strtotime($l['created_at'])) ?></td>
                            <td>
                                <strong><?= htmlspecialchars($l['username'] ?? 'System') ?></strong>
                            </td>
                            <td>
                                <span class="badge bg-light text-dark border text-uppercase" style="font-size: 0.7rem;">
                                    <?= htmlspecialchars($l['role'] ?? 'Guest') ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge bg-primary-subtle text-primary border font-monospace">
                                    <?= htmlspecialchars($l['action']) ?>
                                </span>
                            </td>
                            <td><?= htmlspecialchars($l['description']) ?></td>
                            <td><code class="text-muted"><?= htmlspecialchars($l['ip_address'] ?? '—') ?></code></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
