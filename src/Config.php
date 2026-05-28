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
        return Env::get('FRONTEND_URL', 'http://localhost:5173') ?? 'http://localhost:5173';
    }

    public static function allowedFrontendOrigin(): string
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? null;

        if ($origin === null) {
            return self::frontendUrl();
        }

        $allowed = Env::get('FRONTEND_ALLOWED', '');
        if ($allowed === null || trim($allowed) === '') {
            return $origin;
        }

        $list = array_map('trim', explode(',', $allowed));
        foreach ($list as $item) {
            if ($item === $origin) {
                return $origin;
            }
        }

        return self::frontendUrl();
    }
}