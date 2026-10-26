<?php

declare(strict_types=1);

namespace Survos\Nara\Model;

final readonly class TranscriptionResult
{
    public function __construct(
        public ?string $contributionId = null,
        public ?string $contributionType = null,
        public ?string $contribution = null,
        public ?int $contributionSequence = null,
        public ?array $contributors = [],
        public ?array $target = [],
    ) {
    }

    public static function fromArray(array $data): self
    {
        $source = $data['_source'] ?? [];
        $record = $source['record'] ?? [];

        return new self(
            contributionId: $record['contributionId'] ?? null,
            contributionType: $record['contributionType'] ?? null,
            contribution: $record['contribution'] ?? null,
            contributionSequence: $record['contributionSequence'] ?? null,
            contributors: $record['contributors'] ?? [],
            target: $record['target'] ?? [],
        );
    }
}
