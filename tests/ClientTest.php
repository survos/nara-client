<?php

declare(strict_types=1);

namespace Survos\Nara\Tests;

use PHPUnit\Framework\TestCase;
use Survos\Nara\Client;
use Survos\Nara\ClientFactory;
use Survos\Nara\Exception\NaraApiException;
use Survos\Nara\SearchQuery;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ClientTest extends TestCase
{
    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    private array $requests = [];

    /**
     * @param list<MockResponse> $responses
     */
    private function client(array $responses, ?string $userId = null): Client
    {
        $this->requests = [];
        $mock = new MockHttpClient(function (string $method, string $url, array $options) use (&$responses): MockResponse {
            $this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];

            return array_shift($responses) ?? new MockResponse('{}');
        });

        return new Client($mock, 'KEY', $userId, 'me@example.org', 'cc@example.org');
    }

    private static function hits(int $total, int $count, int $from = 0): MockResponse
    {
        $hits = [];
        for ($i = 0; $i < $count; ++$i) {
            $id = (string) ($from + $i + 1);
            $hits[] = ['_id' => $id, '_source' => ['record' => ['naId' => $id, 'title' => 'T'.$id, 'digitalObjects' => [['objectId' => 9000 + $i, 'objectUrl' => 'https://x/'.$id.'.jpg']]]]];
        }

        return new MockResponse(json_encode(['body' => ['hits' => ['total' => ['value' => $total], 'hits' => $hits]]]));
    }

    public function testSearchQueryParams(): void
    {
        $params = (new SearchQuery(
            q: 'widow pension', recordGroup: '15', startDate: '1861-01-01', availableOnline: true,
            objectType: ['pdf', 'jpg'], limit: 999, offset: 40,
        ))->toParams();

        self::assertSame('widow pension', $params['q']);
        self::assertSame('15', $params['recordGroup']);
        self::assertSame('1861-01-01', $params['startDate']);
        self::assertSame('true', $params['availableOnline']);
        self::assertSame('pdf,jpg', $params['objectType']);
        self::assertSame(200, $params['limit'], 'limit is capped at the API maximum');
        self::assertSame(40, $params['offset']);
        self::assertArrayNotHasKey('rows', $params);
        self::assertArrayNotHasKey('naId', $params);
    }

    public function testRequestCarriesKeyAndIdentification(): void
    {
        $client = $this->client([self::hits(1, 1)]);
        $client->search(SearchQuery::terms(['civil', 'war']));

        $req = $this->requests[0];
        self::assertSame('GET', $req['method']);
        self::assertStringStartsWith('https://catalog.archives.gov/api/v2/records/search?', $req['url']);
        self::assertStringContainsString('q=civil%20war', $req['url']);
        $headers = implode("\n", $req['options']['headers']);
        self::assertStringContainsString('x-api-key: KEY', $headers);
        self::assertStringContainsString('From: me@example.org', $headers);
        self::assertStringContainsString('CC: cc@example.org', $headers);
    }

    public function testSearchPageReportsNextOffset(): void
    {
        $client = $this->client([self::hits(5, 2), self::hits(5, 1, 4)]);

        $first = $client->searchPage(new SearchQuery(q: 'x', limit: 2));
        self::assertSame(5, $first->total);
        self::assertSame(2, $first->nextOffset);

        $last = $client->searchPage(new SearchQuery(q: 'x', limit: 2, offset: 4));
        self::assertSame(5, $last->total);
        self::assertNull($last->nextOffset);
    }

    public function testIterateStopsAtTheEnd(): void
    {
        $client = $this->client([self::hits(3, 2), self::hits(3, 1, 2)]);

        $ids = [];
        foreach ($client->iterate(new SearchQuery(q: 'x', limit: 2)) as $hit) {
            $ids[] = $hit['_id'];
        }

        self::assertSame(['1', '2', '3'], $ids);
        self::assertCount(2, $this->requests);
        self::assertStringContainsString('offset=2', $this->requests[1]['url']);
    }

    public function testIterateHonoursMax(): void
    {
        $client = $this->client([self::hits(100, 2)]);

        $ids = array_map(static fn (array $h): string => $h['_id'], iterator_to_array($client->iterate(new SearchQuery(q: 'x', limit: 50), max: 2), false));

        self::assertSame(['1', '2'], $ids);
        self::assertCount(1, $this->requests);
        self::assertStringContainsString('limit=2', $this->requests[0]['url']);
    }

    public function testDigitalObjectsComeFromTheRecord(): void
    {
        $client = $this->client([self::hits(1, 1)]);

        $objects = $client->getDigitalObjects(1);

        self::assertCount(1, $objects);
        self::assertSame('9000', $objects[0]->objectId);
        self::assertStringContainsString('naId=1', $this->requests[0]['url']);
    }

    public function testRecordPayloadUsesExactNaIdLookup(): void
    {
        $client = $this->client([self::hits(1, 1)]);

        $body = $client->getRecordPayload(170821856);

        self::assertArrayHasKey('hits', $body);
        self::assertStringContainsString('naId_is=170821856', $this->requests[0]['url']);
    }

    public function testHtmlResponseIsReportedAsABadKeyOrEndpoint(): void
    {
        $client = $this->client([new MockResponse('<!doctype html><html></html>')]);

        $this->expectException(NaraApiException::class);
        $this->expectExceptionMessage('HTML');
        $client->search(['q' => 'x']);
    }

    public function testReadErrorsThrowWithStatus(): void
    {
        $client = $this->client([new MockResponse('{"message":"no"}', ['http_code' => 403])]);

        try {
            $client->search(['q' => 'x']);
            self::fail('expected exception');
        } catch (NaraApiException $e) {
            self::assertSame(403, $e->statusCode);
        }
    }

    public function testWriteNeedsAUserId(): void
    {
        $client = $this->client([]);

        $this->expectException(\InvalidArgumentException::class);
        $client->submitTag(1, 'x');
    }

    public function testEmptyUserIdCountsAsMissing(): void
    {
        self::assertNull($this->client([], '')->getUserId());
    }

    public function testTranscriptionPostBody(): void
    {
        $client = $this->client([new MockResponse('{"contributionId":"abc"}', ['http_code' => 201])], 'uuid-1');

        $result = $client->submitTranscription('170821856', 'Dear Sir', '170821859');

        self::assertTrue($result->success);
        self::assertSame('abc', $result->contributionId);
        $req = $this->requests[0];
        self::assertSame('POST', $req['method']);
        self::assertSame('https://catalog.archives.gov/api/v2/transcriptions', $req['url']);
        self::assertSame(
            ['transcription' => 'Dear Sir', 'targetNaId' => 170821856, 'userId' => 'uuid-1', 'targetObjectId' => 170821859],
            json_decode($req['options']['body'], true),
        );
    }

    public function testRefusedWriteIsReturnedNotThrown(): void
    {
        $client = $this->client([new MockResponse('{"message":"not enabled"}', ['http_code' => 401])], 'u');

        $result = $client->submitComment(5, 'hi');

        self::assertFalse($result->success);
        self::assertSame(401, $result->statusCode);
        self::assertSame('not enabled', $result->message);
    }

    public function testFactoryBuildsAClient(): void
    {
        self::assertInstanceOf(Client::class, ClientFactory::create('k', new MockHttpClient()));
    }
}
