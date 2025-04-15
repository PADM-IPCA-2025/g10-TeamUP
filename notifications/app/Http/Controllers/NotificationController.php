<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Tymon\JWTAuth\Facades\JWTAuth;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Connection\AMQPSSLConnection;
class NotificationController extends Controller
{
    /**
     * Listen to RabbitMQ queue and store notifications for all event participants.
     */
    public function listenToNotifications()
    {
        $sslOptions = [
            'cafile'           => '/etc/rabbitmq/certs/ca_certificate.pem', // Path inside container
            'verify_peer'      => true,
            'verify_peer_name' => false
        ];

        $connection = new AMQPSSLConnection(
            env('RABBITMQ_HOST', 'rabbitmq'),
            env('RABBITMQ_PORT', 5671),  // Secure SSL port
            env('RABBITMQ_USER', 'guest'),
            env('RABBITMQ_PASSWORD', 'guest'),
            '/',
            $sslOptions
        );

        $channel = $connection->channel();
        $queueName = 'event_stream';

        $channel->queue_declare($queueName, false, true, false, false);

        Log::info("Listening to event stream for notifications.");

        $callback = function ($msg) {
            Log::info("Event received in Notification service:", ['message' => $msg->body]);

            $event = json_decode($msg->body, true);

            if (isset($event['event_type']) && $event['event_type'] === 'MessageSent') {
                $payload = $event['payload'];

                if (isset($payload['event_id'], $payload['participants'], $payload['event_name'], $payload['message'], $payload['user_id'])) {
                    $initiatorId = $payload['user_id'];

                    foreach ($payload['participants'] as $participant) {
                        if ((int)$participant['user_id'] === (int)$initiatorId) {
                            continue;
                        }

                        Notification::create([
                            'event_id'   => $payload['event_id'],
                            'user_id'    => $participant['user_id'],
                            'event_name' => $payload['event_name'],
                            'message'    => $payload['message'],
                        ]);
                    }
                } else {
                    Log::warning("Invalid event payload: missing required fields.");
                }
            } else {
                Log::warning("Event type not supported or missing; ignoring event.");
            }

            $msg->ack();
        };

        $channel->basic_consume($queueName, '', false, false, false, false, $callback);

        while ($channel->is_consuming()) {
            $channel->wait();
        }

        $channel->close();
        $connection->close();
    }
    /**
     * Get all notifications for the authenticated user.
     */
    public function getNotifications(Request $request)
    {
        try {
            $token = $this->validateToken($request);
           /* Log::info('Token validated successfully:', ['token' => $token]);*/

            $payload = JWTAuth::setToken($token)->getPayload();
            $userId = (int) $payload->get('sub');
           // Log::info('User ID obtained from token:', ['user_id' => $userId]);

            $notifications = Notification::where('user_id', $userId)
                ->orderBy('created_at', 'desc')
                ->get();

            if ($notifications->isEmpty()) {
              //  Log::info('No notifications found for user:', ['user_id' => $userId]);
                return response()->json(['message' => 'No notifications found'], 404);
            }

            $notificationMessages = $notifications->map(function ($notification) {
                return [
                    'event_name' => $notification->event_name,
                    'message'    => $notification->message,
                    'created_at' => $notification->created_at->toDateTimeString(),
                ];
            })->toArray();

         /*   Log::info('Notifications fetched for user:', [
                'user_id'       => $userId,
                'notifications' => $notificationMessages
            ]);*/

            return response()->json(['notifications' => $notificationMessages], 200);
        } catch (\Exception $e) {
          /*  Log::error('Error fetching notifications:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);*/
            return response()->json(['error' => 'Failed to fetch notifications', 'details' => $e->getMessage()], 500);
        }
    }

    /**
     * Delete all notifications for the authenticated user.
     */
    public function deleteNotifications(Request $request)
    {
        try {
            $token = $this->validateToken($request);
            $payload = JWTAuth::setToken($token)->getPayload();
            $userId = (int) $payload->get('sub');

            // Delete all notifications for this user
            Notification::where('user_id', $userId)->delete();

            return response()->json(['message' => 'All notifications deleted successfully'], 200);
        } catch (\Exception $e) {
         /*   Log::error('Error deleting notifications:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);*/
            return response()->json(['error' => 'Failed to delete notifications', 'details' => $e->getMessage()], 500);
        }
    }

    /**
     * Validate token and check if blacklisted.
     */
    private function validateToken(Request $request)
    {
        $token = $this->getTokenFromRequest($request);

        if ($this->isTokenBlacklisted($token)) {
            throw new \Exception('Unauthorized: Token is blacklisted.');
        }

        return $token;
    }

    /**
     * Extract token from Authorization header.
     */
    private function getTokenFromRequest(Request $request)
    {
        $token = $request->header('Authorization');

        if (!$token) {
            throw new \Exception('Token is required.');
        }

        // Strip "Bearer " prefix if present
        if (str_starts_with($token, 'Bearer ')) {
            $token = substr($token, 7);
        }

        return $token;
    }

    /**
     * Check if a token is blacklisted.
     */
    private function isTokenBlacklisted($token)
    {
        $cacheKey = "blacklisted:{$token}";
        return Cache::has($cacheKey);
    }
}
