<?php

namespace Flc\Identity\Domain;

final class EmailAllowlist
{
    public static function isAllowed(string $email, bool $allowAll, array $patterns): bool
    {
        if ($allowAll) {
            return true;
        }

        $email = strtolower(trim($email));

        if ($patterns === []) {
            return false;
        }

        foreach ($patterns as $pattern) {
            if (self::matchesPattern($email, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $fromDb
     * @param  list<string>  $fromEnv
     * @return list<string>
     */
    public static function mergePatterns(array $fromDb, array $fromEnv): array
    {
        return array_values(array_unique(array_merge($fromDb, $fromEnv)));
    }

    private static function matchesPattern(string $email, string $pattern): bool
    {
        $pattern = strtolower(trim($pattern));

        if ($pattern === $email) {
            return true;
        }

        if (str_starts_with($pattern, '*@')) {
            $domain = substr($pattern, 2);

            return $domain !== '' && str_ends_with($email, '@'.$domain);
        }

        return false;
    }
}
