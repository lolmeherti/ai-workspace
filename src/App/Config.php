<?php

namespace App;

use Dotenv\Dotenv;

class Config
{
    private static bool $loaded = false;

    public static function load(string $path): void
    {
        if (!self::$loaded) {
            $dotenv = Dotenv::createImmutable($path);
            $dotenv->safeLoad();
            self::$loaded = true;
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_ENV[$key] ?? $default;
    }

    public static function getProjectRoot(): string
    {
        return dirname(__DIR__);
    }
}