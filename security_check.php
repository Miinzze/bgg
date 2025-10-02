<?php
/**
 * Sicherheits-Check Script
 * Prüft ob alle Sicherheitsmaßnahmen korrekt implementiert sind
 * 
 * WICHTIG: Lösche diese Datei nach dem Test oder schütze sie mit Passwort!
 */

// Passwort-Schutz (ändere das Passwort!)
$SECURITY_PASSWORD = 'oschman17';

if (!isset($_GET['password']) || $_GET['password'] !== $SECURITY_PASSWORD) {
    http_response_code(403);
    die('Zugriff verweigert. Nutze: ?password=your-secure-password-here');
}

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sicherheits-Check</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 20px;
            min-height: 100vh;
        }
        .container {
            max-width: 900px;
            margin: 0 auto;
            background: white;
            border-radius: 12px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            overflow: hidden;
        }
        .header {
            background: linear-gradient(135deg, #2d5016, #1a300a);
            color: white;
            padding: 30px;
            text-align: center;
        }
        .header h1 { font-size: 28px; margin-bottom: 10px; }
        .header p { opacity: 0.9; font-size: 14px; }
        .content { padding: 30px; }
        .check-group {
            margin-bottom: 30px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            overflow: hidden;
        }
        .check-group-header {
            background: #f5f5f5;
            padding: 15px 20px;
            font-weight: 700;
            font-size: 16px;
            color: #333;
            border-bottom: 2px solid #e0e0e0;
        }
        .check-item {
            padding: 15px 20px;
            border-bottom: 1px solid #f0f0f0;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .check-item:last-child { border-bottom: none; }
        .check-label {
            flex: 1;
            font-size: 14px;
            color: #555;
        }
        .check-status {
            padding: 6px 16px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .status-ok {
            background: #4caf50;
            color: white;
        }
        .status-warning {
            background: #ff9800;
            color: white;
        }
        .status-error {
            background: #f44336;
            color: white;
        }
        .status-info {
            background: #2196f3;
            color: white;
        }
        .score-card {
            background: linear-gradient(135deg, #6bb032, #4a7c2a);
            color: white;
            padding: 30px;
            border-radius: 8px;
            text-align: center;
            margin-bottom: 30px;
        }
        .score-value {
            font-size: 72px;
            font-weight: 700;
            margin: 10px 0;
        }
        .score-label {
            font-size: 18px;
            opacity: 0.9;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .recommendation {
            background: #fff3cd;
            border-left: 4px solid #ff9800;
            padding: 15px 20px;
            margin-top: 20px;
            border-radius: 4px;
        }
        .recommendation h3 {
            color: #ff6f00;
            margin-bottom: 10px;
            font-size: 16px;
        }
        .recommendation ul {
            margin-left: 20px;
            color: #666;
            font-size: 14px;
            line-height: 1.8;
        }
        .footer {
            text-align: center;
            padding: 20px;
            color: #999;
            font-size: 12px;
            border-top: 1px solid #e0e0e0;
        }
        .detail {
            font-size: 12px;
            color: #999;
            margin-top: 5px;
            font-family: monospace;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🔒 Sicherheits-Check</h1>
            <p>Automatische Überprüfung der Sicherheitseinstellungen</p>
        </div>
        
        <div class="content">
            <?php
            $checks = [];
            $score = 0;
            $maxScore = 0;
            
            // CHECK 1: .env Datei existiert
            $maxScore += 10;
            if (file_exists('.env')) {
                $checks['env']['exists'] = [
                    'label' => '.env Datei existiert',
                    'status' => 'ok',
                    'detail' => 'Gefunden: .env',
                    'points' => 10
                ];
                $score += 10;
            } else {
                $checks['env']['exists'] = [
                    'label' => '.env Datei existiert',
                    'status' => 'error',
                    'detail' => 'Nicht gefunden - KRITISCH!',
                    'points' => 0
                ];
            }
            
            // CHECK 2: .env Dateiberechtigungen
            $maxScore += 10;
            if (file_exists('.env')) {
                $perms = substr(sprintf('%o', fileperms('.env')), -4);
                if ($perms === '0600' || $perms === '0400') {
                    $checks['env']['permissions'] = [
                        'label' => '.env Dateiberechtigungen',
                        'status' => 'ok',
                        'detail' => "Korrekt: $perms",
                        'points' => 10
                    ];
                    $score += 10;
                } else {
                    $checks['env']['permissions'] = [
                        'label' => '.env Dateiberechtigungen',
                        'status' => 'warning',
                        'detail' => "Aktuell: $perms - Empfohlen: 0600",
                        'points' => 5
                    ];
                    $score += 5;
                }
            } else {
                $checks['env']['permissions'] = [
                    'label' => '.env Dateiberechtigungen',
                    'status' => 'error',
                    'detail' => 'Datei nicht vorhanden',
                    'points' => 0
                ];
            }
            
            // CHECK 3: config.php enthält keine Credentials
            $maxScore += 15;
            $configContent = file_get_contents('config.php');
            $hasCredentials = (
                strpos($configContent, "DB_PASS', '") !== false &&
                strpos($configContent, "env('DB_PASS')") === false
            );
            
            if (!$hasCredentials) {
                $checks['config']['credentials'] = [
                    'label' => 'Keine Credentials in config.php',
                    'status' => 'ok',
                    'detail' => 'Credentials sicher in .env',
                    'points' => 15
                ];
                $score += 15;
            } else {
                $checks['config']['credentials'] = [
                    'label' => 'Keine Credentials in config.php',
                    'status' => 'error',
                    'detail' => 'KRITISCH: Credentials im Code gefunden!',
                    'points' => 0
                ];
            }
            
            // CHECK 4: env_loader.php existiert
            $maxScore += 5;
            if (file_exists('env_loader.php')) {
                $checks['config']['env_loader'] = [
                    'label' => 'env_loader.php vorhanden',
                    'status' => 'ok',
                    'detail' => 'ENV Loader gefunden',
                    'points' => 5
                ];
                $score += 5;
            } else {
                $checks['config']['env_loader'] = [
                    'label' => 'env_loader.php vorhanden',
                    'status' => 'error',
                    'detail' => 'Nicht gefunden',
                    'points' => 0
                ];
            }
            
            // CHECK 5: Upload-Verzeichnisse geschützt
            $maxScore += 10;
            $uploadProtected = file_exists('uploads/.htaccess');
            $backgroundProtected = file_exists('background/.htaccess');
            
            if ($uploadProtected && $backgroundProtected) {
                $checks['security']['uploads'] = [
                    'label' => 'Upload-Verzeichnisse geschützt',
                    'status' => 'ok',
                    'detail' => '.htaccess Dateien vorhanden',
                    'points' => 10
                ];
                $score += 10;
            } elseif ($uploadProtected || $backgroundProtected) {
                $checks['security']['uploads'] = [
                    'label' => 'Upload-Verzeichnisse geschützt',
                    'status' => 'warning',
                    'detail' => 'Teilweise geschützt',
                    'points' => 5
                ];
                $score += 5;
            } else {
                $checks['security']['uploads'] = [
                    'label' => 'Upload-Verzeichnisse geschützt',
                    'status' => 'warning',
                    'detail' => 'Kein .htaccess Schutz',
                    'points' => 0
                ];
            }
            
            // CHECK 6: PHP Version
            $maxScore += 10;
            $phpVersion = PHP_VERSION;
            $versionOk = version_compare($phpVersion, '7.4.0', '>=');
            
            if ($versionOk) {
                $checks['system']['php'] = [
                    'label' => 'PHP Version',
                    'status' => 'ok',
                    'detail' => "Version: $phpVersion",
                    'points' => 10
                ];
                $score += 10;
            } else {
                $checks['system']['php'] = [
                    'label' => 'PHP Version',
                    'status' => 'warning',
                    'detail' => "Version: $phpVersion - Update empfohlen",
                    'points' => 5
                ];
                $score += 5;
            }
            
            // CHECK 7: Error Reporting
            $maxScore += 5;
            $displayErrors = ini_get('display_errors');
            if ($displayErrors === '0' || $displayErrors === '' || $displayErrors === false) {
                $checks['system']['errors'] = [
                    'label' => 'Error Display deaktiviert',
                    'status' => 'ok',
                    'detail' => 'Fehler werden nicht angezeigt (Produktion)',
                    'points' => 5
                ];
                $score += 5;
            } else {
                $checks['system']['errors'] = [
                    'label' => 'Error Display deaktiviert',
                    'status' => 'warning',
                    'detail' => 'Fehler werden angezeigt (Entwicklung)',
                    'points' => 0
                ];
            }
            
            // CHECK 8: Session Sicherheit
            $maxScore += 10;
            $sessionSecure = (
                ini_get('session.cookie_httponly') == '1' &&
                ini_get('session.use_strict_mode') == '1'
            );
            
            if ($sessionSecure) {
                $checks['security']['session'] = [
                    'label' => 'Session-Sicherheit',
                    'status' => 'ok',
                    'detail' => 'HTTPOnly und Strict Mode aktiv',
                    'points' => 10
                ];
                $score += 10;
            } else {
                $checks['security']['session'] = [
                    'label' => 'Session-Sicherheit',
                    'status' => 'warning',
                    'detail' => 'Session-Einstellungen könnten verbessert werden',
                    'points' => 5
                ];
                $score += 5;
            }
            
            // CHECK 9: HTTPS
            $maxScore += 10;
            $isHttps = (
                (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
                $_SERVER['SERVER_PORT'] == 443 ||
                (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
            );
            
            if ($isHttps) {
                $checks['security']['https'] = [
                    'label' => 'HTTPS aktiviert',
                    'status' => 'ok',
                    'detail' => 'Sichere Verbindung',
                    'points' => 10
                ];
                $score += 10;
            } else {
                $checks['security']['https'] = [
                    'label' => 'HTTPS aktiviert',
                    'status' => 'warning',
                    'detail' => 'HTTP - SSL-Zertifikat empfohlen',
                    'points' => 0
                ];
            }
            
            // CHECK 10: .gitignore vorhanden
            $maxScore += 5;
            if (file_exists('.gitignore')) {
                $gitignoreContent = file_get_contents('.gitignore');
                $protectsEnv = strpos($gitignoreContent, '.env') !== false;
                
                if ($protectsEnv) {
                    $checks['config']['gitignore'] = [
                        'label' => '.gitignore schützt .env',
                        'status' => 'ok',
                        'detail' => '.env wird von Git ignoriert',
                        'points' => 5
                    ];
                    $score += 5;
                } else {
                    $checks['config']['gitignore'] = [
                        'label' => '.gitignore schützt .env',
                        'status' => 'warning',
                        'detail' => '.env sollte in .gitignore sein',
                        'points' => 2
                    ];
                    $score += 2;
                }
            } else {
                $checks['config']['gitignore'] = [
                    'label' => '.gitignore vorhanden',
                    'status' => 'info',
                    'detail' => 'Nicht gefunden (optional wenn kein Git)',
                    'points' => 0
                ];
            }
            
            // CHECK 11: Log-Verzeichnis beschreibbar
            $maxScore += 5;
            $logsWritable = is_writable('logs');
            if ($logsWritable) {
                $checks['system']['logs'] = [
                    'label' => 'Log-Verzeichnis beschreibbar',
                    'status' => 'ok',
                    'detail' => 'Logs können geschrieben werden',
                    'points' => 5
                ];
                $score += 5;
            } else {
                $checks['system']['logs'] = [
                    'label' => 'Log-Verzeichnis beschreibbar',
                    'status' => 'error',
                    'detail' => 'Verzeichnis nicht beschreibbar',
                    'points' => 0
                ];
            }
            
            // Prozent berechnen
            $percentage = round(($score / $maxScore) * 100);
            
            // Score-Bewertung
            $scoreRating = 'Kritisch';
            $scoreColor = '#f44336';
            if ($percentage >= 90) {
                $scoreRating = 'Exzellent';
                $scoreColor = '#4caf50';
            } elseif ($percentage >= 75) {
                $scoreRating = 'Gut';
                $scoreColor = '#6bb032';
            } elseif ($percentage >= 60) {
                $scoreRating = 'Ausreichend';
                $scoreColor = '#ff9800';
            } elseif ($percentage >= 40) {
                $scoreRating = 'Mangelhaft';
                $scoreColor = '#ff5722';
            }
            ?>
            
            <div class="score-card" style="background: linear-gradient(135deg, <?= $scoreColor ?>, <?= $scoreColor ?>dd);">
                <div class="score-label">Sicherheits-Score</div>
                <div class="score-value"><?= $percentage ?>%</div>
                <div class="score-label"><?= $scoreRating ?></div>
                <div style="margin-top: 15px; font-size: 14px; opacity: 0.9;">
                    <?= $score ?> von <?= $maxScore ?> Punkten erreicht
                </div>
            </div>
            
            <div class="check-group">
                <div class="check-group-header">🔐 Umgebungsvariablen & Konfiguration</div>
                <?php foreach ($checks['env'] ?? [] as $check): ?>
                    <div class="check-item">
                        <div class="check-label">
                            <?= htmlspecialchars($check['label']) ?>
                            <div class="detail"><?= htmlspecialchars($check['detail']) ?></div>
                        </div>
                        <span class="check-status status-<?= $check['status'] ?>"><?= strtoupper($check['status']) ?></span>
                    </div>
                <?php endforeach; ?>
                
                <?php foreach ($checks['config'] ?? [] as $check): ?>
                    <div class="check-item">
                        <div class="check-label">
                            <?= htmlspecialchars($check['label']) ?>
                            <div class="detail"><?= htmlspecialchars($check['detail']) ?></div>
                        </div>
                        <span class="check-status status-<?= $check['status'] ?>"><?= strtoupper($check['status']) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <div class="check-group">
                <div class="check-group-header">🛡️ Sicherheitseinstellungen</div>
                <?php foreach ($checks['security'] ?? [] as $check): ?>
                    <div class="check-item">
                        <div class="check-label">
                            <?= htmlspecialchars($check['label']) ?>
                            <div class="detail"><?= htmlspecialchars($check['detail']) ?></div>
                        </div>
                        <span class="check-status status-<?= $check['status'] ?>"><?= strtoupper($check['status']) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <div class="check-group">
                <div class="check-group-header">⚙️ System-Einstellungen</div>
                <?php foreach ($checks['system'] ?? [] as $check): ?>
                    <div class="check-item">
                        <div class="check-label">
                            <?= htmlspecialchars($check['label']) ?>
                            <div class="detail"><?= htmlspecialchars($check['detail']) ?></div>
                        </div>
                        <span class="check-status status-<?= $check['status'] ?>"><?= strtoupper($check['status']) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <?php if ($percentage < 90): ?>
            <div class="recommendation">
                <h3>⚠️ Empfohlene Maßnahmen:</h3>
                <ul>
                    <?php if (!file_exists('.env')): ?>
                        <li><strong>KRITISCH:</strong> Erstelle eine .env Datei aus .env.example</li>
                    <?php endif; ?>
                    
                    <?php if (file_exists('.env') && (substr(sprintf('%o', fileperms('.env')), -4) !== '0600')): ?>
                        <li>Setze .env Dateiberechtigungen: <code>chmod 600 .env</code></li>
                    <?php endif; ?>
                    
                    <?php if ($hasCredentials): ?>
                        <li><strong>KRITISCH:</strong> Entferne Datenbankzugangsdaten aus config.php</li>
                    <?php endif; ?>
                    
                    <?php if (!$uploadProtected || !$backgroundProtected): ?>
                        <li>Erstelle .htaccess Dateien in Upload-Verzeichnissen</li>
                    <?php endif; ?>
                    
                    <?php if (!$isHttps): ?>
                        <li>Aktiviere HTTPS mit einem SSL-Zertifikat (z.B. Let's Encrypt)</li>
                    <?php endif; ?>
                    
                    <?php if ($displayErrors !== '0'): ?>
                        <li>Deaktiviere Error Display für Produktion in php.ini oder .htaccess</li>
                    <?php endif; ?>
                    
                    <?php if (!$versionOk): ?>
                        <li>Update PHP auf Version 7.4 oder höher</li>
                    <?php endif; ?>
                    
                    <?php if (!$sessionSecure): ?>
                        <li>Aktiviere Session-Sicherheit in php.ini oder config.php</li>
                    <?php endif; ?>
                </ul>
            </div>
            <?php else: ?>
            <div class="recommendation" style="background: #e8f5e9; border-left-color: #4caf50;">
                <h3 style="color: #2e7d32;">✅ Hervorragend!</h3>
                <ul>
                    <li>Alle kritischen Sicherheitsmaßnahmen sind implementiert</li>
                    <li>Überprüfe regelmäßig auf Updates</li>
                    <li>Führe regelmäßige Backups durch</li>
                    <li>Überwache die Logs auf verdächtige Aktivitäten</li>
                </ul>
            </div>
            <?php endif; ?>
            
            <div class="recommendation" style="background: #e3f2fd; border-left-color: #2196f3;">
                <h3 style="color: #1565c0;">ℹ️ Zusätzliche Empfehlungen:</h3>
                <ul>
                    <li>Aktiviere OPcache für bessere Performance</li>
                    <li>Implementiere Datenbank-Indizes (siehe INSTALLATION.md)</li>
                    <li>Richte automatische Backups ein</li>
                    <li>Erwäge Redis/Memcached für Session-Speicherung</li>
                    <li>Aktiviere HTTP/2 auf dem Webserver</li>
                    <li>Komprimiere JavaScript und CSS in Produktion</li>
                    <li>Implementiere Content Security Policy (CSP) Headers</li>
                    <li>Nutze Fail2Ban gegen Brute-Force-Angriffe</li>
                </ul>
            </div>
            
            <div style="margin-top: 30px; padding: 20px; background: #fff3cd; border-radius: 8px; border-left: 4px solid #ff9800;">
                <h3 style="color: #ff6f00; margin-bottom: 10px; font-size: 16px;">⚠️ WICHTIG: Nach dem Test</h3>
                <p style="color: #666; font-size: 14px; line-height: 1.6;">
                    <strong>Lösche diese Datei (security_check.php) nach dem Test!</strong><br>
                    Sie enthält sensible Informationen über dein System und sollte nicht öffentlich zugänglich sein.
                </p>
                <p style="color: #666; font-size: 14px; margin-top: 10px;">
                    Oder schütze sie dauerhaft mit einem starken Passwort und ändere die Variable <code>$SECURITY_PASSWORD</code> am Anfang der Datei.
                </p>
            </div>
        </div>
        
        <div class="footer">
            <p>Security Check v2.0 | Generiert am <?= date('d.m.Y H:i:s') ?></p>
            <p style="margin-top: 5px;">Server: <?= htmlspecialchars($_SERVER['SERVER_SOFTWARE'] ?? 'Unknown') ?> | PHP <?= PHP_VERSION ?></p>
        </div>
    </div>
</body>
</html>