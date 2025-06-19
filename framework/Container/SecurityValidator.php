<?php

declare(strict_types=1);

namespace Framework\Container;

use Framework\Http\RequestSanitizer;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionMethod;

/**
 * Security Validator für Container-Operationen mit PHP 8.4 Features
 * Delegiert alle Basis-Validierungen an RequestSanitizer für Konsistenz
 */
final readonly class SecurityValidator
{
    private const int MAX_FILE_SIZE = 2097152; // 2MB

    // Gefährliche Patterns für Code-Scanning (Container-spezifisch)
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

    // Container-spezifische gefährliche Elemente
    private const array DANGEROUS_INTERFACES = ['Serializable', 'Traversable'];
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
     * ✅ VEREINFACHT: Delegiert Service-ID-Validierung an RequestSanitizer
     */
    public function isServiceIdSafe(string $serviceId): bool
    {
        return RequestSanitizer::isSecureClassName($serviceId);
    }

    /**
     * ✅ VEREINFACHT: Delegiert Namespace-Validierung an RequestSanitizer
     */
    public function isNamespaceSafe(string $namespace): bool
    {
        return RequestSanitizer::isSecureClassName($namespace);
    }

    /**
     * ✅ FOKUSSIERT: Nur Container-spezifische Klassen-Validierung
     */
    public function isClassSecure(ReflectionClass $reflection): bool
    {
        // Basis-Klassennamen-Check über RequestSanitizer
        if (!$this->isClassNameSafe($reflection->getName())) {
            return false;
        }

        return match (true) {
            $reflection->isInternal() => false,
            $this->isActionClass($reflection) => true,
            $this->isFrameworkClass($reflection) => true,
            $this->hasContainerSecurityRisks($reflection) => false,
            !$this->isClassFileSecure($reflection) => false,
            default => true
        };
    }

    /**
     * ✅ VEREINFACHT: Delegiert Klassennamen-Validierung an RequestSanitizer
     */
    public function isClassNameSafe(string $className): bool
    {
        return RequestSanitizer::isSecureClassName($className);
    }

    private function isActionClass(ReflectionClass $reflection): bool
    {
        $className = $reflection->getName();
        $actionNamespaces = [
            'App\\Actions\\', 'App\\Controllers\\',
            'App\\Http\\Actions\\', 'App\\Http\\Controllers\\'
        ];

        foreach ($actionNamespaces as $namespace) {
            if (str_starts_with($className, $namespace)) {
                return $reflection->hasMethod('__invoke');
            }
        }
        return false;
    }

    private function isFrameworkClass(ReflectionClass $reflection): bool
    {
        $className = $reflection->getName();
        $frameworkNamespaces = ['Framework\\', 'App\\'];

        foreach ($frameworkNamespaces as $namespace) {
            if (str_starts_with($className, $namespace)) {
                return true;
            }
        }
        return false;
    }

    /**
     * ✅ FOKUSSIERT: Container-spezifische Sicherheitsrisiken
     */
    private function hasContainerSecurityRisks(ReflectionClass $reflection): bool
    {
        // Prüfe gefährliche Interfaces (Container-spezifisch)
        foreach (self::DANGEROUS_INTERFACES as $dangerousInterface) {
            if ($reflection->implementsInterface($dangerousInterface)) {
                return true;
            }
        }

        // Prüfe gefährliche Methoden (Container-spezifisch)
        $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);
        foreach ($methods as $method) {
            if (in_array(strtolower($method->getName()), self::DANGEROUS_METHODS, true)) {
                return true;
            }
        }

        return false;
    }

    private function isClassFileSecure(ReflectionClass $reflection): bool
    {
        $fileName = $reflection->getFileName();
        return $fileName === false || $this->isFileSafe($fileName);
    }

    // === Private Helper Methods (Container-spezifisch) ===

    /**
     * ✅ FOKUSSIERT: Nur Container-relevante Datei-Validierung
     */
    public function isFileSafe(string $filePath): bool
    {
        // Basis-Validierung über RequestSanitizer
        if (!$this->isPathSafe($filePath)) {
            return false;
        }

        return match (true) {
            !file_exists($filePath) => false,
            !is_readable($filePath) => false,
            !$this->isValidFileSize($filePath) => false,
            !$this->isValidPhpFile($filePath) => false,
            default => true
        };
    }

    /**
     * ✅ VEREINFACHT: Delegiert Path-Validierung an RequestSanitizer
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

    private function isValidFileSize(string $filePath): bool
    {
        $size = filesize($filePath);
        return $size !== false && $size > 0 && $size <= self::MAX_FILE_SIZE;
    }

    private function isValidPhpFile(string $filePath): bool
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        if ($extension !== 'php') {
            return false;
        }

        $filename = basename($filePath);
        return !str_starts_with($filename, '.') &&
            !str_starts_with($filename, '_') &&
            !str_starts_with($filename, '#');
    }

    /**
     * ✅ FOKUSSIERT: Container-spezifische Content-Validierung
     */
    public function isContentSafe(string $content): bool
    {
        // Container-spezifische gefährliche Patterns
        foreach (self::DANGEROUS_CODE_PATTERNS as $pattern) {
            if (preg_match($pattern, $content)) {
                return false;
            }
        }

        if ($this->strictMode) {
            return $this->strictContainerValidation($content);
        }

        return true;
    }

    private function strictContainerValidation(string $content): bool
    {
        $containerSuspicious = [
            'eval(', 'assert(', 'create_function(', 'extract(', 'parse_str('
        ];

        foreach ($containerSuspicious as $suspicious) {
            if (str_contains(strtolower($content), $suspicious)) {
                return false;
            }
        }
        return true;
    }

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