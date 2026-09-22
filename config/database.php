<?php
/**
 * KETAN M/A B COMPLEX - Database Connection
 * PDO Database Handler
 */

class Database {
    private static ?Database $instance = null;
    private ?PDO $connection = null;

    private string $host = 'localhost';
    private string $dbname = 'ketan_complex_sba';
    private string $user = 'root';
    private string $pass = '';
    private string $port = '3306';
    private string $charset = 'utf8mb4';

    private function __construct() {
        // Load custom config file if exists
        $customConfigFile = __DIR__ . '/db_config.php';
        if (file_exists($customConfigFile)) {
            $custom = require $customConfigFile;
            $this->host = $custom['host'] ?? $this->host;
            $this->dbname = $custom['dbname'] ?? $this->dbname;
            $this->user = $custom['user'] ?? $this->user;
            $this->pass = $custom['pass'] ?? $this->pass;
            $this->port = $custom['port'] ?? $this->port;
        }

        $dsn = "mysql:host={$this->host};port={$this->port};dbname={$this->dbname};charset={$this->charset}";
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        try {
            $this->connection = new PDO($dsn, $this->user, $this->pass, $options);
        } catch (PDOException $e) {
            // Check if installer is running or if DB doesn't exist yet
            $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
            if ($script !== 'install.php') {
                // If tables or DB are missing, redirect gracefully to installer
                header('Location: ' . self::getBasePath() . '/install.php');
                exit;
            }
            throw $e;
        }
    }

    public static function getInstance(): Database {
        if (self::$instance === null) {
            self::$instance = new Database();
        }
        return self::$instance;
    }

    public function getConnection(): ?PDO {
        return $this->connection;
    }

    public static function getBasePath(): string {
        $scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '');
        return rtrim(str_replace('\\', '/', $scriptDir), '/');
    }

    /**
     * Helper to test raw server connection without selecting database (for installer)
     */
    public static function testRawConnection(string $host, string $user, string $pass, string $port = '3306'): PDO {
        $dsn = "mysql:host={$host};port={$port};charset=utf8mb4";
        return new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
    }
}

function getDB(): PDO {
    return Database::getInstance()->getConnection();
}
