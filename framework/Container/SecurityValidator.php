<?php


declare(strict_types=1);

namespace Framework\Container;

use ReflectionClass;
use ReflectionMethod;

/**
 * Security Validator für Container-Operationen mit PHP 8.4 Features
 *
 * Zentralisiert alle Sicherheitsprüfungen für Service-Discovery,
 * Klassennamen, Dateiinhalte und Pfade.
 */
final readonly class SecurityValidator
{
    private const int MAX_FILE_SIZE = 2097152; // 2MB
    private const int MAX_CLASS_NAME_LENGTH = 255;
    private const int MAX_PATH_LENGTH = 500;

    // Gefährliche Patterns für Code-Scanning
    private const array DANGEROUS_CODE_PATTERNS = [
        '/eval\s*\(/i',
        '/system\s*\(/i',
        '/exec\s*\(/i',
        '/shell_exec\s*\(/i',
        '/passthru\s*\(/i',
        '/file_get_contents\s*\(\s*["\']php:\/\//i',
        '/__halt_compiler\s*\(/i',
        '/base64_decode\s*\(/i'
    ];

    // Gefährliche Klassennamen und Interfaces
    private const array DANGEROUS_CLASSES = [
        'ReflectionFunction',
        'ReflectionMethod',
        'Closure',
        'Generator'
    ];

    private const array DANGEROUS_INTERFACES = [
        'Serializable',
        'Traversable'
    ];

    // Gefährliche Methodennamen
    private const array DANGEROUS_METHODS = [
        'eval', 'system', 'exec', 'shell_exec', 'passthru',
        '__destruct', '__wakeup', '__unserialize', '__serialize',
        'file_get_contents', 'file_put_contents', 'fopen'
    ];

    public function __construct(
        private bool  $strictMode = true,
        private array $allowedPaths = []
    )
    {
    }

    /**
     * Validiert Dateiinhalte auf gefährlichen Code
     */
    public function isContentSafe(string $content): bool
    {
        // Prüfe auf gefährliche PHP-Konstrukte
        foreach (self::DANGEROUS_CODE_PATTERNS as $pattern) {
            if (preg_match($pattern, $content)) {
                return false;
            }
        }

        // Zusätzliche Prüfungen im strict mode
        if ($this->strictMode) {
            return $this->strictContentValidation($content);
        }

        return true;
    }

    /**
     * Strenge Content-Validierung für strict mode
     */
    private function strictContentValidation(string $content): bool
    {
        // Prüfe auf verdächtige Strings
        $suspiciousStrings = [
            'eval(',
            'assert(',
            'create_function(',
            'preg_replace_callback_array(',
            '${',
            'extract(',
            'parse_str('
        ];

        foreach ($suspiciousStrings as $suspicious) {
            if (str_contains(strtolower($content), $suspicious)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Validiert PHP-Klassen auf Sicherheitsrisiken
     */
    public function isClassSecure(ReflectionClass $reflection): bool
    {
        return match (true) {
            $reflection->isInternal() => false,
            $this->isActionClass($reflection) => true, // ✅ Action-Klassen sind erlaubt
            $this->isFrameworkClass($reflection) => true, // ✅ Neue Methode für Framework-Klassen
            $this->hasSecurityRisks($reflection) => false,
            !$this->isClassFileSecure($reflection) => false,
            default => true
        };
    }


    /**
     * ✅ Neue Methode: Prüft ob es eine Action-Klasse ist
     */
    private function isActionClass(ReflectionClass $reflection): bool
    {
        $className = $reflection->getName();

        // Action-Klassen sind explizit erlaubt
        $actionNamespaces = [
            'App\\Actions\\',
            'App\\Controllers\\',
            'App\\Http\\Actions\\',
            'App\\Http\\Controllers\\'
        ];

        foreach ($actionNamespaces as $namespace) {
            if (str_starts_with($className, $namespace)) {
                // Zusätzliche Prüfung: Muss __invoke haben
                return $reflection->hasMethod('__invoke');
            }
        }

        return false;
    }

    /**
     * ✅ Neue Methode: Prüft ob es eine Framework-Klasse ist
     */
    private function isFrameworkClass(ReflectionClass $reflection): bool
    {
        $className = $reflection->getName();

        // Framework-Klassen sind grundsätzlich erlaubt
        $frameworkNamespaces = [
            'Framework\\',
            'App\\' // App-Namespace ist auch erlaubt
        ];

        foreach ($frameworkNamespaces as $namespace) {
            if (str_starts_with($className, $namespace)) {
                return true;
            }
        }

        return false;
    }


    /**
     * Prüft Klasse auf Sicherheitsrisiken
     */
    private function hasSecurityRisks(ReflectionClass $reflection): bool
    {
        // Prüfe gefährliche Interfaces
        foreach (self::DANGEROUS_INTERFACES as $dangerousInterface) {
            if ($reflection->implementsInterface($dangerousInterface)) {
                return true;
            }
        }

        // Prüfe gefährliche Methoden
        $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);
        foreach ($methods as $method) {
            if (in_array(strtolower($method->getName()), self::DANGEROUS_METHODS, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Prüft ob Klassendatei sicher ist
     */
    private function isClassFileSecure(ReflectionClass $reflection): bool
    {
        $fileName = $reflection->getFileName();

        if ($fileName === false) {
            return true; // Built-in classes
        }

        return $this->isFileSafe($fileName);
    }

    /**
     * Validiert Dateien auf Größe und Sicherheit
     */
    public function isFileSafe(string $filePath): bool
    {
        return match (true) {
            !file_exists($filePath) => false,
            !is_readable($filePath) => false,
            !$this->isPathSafe($filePath) => false,
            !$this->isValidFileSize($filePath) => false,
            !$this->isValidFileExtension($filePath) => false,
            !$this->isValidFileName($filePath) => false,
            default => true
        };
    }

    /**
     * Validiert Dateipfade auf Sicherheit
     */
    public function isPathSafe(string $path): bool
    {
        $realPath = realpath($path);

        return match (true) {
            $realPath === false => false,
            strlen($path) > self::MAX_PATH_LENGTH => false,
            str_contains($path, '..') => false,
            str_contains($path, "\0") => false,
            !$this->isWithinAllowedPaths($realPath) => false,
            default => true
        };
    }

    /**
     * Prüft ob Pfad innerhalb erlaubter Pfade liegt
     */
    private function isWithinAllowedPaths(string $realPath): bool
    {
        if (empty($this->allowedPaths)) {
            return true; // Keine Einschränkungen wenn keine Pfade definiert
        }

        foreach ($this->allowedPaths as $allowedPath) {
            $realAllowedPath = realpath($allowedPath);
            if ($realAllowedPath && str_starts_with($realPath, $realAllowedPath)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Validiert Dateigröße
     */
    private function isValidFileSize(string $filePath): bool
    {
        $size = filesize($filePath);
        return $size !== false && $size > 0 && $size <= self::MAX_FILE_SIZE;
    }

    /**
     * Validiert Dateierweiterung
     */
    private function isValidFileExtension(string $filePath): bool
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        return $extension === 'php';
    }

    // === Private Helper Methods ===

    /**
     * Validiert Dateinamen
     */
    private function isValidFileName(string $filePath): bool
    {
        $filename = basename($filePath);

        return match (true) {
            str_starts_with($filename, '.') => false,
            str_starts_with($filename, '_') => false,
            str_starts_with($filename, '#') => false,
            preg_match('/\.(bak|tmp|old|orig)\.php$/', $filename) => true, // Backup-Dateien ablehnen
            default => true
        };
    }

    /**
     * Validiert Methodennamen auf Sicherheit
     */
    public function isMethodSafe(ReflectionMethod $method): bool
    {
        $methodName = strtolower($method->getName());

        return match (true) {
            in_array($methodName, self::DANGEROUS_METHODS, true) => false,
            str_starts_with($methodName, '__') && !in_array($methodName, ['__construct', '__invoke'], true) => false,
            !$method->isPublic() => false,
            !$method->isStatic() && $this->strictMode => $this->validateInstanceMethod($method),
            default => true
        };
    }

    /**
     * Validiert Instance-Methoden (für Factory-Pattern etc.)
     */
    private function validateInstanceMethod(ReflectionMethod $method): bool
    {
        // Im strict mode nur bestimmte Instance-Methoden erlauben
        $allowedInstanceMethods = ['__invoke', 'handle', 'execute', 'process'];

        return in_array($method->getName(), $allowedInstanceMethods, true);
    }

    /**
     * Validiert Namespace auf erlaubte Prefixes
     */
    public function isNamespaceSafe(string $namespace): bool
    {
        if (strlen($namespace) > self::MAX_CLASS_NAME_LENGTH) {
            return false;
        }

        $allowedPrefixes = [
            'App\\',
            'Framework\\',
            'Modules\\',
            'Domain\\',
            'Infrastructure\\'
        ];

        foreach ($allowedPrefixes as $prefix) {
            if (str_starts_with($namespace, $prefix)) {
                return true;
            }
        }

        return !$this->strictMode; // Im non-strict mode andere Namespaces erlauben
    }

    /**
     * Validiert Interface-Namen auf Sicherheit
     */
    public function isInterfaceSafe(string $interfaceName): bool
    {
        return match (true) {
            !$this->isServiceIdSafe($interfaceName) => false,
            in_array($interfaceName, self::DANGEROUS_INTERFACES, true) => false,
            str_starts_with($interfaceName, 'Psr\\') && $this->strictMode => false, // PSR nur mit Vorsicht
            default => true
        };
    }

    /**
     * Validiert Service-IDs
     */
    public function isServiceIdSafe(string $serviceId): bool
    {
        return match (true) {
            empty($serviceId) => false,
            strlen($serviceId) > self::MAX_CLASS_NAME_LENGTH => false,
            str_contains($serviceId, '..') => false,
            str_contains($serviceId, '/') => false,
            str_contains($serviceId, "\0") => false,
            !preg_match('/^[a-zA-Z_\\\\][a-zA-Z0-9_\\\\.]*$/', $serviceId) => false,
            default => true
        };
    }

    /**
     * Generiere sichere Pfad-Validierung
     */
    public function sanitizePath(string $path): string
    {
        // Entferne gefährliche Sequenzen
        $cleaned = str_replace(['../', '.\\', '..\\', "\0"], '', $path);

        // Normalisiere Slashes
        $cleaned = str_replace('\\', '/', $cleaned);

        // Entferne doppelte Slashes
        return preg_replace('/\/+/', '/', $cleaned);
    }

    /**
     * Erstelle Sicherheitsbericht für Debugging
     */
    public function createSecurityReport(array $items): array
    {
        $report = [
            'total_items' => count($items),
            'safe_items' => 0,
            'unsafe_items' => 0,
            'issues' => []
        ];

        foreach ($items as $item) {
            if (is_string($item)) {
                if ($this->isClassNameSafe($item)) {
                    $report['safe_items']++;
                } else {
                    $report['unsafe_items']++;
                    $report['issues'][] = "Unsafe class name: {$item}";
                }
            }
        }

        return $report;
    }

    /**
     * Validiert Klassennamen auf Sicherheit und Format
     */
    public function isClassNameSafe(string $className): bool
    {
        return match (true) {
            empty($className) => false,
            strlen($className) > self::MAX_CLASS_NAME_LENGTH => false,
            str_contains($className, '..') => false,
            str_contains($className, '/') => false,
            str_contains($className, "\0") => false,
            !preg_match('/^[a-zA-Z_\\\\][a-zA-Z0-9_\\\\]*$/', $className) => false,
            $this->isDangerousClassName($className) => false,
            default => true
        };
    }

    /**
     * Prüft ob Klassenname gefährlich ist
     */
    private function isDangerousClassName(string $className): bool
    {
        $baseName = basename(str_replace('\\', '/', $className));

        foreach (self::DANGEROUS_CLASSES as $dangerous) {
            if (str_contains(strtolower($baseName), strtolower($dangerous))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Magic method für Debugging
     */
    public function __debugInfo(): array
    {
        return [
            'strict_mode' => $this->strictMode,
            'allowed_paths_count' => count($this->allowedPaths),
            'max_file_size' => self::MAX_FILE_SIZE,
            'dangerous_patterns_count' => count(self::DANGEROUS_CODE_PATTERNS),
            'dangerous_classes_count' => count(self::DANGEROUS_CLASSES)
        ];
    }
}