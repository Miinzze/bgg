-- Rollen Tabelle
CREATE TABLE roles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) UNIQUE NOT NULL,
    display_name VARCHAR(100) NOT NULL,
    description TEXT,
    is_system_role BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Berechtigungen Tabelle
CREATE TABLE permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) UNIQUE NOT NULL,
    display_name VARCHAR(100) NOT NULL,
    description TEXT,
    category VARCHAR(50) DEFAULT 'general',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_name (name),
    INDEX idx_category (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rollen-Berechtigungen Zuordnungstabelle
CREATE TABLE role_permissions (
    role_id INT NOT NULL,
    permission_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (role_id, permission_id),
    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
    FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Benutzer Tabelle
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    role_id INT NOT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_login TIMESTAMP NULL,
    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE RESTRICT,
    INDEX idx_username (username),
    INDEX idx_role (role_id),
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- System Settings Tabelle
CREATE TABLE system_settings (
    setting_key VARCHAR(100) PRIMARY KEY,
    setting_value TEXT,
    setting_type ENUM('string', 'int', 'float', 'boolean') DEFAULT 'string',
    updated_by INT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Map Objects Tabelle - ERWEITERT MIT WARTUNGSINTERVALL
CREATE TABLE map_objects (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    image_path VARCHAR(255),
    x_percent DECIMAL(6,3) NOT NULL DEFAULT 50.000,
    y_percent DECIMAL(6,3) NOT NULL DEFAULT 50.000,
    x_position FLOAT DEFAULT NULL,
    y_position FLOAT DEFAULT NULL,
    width FLOAT DEFAULT 200,
    height FLOAT DEFAULT 150,
    status ENUM('available', 'rented', 'maintenance', 'inactive') DEFAULT 'available',
    category VARCHAR(50) DEFAULT 'general',
    
    -- NEU: Wartungsfelder
    last_maintenance DATE NULL COMMENT 'Datum der letzten Wartung',
    maintenance_interval_days INT DEFAULT NULL COMMENT 'Wartungsintervall in Tagen (z.B. 180 für 6 Monate)',
    next_maintenance_due DATE NULL COMMENT 'Berechnetes Datum der nächsten fälligen Wartung',
    maintenance_notification_sent BOOLEAN DEFAULT FALSE COMMENT 'Wurde Benachrichtigung gesendet',
    
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_status (status),
    INDEX idx_category (category),
    INDEX idx_coordinates (x_percent, y_percent),
    INDEX idx_next_maintenance (next_maintenance_due),
    INDEX idx_maintenance_status (status, next_maintenance_due)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Background Images Tabelle
CREATE TABLE background_images (
    id INT AUTO_INCREMENT PRIMARY KEY,
    filename VARCHAR(255) NOT NULL,
    original_width INT NOT NULL,
    original_height INT NOT NULL,
    file_size INT NOT NULL,
    upload_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    uploaded_by INT,
    is_active BOOLEAN DEFAULT TRUE,
    FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- NEU: Wartungshistorie Tabelle
CREATE TABLE maintenance_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    object_id INT NOT NULL,
    maintenance_date DATE NOT NULL,
    performed_by INT NULL,
    notes TEXT,
    was_automatic BOOLEAN DEFAULT FALSE COMMENT 'Wurde automatisch auf Wartung gesetzt',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (object_id) REFERENCES map_objects(id) ON DELETE CASCADE,
    FOREIGN KEY (performed_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_object (object_id),
    INDEX idx_date (maintenance_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Standard-Berechtigungen einfügen
INSERT INTO permissions (name, display_name, description, category) VALUES
-- Marker-Berechtigungen
('marker.create', 'Marker erstellen', 'Berechtigung zum Erstellen neuer Marker auf der Karte', 'marker'),
('marker.edit', 'Marker bearbeiten', 'Berechtigung zum Bearbeiten bestehender Marker', 'marker'),
('marker.delete', 'Marker löschen', 'Berechtigung zum Löschen von Markern', 'marker'),
('marker.change_status', 'Marker Status ändern', 'Berechtigung zum Ändern des Status von Markern', 'marker'),
('marker.view', 'Marker ansehen', 'Berechtigung zum Ansehen von Markern', 'marker'),

-- Einstellungen-Berechtigungen
('settings.edit', 'Einstellungen ändern', 'Berechtigung zum Ändern der Systemeinstellungen', 'settings'),
('settings.view', 'Einstellungen ansehen', 'Berechtigung zum Ansehen der Systemeinstellungen', 'settings'),

-- Benutzer-Berechtigungen
('user.create', 'Benutzer anlegen', 'Berechtigung zum Anlegen neuer Benutzer', 'user'),
('user.edit', 'Benutzer bearbeiten', 'Berechtigung zum Bearbeiten bestehender Benutzer', 'user'),
('user.delete', 'Benutzer löschen', 'Berechtigung zum Löschen von Benutzern', 'user'),
('user.view', 'Benutzer ansehen', 'Berechtigung zum Ansehen der Benutzerliste', 'user'),

-- Rollen-Berechtigungen
('role.create', 'Rolle anlegen', 'Berechtigung zum Anlegen neuer Rollen', 'role'),
('role.edit', 'Rolle bearbeiten', 'Berechtigung zum Bearbeiten bestehender Rollen', 'role'),
('role.delete', 'Rolle löschen', 'Berechtigung zum Löschen von Rollen', 'role'),
('role.view', 'Rollen ansehen', 'Berechtigung zum Ansehen der Rollenliste', 'role');

-- Standard-Rollen erstellen
INSERT INTO roles (name, display_name, description, is_system_role) VALUES
('administrator', 'Administrator', 'Vollzugriff auf alle Funktionen des Systems', TRUE),
('manager', 'Manager', 'Kann Marker und Einstellungen verwalten', FALSE),
('user', 'Benutzer', 'Kann Marker ansehen und Status ändern', FALSE),
('viewer', 'Betrachter', 'Kann nur Marker ansehen', FALSE);

-- Berechtigungen den Rollen zuweisen
INSERT INTO role_permissions (role_id, permission_id)
SELECT 
    (SELECT id FROM roles WHERE name = 'administrator'),
    id
FROM permissions;

INSERT INTO role_permissions (role_id, permission_id)
SELECT 
    (SELECT id FROM roles WHERE name = 'manager'),
    id
FROM permissions
WHERE name IN (
    'marker.create', 'marker.edit', 'marker.delete', 'marker.change_status', 'marker.view',
    'settings.edit', 'settings.view',
    'user.view'
);

INSERT INTO role_permissions (role_id, permission_id)
SELECT 
    (SELECT id FROM roles WHERE name = 'user'),
    id
FROM permissions
WHERE name IN (
    'marker.view', 'marker.change_status'
);

INSERT INTO role_permissions (role_id, permission_id)
SELECT 
    (SELECT id FROM roles WHERE name = 'viewer'),
    id
FROM permissions
WHERE name IN ('marker.view');

-- Standard Admin-Benutzer erstellen (Passwort: admin123)
INSERT INTO users (username, password, role_id) VALUES 
('admin', '$2y$10$2VKoQJSLSKHcjW8YN9jJeu8RpVcg6P6WBmgXz1n5YpFRzq0vc5GR6', 
 (SELECT id FROM roles WHERE name = 'administrator'));

-- Standard-Systemeinstellungen
INSERT INTO system_settings (setting_key, setting_value, setting_type, updated_by) VALUES
('marker_size', '24', 'int', 1),
('marker_border_width', '3', 'int', 1),
('show_legend', 'true', 'boolean', 1),
('show_coordinates', 'true', 'boolean', 1),
('enable_marker_pulse', 'true', 'boolean', 1),
('marker_hover_scale', '1.3', 'float', 1),
('tooltip_delay', '0', 'int', 1),
('background_blur_admin', 'false', 'boolean', 1),
('auto_save_interval', '30', 'int', 1),
('enable_notifications', 'true', 'boolean', 1),
('marker_shadow_intensity', '0.3', 'float', 1),
('interface_theme', 'auto', 'string', 1),
('maintenance_check_enabled', 'true', 'boolean', 1),
('maintenance_notification_days', '7', 'int', 1);

-- Beispiel-Objekte MIT WARTUNGSDATEN
INSERT INTO map_objects (title, description, x_percent, y_percent, status, category, created_by, last_maintenance, maintenance_interval_days, next_maintenance_due) VALUES 
('Generator A1', 'Mobile Stromerzeuger 15kW', 25.500, 35.750, 'available', 'generator', 1, '2024-09-01', 180, DATE_ADD('2024-09-01', INTERVAL 180 DAY)),
('Lichtmast B2', 'LED Lichtmastanlage 4x1000W', 65.250, 22.100, 'rented', 'lighting', 1, '2024-08-15', 90, DATE_ADD('2024-08-15', INTERVAL 90 DAY)),
('Container C3', 'Containeraggregat 50kW', 45.800, 68.400, 'available', 'generator', 1, '2024-07-20', 180, DATE_ADD('2024-07-20', INTERVAL 180 DAY)),
('Generator D4', 'Notstromversorgung 25kW', 78.900, 15.600, 'available', 'generator', 1, '2024-06-10', 180, DATE_ADD('2024-06-10', INTERVAL 180 DAY)),
('Beleuchtung E5', 'Mobile LED-Beleuchtung', 15.300, 82.750, 'rented', 'lighting', 1, '2024-09-10', 90, DATE_ADD('2024-09-10', INTERVAL 90 DAY)),
('Audio-Anlage F6', 'PA-System 2000W', 55.600, 44.200, 'maintenance', 'audio', 1, '2024-05-01', 120, DATE_ADD('2024-05-01', INTERVAL 120 DAY)),
('Werkzeugkoffer G7', 'Elektriker-Werkzeugset', 35.400, 58.800, 'available', 'tools', 1, NULL, NULL, NULL),
('Kabeltrommel H8', 'Verlängerungskabel 50m', 85.200, 75.300, 'available', 'tools', 1, NULL, NULL, NULL);

-- Trigger für automatische Berechnung des nächsten Wartungsdatums
DELIMITER //

CREATE TRIGGER calculate_next_maintenance_before_insert
BEFORE INSERT ON map_objects
FOR EACH ROW
BEGIN
    IF NEW.last_maintenance IS NOT NULL AND NEW.maintenance_interval_days IS NOT NULL THEN
        SET NEW.next_maintenance_due = DATE_ADD(NEW.last_maintenance, INTERVAL NEW.maintenance_interval_days DAY);
    END IF;
END//

CREATE TRIGGER calculate_next_maintenance_before_update
BEFORE UPDATE ON map_objects
FOR EACH ROW
BEGIN
    IF NEW.last_maintenance IS NOT NULL AND NEW.maintenance_interval_days IS NOT NULL THEN
        SET NEW.next_maintenance_due = DATE_ADD(NEW.last_maintenance, INTERVAL NEW.maintenance_interval_days DAY);
    ELSE
        SET NEW.next_maintenance_due = NULL;
    END IF;
END//

DELIMITER ;

-- Trigger für automatische Historie bei Status-Wechsel auf Wartung
DELIMITER //

CREATE TRIGGER log_maintenance_status_change
AFTER UPDATE ON map_objects
FOR EACH ROW
BEGIN
    IF OLD.status != 'maintenance' AND NEW.status = 'maintenance' THEN
        INSERT INTO maintenance_history (object_id, maintenance_date, was_automatic)
        VALUES (NEW.id, CURDATE(), TRUE);
    END IF;
END//

DELIMITER ;

-- Views
CREATE VIEW active_objects_view AS
SELECT 
    id, title, description, image_path,
    x_percent, y_percent, status, category,
    last_maintenance, maintenance_interval_days, next_maintenance_due,
    created_by, created_at, updated_at,
    ROUND((x_percent / 100) * 1200) as x_pixel_1200,
    ROUND((y_percent / 100) * 800) as y_pixel_800,
    CASE 
        WHEN next_maintenance_due IS NOT NULL AND next_maintenance_due <= CURDATE() THEN TRUE
        ELSE FALSE
    END as maintenance_overdue
FROM map_objects 
WHERE status != 'inactive'
ORDER BY created_at DESC;

CREATE VIEW user_roles_view AS
SELECT 
    u.id,
    u.username,
    u.is_active,
    u.created_at,
    u.last_login,
    r.id as role_id,
    r.name as role_name,
    r.display_name as role_display_name,
    r.is_system_role
FROM users u
INNER JOIN roles r ON u.role_id = r.id;

CREATE VIEW roles_permissions_count AS
SELECT 
    r.id,
    r.name,
    r.display_name,
    r.description,
    r.is_system_role,
    COUNT(rp.permission_id) as permission_count
FROM roles r
LEFT JOIN role_permissions rp ON r.id = rp.role_id
GROUP BY r.id, r.name, r.display_name, r.description, r.is_system_role;

-- View für fällige Wartungen
CREATE VIEW maintenance_due_view AS
SELECT 
    o.id,
    o.title,
    o.status,
    o.last_maintenance,
    o.maintenance_interval_days,
    o.next_maintenance_due,
    DATEDIFF(o.next_maintenance_due, CURDATE()) as days_until_maintenance,
    o.maintenance_notification_sent
FROM map_objects o
WHERE o.next_maintenance_due IS NOT NULL
  AND o.status != 'inactive'
ORDER BY o.next_maintenance_due ASC;

-- Datenbereinigung
UPDATE map_objects 
SET x_percent = GREATEST(0.000, LEAST(100.000, x_percent)),
    y_percent = GREATEST(0.000, LEAST(100.000, y_percent))
WHERE x_percent < 0 OR x_percent > 100 OR y_percent < 0 OR y_percent > 100;

-- Datenbank-Update für E-Mail-Benachrichtigungen
-- Diese Datei ausführen um die neuen Felder hinzuzufügen

-- E-Mail und Benachrichtigungs-Felder zur users-Tabelle hinzufügen
ALTER TABLE users 
ADD COLUMN email VARCHAR(255) NULL AFTER password,
ADD COLUMN receive_maintenance_notifications BOOLEAN DEFAULT FALSE AFTER email,
ADD INDEX idx_email (email),
ADD INDEX idx_notifications (receive_maintenance_notifications);

-- Optionale System-Einstellung für E-Mail-Konfiguration
INSERT INTO system_settings (setting_key, setting_value, setting_type, updated_by) VALUES
('email_notifications_enabled', 'true', 'boolean', 1),
('email_from_address', 'noreply@example.com', 'string', 1),
('email_from_name', 'Wartungssystem', 'string', 1)
ON DUPLICATE KEY UPDATE setting_value = setting_value;

-- Tabelle für E-Mail-Log (optional, zur Nachverfolgung)
CREATE TABLE IF NOT EXISTS email_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    recipient_email VARCHAR(255) NOT NULL,
    subject VARCHAR(500) NOT NULL,
    message TEXT,
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    success BOOLEAN DEFAULT TRUE,
    error_message TEXT NULL,
    notification_type VARCHAR(50) DEFAULT 'maintenance',
    INDEX idx_recipient (recipient_email),
    INDEX idx_sent_at (sent_at),
    INDEX idx_type (notification_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;