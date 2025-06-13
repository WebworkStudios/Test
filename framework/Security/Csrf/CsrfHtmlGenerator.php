<?php


declare(strict_types=1);

namespace Framework\Security\Csrf;

/**
 * HTML-Generierung für CSRF-Tokens
 */
final readonly class CsrfHtmlGenerator
{
    public function __construct(
        private CsrfTokenManager $tokenManager
    )
    {
    }

    /**
     * Generate HTML hidden input field
     */
    public function field(string $action = 'default', string $name = '_token'): string
    {
        $token = $this->getOrCreateToken($action);
        $escapedName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        $escapedToken = htmlspecialchars($token, ENT_QUOTES, 'UTF-8');

        return "<input type=\"hidden\" name=\"{$escapedName}\" value=\"{$escapedToken}\">";
    }

    /**
     * Generate meta tag for AJAX requests (optional - nur bei Bedarf)
     */
    public function metaTag(string $action = 'default'): string
    {
        $token = $this->getOrCreateToken($action);
        $escapedToken = htmlspecialchars($token, ENT_QUOTES, 'UTF-8');

        return "<meta name=\"csrf-token\" content=\"{$escapedToken}\">";
    }

    /**
     * Generate data attribute for JavaScript
     */
    public function dataAttribute(string $action = 'default'): string
    {
        $token = $this->getOrCreateToken($action);
        $escapedToken = htmlspecialchars($token, ENT_QUOTES, 'UTF-8');

        return "data-csrf-token=\"{$escapedToken}\"";
    }

    /**
     * Get existing token or create new one
     */
    private function getOrCreateToken(string $action): string
    {
        if ($this->tokenManager->exists($action)) {
            return $this->tokenManager->get($action);
        }

        return $this->tokenManager->generate($action);
    }
}