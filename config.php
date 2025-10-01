<?php
// Karten-Verwaltungssystem mit RBAC und Wartungsmanagement

// Datenbank Konfiguration
define('DB_HOST', 'localhost');
define('DB_NAME', 'd044e4cb');
define('DB_USER', 'd044e4cb');
define('DB_PASS', '9oN7KeAFFAE8oYKQzxVf');

// Anwendung Einstellungen
define('UPLOAD_PATH', 'uploads/');
define('MAX_FILE_SIZE', 2 * 1024 * 1024);
define('ALLOWED_EXTENSIONS', ['jpg', 'jpeg', 'png', 'gif']);

// Hintergrundbild-Konfiguration
define('BACKGROUND_PATH', 'background/');
define('BACKGROUND_MAX_SIZE', 10 * 1024 * 1024);
define('BACKGROUND_ALLOWED_EXT', ['jpg', 'jpeg', 'png']);

// System-Einstellungen
define('SYSTEM_NAME', 'Objekt-Verwaltungssystem');
define('DEFAULT_CONTAINER_WIDTH', 1200);
define('DEFAULT_CONTAINER_HEIGHT', 800);

// Wartungs-Einstellungen
define('DEFAULT_MAINTENANCE_INTERVAL', 180); // Standard: 6 Monate
define('MAINTENANCE_WARNING_DAYS', 7); // Warnung 7 Tage vorher

// E-Mail-Konfiguration
define('SEND_EMAIL_NOTIFICATIONS', true);
define('EMAIL_FROM_ADDRESS', 'noreply@' . $_SERVER['HTTP_HOST']);
define('EMAIL_FROM_NAME', SYSTEM_NAME);

// ===== NEUE SICHERHEITS-EINSTELLUNGEN =====

// Session-Timeout (in Sekunden) - Standard: 30 Minuten
define('SESSION_TIMEOUT', 1800);

// Session-Warnung vor Ablauf (in Sekunden) - Standard: 5 Minuten
define('SESSION_WARNING_TIME', 300);

// Login-Versuche limitieren
define('MAX_LOGIN_ATTEMPTS', 5); // Maximale Fehlversuche
define('LOGIN_LOCKOUT_TIME', 900); // Sperrzeit in Sekunden (15 Minuten)
define('LOGIN_ATTEMPT_WINDOW', 300); // Zeitfenster für Versuche in Sekunden (5 Minuten)

// Audit-Log Einstellungen
define('AUDIT_LOG_ENABLED', true);
define('AUDIT_LOG_PATH', 'logs/audit/');
define('AUDIT_LOG_RETENTION_DAYS', 365); // Logs 1 Jahr aufbewahren

// IP-Whitelist (optional - leer lassen für keine Einschränkung)
define('IP_WHITELIST', []); // Beispiel: ['192.168.1.100', '10.0.0.1']

// ============================================

// Session starten mit erweiterten Sicherheitseinstellungen
if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    // Session-Cookie-Parameter setzen
    session_set_cookie_params([
        'lifetime' => SESSION_TIMEOUT,
        'path' => '/',
        'domain' => $_SERVER['HTTP_HOST'],
        'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
        'httponly' => true,
        'samesite' => 'Strict'
    ]);
    
    session_start();
    
    // Session-Timeout prüfen
    SessionManager::checkTimeout();
} elseif (session_status() === PHP_SESSION_NONE && headers_sent()) {
    error_log("Warning: Tried to start session but headers already sent");
}

// Upload-Verzeichnisse erstellen
if (!file_exists(UPLOAD_PATH)) {
    mkdir(UPLOAD_PATH, 0777, true);
}
if (!file_exists(BACKGROUND_PATH)) {
    mkdir(BACKGROUND_PATH, 0777, true);
}
if (!file_exists('logs')) {
    mkdir('logs', 0777, true);
}
if (AUDIT_LOG_ENABLED && !file_exists(AUDIT_LOG_PATH)) {
    mkdir(AUDIT_LOG_PATH, 0777, true);
}

// Datenbank Verbindung
class Database {
    private static $instance = null;
    private $conn;
    
    private function __construct() {
        try {
            $this->conn = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false
                ]
            );
        } catch (PDOException $e) {
            die("Datenbankverbindung fehlgeschlagen: " . $e->getMessage());
        }
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function getConnection() {
        return $this->conn;
    }
}

// ===== NEUE KLASSE: Session-Manager =====
class SessionManager {
    
    /**
     * Prüft Session-Timeout
     */
    public static function checkTimeout() {
        if (!isset($_SESSION['user_id'])) {
            return;
        }
        
        $currentTime = time();
        
        // Session-Start-Zeit setzen
        if (!isset($_SESSION['session_started'])) {
            $_SESSION['session_started'] = $currentTime;
        }
        
        // Letzte Aktivität setzen
        if (!isset($_SESSION['last_activity'])) {
            $_SESSION['last_activity'] = $currentTime;
        }
        
        // Timeout prüfen
        $inactiveTime = $currentTime - $_SESSION['last_activity'];
        
        if ($inactiveTime > SESSION_TIMEOUT) {
            AuditLogger::log('session_timeout', 'Session expired due to inactivity', [
                'inactive_seconds' => $inactiveTime,
                'session_duration' => $currentTime - $_SESSION['session_started']
            ]);
            
            Auth::logout();
            
            // JSON-Response für AJAX
            if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 
                strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                http_response_code(401);
                exit(json_encode([
                    'success' => false, 
                    'message' => 'Ihre Sitzung ist abgelaufen. Bitte melden Sie sich erneut an.',
                    'session_expired' => true
                ]));
            }
            
            // Normale Seite - Weiterleitung
            header('Location: index.php?session_expired=1');
            exit;
        }
        
        // Aktivitätszeitpunkt aktualisieren
        $_SESSION['last_activity'] = $currentTime;
        
        // Session-ID regenerieren alle 30 Minuten
        if (!isset($_SESSION['last_regeneration'])) {
            $_SESSION['last_regeneration'] = $currentTime;
        } elseif ($currentTime - $_SESSION['last_regeneration'] > 1800) {
            session_regenerate_id(true);
            $_SESSION['last_regeneration'] = $currentTime;
        }
    }
    
    /**
     * Gibt verbleibende Session-Zeit zurück
     */
    public static function getRemainingTime() {
        if (!isset($_SESSION['last_activity'])) {
            return 0;
        }
        
        $remaining = SESSION_TIMEOUT - (time() - $_SESSION['last_activity']);
        return max(0, $remaining);
    }
    
    /**
     * Verlängert die Session (bei Benutzeraktivität)
     */
    public static function extendSession() {
        if (isset($_SESSION['user_id'])) {
            $_SESSION['last_activity'] = time();
        }
    }
}

// ===== NEUE KLASSE: Login-Attempts-Manager =====
class LoginAttemptsManager {
    
    /**
     * Prüft ob IP gesperrt ist
     */
    public static function isIpLocked($ip) {
        $db = Database::getInstance()->getConnection();
        
        $stmt = $db->prepare("
            SELECT locked_until 
            FROM login_attempts 
            WHERE ip_address = ? 
            AND locked_until > NOW()
            LIMIT 1
        ");
        $stmt->execute([$ip]);
        
        return $stmt->fetch() !== false;
    }
    
    /**
     * Gibt verbleibende Sperrzeit zurück (in Sekunden)
     */
    public static function getRemainingLockTime($ip) {
        $db = Database::getInstance()->getConnection();
        
        $stmt = $db->prepare("
            SELECT TIMESTAMPDIFF(SECOND, NOW(), locked_until) as remaining 
            FROM login_attempts 
            WHERE ip_address = ? 
            AND locked_until > NOW()
            LIMIT 1
        ");
        $stmt->execute([$ip]);
        $result = $stmt->fetch();
        
        return $result ? max(0, $result['remaining']) : 0;
    }
    
    /**
     * Registriert fehlgeschlagenen Login-Versuch
     */
    public static function recordFailedAttempt($username, $ip) {
        $db = Database::getInstance()->getConnection();
        
        // Fehlversuch speichern
        $stmt = $db->prepare("
            INSERT INTO login_attempts 
            (username, ip_address, attempt_time, success) 
            VALUES (?, ?, NOW(), 0)
        ");
        $stmt->execute([$username, $ip]);
        
        // Anzahl Fehlversuche im Zeitfenster prüfen
        $stmt = $db->prepare("
            SELECT COUNT(*) as attempt_count 
            FROM login_attempts 
            WHERE ip_address = ? 
            AND success = 0 
            AND attempt_time > DATE_SUB(NOW(), INTERVAL ? SECOND)
        ");
        $stmt->execute([$ip, LOGIN_ATTEMPT_WINDOW]);
        $result = $stmt->fetch();
        
        $attemptCount = $result['attempt_count'];
        
        // IP sperren wenn zu viele Versuche
        if ($attemptCount >= MAX_LOGIN_ATTEMPTS) {
            $stmt = $db->prepare("
                UPDATE login_attempts 
                SET locked_until = DATE_ADD(NOW(), INTERVAL ? SECOND)
                WHERE ip_address = ?
            ");
            $stmt->execute([LOGIN_LOCKOUT_TIME, $ip]);
            
            AuditLogger::log('ip_locked', "IP locked after $attemptCount failed login attempts", [
                'ip_address' => $ip,
                'username' => $username,
                'attempt_count' => $attemptCount,
                'lockout_seconds' => LOGIN_LOCKOUT_TIME
            ]);
            
            return true; // IP wurde gesperrt
        }
        
        return false; // IP noch nicht gesperrt
    }
    
    /**
     * Registriert erfolgreichen Login
     */
    public static function recordSuccessfulLogin($username, $ip) {
        $db = Database::getInstance()->getConnection();
        
        // Erfolgreichen Login speichern
        $stmt = $db->prepare("
            INSERT INTO login_attempts 
            (username, ip_address, attempt_time, success) 
            VALUES (?, ?, NOW(), 1)
        ");
        $stmt->execute([$username, $ip]);
        
        // Alte Fehlversuche für diese IP löschen
        $stmt = $db->prepare("
            DELETE FROM login_attempts 
            WHERE ip_address = ? 
            AND success = 0
        ");
        $stmt->execute([$ip]);
    }
    
    /**
     * Gibt verbleibende Login-Versuche zurück
     */
    public static function getRemainingAttempts($ip) {
        $db = Database::getInstance()->getConnection();
        
        $stmt = $db->prepare("
            SELECT COUNT(*) as attempt_count 
            FROM login_attempts 
            WHERE ip_address = ? 
            AND success = 0 
            AND attempt_time > DATE_SUB(NOW(), INTERVAL ? SECOND)
        ");
        $stmt->execute([$ip, LOGIN_ATTEMPT_WINDOW]);
        $result = $stmt->fetch();
        
        $attemptCount = $result['attempt_count'];
        return max(0, MAX_LOGIN_ATTEMPTS - $attemptCount);
    }
    
    /**
     * Alte Login-Versuche aufräumen (Cronjob)
     */
    public static function cleanupOldAttempts() {
        $db = Database::getInstance()->getConnection();
        
        // Alte Einträge löschen (älter als 30 Tage)
        $stmt = $db->query("
            DELETE FROM login_attempts 
            WHERE attempt_time < DATE_SUB(NOW(), INTERVAL 30 DAY)
        ");
        
        return $stmt->rowCount();
    }
}

// ===== NEUE KLASSE: Audit-Logger =====
class AuditLogger {
    
    /**
     * Schreibt Audit-Log-Eintrag
     * 
     * @param string $action Art der Aktion
     * @param string $description Beschreibung
     * @param array $details Zusätzliche Details
     * @param string $severity Schweregrad (info, warning, error, critical)
     */
    public static function log($action, $description = '', $details = [], $severity = 'info') {
        if (!AUDIT_LOG_ENABLED) {
            return;
        }
        
        $userId = Auth::getUserId() ?? null;
        $username = Auth::getUsername() ?? 'anonymous';
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        $requestMethod = $_SERVER['REQUEST_METHOD'] ?? '';
        
        // In Datenbank schreiben
        try {
            $db = Database::getInstance()->getConnection();
            
            $stmt = $db->prepare("
                INSERT INTO audit_log 
                (user_id, username, ip_address, user_agent, action, description, 
                 details, severity, request_uri, request_method, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            
            $stmt->execute([
                $userId,
                $username,
                $ip,
                substr($userAgent, 0, 500),
                $action,
                $description,
                json_encode($details, JSON_UNESCAPED_UNICODE),
                $severity,
                $requestUri,
                $requestMethod
            ]);
            
        } catch (Exception $e) {
            error_log("Audit log database error: " . $e->getMessage());
        }
        
        // Zusätzlich in Datei schreiben
        try {
            $timestamp = date('Y-m-d H:i:s');
            $logFile = AUDIT_LOG_PATH . date('Y-m-d') . '.log';
            
            $logEntry = sprintf(
                "[%s] [%s] User: %s (ID: %s) | IP: %s | Action: %s | %s | Details: %s\n",
                $timestamp,
                strtoupper($severity),
                $username,
                $userId ?? 'NULL',
                $ip,
                $action,
                $description,
                json_encode($details, JSON_UNESCAPED_UNICODE)
            );
            
            file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
            
        } catch (Exception $e) {
            error_log("Audit log file error: " . $e->getMessage());
        }
    }
    
    /**
     * Sucht im Audit-Log
     */
    public static function search($filters = [], $limit = 100, $offset = 0) {
        $db = Database::getInstance()->getConnection();
        
        $sql = "SELECT * FROM audit_log WHERE 1=1";
        $params = [];
        
        if (!empty($filters['user_id'])) {
            $sql .= " AND user_id = ?";
            $params[] = $filters['user_id'];
        }
        
        if (!empty($filters['username'])) {
            $sql .= " AND username LIKE ?";
            $params[] = '%' . $filters['username'] . '%';
        }
        
        if (!empty($filters['ip_address'])) {
            $sql .= " AND ip_address = ?";
            $params[] = $filters['ip_address'];
        }
        
        if (!empty($filters['action'])) {
            $sql .= " AND action = ?";
            $params[] = $filters['action'];
        }
        
        if (!empty($filters['severity'])) {
            $sql .= " AND severity = ?";
            $params[] = $filters['severity'];
        }
        
        if (!empty($filters['date_from'])) {
            $sql .= " AND created_at >= ?";
            $params[] = $filters['date_from'];
        }
        
        if (!empty($filters['date_to'])) {
            $sql .= " AND created_at <= ?";
            $params[] = $filters['date_to'];
        }
        
        $sql .= " ORDER BY created_at DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll();
    }
    
    /**
     * Alte Audit-Logs aufräumen
     */
    public static function cleanup() {
        $db = Database::getInstance()->getConnection();
        
        $stmt = $db->prepare("
            DELETE FROM audit_log 
            WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
        ");
        $stmt->execute([AUDIT_LOG_RETENTION_DAYS]);
        
        $deletedRows = $stmt->rowCount();
        
        // Datei-Logs älter als Retention-Periode löschen
        $files = glob(AUDIT_LOG_PATH . '*.log');
        $cutoffDate = strtotime('-' . AUDIT_LOG_RETENTION_DAYS . ' days');
        $deletedFiles = 0;
        
        foreach ($files as $file) {
            if (filemtime($file) < $cutoffDate) {
                unlink($file);
                $deletedFiles++;
            }
        }
        
        return [
            'deleted_rows' => $deletedRows,
            'deleted_files' => $deletedFiles
        ];
    }
}

// RBAC - Authentifizierung und Rechteverwaltung (ERWEITERT)
class Auth {
    
    private static $userPermissionsCache = null;
    
    public static function login($username, $password) {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        
        // IP-Whitelist prüfen (wenn konfiguriert)
        if (!empty(IP_WHITELIST) && !in_array($ip, IP_WHITELIST)) {
            AuditLogger::log('login_blocked', 'Login attempt from non-whitelisted IP', [
                'ip_address' => $ip,
                'username' => $username
            ], 'warning');
            return false;
        }
        
        // IP-Sperre prüfen
        if (LoginAttemptsManager::isIpLocked($ip)) {
            $remainingTime = LoginAttemptsManager::getRemainingLockTime($ip);
            $minutes = ceil($remainingTime / 60);
            
            AuditLogger::log('login_blocked', 'Login attempt from locked IP', [
                'ip_address' => $ip,
                'username' => $username,
                'remaining_seconds' => $remainingTime
            ], 'warning');
            
            throw new Exception("Zu viele fehlgeschlagene Login-Versuche. Bitte versuchen Sie es in $minutes Minute(n) erneut.");
        }
        
        $db = Database::getInstance()->getConnection();
        
        $stmt = $db->prepare("
            SELECT u.*, r.name as role_name, r.display_name as role_display_name 
            FROM users u 
            INNER JOIN roles r ON u.role_id = r.id 
            WHERE u.username = ? AND u.is_active = 1
        ");
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        
        if ($user && password_verify($password, $user['password'])) {
            // Erfolgreicher Login
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role_id'] = $user['role_id'];
            $_SESSION['role_name'] = $user['role_name'];
            $_SESSION['role_display_name'] = $user['role_display_name'];
            $_SESSION['session_started'] = time();
            $_SESSION['last_activity'] = time();
            $_SESSION['last_regeneration'] = time();
            $_SESSION['login_ip'] = $ip;
            
            $updateStmt = $db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
            $updateStmt->execute([$user['id']]);
            
            self::loadUserPermissions();
            
            LoginAttemptsManager::recordSuccessfulLogin($username, $ip);
            
            AuditLogger::log('login_success', 'User logged in successfully', [
                'username' => $username,
                'role' => $user['role_name']
            ], 'info');
            
            return true;
        }
        
        // Fehlgeschlagener Login
        $locked = LoginAttemptsManager::recordFailedAttempt($username, $ip);
        $remaining = LoginAttemptsManager::getRemainingAttempts($ip);
        
        AuditLogger::log('login_failed', 'Failed login attempt', [
            'username' => $username,
            'remaining_attempts' => $remaining,
            'ip_locked' => $locked
        ], 'warning');
        
        return false;
    }
    
    public static function logout() {
        if (self::isLoggedIn()) {
            AuditLogger::log('logout', 'User logged out', [
                'session_duration' => time() - ($_SESSION['session_started'] ?? time())
            ]);
        }
        
        session_destroy();
    }
    
    public static function isLoggedIn() {
        return isset($_SESSION['user_id']);
    }
    
    public static function requireLogin() {
        if (!self::isLoggedIn()) {
            http_response_code(403);
            exit(json_encode(['success' => false, 'message' => 'Anmeldung erforderlich']));
        }
    }
    
    public static function loadUserPermissions() {
        if (!self::isLoggedIn()) {
            return [];
        }
        
        if (self::$userPermissionsCache !== null) {
            return self::$userPermissionsCache;
        }
        
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            SELECT p.name 
            FROM permissions p
            INNER JOIN role_permissions rp ON p.id = rp.permission_id
            WHERE rp.role_id = ?
        ");
        $stmt->execute([$_SESSION['role_id']]);
        
        $permissions = [];
        while ($row = $stmt->fetch()) {
            $permissions[] = $row['name'];
        }
        
        self::$userPermissionsCache = $permissions;
        $_SESSION['user_permissions'] = $permissions;
        
        return $permissions;
    }
    
    public static function hasPermission($permission) {
        if (!self::isLoggedIn()) {
            return false;
        }
        
        if (self::$userPermissionsCache === null) {
            if (isset($_SESSION['user_permissions'])) {
                self::$userPermissionsCache = $_SESSION['user_permissions'];
            } else {
                self::loadUserPermissions();
            }
        }
        
        return in_array($permission, self::$userPermissionsCache);
    }
    
    public static function hasAnyPermission($permissions) {
        foreach ($permissions as $permission) {
            if (self::hasPermission($permission)) {
                return true;
            }
        }
        return false;
    }
    
    public static function hasAllPermissions($permissions) {
        foreach ($permissions as $permission) {
            if (!self::hasPermission($permission)) {
                return false;
            }
        }
        return true;
    }
    
    public static function requirePermission($permission, $errorMessage = null) {
        if (!self::hasPermission($permission)) {
            $message = $errorMessage ?? "Fehlende Berechtigung: " . $permission;
            
            AuditLogger::log('permission_denied', $message, [
                'required_permission' => $permission
            ], 'warning');
            
            http_response_code(403);
            exit(json_encode(['success' => false, 'message' => $message]));
        }
    }
    
    public static function requireAnyPermission($permissions, $errorMessage = null) {
        if (!self::hasAnyPermission($permissions)) {
            $message = $errorMessage ?? "Sie haben keine der erforderlichen Berechtigungen";
            
            AuditLogger::log('permission_denied', $message, [
                'required_permissions' => $permissions
            ], 'warning');
            
            http_response_code(403);
            exit(json_encode(['success' => false, 'message' => $message]));
        }
    }
    
    public static function requireAllPermissions($permissions, $errorMessage = null) {
        if (!self::hasAllPermissions($permissions)) {
            $message = $errorMessage ?? "Sie haben nicht alle erforderlichen Berechtigungen";
            
            AuditLogger::log('permission_denied', $message, [
                'required_permissions' => $permissions
            ], 'warning');
            
            http_response_code(403);
            exit(json_encode(['success' => false, 'message' => $message]));
        }
    }
    
    public static function isAdmin() {
        if (!self::isLoggedIn()) {
            return false;
        }
        return isset($_SESSION['role_name']) && $_SESSION['role_name'] === 'administrator';
    }
    
    public static function requireAdmin() {
        self::requireLogin();
        if (!self::isAdmin()) {
            AuditLogger::log('admin_required', 'Admin access denied', [], 'warning');
            http_response_code(403);
            exit(json_encode(['success' => false, 'message' => 'Administrator-Berechtigung erforderlich']));
        }
    }
    
    public static function getUserId() {
        return $_SESSION['user_id'] ?? null;
    }
    
    public static function getUsername() {
        return $_SESSION['username'] ?? null;
    }
    
    public static function getRoleId() {
        return $_SESSION['role_id'] ?? null;
    }
    
    public static function getRoleName() {
        return $_SESSION['role_name'] ?? null;
    }
    
    public static function getRoleDisplayName() {
        return $_SESSION['role_display_name'] ?? null;
    }
    
    public static function getUserPermissions() {
        if (self::$userPermissionsCache === null) {
            self::loadUserPermissions();
        }
        return self::$userPermissionsCache;
    }
}

// [REST DER KLASSEN BLEIBEN UNVERÄNDERT]
// ... RoleManager, MaintenanceManager, EmailManager, ImageHelper, CategoryHelper, ImageGallery ...

// Rollen-Verwaltung
class RoleManager {
    
    public static function getAllRoles() {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->query("
            SELECT r.*, COUNT(rp.permission_id) as permission_count
            FROM roles r
            LEFT JOIN role_permissions rp ON r.id = rp.role_id
            GROUP BY r.id
            ORDER BY r.is_system_role DESC, r.name ASC
        ");
        return $stmt->fetchAll();
    }
    
    public static function getRoleById($roleId) {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT * FROM roles WHERE id = ?");
        $stmt->execute([$roleId]);
        return $stmt->fetch();
    }
    
    public static function getRolePermissions($roleId) {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            SELECT p.* 
            FROM permissions p
            INNER JOIN role_permissions rp ON p.id = rp.permission_id
            WHERE rp.role_id = ?
            ORDER BY p.category, p.name
        ");
        $stmt->execute([$roleId]);
        return $stmt->fetchAll();
    }
    
    public static function getAllPermissions() {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->query("SELECT * FROM permissions ORDER BY category, name");
        return $stmt->fetchAll();
    }
    
    public static function getPermissionsByCategory() {
        $permissions = self::getAllPermissions();
        $grouped = [];
        
        foreach ($permissions as $permission) {
            $category = $permission['category'];
            if (!isset($grouped[$category])) {
                $grouped[$category] = [];
            }
            $grouped[$category][] = $permission;
        }
        
        return $grouped;
    }
    
    public static function createRole($name, $displayName, $description = null, $permissions = []) {
        $db = Database::getInstance()->getConnection();
        
        try {
            $db->beginTransaction();
            
            $stmt = $db->prepare("
                INSERT INTO roles (name, display_name, description, is_system_role) 
                VALUES (?, ?, ?, 0)
            ");
            $stmt->execute([$name, $displayName, $description]);
            $roleId = $db->lastInsertId();
            
            if (!empty($permissions)) {
                self::updateRolePermissions($roleId, $permissions);
            }
            
            $db->commit();
            
            AuditLogger::log('role_created', "Role created: $displayName", [
                'role_id' => $roleId,
                'role_name' => $name,
                'permissions_count' => count($permissions)
            ]);
            
            return $roleId;
            
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }
    
    public static function updateRole($roleId, $displayName, $description = null, $permissions = []) {
        $db = Database::getInstance()->getConnection();
        
        $role = self::getRoleById($roleId);
        if (!$role) {
            throw new Exception('Rolle nicht gefunden');
        }
        
        if ($role['is_system_role']) {
            throw new Exception('System-Rollen können nicht bearbeitet werden');
        }
        
        try {
            $db->beginTransaction();
            
            $stmt = $db->prepare("
                UPDATE roles 
                SET display_name = ?, description = ? 
                WHERE id = ?
            ");
            $stmt->execute([$displayName, $description, $roleId]);
            
            self::updateRolePermissions($roleId, $permissions);
            
            $db->commit();
            
            AuditLogger::log('role_updated', "Role updated: $displayName", [
                'role_id' => $roleId,
                'role_name' => $role['name'],
                'permissions_count' => count($permissions)
            ]);
            
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }
    
    public static function deleteRole($roleId) {
        $db = Database::getInstance()->getConnection();
        
        $role = self::getRoleById($roleId);
        if (!$role) {
            throw new Exception('Rolle nicht gefunden');
        }
        
        if ($role['is_system_role']) {
            throw new Exception('System-Rollen können nicht gelöscht werden');
        }
        
        $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE role_id = ?");
        $stmt->execute([$roleId]);
        $userCount = $stmt->fetchColumn();
        
        if ($userCount > 0) {
            throw new Exception("Diese Rolle wird noch von $userCount Benutzer(n) verwendet und kann nicht gelöscht werden");
        }
        
        $stmt = $db->prepare("DELETE FROM roles WHERE id = ?");
        $stmt->execute([$roleId]);
        
        AuditLogger::log('role_deleted', "Role deleted: {$role['display_name']}", [
            'role_id' => $roleId,
            'role_name' => $role['name']
        ]);
    }
    
    private static function updateRolePermissions($roleId, $permissions) {
        $db = Database::getInstance()->getConnection();
        
        $stmt = $db->prepare("DELETE FROM role_permissions WHERE role_id = ?");
        $stmt->execute([$roleId]);
        
        if (!empty($permissions)) {
            $stmt = $db->prepare("
                INSERT INTO role_permissions (role_id, permission_id) 
                VALUES (?, ?)
            ");
            
            foreach ($permissions as $permissionId) {
                $stmt->execute([$roleId, $permissionId]);
            }
        }
    }
}

// Wartungs-Manager
class MaintenanceManager {
    
    /**
     * Berechnet nächstes Wartungsdatum
     */
    public static function calculateNextMaintenanceDate($lastMaintenance, $intervalDays) {
        if (empty($lastMaintenance) || empty($intervalDays)) {
            return null;
        }
        
        $date = new DateTime($lastMaintenance);
        $date->add(new DateInterval("P{$intervalDays}D"));
        return $date->format('Y-m-d');
    }
    
    /**
     * Prüft ob Wartung fällig ist
     */
    public static function isMaintenanceDue($nextMaintenanceDate) {
        if (empty($nextMaintenanceDate)) {
            return false;
        }
        
        $today = new DateTime();
        $maintenanceDate = new DateTime($nextMaintenanceDate);
        
        return $maintenanceDate <= $today;
    }
    
    /**
     * Gibt Tage bis zur Wartung zurück (negativ = überfällig)
     */
    public static function getDaysUntilMaintenance($nextMaintenanceDate) {
        if (empty($nextMaintenanceDate)) {
            return null;
        }
        
        $today = new DateTime();
        $maintenanceDate = new DateTime($nextMaintenanceDate);
        $interval = $today->diff($maintenanceDate);
        
        return $interval->invert ? -$interval->days : $interval->days;
    }
    
    /**
     * Formatiert Wartungsintervall für Anzeige
     */
    public static function formatMaintenanceInterval($days) {
        if (empty($days)) {
            return 'Nicht festgelegt';
        }
        
        if ($days % 365 === 0) {
            $years = $days / 365;
            return $years . ($years === 1 ? ' Jahr' : ' Jahre');
        } elseif ($days % 30 === 0) {
            $months = $days / 30;
            return $months . ($months === 1 ? ' Monat' : ' Monate');
        } elseif ($days % 7 === 0) {
            $weeks = $days / 7;
            return $weeks . ($weeks === 1 ? ' Woche' : ' Wochen');
        } else {
            return $days . ' Tage';
        }
    }
}

// E-Mail-Manager
class EmailManager {
    
    /**
     * Sendet E-Mail-Benachrichtigung
     */
    public static function sendMail($to, $subject, $message, $logType = 'maintenance') {
        if (!SEND_EMAIL_NOTIFICATIONS) {
            return false;
        }
        
        $headers = "From: " . EMAIL_FROM_NAME . " <" . EMAIL_FROM_ADDRESS . ">\r\n";
        $headers .= "Reply-To: " . EMAIL_FROM_ADDRESS . "\r\n";
        $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";
        $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
        
        $success = mail($to, $subject, $message, $headers);
        
        self::logEmail($to, $subject, $message, $success, $logType);
        
        return $success;
    }
    
    /**
     * Holt alle Benutzer die Wartungsbenachrichtigungen erhalten sollen
     */
    public static function getMaintenanceNotificationRecipients() {
        $db = Database::getInstance()->getConnection();
        
        $stmt = $db->query("
            SELECT u.id, u.username, u.email, r.display_name as role_name
            FROM users u
            INNER JOIN roles r ON u.role_id = r.id
            WHERE u.is_active = 1 
              AND u.receive_maintenance_notifications = 1
              AND u.email IS NOT NULL
              AND u.email != ''
        ");
        
        return $stmt->fetchAll();
    }
    
    /**
     * Loggt gesendete E-Mails
     */
    private static function logEmail($recipient, $subject, $message, $success, $type = 'maintenance') {
        try {
            $db = Database::getInstance()->getConnection();
            
            $stmt = $db->prepare("
                INSERT INTO email_log (recipient_email, subject, message, success, notification_type)
                VALUES (?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $recipient,
                $subject,
                $message,
                $success ? 1 : 0,
                $type
            ]);
        } catch (Exception $e) {
            error_log("Email log error: " . $e->getMessage());
        }
    }
    
    /**
     * Sendet Wartungsbenachrichtigungen an alle registrierten Empfänger
     */
    public static function sendMaintenanceNotifications($notifications) {
        if (empty($notifications)) {
            return 0;
        }
        
        $recipients = self::getMaintenanceNotificationRecipients();
        
        if (empty($recipients)) {
            error_log("No maintenance notification recipients configured");
            return 0;
        }
        
        $sentCount = 0;
        
        foreach ($recipients as $recipient) {
            $subject = 'Wartungsbenachrichtigung - ' . SYSTEM_NAME;
            
            $message = "Hallo {$recipient['username']},\n\n";
            $message .= "Automatische Wartungsbenachrichtigung\n";
            $message .= "Datum: " . date('d.m.Y H:i:s') . "\n\n";
            $message .= "Folgende Wartungen wurden automatisch durchgeführt oder sind fällig:\n\n";
            
            foreach ($notifications as $notification) {
                $message .= "• " . $notification['title'] . ": " . $notification['message'] . "\n";
                if (isset($notification['days_overdue']) && $notification['days_overdue'] > 0) {
                    $message .= "  (Überfällig seit {$notification['days_overdue']} Tagen)\n";
                }
                $message .= "\n";
            }
            
            $message .= "\n---\n";
            $message .= "Diese E-Mail wurde automatisch generiert.\n";
            $message .= "Bitte melden Sie sich im System an um weitere Details zu sehen.\n\n";
            $message .= "System-URL: https://" . $_SERVER['HTTP_HOST'] . "\n";
            
            if (self::sendMail($recipient['email'], $subject, $message)) {
                $sentCount++;
                error_log("Maintenance notification sent to: {$recipient['email']}");
            } else {
                error_log("Failed to send maintenance notification to: {$recipient['email']}");
            }
        }
        
        return $sentCount;
    }
}

// Hilfsfunktionen für Bildverarbeitung
class ImageHelper {
    
    public static function handleImageUpload($file, $isBackground = false) {
        $maxSize = $isBackground ? BACKGROUND_MAX_SIZE : MAX_FILE_SIZE;
        $allowedExt = $isBackground ? BACKGROUND_ALLOWED_EXT : ALLOWED_EXTENSIONS;
        $uploadPath = $isBackground ? BACKGROUND_PATH : UPLOAD_PATH;
        
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        if (!in_array($extension, $allowedExt)) {
            throw new Exception('Ungültiger Dateityp. Erlaubt: ' . implode(', ', $allowedExt));
        }
        
        if ($file['size'] > $maxSize) {
            $maxSizeMB = round($maxSize / (1024 * 1024), 1);
            throw new Exception("Datei zu groß. Maximum: {$maxSizeMB}MB");
        }
        
        $imageInfo = getimagesize($file['tmp_name']);
        if ($imageInfo === false) {
            throw new Exception('Datei ist kein gültiges Bild');
        }
        
        $filename = $isBackground ? 'background.' . $extension : uniqid() . '.' . $extension;
        $filepath = $uploadPath . $filename;
        
        if (move_uploaded_file($file['tmp_name'], $filepath)) {
            return $filepath;
        }
        
        throw new Exception('Upload fehlgeschlagen');
    }
    
    public static function resizeImage($imagePath, $maxWidth, $maxHeight = null) {
        if (!file_exists($imagePath)) {
            throw new Exception('Bild nicht gefunden');
        }
        
        $imageInfo = getimagesize($imagePath);
        if (!$imageInfo) {
            throw new Exception('Ungültiges Bild');
        }
        
        $originalWidth = $imageInfo[0];
        $originalHeight = $imageInfo[1];
        $imageType = $imageInfo[2];
        
        if ($originalWidth <= $maxWidth && ($maxHeight === null || $originalHeight <= $maxHeight)) {
            return true;
        }
        
        if ($maxHeight === null) {
            $ratio = $maxWidth / $originalWidth;
            $newWidth = $maxWidth;
            $newHeight = intval($originalHeight * $ratio);
        } else {
            $ratioW = $maxWidth / $originalWidth;
            $ratioH = $maxHeight / $originalHeight;
            $ratio = min($ratioW, $ratioH);
            $newWidth = intval($originalWidth * $ratio);
            $newHeight = intval($originalHeight * $ratio);
        }
        
        switch ($imageType) {
            case IMAGETYPE_JPEG:
                $originalImage = imagecreatefromjpeg($imagePath);
                break;
            case IMAGETYPE_PNG:
                $originalImage = imagecreatefrompng($imagePath);
                break;
            case IMAGETYPE_GIF:
                $originalImage = imagecreatefromgif($imagePath);
                break;
            default:
                throw new Exception('Nicht unterstütztes Bildformat');
        }
        
        if ($originalImage === false) {
            throw new Exception('Fehler beim Laden des Bildes');
        }
        
        $newImage = imagecreatetruecolor($newWidth, $newHeight);
        
        if ($imageType == IMAGETYPE_PNG) {
            imagealphablending($newImage, false);
            imagesavealpha($newImage, true);
            $transparent = imagecolorallocatealpha($newImage, 255, 255, 255, 127);
            imagefilledrectangle($newImage, 0, 0, $newWidth, $newHeight, $transparent);
        }
        
        if (!imagecopyresampled($newImage, $originalImage, 0, 0, 0, 0, $newWidth, $newHeight, $originalWidth, $originalHeight)) {
            imagedestroy($originalImage);
            imagedestroy($newImage);
            throw new Exception('Fehler beim Skalieren des Bildes');
        }
        
        $success = false;
        switch ($imageType) {
            case IMAGETYPE_JPEG:
                $success = imagejpeg($newImage, $imagePath, 90);
                break;
            case IMAGETYPE_PNG:
                $success = imagepng($newImage, $imagePath);
                break;
            case IMAGETYPE_GIF:
                $success = imagegif($newImage, $imagePath);
                break;
        }
        
        imagedestroy($originalImage);
        imagedestroy($newImage);
        
        if (!$success) {
            throw new Exception('Fehler beim Speichern des skalierten Bildes');
        }
        
        return true;
    }
    
    public static function getCurrentBackground() {
        $files = glob(BACKGROUND_PATH . 'background.*');
        return !empty($files) ? $files[0] : null;
    }
    
    public static function deleteOldBackgrounds() {
        $files = glob(BACKGROUND_PATH . '*');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }
}

// Sicherheitsfunktionen
function sanitizeInput($input) {
    return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
}

function validateCSRF($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function generateCSRF() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// Error Handling
function handleError($message, $code = 500) {
    http_response_code($code);
    if (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => $message]);
    } else {
        echo "<h1>Fehler $code</h1><p>$message</p>";
    }
    exit;
}

// Logging (Legacy - für Kompatibilität)
function logActivity($action, $details = '') {
    AuditLogger::log($action, $details);
}

// Zusätzliche Validierungsfunktionen
function validateEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function validateImageFile($file) {
    if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        return false;
    }
    
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    return in_array($mimeType, $allowedTypes);
}

function validateDate($date, $format = 'Y-m-d') {
    $d = DateTime::createFromFormat($format, $date);
    return $d && $d->format($format) === $date;
}

/**
 * Kategorie-Manager Helper
 */
class CategoryHelper {
    
    private static $categoriesCache = null;
    
    /**
     * Holt alle aktiven Kategorien (gecached)
     */
    public static function getActiveCategories() {
        if (self::$categoriesCache === null) {
            $db = Database::getInstance()->getConnection();
            $stmt = $db->query("
                SELECT name, display_name, color, icon 
                FROM categories 
                WHERE is_active = 1 
                ORDER BY sort_order ASC, display_name ASC
            ");
            self::$categoriesCache = $stmt->fetchAll();
        }
        return self::$categoriesCache;
    }
    
    /**
     * Holt Kategorie-Namen als Array
     */
    public static function getCategoryNames() {
        $categories = self::getActiveCategories();
        return array_column($categories, 'name');
    }
    
    /**
     * Formatiert Kategorie mit Icon und Farbe
     */
    public static function formatCategory($categoryName) {
        $categories = self::getActiveCategories();
        foreach ($categories as $cat) {
            if ($cat['name'] === $categoryName) {
                return [
                    'name' => $cat['name'],
                    'display_name' => $cat['display_name'],
                    'icon' => $cat['icon'],
                    'color' => $cat['color']
                ];
            }
        }
        return [
            'name' => $categoryName,
            'display_name' => ucfirst($categoryName),
            'icon' => '📦',
            'color' => '#6bb032'
        ];
    }

    public static function getStorageDeviceColor() {
        try {
            $db = Database::getInstance()->getConnection();
            $stmt = $db->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'storage_device_color'");
            $stmt->execute();
            $result = $stmt->fetchColumn();
            return $result ?: '#9e9e9e';
        } catch (Exception $e) {
            return '#9e9e9e';
        }
    }

    public static function setStorageDeviceColor($color) {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            INSERT INTO system_settings (setting_key, setting_value, setting_type, updated_by) 
            VALUES ('storage_device_color', ?, 'string', ?)
            ON DUPLICATE KEY UPDATE setting_value = ?, updated_by = ?
        ");
        $userId = Auth::getUserId();
        $stmt->execute([$color, $userId, $color, $userId]);
        
        AuditLogger::log('setting_changed', 'Storage device color changed', [
            'setting_key' => 'storage_device_color',
            'new_value' => $color
        ]);
    }
}

/**
 * Bildergalerie-Manager für Objekte
 */
class ImageGallery {
    
    /**
     * Holt alle Bilder eines Objekts
     */
    public static function getObjectImages($objectId) {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT * FROM object_images WHERE object_id = ? ORDER BY sort_order ASC, id ASC");
        $stmt->execute([$objectId]);
        return $stmt->fetchAll();
    }
    
    /**
     * Fügt ein Bild hinzu
     */
    public static function addImage($objectId, $filePath, $sortOrder = null) {
        $db = Database::getInstance()->getConnection();
        
        if ($sortOrder === null) {
            $stmt = $db->prepare("SELECT MAX(sort_order) FROM object_images WHERE object_id = ?");
            $stmt->execute([$objectId]);
            $maxOrder = $stmt->fetchColumn();
            $sortOrder = ($maxOrder !== null) ? $maxOrder + 1 : 0;
        }
        
        $stmt = $db->prepare("INSERT INTO object_images (object_id, image_path, sort_order) VALUES (?, ?, ?)");
        $stmt->execute([$objectId, $filePath, $sortOrder]);
        
        $imageId = $db->lastInsertId();
        
        AuditLogger::log('image_added', "Image added to object", [
            'object_id' => $objectId,
            'image_id' => $imageId,
            'file_path' => $filePath
        ]);
        
        return $imageId;
    }
    
    /**
     * Löscht ein Bild
     */
    public static function deleteImage($imageId) {
        $db = Database::getInstance()->getConnection();
        
        $stmt = $db->prepare("SELECT image_path, object_id FROM object_images WHERE id = ?");
        $stmt->execute([$imageId]);
        $image = $stmt->fetch();
        
        if ($image) {
            if ($image['image_path'] && file_exists($image['image_path'])) {
                unlink($image['image_path']);
            }
            
            $stmt = $db->prepare("DELETE FROM object_images WHERE id = ?");
            $stmt->execute([$imageId]);
            
            AuditLogger::log('image_deleted', "Image deleted from object", [
                'object_id' => $image['object_id'],
                'image_id' => $imageId
            ]);
        }
    }
    
    /**
     * Ändert die Reihenfolge
     */
    public static function reorderImages($objectId, $imageIds) {
        $db = Database::getInstance()->getConnection();
        
        try {
            $db->beginTransaction();
            
            $stmt = $db->prepare("UPDATE object_images SET sort_order = ? WHERE id = ? AND object_id = ?");
            
            foreach ($imageIds as $index => $imageId) {
                $stmt->execute([$index, $imageId, $objectId]);
            }
            
            $db->commit();
            
            AuditLogger::log('images_reordered', "Images reordered for object", [
                'object_id' => $objectId,
                'image_count' => count($imageIds)
            ]);
            
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }
    
    /**
     * Löscht alle Bilder eines Objekts
     */
    public static function deleteAllImages($objectId) {
        $images = self::getObjectImages($objectId);
        foreach ($images as $image) {
            self::deleteImage($image['id']);
        }
    }
}