<?php

namespace Tests\Feature;

use hexa_package_unsplash\Services\UnsplashService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class UnsplashServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->requireInstalledPackage("hexawebsystems/laravel-hexa-package-unsplash", UnsplashService::class);
    }

    public function test_api_key_probe_uses_provider_endpoint(): void
    {
        Http::fake(["*api.unsplash.com/*" => Http::response(["id" => "photo-1"], 200)]);

        $result = app(UnsplashService::class)->testApiKey("test-key");

        $this->assertTrue($result["success"]);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), "api.unsplash.com/photos/random"));
    }
}
