<?php

namespace Flc\Identity\Infrastructure\Persistence;

use App\Models\AllowedEmailEntry as AllowedEmailEntryModel;
use Flc\Identity\Application\Repository\AllowedEmailRepository;
use Flc\Identity\Domain\AllowedEmail;
use Flc\Shared\Application\PaginatedResult;

final class EloquentAllowedEmailRepository implements AllowedEmailRepository
{
    /**
     * @return list<string>
     */
    public function activePatterns(): array
    {
        return AllowedEmailEntryModel::query()
            ->where('is_active', true)
            ->pluck('pattern')
            ->all();
    }

    public function countActive(): int
    {
        return AllowedEmailEntryModel::query()
            ->where('is_active', true)
            ->count();
    }

    public function paginate(int $perPage = 20): PaginatedResult
    {
        $page = AllowedEmailEntryModel::query()
            ->orderByDesc('created_at')
            ->paginate($perPage);

        $items = [];
        foreach ($page->items() as $model) {
            $items[] = $this->toDomain($model);
        }

        return new PaginatedResult(
            items: $items,
            total: $page->total(),
            perPage: $page->perPage(),
            currentPage: $page->currentPage(),
            lastPage: $page->lastPage(),
        );
    }

    public function find(int $id): ?AllowedEmail
    {
        $model = AllowedEmailEntryModel::query()->find($id);

        return $model ? $this->toDomain($model) : null;
    }

    public function save(AllowedEmail $entry): AllowedEmail
    {
        if ($entry->id === null) {
            $model = AllowedEmailEntryModel::query()->create([
                'pattern' => $entry->pattern,
                'label' => $entry->label,
                'is_active' => $entry->isActive,
            ]);
        } else {
            $model = AllowedEmailEntryModel::query()->findOrFail($entry->id);
            $model->update([
                'pattern' => $entry->pattern,
                'label' => $entry->label,
                'is_active' => $entry->isActive,
            ]);
        }

        return $this->toDomain($model->fresh());
    }

    public function delete(int $id): void
    {
        AllowedEmailEntryModel::query()->whereKey($id)->delete();
    }

    private function toDomain(AllowedEmailEntryModel $model): AllowedEmail
    {
        return new AllowedEmail(
            id: $model->id,
            pattern: $model->pattern,
            label: $model->label,
            isActive: (bool) $model->is_active,
        );
    }
}
