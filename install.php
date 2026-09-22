<?php
/**
 * KETAN M/A B COMPLEX - Initial Installation & Setup Wizard
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$configFile = __DIR__ . '/config/db_config.php';
$unifiedDbFile = __DIR__ . '/database/database.sql';

$errors = [];
$success = false;

// Check if already installed
$isInstalled = false;
if (file_exists($configFile)) {
    try {
        $dbConf = require $configFile;
        $dsn = "mysql:host={$dbConf['host']};port={$dbConf['port']};dbname={$dbConf['dbname']};charset=utf8mb4";
        $pdo = new PDO($dsn, $dbConf['user'], $dbConf['pass']);
        $check = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
        if ($check > 0) {
            $isInstalled = true;
        }
    } catch (Exception $e) {
        $isInstalled = false;
    }
}

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'install') {
    $dbHost = trim($_POST['db_host'] ?? 'localhost');
    $dbPort = trim($_POST['db_port'] ?? '3306');
    $dbUser = trim($_POST['db_user'] ?? 'root');
    $dbPass = $_POST['db_pass'] ?? '';
    $dbName = trim($_POST['db_name'] ?? 'ketan_complex_sba');

    $adminName  = trim($_POST['admin_name'] ?? '');
    $adminUser  = trim($_POST['admin_username'] ?? '');
    $adminEmail = trim($_POST['admin_email'] ?? '');
    $adminPass  = $_POST['admin_password'] ?? '';
    $adminPass2 = $_POST['admin_password_confirm'] ?? '';
    $loadDemo   = !empty($_POST['load_demo']);

    // Validation
    if (empty($adminName) || empty($adminUser) || empty($adminEmail) || empty($adminPass)) {
        $errors[] = 'All Administrator fields are required.';
    }
    if ($adminPass !== $adminPass2) {
        $errors[] = 'Admin passwords do not match.';
    }
    if (strlen($adminPass) < 6) {
        $errors[] = 'Password must be at least 6 characters long.';
    }

    if (empty($errors)) {
        try {
            // 1. Test raw MySQL connection
            $rawDsn = "mysql:host={$dbHost};port={$dbPort};charset=utf8mb4";
            $pdo = new PDO($rawDsn, $dbUser, $dbPass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
            ]);

            // 2. Create Database if not exists
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("USE `{$dbName}`");

            // 3. Import Unified Database File
            if (file_exists($unifiedDbFile)) {
                $dbSql = file_get_contents($unifiedDbFile);
                $pdo->exec($dbSql);
            }

            // 6. Create the Administrator Account
            $hashedPassword = password_hash($adminPass, PASSWORD_BCRYPT);
            // Check if admin already exists
            $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
            $stmt->execute([$adminUser, $adminEmail]);
            $existingUser = $stmt->fetch();

            if ($existingUser) {
                $update = $pdo->prepare("UPDATE users SET full_name = ?, password_hash = ?, role = 'admin', status = 'active' WHERE id = ?");
                $update->execute([$adminName, $hashedPassword, $existingUser['id']]);
            } else {
                $insert = $pdo->prepare("INSERT INTO users (username, email, password_hash, full_name, role, status) VALUES (?, ?, ?, ?, 'admin', 'active')");
                $insert->execute([$adminUser, $adminEmail, $hashedPassword, $adminName]);
            }

            // 7. Write db_config.php
            $configContent = "<?php\nreturn [\n"
                . "    'host'   => " . var_export($dbHost, true) . ",\n"
                . "    'port'   => " . var_export($dbPort, true) . ",\n"
                . "    'dbname' => " . var_export($dbName, true) . ",\n"
                . "    'user'   => " . var_export($dbUser, true) . ",\n"
                . "    'pass'   => " . var_export($dbPass, true) . ",\n"
                . "];\n";
            file_put_contents($configFile, $configContent);

            $_SESSION['install_success'] = 'KETAN M/A B COMPLEX SBA System installed successfully! You can now log in.';
            header('Location: login.php');
            exit;
        } catch (Exception $e) {
            $errors[] = 'Database Installation Error: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Setup | KETAN M/A B COMPLEX</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Outfit:wght@600;700;800;900&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #0f2942 0%, #1e3a8a 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem 1rem;
        }
        .install-card {
            background: #ffffff;
            border-radius: 16px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.3);
            max-width: 760px;
            width: 100%;
            overflow: hidden;
        }
        .install-header {
            background: #0f2942;
            color: #ffffff;
            padding: 2rem;
            text-align: center;
        }
        .school-crest {
            width: 80px;
            height: 80px;
            margin-bottom: 0.75rem;
        }
        .step-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.35rem 0.75rem;
            background: rgba(245, 158, 11, 0.2);
            color: #f59e0b;
            border: 1px solid #f59e0b;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
        }
    </style>
</head>
<body>

<div class="install-card">
    <div class="install-header">
        <img src="assets/images/logo.svg" alt="Crest" class="school-crest">
        <h2 class="h3 font-weight-bold mb-1" style="font-family: 'Outfit', sans-serif; letter-spacing: 0.5px;">KETAN M/A B COMPLEX</h2>
        <div class="text-warning small text-uppercase font-weight-bold tracking-wider mb-2">School-Based Assessment & Performance Management System</div>
        <div class="step-badge"><i class="bi bi-gear-fill"></i> System Setup & Initial Configuration</div>
    </div>

    <div class="p-4 p-md-5">
        <?php if ($isInstalled): ?>
            <div class="alert alert-info d-flex align-items-center gap-3">
                <i class="bi bi-check-circle-fill fs-3 text-success"></i>
                <div>
                    <strong>System is already installed!</strong><br>
                    You have an existing installation configured. You can log in directly, or re-run setup to re-initialize.
                </div>
            </div>
            <div class="text-center my-3">
                <a href="login.php" class="btn btn-primary px-4 py-2 me-2"><i class="bi bi-box-arrow-in-right me-2"></i>Go to Login</a>
            </div>
            <hr class="my-4">
            <h5 class="text-muted small text-uppercase font-weight-bold mb-3">Or Reconfigure Installation</h5>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger">
                <ul class="mb-0">
                    <?php foreach ($errors as $error): ?>
                        <li><?= htmlspecialchars($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="POST" action="install.php">
            <input type="hidden" name="action" value="install">

            <!-- Section 1: Database Settings -->
            <div class="mb-4">
                <h5 class="fw-bold text-primary mb-3 d-flex align-items-center gap-2" style="font-family: 'Outfit';">
                    <i class="bi bi-database"></i> 1. MySQL Database Settings
                </h5>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label small fw-bold">Database Host</label>
                        <input type="text" name="db_host" class="form-control" value="<?= htmlspecialchars($_POST['db_host'] ?? 'localhost') ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-bold">Database Port</label>
                        <input type="text" name="db_port" class="form-control" value="<?= htmlspecialchars($_POST['db_port'] ?? '3306') ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-bold">Database User</label>
                        <input type="text" name="db_user" class="form-control" value="<?= htmlspecialchars($_POST['db_user'] ?? 'root') ?>" required>
                        <div class="form-text small">Default XAMPP user is <code>root</code>.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-bold">Database Password</label>
                        <input type="password" name="db_pass" class="form-control" value="<?= htmlspecialchars($_POST['db_pass'] ?? '') ?>" placeholder="Empty by default on XAMPP">
                    </div>
                    <div class="col-12">
                        <label class="form-label small fw-bold">Database Name</label>
                        <input type="text" name="db_name" class="form-control" value="<?= htmlspecialchars($_POST['db_name'] ?? 'ketan_complex_sba') ?>" required>
                        <div class="form-text small">The system will automatically create this database if it does not exist.</div>
                    </div>
                </div>
            </div>

            <hr class="my-4">

            <!-- Section 2: First Administrator Account -->
            <div class="mb-4">
                <h5 class="fw-bold text-primary mb-3 d-flex align-items-center gap-2" style="font-family: 'Outfit';">
                    <i class="bi bi-person-shield"></i> 2. Create Administrator Account
                </h5>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label small fw-bold">Full Name</label>
                        <input type="text" name="admin_name" class="form-control" placeholder="e.g. Samuel K. Arthur" value="<?= htmlspecialchars($_POST['admin_name'] ?? 'System Administrator') ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-bold">Username</label>
                        <input type="text" name="admin_username" class="form-control" placeholder="e.g. admin" value="<?= htmlspecialchars($_POST['admin_username'] ?? 'admin') ?>" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label small fw-bold">Email Address</label>
                        <input type="email" name="admin_email" class="form-control" placeholder="admin@ketancomplex.edu.gh" value="<?= htmlspecialchars($_POST['admin_email'] ?? 'admin@ketancomplex.edu.gh') ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-bold">Admin Password</label>
                        <input type="password" name="admin_password" class="form-control" placeholder="Enter secure password" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-bold">Confirm Password</label>
                        <input type="password" name="admin_password_confirm" class="form-control" placeholder="Re-type password" required>
                    </div>
                </div>
            </div>

            <hr class="my-4">

            <!-- Section 3: Demo Data Option -->
            <div class="form-check form-switch p-3 bg-light rounded-3 mb-4">
                <input class="form-check-input ms-0 me-3" type="checkbox" role="switch" name="load_demo" id="loadDemoSwitch" value="1" checked>
                <label class="form-check-label" for="loadDemoSwitch">
                    <strong>Load Sample Demo Data (Recommended)</strong>
                    <div class="text-muted small">
                        Pre-populates sample teachers (<code>headteacher</code>, <code>kwame.mensah</code>, <code>abena.asante</code>), 10 JHS 1 students, teacher assignments, and sample approved marks so all dashboards, reports, and charts are ready to test immediately.
                    </div>
                </label>
            </div>

            <button type="submit" class="btn btn-warning w-100 py-3 fw-bold text-dark fs-5 shadow-sm" style="font-family: 'Outfit';">
                <i class="bi bi-rocket-takeoff-fill me-2"></i> Install & Initialize System
            </button>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
