<?php

if (!function_exists('food_order_load_env')) {
    function food_order_load_env(?string $path = null): void
    {
        static $loaded = false;

        if ($loaded) {
            return;
        }

        $loaded = true;
        $path = $path ?? (__DIR__ . '/../.env');

        if (!is_readable($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || strpos($line, '#') === 0 || strpos($line, '=') === false) {
                continue;
            }

            [$name, $value] = explode('=', $line, 2);
            $name = trim($name);

            if ($name === '') {
                continue;
            }

            $value = trim($value);
            $length = strlen($value);

            if ($length >= 2) {
                $first = $value[0];
                $last = $value[$length - 1];

                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $value = substr($value, 1, -1);
                }
            }

            putenv($name . '=' . $value);
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }
}

if (!function_exists('food_order_env')) {
    function food_order_env(string $key, $default = null)
    {
        $value = getenv($key);

        if ($value === false || $value === '') {
            return $default;
        }

        return $value;
    }
}

food_order_load_env();
