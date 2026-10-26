<?php

declare(strict_types=1);

namespace Survos\Nara\Model;

final readonly class Record
{
    /**
     * @param DigitalObject[] $digitalObjects
     */
    public function __construct(
        public string|int|null $naId = null,
        public ?string $title = null,
        public ?string $levelOfDescription = null,
        public ?string $recordType = null,
        public string|bool|null $audiovisual = null,
        public ?array $onlineResources = [],
        public ?array $physicalOccurrences = [],
        public array $digitalObjects = [],
        public ?array $creators = [],
        public ?array $subjects = [],
        public ?array $pinnedTerms = [],
        public ?array $ancestors = [],
        public ?array $generalRecordsTypes = [],
        public ?array $useRestriction = [],
        public ?array $accessRestriction = [],
        public ?array $dataControlGroup = [],
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            naId: $data['naId'] ?? null,
            title: $data['title'] ?? null,
            levelOfDescription: $data['levelOfDescription'] ?? null,
            recordType: $data['recordType'] ?? null,
            audiovisual: $data['audiovisual'] ?? null,
            onlineResources: $data['onlineResources'] ?? [],
            physicalOccurrences: $data['physicalOccurrences'] ?? [],
            digitalObjects: array_map(
                static fn (array $o): DigitalObject => DigitalObject::fromArray($o),
                array_values(array_filter($data['digitalObjects'] ?? [], 'is_array')),
            ),
            creators: $data['creators'] ?? [],
            subjects: $data['subjects'] ?? [],
            pinnedTerms: $data['pinnedTerms'] ?? [],
            ancestors: $data['ancestors'] ?? [],
            generalRecordsTypes: $data['generalRecordsTypes'] ?? [],
            useRestriction: $data['useRestriction'] ?? [],
            accessRestriction: $data['accessRestriction'] ?? [],
            dataControlGroup: $data['dataControlGroup'] ?? [],
        );
    }
}
