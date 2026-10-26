<?php

declare(strict_types=1);

namespace Survos\Nara\Model;

final readonly class SearchResult
{
    public function __construct(
        public ?string $naId = null,
        public ?Record $record = null,
    ) {
    }

    public static function fromArray(array $data): self
    {
        $recordData = $data['_source']['record'] ?? $data['record'] ?? [];

        return new self(
            naId: $data['_id'] ?? $recordData['naId'] ?? null,
            record: $recordData ? Record::fromArray($recordData) : null,
        );
    }
}
