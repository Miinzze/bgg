<?php
/**
 * Cron-Job für automatische Wartungsprüfung mit E-Mail-Benachrichtigungen
 * 
 * SETUP:
 * 1. Diesen Cron-Job in der crontab einrichten:
 *    # Täglich um 6:00 Uhr morgens
 *    0 6 * * * php /pfad/zu/cron_maintenance.php
 * 
 *    # Stündlich
 *    0 * * * * php /pfad/zu/cron_maintenance.php
 * 
 * 2. Oder als Web-Cron mit einem geheimen Token:
 *    https://ihre-domain.de/cron_maintenance.php?token=IHR_GEHEIMER_TOKEN
 */

// Sicherheitstoken für Web-Cron (ändern Sie diesen Wert!)
define('CRON_TOKEN', 'IHR_GEHEIMER_TOKEN_HIER_EINFUEGEN');

// Prüfen ob Script über Kommandozeile oder als Web-Cron aufgerufen wird
$isCLI = (php_sapi_name() === 'cli');
$isWebCron = !$isCLI && isset($_GET['token']) && $_GET['token'] === CRON_TOKEN;

if (!$isCLI && !$isWebCron) {
    http_response_code(403);
    die('Zugriff verweigert');
}

// Config und Maintenance-Check laden
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/maintenance_check.php';

// Log-Funktion für Cron-Jobs
function cronLog($message, $type = 'INFO') {
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] [$type] $message" . PHP_EOL;
    
    $logFile = __DIR__ . '/logs/cron_' . date('Y-m-d') . '.log';
    file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
    
    if ($GLOBALS['isCLI']) {
        echo $logMessage;
    }
}

cronLog('=== Starte automatische Wartungsprüfung ===');

try {
    $db = Database::getInstance()->getConnection();
    
    // Statistiken vor der Prüfung
    $stmt = $db->query("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN next_maintenance_due <= CURDATE() AND status != 'inactive' AND status != 'maintenance' THEN 1 ELSE 0 END) as due,
            SUM(CASE WHEN status = 'maintenance' THEN 1 ELSE 0 END) as in_maintenance
        FROM map_objects
        WHERE next_maintenance_due IS NOT NULL
    ");
    $stats = $stmt->fetch();
    
    cronLog("Objekte mit Wartungsintervall: {$stats['total']}");
    cronLog("Fällige Wartungen: {$stats['due']}");
    cronLog("Bereits in Wartung: {$stats['in_maintenance']}");
    
    // Wartungsprüfung durchführen
    $result = checkMaintenanceDue();
    
    if (!empty($result['updated'])) {
        cronLog("Automatisch auf Wartung gesetzt: " . count($result['updated']) . " Objekte", 'SUCCESS');
        foreach ($result['updated'] as $objectId) {
            cronLog("  - Objekt #$objectId auf Wartung gesetzt", 'INFO');
        }
    } else {
        cronLog("Keine Objekte mussten auf Wartung gesetzt werden", 'INFO');
    }
    
    if (!empty($result['notifications'])) {
        cronLog("Benachrichtigungen generiert: " . count($result['notifications']), 'INFO');
        foreach ($result['notifications'] as $notification) {
            $type = $notification['type'] ?? 'unknown';
            $title = $notification['title'] ?? 'Unbekannt';
            cronLog("  - [$type] $title", 'INFO');
        }
    }
    
    // E-Mail-Benachrichtigungen versenden
    if (!empty($result['notifications']) && SEND_EMAIL_NOTIFICATIONS) {
        cronLog("Sende E-Mail-Benachrichtigungen...", 'INFO');
        
        $recipients = EmailManager::getMaintenanceNotificationRecipients();
        cronLog("E-Mail-Empfänger gefunden: " . count($recipients), 'INFO');
        
        if (!empty($recipients)) {
            foreach ($recipients as $recipient) {
                cronLog("  - {$recipient['username']} ({$recipient['email']})", 'INFO');
            }
            
            $sentCount = EmailManager::sendMaintenanceNotifications($result['notifications']);
            cronLog("E-Mails erfolgreich versendet: $sentCount von " . count($recipients), 'SUCCESS');
        } else {
            cronLog("Keine E-Mail-Empfänger konfiguriert", 'WARNING');
        }
    } elseif (!SEND_EMAIL_NOTIFICATIONS) {
        cronLog("E-Mail-Benachrichtigungen sind deaktiviert", 'INFO');
    }
    
    // Aufräumen: Alte Wartungshistorie-Einträge (älter als 2 Jahre)
    $stmt = $db->prepare("
        DELETE FROM maintenance_history 
        WHERE maintenance_date < DATE_SUB(CURDATE(), INTERVAL 2 YEAR)
    ");
    $stmt->execute();
    $deletedRows = $stmt->rowCount();
    
    if ($deletedRows > 0) {
        cronLog("Alte Wartungshistorie bereinigt: $deletedRows Einträge gelöscht", 'INFO');
    }
    
    // Aufräumen: Alte E-Mail-Logs (älter als 90 Tage)
    $stmt = $db->prepare("
        DELETE FROM email_log 
        WHERE sent_at < DATE_SUB(NOW(), INTERVAL 90 DAY)
    ");
    $stmt->execute();
    $deletedEmailLogs = $stmt->rowCount();
    
    if ($deletedEmailLogs > 0) {
        cronLog("Alte E-Mail-Logs bereinigt: $deletedEmailLogs Einträge gelöscht", 'INFO');
    }
    
    // Statistiken nach der Prüfung
    $stmt = $db->query("
        SELECT 
            SUM(CASE WHEN next_maintenance_due <= CURDATE() AND status != 'inactive' AND status != 'maintenance' THEN 1 ELSE 0 END) as still_due,
            SUM(CASE WHEN status = 'maintenance' THEN 1 ELSE 0 END) as now_in_maintenance
        FROM map_objects
        WHERE next_maintenance_due IS NOT NULL
    ");
    $statsAfter = $stmt->fetch();
    
    cronLog("Nach Prüfung - Noch fällig: {$statsAfter['still_due']}, In Wartung: {$statsAfter['now_in_maintenance']}", 'SUCCESS');
    
    cronLog('=== Wartungsprüfung erfolgreich abgeschlossen ===', 'SUCCESS');
    
    // Erfolgsrückmeldung für Web-Cron
    if ($isWebCron) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'message' => 'Wartungsprüfung erfolgreich',
            'updated' => count($result['updated'] ?? []),
            'notifications' => count($result['notifications'] ?? []),
            'emails_sent' => $sentCount ?? 0,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    }
    
    exit(0);
    
} catch (Exception $e) {
    cronLog('FEHLER: ' . $e->getMessage(), 'ERROR');
    cronLog('Stack Trace: ' . $e->getTraceAsString(), 'ERROR');
    cronLog('=== Wartungsprüfung mit Fehler beendet ===', 'ERROR');
    
    // Fehlerrückmeldung für Web-Cron
    if ($isWebCron) {
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Fehler bei Wartungsprüfung: ' . $e->getMessage(),
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    }
    
    exit(1);
}