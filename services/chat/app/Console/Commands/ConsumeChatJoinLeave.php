<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\Models\EventUser;

class ConsumeChatJoinLeave extends Command
{
    /** php artisan rabbitmq:consume-lolchat-join-leave */
    protected $signature   = 'rabbitmq:consume-lolchat-join-leave';
    protected $description = 'Consume lolchat_event_join-leave messages from RabbitMQ and store them in event_user table';

    public function handle()
    {
        $this->info('Listening to lolchat_event_join-leave queue…');

        try {
            $connection = new AMQPStreamConnection('rabbitmq', 5672, 'guest', 'guest');
            $channel    = $connection->channel();

            $queueName = 'lolchat_event_join-leave';
            $channel->queue_declare($queueName, false, true, false, false);
            $channel->basic_qos(null, 1, null);

            /* ─────────────────────────── CONSUMER CALLBACK ─────────────────────────── */
            $callback = function (AMQPMessage $msg) {
                $payload = json_decode($msg->body, true) ?: [];

                /* 1️⃣ Minimal sanity-check: we just need event_id, user_id, user_name */
                if (!isset($payload['event_id'], $payload['user_id'], $payload['user_name'])) {
                    Log::warning('Invalid payload - missing id or user info', ['body' => $msg->body]);
                    $msg->ack();                 // discard badly-formed message
                    return;
                }

                /* 2️⃣ Determine message text */
                $text = $payload['message']             // pre-built message
                    ?? (isset($payload['action'])       // otherwise build from action
                        ? "User {$payload['user_name']} {$payload['action']} the event"
                        : null);                        // allow null if column is nullable

                /* 3️⃣ Build DB row */
                $row = [
                    'event_id'   => $payload['event_id'],
                    'event_name' => $payload['event_name'] ?? 'Evento '.$payload['event_id'],
                    'user_id'    => $payload['user_id'],
                    'user_name'  => $payload['user_name'],
                    'message'    => $text,
                ];

                DB::enableQueryLog();                    // capture SQL

                try {
                    EventUser::create($row);

                    Log::info('EventUser INSERT OK', DB::getQueryLog());
                    $this->info("Stored event for user {$payload['user_id']}");
                    $msg->ack();                         // ✅ success
                } catch (\Throwable $e) {
                    Log::error('DB INSERT FAILED', [
                        'error'   => $e->getMessage(),
                        'payload' => $row,
                        'sql'     => DB::getQueryLog(),
                    ]);

                    $this->error('DB INSERT FAILED: '.$e->getMessage());
                    $msg->nack(false, true);             // re-queue while debugging
                }
            };

            $channel->basic_consume($queueName, '', false, false, false, false, $callback);

            while ($channel->is_consuming()) {
                $channel->wait();
            }

            $channel->close();
            $connection->close();

        } catch (\Throwable $e) {
            $this->error('RabbitMQ consumption failed: '.$e->getMessage());
            Log::error('RabbitMQ consumption error', ['exception' => $e]);
        }
    }
}
