<?php
require_once 'config.php';

// Berechtigung prüfen
Auth::requireLogin();
Auth::requirePermission('role.view', 'Sie haben keine Berechtigung zur Rollenverwaltung');

// AJAX Request Handling
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    try {
        switch ($_POST['action']) {
            case 'get_roles':
                $roles = RoleManager::getAllRoles();
                echo json_encode(['success' => true, 'roles' => $roles]);
                break;
                
            case 'get_role':
                $roleId = intval($_POST['role_id'] ?? 0);
                if ($roleId <= 0) {
                    throw new Exception('Ungültige Rollen-ID');
                }
                
                $role = RoleManager::getRoleById($roleId);
                if (!$role) {
                    throw new Exception('Rolle nicht gefunden');
                }
                
                $permissions = RoleManager::getRolePermissions($roleId);
                $permissionIds = array_map(function($p) { return $p['id']; }, $permissions);
                
                echo json_encode([
                    'success' => true,
                    'role' => $role,
                    'permission_ids' => $permissionIds
                ]);
                break;
                
            case 'create_role':
                Auth::requirePermission('role.create', 'Sie haben keine Berechtigung zum Erstellen von Rollen');
                
                $name = sanitizeInput($_POST['name'] ?? '');
                $displayName = sanitizeInput($_POST['display_name'] ?? '');
                $description = sanitizeInput($_POST['description'] ?? '');
                $permissions = $_POST['permissions'] ?? [];
                
                if (empty($name) || empty($displayName)) {
                    throw new Exception('Name und Anzeigename sind erforderlich');
                }
                
                if (!preg_match('/^[a-z_]+$/', $name)) {
                    throw new Exception('Name darf nur Kleinbuchstaben und Unterstriche enthalten');
                }
                
                if (strlen($name) < 3 || strlen($name) > 50) {
                    throw new Exception('Name muss zwischen 3 und 50 Zeichen lang sein');
                }
                
                $roleId = RoleManager::createRole($name, $displayName, $description, $permissions);
                
                logActivity('role_created', "Role created: $displayName (ID: $roleId)");
                echo json_encode(['success' => true, 'role_id' => $roleId]);
                break;
                
            case 'update_role':
                Auth::requirePermission('role.edit', 'Sie haben keine Berechtigung zum Bearbeiten von Rollen');
                
                $roleId = intval($_POST['role_id'] ?? 0);
                $displayName = sanitizeInput($_POST['display_name'] ?? '');
                $description = sanitizeInput($_POST['description'] ?? '');
                $permissions = $_POST['permissions'] ?? [];
                
                if ($roleId <= 0) {
                    throw new Exception('Ungültige Rollen-ID');
                }
                
                if (empty($displayName)) {
                    throw new Exception('Anzeigename ist erforderlich');
                }
                
                RoleManager::updateRole($roleId, $displayName, $description, $permissions);
                
                logActivity('role_updated', "Role updated: $displayName (ID: $roleId)");
                echo json_encode(['success' => true]);
                break;
                
            case 'delete_role':
                Auth::requirePermission('role.delete', 'Sie haben keine Berechtigung zum Löschen von Rollen');
                
                $roleId = intval($_POST['role_id'] ?? 0);
                if ($roleId <= 0) {
                    throw new Exception('Ungültige Rollen-ID');
                }
                
                $role = RoleManager::getRoleById($roleId);
                if (!$role) {
                    throw new Exception('Rolle nicht gefunden');
                }
                
                RoleManager::deleteRole($roleId);
                
                logActivity('role_deleted', "Role deleted: {$role['display_name']} (ID: $roleId)");
                echo json_encode(['success' => true]);
                break;
                
            case 'get_permissions':
                $permissions = RoleManager::getPermissionsByCategory();
                echo json_encode(['success' => true, 'permissions' => $permissions]);
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
$canCreate = Auth::hasPermission('role.create');
$canEdit = Auth::hasPermission('role.edit');
$canDelete = Auth::hasPermission('role.delete');
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rollenverwaltung - <?= htmlspecialchars(SYSTEM_NAME) ?></title>
    <link rel="stylesheet" href="style.css">
    <style>
        .roles-container {
            max-width: 1400px;
            margin: calc(var(--grid-unit) * 4) auto;
            padding: 0 var(--spacing-md);
        }
        
        .roles-header {
            background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
            color: white;
            padding: calc(var(--grid-unit) * 4);
            border-radius: var(--border-radius-large);
            margin-bottom: calc(var(--grid-unit) * 3);
            box-shadow: var(--shadow-heavy);
            border: 3px solid var(--secondary-color);
        }
        
        .roles-header h1 {
            font-size: clamp(20px, 4vw, 28px);
            margin-bottom: calc(var(--grid-unit) * 1);
            font-weight: 700;
        }
        
        .roles-header p {
            opacity: 0.9;
            font-size: var(--font-sm);
        }
        
        .roles-actions {
            display: flex;
            gap: var(--spacing-md);
            margin-bottom: calc(var(--grid-unit) * 3);
            flex-wrap: wrap;
        }
        
        .roles-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(clamp(280px, 40vw, 350px), 1fr));
            gap: var(--spacing-lg);
            margin-bottom: calc(var(--grid-unit) * 4);
        }
        
        .role-card {
            background: white;
            border: 2px solid var(--border-color);
            border-radius: var(--border-radius-large);
            padding: var(--spacing-lg);
            transition: all var(--transition-fast);
            box-shadow: var(--shadow-light);
        }
        
        .role-card:hover {
            border-color: var(--secondary-color);
            box-shadow: var(--shadow-green);
            transform: translateY(-2px);
        }
        
        .role-card.system-role {
            border-color: var(--warning-color);
            background: linear-gradient(135deg, #fff8e1 0%, white 100%);
        }
        
        .role-card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: var(--spacing-md);
            gap: var(--spacing-sm);
        }
        
        .role-name {
            flex: 1;
            min-width: 0;
        }
        
        .role-name h3 {
            margin: 0 0 var(--spacing-xs) 0;
            color: var(--primary-color);
            font-size: var(--font-lg);
            font-weight: 700;
            word-break: break-word;
        }
        
        .role-name .role-key {
            font-size: var(--font-xs);
            color: var(--text-muted);
            font-family: monospace;
            background: var(--light-gray);
            padding: 2px 8px;
            border-radius: 4px;
        }
        
        .role-badge {
            padding: 4px 12px;
            border-radius: 12px;
            font-size: var(--font-xs);
            font-weight: 600;
            white-space: nowrap;
            flex-shrink: 0;
        }
        
        .role-badge.system {
            background: linear-gradient(135deg, var(--warning-color), #ffb74d);
            color: white;
        }
        
        .role-badge.custom {
            background: linear-gradient(135deg, var(--secondary-color), var(--accent-color));
            color: white;
        }
        
        .role-description {
            color: var(--text-muted);
            font-size: var(--font-sm);
            margin-bottom: var(--spacing-md);
            line-height: 1.5;
            min-height: 3em;
        }
        
        .role-stats {
            display: flex;
            gap: var(--spacing-lg);
            padding: var(--spacing-md);
            background: var(--light-gray);
            border-radius: var(--border-radius);
            margin-bottom: var(--spacing-md);
        }
        
        .role-stat {
            text-align: center;
            flex: 1;
        }
        
        .role-stat-value {
            font-size: var(--font-xl);
            font-weight: 700;
            color: var(--secondary-color);
        }
        
        .role-stat-label {
            font-size: var(--font-xs);
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .role-actions {
            display: flex;
            gap: var(--spacing-xs);
            flex-wrap: wrap;
        }
        
        .role-actions .btn {
            flex: 1;
            min-width: 80px;
            justify-content: center;
        }
        
        .permissions-grid {
            display: grid;
            gap: var(--spacing-lg);
        }
        
        .permission-category {
            background: white;
            border: 2px solid var(--border-color);
            border-radius: var(--border-radius-large);
            padding: var(--spacing-lg);
            box-shadow: var(--shadow-light);
        }
        
        .permission-category-header {
            display: flex;
            align-items: center;
            gap: var(--spacing-sm);
            margin-bottom: var(--spacing-md);
            padding-bottom: var(--spacing-sm);
            border-bottom: 2px solid var(--border-color);
        }
        
        .permission-category-header h4 {
            margin: 0;
            color: var(--primary-color);
            font-size: var(--font-md);
            font-weight: 700;
            text-transform: uppercase;
            flex: 1;
        }
        
        .permission-category-icon {
            font-size: clamp(20px, 4vw, 24px);
        }
        
        .permissions-list {
            display: grid;
            gap: var(--spacing-sm);
        }
        
        .permission-item {
            display: flex;
            align-items: flex-start;
            gap: var(--spacing-sm);
            padding: var(--spacing-sm);
            border-radius: var(--border-radius);
            transition: all var(--transition-fast);
            cursor: pointer;
        }
        
        .permission-item:hover {
            background: var(--light-gray);
        }
        
        .permission-item input[type="checkbox"] {
            margin-top: 4px;
            width: 18px;
            height: 18px;
            cursor: pointer;
            accent-color: var(--secondary-color);
            flex-shrink: 0;
        }
        
        .permission-info {
            flex: 1;
            min-width: 0;
        }
        
        .permission-name {
            font-weight: 600;
            color: var(--primary-color);
            font-size: var(--font-sm);
            margin-bottom: 2px;
        }
        
        .permission-description {
            font-size: var(--font-xs);
            color: var(--text-muted);
            line-height: 1.4;
        }
        
        .empty-state {
            text-align: center;
            padding: calc(var(--grid-unit) * 8);
            background: var(--light-gray);
            border-radius: var(--border-radius-large);
            border: 2px dashed var(--border-color);
        }
        
        .empty-state-icon {
            font-size: clamp(48px, 10vw, 72px);
            margin-bottom: var(--spacing-md);
            color: var(--text-muted);
        }
        
        .empty-state h3 {
            color: var(--primary-color);
            margin-bottom: var(--spacing-sm);
        }
        
        .empty-state p {
            color: var(--text-muted);
            font-size: var(--font-sm);
        }
        
        .back-button {
            position: fixed;
            top: 100px;
            left: var(--spacing-lg);
            z-index: 1000;
        }
        
        @media (max-width: 768px) {
            .roles-grid {
                grid-template-columns: 1fr;
            }
            
            .role-stats {
                flex-direction: column;
                gap: var(--spacing-sm);
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
    
    <div class="roles-container">
        <div class="roles-header">
            <h1>🎭 Rollenverwaltung</h1>
            <p>Verwalten Sie Rollen und weisen Sie granulare Berechtigungen zu</p>
        </div>
        
        <div class="roles-actions">
            <?php if ($canCreate): ?>
                <button id="create-role-btn" class="btn btn-primary">
                    ➕ Neue Rolle erstellen
                </button>
            <?php endif; ?>
            
            <button id="refresh-roles-btn" class="btn btn-secondary">
                🔄 Aktualisieren
            </button>
            
            <a href="settings.php" class="btn btn-secondary">
                ⚙️ Einstellungen
            </a>
        </div>
        
        <div id="roles-grid" class="roles-grid">
            <div style="text-align: center; padding: 2rem; color: var(--text-muted);">
                Lade Rollen...
            </div>
        </div>
    </div>

    <!-- Create/Edit Role Modal -->
    <div id="role-modal" class="modal">
        <div class="modal-content large-modal">
            <div class="modal-header">
                <h2 id="role-modal-title">Rolle erstellen</h2>
                <button class="modal-close-btn close-modal">×</button>
            </div>
            
            <form id="role-form">
                <input type="hidden" id="role-id" name="role_id">
                
                <div class="form-group">
                    <label for="role-name">Rollenname (technisch): *</label>
                    <input type="text" id="role-name" name="name" required pattern="[a-z_]+" 
                           title="Nur Kleinbuchstaben und Unterstriche" minlength="3" maxlength="50">
                    <small>Nur Kleinbuchstaben und Unterstriche (z.B. project_manager)</small>
                </div>
                
                <div class="form-group">
                    <label for="role-display-name">Anzeigename: *</label>
                    <input type="text" id="role-display-name" name="display_name" required maxlength="100">
                    <small>Wird in der Benutzeroberfläche angezeigt</small>
                </div>
                
                <div class="form-group">
                    <label for="role-description">Beschreibung:</label>
                    <textarea id="role-description" name="description" rows="3" maxlength="500"></textarea>
                </div>
                
                <div class="form-group">
                    <label>Berechtigungen:</label>
                    <div id="permissions-container" class="permissions-grid">
                        <div style="text-align: center; padding: 2rem; color: var(--text-muted);">
                            Lade Berechtigungen...
                        </div>
                    </div>
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
        
        let allPermissions = {};
        let currentRoleId = null;
        let isEditMode = false;
        
        document.addEventListener('DOMContentLoaded', function() {
            loadRoles();
            loadPermissions();
            
            if (canCreate) {
                document.getElementById('create-role-btn').addEventListener('click', showCreateRoleModal);
            }
            
            document.getElementById('refresh-roles-btn').addEventListener('click', loadRoles);
            document.getElementById('role-form').addEventListener('submit', handleRoleSubmit);
            
            document.querySelectorAll('.close-modal').forEach(btn => {
                btn.addEventListener('click', closeModal);
            });
            
            document.querySelector('.modal').addEventListener('click', function(e) {
                if (e.target === this) {
                    closeModal();
                }
            });
        });
        
        async function loadRoles() {
            try {
                const formData = new FormData();
                formData.append('action', 'get_roles');
                
                const response = await fetch('', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    displayRoles(result.roles);
                } else {
                    showNotification(result.message || 'Fehler beim Laden der Rollen', 'error');
                }
            } catch (error) {
                console.error('Error loading roles:', error);
                showNotification('Verbindungsfehler beim Laden', 'error');
            }
        }
        
        function displayRoles(roles) {
            const grid = document.getElementById('roles-grid');
            
            if (roles.length === 0) {
                grid.innerHTML = `
                    <div class="empty-state" style="grid-column: 1 / -1;">
                        <div class="empty-state-icon">🎭</div>
                        <h3>Keine Rollen vorhanden</h3>
                        <p>Erstellen Sie Ihre erste Rolle mit individuellen Berechtigungen</p>
                    </div>
                `;
                return;
            }
            
            grid.innerHTML = roles.map(role => {
                const isSystemRole = role.is_system_role == 1;
                
                return `
                    <div class="role-card ${isSystemRole ? 'system-role' : ''}">
                        <div class="role-card-header">
                            <div class="role-name">
                                <h3>${escapeHtml(role.display_name)}</h3>
                                <span class="role-key">${escapeHtml(role.name)}</span>
                            </div>
                            <span class="role-badge ${isSystemRole ? 'system' : 'custom'}">
                                ${isSystemRole ? '🔒 System' : '✨ Benutzerdefiniert'}
                            </span>
                        </div>
                        
                        <div class="role-description">
                            ${role.description ? escapeHtml(role.description) : '<em>Keine Beschreibung</em>'}
                        </div>
                        
                        <div class="role-stats">
                            <div class="role-stat">
                                <div class="role-stat-value">${role.permission_count || 0}</div>
                                <div class="role-stat-label">Berechtigungen</div>
                            </div>
                        </div>
                        
                        <div class="role-actions">
                            ${canEdit ? `
                                <button class="btn btn-secondary" onclick="editRole(${role.id})">
                                    ✏️ Bearbeiten
                                </button>
                            ` : ''}
                            
                            ${canDelete && !isSystemRole ? `
                                <button class="btn btn-secondary" onclick="deleteRole(${role.id}, '${escapeHtml(role.display_name)}')">
                                    🗑️ Löschen
                                </button>
                            ` : ''}
                        </div>
                    </div>
                `;
            }).join('');
        }
        
        async function loadPermissions() {
            try {
                const formData = new FormData();
                formData.append('action', 'get_permissions');
                
                const response = await fetch('', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    allPermissions = result.permissions;
                } else {
                    showNotification('Fehler beim Laden der Berechtigungen', 'error');
                }
            } catch (error) {
                console.error('Error loading permissions:', error);
            }
        }
        
        function displayPermissions(selectedPermissions = []) {
            const container = document.getElementById('permissions-container');
            
            const categoryIcons = {
                'marker': '📍',
                'settings': '⚙️',
                'user': '👤',
                'role': '🎭',
                'category': '📦'
            };
            
            const categoryNames = {
                'marker': 'Marker-Verwaltung',
                'settings': 'Einstellungen',
                'user': 'Benutzerverwaltung',
                'role': 'Rollenverwaltung',
                'category': 'Kategorienverwaltung'
            };
            
            container.innerHTML = Object.keys(allPermissions).map(category => {
                const permissions = allPermissions[category];
                const icon = categoryIcons[category] || '📋';
                const name = categoryNames[category] || category;
                
                return `
                    <div class="permission-category">
                        <div class="permission-category-header">
                            <span class="permission-category-icon">${icon}</span>
                            <h4>${name}</h4>
                        </div>
                        <div class="permissions-list">
                            ${permissions.map(perm => `
                                <label class="permission-item">
                                    <input type="checkbox" name="permissions[]" value="${perm.id}"
                                           ${selectedPermissions.includes(perm.id) ? 'checked' : ''}>
                                    <div class="permission-info">
                                        <div class="permission-name">${escapeHtml(perm.display_name)}</div>
                                        <div class="permission-description">${escapeHtml(perm.description || '')}</div>
                                    </div>
                                </label>
                            `).join('')}
                        </div>
                    </div>
                `;
            }).join('');
        }
        
        function showCreateRoleModal() {
            isEditMode = false;
            currentRoleId = null;
            
            document.getElementById('role-modal-title').textContent = 'Neue Rolle erstellen';
            document.getElementById('role-form').reset();
            document.getElementById('role-id').value = '';
            document.getElementById('role-name').disabled = false;
            
            displayPermissions([]);
            
            document.getElementById('role-modal').classList.add('show');
        }
        
        async function editRole(roleId) {
            isEditMode = true;
            currentRoleId = roleId;
            
            try {
                const formData = new FormData();
                formData.append('action', 'get_role');
                formData.append('role_id', roleId);
                
                const response = await fetch('', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    const role = result.role;
                    
                    document.getElementById('role-modal-title').textContent = 'Rolle bearbeiten';
                    document.getElementById('role-id').value = role.id;
                    document.getElementById('role-name').value = role.name;
                    document.getElementById('role-name').disabled = true;
                    document.getElementById('role-display-name').value = role.display_name;
                    document.getElementById('role-description').value = role.description || '';
                    
                    displayPermissions(result.permission_ids);
                    
                    document.getElementById('role-modal').classList.add('show');
                } else {
                    showNotification(result.message || 'Fehler beim Laden der Rolle', 'error');
                }
            } catch (error) {
                console.error('Error loading role:', error);
                showNotification('Verbindungsfehler', 'error');
            }
        }
        
        async function handleRoleSubmit(e) {
            e.preventDefault();
            
            const form = e.target;
            const formData = new FormData(form);
            
            const action = isEditMode ? 'update_role' : 'create_role';
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
                        isEditMode ? 'Rolle erfolgreich aktualisiert' : 'Rolle erfolgreich erstellt',
                        'success'
                    );
                    closeModal();
                    loadRoles();
                } else {
                    showNotification(result.message || 'Fehler beim Speichern', 'error');
                }
            } catch (error) {
                console.error('Error saving role:', error);
                showNotification('Verbindungsfehler beim Speichern', 'error');
            } finally {
                submitBtn.disabled = false;
                submitBtn.textContent = originalText;
            }
        }
        
        async function deleteRole(roleId, roleName) {
            if (!confirm(`Möchten Sie die Rolle "${roleName}" wirklich löschen?\n\nDiese Aktion kann nicht rückgängig gemacht werden.`)) {
                return;
            }
            
            try {
                const formData = new FormData();
                formData.append('action', 'delete_role');
                formData.append('role_id', roleId);
                
                const response = await fetch('', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showNotification('Rolle erfolgreich gelöscht', 'success');
                    loadRoles();
                } else {
                    showNotification(result.message || 'Fehler beim Löschen', 'error');
                }
            } catch (error) {
                console.error('Error deleting role:', error);
                showNotification('Verbindungsfehler beim Löschen', 'error');
            }
        }
        
        function closeModal() {
            document.getElementById('role-modal').classList.remove('show');
        }
        
        function showNotification(message, type = 'success', duration = 4000) {
            const container = document.getElementById('notification-container');
            const notification = document.createElement('div');
            notification.className = 'notification ' + type;
            notification.style.cssText = `
                background: white;
                color: #333;
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