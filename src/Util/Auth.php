<?php

declare(strict_types=1);

namespace Kniebes\SimpleRssReader\Util;

/**
 * Stateless Cookie-Login fuer den Single-User-Reader.
 *
 * Der Cookie `srr_auth` ist `<expiry>:<hmac>`, signiert per HMAC-SHA256 mit
 * AUTH_SECRET. Keine Session, kein DB-Eintrag - die Gueltigkeit steckt komplett
 * in der Signatur. `Kernel::environment()` muss vorher gelaufen sein (fuellt
 * AUTH_SECRET / AUTH_PASSWORD_HASH aus der .env nach $_ENV).
 */
final class Auth
{
    private const COOKIE_NAME = 'srr_auth';
    private const DEFAULT_LIFETIME = 31536000; // 1 Jahr in Sekunden

    public static function check(): bool
    {
        $secret = (string) ($_ENV['AUTH_SECRET'] ?? '');
        if ($secret === '') {
            // Ohne Secret waere jeder Cookie faelschbar -> fail closed.
            return false;
        }

        $cookie = (string) ($_COOKIE[self::COOKIE_NAME] ?? '');
        if (!str_contains($cookie, ':')) {
            return false;
        }

        [$expiry, $signature] = explode(':', $cookie, 2);
        if (!ctype_digit($expiry) || (int) $expiry < time()) {
            return false;
        }

        return hash_equals(self::sign($expiry), $signature);
    }

    /**
     * Guard fuer Web-Controller. CLI-Aufrufe (fetch/categorize per Cron) laufen
     * ungehindert durch.
     */
    public static function requireLogin(): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }
        if (self::check()) {
            return;
        }

        if (($_SERVER['HTTP_HX_REQUEST'] ?? '') === 'true') {
            // htmx folgt 302 nicht sinnvoll -> Full-Page-Redirect anstossen.
            header('HX-Redirect: /login.php');
            exit;
        }

        $redirect = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        header('Location: /login.php?redirect=' . urlencode($redirect), response_code: 302);
        exit;
    }

    public static function attempt(string $password): bool
    {
        // Der bcrypt-Hash wird base64-kodiert in der .env hinterlegt: base64
        // enthaelt kein `$`, deshalb kann Dotenv nichts als Variable expandieren
        // (ein roher Hash wuerde ohne einfache Quotes zerschossen).
        $hash = (string) base64_decode((string) ($_ENV['AUTH_PASSWORD_HASH'] ?? ''), true);
        if ($hash === '' || !password_verify($password, $hash)) {
            return false;
        }

        self::setCookie();

        return true;
    }

    public static function logout(): void
    {
        setcookie(self::COOKIE_NAME, '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'secure'   => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private static function setCookie(): void
    {
        $expiry = (string) (time() + self::lifetime());

        setcookie(self::COOKIE_NAME, $expiry . ':' . self::sign($expiry), [
            'expires'  => (int) $expiry,
            'path'     => '/',
            'secure'   => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private static function sign(string $expiry): string
    {
        return hash_hmac('sha256', $expiry, (string) ($_ENV['AUTH_SECRET'] ?? ''));
    }

    private static function lifetime(): int
    {
        $lifetime = (int) ($_ENV['AUTH_COOKIE_LIFETIME'] ?? 0);

        return $lifetime > 0 ? $lifetime : self::DEFAULT_LIFETIME;
    }
}
