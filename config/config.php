<?php
/**
 * KETAN M/A B COMPLEX - Core Configuration & Global Utilities
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// App Constants
define('APP_NAME', 'KETAN M/A B COMPLEX');
define('APP_FULL_TITLE', 'KETAN M/A B COMPLEX – School-Based Assessment & Performance Management System');
define('APP_VERSION', '1.0.0');

// Paths
define('BASE_DIR', dirname(__DIR__));
define('UPLOAD_DIR', BASE_DIR . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'students');
define('LOGO_UPLOAD_DIR', BASE_DIR . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'logo');
define('SIGNATURE_UPLOAD_DIR', BASE_DIR . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'signatures');

/**
 * Determine dynamic base URL
 */
function getBaseUrl(): string
{
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') 
        || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443)
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
        
    $protocol = $isHttps ? "https://" : "http://";
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '');
    $basePath = rtrim(str_replace('\\', '/', $scriptDir), '/');
    
    // On Vercel serverless /api entrypoint or root domain, basePath should be empty
    if ($basePath === '.' || $basePath === '/' || $basePath === '/api') {
        $basePath = '';
    }
    
    return $protocol . $host . $basePath;
}

define('BASE_URL', getBaseUrl());

function url(string $path = ''): string
{
    $cleanPath = ltrim($path, '/');
    return rtrim(BASE_URL, '/') . ($cleanPath ? '/' . $cleanPath : '');
}

function asset(string $path = ''): string
{
    return url('assets/' . ltrim($path, '/'));
}

/**
 * Get School Logo URL (Custom uploaded logo or default vector crest)
 */
function getSchoolLogoUrl(): string
{
    $customLogo = getSetting('school_logo');
    if (!empty($customLogo)) {
        $logoFile = LOGO_UPLOAD_DIR . DIRECTORY_SEPARATOR . $customLogo;
        if (file_exists($logoFile)) {
            return url('uploads/logo/' . $customLogo) . '?v=' . filemtime($logoFile);
        }
    }
    return asset('images/logo.svg');
}

/**
 * Security: Sanitize string
 */
function sanitize($data)
{
    if (is_array($data)) {
        return array_map('sanitize', $data);
    }
    return htmlspecialchars(trim((string) $data), ENT_QUOTES, 'UTF-8');
}

/**
 * CSRF Protection
 */
function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfField(): string
{
    return '<input type="hidden" name="csrf_token" value="' . csrfToken() . '">';
}

function verifyCsrfToken(?string $token): bool
{
    if (!$token || empty($_SESSION['csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Flash Messages
 */
function flash(string $type, string $message): void
{
    if (!isset($_SESSION['flashes'])) {
        $_SESSION['flashes'] = [];
    }
    $_SESSION['flashes'][] = ['type' => $type, 'message' => $message];
}

function getFlashes(): array
{
    $flashes = $_SESSION['flashes'] ?? [];
    unset($_SESSION['flashes']);
    return $flashes;
}

/**
 * Fetch dynamic System Settings from DB
 */
function getSetting(string $key, string $default = ''): string
{
    static $settingsCache = null;
    if ($settingsCache === null) {
        $settingsCache = [];
        try {
            $db = getDB();
            $stmt = $db->query("SELECT setting_key, setting_value FROM system_settings");
            while ($row = $stmt->fetch()) {
                $settingsCache[$row['setting_key']] = $row['setting_value'];
            }
        } catch (Exception $e) {
            // DB might be uninitialized
        }
    }
    return $settingsCache[$key] ?? $default;
}

/**
 * Get currently active academic year
 */
function getActiveAcademicYear(): ?array
{
    static $year = null;
    if ($year === null) {
        try {
            $db = getDB();
            $stmt = $db->query("SELECT * FROM academic_years WHERE is_active = 1 LIMIT 1");
            $year = $stmt->fetch() ?: null;
        } catch (Exception $e) {
            $year = null;
        }
    }
    return $year;
}

/**
 * Get currently active term
 */
function getActiveTerm(): ?array
{
    static $term = null;
    if ($term === null) {
        try {
            $db = getDB();
            $stmt = $db->query("
                SELECT t.*, y.year_name 
                FROM terms t 
                JOIN academic_years y ON t.academic_year_id = y.id 
                WHERE t.is_active = 1 AND y.is_active = 1 
                LIMIT 1
            ");
            $term = $stmt->fetch() ?: null;
            if (!$term) {
                // Fallback to first term of active year
                $stmt = $db->query("
                    SELECT t.*, y.year_name 
                    FROM terms t 
                    JOIN academic_years y ON t.academic_year_id = y.id 
                    WHERE y.is_active = 1 
                    ORDER BY t.id ASC 
                    LIMIT 1
                ");
                $term = $stmt->fetch() ?: null;
            }
        } catch (Exception $e) {
            $term = null;
        }
    }
    return $term;
}

/**
 * Fetch Grading Scales
 */
function getGradingScale(): array
{
    static $scale = null;
    if ($scale === null) {
        try {
            $db = getDB();
            $stmt = $db->query("SELECT * FROM grading_scale ORDER BY display_order ASC, min_score DESC");
            $scale = $stmt->fetchAll();
        } catch (Exception $e) {
            $scale = [];
        }
    }
    return $scale;
}

/**
 * Calculate Grade and Remark from numeric score
 */
function calculateGradeAndRemark(float $score, ?array $gradingScale = null): array
{
    $scale = $gradingScale ?: getGradingScale();
    foreach ($scale as $tier) {
        if ($score >= (float) $tier['min_score'] && $score <= (float) $tier['max_score']) {
            return [
                'grade' => $tier['grade'],
                'remark' => $tier['remark']
            ];
        }
    }
    // Fallback standard Ghanaian MJHS scale if table empty
    if ($score >= 80)
        return ['grade' => 'A', 'remark' => 'Excellent'];
    if ($score >= 70)
        return ['grade' => 'B', 'remark' => 'Very Good'];
    if ($score >= 60)
        return ['grade' => 'C', 'remark' => 'Good'];
    if ($score >= 50)
        return ['grade' => 'D', 'remark' => 'Credit'];
    if ($score >= 40)
        return ['grade' => 'E', 'remark' => 'Pass'];
    return ['grade' => 'F', 'remark' => 'Fail'];
}

/**
 * Dynamic intelligent Class Teacher Remark based on terminal score and grade
 */
function getDefaultClassTeacherRemark(float $averageScore, string $grade): string
{
    switch (strtoupper(trim($grade))) {
        case 'A':
            return "An exceptional and outstanding terminal performance! Consistently exhibits intellectual curiosity, disciplined study habits, and active leadership in class.";
        case 'B':
            return "Very good academic performance. Shows strong comprehension and diligence across subjects. Capable of attaining top honors with sustained focus.";
        case 'C':
            return "Good effort demonstrated this term. Solid grasp of core principles, though greater consistency in homework and revisions will boost grades.";
        case 'D':
            return "Fair academic standing. Capable of better results if more time is devoted to study and active participation in class.";
        case 'E':
            return "Weak performance. Struggles with fundamental concepts in several subjects. Needs to seek teacher assistance and attend remedial study sessions.";
        case 'F':
        default:
            return "Unsatisfactory performance. Urgent academic intervention and committed remedial lessons are required to overcome difficulties.";
    }
}

/**
 * Dynamic intelligent Head Teacher Remark based on terminal score and grade
 */
function getDefaultHeadTeacherRemark(float $averageScore, string $grade): string
{
    switch (strtoupper(trim($grade))) {
        case 'A':
            return "Brilliant and exemplary academic achievement. Commended for top-tier excellence; keep upholding this high standard.";
        case 'B':
            return "Commendable progress and very good results. Keep up the high standard and dedication next term.";
        case 'C':
            return "Satisfactory terminal achievement with good potential for further advancement. Strive for higher academic excellence next term.";
        case 'D':
            return "Average performance. Encouraged to exhibit greater seriousness, focus, and punctuality in all academic tasks.";
        case 'E':
            return "Borderline pass. Immediate improvement is required. Closer parental supervision and focused revision advised.";
        case 'F':
        default:
            return "Critical result. Must undergo intensive remedial coaching and improve considerably next term to meet promotion criteria.";
    }
}


/**
 * Generate Next Unique Student ID (Format: KCM-YYYY-####)
 */
function generateNextStudentId(?int $academicYear = null): string
{
    $db = getDB();
    $year = $academicYear ? (string) $academicYear : date('Y');
    $prefix = "KCM-{$year}-";

    $stmt = $db->prepare("SELECT student_id FROM students WHERE student_id LIKE ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$prefix . '%']);
    $last = $stmt->fetchColumn();

    if ($last) {
        $num = (int) substr($last, strlen($prefix));
        $nextNum = str_pad((string) ($num + 1), 4, '0', STR_PAD_LEFT);
    } else {
        $nextNum = '0001';
    }

    return $prefix . $nextNum;
}

/**
 * JSON response helper
 */
function jsonResponse(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

/**
 * Get assigned class teacher for a class
 */
function getAssignedClassTeacher(int $classId): ?array
{
    try {
        $db = getDB();
        $stmt = $db->prepare("
            SELECT u.id, u.full_name, u.email, u.phone, u.signature, c.class_name, c.class_teacher_assigned_at
            FROM classes c
            JOIN users u ON c.class_teacher_id = u.id
            WHERE c.id = ? AND u.status = 'active'
            LIMIT 1
        ");
        $stmt->execute([$classId]);
        $teacher = $stmt->fetch();
        if ($teacher) {
            return $teacher;
        }

        $stmt2 = $db->prepare("
            SELECT u.id, u.full_name, u.email, u.phone, u.signature, c.class_name, ct.assigned_at as class_teacher_assigned_at
            FROM class_teachers ct
            JOIN users u ON ct.teacher_id = u.id
            JOIN classes c ON ct.class_id = c.id
            WHERE ct.class_id = ? AND u.status = 'active'
            LIMIT 1
        ");
        $stmt2->execute([$classId]);
        return $stmt2->fetch() ?: null;
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Get class assigned to a teacher
 */
function getTeacherAssignedClass(int $teacherId): ?array
{
    try {
        $db = getDB();
        $stmt = $db->prepare("
            SELECT c.id, c.class_name, c.class_code, c.display_order, c.class_teacher_assigned_at
            FROM classes c
            WHERE c.class_teacher_id = ? AND c.status = 'active'
            LIMIT 1
        ");
        $stmt->execute([$teacherId]);
        $cls = $stmt->fetch();
        if ($cls) {
            return $cls;
        }

        $stmt2 = $db->prepare("
            SELECT c.id, c.class_name, c.class_code, c.display_order, ct.assigned_at as class_teacher_assigned_at
            FROM class_teachers ct
            JOIN classes c ON ct.class_id = c.id
            WHERE ct.teacher_id = ? AND c.status = 'active'
            LIMIT 1
        ");
        $stmt2->execute([$teacherId]);
        return $stmt2->fetch() ?: null;
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Get Teacher Signature URL (returns null if not found or file does not exist)
 */
function getTeacherSignatureUrl(?string $filename): ?string
{
    if (empty($filename)) {
        return null;
    }
    $clean = basename($filename);
    $filePath = SIGNATURE_UPLOAD_DIR . DIRECTORY_SEPARATOR . $clean;
    if (file_exists($filePath)) {
        return url('uploads/signatures/' . $clean) . '?v=' . filemtime($filePath);
    }
    return null;
}

