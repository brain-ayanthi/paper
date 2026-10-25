<?php

namespace App\Plugins;

final class VersionConstraint
{
    public static function matches(string $version, string $constraint): bool
    {
        $constraint = trim($constraint);
        if ($constraint === '' || $constraint === '*') {
            return true;
        }

        foreach (preg_split('/\s*,?\s+/', $constraint) ?: [] as $part) {
            if ($part === '') {
                continue;
            }

            if (! self::matchesPart($version, $part)) {
                return false;
            }
        }

        return true;
    }

    private static function matchesPart(string $version, string $part): bool
    {
        if (str_starts_with($part, '^')) {
            $floor = substr($part, 1);
            $segments = array_map('intval', explode('.', $floor));
            $major = $segments[0] ?? 0;
            $upper = $major > 0
                ? ($major + 1).'.0.0'
                : '0.'.(($segments[1] ?? 0) + 1).'.0';

            return version_compare($version, $floor, '>=') && version_compare($version, $upper, '<');
        }

        if (str_starts_with($part, '~')) {
            $floor = substr($part, 1);
            $segments = array_map('intval', explode('.', $floor));
            $upper = ($segments[0] ?? 0).'.'.(($segments[1] ?? 0) + 1).'.0';

            return version_compare($version, $floor, '>=') && version_compare($version, $upper, '<');
        }

        if (preg_match('/^(>=|<=|>|<|=)?\s*(\d+(?:\.\d+){0,3}(?:[-+][0-9A-Za-z.-]+)?)$/', $part, $m) !== 1) {
            return false;
        }

        return version_compare($version, $m[2], $m[1] ?: '=');
    }
}
