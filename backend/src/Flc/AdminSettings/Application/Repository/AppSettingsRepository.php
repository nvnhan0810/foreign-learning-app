<?php

namespace Flc\AdminSettings\Application\Repository;

interface AppSettingsRepository
{
    public function get(string $key, mixed $default = null): mixed;

    public function getBool(string $key, bool $default = false): bool;

    public function set(string $key, mixed $value): void;

    /**
     * @param  array<string, mixed>  $values
     */
    public function setMany(array $values): void;

    /**
     * @return array<string, string|null>
     */
    public function all(): array;
}
