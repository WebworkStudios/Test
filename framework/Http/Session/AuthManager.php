<?php


declare(strict_types=1);

namespace framework\Http\Session;

final class AuthManager implements AuthInterface
{
    private const string USER_KEY = '_framework_user';

    public function __construct(
        private readonly Session $session
    )
    {
    }

    public function login(string|int $userId, array $userData = []): void
    {
        $this->session->regenerate();

        $_SESSION[self::USER_KEY] = [
            'id' => $userId,
            'data' => $userData,
            'login_time' => time(),
            'last_activity' => time()
        ];
    }

    public function logout(): void
    {
        unset($_SESSION[self::USER_KEY]);
        $this->session->regenerate();
    }

    public function id(): string|int|null
    {
        return $_SESSION[self::USER_KEY]['id'] ?? null;
    }

    public function user(): array
    {
        return $_SESSION[self::USER_KEY]['data'] ?? [];
    }

    public function touch(): void
    {
        if ($this->check()) {
            $_SESSION[self::USER_KEY]['last_activity'] = time();
        }
    }

    public function check(): bool
    {
        return isset($_SESSION[self::USER_KEY]['id']);
    }
}