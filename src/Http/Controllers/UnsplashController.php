<?php

namespace hexa_package_unsplash\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use hexa_package_unsplash\Services\UnsplashService;

/**
 * UnsplashController — handles the raw dev page and AJAX search endpoint.
 */
class UnsplashController extends Controller
{
    /**
     * Show the raw dev page for Unsplash.
     *
     * @return \Illuminate\View\View
     */
    public function raw()
    {
        return view('unsplash::raw.index');
    }

    /**
     * AJAX: Search photos via Unsplash API.
     *
     * @param Request $request
     * @param UnsplashService $service
     * @return JsonResponse
     */
    public function search(Request $request, UnsplashService $service): JsonResponse
    {
        $request->validate([
            'query'    => 'required|string|max:255',
            'per_page' => 'nullable|integer|min:1|max:30',
            'page'     => 'nullable|integer|min:1',
        ]);

        $result = $service->searchPhotos(
            $request->input('query'),
            (int) $request->input('per_page', 15),
            (int) $request->input('page', 1)
        );

        return response()->json($result);
    }
}
