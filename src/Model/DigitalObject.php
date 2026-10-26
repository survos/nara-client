<?php

declare(strict_types=1);

namespace Survos\Nara\Model;

final readonly class DigitalObject
{
    public function __construct(
        public ?string $objectId = null,
        public ?string $objectFilename = null,
        public ?string $objectUrl = null,
        public ?string $objectFileSize = null,
        public ?string $objectDesignator = null,
        public ?string $objectDescription = null,
        public ?string $objectType = null,
    ) {
    }

    public static function fromArray(array $data): self
    {
        // NARA returns some of these as numbers (e.g. objectFileSize, and
        // sometimes objectId), so coerce scalars to string rather than trip the
        // typed properties.
        $str = static fn (string $key): ?string => isset($data[$key]) && is_scalar($data[$key])
            ? (string) $data[$key]
            : null;

        return new self(
            objectId: $str('objectId'),
            objectFilename: $str('objectFilename'),
            objectUrl: $str('objectUrl'),
            objectFileSize: $str('objectFileSize'),
            objectDesignator: $str('objectDesignator'),
            objectDescription: $str('objectDescription'),
            objectType: $str('objectType'),
        );
    }
}
