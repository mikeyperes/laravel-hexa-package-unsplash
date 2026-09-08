<?php

namespace Tests\Feature;

use hexa_core\Security\Http\OutboundHttpException;
use hexa_core\Security\Http\OutboundHttpRequest;
use hexa_core\Security\Http\OutboundHttpResponse;
use hexa_core\Security\Http\OutboundUrlGuard;
use hexa_core\Security\Http\SafeOutboundHttpClient;
use hexa_package_unsplash\Services\UnsplashService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class UnsplashServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->requireInstalledPackage('hexawebsystems/laravel-hexa-package-unsplash', UnsplashService::class);
        Http::preventStrayRequests();
    }

    public function test_api_key_probe_uses_bounded_pinned_transport(): void
    {
        $requests = [];
        $service = $this->service($requests, new OutboundHttpResponse(
            200,
            ['X-Ratelimit-Remaining' => '48'],
            '{"id":"photo-1"}',
        ));

        $result = $service->testApiKey('fixture-key');

        $this->assertTrue($result['success']);
        $this->assertSame('Unsplash API key is valid. Rate limit remaining: 48.', $result['message']);
        $this->assertCount(1, $requests);
        $this->assertSame('https://api.unsplash.com/photos/random', $requests[0]->target->url);
        $this->assertSame('Client-ID fixture-key', $requests[0]->headers['Authorization']);
        $this->assertSame(10, $requests[0]->timeoutSeconds);
        $this->assertSame(512 * 1024, $requests[0]->maxResponseBytes);
        Http::assertNothingSent();
    }

    public function test_search_contract_owns_endpoint_credentials_and_request_bounds(): void
    {
        $requests = [];
        $service = $this->service($requests, new OutboundHttpResponse(200, [], '{"results":[]}'));

        $request = $service->searchRequest('  city   skyline  ', 999, 0, 'fixture-key');

        $this->assertNotNull($request);
        $this->assertSame('GET', $request['method']);
        $this->assertStringStartsWith('https://api.unsplash.com/search/photos?', $request['url']);
        parse_str((string) parse_url($request['url'], PHP_URL_QUERY), $query);
        $this->assertSame('city skyline', $query['query']);
        $this->assertSame('30', $query['per_page']);
        $this->assertSame('1', $query['page']);
        $this->assertSame('Client-ID fixture-key', $request['options']['headers']['Authorization']);
        $this->assertSame(15, $request['options']['timeout']);
        $this->assertSame(4 * 1024 * 1024, $request['options']['max_bytes']);
        $this->assertSame(0, $request['options']['max_redirects']);
    }

    public function test_search_response_is_normalized_to_the_compatible_photo_shape(): void
    {
        $requests = [];
        $service = $this->service($requests, new OutboundHttpResponse(200, [], '{}'));
        $response = new OutboundHttpResponse(200, [], json_encode([
            'results' => [[
                'id' => 'photo-42',
                'urls' => [
                    'thumb' => 'https://images.unsplash.com/thumb.jpg',
                    'small' => 'https://images.unsplash.com/small.jpg',
                    'regular' => 'https://images.unsplash.com/regular.jpg',
                    'full' => 'https://images.unsplash.com/full.jpg',
                ],
                'links' => [
                    'html' => 'https://unsplash.com/photos/photo-42',
                    'download' => 'https://unsplash.com/photos/photo-42/download',
                ],
                'alt_description' => 'City skyline',
                'user' => [
                    'name' => 'Photographer',
                    'links' => ['html' => 'https://unsplash.com/@photographer'],
                ],
                'width' => 2400,
                'height' => 1600,
            ]],
            'total' => 120,
            'page' => 2,
        ], JSON_THROW_ON_ERROR));

        $result = $service->parseSearchResponse($response);
        $photo = $result['data']['photos'][0];

        $this->assertTrue($result['success']);
        $this->assertSame(120, $result['data']['total']);
        $this->assertSame('unsplash', $photo['source']);
        $this->assertSame($photo['source_url'], $photo['unsplash_url']);
        $this->assertSame('https://images.unsplash.com/regular.jpg', $photo['url_large']);
        $this->assertTrue($photo['attribution_required']);
    }

    public function test_redirect_and_transport_failures_do_not_leak_credentials_or_provider_detail(): void
    {
        $requests = [];
        $service = $this->service($requests, new OutboundHttpResponse(
            302,
            ['Location' => 'https://redirect.example.net/collect?secret=fixture-key'],
            'fixture-key provider detail',
        ));

        $result = $service->testApiKey('fixture-key');
        $pooled = $service->parseSearchResponse(new OutboundHttpException('transport_failed'));
        $serialized = json_encode([$result, $pooled], JSON_THROW_ON_ERROR);

        $this->assertFalse($result['success']);
        $this->assertFalse($pooled['success']);
        $this->assertStringNotContainsString('fixture-key', $serialized);
        $this->assertStringNotContainsString('provider detail', $serialized);
        $this->assertCount(1, $requests);
        Http::assertNothingSent();
    }

    public function test_service_source_has_no_raw_http_or_exception_detail_fallback(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/src/Services/UnsplashService.php');

        $this->assertStringContainsString('SafeOutboundHttpClient', $source);
        $this->assertStringNotContainsString('Facades\\Http', $source);
        $this->assertStringNotContainsString('Http::', $source);
        $this->assertStringNotContainsString('->getMessage()', $source);
    }

    /** @param list<OutboundHttpRequest> $requests */
    private function service(array &$requests, OutboundHttpResponse $response): UnsplashService
    {
        $client = new SafeOutboundHttpClient(
            new OutboundUrlGuard(static fn (string $host): array => ['93.184.216.34']),
            static function (OutboundHttpRequest $request) use (&$requests, $response): OutboundHttpResponse {
                $requests[] = $request;

                return $response;
            },
        );

        return new UnsplashService($client);
    }
}
