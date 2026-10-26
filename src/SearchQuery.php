<?php

declare(strict_types=1);

namespace Survos\Nara;

/**
 * Parameters for GET /records/search (and, where they apply, /transcriptions/search).
 *
 * NARA paginates with `limit` (max 200) and `offset`; the older `rows` parameter
 * is silently ignored and leaves every search at the default page size of 20.
 */
final readonly class SearchQuery
{
    /**
     * @param string|list<string>|null $objectType short keyword(s) such as "pdf", NOT the stored label
     *                                             "Portable Document File (PDF)" — the spaces/parens tokenize
     *                                             into an OR query that matches nearly everything
     * @param array<string, scalar>    $extra      any other API parameter, passed through unchanged
     */
    public function __construct(
        public ?string $q = null,
        public string|int|null $naId = null,
        public ?string $recordGroup = null,
        public ?string $startDate = null,
        public ?string $endDate = null,
        public bool $availableOnline = false,
        public string|array|null $objectType = null,
        public bool $transcriptionsExist = false,
        public int $limit = 20,
        public int $offset = 0,
        public array $extra = [],
    ) {
    }

    /**
     * @param list<string> $terms joined with spaces into q
     */
    public static function terms(array $terms, int $limit = 20, int $offset = 0): self
    {
        return new self(q: implode(' ', $terms), limit: $limit, offset: $offset);
    }

    public function withPage(int $limit, int $offset): self
    {
        return new self(
            $this->q, $this->naId, $this->recordGroup, $this->startDate, $this->endDate,
            $this->availableOnline, $this->objectType, $this->transcriptionsExist,
            $limit, $offset, $this->extra,
        );
    }

    /**
     * @return array<string, scalar>
     */
    public function toParams(): array
    {
        $params = [];
        if (null !== $this->q && '' !== $this->q) {
            $params['q'] = $this->q;
        }
        if (null !== $this->naId && '' !== $this->naId) {
            $params['naId'] = (string) $this->naId;
        }
        if (null !== $this->recordGroup && '' !== $this->recordGroup) {
            $params['recordGroup'] = $this->recordGroup;
        }
        if (null !== $this->startDate && '' !== $this->startDate) {
            $params['startDate'] = $this->startDate;
        }
        if (null !== $this->endDate && '' !== $this->endDate) {
            $params['endDate'] = $this->endDate;
        }
        if ($this->availableOnline) {
            $params['availableOnline'] = 'true';
        }
        if (null !== $this->objectType && [] !== $this->objectType && '' !== $this->objectType) {
            $params['objectType'] = \is_array($this->objectType) ? implode(',', $this->objectType) : $this->objectType;
        }
        if ($this->transcriptionsExist) {
            $params['transcriptions_exist'] = 'true';
        }
        $params['limit'] = max(1, min($this->limit, Client::MAX_LIMIT));
        $params['offset'] = max(0, $this->offset);

        return $params + $this->extra;
    }
}
