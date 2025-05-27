<?php

declare(strict_types=1);

namespace Framework\Http;

/**
 * HTTP Response representation
 */
final class Response
{
    /**
     * @param string $body Response body
     * @param int $status HTTP status code
     * @param array<string, string> $headers HTTP headers
     */
    public function __construct(
        private string $body = '',
        private int $status = 200,
        private array $headers = []
    ) {}

    /**
     * Create JSON response
     */
    public function json(mixed $data, int $status = 200): self
    {
        return new self(
            json_encode($data, JSON_THROW_ON_ERROR),
            $status,
            ['Content-Type' => 'application/json']
        );
    }

    /**
     * Create HTML response
     */
    public function html(string $html, int $status = 200): self
    {
        return new self(
            $html,
            $status,
            ['Content-Type' => 'text/html']
        );
    }

    /**
     * Create plain text response
     */
    public function text(string $text, int $status = 200): self
    {
        return new self(
            $text,
            $status,
            ['Content-Type' => 'text/plain']
        );
    }

    /**
     * Set response header
     */
    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    /**
     * Set status code
     */
    public function withStatus(int $status): self
    {
        $this->status = $status;
        return $this;
    }

    /**
     * Send response to client
     */
    public function send(): void
    {
        // Set status code
        http_response_code($this->status);
        
        // Send headers
        foreach ($this->headers as $name => $value) {
            header("{$name}: {$value}");
        }
        
        // Send body
        echo $this->body;
    }

    /**
     * Get response body
     */
    public function getBody(): string
    {
        return $this->body;
    }

    /**
     * Get status code
     */
    public function getStatus(): int
    {
        return $this->status;
    }

    /**
     * Get headers
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }
}