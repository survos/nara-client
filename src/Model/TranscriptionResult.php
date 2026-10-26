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

    /**
     * The digital object (page) this transcription is attached to.
     */
    public function objectId(): ?string
    {
        $id = $this->target['objectId'] ?? null;

        return \is_scalar($id) ? (string) $id : null;
    }

    /**
     * Catalog user names of everyone who has contributed to this transcription.
     *
     * @return list<string>
     */
    public function contributorNames(): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $c): ?string => \is_array($c) && isset($c['userName']) ? (string) $c['userName'] : null,
            $this->contributors ?? [],
        )));
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
