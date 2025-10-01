<?php
require_once 'config.php';

Auth::requireLogin();
Auth::requirePermission('view_audit_log');

// Filter-Parameter
$filters = [
    'entity_type' => $_GET['entity_type'] ?? '',
    'action' => $_GET['action'] ?? '',
    'user_id' => $_GET['user_id'] ?? '',
    'date_from' => $_GET['date_from'] ?? '',
    'date_to' => $_GET['date_to'] ?? ''
];

$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$perPage = 50;
$offset = ($page - 1) * $perPage;

// Audit-Log Einträge holen
$entries = AuditLog::getEntries($filters, $perPage, $offset);
$totalEntries = AuditLog::countEntries($filters);
$totalPages = ceil($totalEntries / $perPage);

// Verfügbare Entity-Types und Actions für Filter
$db = Database::getInstance()->getConnection();
$entityTypes = $db->query("SELECT DISTINCT entity_type FROM audit_log ORDER BY entity_type")->fetchAll(PDO::FETCH_COLUMN);
$actions = $db->query("SELECT DISTINCT action FROM audit_log ORDER BY action")->fetchAll(PDO::FETCH_COLUMN);
$users = $db->query("SELECT id, username FROM users ORDER BY username")->fetchAll();

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Audit-Log - <?= SYSTEM_NAME ?></title>
    <link rel="stylesheet" href="style.css">
    <style>
        .audit-log-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .filters {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        
        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 15px;
        }
        
        .filter-group {
            display: flex;
            flex-direction: column;
        }
        
        .filter-group label {
            font-weight: 500;
            margin-bottom: 5px;
            color: #555;
        }
        
        .filter-group select,
        .filter-group input {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }
        
        .audit-table {
            width: 100%;
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .audit-table table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .audit-table th {
            background: #667eea;
            color: white;
            padding: 12px;
            text-align: left;
            font-weight: 500;
        }
        
        .audit-table td {
            padding: 12px;
            border-bottom: 1px solid #eee;
        }
        
        .audit-table tr:hover {
            background: #f8f9fa;
        }
        
        .action-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .action-create {
            background: #d4edda;
            color: #155724;
        }
        
        .action-update {
            background: #cce5ff;
            color: #004085;
        }
        
        .action-delete {
            background: #f8d7da;
            color: #721c24;
        }
        
        .action-login {
            background: #d1ecf1;
            color: #0c5460;
        }
        
        .action-logout {
            background: #e2e3e5;
            color: #383d41;
        }
        
        .entity-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            background: #e9ecef;
            color: #495057;
        }
        
        .changes-preview {
            max-width: 300px;
            font-size: 12px;
            color: #666;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .changes-detail {
            display: none;
            background: #f8f9fa;
            padding: 10px;
            margin-top: 5px;
            border-radius: 4px;
            font-family: monospace;
            font-size: 12px;
            white-space: pre-wrap;
            max-height: 300px;
            overflow-y: auto;
        }
        
        .show-details-btn {
            background: none;
            border: none;
            color: #667eea;
            cursor: pointer;
            text-decoration: underline;
            font-size: 12px;
            padding: 0;
        }
        
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            margin-top: 20px;
            padding: 20px;
            background: white;
            border-radius: 8px;
        }
        
        .pagination a,
        .pagination span {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            text-decoration: none;
            color: #333;
        }
        
        .pagination a:hover {
            background: #667eea;
            color: white;
            border-color: #667eea;
        }
        
        .pagination .current {
            background: #667eea;
            color: white;
            border-color: #667eea;
        }
        
        .btn-group {
            display: flex;
            gap: 10px;
        }
        
        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            text-decoration: none;
            display: inline-block;
        }
        
        .btn-primary {
            background: #667eea;
            color: white;
        }
        
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        
        .btn-secondary:hover {
            background: #5a6268;
        }
        
        .stats-bar {
            background: white;
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .stat-item {
            text-align: center;
        }
        
        .stat-value {
            font-size: 24px;
            font-weight: bold;
            color: #667eea;
        }
        
        .stat-label {
            font-size: 12px;
            color: #666;
        }
    </style>
</head>
<body>
    <?php include 'nav.php'; ?>
    
    <div class="audit-log-container">
        <h1>📋 Audit-Log</h1>
        
        <!-- Statistik -->
        <div class="stats-bar">
            <div class="stat-item">
                <div class="stat-value"><?= number_format($totalEntries) ?></div>
                <div class="stat-label">Gefilterte Einträge</div>
            </div>
            <div class="stat-item">
                <div class="stat-value"><?= $page ?> / <?= max(1, $totalPages) ?></div>
                <div class="stat-label">Seite</div>
            </div>
            <div class="btn-group">
                <a href="security_settings.php" class="btn btn-secondary">⚙️ Einstellungen</a>
                <button onclick="exportAuditLog()" class="btn btn-secondary">📥 Exportieren</button>
            </div>
        </div>
        
        <!-- Filter -->
        <div class="filters">
            <form method="GET">
                <div class="filter-grid">
                    <div class="filter-group">
                        <label for="entity_type">Entitätstyp</label>
                        <select name="entity_type" id="entity_type">
                            <option value="">Alle</option>
                            <?php foreach ($entityTypes as $type): ?>
                                <option value="<?= htmlspecialchars($type) ?>" 
                                        <?= $filters['entity_type'] === $type ? 'selected' : '' ?>>
                                    <?= AuditLog::formatEntityType($type) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label for="action">Aktion</label>
                        <select name="action" id="action">
                            <option value="">Alle</option>
                            <?php foreach ($actions as $action): ?>
                                <option value="<?= htmlspecialchars($action) ?>" 
                                        <?= $filters['action'] === $action ? 'selected' : '' ?>>
                                    <?= AuditLog::formatAction($action) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label for="user_id">Benutzer</label>
                        <select name="user_id" id="user_id">
                            <option value="">Alle</option>
                            <?php foreach ($users as $user): ?>
                                <option value="<?= $user['id'] ?>" 
                                        <?= $filters['user_id'] == $user['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($user['username']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label for="date_from">Von Datum</label>
                        <input type="date" name="date_from" id="date_from" 
                               value="<?= htmlspecialchars($filters['date_from']) ?>">
                    </div>
                    
                    <div class="filter-group">
                        <label for="date_to">Bis Datum</label>
                        <input type="date" name="date_to" id="date_to" 
                               value="<?= htmlspecialchars($filters['date_to']) ?>">
                    </div>
                </div>
                
                <div class="btn-group">
                    <button type="submit" class="btn btn-primary">🔍 Filtern</button>
                    <a href="audit_log.php" class="btn btn-secondary">🔄 Zurücksetzen</a>
                </div>
            </form>
        </div>
        
        <!-- Tabelle -->
        <div class="audit-table">
            <?php if (empty($entries)): ?>
                <div style="padding: 40px; text-align: center; color: #999;">
                    Keine Einträge gefunden
                </div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Datum/Zeit</th>
                            <th>Benutzer</th>
                            <th>Entität</th>
                            <th>Aktion</th>
                            <th>Details</th>
                            <th>IP-Adresse</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($entries as $entry): ?>
                            <tr>
                                <td style="white-space: nowrap;">
                                    <?= date('d.m.Y H:i:s', strtotime($entry['created_at'])) ?>
                                </td>
                                <td>
                                    <?= htmlspecialchars($entry['username'] ?? 'System') ?>
                                </td>
                                <td>
                                    <span class="entity-badge">
                                        <?= AuditLog::formatEntityType($entry['entity_type']) ?>
                                    </span>
                                    <?php if ($entry['entity_name']): ?>
                                        <br><small><?= htmlspecialchars($entry['entity_name']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="action-badge action-<?= $entry['action'] ?>">
                                        <?= AuditLog::formatAction($entry['action']) ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($entry['changes']): ?>
                                        <div class="changes-preview">
                                            <?= htmlspecialchars(substr($entry['changes'], 0, 50)) ?>...
                                        </div>
                                        <button class="show-details-btn" onclick="toggleDetails(<?= $entry['id'] ?>)">
                                            Details anzeigen
                                        </button>
                                        <div id="details-<?= $entry['id'] ?>" class="changes-detail">
                                            <?= htmlspecialchars(json_encode(json_decode($entry['changes']), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?>
                                        </div>
                                    <?php else: ?>
                                        <span style="color: #999;">-</span>
                                    <?php endif; ?>
                                </td>
                                <td style="font-size: 12px; color: #666;">
                                    <?= htmlspecialchars($entry['ip_address']) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        
        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="?<?= http_build_query(array_merge($filters, ['page' => $page - 1])) ?>">
                        ← Zurück
                    </a>
                <?php endif; ?>
                
                <?php
                $start = max(1, $page - 2);
                $end = min($totalPages, $page + 2);
                
                if ($start > 1): ?>
                    <a href="?<?= http_build_query(array_merge($filters, ['page' => 1])) ?>">1</a>
                    <?php if ($start > 2): ?>
                        <span>...</span>
                    <?php endif; ?>
                <?php endif; ?>
                
                <?php for ($i = $start; $i <= $end; $i++): ?>
                    <?php if ($i === $page): ?>
                        <span class="current"><?= $i ?></span>
                    <?php else: ?>
                        <a href="?<?= http_build_query(array_merge($filters, ['page' => $i])) ?>">
                            <?= $i ?>
                        </a>
                    <?php endif; ?>
                <?php endfor; ?>
                
                <?php if ($end < $totalPages): ?>
                    <?php if ($end < $totalPages - 1): ?>
                        <span>...</span>
                    <?php endif; ?>
                    <a href="?<?= http_build_query(array_merge($filters, ['page' => $totalPages])) ?>">
                        <?= $totalPages ?>
                    </a>
                <?php endif; ?>
                
                <?php if ($page < $totalPages): ?>
                    <a href="?<?= http_build_query(array_merge($filters, ['page' => $page + 1])) ?>">
                        Weiter →
                    </a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
    
    <script>
        function toggleDetails(id) {
            const detailsDiv = document.getElementById('details-' + id);
            const btn = detailsDiv.previousElementSibling;
            
            if (detailsDiv.style.display === 'block') {
                detailsDiv.style.display = 'none';
                btn.textContent = 'Details anzeigen';
            } else {
                detailsDiv.style.display = 'block';
                btn.textContent = 'Details ausblenden';
            }
        }
        
        function exportAuditLog() {
            const params = new URLSearchParams(window.location.search);
            params.set('export', 'csv');
            window.location.href = 'audit_log_export.php?' + params.toString();
        }
    </script>
</body>
</html>