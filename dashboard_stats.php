<?php
/**
 * Dashboard Statistiken API
 * Liefert KPIs und Statistiken für das Dashboard
 */

require_once 'config.php';

// Berechtigung prüfen
Auth::requireLogin();
Auth::requirePermission('marker.view');

header('Content-Type: application/json');

try {
    $db = Database::getInstance()->getConnection();
    
    // Basis-Statistiken
    $stats = [
        'total_objects' => 0,
        'available' => 0,
        'rented' => 0,
        'maintenance' => 0,
        'utilization_rate' => 0,
        'maintenance_this_week' => 0,
        'maintenance_overdue' => 0,
        'categories' => [],
        'rental_trend' => [],
        'maintenance_upcoming' => []
    ];
    
    // 1. Gesamtanzahl und Status-Verteilung
    $stmt = $db->query("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'available' THEN 1 ELSE 0 END) as available,
            SUM(CASE WHEN status = 'rented' THEN 1 ELSE 0 END) as rented,
            SUM(CASE WHEN status = 'maintenance' THEN 1 ELSE 0 END) as maintenance
        FROM map_objects 
        WHERE status != 'inactive'
    ");
    $statusStats = $stmt->fetch();
    
    $stats['total_objects'] = intval($statusStats['total']);
    $stats['available'] = intval($statusStats['available']);
    $stats['rented'] = intval($statusStats['rented']);
    $stats['maintenance'] = intval($statusStats['maintenance']);
    
    // Auslastungsrate berechnen (Vermietet / (Verfügbar + Vermietet))
    $usableObjects = $stats['available'] + $stats['rented'];
    if ($usableObjects > 0) {
        $stats['utilization_rate'] = round(($stats['rented'] / $usableObjects) * 100, 1);
    }
    
    // 2. Wartungen diese Woche fällig
    $stmt = $db->query("
        SELECT COUNT(*) as count
        FROM map_objects
        WHERE next_maintenance_due IS NOT NULL
        AND next_maintenance_due BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
        AND status != 'inactive'
        AND status != 'maintenance'
    ");
    $maintenanceWeek = $stmt->fetch();
    $stats['maintenance_this_week'] = intval($maintenanceWeek['count']);
    
    // 3. Überfällige Wartungen
    $stmt = $db->query("
        SELECT COUNT(*) as count
        FROM map_objects
        WHERE next_maintenance_due IS NOT NULL
        AND next_maintenance_due < CURDATE()
        AND status != 'inactive'
        AND status != 'maintenance'
    ");
    $maintenanceOverdue = $stmt->fetch();
    $stats['maintenance_overdue'] = intval($maintenanceOverdue['count']);
    
    // 4. Kategorien-Verteilung
    $stmt = $db->query("
        SELECT 
            c.name,
            c.display_name,
            c.icon,
            c.color,
            COUNT(m.id) as count,
            SUM(CASE WHEN m.status = 'rented' THEN 1 ELSE 0 END) as rented_count
        FROM categories c
        LEFT JOIN map_objects m ON c.name = m.category AND m.status != 'inactive'
        WHERE c.is_active = 1
        GROUP BY c.id, c.name, c.display_name, c.icon, c.color
        ORDER BY count DESC
        LIMIT 10
    ");
    $stats['categories'] = $stmt->fetchAll();
    
    // 5. Vermietungs-Trend (letzte 30 Tage)
    // Hinweis: Dies ist eine vereinfachte Version. Für echte Trends bräuchte man eine History-Tabelle
    $stmt = $db->query("
        SELECT 
            DATE(updated_at) as date,
            COUNT(*) as changes
        FROM map_objects
        WHERE updated_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        AND status = 'rented'
        GROUP BY DATE(updated_at)
        ORDER BY date ASC
    ");
    $trendData = $stmt->fetchAll();
    
    // Trend-Daten für Chart aufbereiten
    $trend = [];
    for ($i = 29; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $found = false;
        foreach ($trendData as $item) {
            if ($item['date'] === $date) {
                $trend[] = [
                    'date' => date('d.m', strtotime($date)),
                    'count' => intval($item['changes'])
                ];
                $found = true;
                break;
            }
        }
        if (!$found) {
            $trend[] = [
                'date' => date('d.m', strtotime($date)),
                'count' => 0
            ];
        }
    }
    $stats['rental_trend'] = $trend;
    
    // 6. Kommende Wartungen (nächste 14 Tage)
    $stmt = $db->query("
        SELECT 
            id,
            title,
            next_maintenance_due,
            DATEDIFF(next_maintenance_due, CURDATE()) as days_until,
            category
        FROM map_objects
        WHERE next_maintenance_due IS NOT NULL
        AND next_maintenance_due >= CURDATE()
        AND next_maintenance_due <= DATE_ADD(CURDATE(), INTERVAL 14 DAY)
        AND status != 'inactive'
        ORDER BY next_maintenance_due ASC
        LIMIT 10
    ");
    $stats['maintenance_upcoming'] = $stmt->fetchAll();
    
    // 7. Zusätzliche Metriken
    $stmt = $db->query("
        SELECT 
            AVG(CASE 
                WHEN next_maintenance_due IS NOT NULL 
                THEN DATEDIFF(next_maintenance_due, last_maintenance) 
                ELSE NULL 
            END) as avg_maintenance_interval
        FROM map_objects
        WHERE last_maintenance IS NOT NULL
        AND next_maintenance_due IS NOT NULL
    ");
    $avgInterval = $stmt->fetch();
    $stats['avg_maintenance_interval'] = $avgInterval['avg_maintenance_interval'] 
        ? round($avgInterval['avg_maintenance_interval']) 
        : null;
    
    echo json_encode([
        'success' => true,
        'stats' => $stats,
        'generated_at' => date('Y-m-d H:i:s')
    ]);
    
} catch (Exception $e) {
    error_log('Dashboard stats error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Fehler beim Laden der Statistiken: ' . $e->getMessage()
    ]);
}