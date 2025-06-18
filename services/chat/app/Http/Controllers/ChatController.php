<?php

namespace App\Http\Controllers;

use App\Models\EventUser;
use App\Models\UsersFc;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Tymon\JWTAuth\Facades\JWTAuth;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use Google\Client as GoogleClient;

class ChatController extends Controller
{
    /* ────────────────────────── RABBITMQ PUBLISHER ────────────────────────── */

    private function publishToRabbitMQ(string $json): void
    {
        $conn = new AMQPStreamConnection(
            env('RABBITMQ_HOST', 'rabbitmq'),
            env('RABBITMQ_PORT', 5672),
            env('RABBITMQ_USER', 'guest'),
            env('RABBITMQ_PASSWORD', 'guest')
        );
        $ch = $conn->channel();

        foreach (['notification_fanout_1', 'notification_fanout_2'] as $ex) {
            $ch->exchange_declare($ex, 'fanout', false, true, false);
            $ch->basic_publish(
                new AMQPMessage($json, [
                    'content_type' => 'application/json',
                    'delivery_mode'=> AMQPMessage::DELIVERY_MODE_PERSISTENT,
                ]),
                $ex
            );
        }

        $queue = 'realfrontchat';
        $ch->queue_declare($queue, false, true, false, false);
        $ch->basic_publish(
            new AMQPMessage($json, [
                'content_type' => 'application/json',
                'delivery_mode'=> AMQPMessage::DELIVERY_MODE_PERSISTENT,
            ]),
            '',
            $queue
        );

        $ch->close();
        $conn->close();
    }

    /* ──────────────────────────── HELPERS ──────────────────────────── */

    /** Return every distinct participant of an event (user_id + user_name). */
    private function listParticipants(int $eventId): array
    {
        return EventUser::where('event_id', $eventId)
            ->select('user_id', 'user_name')
            ->distinct()
            ->get()
            ->map(fn ($row) => [
                'user_id'   => (string) $row->user_id,
                'user_name' => $row->user_name,
            ])
            ->values()
            ->toArray();
    }

    /* ───────────────────────────── SEND MESSAGE ───────────────────────────── */

    public function sendMessage(Request $request, int $id)
    {
        try {
            /* 0. Auth & validation */
            $token   = $this->validateToken($request);
            $payload = JWTAuth::setToken($token)->getPayload();
            $userId  = $payload->get('sub');
            $userName= $payload->get('name');

            $validated = $request->validate(['message' => 'required|string']);

            /* 1. Ensure membership */
            if (! EventUser::where('event_id', $id)->where('user_id', $userId)->exists()) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }

            /* 2. Persist */
            EventUser::create([
                'event_id'   => $id,
                'event_name' => "Evento $id",
                'user_id'    => $userId,
                'user_name'  => $userName,
                'message'    => $validated['message'],
            ]);

            /* 3. Participants list for WebSocket */
            $participants = $this->listParticipants($id);

            /* 4. WebSocket fan-out */
            $this->publishToRabbitMQ(json_encode([
                'type'         => 'new_message',
                'event_id'     => $id,
                'event_name'   => "Evento $id",
                'user_id'      => $userId,
                'user_name'    => $userName,
                'message'      => $validated['message'],
                'timestamp'    => now()->toISOString(),
                'participants' => $participants,        // ← NEW
            ]));

            /* 5. Push-notification targets (all except sender) */
            $recipientIds = collect($participants)
                ->pluck('user_id')
                ->map(fn ($uid) => (int) $uid)
                ->reject(fn ($uid) => $uid === $userId);

            $tokens = $recipientIds->isEmpty()
                ? []
                : UsersFc::whereIn('user_id', $recipientIds)->pluck('fcm_token')->all();

            /* 6. Send FCM in parallel */
            $this->sendPushNotificationV1Parallel(
                $tokens,
                "New message from $userName",
                $validated['message'],
                [
                    'event_id'  => (string) $id,
                    'sender_id' => (string) $userId,
                    'sender'    => $userName,
                ]
            );

            /* 7. Done */
            return response()->json(['status' => 'Message sent'], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 401);
        }
    }

    /* ─────────────────────────── FETCH MESSAGES ─────────────────────────── */

    public function fetchMessages(Request $request, int $id)
    {
        try {
            $payload = JWTAuth::setToken($this->validateToken($request))->getPayload();
            $userId  = $payload->get('sub');

            if (! EventUser::where('event_id', $id)->where('user_id', $userId)->exists()) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }

            return response()->json(
                ['messages' => EventUser::where('event_id', $id)->get()],
                200
            );
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 401);
        }
    }

    /* ──────────────── FCM TOKEN REGISTRATION (users_fc) ──────────────── */

    public function storeFcmToken(Request $request)
    {
        $request->validate(['fcm_token' => 'required|string']);

        $jwt     = $this->validateToken($request);
        $payload = JWTAuth::setToken($jwt)->getPayload();
        $userId  = $payload->get('sub');

        UsersFc::updateOrCreate(
            ['user_id' => $userId],
            ['fcm_token' => $request->fcm_token]
        );

        return response()->json(['message' => 'FCM token stored'], 201);
    }

    /* ────────────── FCM v1 helpers ────────────── */

    private function getFirebaseAccessToken(): string
    {
        static $cache = null;
        if ($cache && $cache['exp'] > time()) return $cache['tok'];

        $client = new GoogleClient();
        $client->useApplicationDefaultCredentials();
        $client->addScope('https://www.googleapis.com/auth/firebase.messaging');

        $creds  = $client->fetchAccessTokenWithAssertion();
        $cache  = [
            'tok' => $creds['access_token'],
            'exp' => time() + $creds['expires_in'] - 60,
        ];
        return $cache['tok'];
    }

    /**
     * Send the same notification to many tokens concurrently using curl_multi.
     */
    private function sendPushNotificationV1Parallel(
        array  $tokens,
        string $title,
        string $body,
        array  $data = []
    ): void {
        if (empty($tokens)) return;

        $projectId = env('FIREBASE_PROJECT_ID');
        $url       = "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send";
        $headers   = [
            'Authorization: Bearer ' . $this->getFirebaseAccessToken(),
            'Content-Type: application/json; charset=utf-8',
        ];

        $mh   = curl_multi_init();
        $chs  = [];

        foreach ($tokens as $t) {
            $payload = [
                'message' => [
                    'token'        => $t,
                    'notification' => ['title' => $title, 'body' => $body],
                    'data'         => $data,
                    'android'      => ['priority' => 'high'],
                ],
            ];
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POSTFIELDS     => json_encode($payload),
            ]);
            curl_multi_add_handle($mh, $ch);
            $chs[] = $ch;
        }

        do { $status = curl_multi_exec($mh, $active); } while ($active && $status == CURLM_OK);

        foreach ($chs as $ch) curl_multi_remove_handle($mh, $ch);
        curl_multi_close($mh);
    }

    /* ─────────────────────── JWT helper methods ─────────────────────── */

    private function getTokenFromRequest(Request $r): string
    {
        $h = $r->header('Authorization');
        if (!$h) throw new \Exception('Token required');
        return str_starts_with($h, 'Bearer ') ? substr($h, 7) : $h;
    }

    private function isTokenBlacklisted(string $t): bool
    {
        return Cache::has("blacklisted:$t");
    }

    private function validateToken(Request $r): string
    {
        $t = $this->getTokenFromRequest($r);
        if ($this->isTokenBlacklisted($t)) throw new \Exception('Token blacklisted');
        return $t;
    }
}
