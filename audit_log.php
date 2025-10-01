<?php
require_once 'config.php';

// Nur für Administratoren
Auth::requireAdmin();

// AJAX Request Handling
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    try {
        switch ($_POST['action']) {
            case 'search_audit_log':
                $filters = [
                    'username' => $_POST['username'] ?? '',
                    'ip_address' => $_POST['ip_address'] ?? '',
                    'action' => $_POST['log_action'] ?? '',
                    'severity' => $_POST['severity'] ?? '',
                    'date_from' => $_POST['date_from'] ?? '',
                    'date_to' => $_POST['date_to'] ?? ''
                ];
                
                // Leere Filter entfernen
                $filters = array_filter($filters, function($value) {
                    return !empty($value);
                });
                
                $limit = intval($_POST['limit'] ?? 100);
                $offset = intval($_POST['offset'] ?? 0);
                
                $results = AuditLogger::search($filters, $limit, $offset);
                
                // Details von JSON zu Array konvertieren
                foreach ($results as &$result) {
                    if (!empty($result['details'])) {
                        $result['details_parsed'] = json_decode($result['details'], true);
                    }
                }
                
                echo json_encode(['success' => true, 'results' => $results, 'count' => count($results)]);
                break;
            
            case 'get_statistics':
                $db = Database::getInstance()->getConnection();
                
                // Letzte 24 Stunden
                $stmt = $db->query("
                    SELECT 
                        COUNT(*) as total_events,
                        COUNT(DISTINCT user_id) as unique_users,
                        COUNT(DISTINCT ip_address) as unique_ips,
                        SUM(CASE WHEN severity = 'critical' THEN 1 ELSE 0 END) as critical_count,
                        SUM(CASE WHEN severity = 'error' THEN 1 ELSE 0 END) as error_count,
                        SUM(CASE WHEN severity = 'warning' THEN 1 ELSE 0 END) as warning_count
                    FROM audit_log
                    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                ");
                $stats_24h = $stmt->fetch();
                
                // Letzte 7 Tage
                $stmt = $db->query("
                    SELECT 
                        COUNT(*) as total_events,
                        COUNT(DISTINCT user_id) as unique_users
                    FROM audit_log
                    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                ");
                $stats_7d = $stmt->fetch();
                
                // Top Aktionen
                $stmt = $db->query("
                    SELECT action, COUNT(*) as count
                    FROM audit_log
                    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                    GROUP BY action
                    ORDER BY count DESC
                    LIMIT 10
                ");
                $top_actions = $stmt->fetchAll();
                
                // Top Benutzer
                $stmt = $db->query("
                    SELECT username, COUNT(*) as count
                    FROM audit_log
                    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                    AND user_id IS NOT NULL
                    GROUP BY username
                    ORDER BY count DESC
                    LIMIT 10
                ");
                $top_users = $stmt->fetchAll();
                
                echo json_encode([
                    'success' => true,
                    'stats_24h' => $stats_24h,
                    'stats_7d' => $stats_7d,
                    'top_actions' => $top_actions,
                    'top_users' => $top_users
                ]);
                break;
            
            case 'get_locked_ips':
                $db = Database::getInstance()->getConnection();
                $stmt = $db->query("SELECT * FROM v_locked_ips");
                $locked_ips = $stmt->fetchAll();
                
                echo json_encode(['success' => true, 'locked_ips' => $locked_ips]);
                break;
            
            case 'unlock_ip':
                $ip = $_POST['ip_address'] ?? '';
                
                if (empty($ip)) {
                    throw new Exception('IP-Adresse erforderlich');
                }
                
                $db = Database::getInstance()->getConnection();
                $stmt = $db->prepare("UPDATE login_attempts SET locked_until = NULL WHERE ip_address = ?");
                $stmt->execute([$ip]);
                
                AuditLogger::log('ip_unlocked', "IP manually unlocked by admin", [
                    'ip_address' => $ip,
                    'unlocked_by' => Auth::getUsername()
                ]);
                
                echo json_encode(['success' => true, 'message' => 'IP erfolgreich entsperrt']);
                break;
            
            case 'cleanup_old_logs':
                $days = intval($_POST['retention_days'] ?? AUDIT_LOG_RETENTION_DAYS);
                
                if ($days < 1 || $days > 3650) {
                    throw new Exception('Ungültige Retention-Period');
                }
                
                $result = AuditLogger::cleanup();
                
                AuditLogger::log('audit_cleanup', "Manual audit log cleanup performed", [
                    'retention_days' => $days,
                    'deleted_rows' => $result['deleted_rows'],
                    'deleted_files' => $result['deleted_files']
                ], 'info');
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Bereinigung erfolgreich',
                    'deleted_rows' => $result['deleted_rows'],
                    'deleted_files' => $result['deleted_files']
                ]);
                break;
            
            case 'export_audit_log':
                $filters = [
                    'username' => $_POST['username'] ?? '',
                    'ip_address' => $_POST['ip_address'] ?? '',
                    'action' => $_POST['log_action'] ?? '',
                    'severity' => $_POST['severity'] ?? '',
                    'date_from' => $_POST['date_from'] ?? '',
                    'date_to' => $_POST['date_to'] ?? ''
                ];
                
                $filters = array_filter($filters, function($value) {
                    return !empty($value);
                });
                
                $results = AuditLogger::search($filters, 10000, 0);
                
                // CSV generieren
                $filename = 'audit_log_' . date('Y-m-d_His') . '.csv';
                
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename="' . $filename . '"');
                
                $output = fopen('php://output', 'w');
                
                // BOM für Excel UTF-8 Support
                fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
                
                // Header
                fputcsv($output, ['ID', 'Datum', 'Benutzer', 'IP-Adresse', 'Aktion', 'Beschreibung', 'Schweregrad', 'Details']);
                
                // Daten
                foreach ($results as $row) {
                    fputcsv($output, [
                        $row['id'],
                        $row['created_at'],
                        $row['username'],
                        $row['ip_address'],
                        $row['action'],
                        $row['description'],
                        $row['severity'],
                        $row['details']
                    ]);
                }
                
                fclose($output);
                
                AuditLogger::log('audit_export', "Audit log exported", [
                    'record_count' => count($results),
                    'filters' => $filters
                ]);
                
                exit;
                break;
            
            default:
                throw new Exception('Unbekannte Aktion');
        }
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    
    exit;
}

// Verfügbare Aktionen für Filter
$db = Database::getInstance()->getConnection();
$stmt = $db->query("SELECT DISTINCT action FROM audit_log ORDER BY action");
$available_actions = $stmt->fetchAll(PDO::FETCH_COLUMN);

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Audit-Log - <?= htmlspecialchars(SYSTEM_NAME) ?></title>
    <link rel="stylesheet" href="style.css">
    <style>
        .audit-container {
            max-width: 1400px;
            margin: 2rem auto;
            padding: 0 1rem;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }
        
        .stat-card {
            background: white;
            padding: 1.5rem;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .stat-card h3 {
            margin: 0 0 0.5rem 0;
            font-size: 0.9rem;
            color: #666;
            text-transform: uppercase;
        }
        
        .stat-value {
            font-size: 2rem;
            font-weight: bold;
            color: #2c5530;
        }
        
        .stat-card.critical .stat-value { color: #dc3545; }
        .stat-card.error .stat-value { color: #fd7e14; }
        .stat-card.warning .stat-value { color: #ffc107; }
        
        .filter-section {
            background: white;
            padding: 1.5rem;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
        }
        
        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }
        
        .results-section {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        
        .results-header {
            padding: 1rem 1.5rem;
            background: #f8f9fa;
            border-bottom: 1px solid #dee2e6;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .audit-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .audit-table th,
        .audit-table td {
            padding: 0.75rem;
            text-align: left;
            border-bottom: 1px solid #dee2e6;
        }
        
        .audit-table th {
            background: #f8f9fa;
            font-weight: 600;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        
        .audit-table tbody tr:hover {
            background: #f8f9fa;
        }
        
        .severity-badge {
            display: inline-block;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .severity-info { background: #d1ecf1; color: #0c5460; }
        .severity-warning { background: #fff3cd; color: #856404; }
        .severity-error { background: #f8d7da; color: #721c24; }
        .severity-critical { background: #f5c6cb; color: #721c24; font-weight: bold; }
        
        .details-toggle {
            cursor: pointer;
            color: #007bff;
            text-decoration: underline;
        }
        
        .details-content {
            display: none;
            margin-top: 0.5rem;
            padding: 0.5rem;
            background: #f8f9fa;
            border-radius: 4px;
            font-family: 'Courier New', monospace;
            font-size: 0.85rem;
            white-space: pre-wrap;
            word-break: break-all;
        }
        
        .details-content.show {
            display: block;
        }
        
        .locked-ips-section {
            margin-top: 2rem;
        }
        
        .no-results {
            padding: 3rem;
            text-align: center;
            color: #666;
        }
        
        .action-buttons {
            display: flex;
            gap: 0.5rem;
        }
        
        .top-lists {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }
        
        .top-list-card {
            background: white;
            padding: 1.5rem;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .top-list-card h3 {
            margin: 0 0 1rem 0;
            font-size: 1rem;
            color: #333;
        }
        
        .top-list-item {
            display: flex;
            justify-content: space-between;
            padding: 0.5rem 0;
            border-bottom: 1px solid #eee;
        }
        
        .top-list-item:last-child {
            border-bottom: none;
        }
        
        .table-container {
            max-height: 600px;
            overflow-y: auto;
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="container">
            <a href="index.php" class="logo">← <?= htmlspecialchars(SYSTEM_NAME) ?></a>
            <nav class="nav-actions">
                <span class="user-info">
                    <?= htmlspecialchars(Auth::getUsername()) ?> | <?= htmlspecialchars(Auth::getRoleDisplayName()) ?>
                </span>
            </nav>
        </div>
    </header>

    <main class="audit-container">
        <h1>🔍 Audit-Log & Sicherheit</h1>
        
        <!-- Statistiken -->
        <div id="statistics-section">
            <h2>📊 Statistiken (Letzte 24 Stunden)</h2>
            <div class="stats-grid" id="stats-grid">
                <div class="stat-card">
                    <h3>Gesamt-Ereignisse</h3>
                    <div class="stat-value" id="stat-total">-</div>
                </div>
                <div class="stat-card">
                    <h3>Eindeutige Benutzer</h3>
                    <div class="stat-value" id="stat-users">-</div>
                </div>
                <div class="stat-card">
                    <h3>Eindeutige IPs</h3>
                    <div class="stat-value" id="stat-ips">-</div>
                </div>
                <div class="stat-card critical">
                    <h3>Kritisch</h3>
                    <div class="stat-value" id="stat-critical">-</div>
                </div>
                <div class="stat-card error">
                    <h3>Fehler</h3>
                    <div class="stat-value" id="stat-error">-</div>
                </div>
                <div class="stat-card warning">
                    <h3>Warnungen</h3>
                    <div class="stat-value" id="stat-warning">-</div>
                </div>
            </div>
            
            <div class="top-lists">
                <div class="top-list-card">
                    <h3>🔥 Top Aktionen (7 Tage)</h3>
                    <div id="top-actions"></div>
                </div>
                <div class="top-list-card">
                    <h3>👤 Aktivste Benutzer (7 Tage)</h3>
                    <div id="top-users"></div>
                </div>
            </div>
        </div>

        <!-- Gesperrte IPs -->
        <div class="locked-ips-section">
            <h2>🔒 Gesperrte IP-Adressen</h2>
            <div class="results-section">
                <div class="results-header">
                    <span id="locked-ips-count">0 gesperrte IPs</span>
                    <button class="btn btn-secondary btn-sm" onclick="refreshLockedIPs()">🔄 Aktualisieren</button>
                </div>
                <div id="locked-ips-container"></div>
            </div>
        </div>

        <!-- Filter -->
        <div class="filter-section">
            <h2>🔎 Audit-Log durchsuchen</h2>
            <form id="search-form">
                <div class="filter-grid">
                    <div class="form-group">
                        <label>Benutzer</label>
                        <input type="text" id="filter-username" name="username" placeholder="Benutzername">
                    </div>
                    <div class="form-group">
                        <label>IP-Adresse</label>
                        <input type="text" id="filter-ip" name="ip_address" placeholder="z.B. 192.168.1.1">
                    </div>
                    <div class="form-group">
                        <label>Aktion</label>
                        <select id="filter-action" name="log_action">
                            <option value="">Alle Aktionen</option>
                            <?php foreach ($available_actions as $action): ?>
                                <option value="<?= htmlspecialchars($action) ?>"><?= htmlspecialchars($action) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Schweregrad</label>
                        <select id="filter-severity" name="severity">
                            <option value="">Alle</option>
                            <option value="info">Info</option>
                            <option value="warning">Warnung</option>
                            <option value="error">Fehler</option>
                            <option value="critical">Kritisch</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Von Datum</label>
                        <input type="date" id="filter-date-from" name="date_from">
                    </div>
                    <div class="form-group">
                        <label>Bis Datum</label>
                        <input type="date" id="filter-date-to" name="date_to">
                    </div>
                </div>
                <div class="action-buttons">
                    <button type="submit" class="btn btn-primary">🔍 Suchen</button>
                    <button type="button" class="btn btn-secondary" onclick="resetFilters()">🔄 Zurücksetzen</button>
                    <button type="button" class="btn btn-secondary" onclick="exportAuditLog()">📥 Export CSV</button>
                    <button type="button" class="btn btn-danger" onclick="cleanupOldLogs()">🗑️ Alte Logs löschen</button>
                </div>
            </form>
        </div>

        <!-- Ergebnisse -->
        <div class="results-section">
            <div class="results-header">
                <span id="results-count">0 Einträge</span>
                <div class="action-buttons">
                    <select id="limit-select" onchange="searchAuditLog()">
                        <option value="50">50 Einträge</option>
                        <option value="100" selected>100 Einträge</option>
                        <option value="250">250 Einträge</option>
                        <option value="500">500 Einträge</option>
                    </select>
                </div>
            </div>
            <div class="table-container">
                <table class="audit-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Datum/Zeit</th>
                            <th>Benutzer</th>
                            <th>IP-Adresse</th>
                            <th>Aktion</th>
                            <th>Beschreibung</th>
                            <th>Schweregrad</th>
                            <th>Details</th>
                        </tr>
                    </thead>
                    <tbody id="audit-results">
                        <tr>
                            <td colspan="8" class="no-results">
                                Verwenden Sie die Filter oben um das Audit-Log zu durchsuchen
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <div id="notification-container" class="notification-container"></div>
    <div id="loading-overlay" class="loading-overlay" style="display: none;">
        <div class="spinner"></div>
        <p id="loading-text">Laden...</p>
    </div>

    <script>
        // Beim Laden Statistiken abrufen
        document.addEventListener('DOMContentLoaded', function() {
            loadStatistics();
            refreshLockedIPs();
            
            // Standard: Letzte 24 Stunden anzeigen
            const today = new Date();
            const yesterday = new Date(today);
            yesterday.setDate(yesterday.getDate() - 1);
            
            document.getElementById('filter-date-from').value = yesterday.toISOString().split('T')[0];
            document.getElementById('filter-date-to').value = today.toISOString().split('T')[0];
            
            searchAuditLog();
        });

        // Statistiken laden
        function loadStatistics() {
            fetch('audit_log.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=get_statistics'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // 24h Statistiken
                    document.getElementById('stat-total').textContent = data.stats_24h.total_events || 0;
                    document.getElementById('stat-users').textContent = data.stats_24h.unique_users || 0;
                    document.getElementById('stat-ips').textContent = data.stats_24h.unique_ips || 0;
                    document.getElementById('stat-critical').textContent = data.stats_24h.critical_count || 0;
                    document.getElementById('stat-error').textContent = data.stats_24h.error_count || 0;
                    document.getElementById('stat-warning').textContent = data.stats_24h.warning_count || 0;
                    
                    // Top Aktionen
                    let actionsHtml = '';
                    if (data.top_actions && data.top_actions.length > 0) {
                        data.top_actions.forEach(item => {
                            actionsHtml += `
                                <div class="top-list-item">
                                    <span>${item.action}</span>
                                    <strong>${item.count}</strong>
                                </div>
                            `;
                        });
                    } else {
                        actionsHtml = '<div class="no-results">Keine Daten verfügbar</div>';
                    }
                    document.getElementById('top-actions').innerHTML = actionsHtml;
                    
                    // Top Benutzer
                    let usersHtml = '';
                    if (data.top_users && data.top_users.length > 0) {
                        data.top_users.forEach(item => {
                            usersHtml += `
                                <div class="top-list-item">
                                    <span>${item.username}</span>
                                    <strong>${item.count}</strong>
                                </div>
                            `;
                        });
                    } else {
                        usersHtml = '<div class="no-results">Keine Daten verfügbar</div>';
                    }
                    document.getElementById('top-users').innerHTML = usersHtml;
                }
            })
            .catch(error => {
                console.error('Fehler beim Laden der Statistiken:', error);
            });
        }

        // Gesperrte IPs laden
        function refreshLockedIPs() {
            fetch('audit_log.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=get_locked_ips'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const container = document.getElementById('locked-ips-container');
                    const count = data.locked_ips.length;
                    
                    document.getElementById('locked-ips-count').textContent = `${count} gesperrte IP${count !== 1 ? 's' : ''}`;
                    
                    if (count === 0) {
                        container.innerHTML = '<div class="no-results">Keine gesperrten IPs</div>';
                        return;
                    }
                    
                    let html = '<table class="audit-table"><thead><tr>';
                    html += '<th>IP-Adresse</th><th>Benutzer</th><th>Gesperrt bis</th>';
                    html += '<th>Verbleibende Zeit</th><th>Fehlversuche</th><th>Aktion</th>';
                    html += '</tr></thead><tbody>';
                    
                    data.locked_ips.forEach(ip => {
                        html += '<tr>';
                        html += `<td>${ip.ip_address}</td>`;
                        html += `<td>${ip.username}</td>`;
                        html += `<td>${ip.locked_until}</td>`;
                        html += `<td>${ip.minutes_remaining} Minuten</td>`;
                        html += `<td>${ip.failed_attempt_count}</td>`;
                        html += `<td><button class="btn btn-sm btn-secondary" onclick="unlockIP('${ip.ip_address}')">🔓 Entsperren</button></td>`;
                        html += '</tr>';
                    });
                    
                    html += '</tbody></table>';
                    container.innerHTML = html;
                }
            })
            .catch(error => {
                console.error('Fehler beim Laden der gesperrten IPs:', error);
            });
        }

        // IP entsperren
        function unlockIP(ipAddress) {
            if (!confirm(`IP-Adresse ${ipAddress} wirklich entsperren?`)) {
                return;
            }
            
            showLoading('IP wird entsperrt...');
            
            fetch('audit_log.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=unlock_ip&ip_address=${encodeURIComponent(ipAddress)}`
            })
            .then(response => response.json())
            .then(data => {
                hideLoading();
                if (data.success) {
                    showNotification('✅ ' + data.message, 'success');
                    refreshLockedIPs();
                } else {
                    showNotification('❌ ' + data.message, 'error');
                }
            })
            .catch(error => {
                hideLoading();
                showNotification('❌ Fehler beim Entsperren der IP', 'error');
            });
        }

        // Audit-Log durchsuchen
        document.getElementById('search-form').addEventListener('submit', function(e) {
            e.preventDefault();
            searchAuditLog();
        });

        function searchAuditLog() {
            const formData = new FormData(document.getElementById('search-form'));
            formData.append('action', 'search_audit_log');
            formData.append('limit', document.getElementById('limit-select').value);
            formData.append('offset', 0);
            
            showLoading('Durchsuche Audit-Log...');
            
            fetch('audit_log.php', {
                method: 'POST',
                body: new URLSearchParams(formData)
            })
            .then(response => response.json())
            .then(data => {
                hideLoading();
                if (data.success) {
                    displayResults(data.results);
                    document.getElementById('results-count').textContent = `${data.count} Einträge`;
                } else {
                    showNotification('❌ ' + data.message, 'error');
                }
            })
            .catch(error => {
                hideLoading();
                showNotification('❌ Fehler bei der Suche', 'error');
            });
        }

        // Ergebnisse anzeigen
        function displayResults(results) {
            const tbody = document.getElementById('audit-results');
            
            if (results.length === 0) {
                tbody.innerHTML = '<tr><td colspan="8" class="no-results">Keine Einträge gefunden</td></tr>';
                return;
            }
            
            let html = '';
            results.forEach(row => {
                const severityClass = `severity-${row.severity}`;
                const hasDetails = row.details && row.details !== 'null';
                
                html += '<tr>';
                html += `<td>${row.id}</td>`;
                html += `<td>${row.created_at}</td>`;
                html += `<td>${row.username}</td>`;
                html += `<td>${row.ip_address}</td>`;
                html += `<td><code>${row.action}</code></td>`;
                html += `<td>${row.description || '-'}</td>`;
                html += `<td><span class="severity-badge ${severityClass}">${row.severity}</span></td>`;
                html += '<td>';
                
                if (hasDetails) {
                    const detailsId = `details-${row.id}`;
                    html += `<span class="details-toggle" onclick="toggleDetails('${detailsId}')">Details anzeigen</span>`;
                    html += `<div id="${detailsId}" class="details-content">${JSON.stringify(row.details_parsed, null, 2)}</div>`;
                } else {
                    html += '-';
                }
                
                html += '</td>';
                html += '</tr>';
            });
            
            tbody.innerHTML = html;
        }

        // Details ein-/ausblenden
        function toggleDetails(elementId) {
            const element = document.getElementById(elementId);
            element.classList.toggle('show');
        }

        // Filter zurücksetzen
        function resetFilters() {
            document.getElementById('search-form').reset();
            
            const today = new Date();
            const yesterday = new Date(today);
            yesterday.setDate(yesterday.getDate() - 1);
            
            document.getElementById('filter-date-from').value = yesterday.toISOString().split('T')[0];
            document.getElementById('filter-date-to').value = today.toISOString().split('T')[0];
            
            searchAuditLog();
        }

        // Export
        function exportAuditLog() {
            if (!confirm('Audit-Log mit aktuellen Filtern als CSV exportieren?')) {
                return;
            }
            
            const formData = new FormData(document.getElementById('search-form'));
            formData.append('action', 'export_audit_log');
            
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'audit_log.php';
            
            for (let [key, value] of formData.entries()) {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = key;
                input.value = value;
                form.appendChild(input);
            }
            
            document.body.appendChild(form);
            form.submit();
            document.body.removeChild(form);
            
            showNotification('📥 Export wird heruntergeladen...', 'info');
        }

        // Alte Logs löschen
        function cleanupOldLogs() {
            const days = prompt('Logs älter als wie viele Tage sollen gelöscht werden?', '<?= AUDIT_LOG_RETENTION_DAYS ?>');
            
            if (days === null) {
                return;
            }
            
            const daysInt = parseInt(days);
            if (isNaN(daysInt) || daysInt < 1) {
                showNotification('❌ Ungültige Eingabe', 'error');
                return;
            }
            
            if (!confirm(`Wirklich alle Logs löschen die älter als ${daysInt} Tage sind?`)) {
                return;
            }
            
            showLoading('Lösche alte Logs...');
            
            fetch('audit_log.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=cleanup_old_logs&retention_days=${daysInt}`
            })
            .then(response => response.json())
            .then(data => {
                hideLoading();
                if (data.success) {
                    showNotification(`✅ ${data.message}: ${data.deleted_rows} Zeilen und ${data.deleted_files} Dateien gelöscht`, 'success');
                    loadStatistics();
                    searchAuditLog();
                } else {
                    showNotification('❌ ' + data.message, 'error');
                }
            })
            .catch(error => {
                hideLoading();
                showNotification('❌ Fehler beim Löschen', 'error');
            });
        }

        // Hilfsfunktionen
        function showLoading(text) {
            document.getElementById('loading-text').textContent = text;
            document.getElementById('loading-overlay').style.display = 'flex';
        }

        function hideLoading() {
            document.getElementById('loading-overlay').style.display = 'none';
        }

        function showNotification(message, type) {
            const container = document.getElementById('notification-container');
            const notification = document.createElement('div');
            notification.className = `notification ${type}`;
            notification.textContent = message;
            
            container.appendChild(notification);
            
            setTimeout(() => {
                notification.style.animation = 'slideOut 0.3s ease-out forwards';
                setTimeout(() => notification.remove(), 300);
            }, 5000);
        }
    </script>
</body>
</html>