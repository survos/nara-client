<?php

declare(strict_types=1);

/*
 * This file is part of Survos' NARA PHP API project.
 *
 * (c) Survos <hello@survos.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Survos\Nara\Model;

/**
 * Result of a write/contribution to the NARA Catalog (a tag, transcription, or
 * comment submitted via POST to the api/v2 contribution endpoints).
 *
 * The Catalog returns a small confirmation payload on success (HTTP 200). The
 * exact shape is not formally specified, so the full decoded body is preserved
 * in {@see $raw} and the commonly-present fields are surfaced for convenience.
 */
final readonly class ContributionResult
{
    /**
     * @param 'tag'|'transcription'|'comment' $type
     * @param array<string, mixed>            $raw  full decoded response body
     */
    public function __construct(
        public bool $success,
        public string $type,
        public int $targetNaId,
        public int $statusCode,
        public ?string $contributionId = null,
        public ?string $message = null,
        public array $raw = [],
    ) {
    }

    public static function fromResponse(string $type, int $targetNaId, int $statusCode, array $body): self
    {
        return new self(
            success: $statusCode >= 200 && $statusCode < 300,
            type: $type,
            targetNaId: $targetNaId,
            statusCode: $statusCode,
            contributionId: $body['contributionId'] ?? $body['id'] ?? null,
            message: $body['message'] ?? $body['detail'] ?? $body['error'] ?? null,
            raw: $body,
        );
    }
}
