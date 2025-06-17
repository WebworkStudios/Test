<?php
declare(strict_types=1);

namespace Framework\Http;

use InvalidArgumentException;

final class PathSanitizer
{
    private const array DANGEROUS_PATTERNS = [
        '../', '..\\', '..../', '...//', '....//',
        '%2e%2e%2f', '%2e%2e%5c', '%2e%2e/',
        '%2E%2E%2F', '%2E%2E%5C', '%2E%2E/',
        "\0", '/./', '/.//', '/../',
        '%00', '%2F%2E%2E', '%5C%2E%2E',
        'php://', 'file://', 'data://', 'zip://',
        '\\.\\', '//\\', '\\/\\', '\\\\',
        '%5c%5c', '%2f%5c', '%5c%2f'
    ];

    public static function sanitize(string $path): string
    {
        do {
            $before = $path;
            $path = str_ireplace(self::DANGEROUS_PATTERNS, '', $path);
            $path = urldecode($path);
        } while ($before !== $path);

        // Zusätzliche Validierung
        if (preg_match('/[^\x20-\x7E]/', $path)) {
            throw new InvalidArgumentException('Path contains non-printable characters');
        }

        if (preg_match('/^[A-Z]:[\\\\\/]/', $path)) {
            throw new InvalidArgumentException('Absolute file paths not allowed in URL');
        }

        $cleaned = str_replace('\\', '/', $path);
        if (!str_starts_with($cleaned, '/')) {
            $cleaned = '/' . $cleaned;
        }
        $cleaned = preg_replace('#/+#', '/', $cleaned);

        if (strlen($cleaned) > 2048) {
            throw new InvalidArgumentException('Path too long');
        }

        return $cleaned;
    }
}