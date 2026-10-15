<?php

namespace App\Http\Controllers;

use App\Events\SyncRequested;
use App\Http\Requests\StoreStoryRequest;
use App\Http\Resources\ContactResource;
use App\Http\Resources\StoryResource;
use App\Models\Story;
use App\Models\User;
use finfo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class StoryController extends Controller
{
    /**
     * Active stories from me and my chat partners, grouped per author.
     * My own group comes first, then authors with unseen stories, then the rest.
     */
    public function index(Request $request): JsonResponse
    {
        $me = $request->user();
        $authorIds = $me->partnerIds()->push($me->id);

        $stories = Story::query()
            ->active()
            ->whereIn('user_id', $authorIds)
            ->withExists(['viewers as seen_by_me' => fn (Builder $query) => $query->whereKey($me->id)])
            ->withCount('viewers')
            ->with('user')
            ->orderBy('id')
            ->get();

        $groups = $stories
            ->groupBy('user_id')
            ->map(function ($items) use ($request, $me) {
                $resources = StoryResource::collection($items)->resolve($request);

                return [
                    'user' => (new ContactResource($items->first()->user))->resolve($request),
                    'is_me' => $items->first()->user_id === $me->id,
                    'has_unseen' => collect($resources)->contains(fn (array $story) => ! $story['seen']),
                    'latest_id' => $items->max('id'),
                    'stories' => $resources,
                ];
            })
            ->sortBy([
                fn (array $a, array $b) => $b['is_me'] <=> $a['is_me'],
                fn (array $a, array $b) => $b['has_unseen'] <=> $a['has_unseen'],
                fn (array $a, array $b) => $b['latest_id'] <=> $a['latest_id'],
            ])
            ->values();

        return response()->json(['data' => $groups]);
    }

    public function store(StoreStoryRequest $request): JsonResponse
    {
        $file = $request->file('file');
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath()) ?: 'application/octet-stream';

        $story = $request->user()->stories()->create([
            'type' => str_starts_with($mime, 'video/') ? 'video' : 'image',
            'media_path' => $file->store('stories/'.now()->format('Y/m'), 'local'),
            'media_mime' => $mime,
            'caption' => $request->filled('caption') ? trim($request->string('caption')->toString()) : null,
            'expires_at' => now()->addHours(Story::LIFETIME_HOURS),
        ]);

        $story->loadCount('viewers');

        self::notifyAudience($request->user());

        return (new StoryResource($story))->response()->setStatusCode(201);
    }

    public function markViewed(Request $request, Story $story): JsonResponse
    {
        Gate::authorize('view', $story);

        if ($story->user_id !== $request->user()->id) {
            $changes = $story->viewers()->syncWithoutDetaching([$request->user()->id]);

            if ($changes['attached'] !== []) {
                SyncRequested::send([$story->user_id], SyncRequested::TOPIC_STORY_VIEWED, ['story_id' => $story->id]);
            }
        }

        return response()->json(['ok' => true]);
    }

    public function viewers(Request $request, Story $story): JsonResponse
    {
        Gate::authorize('viewViewers', $story);

        $viewers = $story->viewers()->orderByPivot('created_at', 'desc')->get();

        return response()->json([
            'data' => $viewers->map(fn (User $viewer) => [
                ...(new ContactResource($viewer))->resolve($request),
                'viewed_at' => $viewer->pivot->created_at->toIso8601String(),
            ]),
        ]);
    }

    public function destroy(Story $story): JsonResponse
    {
        Gate::authorize('delete', $story);

        Storage::disk('local')->delete($story->media_path);
        $story->delete();

        self::notifyAudience($story->user);

        return response()->json(['id' => $story->id, 'deleted' => true]);
    }

    /**
     * Tell the author's chat partners (and the author's other tabs) that the story strip changed.
     */
    private static function notifyAudience(User $author): void
    {
        SyncRequested::send($author->partnerIds()->push($author->id), SyncRequested::TOPIC_STORIES);
    }

    public function media(Story $story): BinaryFileResponse
    {
        Gate::authorize('view', $story);

        $disk = Storage::disk('local');
        abort_unless($disk->exists($story->media_path), 404);

        return response()->file($disk->path($story->media_path), [
            'Content-Type' => $story->media_mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }
}
