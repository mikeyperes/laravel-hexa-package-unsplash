<?php

namespace hexa_package_unsplash\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use hexa_core\Models\Setting;

class UnsplashService
{
    /**
     * @return string|null
     */
    private function getApiKey(): ?string
    {
        return Setting::getValue('unsplash_api_key');
    }

    /**
     * Test the API key.
     *
     * @param string|null $apiKey Override key to test.
     * @return array{success: bool, message: string}
     */
    public function testApiKey(?string $apiKey = null): array
    {
        $key = $apiKey ?? $this->getApiKey();
        if (!$key) {
            return ['success' => false, 'message' => 'No Unsplash API key configured.'];
        }

        try {
            $response = Http::withHeaders(['Authorization' => "Client-ID {$key}"])
                ->timeout(10)
                ->get('https://api.unsplash.com/photos/random');

            if ($response->successful()) {
                $remaining = $response->header('X-Ratelimit-Remaining') ?? '?';
                return ['success' => true, 'message' => "Unsplash API key is valid. Rate limit remaining: {$remaining}."];
            }
            if ($response->status() === 401) {
                return ['success' => false, 'message' => 'Invalid API key.'];
            }
            return ['success' => false, 'message' => "Unsplash returned HTTP {$response->status()}."];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }

    /**
     * Search for photos.
     *
     * @param string $query
     * @param int $perPage
     * @param int $page
     * @return array{success: bool, message: string, data: array|null}
     */
    public function searchPhotos(string $query, int $perPage = 15, int $page = 1): array
    {
        $key = $this->getApiKey();
        if (!$key) {
            return ['success' => false, 'message' => 'No Unsplash API key configured.', 'data' => null];
        }

        try {
            $response = Http::withHeaders(['Authorization' => "Client-ID {$key}"])
                ->timeout(15)
                ->get('https://api.unsplash.com/search/photos', [
                    'query' => $query,
                    'per_page' => min($perPage, 30),
                    'page' => $page,
                ]);

            if ($response->successful()) {
                $data = $response->json();
                $photos = collect($data['results'] ?? [])->map(fn($p) => [
                    'source' => 'unsplash',
                    'id' => $p['id'],
                    'url_thumb' => $p['urls']['small'] ?? $p['urls']['thumb'],
                    'url_full' => $p['urls']['full'],
                    'url_large' => $p['urls']['regular'],
                    'alt' => $p['alt_description'] ?? $p['description'] ?? '',
                    'photographer' => $p['user']['name'] ?? '',
                    'photographer_url' => $p['user']['links']['html'] ?? '',
                    'width' => $p['width'],
                    'height' => $p['height'],
                    'unsplash_url' => $p['links']['html'] ?? '',
                    'download_url' => $p['links']['download'] ?? $p['urls']['full'],
                    'attribution_required' => true,
                ])->toArray();

                return [
                    'success' => true,
                    'message' => count($photos) . ' photos found.',
                    'data' => ['photos' => $photos, 'total' => $data['total'] ?? 0, 'page' => $page],
                ];
            }

            return ['success' => false, 'message' => "Unsplash returned HTTP {$response->status()}.", 'data' => null];
        } catch (\Exception $e) {
            Log::error('UnsplashService::searchPhotos error', ['query' => $query, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage(), 'data' => null];
        }
    }
}
