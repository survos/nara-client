<?php

declare(strict_types=1);

namespace Survos\Nara;

/**
 * One page of search hits, still as the API's raw arrays.
 */
final readonly class SearchPage
{
    /**
     * @param list<array<string, mixed>> $hits
     * @param int|null                   $nextOffset null once the last page has been read
     */
    public function __construct(
        public array $hits,
        public int $total,
        public ?int $nextOffset,
    ) {
    }

    /**
     * @param array<string, mixed> $response decoded JSON of a search call
     */
    public static function fromResponse(array $response, int $offset): self
    {
        $hits = $response['body']['hits']['hits'] ?? [];
        $total = (int) ($response['body']['hits']['total']['value'] ?? 0);

        $consumed = $offset + \count($hits);
        $next = [] !== $hits && $consumed < $total ? $consumed : null;

        return new self(array_values($hits), $total, $next);
    }
}
