<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreMessageRequest;
use App\Http\Requests\StoreStoryRequest;
use App\Http\Resources\ContactResource;
use App\Http\Resources\MessageResource;
use App\Models\Message;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

class ChatController extends Controller
{
    /**
     * Seconds of overlap when polling, so writes landing in the same second are never missed.
     */
    private const SYNC_OVERLAP_SECONDS = 2;

    public function index(Request $request): View
    {
        return view('chat', [
            'user' => $request->user(),
            'bootstrap' => [
                'me' => (new ContactResource($request->user()))->resolve($request),
                'maxUploadBytes' => StoreMessageRequest::MAX_FILE_KB * 1024,
                'maxStoryBytes' => StoreStoryRequest::MAX_FILE_KB * 1024,
            ],
        ]);
    }

    /**
     * The sidebar: one row per conversation partner with the latest message and unread count.
     */
    public function chats(Request $request): JsonResponse
    {
        $me = $request->user();

        $latestIds = Message::query()
            ->involving($me->id)
            ->selectRaw('CASE WHEN sender_id = ? THEN receiver_id ELSE sender_id END AS partner_id, MAX(id) AS last_id', [$me->id])
            ->groupBy('partner_id')
            ->pluck('last_id', 'partner_id');

        $lastMessages = Message::query()->whereIn('id', $latestIds->values())->get()->keyBy('id');
        $partners = User::query()->whereIn('id', $latestIds->keys())->get()->keyBy('id');

        $unreadCounts = Message::query()
            ->where('receiver_id', $me->id)
            ->where('sender_id', '!=', $me->id)
            ->whereNull('read_at')
            ->groupBy('sender_id')
            ->selectRaw('sender_id, COUNT(*) AS unread')
            ->pluck('unread', 'sender_id');

        $chats = $latestIds
            ->map(fn (int $lastId, int $partnerId) => [
                'user' => new ContactResource($partners[$partnerId]),
                'last_message' => new MessageResource($lastMessages[$lastId]),
                'unread' => (int) ($unreadCounts[$partnerId] ?? 0),
            ])
            ->sortByDesc(fn (array $chat) => $chat['last_message']->id)
            ->values();

        return response()->json(['data' => $chats]);
    }

    /**
     * Find people by their unique username (or display name).
     */
    public function search(Request $request): AnonymousResourceCollection
    {
        $term = strtolower(ltrim(trim($request->string('q')->toString()), '@'));

        if ($term === '') {
            return ContactResource::collection(collect());
        }

        $like = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term);

        $users = User::query()
            ->where(function ($query) use ($like) {
                $query->whereRaw("username LIKE ? ESCAPE '!'", [$like.'%'])
                    ->orWhereRaw("LOWER(name) LIKE ? ESCAPE '!'", ['%'.$like.'%']);
            })
            ->orderByRaw('CASE WHEN username = ? THEN 0 ELSE 1 END', [$term])
            ->orderBy('username')
            ->limit(20)
            ->get();

        return ContactResource::collection($users);
    }

    public function contact(User $user): ContactResource
    {
        return new ContactResource($user);
    }

    /**
     * Polling endpoint: every message created, edited, read or deleted since the cursor,
     * plus presence and typing state of the open chat's partner.
     */
    public function sync(Request $request): JsonResponse
    {
        $me = $request->user();
        $me->touchLastSeen();

        $request->validate(['since' => ['nullable', 'date'], 'peer' => ['nullable', 'integer']]);

        $now = now();
        $since = $request->filled('since')
            ? Carbon::parse($request->string('since')->toString())->subSeconds(self::SYNC_OVERLAP_SECONDS)
            : $now->copy()->subSeconds(self::SYNC_OVERLAP_SECONDS);

        $messages = Message::withTrashed()
            ->involving($me->id)
            ->where('updated_at', '>=', $since)
            ->with('replyTo')
            ->orderBy('id')
            ->limit(500)
            ->get();

        $peer = null;
        $peerId = $request->integer('peer');

        if ($peerId > 0 && ($peerUser = User::find($peerId)) !== null) {
            $peer = [
                ...(new ContactResource($peerUser))->resolve($request),
                'typing' => Cache::has(self::typingKey($peerUser->id, $me->id)),
            ];
        }

        return response()->json([
            'cursor' => $now->toIso8601String(),
            'messages' => MessageResource::collection($messages)->resolve($request),
            'peer' => $peer,
        ]);
    }

    public function typing(Request $request, User $user): JsonResponse
    {
        $request->user()->touchLastSeen();

        Cache::put(self::typingKey($request->user()->id, $user->id), true, now()->addSeconds(4));

        return response()->json(['ok' => true]);
    }

    public static function typingKey(int $fromUserId, int $toUserId): string
    {
        return "typing:{$fromUserId}:{$toUserId}";
    }
}
