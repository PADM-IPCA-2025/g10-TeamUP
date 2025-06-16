<?php

namespace App\Http\Controllers;

use App\Models\EventUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Tymon\JWTAuth\Facades\JWTAuth;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class ChatController extends Controller
{
    /* ───────────────────────────── RABBITMQ PUBLISHER ───────────────────────────── */

    /**
     * Publish a JSON-encoded message to RabbitMQ.
     *
     * ‼️  This controller no longer contains a queue *consumer*; that responsibility
     *     lives in an artisan command run by Supervisor, avoiding multiple
     *     consumers for the same queue.
     *
     * @param  string  $messageBody
     * @return void
     */
    private function publishToRabbitMQ(string $messageBody): void
    {
        $rabbitmqHost     = env('RABBITMQ_HOST',     'rabbitmq');
        $rabbitmqPort     = env('RABBITMQ_PORT',     5672);
        $rabbitmqUser     = env('RABBITMQ_USER',     'guest');
        $rabbitmqPassword = env('RABBITMQ_PASSWORD', 'guest');

        // Fan-out exchanges and direct queue
        $fanoutExchanges = [
            'notification_fanout_1',
            'notification_fanout_2',
        ];
        $directQueue = 'realfrontchat';

        try {
            $connection = new AMQPStreamConnection(
                $rabbitmqHost,
                $rabbitmqPort,
                $rabbitmqUser,
                $rabbitmqPassword
            );
            $channel = $connection->channel();

            /* Fan-out publishing */
            foreach ($fanoutExchanges as $exchange) {
                $channel->exchange_declare($exchange, 'fanout', false, true, false);

                $channel->basic_publish(
                    new AMQPMessage($messageBody, [
                        'content_type' => 'application/json',
                        'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                    ]),
                    $exchange          // exchange
                );
            }

            /* Direct queue publishing */
            $channel->queue_declare($directQueue, false, true, false, false);
            $channel->basic_publish(
                new AMQPMessage($messageBody, [
                    'content_type' => 'application/json',
                    'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                ]),
                '',                 // default exchange
                $directQueue        // routing-key (queue name)
            );

            $channel->close();
            $connection->close();
        } catch (\Throwable $e) {
            // You may want to log the error instead of swallowing it silently.
            report($e);
        }
    }

    /* ───────────────────────────── SEND MESSAGE ───────────────────────────── */

    public function sendMessage(Request $request, int $id)
    {
        try {
            /* Validate & decode JWT */
            $token   = $this->validateToken($request);
            $payload = JWTAuth::setToken($token)->getPayload();
            $userId  = $payload->get('sub');
            $userName = $payload->get('name');

            /* Validate message payload */
            $validatedData = $request->validate([
                'message' => 'required|string',
            ]);

            /* Ensure the user is part of the event */
            $isParticipating = EventUser::where('event_id', $id)
                ->where('user_id', $userId)
                ->exists();

            if (! $isParticipating) {
                return response()->json(
                    ['error' => 'Unauthorized: User is not participating in this event'],
                    403
                );
            }

            /* Persist message */
            $messageData = [
                'event_id'   => $id,
                'event_name' => "Evento $id",
                'user_id'    => $userId,
                'user_name'  => $userName,
                'message'    => $validatedData['message'],
            ];
            EventUser::create($messageData);

            /* Fetch distinct participants for the payload */
            $eventParticipants = EventUser::where('event_id', $id)
                ->distinct()
                ->get(['user_id', 'user_name']);

            /* Construct and publish notification */
            $notificationMessage = [
                'type'        => 'new_message',
                'event_id'    => $id,
                'event_name'  => "Evento $id",
                'user_id'     => $userId,
                'user_name'   => $userName,
                'message'     => $validatedData['message'],
                'timestamp'   => now()->toISOString(),
                'participants'=> $eventParticipants->toArray(),
            ];
            $this->publishToRabbitMQ(json_encode($notificationMessage));

            return response()->json(['status' => 'Message sent successfully'], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Validation Error',
                'errors'  => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 401);
        }
    }

    /* ───────────────────────────── FETCH MESSAGES ───────────────────────────── */

    public function fetchMessages(Request $request, int $id)
    {
        try {
            $token   = $this->validateToken($request);
            $payload = JWTAuth::setToken($token)->getPayload();
            $userId  = $payload->get('sub');

            /* Verify participation */
            $isParticipating = EventUser::where('event_id', $id)
                ->where('user_id', $userId)
                ->exists();

            if (! $isParticipating) {
                return response()->json(
                    ['error' => 'Unauthorized: User is not participating in this event'],
                    403
                );
            }

            /* Retrieve messages */
            $messages = EventUser::where('event_id', $id)->get();

            return response()->json(['messages' => $messages], 200);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 401);
        }
    }

    /* ───────────────────────────── JWT / TOKEN HELPERS ───────────────────────────── */

    private function isTokenBlacklisted(string $token): bool
    {
        return Cache::has("blacklisted:{$token}");
    }

    private function getTokenFromRequest(Request $request): string
    {
        $token = $request->header('Authorization');

        if (! $token) {
            throw new \Exception('Token is required.');
        }

        return str_starts_with($token, 'Bearer ')
            ? substr($token, 7)
            : $token;
    }

    private function validateToken(Request $request): string
    {
        $token = $this->getTokenFromRequest($request);

        if ($this->isTokenBlacklisted($token)) {
            throw new \Exception('Unauthorized: Token is blacklisted.');
        }

        return $token;
    }
}
