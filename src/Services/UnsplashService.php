<?php

namespace hexa_package_unsplash\Services;

use hexa_core\Models\Setting;
use hexa_core\Security\Http\OutboundHttpException;
use hexa_core\Security\Http\OutboundHttpResponse;
use hexa_core\Security\Http\SafeOutboundHttpClient;
use Illuminate\Support\Facades\Log;

class UnsplashService
{
    private const API_BASE = 'https://api.unsplash.com';

    private const PROBE_TIMEOUT_SECONDS = 10;

    private const SEARCH_TIMEOUT_SECONDS = 15;

    private const PROBE_MAX_RESPONSE_BYTES = 512 * 1024;

    private const SEARCH_MAX_RESPONSE_BYTES = 4 * 1024 * 1024;

    private const MAX_PER_PAGE = 30;

    public function __construct(private readonly SafeOutboundHttpClient $http) {}

    /** @return array{success: bool, message: string} */
    public function testApiKey(?string $apiKey = null): array
    {
        $key = $this->validApiKey($apiKey ?? $this->getApiKey());
        if ($key === null) {
            return ['success' => false, 'message' => 'No Unsplash API key configured.'];
        }

        try {
            $response = $this->execute($this->request(
                '/photos/random',
                $key,
                [],
                self::PROBE_TIMEOUT_SECONDS,
                self::PROBE_MAX_RESPONSE_BYTES,
            ));
        } catch (OutboundHttpException $exception) {
            $this->logTransportFailure('probe', $exception);

            return ['success' => false, 'message' => 'Unsplash API key validation failed safely.'];
        }

        if ($response->successful()) {
            $remaining = $response->headerValues('x-ratelimit-remaining')[0] ?? '?';

            return ['success' => true, 'message' => "Unsplash API key is valid. Rate limit remaining: {$remaining}."];
        }

        if ($response->status === 401) {
            return ['success' => false, 'message' => 'Invalid API key.'];
        }

        return ['success' => false, 'message' => "Unsplash returned HTTP {$response->status}."];
    }

    /**
     * Build the provider-owned request contract used by direct and pooled searches.
     *
     * @return array{method: string, url: string, options: array<string, mixed>}|null
     */
    public function searchRequest(
        string $query,
        int $perPage = 15,
        int $page = 1,
        ?string $apiKey = null,
    ): ?array {
        $key = $this->validApiKey($apiKey ?? $this->getApiKey());
        $query = $this->normalizeQuery($query);
        if ($key === null || $query === '') {
            return null;
        }

        return $this->request(
            '/search/photos',
            $key,
            [
                'query' => $query,
                'per_page' => max(1, min($perPage, self::MAX_PER_PAGE)),
                'page' => max(1, min($page, 1000)),
            ],
            self::SEARCH_TIMEOUT_SECONDS,
            self::SEARCH_MAX_RESPONSE_BYTES,
        );
    }

    /** @return array{success: bool, message: string, data: array|null} */
    public function searchPhotos(string $query, int $perPage = 15, int $page = 1): array
    {
        if ($this->normalizeQuery($query) === '') {
            return ['success' => false, 'message' => 'An Unsplash search query is required.', 'data' => null];
        }

        $request = $this->searchRequest($query, $perPage, $page);
        if ($request === null) {
            return ['success' => false, 'message' => 'No Unsplash API key configured.', 'data' => null];
        }

        try {
            return $this->parseSearchResponse($this->execute($request));
        } catch (OutboundHttpException $exception) {
            $this->logTransportFailure('search', $exception);

            return $this->failedSearch('Unsplash request failed safely.');
        }
    }

    /**
     * Normalize a pooled provider response without exposing provider or transport detail.
     *
     * @return array{success: bool, message: string, data: array|null}
     */
    public function parseSearchResponse(OutboundHttpResponse|OutboundHttpException|null $response): array
    {
        if ($response instanceof OutboundHttpException || ! $response instanceof OutboundHttpResponse) {
            return $this->failedSearch('Unsplash request failed safely.');
        }

        if (! $response->successful()) {
            return $this->failedSearch("Unsplash returned HTTP {$response->status}.");
        }

        $payload = $response->json();
        if (! is_array($payload) || ! is_array($payload['results'] ?? [])) {
            return $this->failedSearch('Unsplash returned an invalid response.');
        }

        $photos = [];
        foreach ($payload['results'] as $photo) {
            if (! is_array($photo)) {
                continue;
            }

            $urls = is_array($photo['urls'] ?? null) ? $photo['urls'] : [];
            $links = is_array($photo['links'] ?? null) ? $photo['links'] : [];
            $user = is_array($photo['user'] ?? null) ? $photo['user'] : [];
            $userLinks = is_array($user['links'] ?? null) ? $user['links'] : [];
            $sourceUrl = is_string($links['html'] ?? null) ? $links['html'] : '';
            $fullUrl = $urls['full'] ?? null;
            $photos[] = [
                'source' => 'unsplash',
                'id' => $photo['id'] ?? null,
                'url_thumb' => $urls['small'] ?? $urls['thumb'] ?? $urls['regular'] ?? null,
                'url_full' => $fullUrl,
                'url_large' => $urls['regular'] ?? $fullUrl,
                'source_url' => $sourceUrl,
                'unsplash_url' => $sourceUrl,
                'alt' => is_string($photo['alt_description'] ?? null)
                    ? $photo['alt_description']
                    : (is_string($photo['description'] ?? null) ? $photo['description'] : ''),
                'photographer' => is_string($user['name'] ?? null) ? $user['name'] : '',
                'photographer_url' => is_string($userLinks['html'] ?? null) ? $userLinks['html'] : '',
                'width' => max(0, (int) ($photo['width'] ?? 0)),
                'height' => max(0, (int) ($photo['height'] ?? 0)),
                'download_url' => $links['download'] ?? $fullUrl,
                'attribution_required' => true,
            ];
        }

        return [
            'success' => true,
            'message' => count($photos).' photos found.',
            'data' => [
                'photos' => $photos,
                'total' => max(0, (int) ($payload['total'] ?? 0)),
                'page' => max(1, (int) ($payload['page'] ?? 1)),
            ],
        ];
    }

    private function getApiKey(): ?string
    {
        return Setting::getValue('unsplash_api_key');
    }

    /**
     * @param  array<string, string|int>  $query
     * @return array{method: string, url: string, options: array<string, mixed>}
     */
    private function request(string $path, string $apiKey, array $query, int $timeout, int $maxBytes): array
    {
        $url = self::API_BASE.$path;
        if ($query !== []) {
            $url .= '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        return [
            'method' => 'GET',
            'url' => $url,
            'options' => [
                'headers' => [
                    'Accept' => 'application/json',
                    'Authorization' => 'Client-ID '.$apiKey,
                ],
                'timeout' => $timeout,
                'max_bytes' => $maxBytes,
                'max_redirects' => 0,
            ],
        ];
    }

    /** @param array{method: string, url: string, options: array<string, mixed>} $request */
    private function execute(array $request): OutboundHttpResponse
    {
        return $this->http->request($request['method'], $request['url'], $request['options']);
    }

    private function normalizeQuery(string $query): string
    {
        $query = preg_replace('/\s+/u', ' ', trim($query)) ?? '';

        return mb_substr($query, 0, 255);
    }

    private function validApiKey(?string $apiKey): ?string
    {
        $apiKey = trim((string) $apiKey);

        return $apiKey !== ''
            && strlen($apiKey) <= 4096
            && preg_match('/[\x00-\x1f\x7f]/', $apiKey) !== 1
                ? $apiKey
                : null;
    }

    /** @return array{success: false, message: string, data: null} */
    private function failedSearch(string $message): array
    {
        return ['success' => false, 'message' => $message, 'data' => null];
    }

    private function logTransportFailure(string $operation, OutboundHttpException $exception): void
    {
        Log::warning('Unsplash request failed safely', [
            'operation' => $operation,
            'failure_code' => $exception->failureCode(),
        ]);
    }
}
