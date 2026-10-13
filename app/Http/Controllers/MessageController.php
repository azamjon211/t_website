<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreMessageRequest;
use App\Http\Resources\MessageResource;
use App\Models\Message;
use App\Models\User;
use finfo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

class MessageController extends Controller
{
    private const PAGE_SIZE = 50;

    /**
     * Conversation history with a user, newest page first; pass `before` to load older pages.
     */
    public function index(Request $request, User $user): AnonymousResourceCollection
    {
        $request->validate(['before' => ['nullable', 'integer']]);

        $messages = Message::query()
            ->between($request->user()->id, $user->id)
            ->when($request->filled('before'), fn ($query) => $query->where('id', '<', $request->integer('before')))
            ->with('replyTo')
            ->orderByDesc('id')
            ->limit(self::PAGE_SIZE + 1)
            ->get();

        $hasMore = $messages->count() > self::PAGE_SIZE;

        return MessageResource::collection($messages->take(self::PAGE_SIZE)->reverse()->values())
            ->additional(['has_more' => $hasMore]);
    }

    public function store(StoreMessageRequest $request, User $user): JsonResponse
    {
        $me = $request->user();
        $replyToId = $request->integer('reply_to_id') ?: null;

        if ($replyToId !== null && ! Message::query()->between($me->id, $user->id)->whereKey($replyToId)->exists()) {
            throw ValidationException::withMessages(['reply_to_id' => 'Reply target is not in this chat.']);
        }

        $attributes = [
            'sender_id' => $me->id,
            'receiver_id' => $user->id,
            'body' => $request->filled('body') ? $request->string('body')->trim()->toString() : null,
            'type' => Message::TYPE_TEXT,
            'reply_to_id' => $replyToId,
        ];

        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath()) ?: null;

            $attributes = [
                ...$attributes,
                'type' => Message::typeForMime($mime),
                'attachment_path' => $file->store('attachments/'.now()->format('Y/m'), 'local'),
                'attachment_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                'attachment_mime' => $mime,
                'attachment_size' => $file->getSize(),
            ];
        }

        $message = Message::create($attributes);

        if ($user->id === $me->id) {
            $message->forceFill(['read_at' => now()])->save();
        }

        $me->touchLastSeen();

        return (new MessageResource($message->load('replyTo')))->response()->setStatusCode(201);
    }

    public function update(Request $request, Message $message): MessageResource
    {
        Gate::authorize('update', $message);

        $validated = $request->validate(['body' => ['required', 'string', 'max:4096']]);

        $message->forceFill([
            'body' => trim($validated['body']),
            'edited_at' => now(),
        ])->save();

        return new MessageResource($message->load('replyTo'));
    }

    public function destroy(Message $message): JsonResponse
    {
        Gate::authorize('delete', $message);

        if ($message->attachment_path !== null) {
            Storage::disk('local')->delete($message->attachment_path);
        }

        $message->delete();

        return response()->json(['id' => $message->id, 'deleted' => true]);
    }

    /**
     * Mark everything the partner sent me as read.
     */
    public function markRead(Request $request, User $user): JsonResponse
    {
        $updated = Message::query()
            ->where('sender_id', $user->id)
            ->where('receiver_id', $request->user()->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['updated' => $updated]);
    }

    /**
     * Stream an attachment to a participant. Range requests are supported, so videos can seek.
     */
    public function media(Request $request, Message $message): BinaryFileResponse
    {
        Gate::authorize('view', $message);

        $disk = Storage::disk('local');
        abort_if($message->attachment_path === null || ! $disk->exists($message->attachment_path), 404);

        $inline = $message->type !== Message::TYPE_FILE && ! $request->boolean('download');

        $response = response()->file($disk->path($message->attachment_path), [
            'Content-Type' => $inline ? $message->attachment_mime : 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=31536000, immutable',
        ]);

        $response->setContentDisposition(
            $inline ? ResponseHeaderBag::DISPOSITION_INLINE : ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $message->attachment_name ?? 'file',
            'file',
        );

        return $response;
    }
}
