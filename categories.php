<?php
/**
 * Kategorien-Verwaltung
 * Verwaltung von Marker-Kategorien mit Farben, Icons und Beschreibungen
 */

require_once 'config.php';

// Berechtigung prüfen
Auth::requireLogin();
Auth::requirePermission('category.view', 'Sie haben keine Berechtigung zur Kategorieverwaltung');

// Kategorien-Manager Klasse
class CategoryManager {
    
    /**
     * Holt alle Kategorien mit Objekt-Anzahl
     */
    public static function getAllCategories($includeInactive = false) {
        $db = Database::getInstance()->getConnection();
        
        $sql = "SELECT * FROM categories_with_count";
        if (!$includeInactive) {
            $sql .= " WHERE is_active = 1";
        }
        $sql .= " ORDER BY sort_order ASC, display_name ASC";
        
        $stmt = $db->query($sql);
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
        
        // Prüfe ob Name bereits existiert
        if (self::getCategoryByName($data['name'])) {
            throw new Exception('Eine Kategorie mit diesem Namen existiert bereits');
        }
        
        // Farbe validieren
        $color = $data['color'] ?? '#6bb032';
        if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
            throw new Exception('Ungültiges Farbformat (z.B. #6bb032)');
        }
        
        $stmt = $db->prepare("
            INSERT INTO categories 
            (name, display_name, description, color, icon, sort_order, created_by) 
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $data['name'],
            $data['display_name'],
            $data['description'] ?? '',
            $color,
            $data['icon'] ?? '📦',
            $data['sort_order'] ?? 0,
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
                $data['sort_order'] ?? $category['sort_order'],
                $categoryId
            ]);
        } else {
            // Benutzerdefinierte Kategorien: Alle Felder änderbar
            
            // Name validieren wenn geändert
            if (isset($data['name']) && $data['name'] !== $category['name']) {
                if (!preg_match('/^[a-z0-9_]+$/', $data['name'])) {
                    throw new Exception('Name darf nur Kleinbuchstaben, Zahlen und Unterstriche enthalten');
                }
                
                if (self::getCategoryByName($data['name'])) {
                    throw new Exception('Eine Kategorie mit diesem Namen existiert bereits');
                }
            }
            
            $stmt = $db->prepare("
                UPDATE categories 
                SET name = ?, display_name = ?, description = ?, color = ?, icon = ?, sort_order = ?
                WHERE id = ?
            ");
            
            $stmt->execute([
                $data['name'] ?? $category['name'],
                $data['display_name'] ?? $category['display_name'],
                $data['description'] ?? $category['description'],
                $data['color'] ?? $category['color'],
                $data['icon'] ?? $category['icon'],
                $data['sort_order'] ?? $category['sort_order'],
                $categoryId
            ]);
            
            // Wenn Name geändert, aktualisiere map_objects
            if (isset($data['name']) && $data['name'] !== $category['name']) {
                $updateObjects = $db->prepare("UPDATE map_objects SET category = ? WHERE category = ?");
                $updateObjects->execute([$data['name'], $category['name']]);
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
        
        try {
            $db->beginTransaction();
            
            $stmt = $db->prepare("UPDATE categories SET sort_order = ? WHERE id = ?");
            
            foreach ($categoryIds as $index => $categoryId) {
                $sortOrder = ($index + 1) * 10;
                $stmt->execute([$sortOrder, $categoryId]);
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
        $stmt = $db->query("SELECT * FROM category_statistics");
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

// AJAX Request Handling
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    try {
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
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kategorieverwaltung - <?= htmlspecialchars(SYSTEM_NAME) ?></title>
    <link rel="stylesheet" href="style.css">
    <style>
        .categories-container {
            max-width: 1400px;
            margin: calc(var(--grid-unit) * 4) auto;
            padding: 0 var(--spacing-md);
        }
        
        .categories-header {
            background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
            color: white;
            padding: calc(var(--grid-unit) * 4);
            border-radius: var(--border-radius-large);
            margin-bottom: calc(var(--grid-unit) * 3);
            box-shadow: var(--shadow-heavy);
            border: 3px solid var(--secondary-color);
        }
        
        .categories-header h1 {
            font-size: clamp(20px, 4vw, 28px);
            margin-bottom: calc(var(--grid-unit) * 1);
            font-weight: 700;
        }
        
        .categories-actions {
            display: flex;
            gap: var(--spacing-md);
            margin-bottom: calc(var(--grid-unit) * 3);
            flex-wrap: wrap;
        }
        
        .stats-overview {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: var(--spacing-md);
            margin-bottom: calc(var(--grid-unit) * 3);
        }
        
        .stat-card {
            background: white;
            padding: var(--spacing-lg);
            border-radius: var(--border-radius-large);
            border: 2px solid var(--border-color);
            box-shadow: var(--shadow-light);
            transition: all var(--transition-fast);
        }
        
        .stat-card:hover {
            border-color: var(--secondary-color);
            box-shadow: var(--shadow-green);
            transform: translateY(-2px);
        }
        
        .stat-value {
            font-size: clamp(28px, 5vw, 36px);
            font-weight: 700;
            color: var(--secondary-color);
            margin-bottom: var(--spacing-xs);
        }
        
        .stat-label {
            font-size: var(--font-xs);
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
        }
        
        .categories-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(clamp(280px, 40vw, 350px), 1fr));
            gap: var(--spacing-lg);
        }
        
        .category-card {
            background: white;
            border: 2px solid var(--border-color);
            border-radius: var(--border-radius-large);
            padding: var(--spacing-lg);
            transition: all var(--transition-fast);
            box-shadow: var(--shadow-light);
            position: relative;
            overflow: hidden;
        }
        
        .category-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: var(--category-color, var(--secondary-color));
        }
        
        .category-card:hover {
            border-color: var(--secondary-color);
            box-shadow: var(--shadow-green);
            transform: translateY(-2px);
        }
        
        .category-card.inactive {
            opacity: 0.6;
            border-style: dashed;
        }
        
        .category-card.system {
            border-color: var(--warning-color);
            background: linear-gradient(135deg, #fff8e1 0%, white 100%);
        }
        
        .category-header {
            display: flex;
            gap: var(--spacing-md);
            margin-bottom: var(--spacing-md);
            align-items: flex-start;
        }
        
        .category-icon {
            font-size: clamp(32px, 6vw, 48px);
            flex-shrink: 0;
        }
        
        .category-info {
            flex: 1;
            min-width: 0;
        }
        
        .category-name {
            font-size: var(--font-lg);
            font-weight: 700;
            color: var(--primary-color);
            margin: 0 0 var(--spacing-xs) 0;
            word-break: break-word;
        }
        
        .category-key {
            font-size: var(--font-xs);
            font-family: monospace;
            color: var(--text-muted);
            background: var(--light-gray);
            padding: 2px 8px;
            border-radius: 4px;
            display: inline-block;
        }
        
        .category-description {
            color: var(--text-muted);
            font-size: var(--font-sm);
            line-height: 1.5;
            margin: var(--spacing-sm) 0;
            min-height: 3em;
        }
        
        .category-stats {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: var(--spacing-xs);
            padding: var(--spacing-sm);
            background: var(--light-gray);
            border-radius: var(--border-radius);
            margin: var(--spacing-sm) 0;
        }
        
        .category-stat {
            text-align: center;
        }
        
        .category-stat-value {
            font-size: var(--font-md);
            font-weight: 700;
            color: var(--primary-color);
        }
        
        .category-stat-label {
            font-size: var(--font-xs);
            color: var(--text-muted);
        }
        
        .category-badges {
            display: flex;
            gap: var(--spacing-xs);
            margin-bottom: var(--spacing-sm);
            flex-wrap: wrap;
        }
        
        .badge {
            padding: 4px 10px;
            border-radius: 12px;
            font-size: var(--font-xs);
            font-weight: 600;
            white-space: nowrap;
        }
        
        .badge.system {
            background: linear-gradient(135deg, var(--warning-color), #ffb74d);
            color: white;
        }
        
        .badge.custom {
            background: linear-gradient(135deg, var(--secondary-color), var(--accent-color));
            color: white;
        }
        
        .badge.inactive {
            background: #999;
            color: white;
        }
        
        .category-actions {
            display: flex;
            gap: var(--spacing-xs);
            flex-wrap: wrap;
        }
        
        .icon-picker {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(50px, 1fr));
            gap: var(--spacing-xs);
            max-height: 300px;
            overflow-y: auto;
            padding: var(--spacing-sm);
            background: var(--light-gray);
            border-radius: var(--border-radius);
            margin: var(--spacing-sm) 0;
        }
        
        .icon-option {
            aspect-ratio: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: clamp(24px, 4vw, 32px);
            border: 2px solid var(--border-color);
            border-radius: var(--border-radius);
            cursor: pointer;
            transition: all var(--transition-fast);
            background: white;
        }
        
        .icon-option:hover {
            border-color: var(--secondary-color);
            transform: scale(1.1);
            box-shadow: var(--shadow-light);
        }
        
        .icon-option.selected {
            border-color: var(--secondary-color);
            background: var(--light-gray);
            box-shadow: 0 0 0 3px rgba(107, 176, 50, 0.2);
        }
        
        .color-input-wrapper {
            display: flex;
            gap: var(--spacing-sm);
            align-items: center;
        }
        
        .color-preview {
            width: clamp(40px, 8vw, 60px);
            height: clamp(40px, 8vw, 60px);
            border-radius: var(--border-radius);
            border: 2px solid var(--border-color);
            box-shadow: var(--shadow-light);
        }
        
        .back-button {
            position: fixed;
            top: 100px;
            left: var(--spacing-lg);
            z-index: 1000;
        }
        
        @media (max-width: 768px) {
            .categories-grid {
                grid-template-columns: 1fr;
            }
            
            .category-stats {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .back-button {
                position: static;
                margin-bottom: var(--spacing-md);
            }
        }
    </style>
</head>
<body>
    <a href="index.php" class="btn btn-secondary back-button">
        ← Zurück zur Karte
    </a>
    
    <div class="categories-container">
        <div class="categories-header">
            <h1>📦 Kategorieverwaltung</h1>
            <p>Verwalten Sie Marker-Kategorien mit individuellen Farben und Icons</p>
        </div>
        
        <?php if (!empty($statistics)): ?>
        <div class="stats-overview">
            <div class="stat-card">
                <div class="stat-value"><?= count($categories) ?></div>
                <div class="stat-label">Kategorien gesamt</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= array_sum(array_column($statistics, 'total_objects')) ?></div>
                <div class="stat-label">Objekte gesamt</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= count(array_filter($categories, fn($c) => $c['is_system_category'])) ?></div>
                <div class="stat-label">System-Kategorien</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= count(array_filter($categories, fn($c) => !$c['is_system_category'])) ?></div>
                <div class="stat-label">Eigene Kategorien</div>
            </div>
        </div>
        <?php endif; ?>
        
        <div class="categories-actions">
            <?php if ($canCreate): ?>
                <button id="create-category-btn" class="btn btn-primary">
                    ➕ Neue Kategorie
                </button>
            <?php endif; ?>
            
            <button id="refresh-categories-btn" class="btn btn-secondary">
                🔄 Aktualisieren
            </button>
            
            <?php if ($canEdit): ?>
                <button id="reorder-mode-btn" class="btn btn-secondary">
                    ↕️ Sortierung ändern
                </button>
            <?php endif; ?>
            
            <a href="settings.php" class="btn btn-secondary">
                ⚙️ Einstellungen
            </a>
        </div>
        
        <div id="categories-grid" class="categories-grid">
            <?php if (empty($categories)): ?>
                <div class="empty-state" style="grid-column: 1 / -1;">
                    <div class="empty-state-icon">📦</div>
                    <h3>Keine Kategorien vorhanden</h3>
                    <p>Erstellen Sie Ihre erste Kategorie</p>
                </div>
            <?php else: ?>
                <?php foreach ($categories as $cat): ?>
                    <div class="category-card <?= !$cat['is_active'] ? 'inactive' : '' ?> <?= $cat['is_system_category'] ? 'system' : '' ?>" 
                         data-id="<?= $cat['id'] ?>"
                         style="--category-color: <?= htmlspecialchars($cat['color']) ?>">
                        <div class="category-header">
                            <div class="category-icon"><?= $cat['icon'] ?></div>
                            <div class="category-info">
                                <h3 class="category-name"><?= htmlspecialchars($cat['display_name']) ?></h3>
                                <span class="category-key"><?= htmlspecialchars($cat['name']) ?></span>
                            </div>
                        </div>
                        
                        <div class="category-badges">
                            <span class="badge <?= $cat['is_system_category'] ? 'system' : 'custom' ?>">
                                <?= $cat['is_system_category'] ? '🔒 System' : '✨ Benutzerdefiniert' ?>
                            </span>
                            <?php if (!$cat['is_active']): ?>
                                <span class="badge inactive">Inaktiv</span>
                            <?php endif; ?>
                        </div>
                        
                        <div class="category-description">
                            <?= $cat['description'] ? htmlspecialchars($cat['description']) : '<em>Keine Beschreibung</em>' ?>
                        </div>
                        
                        <div class="category-stats">
                            <div class="category-stat">
                                <div class="category-stat-value"><?= $cat['object_count'] ?></div>
                                <div class="category-stat-label">Gesamt</div>
                            </div>
                            <div class="category-stat">
                                <div class="category-stat-value" style="color: #4caf50;"><?= $cat['available_count'] ?></div>
                                <div class="category-stat-label">Verfügbar</div>
                            </div>
                            <div class="category-stat">
                                <div class="category-stat-value" style="color: #f44336;"><?= $cat['rented_count'] ?></div>
                                <div class="category-stat-label">Vermietet</div>
                            </div>
                            <div class="category-stat">
                                <div class="category-stat-value" style="color: #ff9800;"><?= $cat['maintenance_count'] ?></div>
                                <div class="category-stat-label">Wartung</div>
                            </div>
                        </div>
                        
                        <div class="category-actions">
                            <?php if ($canEdit): ?>
                                <button class="btn btn-secondary btn-sm" onclick="editCategory(<?= $cat['id'] ?>)">
                                    ✏️ Bearbeiten
                                </button>
                            <?php endif; ?>
                            
                            <?php if ($canDelete && !$cat['is_system_category'] && $cat['object_count'] == 0): ?>
                                <button class="btn btn-secondary btn-sm" onclick="deleteCategory(<?= $cat['id'] ?>, '<?= htmlspecialchars($cat['display_name']) ?>')">
                                    🗑️ Löschen
                                </button>
                            <?php endif; ?>
                            
                            <?php if ($canEdit && !$cat['is_system_category']): ?>
                                <button class="btn btn-secondary btn-sm" onclick="toggleCategoryStatus(<?= $cat['id'] ?>, <?= $cat['is_active'] ? 'false' : 'true' ?>)">
                                    <?= $cat['is_active'] ? '❌ Deaktivieren' : '✅ Aktivieren' ?>
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Category Modal -->
    <div id="category-modal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="category-modal-title">Kategorie erstellen</h2>
                <button class="modal-close-btn close-modal">×</button>
            </div>
            
            <form id="category-form">
                <input type="hidden" id="category-id" name="category_id">
                <input type="hidden" id="selected-icon" name="icon" value="📦">
                
                <div class="form-group">
                    <label for="category-name">Name (technisch): *</label>
                    <input type="text" id="category-name" name="name" required 
                           pattern="[a-z0-9_]+" title="Nur Kleinbuchstaben, Zahlen und Unterstriche"
                           minlength="2" maxlength="50">
                    <small>Nur Kleinbuchstaben, Zahlen und Unterstriche (z.B. transport_mittel)</small>
                </div>
                
                <div class="form-group">
                    <label for="category-display-name">Anzeigename: *</label>
                    <input type="text" id="category-display-name" name="display_name" required maxlength="100">
                    <small>Wird in der Benutzeroberfläche angezeigt</small>
                </div>
                
                <div class="form-group">
                    <label for="category-description">Beschreibung:</label>
                    <textarea id="category-description" name="description" rows="3" maxlength="500"></textarea>
                </div>
                
                <div class="form-group">
                    <label for="category-color">Farbe: *</label>
                    <div class="color-input-wrapper">
                        <input type="color" id="category-color" name="color" value="#6bb032">
                        <div class="color-preview" id="color-preview" style="background: #6bb032;"></div>
                        <input type="text" id="category-color-text" value="#6bb032" 
                               pattern="^#[0-9A-Fa-f]{6}$" maxlength="7"
                               style="width: 100px; font-family: monospace;">
                    </div>
                    <small>Farbe für die Markierung der Kategorie</small>
                </div>
                
                <div class="form-group">
                    <label>Icon auswählen: *</label>
                    <div class="icon-picker" id="icon-picker">
                        <!-- Icons werden dynamisch geladen -->
                    </div>
                    <small>Aktuell ausgewählt: <span id="current-icon-display">📦</span></small>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">💾 Speichern</button>
                    <button type="button" class="btn btn-secondary close-modal">Abbrechen</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Notification Container -->
    <div id="notification-container" class="notification-container"></div>

    <script>
        const canEdit = <?= $canEdit ? 'true' : 'false' ?>;
        const canDelete = <?= $canDelete ? 'true' : 'false' ?>;
        const canCreate = <?= $canCreate ? 'true' : 'false' ?>;
        
        let availableIcons = <?= json_encode($availableIcons) ?>;
        let currentCategoryId = null;
        let isEditMode = false;
        
        document.addEventListener('DOMContentLoaded', function() {
            loadIconPicker();
            
            if (canCreate) {
                document.getElementById('create-category-btn').addEventListener('click', showCreateCategoryModal);
            }
            
            document.getElementById('refresh-categories-btn').addEventListener('click', () => location.reload());
            document.getElementById('category-form').addEventListener('submit', handleCategorySubmit);
            
            // Color picker sync
            const colorInput = document.getElementById('category-color');
            const colorText = document.getElementById('category-color-text');
            const colorPreview = document.getElementById('color-preview');
            
            colorInput.addEventListener('input', function() {
                colorText.value = this.value;
                colorPreview.style.background = this.value;
            });
            
            colorText.addEventListener('input', function() {
                if (/^#[0-9A-Fa-f]{6}$/.test(this.value)) {
                    colorInput.value = this.value;
                    colorPreview.style.background = this.value;
                }
            });
            
            document.querySelectorAll('.close-modal').forEach(btn => {
                btn.addEventListener('click', closeModal);
            });
            
            document.querySelector('.modal').addEventListener('click', function(e) {
                if (e.target === this) closeModal();
            });
        });
        
        function loadIconPicker() {
            const picker = document.getElementById('icon-picker');
            picker.innerHTML = '';
            
            for (const [icon, label] of Object.entries(availableIcons)) {
                const option = document.createElement('div');
                option.className = 'icon-option';
                option.textContent = icon;
                option.title = label;
                option.onclick = () => selectIcon(icon, option);
                picker.appendChild(option);
            }
        }
        
        function selectIcon(icon, element) {
            document.querySelectorAll('.icon-option').forEach(opt => opt.classList.remove('selected'));
            element.classList.add('selected');
            document.getElementById('selected-icon').value = icon;
            document.getElementById('current-icon-display').textContent = icon;
        }
        
        function showCreateCategoryModal() {
            isEditMode = false;
            currentCategoryId = null;
            
            document.getElementById('category-modal-title').textContent = 'Neue Kategorie erstellen';
            document.getElementById('category-form').reset();
            document.getElementById('category-id').value = '';
            document.getElementById('category-name').disabled = false;
            document.getElementById('selected-icon').value = '📦';
            document.getElementById('current-icon-display').textContent = '📦';
            document.getElementById('category-color').value = '#6bb032';
            document.getElementById('category-color-text').value = '#6bb032';
            document.getElementById('color-preview').style.background = '#6bb032';
            
            document.querySelectorAll('.icon-option').forEach(opt => opt.classList.remove('selected'));
            document.querySelector('.icon-option').classList.add('selected');
            
            document.getElementById('category-modal').classList.add('show');
        }
        
        async function editCategory(categoryId) {
            isEditMode = true;
            currentCategoryId = categoryId;
            
            try {
                const formData = new FormData();
                formData.append('action', 'get_category');
                formData.append('category_id', categoryId);
                
                const response = await fetch('', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    const category = result.category;
                    
                    document.getElementById('category-modal-title').textContent = 'Kategorie bearbeiten';
                    document.getElementById('category-id').value = category.id;
                    document.getElementById('category-name').value = category.name;
                    document.getElementById('category-name').disabled = category.is_system_category == 1;
                    document.getElementById('category-display-name').value = category.display_name;
                    document.getElementById('category-description').value = category.description || '';
                    document.getElementById('category-color').value = category.color;
                    document.getElementById('category-color-text').value = category.color;
                    document.getElementById('color-preview').style.background = category.color;
                    document.getElementById('selected-icon').value = category.icon;
                    document.getElementById('current-icon-display').textContent = category.icon;
                    
                    // Select icon
                    document.querySelectorAll('.icon-option').forEach(opt => {
                        if (opt.textContent === category.icon) {
                            opt.classList.add('selected');
                        } else {
                            opt.classList.remove('selected');
                        }
                    });
                    
                    document.getElementById('category-modal').classList.add('show');
                } else {
                    showNotification(result.message || 'Fehler beim Laden der Kategorie', 'error');
                }
            } catch (error) {
                console.error('Error loading category:', error);
                showNotification('Verbindungsfehler', 'error');
            }
        }
        
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
                
                const response = await fetch('', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
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
        
        async function deleteCategory(categoryId, categoryName) {
            if (!confirm(`Möchten Sie die Kategorie "${categoryName}" wirklich löschen?\n\nDiese Aktion kann nicht rückgängig gemacht werden.`)) {
                return;
            }
            
            try {
                const formData = new FormData();
                formData.append('action', 'delete_category');
                formData.append('category_id', categoryId);
                
                const response = await fetch('', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showNotification('Kategorie erfolgreich gelöscht', 'success');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showNotification(result.message || 'Fehler beim Löschen', 'error');
                }
            } catch (error) {
                console.error('Error deleting category:', error);
                showNotification('Verbindungsfehler beim Löschen', 'error');
            }
        }
        
        async function toggleCategoryStatus(categoryId, isActive) {
            try {
                const formData = new FormData();
                formData.append('action', 'toggle_category_status');
                formData.append('category_id', categoryId);
                formData.append('is_active', isActive);
                
                const response = await fetch('', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showNotification('Status erfolgreich geändert', 'success');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showNotification(result.message || 'Fehler beim Ändern des Status', 'error');
                }
            } catch (error) {
                console.error('Error toggling status:', error);
                showNotification('Verbindungsfehler', 'error');
            }
        }
        
        function closeModal() {
            document.getElementById('category-modal').classList.remove('show');
        }
        
        function showNotification(message, type = 'success', duration = 4000) {
            const container = document.getElementById('notification-container');
            const notification = document.createElement('div');
            notification.className = 'notification ' + type;
            notification.style.cssText = `
                background: white !important;
                color: #333 !important;
                border: 1px solid #ddd;
                border-radius: 8px;
                padding: 1rem;
                margin-bottom: 0.5rem;
                box-shadow: 0 4px 12px rgba(0,0,0,0.15);
                animation: slideInRight 0.3s ease;
                border-left: 4px solid ${type === 'error' ? '#dc3545' : type === 'warning' ? '#ffc107' : '#28a745'};
            `;
            
            notification.innerHTML = `
                <div style="display: flex; align-items: center; justify-content: space-between; gap: 1rem;">
                    <span style="font-weight: 500;">${escapeHtml(message)}</span>
                    <button onclick="this.parentElement.parentElement.remove()" 
                            style="background: none; border: none; font-size: 1.2rem; cursor: pointer; color: #666;">×</button>
                </div>
            `;
            
            container.appendChild(notification);
            
            if (duration > 0) {
                setTimeout(() => notification.remove(), duration);
            }
        }
        
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    </script>
</body>
</html>