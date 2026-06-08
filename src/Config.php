<?php

declare(strict_types=1);

namespace App;

final class Config
{
    public static function dbHost(): string
    {
        return Env::get('DB_HOST', '127.0.0.1') ?? '127.0.0.1';
    }

    public static function dbPort(): string
    {
        return Env::get('DB_PORT', '3306') ?? '3306';
    }

    public static function dbName(): string
    {
        return Env::get('DB_DATABASE', 'utslrc_sgi') ?? 'utslrc_sgi';
    }

    public static function dbUser(): string
    {
        return Env::get('DB_USERNAME', 'root') ?? 'root';
    }

    public static function dbPassword(): string
    {
        return Env::get('DB_PASSWORD', '') ?? '';
    }

    public static function frontendUrl(): string
    {
        $url = Env::get('FRONTEND_URL', 'http://localhost:5173') ?? 'http://localhost:5173';

        return self::normalizeOrigin($url);
    }

    public static function allowedFrontendOrigin(): string
    {
        $origin = self::normalizeOrigin($_SERVER['HTTP_ORIGIN'] ?? self::frontendUrl());

        $allowed = Env::get('FRONTEND_ALLOWED', '');
        if ($allowed === null || trim($allowed) === '') {
            return $origin;
        }

        $list = array_map('trim', explode(',', $allowed));
        foreach ($list as $item) {
            $candidate = self::normalizeOrigin($item);

            if ($candidate === '*' || $candidate === $origin) {
                return $origin;
            }

            if (str_contains($candidate, '*')) {
                $pattern = '/^' . str_replace(['\*', '\/'], ['.*', '\/'], preg_quote($candidate, '/')) . '$/i';
                if (preg_match($pattern, $origin) === 1) {
                    return $origin;
                }
            }
        }

        return self::frontendUrl();
    }

    private static function normalizeOrigin(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return 'http://localhost:5173';
        }

        if (str_starts_with($value, 'http://') || str_starts_with($value, 'https://')) {
            $parts = parse_url($value);

            if (is_array($parts) && isset($parts['scheme'], $parts['host'])) {
                $origin = $parts['scheme'] . '://' . $parts['host'];

                if (isset($parts['port'])) {
                    $origin .= ':' . $parts['port'];
                }

                return $origin;
            }
        }

        return rtrim($value, '/');
    }
}