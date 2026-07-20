<?php

function stockex_load_env()
{
    $paths = [
        '/etc/stockex/stockex.env',
        dirname(__DIR__) . '/.env',
    ];

    foreach ($paths as $path) {
        if (!file_exists($path)) {
            continue;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = ltrim($line);

            if ($line === '' || $line[0] === '#' || $line[0] === ';') {
                continue;
            }

            $parts = explode('=', $line, 2);

            if (count($parts) !== 2) {
                continue;
            }

            $key = trim($parts[0]);
            $value = trim($parts[1]);

            if ($key === '') {
                continue;
            }

            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }

        return;
    }
}

function env($key, $default = null)
{
    $value = getenv($key);

    if ($value === false || $value === '') {
        return $default;
    }

    return $value;
}

stockex_load_env();
