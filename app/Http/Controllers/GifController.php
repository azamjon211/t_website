<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class GifController extends Controller
{
    private const LIMIT = 24;

    /**
     * Search GIPHY (or list trending GIFs when the query is empty) through the server, so the API key stays private.
     */
    public function search(Request $request): JsonResponse
    {
        $validated = $request->validate(['q' => ['nullable', 'string', 'max:100']]);

        $key = config('services.giphy.key');
        abort_if(blank($key), 503, 'GIF qidiruv sozlanmagan (GIPHY_API_KEY).');

        $query = trim($validated['q'] ?? '');
        $cacheKey = 'gifs:'.md5(mb_strtolower($query));

        $gifs = Cache::get($cacheKey);

        if ($gifs === null) {
            $response = Http::timeout(6)->get(
                'https://api.giphy.com/v1/gifs/'.($query === '' ? 'trending' : 'search'),
                array_filter(['api_key' => $key, 'q' => $query ?: null, 'limit' => self::LIMIT, 'rating' => 'pg-13']),
            );

            abort_unless($response->successful(), 502, 'GIF xizmati javob bermadi.');

            $gifs = collect($response->json('data', []))
                ->map(fn (array $gif) => [
                    'id' => $gif['id'],
                    'preview' => $gif['images']['fixed_width_small']['url'] ?? $gif['images']['fixed_width']['url'] ?? null,
                    'url' => $gif['images']['fixed_height']['url'] ?? null,
                    'width' => (int) ($gif['images']['fixed_height']['width'] ?? 0),
                    'height' => (int) ($gif['images']['fixed_height']['height'] ?? 0),
                ])
                ->filter(fn (array $gif) => $gif['url'] !== null && $gif['width'] > 0 && $gif['height'] > 0)
                ->map(fn (array $gif) => [
                    ...$gif,
                    'url' => Str::before($gif['url'], '?'),
                    'preview' => Str::before($gif['preview'] ?? $gif['url'], '?'),
                ])
                ->values()
                ->all();

            Cache::put($cacheKey, $gifs, now()->addMinutes(10));
        }

        return response()->json(['data' => $gifs]);
    }
}
