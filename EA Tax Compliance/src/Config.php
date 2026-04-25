<?php
declare(strict_types=1);

namespace EA\BI;

final class Config
{
    private static array $data = [];

    public static function load(string $rootDir): void
    {
        $envFile = $rootDir . DIRECTORY_SEPARATOR . '.env';
        if (!is_file($envFile)) {
            $envFile = $rootDir . DIRECTORY_SEPARATOR . '.env.example';
        }
        if (is_file($envFile)) {
            foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }
                if (!str_contains($line, '=')) {
                    continue;
                }
                [$key, $val] = explode('=', $line, 2);
                $key = trim($key);
                $val = trim($val);
                // strip optional surrounding quotes
                if ((str_starts_with($val, '"') && str_ends_with($val, '"')) ||
                    (str_starts_with($val, "'") && str_ends_with($val, "'"))) {
                    $val = substr($val, 1, -1);
                }
                self::$data[$key] = $val;
            }
        }
        // environment variables override file values
        foreach (self::$data as $k => $_) {
            $env = getenv($k);
            if ($env !== false && $env !== '') {
                self::$data[$k] = $env;
            }
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        return self::$data[$key] ?? $default;
    }

    public static function all(): array
    {
        return self::$data;
    }

    public static function set(string $key, string $value): void
    {
        self::$data[$key] = $value;
    }
}
