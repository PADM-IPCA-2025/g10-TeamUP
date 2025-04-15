<?php

namespace App\Http\Controllers;

use App\Models\EventUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tymon\JWTAuth\Facades\JWTAuth;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Connection\AMQPSSLConnection;

class ChatController extends Controller
{
    /**
     * Listen to RabbitMQ queue and display messages being published.
     */
    public function listenRabbitMQ()
    {
        $sslOptions = [
            'cafile'           => '/etc/rabbitmq/certs/ca_certificate.pem',
            'verify_peer'      => true,
            'verify_peer_name' => false
        ];
    
        $connection = new AMQPSSLConnection(
            env('RABBITMQ_HOST', 'rabbitmq'),
            env('RABBITMQ_PORT', 5671),  // Use SSL port
            env('RABBITMQ_USER', 'guest'),
            env('RABBITMQ_PASSWORD', 'guest'),
            '/',
            $sslOptions
        );
    
        $channel = $connection->channel();
        $channel->queue_declare(env('RABBITMQ_QUEUE', 'event_joined'), false, true, false, false);
        $channel->basic_qos(null, 1, null);
    
        $callback = function ($msg) {
            echo 'Message received: ', $msg->body, "\n";
            Log::info('Message received by RabbitMQ listener:', ['message' => $msg->body]);
    
            $messageData = json_decode($msg->body, true);
    
            EventUser::create([
                'event_id'   => $messageData['event_id'],
                'event_name' => $messageData['event_name'],
                'user_id'    => $messageData['user_id'],
                'user_name'  => $messageData['user_name'],
                'message'    => $messageData['message'],
            ]);
    
            $msg->ack();
        };
    
        $channel->basic_consume(env('RABBITMQ_QUEUE', 'event_joined'), '', false, false, false, false, $callback);
    
        while ($channel->is_consuming()) {
            $channel->wait();
        }
    
        $channel->close();
        $connection->close();
    }
/**
 * Send a message in an event chat.
 */
/**
 * Send a message in an event chat.
 */
public function sendMessage(Request $request, $id)
{
    try {
        // Validate the token
        $token = $this->validateToken($request);

        // Parse the token to get the user info
        $payload = JWTAuth::setToken($token)->getPayload();
        $userId = $payload->get('sub');
        $userName = $payload->get('name');

        // Validate the message input
        $validatedData = $request->validate([
            'message' => 'required|string',
        ]);

        // Check if the user is participating in the event
        $isParticipating = EventUser::where('event_id', $id)
            ->where('user_id', $userId)
            ->exists();

        if (!$isParticipating) {
          /*  \Log::warning('Unauthorized attempt to send a message to an event:', [
                'user_id' => $userId,
                'event_id' => $id,
            ]);*/
            return response()->json(['error' => 'Unauthorized: User is not participating in this event'], 403);
        }

        // Store the message in the event_user table
        $messageData = [
            'event_id'   => $id,
            'event_name' => "Evento $id",
            'user_id'    => $userId,
            'user_name'  => $userName,
            'message'    => $validatedData['message'],
        ];

        EventUser::create($messageData);

        // Fetch all unique participants of the event
        $eventParticipants = EventUser::where('event_id', $id)
            ->distinct()
            ->get(['user_id', 'user_name']);

        // Prepare the notification message with distinct participants
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

        // Publish the message to the RabbitMQ queue named 'notification'
        $this->publishToRabbitMQ('notification', json_encode($notificationMessage));

        return response()->json(['status' => 'Message sent successfully'], 201);
    } catch (\Illuminate\Validation\ValidationException $e) {
        return response()->json([
            'message' => 'Validation Error',
            'errors' => $e->errors(),
        ], 422);
    } catch (\Exception $e) {
      /*  \Log::error('Error in sendMessage:', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
        return response()->json(['error' => $e->getMessage()], 401);*/
    }
}


/**
 * Publish a message to RabbitMQ.
 */
private function publishToRabbitMQ($queueName, $messageBody)
    {
        $sslOptions = [
            'cafile'           => '/etc/rabbitmq/certs/ca_certificate.pem',
            'verify_peer'      => true,
            'verify_peer_name' => false
        ];
    
        try {
            $connection = new AMQPSSLConnection(
                env('RABBITMQ_HOST', 'rabbitmq'),
                env('RABBITMQ_PORT', 5671),  // Use SSL port
                env('RABBITMQ_USER', 'guest'),
                env('RABBITMQ_PASSWORD', 'guest'),
                '/',
                $sslOptions
            );
    
            $channel = $connection->channel();
            $channel->queue_declare($queueName, false, true, false, false);
    
            $msg = new AMQPMessage($messageBody);
            $channel->basic_publish($msg, '', $queueName);
    
            $channel->close();
            $connection->close();
    
            Log::info('Message published to RabbitMQ:', ['queue' => $queueName, 'message' => $messageBody]);
        } catch (\Exception $e) {
            Log::error('Error publishing message to RabbitMQ:', [
                'error'   => $e->getMessage(),
                'queue'   => $queueName,
                'message' => $messageBody,
            ]);
    
            $this->storeFailedMessage($queueName, $messageBody);
        }
    }
    



    /**
     * Fetch messages for an event.
     */
    public function fetchMessages(Request $request, $id)
    {
        try {
            // Validate the token
            $token = $this->validateToken($request);

            // Parse the token to get the user info
            $payload = JWTAuth::setToken($token)->getPayload();
            $userId = $payload->get('sub');

            // Check if the user is participating in the event by querying the event_user table
            $isParticipating = EventUser::where('event_id', $id)
                ->where('user_id', $userId)
                ->exists();

            if (!$isParticipating) {
               /* \Log::warning('Unauthorized attempt to fetch messages for an event:', [
                    'user_id' => $userId,
                    'event_id' => $id,
                ]);*/
                return response()->json(['error' => 'Unauthorized: User is not participating in this event'], 403);
            }

            // Fetch all messages from the event_user table for the event
            $messages = EventUser::where('event_id', $id)->get();

            return response()->json(['messages' => $messages], 200);
        } catch (\Exception $e) {
         /*   \Log::error('Error in fetchMessages:', ['error' => $e->getMessage()]);
            return response()->json(['error' => $e->getMessage()], 401);*/
        }
    }

    /**
     * Check if a token is blacklisted using the /get-blacklist endpoint.
     */
    private function isTokenBlacklisted($token)
    {
        $cacheKey = "blacklisted:{$token}";

        return Cache::has($cacheKey);
    }

    /**
     * Process token from Authorization header.
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
}
