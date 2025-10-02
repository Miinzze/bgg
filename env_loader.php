<?php
/**
 * Einfacher .env Datei Loader
 * Lädt Umgebungsvariablen aus .env Datei
 */

class EnvLoader {
    
    /**
     * Lädt .env Datei und setzt Umgebungsvariablen
     */
    public static function load($path = null) {
        if ($path === null) {
            $path = __DIR__ . '/.env';
        }
        
        if (!file_exists($path)) {
            throw new Exception('.env Datei nicht gefunden: ' . $path);
        }
        
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        
        foreach ($lines as $line) {
            // Kommentare überspringen
            if (strpos(trim($line), '#') === 0) {
                continue;
            }
            
            // Zeile parsen
            if (strpos($line, '=') !== false) {
                list($name, $value) = explode('=', $line, 2);
                
                $name = trim($name);
                $value = trim($value);
                
                // Anführungszeichen entfernen
                $value = self::stripQuotes($value);
                
                // Boolean-Werte konvertieren
                if (strtolower($value) === 'true') {
                    $value = true;
                } elseif (strtolower($value) === 'false') {
                    $value = false;
                } elseif (strtolower($value) === 'null') {
                    $value = null;
                }
                
                // Umgebungsvariable setzen
                if (!array_key_exists($name, $_ENV)) {
                    putenv("$name=$value");
                    $_ENV[$name] = $value;
                    $_SERVER[$name] = $value;
                }
            }
        }
    }
    
    /**
     * Entfernt Anführungszeichen von Werten
     */
    private static function stripQuotes($value) {
        $value = trim($value);
        
        // Doppelte Anführungszeichen
        if (strlen($value) > 1 && $value[0] === '"' && $value[strlen($value) - 1] === '"') {
            return substr($value, 1, -1);
        }
        
        // Einfache Anführungszeichen
        if (strlen($value) > 1 && $value[0] === "'" && $value[strlen($value) - 1] === "'") {
            return substr($value, 1, -1);
        }
        
        return $value;
    }
    
    /**
     * Holt Umgebungsvariable mit Fallback
     */
    public static function get($key, $default = null) {
        $value = getenv($key);
        
        if ($value === false) {
            $value = $_ENV[$key] ?? $_SERVER[$key] ?? $default;
        }
        
        return $value;
    }
    
    /**
     * Prüft ob Umgebungsvariable existiert
     */
    public static function has($key) {
        return getenv($key) !== false || isset($_ENV[$key]) || isset($_SERVER[$key]);
    }
    
    /**
     * Holt Umgebungsvariable oder wirft Exception
     */
    public static function require($key) {
        if (!self::has($key)) {
            throw new Exception("Erforderliche Umgebungsvariable fehlt: $key");
        }
        
        return self::get($key);
    }
}

/**
 * Helper-Funktion für einfachen Zugriff
 */
function env($key, $default = null) {
    return EnvLoader::get($key, $default);
}