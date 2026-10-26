<?php

declare(strict_types=1);

namespace Survos\Nara;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\RetryableHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class ClientFactory
{
    /**
     * @param HttpClientInterface|null $httpClient wrapped in a retrying client (429/5xx with backoff) unless it already is one
     */
    public static function create(
        string $apiKey,
        ?HttpClientInterface $httpClient = null,
        ?string $userId = null,
        ?string $supportEmail = null,
        ?string $supportEmailCc = null,
        string $baseUri = Client::BASE_URI,
    ): Client {
        $httpClient ??= HttpClient::create();
        if (!$httpClient instanceof RetryableHttpClient) {
            $httpClient = new RetryableHttpClient($httpClient);
        }

        return new Client($httpClient, $apiKey, $userId, $supportEmail, $supportEmailCc, $baseUri);
    }
}
