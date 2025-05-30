<?php

declare(strict_types=1);

namespace Framework\Routing;

use Framework\Routing\Attributes\Route;
use ReflectionClass;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RecursiveCallbackFilterIterator;

/**
 * Secure Route Discovery Engine for attribute-based route registration
 */
final class RouteDiscovery
{
    private array $classCache;
    private readonly array $ignoredDirectories;
    private readonly array $allowedExtensions;
    private readonly int $maxFileSize;
    private readonly int $maxDepth;
    private readonly array $securityPatterns;
    private readonly array $allowedNamespaces;
    private readonly bool $strictMode;
    private int $processedFiles = 0;
    private int $discoveredRoutes = 0;

    public function __construct(
        private readonly Router $router,
        array $ignoredDirectories = ['vendor', 'node_modules', '.git', 'storage', 'cache', 'tests', 'tmp'],
        array $config = []
    ) {
        $this->classCache = [];
        $this->ignoredDirectories = array_merge($ignoredDirectories, ['..', '.']);
        $this->allowedExtensions = $config['allowed_extensions'] ?? ['php'];
        $this->maxFileSize = $config['max_file_size'] ?? 1048576; // 1MB
        $this->maxDepth = $config['max_depth'] ?? 10;
        $this->strictMode = $config['strict_mode'] ?? true;

        // Sicherheits-Pattern für gefährliche Dateien
        $this->securityPatterns = [
            '/\.(?:exe|bat|cmd|sh|bash|zsh|fish|pl|py|rb|jar|com|scr|vbs|js|html|htm)$/i',
            '/(?:passwd|shadow|hosts|config|env|key|secret|token|private)/i',
            '/\.(?:log|backup|bak|old|tmp|temp|swp|~)$/i',
            '/(?:virus|malware|trojan|backdoor|shell|exploit)/i'
        ];

        // Erlaubte Namespaces für maximale Sicherheit
        $this->allowedNamespaces = $config['allowed_namespaces'] ?? [
            'App\\Actions\\',
            'App\\Controllers\\',
            'App\\Http\\Actions\\',
            'App\\Http\\Controllers\\'
        ];

        $this->validateConfiguration();
    }

    /**
     * Discover and register routes from given directories with security validation
     */
    public function discover(array $directories): void
    {
        $this->validateDirectories($directories);

        $startTime = microtime(true);
        $this->processedFiles = 0;
        $this->discoveredRoutes = 0;

        foreach ($directories as $directory) {
            if ($this->processedFiles > 10000) {
                error_log("Route discovery stopped: too many files processed for security");
                break;
            }

            $this->scanDirectorySecurely($directory);
        }

        $duration = microtime(true) - $startTime;

        error_log(sprintf(
            "Route discovery completed: %d files processed, %d routes discovered in %.2fs",
            $this->processedFiles,
            $this->discoveredRoutes,
            $duration
        ));
    }

    /**
     * Register a specific class with security validation
     */
    public function registerClass(string $className): void
    {
        if (!$this->isValidClassName($className)) {
            throw new \InvalidArgumentException("Invalid class name: {$className}");
        }

        if (!class_exists($className)) {
            return;
        }

        try {
            $reflection = new ReflectionClass($className);
            $this->validateClassSecurity($reflection);
            $this->processRouteAttributes($reflection);
        } catch (\ReflectionException $e) {
            error_log("Failed to reflect class {$className}: " . $e->getMessage());
        } catch (\Throwable $e) {
            error_log("Security validation failed for class {$className}: " . $e->getMessage());

            if ($this->strictMode) {
                throw $e;
            }
        }
    }

    /**
     * Validate configuration for security
     */
    private function validateConfiguration(): void
    {
        // Validiere max file size
        if ($this->maxFileSize < 1024 || $this->maxFileSize > 10485760) {
            throw new \InvalidArgumentException("Invalid max file size: must be between 1KB and 10MB");
        }

        // Validiere max depth
        if ($this->maxDepth < 1 || $this->maxDepth > 20) {
            throw new \InvalidArgumentException("Invalid max depth: must be between 1 and 20");
        }

        // Validiere allowed extensions
        foreach ($this->allowedExtensions as $ext) {
            if (!preg_match('/^[a-zA-Z0-9]+$/', $ext)) {
                throw new \InvalidArgumentException("Invalid file extension: {$ext}");
            }
        }

        // Validiere allowed namespaces
        foreach ($this->allowedNamespaces as $namespace) {
            if (!preg_match('/^[a-zA-Z_\\\\][a-zA-Z0-9_\\\\]*\\\\$/', $namespace)) {
                throw new \InvalidArgumentException("Invalid namespace format: {$namespace}");
            }
        }
    }

    /**
     * Validate directories for security
     */
    private function validateDirectories(array $directories): void
    {
        if (empty($directories)) {
            throw new \InvalidArgumentException("No directories specified for discovery");
        }

        foreach ($directories as $directory) {
            if (!is_string($directory)) {
                throw new \InvalidArgumentException("Directory must be string");
            }

            // Absoluten Pfad verhindern (außer im Development)
            if ((str_starts_with($directory, '/') || preg_match('/^[A-Z]:/i', $directory)) && $this->strictMode) {
                throw new \InvalidArgumentException("Absolute paths not allowed in strict mode: {$directory}");
            }

            // Directory traversal prevention
            if (str_contains($directory, '..') || str_contains($directory, '\0')) {
                throw new \InvalidArgumentException("Invalid directory path: {$directory}");
            }

            // Längen-Validierung
            if (strlen($directory) > 255) {
                throw new \InvalidArgumentException("Directory path too long: {$directory}");
            }

            // Pattern-Validierung
            if (!preg_match('/^[a-zA-Z0-9_\-\/\\\\*.]*$/', $directory)) {
                throw new \InvalidArgumentException("Directory contains invalid characters: {$directory}");
            }
        }
    }

    /**
     * Secure directory scanning with comprehensive validation
     */
    private function scanDirectorySecurely(string $directory): void
    {
        // Glob pattern support
        if (str_contains($directory, '*')) {
            $this->handleGlobPattern($directory);
            return;
        }

        if (!is_dir($directory)) {
            error_log("Directory not found: {$directory}");
            return;
        }

        // Realpath für zusätzliche Sicherheit
        $realDirectory = realpath($directory);
        if ($realDirectory === false) {
            error_log("Could not resolve directory: {$directory}");
            return;
        }

        // Sicherheitsvalidierung des resolved path
        if (!$this->isSecurePath($realDirectory)) {
            throw new \InvalidArgumentException("Insecure directory path: {$realDirectory}");
        }

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveCallbackFilterIterator(
                    new RecursiveDirectoryIterator($realDirectory, RecursiveDirectoryIterator::SKIP_DOTS),
                    [$this, 'secureFileFilter']
                ),
                RecursiveIteratorIterator::SELF_FIRST
            );

            // Depth limiting für DoS prevention
            $iterator->setMaxDepth($this->maxDepth);

            $fileCount = 0;
            foreach ($iterator as $file) {
                // DoS prevention - limit file count per directory
                if (++$fileCount > 5000) {
                    error_log("Too many files in directory {$directory}, stopping for security");
                    break;
                }

                // Global file count check
                if ($this->processedFiles > 10000) {
                    error_log("Global file limit reached, stopping discovery");
                    break;
                }

                if ($file->isFile() && $this->isAllowedFile($file)) {
                    $this->processFileSecurely($file->getPathname());
                    $this->processedFiles++;
                }
            }
        } catch (\Exception $e) {
            error_log("Error scanning directory {$directory}: " . $e->getMessage());

            if ($this->strictMode) {
                throw new \RuntimeException("Directory scan failed: " . $e->getMessage(), 0, $e);
            }
        }
    }

    /**
     * Handle glob patterns securely
     */
    private function handleGlobPattern(string $pattern): void
    {
        // Validate glob pattern for security
        if (str_contains($pattern, '..') || str_contains($pattern, '\0')) {
            throw new \InvalidArgumentException("Unsafe glob pattern: {$pattern}");
        }

        $matches = glob($pattern, GLOB_ONLYDIR);
        if ($matches === false) {
            error_log("Glob pattern failed: {$pattern}");
            return;
        }

        foreach ($matches as $match) {
            if (count($matches) > 100) { // Limit glob results
                error_log("Too many glob matches, limiting for security");
                break;
            }

            $this->scanDirectorySecurely($match);
        }
    }

    /**
     * Secure file filter callback
     */
    public function secureFileFilter(\SplFileInfo $file, string $key, \RecursiveCallbackFilterIterator $iterator): bool
    {
        $filename = $file->getFilename();

        // Skip hidden files and directories
        if (str_starts_with($filename, '.')) {
            return false;
        }

        // Skip ignored directories
        if ($file->isDir() && in_array($filename, $this->ignoredDirectories, true)) {
            return false;
        }

        // Skip files matching security patterns
        foreach ($this->securityPatterns as $pattern) {
            if (preg_match($pattern, $filename)) {
                error_log("Security pattern matched, skipping file: {$filename}");
                return false;
            }
        }

        // Für Verzeichnisse: Zusätzliche Sicherheitsprüfungen
        if ($file->isDir()) {
            return $this->isSecureDirectory($file);
        }

        // Für Dateien: Extension check
        return in_array(strtolower($file->getExtension()), array_map('strtolower', $this->allowedExtensions), true);
    }

    /**
     * Check if directory is secure
     */
    private function isSecureDirectory(\SplFileInfo $directory): bool
    {
        $dirName = strtolower($directory->getFilename());

        // Gefährliche Verzeichnisnamen
        $dangerousDirs = [
            'bin', 'sbin', 'etc', 'var', 'tmp', 'temp', 'root', 'home',
            'windows', 'system32', 'program files', 'programdata',
            'proc', 'sys', 'dev', 'boot', 'lib', 'lib64', 'usr',
            'admin', 'administrator', 'config', 'configuration',
            'secret', 'private', 'hidden', 'backup', 'old'
        ];

        if (in_array($dirName, $dangerousDirs, true)) {
            error_log("Dangerous directory name detected: {$dirName}");
            return false;
        }

        // Prüfe auf verdächtige Muster
        if (preg_match('/(?:backup|old|temp|tmp|cache|log|\.git|\.svn)/', $dirName)) {
            return false;
        }

        return true;
    }

    /**
     * Check if file is allowed
     */
    private function isAllowedFile(\SplFileInfo $file): bool
    {
        // Extension check (case insensitive)
        $extension = strtolower($file->getExtension());
        if (!in_array($extension, array_map('strtolower', $this->allowedExtensions), true)) {
            return false;
        }

        // Size check
        try {
            $size = $file->getSize();
            if ($size === false || $size > $this->maxFileSize) {
                if ($size !== false) {
                    error_log("File too large ({$size} bytes), skipping: " . $file->getPathname());
                }
                return false;
            }
        } catch (\Exception $e) {
            error_log("Could not get file size: " . $file->getPathname());
            return false;
        }

        // Readable check
        if (!$file->isReadable()) {
            return false;
        }

        // Additional security checks
        $filename = $file->getFilename();

        // Skip backup files
        if (preg_match('/\.(?:bak|backup|old|orig|save|tmp)$/i', $filename)) {
            return false;
        }

        // Skip editor temporary files
        if (preg_match('/~$|\.swp$|\.swo$/i', $filename)) {
            return false;
        }

        return true;
    }

    /**
     * Process file with comprehensive security
     */
    private function processFileSecurely(string $filePath): void
    {
        // Final path validation
        if (!$this->isSecurePath($filePath)) {
            error_log("Skipping insecure file path: {$filePath}");
            return;
        }

        // Cache check
        $fileHash = $this->getSecureFileHash($filePath);
        if (isset($this->classCache[$fileHash])) {
            foreach ($this->classCache[$fileHash] as $className) {
                $this->registerClass($className);
            }
            return;
        }

        $content = $this->readFileSecurely($filePath);
        if ($content === null) {
            return;
        }

        // Schnelle Vorprüfung für Route-Attribute
        if (!$this->containsRouteAttributes($content)) {
            $this->classCache[$fileHash] = []; // Cache empty result
            return;
        }

        $classes = $this->extractClassNamesSecurely($content, $filePath);
        $this->classCache[$fileHash] = $classes;

        foreach ($classes as $className) {
            $this->registerClass($className);
        }
    }

    /**
     * Read file content securely
     */
    private function readFileSecurely(string $filePath): ?string
    {
        // Final security check
        if (!is_file($filePath) || !is_readable($filePath)) {
            return null;
        }

        // Size double-check
        $fileSize = filesize($filePath);
        if ($fileSize === false || $fileSize > $this->maxFileSize) {
            error_log("File size check failed: {$filePath}");
            return null;
        }

        // Empty file check
        if ($fileSize === 0) {
            return null;
        }

        try {
            $content = file_get_contents($filePath);
            if ($content === false) {
                error_log("Could not read file: {$filePath}");
                return null;
            }

            // Content validation
            if (!$this->isValidPHPContent($content, $filePath)) {
                error_log("Invalid PHP content in file: {$filePath}");
                return null;
            }

            return $content;
        } catch (\Throwable $e) {
            error_log("Error reading file {$filePath}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Check if content contains route attributes
     */
    private function containsRouteAttributes(string $content): bool
    {
        // Mehrere Patterns für verschiedene Schreibweisen
        $patterns = [
            '/#\[Route\s*\(/i',
            '/use\s+Framework\\\\Routing\\\\Attributes\\\\Route/i',
            '/@Route\s*\(/i' // Alternative Annotation-Syntax
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $content)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Validate PHP content for security
     */
    private function isValidPHPContent(string $content, string $filePath): bool
    {
        // Check for PHP opening tag
        $trimmedContent = trim($content);
        if (!str_starts_with($trimmedContent, '<?php')) {
            return false;
        }

        // Check for dangerous functions
        $dangerousFunctions = [
            'eval', 'exec', 'system', 'shell_exec', 'passthru', 'popen',
            'proc_open', 'file_get_contents', 'file_put_contents', 'fopen',
            'fwrite', 'fputs', 'include', 'require', 'include_once', 'require_once',
            'create_function', 'assert', 'preg_replace', 'call_user_func'
        ];

        foreach ($dangerousFunctions as $func) {
            if (preg_match('/\b' . preg_quote($func, '/') . '\s*\(/i', $content)) {
                error_log("Dangerous function '{$func}' found in: {$filePath}");
                return false;
            }
        }

        // Check for suspicious patterns
        $suspiciousPatterns = [
            '/base64_decode\s*\(/i' => 'Base64 decode',
            '/str_rot13\s*\(/i' => 'ROT13',
            '/gzinflate\s*\(/i' => 'GZ inflate',
            '/\$_(?:GET|POST|REQUEST|COOKIE|SERVER|FILES)\s*\[/i' => 'Direct superglobal access',
            '/\$\$\w+/i' => 'Variable variables',
            '/(?:exit|die)\s*\(/i' => 'Exit/Die calls',
            '/header\s*\(\s*["\']location:/i' => 'Header redirects',
            '/eval\s*\(/i' => 'Eval function',
            '/\`[^`]*\`/i' => 'Backtick execution'
        ];

        foreach ($suspiciousPatterns as $pattern => $description) {
            if (preg_match($pattern, $content)) {
                error_log("Suspicious pattern ({$description}) found in: {$filePath}");
                return false;
            }
        }

        // Check for encoded/obfuscated content
        if (preg_match('/[a-zA-Z0-9+\/]{100,}={0,2}/', $content)) {
            error_log("Potential base64 encoded content in: {$filePath}");
            return false;
        }

        // Check for too many special characters (potential obfuscation)
        $specialCharCount = preg_match_all('/[^\w\s\.\,\;\:\(\)\[\]\{\}\-\+\=\<\>\!\?\'"\\\\\/]/', $content);
        if ($specialCharCount > strlen($content) * 0.1) {
            error_log("Too many special characters (potential obfuscation) in: {$filePath}");
            return false;
        }

        return true;
    }

    /**
     * Extract class names with security validation
     */
    private function extractClassNamesSecurely(string $content, string $filePath): array
    {
        $classes = [];
        $namespace = '';

        // Extract namespace with validation
        if (preg_match('/^\s*namespace\s+([a-zA-Z_\\\\][a-zA-Z0-9_\\\\]*)\s*;/m', $content, $nsMatch)) {
            $namespace = trim($nsMatch[1]);

            // Validate namespace
            if (!$this->isValidNamespace($namespace)) {
                error_log("Invalid namespace '{$namespace}' in: {$filePath}");
                return [];
            }

            $namespace .= '\\';
        }

        // Extract class declarations with security validation
        $pattern = '/^\s*(?:final\s+)?(?:readonly\s+)?(?:abstract\s+)?(?:class|interface|trait|enum)\s+([a-zA-Z_][a-zA-Z0-9_]*)/m';
        if (preg_match_all($pattern, $content, $matches)) {
            foreach ($matches[1] as $className) {
                if ($this->isValidClassName($className)) {
                    $fullClassName = $namespace . $className;
                    if ($this->isAllowedClass($fullClassName)) {
                        $classes[] = $fullClassName;
                    } else {
                        error_log("Class not in allowed namespace: {$fullClassName}");
                    }
                } else {
                    error_log("Invalid class name: {$className}");
                }
            }
        }

        return $classes;
    }

    /**
     * Validate namespace
     */
    private function isValidNamespace(string $namespace): bool
    {
        // Length check
        if (strlen($namespace) > 255) {
            return false;
        }

        // Format validation
        if (!preg_match('/^[a-zA-Z_\\\\][a-zA-Z0-9_\\\\]*$/', $namespace)) {
            return false;
        }

        // Check for dangerous namespaces
        $dangerousNamespaces = [
            'System', 'Windows', 'Microsoft', 'PHP', 'Zend', 'PEAR',
            'Composer', 'Symfony\\Component\\Process'
        ];

        foreach ($dangerousNamespaces as $dangerous) {
            if (str_starts_with($namespace, $dangerous)) {
                return false;
            }
        }

        // Allowed namespaces check
        if ($this->strictMode) {
            foreach ($this->allowedNamespaces as $allowed) {
                if (str_starts_with($namespace . '\\', $allowed)) {
                    return true;
                }
            }
            return false;
        }

        return true;
    }

    /**
     * Validate class name
     */
    private function isValidClassName(string $className): bool
    {
        // Length check
        if (strlen($className) > 100) {
            return false;
        }

        // Format validation
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $className)) {
            return false;
        }

        // Reserved names
        $reserved = [
            '__construct', '__destruct', '__call', '__get', '__set', '__isset', '__unset',
            '__toString', '__invoke', '__clone', '__sleep', '__wakeup', '__serialize', '__unserialize',
            'class', 'interface', 'trait', 'enum', 'function', 'const', 'namespace', 'use',
            'abstract', 'final', 'readonly', 'static', 'public', 'protected', 'private'
        ];

        if (in_array(strtolower($className), $reserved, true)) {
            return false;
        }

        // Dangerous class names
        $dangerous = [
            'Virus', 'Malware', 'Trojan', 'Backdoor', 'Shell', 'Exploit',
            'System', 'Process', 'File', 'Directory', 'Socket', 'Curl'
        ];

        foreach ($dangerous as $danger) {
            if (stripos($className, $danger) !== false) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if class is allowed
     */
    private function isAllowedClass(string $fullClassName): bool
    {
        // Length check
        if (strlen($fullClassName) > 255) {
            return false;
        }

        // Must be in allowed namespaces
        foreach ($this->allowedNamespaces as $namespace) {
            if (str_starts_with($fullClassName, $namespace)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Process Route attributes with security validation
     */
    private function processRouteAttributes(ReflectionClass $reflection): void
    {
        try {
            $attributes = $reflection->getAttributes(Route::class);

            foreach ($attributes as $attribute) {
                try {
                    /** @var Route $route */
                    $route = $attribute->newInstance();

                    // Additional security validation for the route
                    $this->validateRouteAttribute($route, $reflection);

                    $this->router->addRoute(
                        $route->method,
                        $route->path,
                        $reflection->getName(),
                        $route->middleware,
                        $route->name,
                        $route->subdomain
                    );

                    $this->discoveredRoutes++;
                } catch (\Throwable $e) {
                    error_log("Failed to process route attribute for class {$reflection->getName()}: " . $e->getMessage());

                    if ($this->strictMode) {
                        throw $e;
                    }
                }
            }
        } catch (\Throwable $e) {
            error_log("Failed to get route attributes for class {$reflection->getName()}: " . $e->getMessage());

            if ($this->strictMode) {
                throw $e;
            }
        }
    }

    /**
     * Validate route attribute for security
     */
    private function validateRouteAttribute(Route $route, ReflectionClass $reflection): void
    {
        // Validate method
        $allowedMethods = ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'HEAD', 'OPTIONS'];
        if (!in_array(strtoupper($route->method), $allowedMethods, true)) {
            throw new \InvalidArgumentException("Invalid HTTP method in route: {$route->method}");
        }

        // Validate path
        if (strlen($route->path) > 255 || !str_starts_with($route->path, '/')) {
            throw new \InvalidArgumentException("Invalid route path: {$route->path}");
        }

        // Path security validation
        if (str_contains($route->path, '..') || str_contains($route->path, '\0')) {
            throw new \InvalidArgumentException("Unsafe characters in route path: {$route->path}");
        }

        // Validate middleware
        foreach ($route->middleware as $middleware) {
            if (!is_string($middleware) || !preg_match('/^[a-zA-Z0-9_.-]+$/', $middleware)) {
                throw new \InvalidArgumentException("Invalid middleware: {$middleware}");
            }

            if (strlen($middleware) > 50) {
                throw new \InvalidArgumentException("Middleware name too long: {$middleware}");
            }
        }

        // Validate name
        if ($route->name !== null) {
            if (!preg_match('/^[a-zA-Z0-9._-]+$/', $route->name) || strlen($route->name) > 100) {
                throw new \InvalidArgumentException("Invalid route name: {$route->name}");
            }
        }

        // Validate subdomain
        if ($route->subdomain !== null) {
            if (!preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?$/', $route->subdomain)) {
                throw new \InvalidArgumentException("Invalid subdomain: {$route->subdomain}");
            }

            if (strlen($route->subdomain) > 63) {
                throw new \InvalidArgumentException("Subdomain too long: {$route->subdomain}");
            }
        }

        // Validate action class
        if (!$reflection->hasMethod('__invoke')) {
            throw new \InvalidArgumentException("Action class must be invokable: {$reflection->getName()}");
        }

        if (!$reflection->isFinal()) {
            error_log("Warning: Action class should be final: {$reflection->getName()}");
        }
    }

    /**
     * Validate class for security risks
     */
    private function validateClassSecurity(ReflectionClass $reflection): void
    {
        $className = $reflection->getName();

        // Check for dangerous interfaces
        $dangerousInterfaces = [
            'Serializable', 'SplObserver', 'SplSubject', 'Iterator', 'IteratorAggregate',
            'ArrayAccess', 'Countable', 'JsonSerializable'
        ];

        foreach ($dangerousInterfaces as $interface) {
            if ($reflection->implementsInterface($interface)) {
                if ($this->strictMode) {
                    throw new \InvalidArgumentException("Class implements potentially dangerous interface {$interface}: {$className}");
                } else {
                    error_log("Warning: Class implements interface {$interface}: {$className}");
                }
            }
        }

        // Check for dangerous parent classes
        $parent = $reflection->getParentClass();
        if ($parent !== false) {
            $dangerousParents = [
                'PDO', 'mysqli', 'SQLite3', 'SplFileObject', 'DirectoryIterator',
                'RecursiveDirectoryIterator', 'FilesystemIterator', 'SplFileInfo',
                'Exception', 'Error', 'DOMDocument', 'SimpleXMLElement',
                'XMLReader', 'XMLWriter', 'ZipArchive', 'Phar'
            ];

            $parentName = $parent->getName();
            foreach ($dangerousParents as $dangerousParent) {
                if ($parentName === $dangerousParent || $parent->isSubclassOf($dangerousParent)) {
                    throw new \InvalidArgumentException("Class extends dangerous parent {$dangerousParent}: {$className}");
                }
            }
        }

        // Check for dangerous methods
        $dangerousMethods = [
            'exec', 'system', 'shell_exec', 'passthru', 'popen', 'proc_open',
            'file_get_contents', 'file_put_contents', 'fopen', 'fwrite', 'fread',
            'include', 'require', 'include_once', 'require_once', 'eval',
            'create_function', 'call_user_func', 'call_user_func_array',
            'array_map', 'array_filter', 'usort', 'uasort', 'uksort'
        ];

        $methods = $reflection->getMethods(\ReflectionMethod::IS_PUBLIC | \ReflectionMethod::IS_PROTECTED);
        foreach ($methods as $method) {
            $methodName = strtolower($method->getName());
            if (in_array($methodName, array_map('strtolower', $dangerousMethods), true)) {
                throw new \InvalidArgumentException("Class contains dangerous method {$method->getName()}: {$className}");
            }

            // Check method parameters for dangerous types
            foreach ($method->getParameters() as $param) {
                $paramType = $param->getType();
                if ($paramType instanceof \ReflectionNamedType) {
                    $typeName = $paramType->getName();
                    if (in_array($typeName, ['Closure', 'callable'], true)) {
                        error_log("Warning: Method {$method->getName()} accepts callable parameter in class: {$className}");
                    }
                }
            }
        }

        // Check for suspicious constants
        $constants = $reflection->getConstants();
        foreach ($constants as $name => $value) {
            $lowerName = strtolower($name);
            if (preg_match('/(?:password|secret|key|token|hash|salt|iv|nonce)/', $lowerName)) {
                error_log("Warning: Class contains suspicious constant {$name}: {$className}");
            }

            // Check constant values for suspicious content
            if (is_string($value)) {
                if (preg_match('/[a-zA-Z0-9+\/]{32,}={0,2}/', $value)) {
                    error_log("Warning: Constant {$name} contains potential base64 content in class: {$className}");
                }

                if (strlen($value) > 1000) {
                    error_log("Warning: Constant {$name} is very large in class: {$className}");
                }
            }
        }

        // Check for suspicious properties
        $properties = $reflection->getProperties();
        foreach ($properties as $property) {
            $propertyName = strtolower($property->getName());
            if (preg_match('/(?:password|secret|key|token|hash|salt|command|exec|shell)/', $propertyName)) {
                error_log("Warning: Class contains suspicious property {$property->getName()}: {$className}");
            }
        }

        // Check class attributes for security
        $classAttributes = $reflection->getAttributes();
        foreach ($classAttributes as $attribute) {
            $attributeName = $attribute->getName();
            if (!str_starts_with($attributeName, 'Framework\\Routing\\Attributes\\')) {
                error_log("Warning: Class uses non-framework attribute {$attributeName}: {$className}");
            }
        }
    }

    /**
     * Check if path is secure
     */
    private function isSecurePath(string $path): bool
    {
        // Resolve to absolute path for security
        $realPath = realpath($path);
        if ($realPath === false) {
            return false;
        }

        // Check for directory traversal
        if (str_contains($realPath, '..')) {
            return false;
        }

        // Check for null bytes
        if (str_contains($realPath, "\0")) {
            return false;
        }

        // Check for system directories (Unix/Linux)
        $dangerousPaths = [
            '/etc/', '/bin/', '/sbin/', '/usr/bin/', '/usr/sbin/',
            '/var/', '/tmp/', '/root/', '/home/', '/proc/', '/sys/',
            '/boot/', '/dev/', '/lib/', '/lib64/', '/opt/',
            '/media/', '/mnt/', '/srv/'
        ];

        foreach ($dangerousPaths as $dangerous) {
            if (str_starts_with($realPath, $dangerous)) {
                return false;
            }
        }

        // Check for Windows system directories
        if (DIRECTORY_SEPARATOR === '\\') {
            $windowsDangerous = [
                'C:\\Windows\\', 'C:\\Program Files\\', 'C:\\ProgramData\\',
                'C:\\Users\\Administrator\\', 'C:\\System Volume Information\\',
                'C:\\$Recycle.Bin\\', 'C:\\Boot\\', 'C:\\Recovery\\'
            ];

            $upperPath = strtoupper($realPath);
            foreach ($windowsDangerous as $dangerous) {
                if (str_starts_with($upperPath, strtoupper($dangerous))) {
                    return false;
                }
            }
        }

        // Must be within application directory in strict mode
        if ($this->strictMode) {
            $appRoot = realpath(getcwd());
            if ($appRoot !== false && !str_starts_with($realPath, $appRoot)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get secure file hash
     */
    private function getSecureFileHash(string $filePath): string
    {
        // Use file modification time + size + path for cache key
        $stat = stat($filePath);
        if ($stat === false) {
            return hash('sha256', $filePath);
        }

        // Include file permissions and inode for additional security
        $hashData = implode('|', [
            $filePath,
            $stat['mtime'],
            $stat['size'],
            $stat['mode'],
            $stat['ino'] ?? 0
        ]);

        return hash('sha256', $hashData);
    }

    /**
     * Get discovery statistics
     */
    public function getStats(): array
    {
        return [
            'cached_files' => count($this->classCache),
            'processed_files' => $this->processedFiles,
            'discovered_routes' => $this->discoveredRoutes,
            'ignored_directories' => $this->ignoredDirectories,
            'max_file_size' => $this->maxFileSize,
            'max_depth' => $this->maxDepth,
            'allowed_extensions' => $this->allowedExtensions,
            'allowed_namespaces' => $this->allowedNamespaces,
            'strict_mode' => $this->strictMode,
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true)
        ];
    }

    /**
     * Clear cache securely
     */
    public function clearCache(): void
    {
        // Clear cache with security logging
        $cacheCount = count($this->classCache);
        $this->classCache = [];
        $this->processedFiles = 0;
        $this->discoveredRoutes = 0;

        error_log("Route discovery cache cleared: {$cacheCount} entries removed");
    }

    /**
     * Validate discovery state
     */
    public function validateState(): bool
    {
        try {
            // Check cache integrity
            foreach ($this->classCache as $hash => $classes) {
                if (!is_string($hash) || !is_array($classes)) {
                    error_log("Invalid cache entry structure");
                    return false;
                }

                foreach ($classes as $className) {
                    if (!is_string($className)) {
                        error_log("Invalid class name in cache: " . gettype($className));
                        return false;
                    }

                    if (!$this->isValidClassName(basename(str_replace('\\', '/', $className)))) {
                        error_log("Invalid cached class name: {$className}");
                        return false;
                    }
                }
            }

            // Validate configuration consistency
            if ($this->maxFileSize < 1024 || $this->maxDepth < 1) {
                error_log("Invalid configuration detected");
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            error_log("Route discovery state validation failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Force security scan of all cached classes
     */
    public function securityScan(): array
    {
        $issues = [];
        $scannedClasses = 0;

        foreach ($this->classCache as $hash => $classes) {
            foreach ($classes as $className) {
                try {
                    if (class_exists($className)) {
                        $reflection = new ReflectionClass($className);
                        $this->validateClassSecurity($reflection);
                        $scannedClasses++;
                    } else {
                        $issues[] = [
                            'class' => $className,
                            'issue' => 'Class no longer exists',
                            'hash' => $hash,
                            'severity' => 'warning'
                        ];
                    }
                } catch (\Throwable $e) {
                    $issues[] = [
                        'class' => $className,
                        'issue' => $e->getMessage(),
                        'hash' => $hash,
                        'severity' => 'error',
                        'file' => $e->getFile(),
                        'line' => $e->getLine()
                    ];
                }
            }
        }

        $summary = [
            'scanned_classes' => $scannedClasses,
            'issues_found' => count($issues),
            'scan_time' => date('Y-m-d H:i:s'),
            'issues' => $issues
        ];

        if (!empty($issues)) {
            error_log("Security scan completed: {$scannedClasses} classes scanned, " . count($issues) . " issues found");
        }

        return $summary;
    }

    /**
     * Export discovery report for debugging
     */
    public function exportReport(): array
    {
        $report = [
            'timestamp' => date('Y-m-d H:i:s'),
            'stats' => $this->getStats(),
            'configuration' => [
                'strict_mode' => $this->strictMode,
                'max_file_size' => $this->maxFileSize,
                'max_depth' => $this->maxDepth,
                'allowed_extensions' => $this->allowedExtensions,
                'allowed_namespaces' => $this->allowedNamespaces,
                'ignored_directories' => $this->ignoredDirectories
            ],
            'cache_summary' => [],
            'security_scan' => $this->securityScan()
        ];

        // Erstelle Cache-Zusammenfassung ohne sensible Daten
        foreach ($this->classCache as $hash => $classes) {
            $report['cache_summary'][] = [
                'hash' => substr($hash, 0, 8) . '...', // Verkürzt für Sicherheit
                'class_count' => count($classes),
                'classes' => $classes
            ];
        }

        return $report;
    }

    /**
     * Clean cache entries for non-existent files
     */
    public function cleanCache(): int
    {
        $removedEntries = 0;
        $cleanedCache = [];

        foreach ($this->classCache as $hash => $classes) {
            // Da wir den Hash aus Dateipfad + Metadaten erstellen,
            // können wir nicht direkt prüfen ob die Datei existiert
            // Stattdessen prüfen wir ob die Klassen noch existieren
            $validClasses = [];

            foreach ($classes as $className) {
                if (class_exists($className)) {
                    $validClasses[] = $className;
                } else {
                    $removedEntries++;
                    error_log("Removing non-existent class from cache: {$className}");
                }
            }

            if (!empty($validClasses)) {
                $cleanedCache[$hash] = $validClasses;
            } else {
                $removedEntries++;
            }
        }

        $this->classCache = $cleanedCache;

        if ($removedEntries > 0) {
            error_log("Cache cleaned: {$removedEntries} invalid entries removed");
        }

        return $removedEntries;
    }

    /**
     * Verify all discovered routes are still valid
     */
    public function verifyRoutes(): array
    {
        $invalid = [];

        foreach ($this->classCache as $hash => $classes) {
            foreach ($classes as $className) {
                try {
                    if (!class_exists($className)) {
                        $invalid[] = [
                            'class' => $className,
                            'reason' => 'Class does not exist',
                            'hash' => $hash
                        ];
                        continue;
                    }

                    $reflection = new ReflectionClass($className);

                    if (!$reflection->hasMethod('__invoke')) {
                        $invalid[] = [
                            'class' => $className,
                            'reason' => 'Class is not invokable',
                            'hash' => $hash
                        ];
                        continue;
                    }

                    // Prüfe Route-Attribute
                    $attributes = $reflection->getAttributes(Route::class);
                    if (empty($attributes)) {
                        $invalid[] = [
                            'class' => $className,
                            'reason' => 'No Route attributes found',
                            'hash' => $hash
                        ];
                    }

                } catch (\Throwable $e) {
                    $invalid[] = [
                        'class' => $className,
                        'reason' => 'Validation failed: ' . $e->getMessage(),
                        'hash' => $hash
                    ];
                }
            }
        }

        return $invalid;
    }

    /**
     * Get memory usage information
     */
    public function getMemoryInfo(): array
    {
        return [
            'current_usage' => memory_get_usage(true),
            'peak_usage' => memory_get_peak_usage(true),
            'cache_entries' => count($this->classCache),
            'cache_size_estimate' => strlen(serialize($this->classCache)),
            'memory_limit' => ini_get('memory_limit')
        ];
    }

    /**
     * Set strict mode
     */
    public function setStrictMode(bool $strict): void
    {
        $oldMode = $this->strictMode;
        $this->strictMode = $strict;

        if ($oldMode !== $strict) {
            error_log("Route discovery strict mode changed: " . ($strict ? 'enabled' : 'disabled'));

            // Clear cache when switching modes
            $this->clearCache();
        }
    }

    /**
     * Check if discovery is in strict mode
     */
    public function isStrictMode(): bool
    {
        return $this->strictMode;
    }
}
// Length check
if (strlen($className) > 100) {
    return false;
}

// Format validation
if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $className)) {
    return false;
}

// Reserved names
$reserved = [
    '__construct', '__destruct', '__call', '__get', '__set', '__isset', '__unset',
    '__toString', '__invoke', '__clone', '__sleep', '__wakeup', '__serialize', '__unserialize',
    'class', 'interface', 'trait', 'enum', 'function', 'const', 'namespace', 'use',
    'abstract', 'final', 'readonly', 'static', 'public', 'protected', 'private'
];

if (in_array(strtolower($className), $reserved, true)) {
    return false;
}

// Dangerous class names
$dangerous = [
    'Virus', 'Malware', 'Trojan', 'Backdoor', 'Shell', 'Exploit',
    'System', 'Process', 'File', 'Directory', 'Socket', 'Curl'
];

foreach ($dangerous as $danger) {
    if (stripos($className, $danger) !== false) {
        return false;
    }
}

return true;
}

/**
 * Check if class is allowed
 */
private function isAllowedClass(string $fullClassName): bool
{
    // Length check
    if (strlen($fullClassName) > 255) {
        return false;
    }

    // Must be in allowed namespaces
    foreach ($this->allowedNamespaces as $namespace) {
        if (str_starts_with($fullClassName, $namespace)) {
            return true;
        }
    }

    return false;
}

/**
 * Process Route attributes with security validation
 */
private function processRouteAttributes(ReflectionClass $reflection): void
{
    try {
        $attributes = $reflection->getAttributes(Route::class);

        foreach ($attributes as $attribute) {
            try {
                /** @var Route $route */
                $route = $attribute->newInstance();

                // Additional security validation for the route
                $this->validateRouteAttribute($route, $reflection);

                $this->router->addRoute(
                    $route->method,
                    $route->path,
                    $reflection->getName(),
                    $route->middleware,
                    $route->name,
                    $route->subdomain
                );

                $this->discoveredRoutes++;
            } catch (\Throwable $e) {
                error_log("Failed to process route attribute for class {$reflection->getName()}: " . $e->getMessage());

                if ($this->strictMode) {
                    throw $e;
                }
            }
        }
    } catch (\Throwable $e) {
        error_log("Failed to get route attributes for class {$reflection->getName()}: " . $e->getMessage());

        if ($this->strictMode) {
            throw $e;
        }
    }
}

/**
 * Validate route attribute for security
 */
private function validateRouteAttribute(Route $route, ReflectionClass $reflection): void
{
    // Validate method
    $allowedMethods = ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'HEAD', 'OPTIONS'];
    if (!in_array(strtoupper($route->method), $allowedMethods, true)) {
        throw new \InvalidArgumentException("Invalid HTTP method in route: {$route->method}");
    }

    // Validate path
    if (strlen($route->path) > 255 || !str_starts_with($route->path, '/')) {
        throw new \InvalidArgumentException("Invalid route path: {$route->path}");
    }

    // Path security validation
    if (str_contains($route->path, '..') || str_contains($route->path, '\0')) {
        throw new \InvalidArgumentException("Unsafe characters in route path: {$route->path}");
    }

    // Validate middleware
    foreach ($route->middleware as $middleware) {
        if (!is_string($middleware) || !preg_match('/^[a-zA-Z0-9_.-]+$/', $middleware)) {
            throw new \InvalidArgumentException("Invalid middleware: {$middleware}");
        }

        if (strlen($middleware) > 50) {
            throw new \InvalidArgumentException("Middleware name too long: {$middleware}");
        }
    }

    // Validate name
    if ($route->name !== null) {
        if (!preg_match('/^[a-zA-Z0-9._-]+$/', $route->name) || strlen($route->name) > 100) {
            throw new \InvalidArgumentException("Invalid route name: {$route->name}");
        }
    }

    // Validate subdomain
    if ($route->subdomain !== null) {
        if (!preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?$/', $route->subdomain)) {
            throw new \InvalidArgumentException("Invalid subdomain: {$route->subdomain}");
        }

        if (strlen($route->subdomain) > 63) {
            throw new \InvalidArgumentException("Subdomain too long: {$route->subdomain}");
        }
    }

    // Validate action class
    if (!$reflection->hasMethod('__invoke')) {
        throw new \InvalidArgumentException("Action class must be invokable: {$reflection->getName()}");
    }

    if (!$reflection->isFinal()) {
        error_log("Warning: Action class should be final: {$reflection->getName()}");
    }
}

/**
 * Validate class for security risks
 */
private function validateClassSecurity(ReflectionClass $reflection): void
{
    $className = $reflection->getName();

    // Check for dangerous interfaces
    $dangerousInterfaces = [
        'Serializable', 'SplObserver', 'SplSubject', 'Iterator', 'IteratorAggregate',
        'ArrayAccess', 'Countable', 'JsonSerializable'
    ];

    foreach ($dangerousInterfaces as $interface) {
        if ($reflection->implementsInterface($interface)) {
            if ($this->strictMode) {
                throw new \InvalidArgumentException("Class implements potentially dangerous interface {$interface}: {$className}");
            } else {
                error_log("Warning: Class implements interface {$interface}: {$className}");
            }
        }
    }

    // Check for dangerous parent classes
    $parent = $reflection->getParentClass();
    if ($parent !== false) {
        $dangerousParents = [
            'PDO', 'mysqli', 'SQLite3', 'SplFileObject', 'DirectoryIterator',
            'RecursiveDirectoryIterator', 'FilesystemIterator', 'SplFileInfo',
            'Exception', 'Error', 'DOMDocument', 'SimpleXMLElement',
            'XMLReader', 'XMLWriter', 'ZipArchive', 'Phar'
        ];

        $parentName = $parent->getName();
        foreach ($dangerousParents as $dangerousParent) {
            if ($parentName === $dangerousParent || $parent->isSubclassOf($dangerousParent)) {
                throw new \InvalidArgumentException("Class extends dangerous parent {$dangerousParent}: {$className}");
            }
        }
    }

    // Check for dangerous methods
    $dangerousMethods = [
        'exec', 'system', 'shell_exec', 'passthru', 'popen', 'proc_open',
        'file_get_contents', 'file_put_contents', 'fopen', 'fwrite', 'fread',
        'include', 'require', 'include_once', 'require_once', 'eval',
        'create_function', 'call_user_func', 'call_user_func_array',
        'array_map', 'array_filter', 'usort', 'uasort', 'uksort'
    ];

    $methods = $reflection->getMethods(\ReflectionMethod::IS_PUBLIC | \ReflectionMethod::IS_PROTECTED);
    foreach ($methods as $method) {
        $methodName = strtolower($method->getName());
        if (in_array($methodName, array_map('strtolower', $dangerousMethods), true)) {
            throw new \InvalidArgumentException("Class contains dangerous method {$method->getName()}: {$className}");
        }

        // Check method parameters for dangerous types
        foreach ($method->getParameters() as $param) {
            $paramType = $param->getType();
            if ($paramType instanceof \ReflectionNamedType) {
                $typeName = $paramType->getName();
                if (in_array($typeName, ['Closure', 'callable'], true)) {
                    error_log("Warning: Method {$method->getName()} accepts callable parameter in class: {$className}");
                }
            }
        }
    }

    // Check for suspicious constants
    $constants = $reflection->getConstants();
    foreach ($constants as $name => $value) {
        $lowerName = strtolower($name);
        if (preg_match('/(?:password|secret|key|token|hash|salt|iv|nonce)/', $lowerName)) {
            error_log("Warning: Class contains suspicious constant {$name}: {$className}");
        }

        // Check constant values for suspicious content
        if (is_string($value)) {
            if (preg_match('/[a-zA-Z0-9+\/]{32,}={0,2}/', $value)) {
                error_log("Warning: Constant {$name} contains potential base64 content in class: {$className}");
            }

            if (strlen($value) > 1000) {
                error_log("Warning: Constant {$name} is very large in class: {$className}");
            }
        }
    }

    // Check for suspicious properties
    $properties = $reflection->getProperties();
    foreach ($properties as $property) {
        $propertyName = strtolower($property->getName());
        if (preg_match('/(?:password|secret|key|token|hash|salt|command|exec|shell)/', $propertyName)) {
            error_log("Warning: Class contains suspicious property {$property->getName()}: {$className}");
        }
    }

    // Check class attributes for security
    $classAttributes = $reflection->getAttributes();
    foreach ($classAttributes as $attribute) {
        $attributeName = $attribute->getName();
        if (!str_starts_with($attributeName, 'Framework\\Routing\\Attributes\\')) {
            error_log("Warning: Class uses non-framework attribute {$attributeName}: {$className}");
        }
    }
}

/**
 * Check if path is secure
 */
private function isSecurePath(string $path): bool
{
    // Resolve to absolute path for security
    $realPath = realpath($path);
    if ($realPath === false) {
        return false;
    }

    // Check for directory traversal
    if (str_contains($realPath, '..')) {
        return false;
    }

    // Check for null bytes
    if (str_contains($realPath, "\0")) {
        return false;
    }

    // Check for system directories (Unix/Linux)
    $dangerousPaths = [
        '/etc/', '/bin/', '/sbin/', '/usr/bin/', '/usr/sbin/',
        '/var/', '/tmp/', '/root/', '/home/', '/proc/', '/sys/',
        '/boot/', '/dev/', '/lib/', '/lib64/', '/opt/',
        '/media/', '/mnt/', '/srv/'
    ];

    foreach ($dangerousPaths as $dangerous) {
        if (str_starts_with($realPath, $dangerous)) {
            return false;
        }
    }

    // Check for Windows system directories
    if (DIRECTORY_SEPARATOR === '\\') {
        $windowsDangerous = [
            'C:\\Windows\\', 'C:\\Program Files\\', 'C:\\ProgramData\\',
            'C:\\Users\\Administrator\\', 'C:\\System Volume Information\\',
            'C:\\$Recycle.Bin\\', 'C:\\Boot\\', 'C:\\Recovery\\'
        ];

        $upperPath = strtoupper($realPath);
        foreach ($windowsDangerous as $dangerous) {
            if (str_starts_with($upperPath, strtoupper($dangerous))) {
                return false;
            }
        }
    }

    // Must be within application directory in strict mode
    if ($this->strictMode) {
        $appRoot = realpath(getcwd());
        if ($appRoot !== false && !str_starts_with($realPath, $appRoot)) {
            return false;
        }
    }

    return true;
}

/**
 * Get secure file hash
 */
private function getSecureFileHash(string $filePath): string
{
    // Use file modification time + size + path for cache key
    $stat = stat($filePath);
    if ($stat === false) {
        return hash('sha256', $filePath);
    }

    // Include file permissions and inode for additional security
    $hashData = implode('|', [
        $filePath,
        $stat['mtime'],
        $stat['size'],
        $stat['mode'],
        $stat['ino'] ?? 0
    ]);

    return hash('sha256', $hashData);
}

/**
 * Get discovery statistics
 */
public function getStats(): array
{
    return [
        'cached_files' => count($this->classCache),
        'processed_files' => $this->processedFiles,
        'discovered_routes' => $this->discoveredRoutes,
        'ignored_directories' => $this->ignoredDirectories,
        'max_file_size' => $this->maxFileSize,
        'max_depth' => $this->maxDepth,
        'allowed_extensions' => $this->allowedExtensions,
        'allowed_namespaces' => $this->allowedNamespaces,
        'strict_mode' => $this->strictMode,
        'memory_usage' => memory_get_usage(true),
        'peak_memory' => memory_get_peak_usage(true)
    ];
}

/**
 * Clear cache securely
 */
public function clearCache(): void
{
    // Clear cache with security logging
    $cacheCount = count($this->classCache);
    $this->classCache = [];
    $this->processedFiles = 0;
    $this->discoveredRoutes = 0;

    error_log("Route discovery cache cleared: {$cacheCount} entries removed");
}

/**
 * Validate discovery state
 */
public function validateState(): bool
{
    try {
        // Check cache integrity
        foreach ($this->classCache as $hash => $classes) {
            if (!is_string($hash) || !is_array($classes)) {
                error_log("Invalid cache entry structure");
                return false;
            }

            foreach ($classes as $className) {
                if (!is_string($className)) {
                    error_log("Invalid class name in cache: " . gettype($className));
                    return false;
                }

                if (!$this->isValidClassName(basename(str_replace('\\', '/', $className)))) {
                    error_log("Invalid cached class name: {$className}");
                    return false;
                }
            }
        }

        // Validate configuration consistency
        if ($this->maxFileSize < 1024 || $this->maxDepth < 1) {
            error_log("Invalid configuration detected");
            return false;
        }

        return true;
    } catch (\Throwable $e) {
        error_log("Route discovery state validation failed: " . $e->getMessage());
        return false;
    }
}

/**
 * Force security scan of all cached classes
 */
public function securityScan(): array
{
    $issues = [];
    $scannedClasses = 0;

    foreach ($this->classCache as $hash => $classes) {
        foreach ($classes as $className) {
            try {
                if (class_exists($className)) {
                    $reflection = new ReflectionClass($className);
                    $this->validateClassSecurity($reflection);
                    $scannedClasses++;
                } else {
                    $issues[] = [
                        'class' => $className,
                        'issue' => 'Class no longer exists',
                        'hash' => $hash,
                        'severity' => 'warning'
                    ];
                }
            } catch (\Throwable $e) {
                $issues[] = [
                    'class' => $className,
                    'issue' => $e->getMessage(),
                    'hash' => $hash,
                    'severity' => 'error',
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ];
            }
        }
    }

    $summary = [
        'scanned_classes' => $scannedClasses,
        'issues_found' => count($issues),
        'scan_time' => date('Y-m-d H:i:s'),
        'issues' => $issues
    ];

    if (!empty($issues)) {
        error_log("Security scan completed: {$scannedClasses} classes scanned, " . count($issues) . " issues found");
    }

    return $summary;
}

/**
 * Export discovery report for debugging
 */
public function exportReport(): array
{
    $report = [
        'timestamp' => date('Y-m-d H:i:s'),
        'stats' => $this->getStats(),
        'configuration' => [
            'strict_mode' => $this->strictMode,
            'max_file_size' => $this->maxFileSize,
            'max_depth' => $this->maxDepth,
            'allowed_extensions' => $this->allowedExtensions,
            'allowed_namespaces' => $this->allowedNamespaces,
            'ignored_directories' => $this->ignoredDirectories
        ],
        'cache_summary' => [],
        'security_scan' => $this->securityScan()
    ];

    // Erstelle Cache-Zusammenfassung ohne sensible Daten
    foreach ($this->classCache as $hash => $classes) {
        $report['cache_summary'][] = [
            'hash' => substr($hash, 0, 8) . '...', // Verkürzt für Sicherheit
            'class_count' => count($classes),
            'classes' => $classes
        ];
    }

    return $report;
}

/**
 * Clean cache entries for non-existent files
 */
public function cleanCache(): int
{
    $removedEntries = 0;
    $cleanedCache = [];

    foreach ($this->classCache as $hash => $classes) {
        // Da wir den Hash aus Dateipfad + Metadaten erstellen,
        // können wir nicht direkt prüfen ob die Datei existiert
        // Stattdessen prüfen wir ob die Klassen noch existieren
        $validClasses = [];

        foreach ($classes as $className) {
            if (class_exists($className)) {
                $validClasses[] = $className;
            } else {
                $removedEntries++;
                error_log("Removing non-existent class from cache: {$className}");
            }
        }

        if (!empty($validClasses)) {
            $cleanedCache[$hash] = $validClasses;
        } else {
            $removedEntries++;
        }
    }

    $this->classCache = $cleanedCache;

    if ($removedEntries > 0) {
        error_log("Cache cleaned: {$removedEntries} invalid entries removed");
    }

    return $removedEntries;
}

/**
 * Verify all discovered routes are still valid
 */
public function verifyRoutes(): array
{
    $invalid = [];

    foreach ($this->classCache as $hash => $classes) {
        foreach ($classes as $className) {
            try {
                if (!class_exists($className)) {
                    $invalid[] = [
                        'class' => $className,
                        'reason' => 'Class does not exist',
                        'hash' => $hash
                    ];
                    continue;
                }

                $reflection = new ReflectionClass($className);

                if (!$reflection->hasMethod('__invoke')) {
                    $invalid[] = [
                        'class' => $className,
                        'reason' => 'Class is not invokable',
                        'hash' => $hash
                    ];
                    continue;
                }

                // Prüfe Route-Attribute
                $attributes = $reflection->getAttributes(Route::class);
                if (empty($attributes)) {
                    $invalid[] = [
                        'class' => $className,
                        'reason' => 'No Route attributes found',
                        'hash' => $hash
                    ];
                }

            } catch (\Throwable $e) {
                $invalid[] = [
                    'class' => $className,
                    'reason' => 'Validation failed: ' . $e->getMessage(),
                    'hash' => $hash
                ];
            }
        }
    }

    return $invalid;
}

/**
 * Get memory usage information
 */
public function getMemoryInfo(): array
{
    return [
        'current_usage' => memory_get_usage(true),
        'peak_usage' => memory_get_peak_usage(true),
        'cache_entries' => count($this->classCache),
        'cache_size_estimate' => strlen(serialize($this->classCache)),
        'memory_limit' => ini_get('memory_limit')
    ];
}

/**
 * Set strict mode
 */
public function setStrictMode(bool $strict): void
{
    $oldMode = $this->strictMode;
    $this->strictMode = $strict;

    if ($oldMode !== $strict) {
        error_log("Route discovery strict mode changed: " . ($strict ? 'enabled' : 'disabled'));

        // Clear cache when switching modes
        $this->clearCache();
    }
}

/**
 * Check if discovery is in strict mode
 */
public function isStrictMode(): bool
{
    return $this->strictMode;
}
}<?php

declare(strict_types=1);

namespace Framework\Routing;

use Framework\Routing\Attributes\Route;
use ReflectionClass;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RecursiveCallbackFilterIterator;

/**
 * Secure Route Discovery Engine for attribute-based route registration
 */
final class RouteDiscovery
{
    private array $classCache;
    private readonly array $ignoredDirectories;
    private readonly array $allowedExtensions;
    private readonly int $maxFileSize;
    private readonly int $maxDepth;
    private readonly array $securityPatterns;

    public function __construct(
        private readonly Router $router,
        array $ignoredDirectories = ['vendor', 'node_modules', '.git', 'storage', 'cache', 'tests', 'tmp'],
        array $config = []
    ) {
        $this->classCache = [];
        $this->ignoredDirectories = array_merge($ignoredDirectories, ['..', '.']);
        $this->allowedExtensions = $config['allowed_extensions'] ?? ['php'];
        $this->maxFileSize = $config['max_file_size'] ?? 1048576; // 1MB
        $this->maxDepth = $config['max_depth'] ?? 10;

        // Sicherheits-Pattern für gefährliche Dateien
        $this->securityPatterns = [
            '/\.(?:exe|bat|cmd|sh|bash|zsh|fish|pl|py|rb|jar|com|scr|vbs|js|html|htm)$/i',
            '/(?:passwd|shadow|hosts|config|env|key|secret|token|private)/i',
            '/\.(?:log|backup|bak|old|tmp|temp|swp|~)$/i'
        ];

        $this->validateConfiguration();
    }

    /**
     * Discover and register routes from given directories with security validation
     */
    public function discover(array $directories): void
    {
        $this->validateDirectories($directories);

        foreach ($directories as $directory) {
            $this->scanDirectorySecurely($directory);
        }
    }

    /**
     * Register a specific class with security validation
     */
    public function registerClass(string $className): void
    {
        if (!$this->isValidClassName($className)) {
            throw new \InvalidArgumentException("Invalid class name: {$className}");
        }

        if (!class_exists($className)) {
            return;
        }

        try {
            $reflection = new ReflectionClass($className);
            $this->validateClassSecurity($reflection);
            $this->processRouteAttributes($reflection);
        } catch (\ReflectionException $e) {
            error_log("Failed to reflect class {$className}: " . $e->getMessage());
        } catch (\Throwable $e) {
            error_log("Security validation failed for class {$className}: " . $e->getMessage());
        }
    }

    /**
     * Validate configuration for security
     */
    private function validateConfiguration(): void
    {
        // Validiere max file size
        if ($this->maxFileSize < 1024 || $this->maxFileSize > 10485760) {
            throw new \InvalidArgumentException("Invalid max file size");
        }

        // Validiere max depth
        if ($this->maxDepth < 1 || $this->maxDepth > 20) {
            throw new \InvalidArgumentException("Invalid max depth");
        }

        // Validiere allowed extensions
        foreach ($this->allowedExtensions as $ext) {
            if (!preg_match('/^[a-zA-Z0-9]+$/', $ext)) {
                throw new \InvalidArgumentException("Invalid file extension: {$ext}");
            }
        }
    }

    /**
     * Validate directories for security
     */
    private function validateDirectories(array $directories): void
    {
        foreach ($directories as $directory) {
            if (!is_string($directory)) {
                throw new \InvalidArgumentException("Directory must be string");
            }

            // Absoluten Pfad verhindern
            if (str_starts_with($directory, '/') || preg_match('/^[A-Z]:/i', $directory)) {
                throw new \InvalidArgumentException("Absolute paths not allowed: {$directory}");
            }

            // Directory traversal prevention
            if (str_contains($directory, '..') || str_contains($directory, '\0')) {
                throw new \InvalidArgumentException("Invalid directory path: {$directory}");
            }

            // Längen-Validierung
            if (strlen($directory) > 255) {
                throw new \InvalidArgumentException("Directory path too long: {$directory}");
            }

            // Pattern-Validierung
            if (!preg_match('/^[a-zA-Z0-9_\-\/\\\\]*$/', $directory)) {
                throw new \InvalidArgumentException("Directory contains invalid characters: {$directory}");
            }
        }
    }

    /**
     * Secure directory scanning with comprehensive validation
     */
    private function scanDirectorySecurely(string $directory): void
    {
        if (!is_dir($directory)) {
            error_log("Directory not found: {$directory}");
            return;
        }

        // Realpath für zusätzliche Sicherheit
        $realDirectory = realpath($directory);
        if ($realDirectory === false) {
            error_log("Could not resolve directory: {$directory}");
            return;
        }

        // Sicherheitsvalidierung des resolved path
        if (!$this->isSecurePath($realDirectory)) {
            throw new \InvalidArgumentException("Insecure directory path: {$realDirectory}");
        }

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveCallbackFilterIterator(
                    new RecursiveDirectoryIterator($realDirectory, RecursiveDirectoryIterator::SKIP_DOTS),
                    [$this, 'secureFileFilter']
                ),
                RecursiveIteratorIterator::SELF_FIRST
            );

            // Depth limiting für DoS prevention
            $iterator->setMaxDepth($this->maxDepth);

            $fileCount = 0;
            foreach ($iterator as $file) {
                // DoS prevention - limit file count
                if (++$fileCount > 10000) {
                    error_log("Too many files in directory scan, stopping for security");
                    break;
                }

                if ($file->isFile() && $this->isAllowedFile($file)) {
                    $this->processFileSecurely($file->getPathname());
                }
            }
        } catch (\Exception $e) {
            error_log("Error scanning directory {$directory}: " . $e->getMessage());
        }
    }

    /**
     * Secure file filter callback
     */
    public function secureFileFilter(\SplFileInfo $file, string $key, \RecursiveCallbackFilterIterator $iterator): bool
    {
        $filename = $file->getFilename();

        // Skip hidden files and directories
        if (str_starts_with($filename, '.')) {
            return false;
        }

        // Skip ignored directories
        if ($file->isDir() && in_array($filename, $this->ignoredDirectories, true)) {
            return false;
        }

        // Skip files matching security patterns
        foreach ($this->securityPatterns as $pattern) {
            if (preg_match($pattern, $filename)) {
                return false;
            }
        }

        // Für Verzeichnisse: Zusätzliche Sicherheitsprüfungen
        if ($file->isDir()) {
            return $this->isSecureDirectory($file);
        }

        // Für Dateien: Extension check
        return in_array($file->getExtension(), $this->allowedExtensions, true);
    }

    /**
     * Check if directory is secure
     */
    private function isSecureDirectory(\SplFileInfo $directory): bool
    {
        $dirName = $directory->getFilename();

        // Gefährliche Verzeichnisnamen
        $dangerousDirs = [
            'bin', 'sbin', 'etc', 'var', 'tmp', 'temp', 'root', 'home',
            'windows', 'system32', 'program files', 'programdata'
        ];

        return !in_array(strtolower($dirName), $dangerousDirs, true);
    }

    /**
     * Check if file is allowed
     */
    private function isAllowedFile(\SplFileInfo $file): bool
    {
        // Extension check
        if (!in_array($file->getExtension(), $this->allowedExtensions, true)) {
            return false;
        }

        // Size check
        if ($file->getSize() > $this->maxFileSize) {
            error_log("File too large, skipping: " . $file->getPathname());
            return false;
        }

        // Readable check
        if (!$file->isReadable()) {
            return false;
        }

        return true;
    }

    /**
     * Process file with comprehensive security
     */
    private function processFileSecurely(string $filePath): void
    {
        // Final path validation
        if (!$this->isSecurePath($filePath)) {
            error_log("Skipping insecure file path: {$filePath}");
            return;
        }

        // Cache check
        $fileHash = $this->getSecureFileHash($filePath);
        if (isset($this->classCache[$fileHash])) {
            foreach ($this->classCache[$fileHash] as $className) {
                $this->registerClass($className);
            }
            return;
        }

        $content = $this->readFileSecurely($filePath);
        if ($content === null) {
            return;
        }

        // Schnelle Vorprüfung für Route-Attribute
        if (!$this->containsRouteAttributes($content)) {
            return;
        }

        $classes = $this->extractClassNamesSecurely($content);
        $this->classCache[$fileHash] = $classes;

        foreach ($classes as $className) {
            $this->registerClass($className);
        }
    }

    /**
     * Read file content securely
     */
    private function readFileSecurely(string $filePath): ?string
    {
        // Final security check
        if (!is_file($filePath) || !is_readable($filePath)) {
            return null;
        }

        // Size double-check
        $fileSize = filesize($filePath);
        if ($fileSize === false || $fileSize > $this->maxFileSize) {
            error_log("File size check failed: {$filePath}");
            return null;
        }

        try {
            $content = file_get_contents($filePath);
            if ($content === false) {
                error_log("Could not read file: {$filePath}");
                return null;
            }

            // Content validation
            if (!$this->isValidPHPContent($content)) {
                error_log("Invalid PHP content in file: {$filePath}");
                return null;
            }

            return $content;
        } catch (\Throwable $e) {
            error_log("Error reading file {$filePath}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Check if content contains route attributes
     */
    private function containsRouteAttributes(string $content): bool
    {
        return str_contains($content, '#[Route') ||
            (str_contains($content, 'Route(') && str_contains($content, 'use Framework\Routing\Attributes\Route'));
    }

    /**
     * Validate PHP content for security
     */
    private function isValidPHPContent(string $content): bool
    {
        // Check for PHP opening tag
        if (!str_starts_with(trim($content), '<?php')) {
            return false;
        }

        // Check for dangerous functions
        $dangerousFunctions = [
            'eval', 'exec', 'system', 'shell_exec', 'passthru', 'popen',
            'proc_open', 'file_get_contents', 'file_put_contents', 'fopen',
            'fwrite', 'fputs', 'include', 'require', 'include_once', 'require_once'
        ];

        foreach ($dangerousFunctions as $func) {
            if (preg_match('/\b' . preg_quote($func) . '\s*\(/i', $content)) {
                error_log("Dangerous function found in content: {$func}");
                return false;
            }
        }

        // Check for suspicious patterns
        $suspiciousPatterns = [
            '/base64_decode\s*\(/i',
            '/str_rot13\s*\(/i',
            '/gzinflate\s*\(/i',
            '/\$_(?:GET|POST|REQUEST|COOKIE|SERVER|FILES)\s*\[/i'
        ];

        foreach ($suspiciousPatterns as $pattern) {
            if (preg_match($pattern, $content)) {
                error_log("Suspicious pattern found in content");
                return false;
            }
        }

        return true;
    }

    /**
     * Extract class names with security validation
     */
    private function extractClassNamesSecurely(string $content): array
    {
        $classes = [];
        $namespace = '';

        // Extract namespace with validation
        if (preg_match('/^\s*namespace\s+([a-zA-Z_\\\\][a-zA-Z0-9_\\\\]*)\s*;/m', $content, $nsMatch)) {
            $namespace = trim($nsMatch[1]);

            // Validate namespace
            if (!$this->isValidNamespace($namespace)) {
                error_log("Invalid namespace: {$namespace}");
                return [];
            }

            $namespace .= '\\';
        }

        // Extract class declarations with security validation
        $pattern = '/^\s*(?:final\s+)?(?:readonly\s+)?(?:abstract\s+)?(?:class|interface|trait|enum)\s+([a-zA-Z_][a-zA-Z0-9_]*)/m';
        if (preg_match_all($pattern, $content, $matches)) {
            foreach ($matches[1] as $className) {
                if ($this->isValidClassName($className)) {
                    $fullClassName = $namespace . $className;
                    if ($this->isAllowedClass($fullClassName)) {
                        $classes[] = $fullClassName;
                    }
                }
            }
        }

        return $classes;
    }

    /**
     * Validate namespace
     */
    private function isValidNamespace(string $namespace): bool
    {
        // Length check
        if (strlen($namespace) > 255) {
            return false;
        }

        // Format validation
        if (!preg_match('/^[a-zA-Z_\\\\][a-zA-Z0-9_\\\\]*$/', $namespace)) {
            return false;
        }

        // Allowed namespaces
        $allowedPrefixes = [
            'App\\',
            'Framework\\',
            'Tests\\App\\'
        ];

        foreach ($allowedPrefixes as $prefix) {
            if (str_starts_with($namespace, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Validate class name
     */
    private function isValidClassName(string $className): bool