<?php

namespace App\Http\Controllers;

use App\Events\SyncRequested;
use App\Http\Requests\CallSignalRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Relays WebRTC signalling between two chat partners.
 *
 * SDP blobs are too big for a websocket frame, so each signal is parked in the cache for a minute and
 * only its id is pushed to the callee over Reverb; the callee then fetches it once.
 */
class CallController extends Controller
{
    private const SIGNAL_TTL_SECONDS = 60;

    private const TURN_CREDENTIAL_TTL_SECONDS = 6 * 3600;

    public function signal(CallSignalRequest $request, User $user): JsonResponse
    {
        $me = $request->user();

        abort_if($user->id === $me->id, 422, 'O\'zingizga qo\'ng\'iroq qilib bo\'lmaydi.');
        abort_unless($me->isPartnerOf($user), 403, 'Avval shu foydalanuvchiga xabar yozing.');

        $signalId = (string) Str::uuid();

        Cache::put(self::signalKey($signalId), [
            'to' => $user->id,
            'signal' => [
                ...$request->safe()->only(['type', 'call_id', 'media', 'sdp']),
                'from' => $me->id,
            ],
        ], now()->addSeconds(self::SIGNAL_TTL_SECONDS));

        SyncRequested::send([$user->id], SyncRequested::TOPIC_CALL, ['signal_id' => $signalId]);

        return response()->json(['ok' => true]);
    }

    /**
     * Hand a parked signal to its recipient exactly once.
     */
    public function fetchSignal(Request $request, string $signalId): JsonResponse
    {
        $key = self::signalKey($signalId);
        $entry = Cache::get($key);

        abort_if($entry === null || $entry['to'] !== $request->user()->id, 404);

        Cache::forget($key);

        return response()->json(['data' => $entry['signal']]);
    }

    /**
     * STUN plus, when configured, short-lived TURN credentials (coturn `use-auth-secret`).
     */
    public function iceServers(Request $request): JsonResponse
    {
        $servers = [['urls' => ['stun:stun.l.google.com:19302', 'stun:stun1.l.google.com:19302']]];

        $turnUrl = config('services.turn.url');
        $turnSecret = config('services.turn.secret');

        if (filled($turnUrl) && filled($turnSecret)) {
            $username = (now()->timestamp + self::TURN_CREDENTIAL_TTL_SECONDS).':'.$request->user()->id;

            $servers[] = [
                'urls' => array_map('trim', explode(',', $turnUrl)),
                'username' => $username,
                'credential' => base64_encode(hash_hmac('sha1', $username, $turnSecret, true)),
            ];
        }

        return response()->json(['data' => $servers]);
    }

    private static function signalKey(string $signalId): string
    {
        return "call-signal:{$signalId}";
    }
}
