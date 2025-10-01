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

// Alle gefilterten Einträge holen (ohne Limit)
$entries = AuditLog::getEntries($filters, 10000, 0);

// Log Export
AuditLog::log('audit_log', 'export', null, [
    'filters' => $filters,
    'entry_count' => count($entries)
]);

// CSV Header
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="audit_log_' . date('Y-m-d_His') . '.csv"');

// BOM für UTF-8
echo "\xEF\xBB\xBF";

$output = fopen('php://output', 'w');

// CSV Header
fputcsv($output, [
    'Datum/Zeit',
    'Benutzer',
    'Entitätstyp',
    'Entitätsname',
    'Aktion',
    'Änderungen',
    'IP-Adresse',
    'User-Agent'
], ';');

// Daten
foreach ($entries as $entry) {
    fputcsv($output, [
        date('d.m.Y H:i:s', strtotime($entry['created_at'])),
        $entry['username'] ?? 'System',
        AuditLog::formatEntityType($entry['entity_type']),
        $entry['entity_name'] ?? '',
        AuditLog::formatAction($entry['action']),
        $entry['changes'] ?? '',
        $entry['ip_address'],
        $entry['user_agent']
    ], ';');
}

fclose($output);
exit;
?>