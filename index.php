<?php
require_once 'config.php';
require_once 'maintenance_check.php';

// Automatische Wartungsprüfung beim Laden der Seite (nur wenn angemeldet)
if (Auth::isLoggedIn()) {
    try {
        $maintenanceResult = checkMaintenanceDue();
        $_SESSION['maintenance_notifications'] = $maintenanceResult['notifications'];
    } catch (Exception $e) {
        error_log("Maintenance check error: " . $e->getMessage());
    }
}

// AJAX Request Handling
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    try {
        switch ($_POST['action']) {
            case 'search_objects':
                Auth::requireLogin();
                Auth::requirePermission('marker.view');
                
                $searchTerm = sanitizeInput($_POST['search'] ?? '');
                $statusFilter = sanitizeInput($_POST['status'] ?? '');
                $categoryFilter = sanitizeInput($_POST['category'] ?? '');
                
                $db = Database::getInstance()->getConnection();
                
                $sql = "SELECT *, 
                        CASE 
                            WHEN next_maintenance_due IS NOT NULL AND next_maintenance_due <= CURDATE() THEN TRUE
                            ELSE FALSE
                        END as maintenance_overdue
                        FROM map_objects WHERE 1=1";
                $params = [];
                
                if (!empty($searchTerm)) {
                    $sql .= " AND (title LIKE ? OR description LIKE ? OR id = ?)";
                    $searchPattern = '%' . $searchTerm . '%';
                    $params[] = $searchPattern;
                    $params[] = $searchPattern;
                    $params[] = $searchTerm;
                }
                
                if (!empty($statusFilter) && $statusFilter !== 'all') {
                    $sql .= " AND status = ?";
                    $params[] = $statusFilter;
                }
                
                if (!empty($categoryFilter) && $categoryFilter !== 'all') {
                    $sql .= " AND category = ?";
                    $params[] = $categoryFilter;
                }
                
                $sql .= " ORDER BY title ASC";
                
                $stmt = $db->prepare($sql);
                $stmt->execute($params);
                $results = $stmt->fetchAll();
                
                logActivity('search_objects', "Search: '$searchTerm', Results: " . count($results));
                echo json_encode(['success' => true, 'results' => $results, 'count' => count($results)]);
                break;
            
            case 'login':
                $username = sanitizeInput($_POST['username'] ?? '');
                $password = $_POST['password'] ?? '';
                
                if (empty($username) || empty($password)) {
                    throw new Exception('Benutzername und Passwort sind erforderlich');
                }
                
                if (Auth::login($username, $password)) {
                    logActivity('login', 'Successful login');
                    echo json_encode(['success' => true]);
                } else {
                    logActivity('login_failed', "Failed login attempt for: $username");
                    echo json_encode(['success' => false, 'message' => 'Ungültige Anmeldedaten']);
                }
                break;
                
            case 'logout':
                logActivity('logout', 'User logged out');
                Auth::logout();
                echo json_encode(['success' => true]);
                break;
                
            case 'add_object':
                Auth::requireLogin();
                Auth::requirePermission('marker.create', 'Sie haben keine Berechtigung zum Erstellen von Markern');
                
                $title = sanitizeInput($_POST['title'] ?? '');
                $description = sanitizeInput($_POST['description'] ?? '');
                $x_percent = floatval($_POST['x_percent'] ?? 50);
                $y_percent = floatval($_POST['y_percent'] ?? 50);
                $category = sanitizeInput($_POST['category'] ?? 'general');
                
                // WARTUNGSFELDER
                $lastMaintenance = !empty($_POST['last_maintenance']) ? $_POST['last_maintenance'] : null;
                $maintenanceInterval = !empty($_POST['maintenance_interval']) ? intval($_POST['maintenance_interval']) : null;
                
                // NEU: LAGERGERÄT
                $isStorageDevice = isset($_POST['is_storage_device']) ? 1 : 0;
                
                if (empty($title)) {
                    throw new Exception('Titel ist erforderlich');
                }
                
                if ($x_percent < 0 || $x_percent > 100 || $y_percent < 0 || $y_percent > 100) {
                    throw new Exception('Ungültige Koordinaten');
                }
                
                // Wenn Lagergerät, keine Wartung erlauben
                if ($isStorageDevice) {
                    $lastMaintenance = null;
                    $maintenanceInterval = null;
                } else {
                    // Wartungsdatum validieren
                    if ($lastMaintenance && !validateDate($lastMaintenance)) {
                        throw new Exception('Ungültiges Wartungsdatum');
                    }
                    
                    // Wartungsintervall validieren
                    if ($maintenanceInterval && ($maintenanceInterval < 1 || $maintenanceInterval > 3650)) {
                        throw new Exception('Wartungsintervall muss zwischen 1 und 3650 Tagen liegen');
                    }
                }
                
                $image_path = null;
                if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                    if (validateImageFile($_FILES['image'])) {
                        $image_path = ImageHelper::handleImageUpload($_FILES['image']);
                    }
                }
                
                $db = Database::getInstance()->getConnection();
                $stmt = $db->prepare("
                    INSERT INTO map_objects 
                    (title, description, image_path, x_percent, y_percent, category, created_by, 
                    last_maintenance, maintenance_interval_days, is_storage_device) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $title, 
                    $description, 
                    $image_path, 
                    $x_percent, 
                    $y_percent, 
                    $category, 
                    Auth::getUserId(),
                    $lastMaintenance,
                    $maintenanceInterval,
                    $isStorageDevice
                ]);
                
                $newId = $db->lastInsertId();
                logActivity('object_created', "Object created: $title (ID: $newId)");
                
                echo json_encode(['success' => true, 'id' => $newId]);
                break;
                
            case 'get_object':
                Auth::requireLogin();
                Auth::requirePermission('marker.view');
                
                $id = intval($_POST['id'] ?? 0);
                if ($id <= 0) {
                    throw new Exception('Ungültige Objekt-ID');
                }
                
                $db = Database::getInstance()->getConnection();
                $stmt = $db->prepare("
                    SELECT *,
                    CASE 
                        WHEN next_maintenance_due IS NOT NULL AND next_maintenance_due <= CURDATE() THEN TRUE
                        ELSE FALSE
                    END as maintenance_overdue,
                    DATEDIFF(next_maintenance_due, CURDATE()) as days_until_maintenance
                    FROM map_objects WHERE id = ?
                ");
                $stmt->execute([$id]);
                $object = $stmt->fetch();
                
                if ($object) {
                    echo json_encode(['success' => true, 'object' => $object]);
                } else {
                    throw new Exception('Objekt nicht gefunden');
                }
                break;
                
            case 'update_object':
                Auth::requireLogin();
                Auth::requirePermission('marker.edit', 'Sie haben keine Berechtigung zum Bearbeiten von Markern');
                
                $id = intval($_POST['id'] ?? 0);
                $title = sanitizeInput($_POST['title'] ?? '');
                $description = sanitizeInput($_POST['description'] ?? '');
                $category = sanitizeInput($_POST['category'] ?? 'general');
                
                // WARTUNGSFELDER
                $lastMaintenance = !empty($_POST['last_maintenance']) ? $_POST['last_maintenance'] : null;
                $maintenanceInterval = !empty($_POST['maintenance_interval']) ? intval($_POST['maintenance_interval']) : null;
                
                // NEU: LAGERGERÄT
                $isStorageDevice = isset($_POST['is_storage_device']) ? 1 : 0;
                
                if ($id <= 0) {
                    throw new Exception('Ungültige Objekt-ID');
                }
                
                if (empty($title)) {
                    throw new Exception('Titel ist erforderlich');
                }
                
                // Wenn Lagergerät, keine Wartung erlauben
                if ($isStorageDevice) {
                    $lastMaintenance = null;
                    $maintenanceInterval = null;
                } else {
                    // Wartungsdatum validieren
                    if ($lastMaintenance && !validateDate($lastMaintenance)) {
                        throw new Exception('Ungültiges Wartungsdatum');
                    }
                    
                    // Wartungsintervall validieren
                    if ($maintenanceInterval && ($maintenanceInterval < 1 || $maintenanceInterval > 3650)) {
                        throw new Exception('Wartungsintervall muss zwischen 1 und 3650 Tagen liegen');
                    }
                }
                
                $db = Database::getInstance()->getConnection();
                
                $stmt = $db->prepare("SELECT * FROM map_objects WHERE id = ?");
                $stmt->execute([$id]);
                $current_object = $stmt->fetch();
                
                if (!$current_object) {
                    throw new Exception('Objekt nicht gefunden');
                }
                
                $image_path = $current_object['image_path'];
                
                if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                    if (validateImageFile($_FILES['image'])) {
                        $new_image_path = ImageHelper::handleImageUpload($_FILES['image']);
                        
                        if ($image_path && file_exists($image_path)) {
                            unlink($image_path);
                        }
                        
                        $image_path = $new_image_path;
                    }
                }
                
                $stmt = $db->prepare("
                    UPDATE map_objects 
                    SET title = ?, description = ?, image_path = ?, category = ?,
                        last_maintenance = ?, maintenance_interval_days = ?,
                        is_storage_device = ?,
                        updated_at = NOW() 
                    WHERE id = ?
                ");
                $stmt->execute([
                    $title, 
                    $description, 
                    $image_path, 
                    $category,
                    $lastMaintenance,
                    $maintenanceInterval,
                    $isStorageDevice,
                    $id
                ]);
                
                logActivity('object_updated', "Object updated: $title (ID: $id)");
                echo json_encode(['success' => true]);
                break;
            case 'update_position':
                Auth::requireLogin();
                Auth::requirePermission('marker.edit', 'Sie haben keine Berechtigung zum Verschieben von Markern');
                
                $id = intval($_POST['id'] ?? 0);
                $x_percent = floatval($_POST['x_percent'] ?? 0);
                $y_percent = floatval($_POST['y_percent'] ?? 0);
                
                if ($id <= 0) {
                    throw new Exception('Ungültige Objekt-ID');
                }
                
                if ($x_percent < 0 || $x_percent > 100 || $y_percent < 0 || $y_percent > 100) {
                    throw new Exception('Ungültige Koordinaten');
                }
                
                $db = Database::getInstance()->getConnection();
                $stmt = $db->prepare("UPDATE map_objects SET x_percent = ?, y_percent = ?, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$x_percent, $y_percent, $id]);
                
                echo json_encode(['success' => true]);
                break;
                
            case 'delete_object':
                Auth::requireLogin();
                Auth::requirePermission('marker.delete', 'Sie haben keine Berechtigung zum Löschen von Markern');
                
                $id = intval($_POST['id'] ?? 0);
                if ($id <= 0) {
                    throw new Exception('Ungültige Objekt-ID');
                }
                
                $db = Database::getInstance()->getConnection();
                
                $stmt = $db->prepare("SELECT * FROM map_objects WHERE id = ?");
                $stmt->execute([$id]);
                $object = $stmt->fetch();
                
                if (!$object) {
                    throw new Exception('Objekt nicht gefunden');
                }
                
                if ($object['image_path'] && file_exists($object['image_path'])) {
                    unlink($object['image_path']);
                }
                
                $stmt = $db->prepare("DELETE FROM map_objects WHERE id = ?");
                $stmt->execute([$id]);
                
                logActivity('object_deleted', "Object deleted: {$object['title']} (ID: $id)");
                echo json_encode(['success' => true]);
                break;
                
			case 'set_status':
				Auth::requireLogin();
				Auth::requirePermission('marker.change_status', 'Sie haben keine Berechtigung zum Ändern des Status');

				$id = intval($_POST['id'] ?? 0);
				$new_status = sanitizeInput($_POST['status'] ?? '');

				if ($id <= 0) {
					throw new Exception('Ungültige Objekt-ID');
				}

				$allowed_statuses = ['available', 'rented', 'maintenance'];
				if (!in_array($new_status, $allowed_statuses)) {
					throw new Exception('Ungültiger Status');
				}

				$db = Database::getInstance()->getConnection();

				$stmt = $db->prepare("SELECT status, title, is_storage_device FROM map_objects WHERE id = ?");
				$stmt->execute([$id]);
				$object = $stmt->fetch();

				if (!$object) {
					throw new Exception('Objekt nicht gefunden');
				}

				// NEU: Lagergeräte können keinen Status ändern
				if ($object['is_storage_device'] == 1) {
					throw new Exception('Lagergeräte haben einen festen Status und können nicht geändert werden');
				}

				$stmt = $db->prepare("UPDATE map_objects SET status = ?, updated_at = NOW() WHERE id = ?");
				$stmt->execute([$new_status, $id]);

				logActivity('status_set', "Status set to $new_status for object '{$object['title']}' (ID: $id)");
				echo json_encode(['success' => true, 'status' => $new_status]);
				break;

			case 'get_users':
				Auth::requireLogin();
				Auth::requirePermission('user.view', 'Sie haben keine Berechtigung zur Benutzerverwaltung');

				$db = Database::getInstance()->getConnection();
				$stmt = $db->query("
					SELECT u.id, u.username, u.role_id, u.is_active, u.created_at, u.last_login,
						   u.email, u.receive_maintenance_notifications,
						   r.name as role_name, r.display_name as role_display_name
					FROM users u
					INNER JOIN roles r ON u.role_id = r.id
					ORDER BY u.created_at DESC
				");
				$users = $stmt->fetchAll();

				echo json_encode(['success' => true, 'users' => $users]);
				break;
                
			case 'add_user':
				Auth::requireLogin();
				Auth::requirePermission('user.create', 'Sie haben keine Berechtigung zum Anlegen von Benutzern');

				$username = sanitizeInput($_POST['username'] ?? '');
				$password = $_POST['password'] ?? '';
				$roleId = intval($_POST['role_id'] ?? 0);
				$email = sanitizeInput($_POST['email'] ?? '');
				$receiveNotifications = isset($_POST['receive_notifications']) ? 1 : 0;

				if (strlen($username) < 3) {
					throw new Exception('Benutzername muss mindestens 3 Zeichen lang sein');
				}

				if (strlen($password) < 6) {
					throw new Exception('Passwort muss mindestens 6 Zeichen lang sein');
				}

				if ($roleId <= 0) {
					throw new Exception('Ungültige Rolle');
				}

				// E-Mail validieren (nur wenn angegeben)
				if (!empty($email) && !validateEmail($email)) {
					throw new Exception('Ungültige E-Mail-Adresse');
				}

				// Wenn Benachrichtigungen aktiviert sind, muss E-Mail angegeben sein
				if ($receiveNotifications && empty($email)) {
					throw new Exception('E-Mail-Adresse erforderlich für Benachrichtigungen');
				}

				$db = Database::getInstance()->getConnection();

				$stmt = $db->prepare("SELECT id FROM roles WHERE id = ?");
				$stmt->execute([$roleId]);
				if (!$stmt->fetch()) {
					throw new Exception('Rolle nicht gefunden');
				}

				$stmt = $db->prepare("SELECT id FROM users WHERE username = ?");
				$stmt->execute([$username]);
				if ($stmt->fetch()) {
					throw new Exception('Benutzername bereits vergeben');
				}

				// Prüfe ob E-Mail bereits verwendet wird (falls angegeben)
				if (!empty($email)) {
					$stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
					$stmt->execute([$email]);
					if ($stmt->fetch()) {
						throw new Exception('E-Mail-Adresse bereits vergeben');
					}
				}

				$hashed_password = password_hash($password, PASSWORD_DEFAULT);
				$stmt = $db->prepare("
					INSERT INTO users (username, password, role_id, email, receive_maintenance_notifications) 
					VALUES (?, ?, ?, ?, ?)
				");
				$stmt->execute([$username, $hashed_password, $roleId, $email ?: null, $receiveNotifications]);

				$newUserId = $db->lastInsertId();
				logActivity('user_created', "User created: $username (ID: $newUserId)");

				echo json_encode(['success' => true, 'message' => 'Benutzer erfolgreich erstellt']);
				break;

			case 'update_user':
				Auth::requireLogin();
				Auth::requirePermission('user.edit', 'Sie haben keine Berechtigung zum Bearbeiten von Benutzern');

				$id = intval($_POST['id'] ?? 0);
				$username = sanitizeInput($_POST['username'] ?? '');
				$roleId = intval($_POST['role_id'] ?? 0);
				$password = $_POST['password'] ?? '';
				$email = sanitizeInput($_POST['email'] ?? '');
				$receiveNotifications = isset($_POST['receive_notifications']) ? 1 : 0;

				if ($id <= 0) {
					throw new Exception('Ungültige Benutzer-ID');
				}

				if (strlen($username) < 3) {
					throw new Exception('Benutzername muss mindestens 3 Zeichen lang sein');
				}

				if (!empty($password) && strlen($password) < 6) {
					throw new Exception('Passwort muss mindestens 6 Zeichen lang sein');
				}

				if ($roleId <= 0) {
					throw new Exception('Ungültige Rolle');
				}

				// E-Mail validieren (nur wenn angegeben)
				if (!empty($email) && !validateEmail($email)) {
					throw new Exception('Ungültige E-Mail-Adresse');
				}

				// Wenn Benachrichtigungen aktiviert sind, muss E-Mail angegeben sein
				if ($receiveNotifications && empty($email)) {
					throw new Exception('E-Mail-Adresse erforderlich für Benachrichtigungen');
				}

				$db = Database::getInstance()->getConnection();

				$stmt = $db->prepare("SELECT id FROM roles WHERE id = ?");
				$stmt->execute([$roleId]);
				if (!$stmt->fetch()) {
					throw new Exception('Rolle nicht gefunden');
				}

				$stmt = $db->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
				$stmt->execute([$username, $id]);
				if ($stmt->fetch()) {
					throw new Exception('Benutzername bereits vergeben');
				}

				// Prüfe ob E-Mail bereits verwendet wird (falls angegeben)
				if (!empty($email)) {
					$stmt = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
					$stmt->execute([$email, $id]);
					if ($stmt->fetch()) {
						throw new Exception('E-Mail-Adresse bereits vergeben');
					}
				}

				if (!empty($password)) {
					$hashed_password = password_hash($password, PASSWORD_DEFAULT);
					$stmt = $db->prepare("
						UPDATE users 
						SET username = ?, role_id = ?, password = ?, email = ?, receive_maintenance_notifications = ?
						WHERE id = ?
					");
					$stmt->execute([$username, $roleId, $hashed_password, $email ?: null, $receiveNotifications, $id]);
				} else {
					$stmt = $db->prepare("
						UPDATE users 
						SET username = ?, role_id = ?, email = ?, receive_maintenance_notifications = ?
						WHERE id = ?
					");
					$stmt->execute([$username, $roleId, $email ?: null, $receiveNotifications, $id]);
				}

				logActivity('user_updated', "User updated: $username (ID: $id)");
				echo json_encode(['success' => true]);
				break;
                
            case 'delete_user':
                Auth::requireLogin();
                Auth::requirePermission('user.delete', 'Sie haben keine Berechtigung zum Löschen von Benutzern');
                
                $id = intval($_POST['id'] ?? 0);
                
                if ($id <= 0) {
                    throw new Exception('Ungültige Benutzer-ID');
                }
                
                if ($id == Auth::getUserId()) {
                    throw new Exception('Sie können sich nicht selbst löschen');
                }
                
                $db = Database::getInstance()->getConnection();
                
                $stmt = $db->prepare("SELECT username FROM users WHERE id = ?");
                $stmt->execute([$id]);
                $username = $stmt->fetchColumn();
                
                if (!$username) {
                    throw new Exception('Benutzer nicht gefunden');
                }
                
                $stmt = $db->prepare("DELETE FROM users WHERE id = ?");
                $stmt->execute([$id]);
                
                logActivity('user_deleted', "User deleted: $username (ID: $id)");
                echo json_encode(['success' => true]);
                break;
                
            case 'get_roles_list':
                Auth::requireLogin();
                
                $db = Database::getInstance()->getConnection();
                $stmt = $db->query("SELECT id, name, display_name FROM roles ORDER BY display_name");
                $roles = $stmt->fetchAll();
                
                echo json_encode(['success' => true, 'roles' => $roles]);
                break;
                
            default:
                throw new Exception('Unbekannte Aktion');
        }
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    
    exit;
}

// Daten für Frontend laden
$db = Database::getInstance()->getConnection();

// Alle Objekte laden
$stmt = $db->query("SELECT * FROM map_objects ORDER BY created_at DESC");
$objects = $stmt->fetchAll();

// Aktuelles Hintergrundbild
$currentBackground = ImageHelper::getCurrentBackground();

// Kategorien dynamisch aus Datenbank laden
$categories = CategoryHelper::getCategoryNames();

// Wartungsintervalle (in Tagen)
$maintenanceIntervals = [
    '' => 'Kein Intervall',
    '7' => '1 Woche',
    '14' => '2 Wochen',
    '30' => '1 Monat',
    '60' => '2 Monate',
    '90' => '3 Monate',
    '180' => '6 Monate',
    '365' => '1 Jahr',
    'custom' => 'Benutzerdefiniert'
];

// Berechtigungen für Frontend
$permissions = [
    'canCreateMarker' => Auth::hasPermission('marker.create'),
    'canViewCategories' => Auth::hasPermission('category.view'),
    'canCreateCategories' => Auth::hasPermission('category.create'),
    'canEditCategories' => Auth::hasPermission('category.edit'),
    'canDeleteCategories' => Auth::hasPermission('category.delete'),
    'canEditMarker' => Auth::hasPermission('marker.edit'),
    'canDeleteMarker' => Auth::hasPermission('marker.delete'),
    'canChangeStatus' => Auth::hasPermission('marker.change_status'),
    'canViewSettings' => Auth::hasPermission('settings.view'),
    'canEditSettings' => Auth::hasPermission('settings.edit'),
    'canViewUsers' => Auth::hasPermission('user.view'),
    'canCreateUsers' => Auth::hasPermission('user.create'),
    'canEditUsers' => Auth::hasPermission('user.edit'),
    'canDeleteUsers' => Auth::hasPermission('user.delete'),
    'canViewRoles' => Auth::hasPermission('role.view'),
    'canCreateRoles' => Auth::hasPermission('role.create'),
    'canEditRoles' => Auth::hasPermission('role.edit'),
    'canDeleteRoles' => Auth::hasPermission('role.delete'),
];

// Wartungsbenachrichtigungen für aktuellen Benutzer
$maintenanceNotifications = $_SESSION['maintenance_notifications'] ?? [];
unset($_SESSION['maintenance_notifications']);

$storageDeviceColor = CategoryHelper::getStorageDeviceColor();
?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(SYSTEM_NAME) ?></title>
    <meta name="description" content="Bildbasiertes Objektverwaltungssystem mit Wartungsmanagement">
    <meta name="theme-color" content="#2c5530">
    <link rel="stylesheet" href="style.css">
    <style>
		:root {
			--storage-device-color: <?= htmlspecialchars($storageDeviceColor) ?>;
		}
	</style>
    <link rel="icon" type="image/x-icon" href="favicon.ico">
</head>
<body class="<?= Auth::isLoggedIn() ? (Auth::hasAnyPermission(['marker.create', 'marker.edit', 'marker.delete']) ? 'admin-mode' : 'user-mode') : '' ?>">
    
    <!-- Header -->
    <header class="header">
        <div class="container">
            <!-- Logo -->
            <a href="index.php" class="logo" title="Zur Startseite">
                <?= htmlspecialchars(SYSTEM_NAME) ?>
            </a>
            
            <!-- Navigation Actions -->
            <nav class="nav-actions">
                <?php if (Auth::isLoggedIn()): ?>
                    <!-- Benutzer Info -->
                    <div class="user-info" title="Angemeldeter Benutzer">
                        <div class="user-info-content">
                            <div class="user-greeting">Willkommen,</div>
                            <div class="user-name"><?= htmlspecialchars(Auth::getUsername()) ?></div>
                            <div class="user-role"><?= htmlspecialchars(Auth::getRoleDisplayName()) ?></div>
                        </div>
                    </div>

                    <!-- Divider -->
                    <div class="nav-divider"></div>

                    <!-- Kategorie-Filter -->
                    <select id="category-filter" class="filter-select" onchange="filterMarkersByCategory(this.value)" title="Nach Kategorie filtern">
                        <option value="all">📦 Alle Kategorien</option>
                        <?php foreach (CategoryHelper::getActiveCategories() as $cat): ?>
                            <option value="<?= $cat['name'] ?>"><?= $cat['icon'] ?> <?= $cat['display_name'] ?></option>
                        <?php endforeach; ?>
                    </select>
                    
                    <!-- Suche -->
                    <button id="search-btn" class="btn btn-secondary" title="Objekte suchen">
                        <span class="btn-icon">🔍</span>
                        <span>Suche</span>
                    </button>
                    
                    <!-- Marker hinzufügen -->
                    <?php if ($permissions['canCreateMarker']): ?>
                        <button id="add-object-btn" class="btn btn-primary" title="Neuen Marker hinzufügen">
                            <span class="btn-icon">➕</span>
                            <span>Marker</span>
                        </button>
                    <?php endif; ?>
                    
                    <!-- Bearbeiten Toggle -->
                    <?php if ($permissions['canEditMarker']): ?>
                        <button id="toggle-edit-mode-btn" class="btn btn-secondary" title="Marker-Positionen bearbeiten">
                            <span class="btn-icon">🔒</span>
                            <span id="edit-mode-text">Sperren</span>
                        </button>
                    <?php endif; ?>

                    <!-- Divider -->
                    <div class="nav-divider"></div>
                    
                    <!-- Benutzerverwaltung -->
                    <?php if ($permissions['canViewUsers']): ?>
                        <button id="manage-users-btn" class="btn btn-secondary" title="Benutzer verwalten">
                            <span class="btn-icon">👥</span>
                            <span>Benutzer</span>
                        </button>
                    <?php endif; ?>
                    
                    <!-- Rollen -->
                    <?php if ($permissions['canViewRoles']): ?>
                        <a href="roles.php" class="btn btn-secondary" title="Rollen verwalten">
                            <span class="btn-icon">🎭</span>
                            <span>Rollen</span>
                        </a>
                    <?php endif; ?>
                    
                    <!-- Kategorien -->
                    <?php if ($permissions['canViewCategories']): ?>
                        <a href="categories.php" class="btn btn-secondary" title="Kategorien verwalten">
                            <span class="btn-icon">📦</span>
                            <span>Kategorien</span>
                        </a>
                    <?php endif; ?>

                    <!-- Einstellungen -->
                    <?php if ($permissions['canViewSettings']): ?>
                        <a href="settings.php" class="btn btn-secondary" title="System-Einstellungen">
                            <span class="btn-icon">⚙️</span>
                            <span>Settings</span>
                        </a>
                    <?php endif; ?>

                    <!-- Divider -->
                    <div class="nav-divider"></div>
                    
                    <!-- Abmelden -->
                    <button id="logout-btn" class="btn btn-secondary" title="Abmelden">
                        <span class="btn-icon">🚪</span>
                        <span>Logout</span>
                    </button>
                <?php else: ?>
                    <!-- Login Button -->
                    <button id="login-btn" class="btn btn-primary">
                        <span class="btn-icon">🔐</span>
                        <span>Anmelden</span>
                    </button>
                <?php endif; ?>
            </nav>
        </div>
    </header>

    <!-- Suchpanel -->
    <?php if (Auth::isLoggedIn()): ?>
    <div id="search-panel" class="search-panel">
        <div class="search-panel-content">
            <div class="search-panel-header">
                <h3>🔍 Objekte durchsuchen</h3>
                <button id="close-search-btn" class="close-search-btn" title="Suche schließen">×</button>
            </div>
            
            <div class="search-controls">
                <div class="search-input-wrapper">
                    <input type="text" id="search-input" class="search-input" 
                           placeholder="Nach Titel, Beschreibung oder ID suchen...">
                    <button id="clear-search-btn" class="clear-search-btn" title="Eingabe löschen">×</button>
                </div>
                
                <div class="search-filters">
                    <select id="status-filter" class="filter-select">
                        <option value="all">Alle Status</option>
                        <option value="available">Verfügbar</option>
                        <option value="rented">Vermietet</option>
                        <option value="maintenance">Wartung</option>
                    </select>
                    
                    <select id="category-filter" class="filter-select">
                        <option value="all">Alle Kategorien</option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?= htmlspecialchars($category) ?>"><?= ucfirst(htmlspecialchars($category)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <div class="search-results-header">
                <span id="results-count">0 Ergebnisse</span>
                <button id="reset-search-btn" class="btn btn-secondary btn-sm">
                    🔄 Zurücksetzen
                </button>
            </div>
            
            <div id="search-results" class="search-results">
                <div class="search-empty">
                    Geben Sie einen Suchbegriff ein oder verwenden Sie die Filter
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Hauptinhalt -->
    <main class="main-content">
        <div class="image-map-container" id="image-map-container">
            
            <!-- Hintergrundbild Container -->
            <div class="background-image" id="background-image"
                 <?php if ($currentBackground): ?>
                 style="background-image: url('<?= htmlspecialchars($currentBackground) ?>'); background-size: contain; background-repeat: no-repeat; background-position: center;"
                 <?php endif; ?>>
                
                <!-- Mapmarker auf dem Bild -->
				<?php foreach ($objects as $object): ?>
					<?php 
					$maintenanceClass = '';
					$markerClass = '';

					// Lagergeräte haben eigene Klasse
					if ($object['is_storage_device'] == 1) {
						$markerClass = 'storage-device';
					} else {
						// Normale Objekte mit Status
						$markerClass = htmlspecialchars($object['status']);

						// Wartungswarnung nur für normale Objekte
						if ($object['next_maintenance_due'] && strtotime($object['next_maintenance_due']) <= time()) {
							$maintenanceClass = 'maintenance-due';
						}
					}
					?>
					<div class="map-object <?= $markerClass ?> <?= $maintenanceClass ?>" 
						 data-id="<?= $object['id'] ?>"
						 data-category="<?= htmlspecialchars($object['category']) ?>"
						 data-title="<?= htmlspecialchars($object['title']) ?>"
						 data-x-percent="<?= floatval($object['x_percent']) ?>"
						 data-y-percent="<?= floatval($object['y_percent']) ?>"
						 data-maintenance-due="<?= $object['next_maintenance_due'] ?? '' ?>"
						 data-is-storage-device="<?= $object['is_storage_device'] ?? 0 ?>"
						 style="left: <?= floatval($object['x_percent']) ?>%; top: <?= floatval($object['y_percent']) ?>%;">

						<?php if ($permissions['canEditMarker']): ?>
							<div class="drag-indicator"></div>
						<?php endif; ?>

						<?php if ($maintenanceClass && $object['is_storage_device'] != 1): ?>
							<div class="maintenance-warning-badge" title="Wartung überfällig">⚠️</div>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
                
                <!-- Nachricht wenn kein Hintergrundbild vorhanden -->
                <?php if (!$currentBackground): ?>
                    <div class="no-background-message">
                        <div class="message-icon">🖼️</div>
                        <h3>Kein Hintergrundbild</h3>
                        <p>Laden Sie ein Hintergrundbild in den Einstellungen hoch, um zu starten.</p>
                        <?php if ($permissions['canEditSettings']): ?>
                            <a href="settings.php" class="btn btn-primary">
                                ⚙️ Zu den Einstellungen
                            </a>
                        <?php else: ?>
                            <p><em>Wenden Sie sich an einen Administrator.</em></p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                
                <!-- Legende -->
                <?php if (!empty($objects)): ?>
                    <div class="legend" id="legend">
                        <h4>Legende</h4>
                        <div class="legend-items">
                            <div class="legend-item">
                                <div class="legend-marker available"></div>
                                <span>Verfügbar</span>
                            </div>
                            <div class="legend-item">
                                <div class="legend-marker rented"></div>
                                <span>Vermietet</span>
                            </div>
                            <div class="legend-item">
                                <div class="legend-marker maintenance"></div>
                                <span>Wartung</span>
                            </div>
                            <div class="legend-item">
                                <div class="legend-marker storage-device"></div>
                                <span>Lagergerät</span>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <!-- Dashboard/Statistiken Panel -->
        <?php if (Auth::isLoggedIn()): ?>
        <div id="stats-dashboard" class="stats-dashboard collapsed">
            <button id="dashboard-toggle" class="dashboard-toggle" title="Dashboard öffnen/schließen">
                <span class="toggle-icon">📊</span>
                <span class="toggle-text">Dashboard</span>
            </button>
            
            <div class="dashboard-content">
                <div class="dashboard-header">
                    <h3>📊 Statistik-Dashboard</h3>
                    <button id="refresh-dashboard" class="btn-icon" title="Aktualisieren">🔄</button>
                </div>
                
                <!-- KPI Cards -->
                <div class="kpi-grid">
                    <div class="kpi-card">
                        <div class="kpi-icon">📦</div>
                        <div class="kpi-value" id="kpi-total">0</div>
                        <div class="kpi-label">Gesamt</div>
                    </div>
                    <div class="kpi-card success">
                        <div class="kpi-icon">✅</div>
                        <div class="kpi-value" id="kpi-available">0</div>
                        <div class="kpi-label">Verfügbar</div>
                    </div>
                    <div class="kpi-card danger">
                        <div class="kpi-icon">🔴</div>
                        <div class="kpi-value" id="kpi-rented">0</div>
                        <div class="kpi-label">Vermietet</div>
                    </div>
                    <div class="kpi-card warning">
                        <div class="kpi-icon">🔧</div>
                        <div class="kpi-value" id="kpi-maintenance">0</div>
                        <div class="kpi-label">Wartung</div>
                    </div>
                </div>
                
                <!-- Auslastungsrate -->
                <div class="dashboard-section">
                    <h4>📈 Auslastungsrate</h4>
                    <div class="utilization-display">
                        <div class="utilization-chart">
                            <svg viewBox="0 0 100 100" class="circular-chart">
                                <circle class="circle-bg" cx="50" cy="50" r="40"/>
                                <circle class="circle-progress" id="utilization-circle" cx="50" cy="50" r="40"/>
                            </svg>
                            <div class="chart-center">
                                <span class="chart-percentage" id="utilization-percentage">0%</span>
                                <span class="chart-sublabel">Ausgelastet</span>
                            </div>
                        </div>
                        <div class="utilization-legend">
                            <div class="legend-item">
                                <span class="legend-color available"></span>
                                <span id="legend-available">0 Verfügbar</span>
                            </div>
                            <div class="legend-item">
                                <span class="legend-color rented"></span>
                                <span id="legend-rented">0 Vermietet</span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Wartungen diese Woche -->
                <div class="dashboard-section">
                    <h4>⚠️ Wartungen diese Woche</h4>
                    <div id="maintenance-this-week" class="maintenance-list">
                        <div class="loading-placeholder">Lade Wartungen...</div>
                    </div>
                </div>
                
                <!-- Trend letzte 30 Tage -->
                <div class="dashboard-section">
                    <h4>📊 Status-Übersicht</h4>
                    <div class="trend-bars">
                        <div class="trend-bar-item">
                            <span class="trend-label">Verfügbar</span>
                            <div class="trend-bar">
                                <div class="trend-fill available" id="trend-available" style="width: 0%"></div>
                            </div>
                            <span class="trend-value" id="trend-available-val">0</span>
                        </div>
                        <div class="trend-bar-item">
                            <span class="trend-label">Vermietet</span>
                            <div class="trend-bar">
                                <div class="trend-fill rented" id="trend-rented" style="width: 0%"></div>
                            </div>
                            <span class="trend-value" id="trend-rented-val">0</span>
                        </div>
                        <div class="trend-bar-item">
                            <span class="trend-label">Wartung</span>
                            <div class="trend-bar">
                                <div class="trend-fill maintenance" id="trend-maintenance" style="width: 0%"></div>
                            </div>
                            <span class="trend-value" id="trend-maintenance-val">0</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </main>

    <!-- Login Modal -->
    <div id="login-modal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>🔐 Anmelden</h2>
                <button class="modal-close-btn close-modal" aria-label="Schließen">×</button>
            </div>
            <form id="login-form" autocomplete="on">
                <div class="form-group">
                    <label for="username">Benutzername:</label>
                    <input type="text" id="username" name="username" required autocomplete="username" autofocus>
                </div>
                <div class="form-group">
                    <label for="password">Passwort:</label>
                    <input type="password" id="password" name="password" required autocomplete="current-password">
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Anmelden</button>
                    <button type="button" class="btn btn-secondary close-modal">Abbrechen</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Add Object Modal MIT WARTUNGSFELDERN -->
    <?php if ($permissions['canCreateMarker']): ?>
    <div id="add-object-modal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>➕ Neuen Marker hinzufügen</h2>
                <button class="modal-close-btn close-modal" aria-label="Schließen">×</button>
            </div>
            <form id="add-object-form" enctype="multipart/form-data">
                <div class="form-group">
                    <label for="obj-title">Titel: *</label>
                    <input type="text" id="obj-title" name="title" required maxlength="255">
                </div>
                
                <div class="form-group">
                    <label for="obj-category">Kategorie:</label>
                    <select id="obj-category" name="category">
                        <?php 
                        $activeCategories = CategoryHelper::getActiveCategories();
                        foreach ($activeCategories as $category): 
                        ?>
                            <option value="<?= htmlspecialchars($category['name']) ?>">
                                <?= $category['icon'] ?> <?= htmlspecialchars($category['display_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                        <input type="checkbox" id="obj-is-storage" name="is_storage_device" 
                            onchange="toggleStorageDevice('add')"
                            style="width: 18px; height: 18px; cursor: pointer;">
                        <span style="font-weight: 600;">📦 Lagergerät</span>
                    </label>
                    <small>Lagergeräte benötigen keine Wartung und erhalten eine eigene Farbe</small>
                </div>

                <div class="form-group">
                    <label for="obj-description">Beschreibung:</label>
                    <textarea id="obj-description" name="description" rows="3" maxlength="1000"></textarea>
                </div>
                
                <div class="form-group">
                    <label for="obj-image">Bild (optional):</label>
                    <input type="file" id="obj-image" name="image" accept="image/*">
                    <small>Empfohlene Größe: 128x128px</small>
                </div>
                
                <div class="maintenance-section">
                    <h3 class="maintenance-section-title">🔧 Wartungsinformationen (optional)</h3>
                    
                    <div class="form-group">
                        <label for="obj-last-maintenance">Letzte Wartung:</label>
                        <input type="date" id="obj-last-maintenance" name="last_maintenance">
                        <small>Datum der letzten durchgeführten Wartung</small>
                    </div>
                    
                    <div class="form-group">
                        <label for="obj-maintenance-interval">Wartungsintervall:</label>
                        <select id="obj-maintenance-interval" name="maintenance_interval_select" onchange="handleMaintenanceIntervalChange(this, 'add')">
                            <?php foreach ($maintenanceIntervals as $value => $label): ?>
                                <option value="<?= htmlspecialchars($value) ?>"><?= htmlspecialchars($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group" id="custom-interval-add" style="display: none;">
                        <label for="obj-maintenance-interval-custom">Tage bis zur nächsten Wartung:</label>
                        <input type="number" id="obj-maintenance-interval-custom" name="maintenance_interval" 
                               min="1" max="3650" placeholder="z.B. 180">
                        <small>Geben Sie die Anzahl der Tage ein (1-3650)</small>
                    </div>
                    
                    <div class="maintenance-info-box" id="next-maintenance-info-add" style="display: none;">
                        <strong>📅 Nächste Wartung fällig:</strong>
                        <span id="next-maintenance-date-add">-</span>
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Hinzufügen</button>
                    <button type="button" class="btn btn-secondary close-modal">Abbrechen</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- Edit Object Modal MIT WARTUNGSFELDERN -->
    <?php if ($permissions['canEditMarker']): ?>
    <div id="edit-object-modal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>✏️ Marker bearbeiten</h2>
                <button class="modal-close-btn close-modal" aria-label="Schließen">×</button>
            </div>
            <form id="edit-object-form" enctype="multipart/form-data">
                <input type="hidden" id="edit-object-id" name="id">
                
                <div class="form-group">
                    <label for="edit-obj-title">Titel: *</label>
                    <input type="text" id="edit-obj-title" name="title" required maxlength="255">
                </div>
                
                <div class="form-group">
                    <label for="edit-obj-category">Kategorie:</label>
                    <select id="edit-obj-category" name="category">
                        <?php 
                        $activeCategories = CategoryHelper::getActiveCategories();
                        foreach ($activeCategories as $category): 
                        ?>
                            <option value="<?= htmlspecialchars($category['name']) ?>">
                                <?= $category['icon'] ?> <?= htmlspecialchars($category['display_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                        <input type="checkbox" id="edit-obj-is-storage" name="is_storage_device" 
                            onchange="toggleStorageDevice('edit')"
                            style="width: 18px; height: 18px; cursor: pointer;">
                        <span style="font-weight: 600;">📦 Lagergerät</span>
                    </label>
                    <small>Lagergeräte benötigen keine Wartung und erhalten eine eigene Farbe</small>
                </div>

                <div class="form-group">
                    <label for="edit-obj-description">Beschreibung:</label>
                    <textarea id="edit-obj-description" name="description" rows="3" maxlength="1000"></textarea>
                </div>
                
                <div class="form-group">
                    <label for="edit-obj-image">Neues Bild (optional):</label>
                    <input type="file" id="edit-obj-image" name="image" accept="image/*">
                    <div id="current-image-preview" class="current-image-preview"></div>
                </div>
                
                <div class="maintenance-section">
                    <h3 class="maintenance-section-title">🔧 Wartungsinformationen</h3>
                    
                    <div class="form-group">
                        <label for="edit-obj-last-maintenance">Letzte Wartung:</label>
                        <input type="date" id="edit-obj-last-maintenance" name="last_maintenance">
                        <small>Datum der letzten durchgeführten Wartung</small>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit-obj-maintenance-interval">Wartungsintervall:</label>
                        <select id="edit-obj-maintenance-interval" name="maintenance_interval_select" onchange="handleMaintenanceIntervalChange(this, 'edit')">
                            <?php foreach ($maintenanceIntervals as $value => $label): ?>
                                <option value="<?= htmlspecialchars($value) ?>"><?= htmlspecialchars($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group" id="custom-interval-edit" style="display: none;">
                        <label for="edit-obj-maintenance-interval-custom">Tage bis zur nächsten Wartung:</label>
                        <input type="number" id="edit-obj-maintenance-interval-custom" name="maintenance_interval" 
                               min="1" max="3650" placeholder="z.B. 180">
                        <small>Geben Sie die Anzahl der Tage ein (1-3650)</small>
                    </div>
                    
                    <div class="maintenance-info-box" id="next-maintenance-info-edit" style="display: none;">
                        <strong>📅 Nächste Wartung fällig:</strong>
                        <span id="next-maintenance-date-edit">-</span>
                    </div>
                    
                    <div class="maintenance-history-section">
                        <button type="button" class="btn btn-secondary btn-sm" onclick="showMaintenanceHistory()">
                            📋 Wartungshistorie anzeigen
                        </button>
                        <div id="maintenance-history-display" style="display: none; margin-top: 1rem;"></div>
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Änderungen speichern</button>
                    <button type="button" class="btn btn-secondary close-modal">Abbrechen</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

<?php if ($permissions['canViewUsers']): ?>
<div id="users-modal" class="modal">
    <div class="modal-content large-modal">
        <div class="modal-header">
            <h2>👥 Benutzerverwaltung</h2>
            <button class="modal-close-btn close-modal" aria-label="Schließen">×</button>
        </div>
        
        <div class="modal-body">
            <?php if ($permissions['canCreateUsers']): ?>
            <section class="section">
                <h3>Neuen Benutzer hinzufügen</h3>
                <form id="add-user-form" class="user-form">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="new-username">Benutzername: *</label>
                            <input type="text" id="new-username" name="username" required minlength="3" maxlength="50">
                        </div>
                        <div class="form-group">
                            <label for="new-password">Passwort: *</label>
                            <input type="password" id="new-password" name="password" required minlength="6">
                        </div>
                        <div class="form-group">
                            <label for="new-role">Rolle: *</label>
                            <select id="new-role" name="role_id" required>
                                <option value="">-- Rolle wählen --</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="new-email">E-Mail (optional):</label>
                            <input type="email" id="new-email" name="email" maxlength="255" placeholder="user@example.com">
                            <small>Wird für Wartungsbenachrichtigungen verwendet</small>
                        </div>
                        <div class="form-group">
                            <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; user-select: none; padding: 10px 0;">
                                <input type="checkbox" id="new-receive-notifications" name="receive_notifications" 
                                       style="width: 18px; height: 18px; cursor: pointer;">
                                <span>Wartungsbenachrichtigungen per E-Mail erhalten</span>
                            </label>
                            <small>Benutzer erhält automatisch E-Mails wenn Wartungen fällig sind</small>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary">Benutzer erstellen</button>
                </form>
            </section>
            <?php endif; ?>
            
            <section class="section">
                <h3>Bestehende Benutzer</h3>
                <div id="users-list" class="users-list-table"></div>
            </section>
        </div>
    </div>
</div>
<?php endif; ?>

    <!-- Notification Container -->
    <div id="notification-container" class="notification-container"></div>

    <!-- Loading Overlay -->
    <div id="loading-overlay" class="loading-overlay" style="display: none;">
        <div class="spinner"></div>
        <p id="loading-text">Laden...</p>
    </div>

    <!-- JavaScript Configuration -->
    <script>
        window.systemConfig = {
            isLoggedIn: <?= Auth::isLoggedIn() ? 'true' : 'false' ?>,
            userId: <?= Auth::isLoggedIn() ? Auth::getUserId() : 'null' ?>,
            username: <?= Auth::isLoggedIn() ? json_encode(Auth::getUsername()) : 'null' ?>,
            roleName: <?= Auth::isLoggedIn() ? json_encode(Auth::getRoleName()) : 'null' ?>,
            roleDisplayName: <?= Auth::isLoggedIn() ? json_encode(Auth::getRoleDisplayName()) : 'null' ?>,
            hasBackground: <?= $currentBackground ? 'true' : 'false' ?>,
            containerWidth: <?= intval(DEFAULT_CONTAINER_WIDTH) ?>,
            containerHeight: <?= intval(DEFAULT_CONTAINER_HEIGHT) ?>,
            categories: <?= json_encode(CategoryHelper::getActiveCategories()) ?>,
            categoriesMap: <?= json_encode(array_column(CategoryHelper::getActiveCategories(), null, 'name')) ?>,
            systemName: <?= json_encode(SYSTEM_NAME) ?>,
            permissions: <?= json_encode($permissions) ?>,
            maintenanceNotifications: <?= json_encode($maintenanceNotifications) ?>,
            storageDeviceColor: <?= json_encode($storageDeviceColor) ?>
        };
        
        window.csrfToken = '<?= generateCSRF() ?>';
        
        // Wartungsbenachrichtigungen beim Laden anzeigen
        if (window.systemConfig.maintenanceNotifications && window.systemConfig.maintenanceNotifications.length > 0) {
            setTimeout(() => {
                window.systemConfig.maintenanceNotifications.forEach(notification => {
                    let message = '';
                    let icon = '';
                    
                    switch(notification.type) {
                        case 'maintenance_set':
                            icon = '🔧';
                            message = `${notification.title}: ${notification.message}`;
                            break;
                        case 'waiting':
                            icon = '⏳';
                            message = `${notification.title}: ${notification.message}`;
                            break;
                        case 'maintenance_after_rental':
                            icon = '🔧';
                            message = `${notification.title}: ${notification.message}`;
                            break;
                    }
                    
                    if (typeof showNotification === 'function') {
                        showNotification(icon + ' ' + message, 'info', 8000);
                    }
                });
            }, 1000);
        }
    </script>

<script src="nav-enhancements.js"></script>
<script src="script.js"></script>
</body>
</html>