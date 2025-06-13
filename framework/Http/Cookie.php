<?php

declare(strict_types=1);

namespace Framework\Http;

/**
 * HTTP Cookie representation
 */
final readonly class Cookie
{
    public function __construct(
        public string $name,
        public string $value,
        public int    $expire = 0,
        public string $path = '/',
        public string $domain = '',
        public bool   $secure = false,
        public bool   $httponly = true,
        public string $samesite = 'Lax'
    )
    {
        $this->validate();
    }

    /**
     * Create cookie that expires in given seconds
     */
    public static function expiresIn(string $name, string $value, int $seconds): self
    {
        return new self($name, $value, time() + $seconds);
    }

    /**
     * Create cookie that expires at specific date
     */
    public static function expiresAt(string $name, string $value, \DateTimeInterface $date): self
    {
        return new self($name, $value, $date->getTimestamp());
    }

    /**
     * Create session cookie (expires when browser closes)
     */
    public static function session(string $name, string $value): self
    {
        return new self($name, $value);
    }

    /**
     * Create cookie for deletion
     */
    public static function delete(string $name, string $path = '/', string $domain = ''): self
    {
        return new self($name, '', 1, $path, $domain); // Expire in past
    }

    /**
     * Convert to Set-Cookie header value
     */
    public function toHeaderValue(): string
    {
        $cookie = "{$this->name}=" . urlencode($this->value);

        if ($this->expire > 0) {
            $cookie .= '; Expires=' . gmdate('D, d M Y H:i:s T', $this->expire);
            $cookie .= '; Max-Age=' . ($this->expire - time());
        }

        if ($this->path !== '') {
            $cookie .= "; Path={$this->path}";
        }

        if ($this->domain !== '') {
            $cookie .= "; Domain={$this->domain}";
        }

        if ($this->secure) {
            $cookie .= '; Secure';
        }

        if ($this->httponly) {
            $cookie .= '; HttpOnly';
        }

        if ($this->samesite !== '') {
            $cookie .= "; SameSite={$this->samesite}";
        }

        return $cookie;
    }

    /**
     * Validate cookie properties using match expression
     */
    private function validate(): void
    {
        match(true) {
            $this->name === '' || preg_match('/[=,; \t\r\n\013\014]/', $this->name)
            => throw new \InvalidArgumentException("Invalid cookie name: {$this->name}"),
            preg_match('/[,; \t\r\n\013\014]/', $this->value)
            => throw new \InvalidArgumentException("Invalid cookie value"),
            $this->samesite !== '' && !in_array($this->samesite, ['Strict', 'Lax', 'None'], true)
            => throw new \InvalidArgumentException("Invalid SameSite value: {$this->samesite}"),
            default => null
        };
    }
}