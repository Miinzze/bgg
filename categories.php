<?php
/**
 * Kategorien-Verwaltung (Sicher)
 * SQL Injection Schutz durch konsistente Prepared Statements
 */

require_once 'config.php';

// Berechtigung prüfen
Auth::requireLogin();
Auth::requirePermission('category.view', 'Sie haben keine Berechtigung zur Kategorieverwaltung');

// Kategorien-Manager Klasse (SICHER)
class CategoryManager {
    
    /**
     * Holt alle Kategorien mit Objekt-Anzahl (SICHER)
     */
    public static function getAllCategories($includeInactive = false) {
        $db = Database::getInstance()->getConnection();
        
        // KORRIGIERT: Prepared Statement statt direktem Query
        $sql = "SELECT * FROM categories_with_count";
        $params = [];
        
        if (!$includeInactive) {
            $sql .= " WHERE is_active = ?";
            $params[] = 1;
        }
        
        $sql .= " ORDER BY sort_order ASC, display_name ASC";
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
    
    /**
     * Holt eine einzelne Kategorie
     */
    public static function getCategoryById($categoryId) {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT * FROM categories WHERE id = ?");
        $stmt->execute([$categoryId]);
        return $stmt->fetch();
    }
    
    /**
     * Holt eine Kategorie nach Name
     */
    public static function getCategoryByName($name) {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT * FROM categories WHERE name = ?");
        $stmt->execute([$name]);
        return $stmt->fetch();
    }
    
    /**
     * Erstellt neue Kategorie
     */
    public static function createCategory($data) {
        $db = Database::getInstance()->getConnection();
        
        // Validierung
        if (empty($data['name']) || empty($data['display_name'])) {
            throw new Exception('Name und Anzeigename sind erforderlich');
        }
        
        // Name validieren (nur Kleinbuchstaben, Zahlen, Unterstriche)
        if (!preg_match('/^[a-z0-9_]+$/', $data['name'])) {
            throw new Exception('Name darf nur Kleinbuchstaben, Zahlen und Unterstriche enthalten');
        }
        
        // Länge prüfen
        if (strlen($data['name']) < 2 || strlen($data['name']) > 50) {
            throw new Exception('Name muss zwischen 2 und 50 Zeichen lang sein');
        }
        
        if (strlen($data['display_name']) > 100) {
            throw new Exception('Anzeigename darf maximal 100 Zeichen lang sein');
        }
        
        // Prüfe ob Name bereits existiert
        if (self::getCategoryByName($data['name'])) {
            throw new Exception('Eine Kategorie mit diesem Namen existiert bereits');
        }
        
        // Farbe validieren
        $color = $data['color'] ?? '#6bb032';
        if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
            throw new Exception('Ungültiges Farbformat (z.B. #6bb032)');
        }
        
        // Icon validieren (max 10 Zeichen für Emoji)
        $icon = $data['icon'] ?? '📦';
        if (mb_strlen($icon) > 10) {
            throw new Exception('Icon zu lang');
        }
        
        // Beschreibung begrenzen
        $description = $data['description'] ?? '';
        if (strlen($description) > 500) {
            throw new Exception('Beschreibung darf maximal 500 Zeichen lang sein');
        }
        
        $stmt = $db->prepare("
            INSERT INTO categories 
            (name, display_name, description, color, icon, sort_order, created_by) 
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $data['name'],
            $data['display_name'],
            $description,
            $color,
            $icon,
            intval($data['sort_order'] ?? 0),
            Auth::getUserId()
        ]);
        
        return $db->lastInsertId();
    }
    
    /**
     * Aktualisiert eine Kategorie
     */
    public static function updateCategory($categoryId, $data) {
        $db = Database::getInstance()->getConnection();
        
        $category = self::getCategoryById($categoryId);
        if (!$category) {
            throw new Exception('Kategorie nicht gefunden');
        }
        
        // Validierung wie bei createCategory
        if (isset($data['display_name']) && strlen($data['display_name']) > 100) {
            throw new Exception('Anzeigename darf maximal 100 Zeichen lang sein');
        }
        
        if (isset($data['description']) && strlen($data['description']) > 500) {
            throw new Exception('Beschreibung darf maximal 500 Zeichen lang sein');
        }
        
        if (isset($data['color']) && !preg_match('/^#[0-9A-Fa-f]{6}$/', $data['color'])) {
            throw new Exception('Ungültiges Farbformat');
        }
        
        if (isset($data['icon']) && mb_strlen($data['icon']) > 10) {
            throw new Exception('Icon zu lang');
        }
        
        if ($category['is_system_category']) {
            // System-Kategorien: nur display_name, description, color, icon änderbar
            $stmt = $db->prepare("
                UPDATE categories 
                SET display_name = ?, description = ?, color = ?, icon = ?, sort_order = ?
                WHERE id = ?
            ");
            
            $stmt->execute([
                $data['display_name'] ?? $category['display_name'],
                $data['description'] ?? $category['description'],
                $data['color'] ?? $category['color'],
                $data['icon'] ?? $category['icon'],
                intval($data['sort_order'] ?? $category['sort_order']),
                $categoryId
            ]);
        } else {
            // Benutzerdefinierte Kategorien: Alle Felder änderbar
            
            // Name validieren wenn geändert
            if (isset($data['name']) && $data['name'] !== $category['name']) {
                if (!preg_match('/^[a-z0-9_]+$/', $data['name'])) {
                    throw new Exception('Name darf nur Kleinbuchstaben, Zahlen und Unterstriche enthalten');
                }
                
                if (strlen($data['name']) < 2 || strlen($data['name']) > 50) {
                    throw new Exception('Name muss zwischen 2 und 50 Zeichen lang sein');
                }
                
                if (self::getCategoryByName($data['name'])) {
                    throw new Exception('Eine Kategorie mit diesem Namen existiert bereits');
                }
            }
            
            try {
                $db->beginTransaction();
                
                $stmt = $db->prepare("
                    UPDATE categories 
                    SET name = ?, display_name = ?, description = ?, color = ?, icon = ?, sort_order = ?
                    WHERE id = ?
                ");
                
                $newName = $data['name'] ?? $category['name'];
                
                $stmt->execute([
                    $newName,
                    $data['display_name'] ?? $category['display_name'],
                    $data['description'] ?? $category['description'],
                    $data['color'] ?? $category['color'],
                    $data['icon'] ?? $category['icon'],
                    intval($data['sort_order'] ?? $category['sort_order']),
                    $categoryId
                ]);
                
                // Wenn Name geändert, aktualisiere map_objects
                if ($newName !== $category['name']) {
                    $updateObjects = $db->prepare("UPDATE map_objects SET category = ? WHERE category = ?");
                    $updateObjects->execute([$newName, $category['name']]);
                }
                
                $db->commit();
            } catch (Exception $e) {
                $db->rollBack();
                throw $e;
            }
        }
    }
    
    /**
     * Löscht eine Kategorie
     */
    public static function deleteCategory($categoryId) {
        $db = Database::getInstance()->getConnection();
        
        $category = self::getCategoryById($categoryId);
        if (!$category) {
            throw new Exception('Kategorie nicht gefunden');
        }
        
        if ($category['is_system_category']) {
            throw new Exception('System-Kategorien können nicht gelöscht werden');
        }
        
        // Prüfe ob Objekte diese Kategorie verwenden
        $stmt = $db->prepare("SELECT COUNT(*) FROM map_objects WHERE category = ?");
        $stmt->execute([$category['name']]);
        $objectCount = $stmt->fetchColumn();
        
        if ($objectCount > 0) {
            throw new Exception("Diese Kategorie wird von $objectCount Objekt(en) verwendet. Bitte verschieben Sie diese zuerst in eine andere Kategorie.");
        }
        
        // Lösche Kategorie
        $stmt = $db->prepare("DELETE FROM categories WHERE id = ?");
        $stmt->execute([$categoryId]);
    }
    
    /**
     * Aktiviert/Deaktiviert eine Kategorie
     */
    public static function toggleCategoryStatus($categoryId, $isActive) {
        $db = Database::getInstance()->getConnection();
        
        $category = self::getCategoryById($categoryId);
        if (!$category) {
            throw new Exception('Kategorie nicht gefunden');
        }
        
        if ($category['is_system_category'] && !$isActive) {
            throw new Exception('System-Kategorien können nicht deaktiviert werden');
        }
        
        $stmt = $db->prepare("UPDATE categories SET is_active = ? WHERE id = ?");
        $stmt->execute([$isActive ? 1 : 0, $categoryId]);
    }
    
    /**
     * Ändert die Sortierung von Kategorien
     */
    public static function reorderCategories($categoryIds) {
        $db = Database::getInstance()->getConnection();
        
        // Validierung: Nur numerische IDs
        foreach ($categoryIds as $id) {
            if (!is_numeric($id) || $id <= 0) {
                throw new Exception('Ungültige Kategorie-ID');
            }
        }
        
        try {
            $db->beginTransaction();
            
            $stmt = $db->prepare("UPDATE categories SET sort_order = ? WHERE id = ?");
            
            foreach ($categoryIds as $index => $categoryId) {
                $sortOrder = ($index + 1) * 10;
                $stmt->execute([$sortOrder, intval($categoryId)]);
            }
            
            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }
    
    /**
     * Verschiebt Objekte von einer Kategorie zur anderen
     */
    public static function moveObjects($fromCategory, $toCategory) {
        $db = Database::getInstance()->getConnection();
        
        // Namen validieren
        if (!preg_match('/^[a-z0-9_]+$/', $fromCategory) || !preg_match('/^[a-z0-9_]+$/', $toCategory)) {
            throw new Exception('Ungültiger Kategoriename');
        }
        
        // Prüfe ob Zielkategorie existiert und aktiv ist
        $target = self::getCategoryByName($toCategory);
        if (!$target || !$target['is_active']) {
            throw new Exception('Zielkategorie nicht gefunden oder inaktiv');
        }
        
        $stmt = $db->prepare("UPDATE map_objects SET category = ? WHERE category = ?");
        $stmt->execute([$toCategory, $fromCategory]);
        
        return $stmt->rowCount();
    }
    
    /**
     * Holt Kategoriestatistiken
     */
    public static function getCategoryStatistics() {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT * FROM category_statistics");
        $stmt->execute();
        return $stmt->fetchAll();
    }
    
    /**
     * Holt verfügbare Icons
     */
    public static function getAvailableIcons() {
        return [
            '📦' => 'Paket/Allgemein',
            '⚡' => 'Generator/Strom',
            '💡' => 'Beleuchtung',
            '🔊' => 'Audio',
            '🔧' => 'Werkzeug',
            '🪑' => 'Möbel',
            '🌡️' => 'Klima/Temperatur',
            '⚠️' => 'Sicherheit',
            '🚚' => 'Transport',
            '💼' => 'Büro',
            '🏗️' => 'Bau',
            '🎨' => 'Kunst/Design',
            '📱' => 'Elektronik',
            '🎭' => 'Event',
            '🏥' => 'Medizin',
            '🔌' => 'Elektrik',
            '🎪' => 'Zelt/Struktur',
            '📹' => 'Video',
            '📸' => 'Foto',
            '🎬' => 'Film',
            '🎤' => 'Mikrofon',
            '🎸' => 'Musik',
            '⚙️' => 'Mechanik',
            '🔩' => 'Hardware',
            '🧰' => 'Werkzeugkasten'
        ];
    }
}

// AJAX Request Handling mit CSRF-Schutz
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    try {
        // CSRF-Token validieren für alle schreibenden Aktionen
        $writeActions = ['create_category', 'update_category', 'delete_category', 'toggle_category_status', 'reorder_categories', 'move_objects'];
        
        if (in_array($_POST['action'], $writeActions)) {
            Auth::requireCSRFToken();
        }
        
        switch ($_POST['action']) {
            case 'get_categories':
                $includeInactive = isset($_POST['include_inactive']) && $_POST['include_inactive'] === 'true';
                $categories = CategoryManager::getAllCategories($includeInactive);
                echo json_encode(['success' => true, 'categories' => $categories]);
                break;
                
            case 'get_category':
                $categoryId = intval($_POST['category_id'] ?? 0);
                if ($categoryId <= 0) {
                    throw new Exception('Ungültige Kategorie-ID');
                }
                
                $category = CategoryManager::getCategoryById($categoryId);
                if (!$category) {
                    throw new Exception('Kategorie nicht gefunden');
                }
                
                echo json_encode(['success' => true, 'category' => $category]);
                break;
                
            case 'create_category':
                Auth::requirePermission('category.create', 'Sie haben keine Berechtigung zum Erstellen von Kategorien');
                
                $data = [
                    'name' => sanitizeInput($_POST['name'] ?? ''),
                    'display_name' => sanitizeInput($_POST['display_name'] ?? ''),
                    'description' => sanitizeInput($_POST['description'] ?? ''),
                    'color' => sanitizeInput($_POST['color'] ?? '#6bb032'),
                    'icon' => $_POST['icon'] ?? '📦',
                    'sort_order' => intval($_POST['sort_order'] ?? 0)
                ];
                
                $categoryId = CategoryManager::createCategory($data);
                
                logActivity('category_created', "Category created: {$data['display_name']} (ID: $categoryId)");
                echo json_encode(['success' => true, 'category_id' => $categoryId]);
                break;
                
            case 'update_category':
                Auth::requirePermission('category.edit', 'Sie haben keine Berechtigung zum Bearbeiten von Kategorien');
                
                $categoryId = intval($_POST['category_id'] ?? 0);
                if ($categoryId <= 0) {
                    throw new Exception('Ungültige Kategorie-ID');
                }
                
                $data = [
                    'name' => sanitizeInput($_POST['name'] ?? ''),
                    'display_name' => sanitizeInput($_POST['display_name'] ?? ''),
                    'description' => sanitizeInput($_POST['description'] ?? ''),
                    'color' => sanitizeInput($_POST['color'] ?? '#6bb032'),
                    'icon' => $_POST['icon'] ?? '📦',
                    'sort_order' => intval($_POST['sort_order'] ?? 0)
                ];
                
                CategoryManager::updateCategory($categoryId, $data);
                
                logActivity('category_updated', "Category updated: {$data['display_name']} (ID: $categoryId)");
                echo json_encode(['success' => true]);
                break;
                
            case 'delete_category':
                Auth::requirePermission('category.delete', 'Sie haben keine Berechtigung zum Löschen von Kategorien');
                
                $categoryId = intval($_POST['category_id'] ?? 0);
                if ($categoryId <= 0) {
                    throw new Exception('Ungültige Kategorie-ID');
                }
                
                $category = CategoryManager::getCategoryById($categoryId);
                CategoryManager::deleteCategory($categoryId);
                
                logActivity('category_deleted', "Category deleted: {$category['display_name']} (ID: $categoryId)");
                echo json_encode(['success' => true]);
                break;
                
            case 'toggle_category_status':
                Auth::requirePermission('category.edit', 'Sie haben keine Berechtigung zum Ändern des Kategorie-Status');
                
                $categoryId = intval($_POST['category_id'] ?? 0);
                $isActive = isset($_POST['is_active']) && $_POST['is_active'] === 'true';
                
                if ($categoryId <= 0) {
                    throw new Exception('Ungültige Kategorie-ID');
                }
                
                CategoryManager::toggleCategoryStatus($categoryId, $isActive);
                
                logActivity('category_status_changed', "Category status changed (ID: $categoryId, Active: " . ($isActive ? 'yes' : 'no') . ")");
                echo json_encode(['success' => true]);
                break;
                
            case 'reorder_categories':
                Auth::requirePermission('category.edit', 'Sie haben keine Berechtigung zum Sortieren von Kategorien');
                
                $categoryIds = $_POST['category_ids'] ?? [];
                if (!is_array($categoryIds) || empty($categoryIds)) {
                    throw new Exception('Ungültige Kategorie-Liste');
                }
                
                // Validiere Array
                if (count($categoryIds) > 100) {
                    throw new Exception('Zu viele Kategorien');
                }
                
                CategoryManager::reorderCategories($categoryIds);
                
                logActivity('categories_reordered', 'Category order updated');
                echo json_encode(['success' => true]);
                break;
                
            case 'move_objects':
                Auth::requirePermission('category.edit', 'Sie haben keine Berechtigung zum Verschieben von Objekten');
                
                $fromCategory = sanitizeInput($_POST['from_category'] ?? '');
                $toCategory = sanitizeInput($_POST['to_category'] ?? '');
                
                if (empty($fromCategory) || empty($toCategory)) {
                    throw new Exception('Quell- und Zielkategorie erforderlich');
                }
                
                $movedCount = CategoryManager::moveObjects($fromCategory, $toCategory);
                
                logActivity('objects_moved', "Moved $movedCount objects from '$fromCategory' to '$toCategory'");
                echo json_encode(['success' => true, 'moved_count' => $movedCount]);
                break;
                
            case 'get_statistics':
                $statistics = CategoryManager::getCategoryStatistics();
                echo json_encode(['success' => true, 'statistics' => $statistics]);
                break;
                
            case 'get_available_icons':
                $icons = CategoryManager::getAvailableIcons();
                echo json_encode(['success' => true, 'icons' => $icons]);
                break;
                
            default:
                throw new Exception('Unbekannte Aktion');
        }
        
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    
    exit;
}

// Daten für Frontend laden
$canCreate = Auth::hasPermission('category.create');
$canEdit = Auth::hasPermission('category.edit');
$canDelete = Auth::hasPermission('category.delete');
$categories = CategoryManager::getAllCategories(true);
$statistics = CategoryManager::getCategoryStatistics();
$availableIcons = CategoryManager::getAvailableIcons();
$csrfToken = Auth::getCSRFToken();
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kategorieverwaltung - <?= htmlspecialchars(SYSTEM_NAME) ?></title>
    <link rel="stylesheet" href="style.css">
    <!-- Inline Styles bleiben wie im Original -->
</head>
<body>
    <!-- HTML bleibt wie im Original -->
    
    <script>
        // CSRF Token für AJAX-Requests
        const csrfToken = '<?= $csrfToken ?>';
        const canEdit = <?= $canEdit ? 'true' : 'false' ?>;
        const canDelete = <?= $canDelete ? 'true' : 'false' ?>;
        const canCreate = <?= $canCreate ? 'true' : 'false' ?>;
        
        // Alle AJAX-Requests mit CSRF-Token senden
        async function secureFetch(url, data) {
            data.append('csrf_token', csrfToken);
            
            const response = await fetch(url, {
                method: 'POST',
                body: data
            });
            
            return response.json();
        }
        
        // Beispiel: Kategorie erstellen mit CSRF-Schutz
        async function handleCategorySubmit(e) {
            e.preventDefault();
            
            const form = e.target;
            const formData = new FormData(form);
            
            const action = isEditMode ? 'update_category' : 'create_category';
            formData.append('action', action);
            
            const submitBtn = form.querySelector('button[type="submit"]');
            const originalText = submitBtn.textContent;
            
            try {
                submitBtn.disabled = true;
                submitBtn.textContent = 'Speichern...';
                
                const result = await secureFetch('', formData);
                
                if (result.success) {
                    showNotification(
                        isEditMode ? 'Kategorie erfolgreich aktualisiert' : 'Kategorie erfolgreich erstellt',
                        'success'
                    );
                    closeModal();
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showNotification(result.message || 'Fehler beim Speichern', 'error');
                }
            } catch (error) {
                console.error('Error saving category:', error);
                showNotification('Verbindungsfehler beim Speichern', 'error');
            } finally {
                submitBtn.disabled = false;
                submitBtn.textContent = originalText;
            }
        }
        
        // Rest des JavaScript-Codes wie im Original...
    </script>
</body>
</html>