<?php
/**
 * Login-Handler mit erweiterten Sicherheitsfunktionen
 * - Login-Versuchs-Limitierung
 * - Audit-Logging
 * - Session-Timeout-Prüfung
 */

require_once 'config.php';

header('Content-Type: application/json');

// Bereits eingeloggt?
if (Auth::isLoggedIn()) {
    echo json_encode(['success' => true, 'message' => 'Bereits angemeldet']);
    exit;
}

// Session-Timeout Nachricht anzeigen
if (isset($_SESSION['session_timeout']) && $_SESSION['session_timeout'] === true) {
    unset($_SESSION['session_timeout']);
    echo json_encode([
        'success' => false, 
        'message' => 'Ihre Sitzung ist abgelaufen. Bitte melden Sie sich erneut an.',
        'timeout' => true
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Methode nicht erlaubt']);
    exit;
}

$username = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';

// Validierung
if (empty($username) || empty($password)) {
    echo json_encode(['success' => false, 'message' => 'Benutzername und Passwort erforderlich']);
    exit;
}

try {
    // Login-Versuch durchführen
    $result = Auth::login($username, $password);
    
    if ($result['success']) {
        // Erfolgreicher Login
        echo json_encode([
            'success' => true,
            'message' => 'Anmeldung erfolgreich',
            'redirect' => 'index.php',
            'user' => [
                'username' => Auth::getUsername(),
                'role' => Auth::getRoleDisplayName()
            ]
        ]);
    } else {
        // Fehlgeschlagener Login
        http_response_code(401);
        echo json_encode($result);
    }
    
} catch (Exception $e) {
    error_log("Login error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Ein Fehler ist aufgetreten. Bitte versuchen Sie es später erneut.'
    ]);
}
?>