<?php
/**
 * Automatische Wartungsprüfung mit E-Mail-Benachrichtigungen
 * Prüft alle Objekte auf fällige Wartungen und setzt den Status automatisch
 */

require_once 'config.php';

// Nur für angemeldete Benutzer oder AJAX-Aufrufe
// if (!Auth::isLoggedIn() && !isset($_POST['action'])) {
//     exit;
// }

/**
 * Prüft alle Objekte auf fällige Wartungen
 */
function checkMaintenanceDue() {
    $db = Database::getInstance()->getConnection();
    
    // Finde alle Objekte mit fälliger Wartung die nicht "inactive" sind
    $stmt = $db->query("
        SELECT 
            id, 
            title, 
            status, 
            next_maintenance_due,
            DATEDIFF(next_maintenance_due, CURDATE()) as days_until,
            maintenance_notification_sent
        FROM map_objects
        WHERE next_maintenance_due IS NOT NULL
          AND next_maintenance_due <= CURDATE()
          AND status != 'inactive'
          AND status != 'maintenance'
        ORDER BY next_maintenance_due ASC
    ");
    
    $objectsNeedingMaintenance = $stmt->fetchAll();
    $updated = [];
    $notifications = [];
    
    foreach ($objectsNeedingMaintenance as $object) {
        // Wenn vermietet: Benachrichtigung senden aber nicht automatisch umstellen
        if ($object['status'] === 'rented') {
            if (!$object['maintenance_notification_sent']) {
                $notifications[] = [
                    'id' => $object['id'],
                    'title' => $object['title'],
                    'type' => 'waiting',
                    'message' => "Wartung überfällig, wartet auf Verfügbarkeit",
                    'days_overdue' => abs($object['days_until'])
                ];
                
                // Markiere Benachrichtigung als gesendet
                $updateStmt = $db->prepare("
                    UPDATE map_objects 
                    SET maintenance_notification_sent = TRUE 
                    WHERE id = ?
                ");
                $updateStmt->execute([$object['id']]);
            }
        }
        // Wenn verfügbar: Automatisch auf Wartung setzen
        elseif ($object['status'] === 'available') {
            $updateStmt = $db->prepare("
                UPDATE map_objects 
                SET status = 'maintenance',
                    maintenance_notification_sent = FALSE
                WHERE id = ?
            ");
            $updateStmt->execute([$object['id']]);
            
            // Eintrag in Wartungshistorie
            $historyStmt = $db->prepare("
                INSERT INTO maintenance_history 
                (object_id, maintenance_date, was_automatic, notes)
                VALUES (?, CURDATE(), TRUE, ?)
            ");
            $historyStmt->execute([
                $object['id'],
                "Automatisch auf Wartung gesetzt - " . abs($object['days_until']) . " Tage überfällig"
            ]);
            
            $updated[] = $object['id'];
            $notifications[] = [
                'id' => $object['id'],
                'title' => $object['title'],
                'type' => 'maintenance_set',
                'message' => "Automatisch auf Wartung gesetzt",
                'days_overdue' => abs($object['days_until'])
            ];
            
            logActivity('auto_maintenance', "Object #{$object['id']} '{$object['title']}' automatically set to maintenance");
        }
    }
    
    // Prüfe auch Objekte die von "rented" zu "available" wechseln und Wartung fällig ist
    $stmt = $db->query("
        SELECT 
            id, 
            title, 
            status, 
            next_maintenance_due,
            DATEDIFF(next_maintenance_due, CURDATE()) as days_until
        FROM map_objects
        WHERE next_maintenance_due IS NOT NULL
          AND next_maintenance_due <= CURDATE()
          AND status = 'available'
          AND maintenance_notification_sent = TRUE
    ");
    
    $nowAvailableObjects = $stmt->fetchAll();
    
    foreach ($nowAvailableObjects as $object) {
        $updateStmt = $db->prepare("
            UPDATE map_objects 
            SET status = 'maintenance',
                maintenance_notification_sent = FALSE
            WHERE id = ?
        ");
        $updateStmt->execute([$object['id']]);
        
        // Eintrag in Wartungshistorie
        $historyStmt = $db->prepare("
            INSERT INTO maintenance_history 
            (object_id, maintenance_date, was_automatic, notes)
            VALUES (?, CURDATE(), TRUE, ?)
        ");
        $historyStmt->execute([
            $object['id'],
            "Nach Vermietung auf Wartung gesetzt - " . abs($object['days_until']) . " Tage überfällig"
        ]);
        
        $updated[] = $object['id'];
        $notifications[] = [
            'id' => $object['id'],
            'title' => $object['title'],
            'type' => 'maintenance_after_rental',
            'message' => "Nach Rückgabe auf Wartung gesetzt",
            'days_overdue' => abs($object['days_until'])
        ];
        
        logActivity('auto_maintenance_after_rental', "Object #{$object['id']} '{$object['title']}' set to maintenance after rental");
    }
    
    return [
        'updated' => $updated,
        'notifications' => $notifications
    ];
}

/**
 * Gibt kommende Wartungen zurück (innerhalb der nächsten X Tage)
 */
function getUpcomingMaintenances($days = 7) {
    $db = Database::getInstance()->getConnection();
    
    $stmt = $db->prepare("
        SELECT 
            id, 
            title, 
            status,
            next_maintenance_due,
            DATEDIFF(next_maintenance_due, CURDATE()) as days_until
        FROM map_objects
        WHERE next_maintenance_due IS NOT NULL
          AND next_maintenance_due > CURDATE()
          AND next_maintenance_due <= DATE_ADD(CURDATE(), INTERVAL ? DAY)
          AND status != 'inactive'
          AND status != 'maintenance'
        ORDER BY next_maintenance_due ASC
    ");
    
    $stmt->execute([$days]);
    return $stmt->fetchAll();
}

/**
 * Wartung abschließen und neues Intervall setzen
 */
function completeMaintenance($objectId, $userId, $notes = '') {
    $db = Database::getInstance()->getConnection();
    
    try {
        $db->beginTransaction();
        
        // Hole aktuelles Objekt
        $stmt = $db->prepare("SELECT * FROM map_objects WHERE id = ?");
        $stmt->execute([$objectId]);
        $object = $stmt->fetch();
        
        if (!$object) {
            throw new Exception('Objekt nicht gefunden');
        }
        
        // Setze letztes Wartungsdatum auf heute
        $updateStmt = $db->prepare("
            UPDATE map_objects 
            SET last_maintenance = CURDATE(),
                status = 'available',
                maintenance_notification_sent = FALSE
            WHERE id = ?
        ");
        $updateStmt->execute([$objectId]);
        
        // Eintrag in Historie (nicht automatisch)
        $historyStmt = $db->prepare("
            INSERT INTO maintenance_history 
            (object_id, maintenance_date, performed_by, was_automatic, notes)
            VALUES (?, CURDATE(), ?, FALSE, ?)
        ");
        $historyStmt->execute([$objectId, $userId, $notes]);
        
        $db->commit();
        
        logActivity('maintenance_completed', "Maintenance completed for object #$objectId by user #$userId");
        
        return true;
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
}

// AJAX Handler - NUR für Wartungsfunktionen
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // Prüfe ob es eine Wartungs-Action ist
    $maintenanceActions = ['check_maintenance', 'get_upcoming_maintenance', 'complete_maintenance', 'get_maintenance_history'];
    
    if (in_array($_POST['action'], $maintenanceActions)) {
        header('Content-Type: application/json');
        Auth::requireLogin();
        
        try {
            switch ($_POST['action']) {
                case 'check_maintenance':
                    $result = checkMaintenanceDue();
                    echo json_encode([
                        'success' => true,
                        'updated' => $result['updated'],
                        'notifications' => $result['notifications']
                    ]);
                    break;
                    
                case 'get_upcoming_maintenance':
                    $days = intval($_POST['days'] ?? 7);
                    $upcoming = getUpcomingMaintenances($days);
                    echo json_encode([
                        'success' => true,
                        'upcoming' => $upcoming
                    ]);
                    break;
                    
                case 'complete_maintenance':
                    Auth::requirePermission('marker.change_status');
                    
                    $objectId = intval($_POST['object_id'] ?? 0);
                    $notes = sanitizeInput($_POST['notes'] ?? '');
                    
                    if ($objectId <= 0) {
                        throw new Exception('Ungültige Objekt-ID');
                    }
                    
                    completeMaintenance($objectId, Auth::getUserId(), $notes);
                    
                    echo json_encode([
                        'success' => true,
                        'message' => 'Wartung erfolgreich abgeschlossen'
                    ]);
                    break;
                    
                case 'get_maintenance_history':
                    $objectId = intval($_POST['object_id'] ?? 0);
                    
                    if ($objectId <= 0) {
                        throw new Exception('Ungültige Objekt-ID');
                    }
                    
                    $db = Database::getInstance()->getConnection();
                    $stmt = $db->prepare("
                        SELECT 
                            mh.*,
                            u.username as performed_by_name
                        FROM maintenance_history mh
                        LEFT JOIN users u ON mh.performed_by = u.id
                        WHERE mh.object_id = ?
                        ORDER BY mh.maintenance_date DESC, mh.created_at DESC
                        LIMIT 20
                    ");
                    $stmt->execute([$objectId]);
                    $history = $stmt->fetchAll();
                    
                    echo json_encode([
                        'success' => true,
                        'history' => $history
                    ]);
                    break;
            }
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
        
        exit;
    }
}