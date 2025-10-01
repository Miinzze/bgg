<?php
require_once 'config.php';

Auth::requireAdmin();

$message = '';
$error = '';

// Einstellungen speichern
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        if ($_POST['action'] === 'update_security_settings') {
            $sessionTimeout = intval($_POST['session_timeout']);
            $maxLoginAttempts = intval($_POST['max_login_attempts']);
            $loginLockoutTime = intval($_POST['login_lockout_time']);
            $enableAuditLog = isset($_POST['enable_audit_log']) ? 1 : 0;
            
            // Validierung
            if ($sessionTimeout < 300) {
                throw new Exception('Session-Timeout muss mindestens 300 Sekunden (5 Minuten) sein');
            }
            if ($sessionTimeout > 86400) {
                throw new Exception('Session-Timeout darf maximal 86400 Sekunden (24 Stunden) sein');
            }
            if ($maxLoginAttempts < 3 || $maxLoginAttempts > 20) {
                throw new Exception('Login-Versuche müssen zwischen 3 und 20 liegen');
            }
            if ($loginLockoutTime < 300 || $loginLockoutTime > 3600) {
                throw new Exception('Sperrzeit muss zwischen 300 und 3600 Sekunden liegen');
            }
            
            $db = Database::getInstance()->getConnection();
            $userId = Auth::getUserId();
            
            // Einstellungen speichern
            $settings = [
                'session_timeout' => $sessionTimeout,
                'max_login_attempts' => $maxLoginAttempts,
                'login_lockout_time' => $loginLockoutTime,
                'enable_audit_log' => $enableAuditLog
            ];
            
            $stmt = $db->prepare("
                INSERT INTO system_settings (setting_key, setting_value, setting_type, updated_by) 
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE setting_value = ?, updated_by = ?, updated_at = NOW()
            ");
            
            foreach ($settings as $key => $value) {
                $type = in_array($key, ['enable_audit_log']) ? 'boolean' : 'integer';
                $stmt->execute([$key, $value, $type, $userId, $value, $userId]);
            }
            
            AuditLog::log('system_settings', 'update', null, [
                'settings' => $settings
            ]);
            
            $message = 'Sicherheitseinstellungen erfolgreich gespeichert';
            
        } elseif ($_POST['action'] === 'cleanup_login_attempts') {
            $deleted = LoginAttemptManager::cleanupOldAttempts(30);
            AuditLog::log('system_maintenance', 'cleanup_login_attempts', null, [
                'deleted_count' => $deleted
            ]);
            $message = "$deleted alte Login-Versuche wurden gelöscht";
            
        } elseif ($_POST['action'] === 'cleanup_audit_log') {
            $days = intval($_POST['days'] ?? 365);
            $deleted = AuditLog::cleanupOldEntries($days);
            AuditLog::log('system_maintenance', 'cleanup_audit_log', null, [
                'deleted_count' => $deleted,
                'older_than_days' => $days
            ]);
            $message = "$deleted alte Audit-Log Einträge wurden gelöscht";
        }
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Aktuelle Einstellungen laden
$db = Database::getInstance()->getConnection();

function getSetting($key, $default) {
    global $db;
    $stmt = $db->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    return $value !== false ? $value : $default;
}

$sessionTimeout = getSetting('session_timeout', DEFAULT_SESSION_TIMEOUT);
$maxLoginAttempts = getSetting('max_login_attempts', MAX_LOGIN_ATTEMPTS);
$loginLockoutTime = getSetting('login_lockout_time', LOGIN_LOCKOUT_TIME);
$enableAuditLog = getSetting('enable_audit_log', ENABLE_AUDIT_LOG ? '1' : '0');

// Statistiken
$stmt = $db->query("SELECT COUNT(*) FROM login_attempts WHERE attempt_time > DATE_SUB(NOW(), INTERVAL 24 HOUR)");
$loginAttemptsLast24h = $stmt->fetchColumn();

$stmt = $db->query("SELECT COUNT(*) FROM login_attempts WHERE success = 0 AND attempt_time > DATE_SUB(NOW(), INTERVAL 24 HOUR)");
$failedLoginsLast24h = $stmt->fetchColumn();

$stmt = $db->query("SELECT COUNT(*) FROM audit_log WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)");
$auditEntriesLast24h = $stmt->fetchColumn();

$stmt = $db->query("SELECT COUNT(*) FROM audit_log");
$totalAuditEntries = $stmt->fetchColumn();

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sicherheitseinstellungen - <?= SYSTEM_NAME ?></title>
    <link rel="stylesheet" href="style.css">
    <style>
        .security-settings {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .settings-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .settings-card {
            background: white;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .settings-card h3 {
            margin-top: 0;
            margin-bottom: 15px;
            color: #333;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
            color: #555;
        }
        
        .form-group input[type="number"],
        .form-group select {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }
        
        .form-group small {
            display: block;
            margin-top: 4px;
            color: #777;
            font-size: 12px;
        }
        
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .checkbox-group input[type="checkbox"] {
            width: auto;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .stat-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 8px;
            text-align: center;
        }
        
        .stat-card.green {
            background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
        }
        
        .stat-card.orange {
            background: linear-gradient(135deg, #f2994a 0%, #f2c94c 100%);
        }
        
        .stat-card.red {
            background: linear-gradient(135deg, #eb3349 0%, #f45c43 100%);
        }
        
        .stat-value {
            font-size: 32px;
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .stat-label {
            font-size: 14px;
            opacity: 0.9;
        }
        
        .button-group {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.3s;
        }
        
        .btn-primary {
            background: #667eea;
            color: white;
        }
        
        .btn-primary:hover {
            background: #5568d3;
        }
        
        .btn-danger {
            background: #e74c3c;
            color: white;
        }
        
        .btn-danger:hover {
            background: #c0392b;
        }
        
        .btn-secondary {
            background: #95a5a6;
            color: white;
        }
        
        .btn-secondary:hover {
            background: #7f8c8d;
        }
        
        .alert {
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        
        .alert-success {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
        }
        
        .alert-error {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
        }
        
        .maintenance-actions {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        
        .maintenance-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px;
            background: #f8f9fa;
            border-radius: 4px;
        }
        
        .time-format {
            display: inline-block;
            margin-left: 10px;
            padding: 4px 8px;
            background: #e9ecef;
            border-radius: 4px;
            font-size: 12px;
            color: #666;
        }
    </style>
</head>
<body>
    <?php include 'nav.php'; ?>
    
    <div class="security-settings">
        <h1>🔒 Sicherheitseinstellungen</h1>
        
        <?php if ($message): ?>
            <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        
        <!-- Statistiken -->
        <h2>📊 Sicherheitsstatistiken (24h)</h2>
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?= $loginAttemptsLast24h ?></div>
                <div class="stat-label">Login-Versuche</div>
            </div>
            <div class="stat-card red">
                <div class="stat-value"><?= $failedLoginsLast24h ?></div>
                <div class="stat-label">Fehlgeschlagen</div>
            </div>
            <div class="stat-card green">
                <div class="stat-value"><?= $auditEntriesLast24h ?></div>
                <div class="stat-label">Audit-Log Einträge</div>
            </div>
            <div class="stat-card orange">
                <div class="stat-value"><?= number_format($totalAuditEntries) ?></div>
                <div class="stat-label">Gesamt Audit-Logs</div>
            </div>
        </div>
        
        <!-- Einstellungen -->
        <form method="POST">
            <input type="hidden" name="action" value="update_security_settings">
            
            <div class="settings-grid">
                <!-- Session-Einstellungen -->
                <div class="settings-card">
                    <h3>⏱️ Session-Verwaltung</h3>
                    
                    <div class="form-group">
                        <label for="session_timeout">Session-Timeout</label>
                        <input type="number" id="session_timeout" name="session_timeout" 
                               value="<?= $sessionTimeout ?>" min="300" max="86400" step="60" required>
                        <small>
                            Sekunden (aktuell: <?= formatSeconds($sessionTimeout) ?>)
                            <span class="time-format">Min: 5 Min | Max: 24 Std</span>
                        </small>
                    </div>
                    
                    <div class="form-group">
                        <label>Schnellauswahl</label>
                        <div class="button-group">
                            <button type="button" class="btn btn-secondary" onclick="setSessionTimeout(1800)">30 Min</button>
                            <button type="button" class="btn btn-secondary" onclick="setSessionTimeout(3600)">1 Std</button>
                            <button type="button" class="btn btn-secondary" onclick="setSessionTimeout(7200)">2 Std</button>
                            <button type="button" class="btn btn-secondary" onclick="setSessionTimeout(14400)">4 Std</button>
                        </div>
                    </div>
                </div>
                
                <!-- Login-Sicherheit -->
                <div class="settings-card">
                    <h3>🔐 Login-Sicherheit</h3>
                    
                    <div class="form-group">
                        <label for="max_login_attempts">Max. Login-Versuche</label>
                        <input type="number" id="max_login_attempts" name="max_login_attempts" 
                               value="<?= $maxLoginAttempts ?>" min="3" max="20" required>
                        <small>Anzahl fehlgeschlagener Versuche vor Sperrung (3-20)</small>
                    </div>
                    
                    <div class="form-group">
                        <label for="login_lockout_time">Sperrzeit</label>
                        <input type="number" id="login_lockout_time" name="login_lockout_time" 
                               value="<?= $loginLockoutTime ?>" min="300" max="3600" step="60" required>
                        <small>
                            Sekunden (aktuell: <?= formatSeconds($loginLockoutTime) ?>)
                            <span class="time-format">Min: 5 Min | Max: 60 Min</span>
                        </small>
                    </div>
                </div>
                
                <!-- Audit-Log -->
                <div class="settings-card">
                    <h3>📝 Audit-Log</h3>
                    
                    <div class="form-group">
                        <div class="checkbox-group">
                            <input type="checkbox" id="enable_audit_log" name="enable_audit_log" 
                                   <?= $enableAuditLog == '1' ? 'checked' : '' ?>>
                            <label for="enable_audit_log">Audit-Log aktivieren</label>
                        </div>
                        <small>Protokolliert alle Änderungen im System</small>
                    </div>
                    
                    <div class="form-group">
                        <label>Aktuelle Einträge</label>
                        <div style="font-size: 18px; font-weight: bold; color: #667eea;">
                            <?= number_format($totalAuditEntries) ?> Einträge
                        </div>
                    </div>
                    
                    <div class="button-group">
                        <a href="audit_log.php" class="btn btn-secondary">📋 Audit-Log anzeigen</a>
                    </div>
                </div>
            </div>
            
            <div class="button-group">
                <button type="submit" class="btn btn-primary">💾 Einstellungen speichern</button>
                <a href="settings.php" class="btn btn-secondary">← Zurück zu Einstellungen</a>
            </div>
        </form>
        
        <!-- Wartungsaktionen -->
        <div class="settings-card" style="margin-top: 20px;">
            <h3>🧹 Wartungsaktionen</h3>
            
            <div class="maintenance-actions">
                <div class="maintenance-item">
                    <div>
                        <strong>Login-Versuche bereinigen</strong>
                        <br><small>Löscht alle Login-Versuche älter als 30 Tage</small>
                    </div>
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="action" value="cleanup_login_attempts">
                        <button type="submit" class="btn btn-danger" 
                                onclick="return confirm('Möchten Sie alte Login-Versuche wirklich löschen?')">
                            🗑️ Bereinigen
                        </button>
                    </form>
                </div>
                
                <div class="maintenance-item">
                    <div>
                        <strong>Audit-Log bereinigen</strong>
                        <br><small>Löscht Audit-Log Einträge älter als X Tage</small>
                    </div>
                    <form method="POST" style="display: inline-flex; gap: 10px; align-items: center;">
                        <input type="hidden" name="action" value="cleanup_audit_log">
                        <input type="number" name="days" value="365" min="30" max="3650" 
                               style="width: 80px; padding: 5px;" placeholder="Tage">
                        <button type="submit" class="btn btn-danger" 
                                onclick="return confirm('Möchten Sie alte Audit-Log Einträge wirklich löschen?')">
                            🗑️ Bereinigen
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        function setSessionTimeout(seconds) {
            document.getElementById('session_timeout').value = seconds;
        }
    </script>
</body>
</html>

<?php
function formatSeconds($seconds) {
    $hours = floor($seconds / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    
    $parts = [];
    if ($hours > 0) $parts[] = $hours . 'h';
    if ($minutes > 0) $parts[] = $minutes . 'min';
    
    return implode(' ', $parts) ?: $seconds . 's';
}
?>