<?php
/**
 * KETAN M/A B COMPLEX - Database Connection
 * PDO Database Handler
 */

class Database {
    private static ?Database $instance = null;
    private ?PDO $connection = null;
    private ?string $lastError = null;

    private string $host = 'localhost';
    private string $dbname = 'ketan_complex_sba';
    private string $user = 'root';
    private string $pass = '';
    private string $port = '3306';
    private string $charset = 'utf8mb4';

    private function __construct() {
        // 1. Check Standard Cloud Connection Strings (Vercel, Railway, Supabase, TiDB, ClearDB, JawsDB)
        $connUrl = getenv('DATABASE_URL') 
            ?: ($_ENV['DATABASE_URL'] 
            ?? ($_SERVER['DATABASE_URL'] 
            ?? (getenv('MYSQL_URL') 
            ?: ($_ENV['MYSQL_URL'] 
            ?? (getenv('CLEARDB_DATABASE_URL') 
            ?: ($_ENV['CLEARDB_DATABASE_URL'] ?? ''))))));

        if (!empty($connUrl)) {
            $parsed = parse_url($connUrl);
            if ($parsed) {
                $this->host = $parsed['host'] ?? $this->host;
                $this->port = isset($parsed['port']) ? (string)$parsed['port'] : $this->port;
                $this->user = isset($parsed['user']) ? urldecode($parsed['user']) : $this->user;
                $this->pass = isset($parsed['pass']) ? urldecode($parsed['pass']) : $this->pass;
                if (!empty($parsed['path'])) {
                    $this->dbname = ltrim($parsed['path'], '/');
                }
            }
        }

        // 2. Check Individual Environment Variables (Overrides URL if explicitly set)
        $this->host = getenv('DB_HOST') ?: ($_ENV['DB_HOST'] ?? ($_SERVER['DB_HOST'] ?? $this->host));
        $this->dbname = getenv('DB_NAME') ?: ($_ENV['DB_NAME'] ?? ($_SERVER['DB_NAME'] ?? $this->dbname));
        $this->user = getenv('DB_USER') ?: ($_ENV['DB_USER'] ?? ($_SERVER['DB_USER'] ?? $this->user));
        $this->pass = getenv('DB_PASS') ?: ($_ENV['DB_PASS'] ?? ($_SERVER['DB_PASS'] ?? $this->pass));
        $this->port = getenv('DB_PORT') ?: ($_ENV['DB_PORT'] ?? ($_SERVER['DB_PORT'] ?? $this->port));

        // 3. Load custom config file if exists (for local development fallback)
        $customConfigFile = __DIR__ . '/db_config.php';
        if (file_exists($customConfigFile) && empty(getenv('DB_HOST')) && empty(getenv('DATABASE_URL'))) {
            $custom = require $customConfigFile;
            if (is_array($custom)) {
                $this->host = $custom['host'] ?? $this->host;
                $this->dbname = $custom['dbname'] ?? $this->dbname;
                $this->user = $custom['user'] ?? $this->user;
                $this->pass = $custom['pass'] ?? $this->pass;
                $this->port = $custom['port'] ?? $this->port;
            }
        }

        $dsn = "mysql:host={$this->host};port={$this->port};dbname={$this->dbname};charset={$this->charset}";
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_TIMEOUT            => 3, // Prevent hanging on cloud cold start
        ];

        // Support cloud databases requiring SSL (e.g. TiDB, Aiven, PlanetScale)
        if (getenv('DB_SSL') || getenv('MYSQL_ATTR_SSL_CA') || $this->port === '4000') {
            $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
        }

        try {
            $this->connection = new PDO($dsn, $this->user, $this->pass, $options);
        } catch (Throwable $e) {
            $this->connection = null;
            $this->lastError = $e->getMessage();
            error_log("Database Connection Error: " . $e->getMessage());
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

    public function getLastError(): ?string {
        return $this->lastError;
    }

    public static function isConnected(): bool {
        return self::getInstance()->getConnection() !== null;
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
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 5
        ]);
    }
}

function getDB(): ?PDO {
    return Database::getInstance()->getConnection();
}
