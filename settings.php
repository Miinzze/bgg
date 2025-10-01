<?php
require_once 'config.php';

// Berechtigung prüfen
Auth::requireLogin();
Auth::requirePermission('settings.view', 'Sie haben keine Berechtigung die Einstellungen anzusehen');

// Prüfen ob Benutzer bearbeiten darf
$canEditSettings = Auth::hasPermission('settings.edit');
$storageDeviceColor = CategoryHelper::getStorageDeviceColor();

// Standard-Einstellungen
$defaultSettings = [
    'marker_size' => 24,
    'marker_border_width' => 3,
    'show_legend' => true,
    'enable_marker_pulse' => true,
    'marker_hover_scale' => 1.3,
    'tooltip_delay' => 0,
    'background_blur_admin' => false,
    'auto_save_interval' => 30,
    'enable_notifications' => true,
    'marker_shadow_intensity' => 0.3,
    'interface_theme' => 'auto'
];

// AJAX Request Handling
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    try {
        switch ($_POST['action']) {
            case 'upload_background':
                Auth::requirePermission('settings.edit', 'Sie haben keine Berechtigung zum Hochladen von Hintergrundbildern');
                
                if (!isset($_FILES['background_image']) || $_FILES['background_image']['error'] !== UPLOAD_ERR_OK) {
                    throw new Exception('Keine gültige Datei hochgeladen');
                }
                
                if (!validateImageFile($_FILES['background_image'])) {
                    throw new Exception('Ungültiges Bildformat');
                }
                
                try {
                    ImageHelper::deleteOldBackgrounds();
                    $filePath = ImageHelper::handleImageUpload($_FILES['background_image'], true);
                    
                    if (!empty($_POST['max_width']) && is_numeric($_POST['max_width'])) {
                        $maxWidth = intval($_POST['max_width']);
                        if ($maxWidth >= 800 && $maxWidth <= 5000) {
                            ImageHelper::resizeImage($filePath, $maxWidth);
                        }
                    }
                    
                    logActivity('background_uploaded', 'Background image uploaded: ' . basename($filePath));
                    echo json_encode(['success' => true, 'filename' => basename($filePath)]);
                    
                } catch (Exception $e) {
                    throw new Exception('Upload fehlgeschlagen: ' . $e->getMessage());
                }
                break;
            case 'test_email_notification':
                Auth::requirePermission('settings.edit', 'Sie haben keine Berechtigung zum Testen von E-Mails');
                
                $testEmail = sanitizeInput($_POST['test_email'] ?? '');
                
                // Validiere Test-E-Mail wenn angegeben
                if (!empty($testEmail) && !validateEmail($testEmail)) {
                    throw new Exception('Ungültige Test-E-Mail-Adresse');
                }
                
                // Test-Benachrichtigungen erstellen
                $testNotifications = [
                    [
                        'id' => 'TEST-1',
                        'title' => 'Generator A',
                        'type' => 'maintenance_set',
                        'message' => 'Automatisch auf Wartung gesetzt (TEST)',
                        'days_overdue' => 5
                    ],
                    [
                        'id' => 'TEST-2',
                        'title' => 'Beleuchtung Halle 3',
                        'type' => 'waiting',
                        'message' => 'Wartung überfällig, wartet auf Verfügbarkeit (TEST)',
                        'days_overdue' => 12
                    ],
                    [
                        'id' => 'TEST-3',
                        'title' => 'Soundanlage',
                        'type' => 'maintenance_after_rental',
                        'message' => 'Nach Rückgabe auf Wartung gesetzt (TEST)',
                        'days_overdue' => 2
                    ]
                ];
                
                if (!empty($testEmail)) {
                    // Sende an spezifische Test-Adresse
                    $subject = 'TEST - Wartungsbenachrichtigung - ' . SYSTEM_NAME;
                    
                    $message = "Dies ist eine TEST-E-Mail\n\n";
                    $message .= "Hallo,\n\n";
                    $message .= "Dies ist eine Test-Wartungsbenachrichtigung vom System.\n";
                    $message .= "Datum: " . date('d.m.Y H:i:s') . "\n\n";
                    $message .= "Folgende Test-Wartungen wurden simuliert:\n\n";
                    
                    foreach ($testNotifications as $notification) {
                        $message .= "• " . $notification['title'] . ": " . $notification['message'] . "\n";
                        if (isset($notification['days_overdue']) && $notification['days_overdue'] > 0) {
                            $message .= "  (Überfällig seit {$notification['days_overdue']} Tagen)\n";
                        }
                        $message .= "\n";
                    }
                    
                    $message .= "\n---\n";
                    $message .= "Dies war eine TEST-E-Mail.\n";
                    $message .= "Wenn Sie diese E-Mail erhalten haben, funktioniert der E-Mail-Versand korrekt.\n\n";
                    $message .= "System-URL: https://" . $_SERVER['HTTP_HOST'] . "\n";
                    
                    $success = EmailManager::sendMail($testEmail, $subject, $message, 'test');
                    
                    if ($success) {
                        echo json_encode([
                            'success' => true, 
                            'message' => "Test-E-Mail erfolgreich an $testEmail gesendet",
                            'sent_count' => 1
                        ]);
                    } else {
                        throw new Exception('Fehler beim Senden der Test-E-Mail');
                    }
                    
                } else {
                    // Sende an alle registrierten Empfänger
                    $sentCount = EmailManager::sendMaintenanceNotifications($testNotifications);
                    
                    if ($sentCount > 0) {
                        echo json_encode([
                            'success' => true, 
                            'message' => "Test-E-Mails erfolgreich versendet",
                            'sent_count' => $sentCount
                        ]);
                    } else {
                        throw new Exception('Keine E-Mails versendet. Prüfen Sie ob Empfänger konfiguriert sind.');
                    }
                }
                
                logActivity('test_email_sent', 'Test email notification sent to: ' . ($testEmail ?: 'all recipients'));
                break;
                
            case 'get_notification_recipients':
                Auth::requirePermission('settings.view');
                
                $recipients = EmailManager::getMaintenanceNotificationRecipients();
                
                echo json_encode([
                    'success' => true,
                    'count' => count($recipients),
                    'recipients' => $recipients
                ]);
                break;
            case 'save_settings':
                Auth::requirePermission('settings.edit', 'Sie haben keine Berechtigung zum Ändern der Einstellungen');
                
                $settings = [];
                
                $markerSize = intval($_POST['marker_size'] ?? 24);
                if ($markerSize < 12 || $markerSize > 48) {
                    throw new Exception('Marker-Größe muss zwischen 12 und 48 Pixel liegen');
                }
                $settings['marker_size'] = $markerSize;
                
                $borderWidth = intval($_POST['marker_border_width'] ?? 3);
                if ($borderWidth < 1 || $borderWidth > 8) {
                    throw new Exception('Rahmenbreite muss zwischen 1 und 8 Pixel liegen');
                }
                $settings['marker_border_width'] = $borderWidth;
                
                $hoverScale = floatval($_POST['marker_hover_scale'] ?? 1.3);
                if ($hoverScale < 1.0 || $hoverScale > 2.0) {
                    throw new Exception('Hover-Skalierung muss zwischen 1.0 und 2.0 liegen');
                }
                $settings['marker_hover_scale'] = $hoverScale;
                
                $tooltipDelay = intval($_POST['tooltip_delay'] ?? 0);
                if ($tooltipDelay < 0 || $tooltipDelay > 2000) {
                    throw new Exception('Tooltip-Verzögerung muss zwischen 0 und 2000ms liegen');
                }
                $settings['tooltip_delay'] = $tooltipDelay;
                
                $autoSaveInterval = intval($_POST['auto_save_interval'] ?? 30);
                if ($autoSaveInterval < 10 || $autoSaveInterval > 300) {
                    throw new Exception('Auto-Save Intervall muss zwischen 10 und 300 Sekunden liegen');
                }
                $settings['auto_save_interval'] = $autoSaveInterval;
                
                $shadowIntensity = floatval($_POST['marker_shadow_intensity'] ?? 0.3);
                if ($shadowIntensity < 0.0 || $shadowIntensity > 1.0) {
                    throw new Exception('Schatten-Intensität muss zwischen 0.0 und 1.0 liegen');
                }
                $settings['marker_shadow_intensity'] = $shadowIntensity;
                
                $interfaceTheme = sanitizeInput($_POST['interface_theme'] ?? 'auto');
                $allowedThemes = ['light', 'dark', 'auto'];
                if (!in_array($interfaceTheme, $allowedThemes)) {
                    throw new Exception('Ungültiges Interface-Theme');
                }
                $settings['interface_theme'] = $interfaceTheme;
                
                $settings['show_legend'] = isset($_POST['show_legend']);
                $settings['enable_marker_pulse'] = isset($_POST['enable_marker_pulse']);
                $settings['background_blur_admin'] = isset($_POST['background_blur_admin']);
                $settings['enable_notifications'] = isset($_POST['enable_notifications']);
                
                $_SESSION['map_settings'] = $settings;
                
                $db = Database::getInstance()->getConnection();
                foreach ($settings as $key => $value) {
                    $valueStr = is_bool($value) ? ($value ? 'true' : 'false') : strval($value);
                    $type = is_bool($value) ? 'boolean' : (is_int($value) ? 'int' : (is_float($value) ? 'float' : 'string'));
                    
                    $stmt = $db->prepare("INSERT INTO system_settings (setting_key, setting_value, setting_type, updated_by) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), setting_type = VALUES(setting_type), updated_by = VALUES(updated_by)");
                    $stmt->execute([$key, $valueStr, $type, Auth::getUserId()]);
                }
                
                logActivity('settings_updated', 'Map display settings updated');
                echo json_encode(['success' => true, 'message' => 'Einstellungen gespeichert']);
                break;
                
            case 'reset_settings':
                Auth::requirePermission('settings.edit', 'Sie haben keine Berechtigung zum Zurücksetzen der Einstellungen');
                
                $_SESSION['map_settings'] = $defaultSettings;
                
                $db = Database::getInstance()->getConnection();
                foreach ($defaultSettings as $key => $value) {
                    $valueStr = is_bool($value) ? ($value ? 'true' : 'false') : strval($value);
                    $type = is_bool($value) ? 'boolean' : (is_int($value) ? 'int' : (is_float($value) ? 'float' : 'string'));
                    
                    $stmt = $db->prepare("INSERT INTO system_settings (setting_key, setting_value, setting_type, updated_by) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), setting_type = VALUES(setting_type), updated_by = VALUES(updated_by)");
                    $stmt->execute([$key, $valueStr, $type, Auth::getUserId()]);
                }
                
                logActivity('settings_reset', 'Map display settings reset to defaults');
                echo json_encode(['success' => true, 'message' => 'Einstellungen zurückgesetzt']);
                break;
            case 'save_storage_color':
                Auth::requirePermission('settings.edit', 'Sie haben keine Berechtigung zum Ändern der Einstellungen');
                
                $color = sanitizeInput($_POST['storage_color'] ?? '#9e9e9e');
                
                if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
                    throw new Exception('Ungültiges Farbformat');
                }
                
                CategoryHelper::setStorageDeviceColor($color);
                
                logActivity('storage_color_updated', 'Storage device color updated to: ' . $color);
                echo json_encode(['success' => true, 'message' => 'Farbe gespeichert']);
                break;  
            case 'get_settings':
                $db = Database::getInstance()->getConnection();
                $stmt = $db->query("SELECT setting_key, setting_value, setting_type FROM system_settings");
                $dbSettings = $stmt->fetchAll();
                
                $settings = $defaultSettings;
                
                foreach ($dbSettings as $setting) {
                    $key = $setting['setting_key'];
                    $value = $setting['setting_value'];
                    $type = $setting['setting_type'];
                    
                    switch ($type) {
                        case 'boolean':
                            $settings[$key] = ($value === 'true');
                            break;
                        case 'int':
                            $settings[$key] = intval($value);
                            break;
                        case 'float':
                            $settings[$key] = floatval($value);
                            break;
                        default:
                            $settings[$key] = $value;
                    }
                }
                
                echo json_encode(['success' => true, 'settings' => $settings]);
                break;
                
            case 'export_settings':
                Auth::requirePermission('settings.view');
                
                $currentSettings = $_SESSION['map_settings'] ?? $defaultSettings;
                
                $exportData = [
                    'version' => '1.0',
                    'exported_at' => date('Y-m-d H:i:s'),
                    'exported_by' => Auth::getUsername(),
                    'settings' => $currentSettings
                ];
                
                $filename = 'map_settings_' . date('Y-m-d_H-i-s') . '.json';
                
                header('Content-Type: application/json');
                header('Content-Disposition: attachment; filename="' . $filename . '"');
                echo json_encode($exportData, JSON_PRETTY_PRINT);
                break;
                
            case 'import_settings':
                Auth::requirePermission('settings.edit', 'Sie haben keine Berechtigung zum Importieren von Einstellungen');
                
                if (!isset($_FILES['settings_file']) || $_FILES['settings_file']['error'] !== UPLOAD_ERR_OK) {
                    throw new Exception('Keine gültige Datei hochgeladen');
                }
                
                $fileContent = file_get_contents($_FILES['settings_file']['tmp_name']);
                $importData = json_decode($fileContent, true);
                
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new Exception('Ungültige JSON-Datei');
                }
                
                if (!isset($importData['settings']) || !is_array($importData['settings'])) {
                    throw new Exception('Ungültiges Einstellungs-Format');
                }
                
                $importedSettings = array_intersect_key($importData['settings'], $defaultSettings);
                $_SESSION['map_settings'] = array_merge($defaultSettings, $importedSettings);
                
                logActivity('settings_imported', 'Settings imported from file: ' . $_FILES['settings_file']['name']);
                echo json_encode(['success' => true, 'message' => 'Einstellungen erfolgreich importiert']);
                break;
                
            default:
                throw new Exception('Unbekannte Aktion');
        }
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    
    exit;
}

// Aktuelle Einstellungen laden
$currentSettings = $_SESSION['map_settings'] ?? $defaultSettings;

if (!isset($_SESSION['map_settings'])) {
    try {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->query("SELECT setting_key, setting_value, setting_type FROM system_settings");
        $dbSettings = $stmt->fetchAll();
        
        foreach ($dbSettings as $setting) {
            $key = $setting['setting_key'];
            $value = $setting['setting_value'];
            $type = $setting['setting_type'];
            
            switch ($type) {
                case 'boolean':
                    $currentSettings[$key] = ($value === 'true');
                    break;
                case 'int':
                    $currentSettings[$key] = intval($value);
                    break;
                case 'float':
                    $currentSettings[$key] = floatval($value);
                    break;
                default:
                    $currentSettings[$key] = $value;
            }
        }
        
        $_SESSION['map_settings'] = $currentSettings;
    } catch (Exception $e) {
        $currentSettings = $defaultSettings;
    }
}

// Aktuelles Hintergrundbild
$currentBackground = ImageHelper::getCurrentBackground();

// Statistiken laden
$db = Database::getInstance()->getConnection();
$totalObjects = $db->query("SELECT COUNT(*) FROM map_objects")->fetchColumn();
$availableObjects = $db->query("SELECT COUNT(*) FROM map_objects WHERE status = 'available'")->fetchColumn();
$rentedObjects = $db->query("SELECT COUNT(*) FROM map_objects WHERE status = 'rented'")->fetchColumn();
$totalUsers = $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Einstellungen - <?= htmlspecialchars(SYSTEM_NAME) ?></title>
    <meta name="description" content="Systemeinstellungen für das Objekt-Verwaltungssystem">
    <link rel="stylesheet" href="style.css">
    <style>
        .settings-container {
            max-width: 1000px;
            margin: calc(var(--grid-unit) * 4) auto;
            background: white;
            border-radius: var(--border-radius-large);
            box-shadow: var(--shadow-medium);
            border: 1px solid var(--border-color);
            overflow: hidden;
        }
        
        .settings-header {
            background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
            color: white;
            padding: calc(var(--grid-unit) * 4);
            text-align: center;
            border-bottom: 3px solid var(--secondary-color);
        }
        
        .settings-header h1 {
            font-size: 20px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: calc(var(--grid-unit) * 1);
        }
        
        .settings-header p {
            font-size: 14px;
            opacity: 0.9;
            margin: 0;
        }
        
        <?php if (!$canEditSettings): ?>
        .settings-read-only-notice {
            background: #fff8e1;
            border: 2px solid #ffc107;
            border-radius: var(--border-radius);
            padding: var(--spacing-md);
            margin: var(--spacing-md);
            text-align: center;
            color: #856404;
            font-weight: 600;
        }
        
        .settings-read-only-notice::before {
            content: '🔒 ';
            font-size: 1.5em;
        }
        <?php endif; ?>
        
        .settings-content {
            padding: calc(var(--grid-unit) * 4);
        }
        
        .setting-section {
            margin-bottom: calc(var(--grid-unit) * 4);
            padding: calc(var(--grid-unit) * 3);
            border: 1px solid var(--border-color);
            border-radius: var(--border-radius);
            background: var(--light-color);
        }
        
        .setting-section h3 {
            color: var(--primary-color);
            margin-bottom: calc(var(--grid-unit) * 2);
            font-size: 14px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            display: flex;
            align-items: center;
            gap: calc(var(--grid-unit) * 1);
        }
        
        .setting-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: calc(var(--grid-unit) * 2);
            padding: calc(var(--grid-unit) * 2);
            background: white;
            border-radius: var(--border-radius);
            border: 1px solid var(--border-color);
        }
        
        .setting-label {
            flex: 1;
            color: var(--dark-color);
            font-weight: 600;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .setting-description {
            color: var(--text-muted);
            font-size: 11px;
            margin-top: calc(var(--grid-unit) * 0.5);
            line-height: 1.4;
        }
        
        .setting-control {
            margin-left: calc(var(--grid-unit) * 2);
            display: flex;
            align-items: center;
            gap: calc(var(--grid-unit) * 1);
        }
        
        .range-input {
            width: 150px;
            height: 4px;
            border-radius: 2px;
            background: var(--border-color);
            outline: none;
            -webkit-appearance: none;
        }
        
        .range-input::-webkit-slider-thumb {
            appearance: none;
            width: 16px;
            height: 16px;
            border-radius: 50%;
            background: var(--secondary-color);
            cursor: pointer;
            border: 2px solid white;
            box-shadow: var(--shadow-light);
        }
        
        .range-input::-moz-range-thumb {
            width: 16px;
            height: 16px;
            border-radius: 50%;
            background: var(--secondary-color);
            cursor: pointer;
            border: 2px solid white;
            box-shadow: var(--shadow-light);
        }
        
        .range-input:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        .range-value {
            display: inline-block;
            min-width: 50px;
            text-align: center;
            font-weight: 700;
            color: var(--primary-color);
            font-size: 11px;
            background: white;
            padding: calc(var(--grid-unit) * 0.5) calc(var(--grid-unit) * 1);
            border-radius: var(--border-radius);
            border: 1px solid var(--border-color);
        }
        
        .checkbox-input {
            width: 18px;
            height: 18px;
            cursor: pointer;
            accent-color: var(--secondary-color);
        }
        
        .checkbox-input:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        .select-input {
            padding: calc(var(--grid-unit) * 1);
            border: 1px solid var(--border-color);
            border-radius: var(--border-radius);
            background: white;
            font-size: 12px;
            color: var(--dark-color);
        }
        
        .select-input:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            background: var(--light-gray);
        }
        
        .preview-area {
            background: white;
            border: 2px dashed var(--border-color);
            border-radius: var(--border-radius);
            padding: calc(var(--grid-unit) * 4);
            text-align: center;
            margin: calc(var(--grid-unit) * 2) 0;
            position: relative;
            min-height: 200px;
            background-image: 
                radial-gradient(circle at 20% 80%, var(--border-color) 1px, transparent 1px),
                radial-gradient(circle at 80% 20%, var(--border-color) 1px, transparent 1px);
            background-size: 20px 20px;
        }
        
        .preview-markers {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: calc(var(--grid-unit) * 4);
            height: 150px;
        }
        
        .preview-marker {
            border-radius: 50%;
            border: 2px solid white;
            box-shadow: 0 2px 4px rgba(0,0,0,0.3), 0 0 0 1px rgba(0,0,0,0.1);
            transition: all var(--transition-fast);
            cursor: pointer;
            position: relative;
        }
        
        .preview-marker.available {
            background: linear-gradient(135deg, var(--success-color), #48bb78);
        }
        
        .preview-marker.rented {
            background: linear-gradient(135deg, var(--danger-color), #f56565);
        }
        
        .preview-marker.maintenance {
            background: linear-gradient(135deg, var(--warning-color), #ecc94b);
        }
        
        .preview-marker::before {
            content: '';
            position: absolute;
            top: -2px;
            left: -2px;
            right: -2px;
            bottom: -2px;
            border-radius: 50%;
            background: inherit;
            opacity: 0.4;
            animation: industrial-pulse 2s infinite;
        }
        
        .preview-marker.no-pulse::before {
            display: none;
        }
        
        .action-buttons {
            display: flex;
            gap: calc(var(--grid-unit) * 2);
            justify-content: center;
            margin-top: calc(var(--grid-unit) * 4);
            padding-top: calc(var(--grid-unit) * 3);
            border-top: 1px solid var(--border-color);
            flex-wrap: wrap;
        }
        
        .back-button {
            position: fixed;
            top: 100px;
            left: calc(var(--grid-unit) * 3);
            background: var(--secondary-color);
            color: white;
            padding: calc(var(--grid-unit) * 2) calc(var(--grid-unit) * 3);
            border-radius: var(--border-radius);
            text-decoration: none;
            box-shadow: var(--shadow-medium);
            transition: all var(--transition-fast);
            z-index: 1000;
            display: flex;
            align-items: center;
            gap: calc(var(--grid-unit) * 1);
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border: 1px solid var(--primary-light);
        }
        
        .back-button:hover {
            background: var(--primary-light);
            transform: translateY(-2px);
            box-shadow: var(--shadow-heavy);
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: calc(var(--grid-unit) * 2);
            margin-bottom: calc(var(--grid-unit) * 2);
        }
        
        .stat-card {
            background: white;
            padding: calc(var(--grid-unit) * 2);
            border-radius: var(--border-radius);
            border: 1px solid var(--border-color);
            text-align: center;
            box-shadow: var(--shadow-light);
        }
        
        .stat-value {
            font-size: 24px;
            font-weight: 700;
            color: var(--primary-color);
            margin-bottom: calc(var(--grid-unit) * 0.5);
        }
        
        .stat-label {
            font-size: 11px;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
        }
        
        .background-preview-section {
            margin-top: calc(var(--grid-unit) * 3);
            padding: calc(var(--grid-unit) * 3);
            background: var(--light-gray);
            border-radius: var(--border-radius);
            border: 1px solid var(--border-color);
        }
        
        .current-background-display {
            text-align: center;
            margin-bottom: calc(var(--grid-unit) * 2);
        }
        
        .current-background-display img {
            max-width: 100%;
            max-height: 300px;
            border-radius: var(--border-radius);
            border: 2px solid var(--border-color);
            box-shadow: var(--shadow-medium);
        }
        
        @keyframes industrial-pulse {
            0% { transform: scale(1); opacity: 0.4; }
            50% { transform: scale(1.3); opacity: 0.1; }
            100% { transform: scale(1); opacity: 0.4; }
        }
        
        @media (max-width: 768px) {
            .settings-container {
                margin: calc(var(--grid-unit) * 2);
            }
            
            .setting-row {
                flex-direction: column;
                align-items: flex-start;
                gap: calc(var(--grid-unit) * 1);
            }
            
            .setting-control {
                margin-left: 0;
                width: 100%;
                justify-content: space-between;
            }
            
            .back-button {
                position: static;
                margin-bottom: calc(var(--grid-unit) * 2);
                width: 100%;
                justify-content: center;
            }
            
            .action-buttons {
                flex-direction: column;
                align-items: stretch;
            }
            
            .preview-markers {
                flex-wrap: wrap;
                gap: calc(var(--grid-unit) * 2);
            }
        }
    </style>
</head>
<body>
    <a href="index.php" class="btn btn-secondary back-button">
        ← Zurück zur Karte
    </a>
    
    <div class="settings-container">
        <div class="settings-header">
            <h1>⚙️ System-Einstellungen</h1>
            <p>Passen Sie die Darstellung und das Verhalten der Karte nach Ihren Wünschen an</p>
        </div>
        
        <?php if (!$canEditSettings): ?>
        <div class="settings-read-only-notice">
            Nur-Lese-Modus: Sie können die Einstellungen ansehen, aber nicht ändern
        </div>
        <?php endif; ?>
        
        <div class="settings-content">
            
            <!-- Hintergrundbild-Verwaltung -->
            <div class="setting-section">
                <h3>🖼️ Hintergrundbild-Verwaltung</h3>
                
                <?php if ($currentBackground): ?>
                    <div class="current-background-display">
                        <h4 style="color: var(--primary-color); margin-bottom: 1rem;">Aktuelles Hintergrundbild:</h4>
                        <img src="<?= htmlspecialchars($currentBackground) ?>" alt="Aktuelles Hintergrundbild">
                        <p style="margin-top: 1rem; color: var(--text-muted); font-size: 0.9rem;">
                            Datei: <?= basename($currentBackground) ?>
                        </p>
                    </div>
                <?php else: ?>
                    <div class="current-background-display">
                        <p style="color: var(--text-muted); font-style: italic;">Kein Hintergrundbild vorhanden</p>
                    </div>
                <?php endif; ?>
                
                <?php if ($canEditSettings): ?>
                <div class="background-preview-section">
                    <h4 style="margin-bottom: 1rem; color: var(--primary-color);">Neues Hintergrundbild hochladen:</h4>
                    
                    <form id="upload-background-form" enctype="multipart/form-data">
                        <div class="form-group">
                            <label for="background-image">Bild auswählen:</label>
                            <input type="file" id="background-image" name="background_image" 
                                   accept="image/jpeg,image/png,image/jpg" required>
                            <small>Unterstützte Formate: JPG, PNG (max. 10MB)</small>
                        </div>
                        
                        <div class="form-group">
                            <label for="max-width">Maximale Breite (optional):</label>
                            <input type="number" id="max-width" name="max_width" 
                                   placeholder="z.B. 1200" min="800" max="5000" step="100">
                            <small>Empfohlen: 1200-2000px für optimale Performance. Leer lassen für Original-Größe.</small>
                        </div>
                        
                        <div class="background-preview" id="background-preview" style="display: none;">
                            <h4>Vorschau:</h4>
                            <img id="preview-image" alt="Bildvorschau" style="max-width: 100%; max-height: 300px; border-radius: 8px; border: 2px solid #ddd;">
                        </div>
                        
                        <div class="form-actions" style="margin-top: 1rem;">
                            <button type="submit" class="btn btn-primary">🖼️ Hintergrundbild hochladen</button>
                        </div>
                    </form>
                </div>
                <?php endif; ?>
            </div>
            
<!-- E-Mail Test-Sektion -->
                <?php if ($canEditSettings): ?>
                <div class="setting-section">
                    <h3>📧 E-Mail Benachrichtigungen testen</h3>
                    
                    <div style="background: #e3f2fd; border: 2px solid #2196f3; border-radius: 8px; padding: 1rem; margin-bottom: 1rem;">
                        <p style="margin: 0; color: #0d47a1; font-weight: 500;">
                            Testen Sie den E-Mail-Versand für Wartungsbenachrichtigungen. Es wird eine Test-E-Mail an alle Benutzer gesendet, die Wartungsbenachrichtigungen aktiviert haben.
                        </p>
                    </div>
                    
                    <form id="test-email-form">
                        <div class="form-group">
                            <label for="test-email-address">Test-E-Mail Adresse (optional):</label>
                            <input type="email" id="test-email-address" name="test_email" 
                                   placeholder="Leer lassen um an alle registrierten Empfänger zu senden">
                            <small>Wenn Sie eine E-Mail-Adresse eingeben, wird die Test-Mail nur an diese Adresse gesendet.</small>
                        </div>
                        
                        <div class="stats-grid" style="margin-bottom: 1rem;">
                            <div class="stat-card">
                                <div class="stat-value" id="notification-recipients-count">-</div>
                                <div class="stat-label">Registrierte Empfänger</div>
                            </div>
                            <div class="stat-card">
                                <div class="stat-value" id="email-status">
                                    <?= SEND_EMAIL_NOTIFICATIONS ? '✅ Aktiv' : '❌ Deaktiviert' ?>
                                </div>
                                <div class="stat-label">E-Mail System</div>
                            </div>
                        </div>
                        
                        <?php if (!SEND_EMAIL_NOTIFICATIONS): ?>
                        <div style="background: #fff3cd; border: 2px solid #ffc107; border-radius: 8px; padding: 1rem; margin-bottom: 1rem;">
                            <p style="margin: 0; color: #856404; font-weight: 500;">
                                ⚠️ E-Mail-Benachrichtigungen sind in der config.php deaktiviert (SEND_EMAIL_NOTIFICATIONS = false)
                            </p>
                        </div>
                        <?php endif; ?>
                        
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary">📧 Test-E-Mail senden</button>
                            <button type="button" class="btn btn-secondary" onclick="loadNotificationRecipients()">🔄 Empfänger aktualisieren</button>
                        </div>
                    </form>
                    
                    <div id="test-email-result" style="display: none; margin-top: 1rem;"></div>
                </div>
                <?php endif; ?>

            <form id="settings-form">
                
                <!-- Marker-Einstellungen -->
                <div class="setting-section">
                    <h3>📍 Marker-Einstellungen</h3>
                    
                    <div class="setting-row">
                        <div>
                            <div class="setting-label">Marker-Größe</div>
                            <div class="setting-description">Durchmesser der Marker in Pixeln (12-48px)</div>
                        </div>
                        <div class="setting-control">
                            <input type="range" id="marker_size" name="marker_size" 
                                   min="12" max="48" value="<?= $currentSettings['marker_size'] ?>" 
                                   class="range-input" oninput="updatePreview()" 
                                   <?= !$canEditSettings ? 'disabled' : '' ?>>
                            <span class="range-value" id="marker_size_value"><?= $currentSettings['marker_size'] ?>px</span>
                        </div>
                    </div>
                    
                    <div class="setting-row">
                        <div>
                            <div class="setting-label">Rahmenbreite</div>
                            <div class="setting-description">Breite des weißen Rahmens um die Marker (1-8px)</div>
                        </div>
                        <div class="setting-control">
                            <input type="range" id="marker_border_width" name="marker_border_width" 
                                   min="1" max="8" value="<?= $currentSettings['marker_border_width'] ?>" 
                                   class="range-input" oninput="updatePreview()"
                                   <?= !$canEditSettings ? 'disabled' : '' ?>>
                            <span class="range-value" id="marker_border_width_value"><?= $currentSettings['marker_border_width'] ?>px</span>
                        </div>
                    </div>
                    
                    <div class="setting-row">
                        <div>
                            <div class="setting-label">Hover-Skalierung</div>
                            <div class="setting-description">Vergrößerung bei Maus-Hover (1.0 = keine Vergrößerung)</div>
                        </div>
                        <div class="setting-control">
                            <input type="range" id="marker_hover_scale" name="marker_hover_scale" 
                                   min="1.0" max="2.0" step="0.1" value="<?= $currentSettings['marker_hover_scale'] ?>" 
                                   class="range-input" oninput="updatePreview()"
                                   <?= !$canEditSettings ? 'disabled' : '' ?>>
                            <span class="range-value" id="marker_hover_scale_value"><?= $currentSettings['marker_hover_scale'] ?>x</span>
                        </div>
                    </div>
                    
                    <div class="setting-row">
                        <div>
                            <div class="setting-label">Schatten-Intensität</div>
                            <div class="setting-description">Stärke des Schattens um die Marker (0.0-1.0)</div>
                        </div>
                        <div class="setting-control">
                            <input type="range" id="marker_shadow_intensity" name="marker_shadow_intensity" 
                                   min="0.0" max="1.0" step="0.1" value="<?= $currentSettings['marker_shadow_intensity'] ?>" 
                                   class="range-input" oninput="updatePreview()"
                                   <?= !$canEditSettings ? 'disabled' : '' ?>>
                            <span class="range-value" id="marker_shadow_intensity_value"><?= $currentSettings['marker_shadow_intensity'] ?></span>
                        </div>
                    </div>
                    
                    <div class="setting-row">
                        <div>
                            <div class="setting-label">Pulseffekt aktivieren</div>
                            <div class="setting-description">Marker pulsieren leicht für bessere Sichtbarkeit</div>
                        </div>
                        <div class="setting-control">
                            <input type="checkbox" id="enable_marker_pulse" name="enable_marker_pulse" 
                                   class="checkbox-input" <?= $currentSettings['enable_marker_pulse'] ? 'checked' : '' ?> 
                                   onchange="updatePreview()" <?= !$canEditSettings ? 'disabled' : '' ?>>
                        </div>
                    </div>
                    
                    <!-- Vorschau -->
                    <div class="preview-area">
                        <h4 style="margin-bottom: 1rem; color: #666;">Live-Vorschau der Marker-Einstellungen:</h4>
                        <div class="preview-markers">
                            <div class="preview-marker available" title="Verfügbar"></div>
                            <div class="preview-marker rented" title="Vermietet"></div>
                            <div class="preview-marker maintenance" title="Wartung"></div>
                        </div>
                        <p style="margin-top: 1rem; color: #666; font-size: 0.9rem;">Fahren Sie mit der Maus über die Marker</p>
                    </div>
                </div>
                
                <!-- Interface-Einstellungen -->
                <div class="setting-section">
                    <h3>🎨 Interface-Einstellungen</h3>
                    
                    <div class="setting-row">
                        <div>
                            <div class="setting-label">Interface-Theme</div>
                            <div class="setting-description">Farbschema der Benutzeroberfläche</div>
                        </div>
                        <div class="setting-control">
                            <select id="interface_theme" name="interface_theme" class="select-input" 
                                    <?= !$canEditSettings ? 'disabled' : '' ?>>
                                <option value="auto" <?= $currentSettings['interface_theme'] === 'auto' ? 'selected' : '' ?>>Automatisch</option>
                                <option value="light" <?= $currentSettings['interface_theme'] === 'light' ? 'selected' : '' ?>>Hell</option>
                                <option value="dark" <?= $currentSettings['interface_theme'] === 'dark' ? 'selected' : '' ?>>Dunkel</option>
                            </select>
                        </div>
                    </div>
                    
                <!-- Lagergeräte-Einstellungen -->
                <div class="setting-section">
                    <h3>📦 Lagergeräte-Einstellungen</h3>
                    
                    <div class="setting-row">
                        <div>
                            <div class="setting-label">Lagergeräte-Farbe</div>
                            <div class="setting-description">Farbe für Marker die als Lagergerät markiert sind</div>
                        </div>
                        <div class="setting-control">
                            <div class="color-input-wrapper">
                                <input type="color" id="storage-device-color" 
                                    value="<?= htmlspecialchars($storageDeviceColor) ?>"
                                    <?= !$canEditSettings ? 'disabled' : '' ?>>
                                <div class="color-preview" id="storage-color-preview" 
                                    style="background: <?= htmlspecialchars($storageDeviceColor) ?>;"></div>
                                <input type="text" id="storage-color-text" 
                                    value="<?= htmlspecialchars($storageDeviceColor) ?>" 
                                    pattern="^#[0-9A-Fa-f]{6}$" maxlength="7"
                                    style="width: 100px; font-family: monospace;"
                                    <?= !$canEditSettings ? 'disabled' : '' ?>>
                            </div>
                        </div>
                    </div>
                    
                    <?php if ($canEditSettings): ?>
                    <div style="margin-top: 1rem;">
                        <button onclick="saveStorageColor()" class="btn btn-primary">💾 Farbe speichern</button>
                    </div>
                    <?php endif; ?>
                </div>

                    <div class="setting-row">
                        <div>
                            <div class="setting-label">Legende anzeigen</div>
                            <div class="setting-description">Status-Legende in der unteren linken Ecke</div>
                        </div>
                        <div class="setting-control">
                            <input type="checkbox" id="show_legend" name="show_legend" 
                                   class="checkbox-input" <?= $currentSettings['show_legend'] ? 'checked' : '' ?>
                                   <?= !$canEditSettings ? 'disabled' : '' ?>>
                        </div>
                    </div>
                    
                    <div class="setting-row">
                        <div>
                            <div class="setting-label">Benachrichtigungen aktivieren</div>
                            <div class="setting-description">System-Benachrichtigungen anzeigen</div>
                        </div>
                        <div class="setting-control">
                            <input type="checkbox" id="enable_notifications" name="enable_notifications" 
                                   class="checkbox-input" <?= $currentSettings['enable_notifications'] ? 'checked' : '' ?>
                                   <?= !$canEditSettings ? 'disabled' : '' ?>>
                        </div>
                    </div>
                    
                    <div class="setting-row">
                        <div>
                            <div class="setting-label">Tooltip-Verzögerung</div>
                            <div class="setting-description">Wartezeit bevor Tooltip erscheint (0-2000ms)</div>
                        </div>
                        <div class="setting-control">
                            <input type="range" id="tooltip_delay" name="tooltip_delay" 
                                   min="0" max="2000" step="100" value="<?= $currentSettings['tooltip_delay'] ?>" 
                                   class="range-input" <?= !$canEditSettings ? 'disabled' : '' ?>>
                            <span class="range-value" id="tooltip_delay_value"><?= $currentSettings['tooltip_delay'] ?>ms</span>
                        </div>
                    </div>
                    
                    <div class="setting-row">
                        <div>
                            <div class="setting-label">Hintergrund-Unschärfe im Admin-Modus</div>
                            <div class="setting-description">Leichte Unschärfe beim Platzieren neuer Marker</div>
                        </div>
                        <div class="setting-control">
                            <input type="checkbox" id="background_blur_admin" name="background_blur_admin" 
                                   class="checkbox-input" <?= $currentSettings['background_blur_admin'] ? 'checked' : '' ?>
                                   <?= !$canEditSettings ? 'disabled' : '' ?>>
                        </div>
                    </div>
                </div>
                
                <!-- System-Einstellungen -->
                <div class="setting-section">
                    <h3>⚙️ System-Einstellungen</h3>
                    
                    <div class="setting-row">
                        <div>
                            <div class="setting-label">Auto-Save Intervall</div>
                            <div class="setting-description">Automatisches Speichern alle X Sekunden (10-300s)</div>
                        </div>
                        <div class="setting-control">
                            <input type="range" id="auto_save_interval" name="auto_save_interval" 
                                   min="10" max="300" step="10" value="<?= $currentSettings['auto_save_interval'] ?>" 
                                   class="range-input" <?= !$canEditSettings ? 'disabled' : '' ?>>
                            <span class="range-value" id="auto_save_interval_value"><?= $currentSettings['auto_save_interval'] ?>s</span>
                        </div>
                    </div>
                    
                    <!-- System-Statistiken -->
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-value"><?= $totalObjects ?></div>
                            <div class="stat-label">Gesamte Objekte</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-value"><?= $availableObjects ?></div>
                            <div class="stat-label">Verfügbar</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-value"><?= $rentedObjects ?></div>
                            <div class="stat-label">Vermietet</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-value"><?= $totalUsers ?></div>
                            <div class="stat-label">Benutzer</div>
                        </div>
                    </div>
                </div>
                
                <?php if ($canEditSettings): ?>
                <div class="action-buttons">
                    <button type="submit" class="btn btn-primary">💾 Einstellungen speichern</button>
                    <button type="button" class="btn btn-secondary" onclick="resetSettings()">🔄 Zurücksetzen</button>
                    <button type="button" class="btn btn-secondary" onclick="applySettingsLive()">👁️ Live-Vorschau</button>
                </div>
                <?php else: ?>
                <div class="action-buttons">
                    <p style="color: var(--text-muted); font-style: italic;">
                        Sie benötigen die Berechtigung "Einstellungen ändern" um Änderungen vorzunehmen
                    </p>
                </div>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <script>
        let currentSettings = <?= json_encode($currentSettings) ?>;
        const canEditSettings = <?= $canEditSettings ? 'true' : 'false' ?>;
        
        document.addEventListener('DOMContentLoaded', function() {
            updatePreview();
            
            if (canEditSettings) {
                document.querySelectorAll('input[type="range"]').forEach(input => {
                    input.addEventListener('input', function() {
                        updatePreview();
                        updateRangeValues();
                    });
                });
                
                const backgroundInput = document.getElementById('background-image');
                if (backgroundInput) {
                    backgroundInput.addEventListener('change', previewBackgroundImage);
                }
            }
        });
        
        function updatePreview() {
            const markerSize = document.getElementById('marker_size').value;
            const borderWidth = document.getElementById('marker_border_width').value;
            const hoverScale = document.getElementById('marker_hover_scale').value;
            const shadowIntensity = document.getElementById('marker_shadow_intensity').value;
            const enablePulse = document.getElementById('enable_marker_pulse').checked;
            
            const previewMarkers = document.querySelectorAll('.preview-marker');
            
            previewMarkers.forEach(marker => {
                marker.style.width = markerSize + 'px';
                marker.style.height = markerSize + 'px';
                marker.style.borderWidth = borderWidth + 'px';
                marker.style.boxShadow = `0 2px 8px rgba(0,0,0,${shadowIntensity})`;
                
                if (enablePulse) {
                    marker.classList.remove('no-pulse');
                } else {
                    marker.classList.add('no-pulse');
                }
                
                marker.onmouseenter = function() {
                    this.style.transform = `scale(${hoverScale})`;
                };
                marker.onmouseleave = function() {
                    this.style.transform = 'scale(1)';
                };
            });
            
            updateRangeValues();
        }
        
        function updateRangeValues() {
            document.getElementById('marker_size_value').textContent = document.getElementById('marker_size').value + 'px';
            document.getElementById('marker_border_width_value').textContent = document.getElementById('marker_border_width').value + 'px';
            document.getElementById('marker_hover_scale_value').textContent = document.getElementById('marker_hover_scale').value + 'x';
            document.getElementById('marker_shadow_intensity_value').textContent = document.getElementById('marker_shadow_intensity').value;
            document.getElementById('tooltip_delay_value').textContent = document.getElementById('tooltip_delay').value + 'ms';
            document.getElementById('auto_save_interval_value').textContent = document.getElementById('auto_save_interval').value + 's';
        }
        
        function previewBackgroundImage(e) {
            if (!e || !e.target || !e.target.files || !e.target.files[0]) return;
            
            const file = e.target.files[0];
            const preview = document.getElementById('background-preview');
            const previewImage = document.getElementById('preview-image');
            
            if (!preview || !previewImage) return;
            
            const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png'];
            if (!allowedTypes.includes(file.type)) {
                showNotification('Bitte wählen Sie eine JPG oder PNG Datei', 'warning');
                e.target.value = '';
                preview.style.display = 'none';
                return;
            }
            
            const maxSize = 10 * 1024 * 1024;
            if (file.size > maxSize) {
                showNotification('Datei zu groß. Maximum: 10MB', 'warning');
                e.target.value = '';
                preview.style.display = 'none';
                return;
            }
            
            const reader = new FileReader();
            reader.onload = function(e) {
                previewImage.src = e.target.result;
                preview.style.display = 'block';
            };
            reader.readAsDataURL(file);
        }
        
        <?php if ($canEditSettings): ?>
        document.getElementById('upload-background-form').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            formData.append('action', 'upload_background');
            
            const fileInput = this.querySelector('input[type="file"]');
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalText = submitBtn.textContent;
            
            if (!fileInput.files.length) {
                showNotification('Bitte wählen Sie eine Datei aus', 'warning');
                return;
            }
            
            try {
                submitBtn.disabled = true;
                submitBtn.textContent = 'Hochladen...';
                
                const response = await fetch('settings.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showNotification('Hintergrundbild erfolgreich hochgeladen', 'success');
                    setTimeout(() => {
                        location.reload();
                    }, 1500);
                } else {
                    showNotification(result.message || 'Upload fehlgeschlagen', 'error');
                }
            } catch (error) {
                showNotification('Verbindungsfehler beim Upload', 'error');
            } finally {
                submitBtn.disabled = false;
                submitBtn.textContent = originalText;
            }
        });
        
        document.getElementById('settings-form').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            formData.append('action', 'save_settings');
            
            try {
                const response = await fetch('settings.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showNotification('Einstellungen gespeichert', 'success');
                    applySettingsToMainPage();
                } else {
                    showNotification(result.message, 'error');
                }
            } catch (error) {
                showNotification('Fehler beim Speichern', 'error');
            }
        });
        
        async function resetSettings() {
            if (!confirm('Möchten Sie alle Einstellungen auf die Standardwerte zurücksetzen?')) {
                return;
            }
            
            try {
                const formData = new FormData();
                formData.append('action', 'reset_settings');
                
                const response = await fetch('settings.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showNotification('Einstellungen zurückgesetzt', 'success');
                    setTimeout(() => location.reload(), 1000);
                }
            } catch (error) {
                showNotification('Fehler beim Zurücksetzen', 'error');
            }
        }
        
        function applySettingsLive() {
            applySettingsToMainPage();
            showNotification('Live-Vorschau angewendet (temporär)', 'info');
        }
        <?php endif; ?>
        
        function applySettingsToMainPage() {
            const markerSize = document.getElementById('marker_size').value;
            const borderWidth = document.getElementById('marker_border_width').value;
            const hoverScale = document.getElementById('marker_hover_scale').value;
            const shadowIntensity = document.getElementById('marker_shadow_intensity').value;
            const enablePulse = document.getElementById('enable_marker_pulse').checked;
            const showLegend = document.getElementById('show_legend').checked;
            
            const css = `
                .map-object {
                    width: ${markerSize}px !important;
                    height: ${markerSize}px !important;
                    border-width: ${borderWidth}px !important;
                    box-shadow: 0 2px 8px rgba(0,0,0,${shadowIntensity}) !important;
                    ${enablePulse ? '' : 'animation: none !important;'}
                }
                
                .map-object::before {
                    ${enablePulse ? '' : 'display: none !important;'}
                }
                
                .map-object:hover {
                    transform: scale(${hoverScale}) !important;
                }
                
                .legend {
                    display: ${showLegend ? 'block' : 'none'} !important;
                }
            `;
            
            localStorage.setItem('mapCustomCSS', css);
        }
        
        function showNotification(message, type = 'success', duration = 4000) {
            const notification = document.createElement('div');
            notification.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                background: white;
                color: #333;
                border: 1px solid #ddd;
                border-radius: 8px;
                padding: 1rem;
                box-shadow: 0 4px 12px rgba(0,0,0,0.15);
                z-index: 3000;
                animation: slideInRight 0.3s ease;
                border-left: 4px solid ${type === 'error' ? '#dc3545' : type === 'warning' ? '#ffc107' : '#28a745'};
                max-width: 300px;
            `;
            
            notification.innerHTML = `
                <div style="display: flex; align-items: center; justify-content: space-between; gap: 1rem;">
                    <span style="font-weight: 500;">${message}</span>
                    <button onclick="this.parentElement.parentElement.remove()" 
                            style="background: none; border: none; font-size: 1.2rem; cursor: pointer; color: #666;">×</button>
                </div>
            `;
            
            document.body.appendChild(notification);
            
            if (duration > 0) {
                setTimeout(() => notification.remove(), duration);
            }
        }
        
        const style = document.createElement('style');
        style.textContent = `
            @keyframes slideInRight {
                from { transform: translateX(100%); opacity: 0; }
                to { transform: translateX(0); opacity: 1; }
            }
        `;
        document.head.appendChild(style);
        
// E-Mail Test
        <?php if ($canEditSettings): ?>
        document.getElementById('test-email-form').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            formData.append('action', 'test_email_notification');
            
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalText = submitBtn.textContent;
            const resultDiv = document.getElementById('test-email-result');
            
            try {
                submitBtn.disabled = true;
                submitBtn.textContent = '📧 Sende...';
                resultDiv.style.display = 'none';
                
                const response = await fetch('settings.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    resultDiv.style.cssText = 'display: block; background: #e8f5e9; border: 2px solid #4caf50; border-radius: 8px; padding: 1rem; color: #2e7d32;';
                    resultDiv.innerHTML = `
                        <strong>✅ Erfolgreich!</strong><br>
                        ${result.message}<br>
                        <small>Versendete E-Mails: ${result.sent_count}</small>
                    `;
                    showNotification(result.message, 'success');
                    
                    // Formular zurücksetzen
                    this.reset();
                } else {
                    resultDiv.style.cssText = 'display: block; background: #ffebee; border: 2px solid #f44336; border-radius: 8px; padding: 1rem; color: #c62828;';
                    resultDiv.innerHTML = `<strong>❌ Fehler:</strong><br>${result.message}`;
                    showNotification(result.message, 'error');
                }
            } catch (error) {
                resultDiv.style.cssText = 'display: block; background: #ffebee; border: 2px solid #f44336; border-radius: 8px; padding: 1rem; color: #c62828;';
                resultDiv.innerHTML = `<strong>❌ Verbindungsfehler:</strong><br>${error.message}`;
                showNotification('Verbindungsfehler beim Senden der Test-E-Mail', 'error');
            } finally {
                submitBtn.disabled = false;
                submitBtn.textContent = originalText;
            }
        });
        
        async function loadNotificationRecipients() {
            try {
                const formData = new FormData();
                formData.append('action', 'get_notification_recipients');
                
                const response = await fetch('settings.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    document.getElementById('notification-recipients-count').textContent = result.count;
                    showNotification(`${result.count} Empfänger gefunden`, 'success', 2000);
                }
            } catch (error) {
                console.error('Error loading recipients:', error);
            }
        }
        
        // Empfänger beim Laden der Seite abrufen
        if (document.getElementById('notification-recipients-count')) {
            loadNotificationRecipients();
        }
        <?php endif; ?>

        updateRangeValues();

        // Lagergeräte-Farbe
        const storageColorInput = document.getElementById('storage-device-color');
        const storageColorText = document.getElementById('storage-color-text');
        const storageColorPreview = document.getElementById('storage-color-preview');

        if (storageColorInput && storageColorText && storageColorPreview) {
            storageColorInput.addEventListener('input', function() {
                storageColorText.value = this.value;
                storageColorPreview.style.background = this.value;
            });
            
            storageColorText.addEventListener('input', function() {
                if (/^#[0-9A-Fa-f]{6}$/.test(this.value)) {
                    storageColorInput.value = this.value;
                    storageColorPreview.style.background = this.value;
                }
            });
        }

        async function saveStorageColor() {
            const color = document.getElementById('storage-device-color').value;
            
            try {
                const formData = new FormData();
                formData.append('action', 'save_storage_color');
                formData.append('storage_color', color);
                
                const response = await fetch('settings.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showNotification('Lagergeräte-Farbe gespeichert', 'success');
                    
                    // CSS-Variable aktualisieren
                    document.documentElement.style.setProperty('--storage-device-color', color);
                    
                    // Vorschau aktualisieren
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showNotification(result.message, 'error');
                }
            } catch (error) {
                showNotification('Fehler beim Speichern', 'error');
            }
        }
    </script>
</body>
</html>