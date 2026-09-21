<?php

namespace Flc\AdminSettings\Infrastructure\Persistence;

use App\Models\AppSetting;
use Flc\AdminSettings\Application\Repository\AppSettingsRepository;
use Illuminate\Support\Facades\Cache;

final class EloquentAppSettingsRepository implements AppSettingsRepository
{
    private const CACHE_KEY = 'flc_app_settings';

    public function get(string $key, mixed $default = null): mixed
    {
        $all = $this->all();

        return $all[$key] ?? $default;
    }

    public function getBool(string $key, bool $default = false): bool
    {
        $value = $this->get($key, $default);

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    public function set(string $key, mixed $value): void
    {
        AppSetting::query()->updateOrCreate(
            ['key' => $key],
            ['value' => is_bool($value) ? ($value ? '1' : '0') : (string) $value]
        );

        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function setMany(array $values): void
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value);
        }
    }

    /**
     * @return array<string, string|null>
     */
    public function all(): array
    {
        return Cache::remember(self::CACHE_KEY, 300, function () {
            return AppSetting::query()
                ->pluck('value', 'key')
                ->all();
        });
    }
}
