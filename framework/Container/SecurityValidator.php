<?php

declare(strict_types=1);

namespace Framework\Container;

use Framework\Http\RequestSanitizer;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionMethod;

/**
 * Security Validator für Container-Operationen mit PHP 8.4 Features
 * Nutzt RequestSanitizer für konsistente Validierung
 */
final readonly class SecurityValidator
{
    private const int MAX_FILE_SIZE = 2097152; // 2MB

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

    // Gefährliche Interfaces
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
     * Validiert PHP-Klassen auf Sicherheitsrisiken
     */
    public function isClassSecure(ReflectionClass $reflection): bool
    {
        return match (true) {
            $reflection->isInternal() => false,
            $this->isActionClass($reflection) => true,
            $this->isFrameworkClass($reflection) => true,
            $this->hasSecurityRisks($reflection) => false,
            !$this->isClassFileSecure($reflection) => false,
            default => true
        };
    }

    /**
     * Prüft ob es eine Action-Klasse ist
     */
    private function isActionClass(ReflectionClass $reflection): bool
    {
        $className = $reflection->getName();

        $actionNamespaces = [
            'App\\Actions\\',
            'App\\Controllers\\',
            'App\\Http\\Actions\\',
            'App\\Http\\Controllers\\'
        ];

        foreach ($actionNamespaces as $namespace) {
            if (str_starts_with($className, $namespace)) {
                return $reflection->hasMethod('__invoke');
            }
        }

        return false;
    }

    /**
     * Prüft ob es eine Framework-Klasse ist
     */
    private function isFrameworkClass(ReflectionClass $reflection): bool
    {
        $className = $reflection->getName();

        $frameworkNamespaces = [
            'Framework\\',
            'App\\'
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
     * Delegiere Path-Validierung an RequestSanitizer
     */
    public function isPathSafe(string $path): bool
    {
        try {
            RequestSanitizer::sanitizePath($path);
            return $this->isWithinAllowedPaths(realpath($path) ?: $path);
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /**
     * Prüft ob Pfad innerhalb erlaubter Pfade liegt
     */
    private function isWithinAllowedPaths(string $realPath): bool
    {
        if (empty($this->allowedPaths)) {
            return true;
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
            preg_match('/\.(bak|tmp|old|orig)\.php$/', $filename) => false,
            default => true
        };
    }

    /**
     * Delegiere Klassennamen-Validierung an RequestSanitizer
     */
    public function isClassNameSafe(string $className): bool
    {
        return RequestSanitizer::isSecureClassName($className);
    }

    /**
     * Delegiere Service-ID-Validierung an RequestSanitizer
     */
    public function isServiceIdSafe(string $serviceId): bool
    {
        return RequestSanitizer::isSecureClassName($serviceId);
    }

    /**
     * Delegiere Namespace-Validierung an RequestSanitizer
     */
    public function isNamespaceSafe(string $namespace): bool
    {
        return RequestSanitizer::isSecureClassName($namespace);
    }

    /**
     * Validiert Dateinehalte auf gefährlichen Code
     */
    public function isContentSafe(string $content): bool
    {
        foreach (self::DANGEROUS_CODE_PATTERNS as $pattern) {
            if (preg_match($pattern, $content)) {
                return false;
            }
        }

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
     * Magic method für Debugging
     */
    public function __debugInfo(): array
    {
        return [
            'strict_mode' => $this->strictMode,
            'allowed_paths_count' => count($this->allowedPaths),
            'max_file_size' => self::MAX_FILE_SIZE,
            'dangerous_patterns_count' => count(self::DANGEROUS_CODE_PATTERNS)
        ];
    }
}