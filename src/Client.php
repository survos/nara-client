<?php

declare(strict_types=1);

namespace Survos\Nara;

use Survos\Nara\Exception\NaraApiException;
use Survos\Nara\Model\ContributionResult;
use Survos\Nara\Model\DigitalObject;
use Survos\Nara\Model\Record;
use Survos\Nara\Model\SearchResult;
use Survos\Nara\Model\TranscriptionResult;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Client for the National Archives Catalog API v2.
 *
 * Reads need only an API key. Writes (transcriptions, tags, comments) also need
 * a key with contributions enabled (ask Catalog_API@nara.gov) and the
 * contributor's Catalog user UUID.
 */
final class Client
{
    public const BASE_URI = 'https://catalog.archives.gov/api/v2';
    public const MAX_LIMIT = 200;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $apiKey,
        private ?string $userId = null,
        private readonly ?string $supportEmail = null,
        private readonly ?string $supportEmailCc = null,
        private readonly string $baseUri = self::BASE_URI,
    ) {
    }

    public function setUserId(?string $userId): self
    {
        $this->userId = $userId;

        return $this;
    }

    /**
     * The Catalog user UUID attributed on every write, or null when none is configured.
     */
    public function getUserId(): ?string
    {
        return '' === $this->userId ? null : $this->userId;
    }

    // ------------------------------------------------------------------
    // Search
    // ------------------------------------------------------------------

    /**
     * Raw decoded response of GET /records/search.
     *
     * @param array<string, mixed>|SearchQuery $query
     *
     * @return array<string, mixed>
     */
    public function search(array|SearchQuery $query = []): array
    {
        return $this->get('/records/search', $this->params($query));
    }

    public function searchPage(SearchQuery $query): SearchPage
    {
        return SearchPage::fromResponse($this->search($query), $query->offset);
    }

    /**
     * Every raw hit of a search, fetching pages lazily until the results run out or
     * $max hits have been yielded.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function iterate(SearchQuery $query, ?int $max = null): \Generator
    {
        $yielded = 0;
        $offset = $query->offset;
        do {
            $limit = null === $max ? $query->limit : min($query->limit, $max - $yielded);
            $page = $this->searchPage($query->withPage($limit, $offset));
            foreach ($page->hits as $hit) {
                yield $yielded++ => $hit;
            }
            $offset = $page->nextOffset;
        } while (null !== $offset && (null === $max || $yielded < $max));
    }

    /**
     * @param array<string, mixed>|SearchQuery $query
     *
     * @return list<SearchResult>
     */
    public function searchWithDtos(array|SearchQuery $query = []): array
    {
        $hits = $this->search($query)['body']['hits']['hits'] ?? [];

        return array_map(SearchResult::fromArray(...), array_values($hits));
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return array<string, mixed>
     */
    public function transcriptionsSearch(array $query = []): array
    {
        return $this->get('/transcriptions/search', $query);
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return list<TranscriptionResult>
     */
    public function transcriptionsSearchWithDtos(array $query = []): array
    {
        $hits = $this->transcriptionsSearch($query)['body']['hits']['hits'] ?? [];

        return array_map(TranscriptionResult::fromArray(...), array_values($hits));
    }

    /**
     * Search existing contributions (transcriptions, tags, comments). Read-only.
     * Use it to look up your own Catalog user UUID (filter by `userName`, read
     * `userId` off a result) and to check what an object already carries before
     * overwriting it.
     *
     * @param array<string, mixed> $query
     *
     * @return array<string, mixed>
     */
    public function contributionsSearch(array $query = []): array
    {
        return $this->get('/contributions/search', $query);
    }

    // ------------------------------------------------------------------
    // Records
    // ------------------------------------------------------------------

    /**
     * The API's `body` for a single record lookup (a one-hit search envelope),
     * untouched. For callers that archive the raw payload.
     *
     * @return array<string, mixed>
     */
    public function getRecordPayload(string|int $naId): array
    {
        return $this->get('/records/search', ['naId_is' => (string) $naId, 'limit' => 1])['body'] ?? [];
    }

    public function getRecordByNaId(string|int $naId): ?Record
    {
        return $this->searchWithDtos(new SearchQuery(naId: $naId, limit: 1))[0]->record ?? null;
    }

    /**
     * @param list<string|int> $naIds
     *
     * @return list<Record>
     */
    public function getRecordsByNaIds(array $naIds): array
    {
        $results = $this->searchWithDtos(new SearchQuery(
            naId: implode(',', array_map('strval', $naIds)),
            limit: max(1, \count($naIds)),
        ));

        return array_values(array_filter(array_map(static fn (SearchResult $r): ?Record => $r->record, $results)));
    }

    /**
     * A record's digital objects (its pages/images). The Catalog has no separate
     * digital-objects endpoint; they come embedded in the record. A transcription
     * attaches to one of these, so this is how to resolve each page's objectId.
     *
     * @return list<DigitalObject>
     */
    public function getDigitalObjects(string|int $naId): array
    {
        return $this->getRecordByNaId($naId)?->digitalObjects ?? [];
    }

    // ------------------------------------------------------------------
    // Contributions (write)
    // ------------------------------------------------------------------

    /**
     * Submit the full text of one digital object. A new submission overwrites the
     * object's current transcription (the Catalog keeps revision history); an empty
     * string clears it.
     */
    public function submitTranscription(int|string $targetNaId, string $transcription, int|string $targetObjectId, ?string $userId = null): ContributionResult
    {
        return $this->contribute('transcription', $targetNaId, ['transcription' => $transcription], $userId, $targetObjectId);
    }

    public function submitTag(int|string $targetNaId, string $tag, ?string $userId = null): ContributionResult
    {
        return $this->contribute('tag', $targetNaId, ['tag' => $tag], $userId);
    }

    public function submitComment(int|string $targetNaId, string $comment, ?string $userId = null): ContributionResult
    {
        return $this->contribute('comment', $targetNaId, ['comment' => $comment], $userId);
    }

    /**
     * API-level refusals (401, 422) come back as a ContributionResult with
     * success=false so a bulk loop can log and continue. A missing user UUID throws,
     * since nothing can be attributed without it.
     *
     * @param 'tag'|'transcription'|'comment' $type
     * @param array<string, mixed>            $payload
     */
    private function contribute(string $type, int|string $targetNaId, array $payload, ?string $userId, int|string|null $targetObjectId = null): ContributionResult
    {
        $userId ??= $this->getUserId();
        if (null === $userId || '' === $userId) {
            throw new \InvalidArgumentException('A Catalog user UUID is required to write contributions: pass it to the call, Client::setUserId(), or configure user_id.');
        }

        $body = $payload + ['targetNaId' => (int) $targetNaId, 'userId' => $userId];
        if (null !== $targetObjectId && '' !== $targetObjectId) {
            $body['targetObjectId'] = (int) $targetObjectId;
        }

        [$status, $decoded] = $this->send('POST', '/'.$type.'s', [], $body);

        return ContributionResult::fromResponse($type, (int) $targetNaId, $status, $decoded);
    }

    // ------------------------------------------------------------------
    // Transport
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed>|SearchQuery $query
     *
     * @return array<string, mixed>
     */
    private function params(array|SearchQuery $query): array
    {
        return $query instanceof SearchQuery ? $query->toParams() : $query;
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return array<string, mixed>
     */
    private function get(string $path, array $query): array
    {
        [$status, $decoded] = $this->send('GET', $path, $query);
        if (200 !== $status) {
            throw new NaraApiException(\sprintf('NARA API error: HTTP %d for %s — %s', $status, $path, json_encode($decoded)), $status, $this->baseUri.$path, json_encode($decoded) ?: '');
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed>      $query
     * @param array<string, mixed>|null $json  sent as the JSON request body
     *
     * @return array{0: int, 1: array<string, mixed>} status and decoded body
     */
    private function send(string $method, string $path, array $query = [], ?array $json = null): array
    {
        $url = $this->baseUri.$path;
        $headers = ['x-api-key' => $this->apiKey, 'Accept' => 'application/json'];
        // NARA asks API users to identify themselves so they can be contacted about heavy use.
        if (null !== $this->supportEmail && '' !== $this->supportEmail) {
            $headers['From'] = $this->supportEmail;
        }
        if (null !== $this->supportEmailCc && '' !== $this->supportEmailCc) {
            $headers['CC'] = $this->supportEmailCc;
        }

        $options = ['headers' => $headers, 'timeout' => 30];
        if ([] !== $query) {
            $options['query'] = $query;
        }
        if (null !== $json) {
            $options['json'] = $json;
        }

        $response = $this->httpClient->request($method, $url, $options);
        $status = $response->getStatusCode();
        // false: 4xx/5xx must not throw here, so writes can report them and reads can say what came back.
        $body = $response->getContent(false);

        // An unknown path or a rejected key is answered with the catalog's HTML app shell, status 200.
        if (str_starts_with(strtolower(ltrim($body)), '<!doctype html')) {
            throw new NaraApiException(\sprintf('NARA API returned HTML instead of JSON for %s (HTTP %d): unknown endpoint or invalid API key.', $path, $status), $status, $url, mb_substr($body, 0, 400));
        }

        $decoded = '' === $body ? [] : json_decode($body, true);
        if (!\is_array($decoded)) {
            if ($status >= 200 && $status < 300) {
                throw new NaraApiException(\sprintf('Invalid JSON from NARA API (HTTP %d, %s): %s', $status, $path, mb_substr($body, 0, 400)), $status, $url, $body);
            }
            $decoded = ['raw' => $body];
        }

        return [$status, $decoded];
    }
}
