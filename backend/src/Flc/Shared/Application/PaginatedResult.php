<?php

namespace Flc\Shared\Application;

/**
 * Framework-free page of items for Application / Query results.
 * Delivery adapters may wrap this into a framework paginator for views.
 *
 * @template T
 */
final class PaginatedResult
{
    /**
     * @param  list<T>  $items
     */
    public function __construct(
        public readonly array $items,
        public readonly int $total,
        public readonly int $perPage,
        public readonly int $currentPage,
        public readonly int $lastPage,
    ) {}

    public function isEmpty(): bool
    {
        return $this->items === [];
    }
}
