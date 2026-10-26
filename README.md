# survos/nara-client

PHP client for the [National Archives Catalog API v2](https://catalog.archives.gov/api/v2/): search, records, and
**contributing transcriptions, tags and comments** back to the national record. Replaces `survos/nara-php-api`.

Symfony apps should use [`survos/nara-bundle`](../../bu/nara-bundle), which configures the key and user id from
environment variables. This package never reads the environment.

```bash
composer require survos/nara-client
```

## Reading

```php
use Survos\Nara\ClientFactory;
use Survos\Nara\SearchQuery;

$client = ClientFactory::create($apiKey, supportEmail: 'you@example.org');

// One page of raw hits, with the total and the next offset (null on the last page).
$page = $client->searchPage(new SearchQuery(q: 'widow pension', recordGroup: '15', availableOnline: true, limit: 100));
echo $page->total;

// Every hit, paging lazily (limit is capped at the API maximum of 200 per request).
foreach ($client->iterate(SearchQuery::terms(['civil', 'war']), max: 1000) as $hit) { /* raw hit array */ }

// Typed
$record = $client->getRecordByNaId(170821856);          // Record
$objects = $client->getDigitalObjects(170821856);       // list<DigitalObject>: the record's pages/images

// Raw payload of a single record (the `body` of an exact naId_is lookup), for archiving as-is
$payload = $client->getRecordPayload(170821856);
```

`ClientFactory` wraps the HTTP client in `RetryableHttpClient`, so 429 and 5xx responses back off and retry.
Failures raise `NaraApiException` (with `statusCode`, `url`, `body`). The Catalog answers an unknown endpoint or
a rejected key with its HTML app shell and status 200; that is reported as an exception too.

Notes learned the hard way:

* Page with `limit`/`offset`. `rows` is silently ignored.
* `objectType` takes short keywords (`pdf`), not the stored label ("Portable Document File (PDF)").
* There is no `/records/{naId}/digitalObjects` endpoint; digital objects are part of the record.

## Contributing

Writes need a key with contributions enabled (email [Catalog_API@nara.gov](mailto:Catalog_API@nara.gov) with your
email and Catalog username) and your Catalog user UUID (the `userId` of any of your contributions, found with
`contributionsSearch(['userName' => '...'])`).

```php
$client = ClientFactory::create($apiKey, userId: $uuid);

foreach ($client->getDigitalObjects(170821856) as $object) {
    $result = $client->submitTranscription(170821856, $ocrText, $object->objectId);
    if (!$result->success) {
        // 401/422 are returned, not thrown, so a bulk loop can log and continue
    }
}
$client->submitTag(521451, 'president');
$client->submitComment(521451, 'Also appears in series ...');
```

A transcription attaches to one digital object; submitting again overwrites it (NARA keeps the history).

## Bulk data

For whole-catalog work use NARA's public S3 bucket instead of the API
([dataset page](https://www.archives.gov/developer/national-archives-catalog-dataset)). No credentials needed.
Verified 2026-10-26: the bucket is updated about twice a year; the newest zip is dated 2026-09-14.

```bash
# Newest full export: 217 GB (authorities: 48 MB)
curl -O https://nara-national-archives-catalog.s3.amazonaws.com/zip/nac_export_descriptions_2026-09-14.zip

# One record group as JSONL. Prefixes are unpadded: rg_11, not rg_011.
aws s3 sync s3://nara-national-archives-catalog/descriptions/record-groups/rg_11/ ./rg11/ --no-sign-request
```

Older dated zips stay in the bucket (`zip/nac_export_descriptions_2025-04-08.zip` is 93 GB).
