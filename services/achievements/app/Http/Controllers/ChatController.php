<?php

namespace App\Http\Controllers;

use App\Models\EventUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tymon\JWTAuth\Facades\JWTAuth;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use App\Models\EventRating;
use App\Models\UserAverage;
use App\Models\UserReputation;
use App\Models\EventFeedback;
use Illuminate\Support\Facades\DB;
use App\Models\Achievement;
use App\Models\UsersAchi;
use App\Models\UserAchievement;
use App\Models\UserStat;

class ChatController extends Controller
{
    //───────────────────────────────────────────── OBTEM XP/LVL DE USER (PUBLICO)────────────────────────────────────────────────────────────────────────────────────
    public function getProfile($id)
    {
        $stat = \App\Models\UserStat::firstOrCreate(
            ['user_id' => $id],
            ['xp' => 0, 'level' => 0]
        );

        return response()->json([
            'xp'    => (int) $stat->xp,
            'level' => (int) $stat->level,
        ], 200);
    }

    //───────────────────────────────────────────── ACHIEVEMENTS────────────────────────────────────────────────────────────────────────────────────
    private function unlock(int $userId, string $code): void
    {
        $exists = EventUser::where('user_id', $userId)->exists();
        if (!$exists) {
            return;
        }

        $ach = Achievement::where('code', $code)->first();
        if (!$ach) {
            return;
        }

        $unlocked = UserAchievement::firstOrCreate(
            ['user_id' => $userId, 'achievement_id' => $ach->id],
            ['unlocked_at' => now()]
        );

        if ($unlocked->wasRecentlyCreated) {
            $xp = self::ACHIEVEMENT_XP[$code] ?? 0;
            $this->addXp($userId, $xp);
        }
    }

    private function resolveUserAchiAchievements(int $userId): void
    {
        $count = UsersAchi::where('user_id', $userId)->count();

        foreach ([1=>'first_event',2=>'two_events',3=>'three_events',4=>'four_events',5=>'five_events'] as $t=>$code) {
            if ($count >= $t) {
                $this->unlock($userId, $code);
            }
        }
    }

    public function listAchievements($id)
    {
        $list = UserAchievement::with('achievement')
            ->where('user_id', $id)
            ->orderBy('unlocked_at')
            ->get()
            ->map(fn ($ua) => [
                'code'        => $ua->achievement->code,
                'title'       => $ua->achievement->name,
                'description' => $ua->achievement->description,
                'icon'        => config('app.url') . '/' . ltrim($ua->achievement->icon, '/'),
                'unlocked_at' => $ua->unlocked_at,
            ]);

        return response()->json(['achievements' => $list], 200);
    }

    //───────────────────────────────────────────── LEADERBOARD────────────────────────────────────────────────────────────────────────────────────
    public function leaderboard(Request $req)
    {
        $perPage = $req->query('per_page', 15);

        $qb = UserStat::query()
            ->orderByDesc('level')
            ->orderByDesc('xp');

        $pageData = $qb->paginate($perPage);

        $userIds = $pageData->getCollection()->pluck('user_id');
        $names   = DB::connection('users_db')
                     ->table('users')
                     ->whereIn('id', $userIds)
                     ->pluck('name', 'id');

        $startRank = ($pageData->currentPage() - 1) * $pageData->perPage();

        $rows = $pageData->getCollection()->map(function ($stat, $idx) use ($startRank, $names) {
            return [
                'rank'  => $startRank + $idx + 1,
                'user'  => $names[$stat->user_id] ?? '—',
                'level' => (int) $stat->level,
                'xp'    => (int) $stat->xp,
            ];
        });

        return response()->json([
            'data' => $rows,
            'meta' => [
                'current_page' => $pageData->currentPage(),
                'last_page'    => $pageData->lastPage(),
                'per_page'     => $pageData->perPage(),
                'total'        => $pageData->total(),
            ],
        ], 200);
    }

    //───────────────────────────────────────────── XP LOGIC────────────────────────────────────────────────────────────────────────────────────
    private const EVENT_XP = 25;

    private const ACHIEVEMENT_XP = [
        'first_event'  => 25,
        'two_events'   => 35,
        'three_events' => 45,
        'four_events'  => 55,
        'five_events'  => 65,
    ];

    private const LEVEL_CUTS = [0, 25, 60, 120, 200, 300, 450, 650, 900];

    private function levelFor(int $xp): int
    {
        foreach (array_reverse(self::LEVEL_CUTS, true) as $level => $cut) {
            if ($xp >= $cut) return $level;
        }
        return 0;
    }

    private function addXp(int $userId, int $xp): void
    {
        if ($xp <= 0) return;

        DB::transaction(function () use ($userId, $xp) {
            $row = UserStat::lockForUpdate()->firstOrNew(['user_id' => $userId]);
            $row->xp    += $xp;
            $row->level  = $this->levelFor($row->xp);
            $row->save();
        });

        Cache::forget('user_level:' . $userId);
    }

    //───────────────────────────────────────────── TOKEN────────────────────────────────────────────────────────────────────────────────────
    private function isTokenBlacklisted($token)
    {
        return Cache::has("blacklisted:{$token}");
    }

    private function getTokenFromRequest(Request $request)
    {
        $token = $request->header('Authorization') ?? '';
        if (str_starts_with($token, 'Bearer ')) {
            $token = substr($token, 7);
        }
        if (!$token) {
            throw new \Exception('Token is required.');
        }
        return $token;
    }

    private function validateToken(Request $request)
    {
        $token = $this->getTokenFromRequest($request);
        if ($this->isTokenBlacklisted($token)) {
            throw new \Exception('Unauthorized: Token is blacklisted.');
        }
        return $token;
    }

    //───────────────────────────────────────────── RABBIT────────────────────────────────────────────────────────────────────────────────────
    private function publishToRabbitMQ($queueName, $messageBody)
    {
        $host = env('RABBITMQ_HOST', 'rabbitmq');
        $port = env('RABBITMQ_PORT', 5672);
        $user = env('RABBITMQ_USER', 'guest');
        $pass = env('RABBITMQ_PASSWORD', 'guest');

        try {
            $conn = new AMQPStreamConnection($host, $port, $user, $pass);
            $ch   = $conn->channel();
            $ch->queue_declare($queueName, false, true, false, false);
            $ch->basic_publish(new AMQPMessage($messageBody), '', $queueName);
            $ch->close();
            $conn->close();
        } catch (\Exception $e) {
        }
    }

    // Escuta mensagem do rabbit
    public function listenRabbitMQ()
    {
        $rabbitmqHost     = env('RABBITMQ_HOST', 'rabbitmq');
        $rabbitmqPort     = env('RABBITMQ_PORT', 5672);
        $rabbitmqUser     = env('RABBITMQ_USER', 'guest');
        $rabbitmqPassword = env('RABBITMQ_PASSWORD', 'guest');

        // Consumir apenas a fila principal; a de achievements tem um consumidor dedicado
        $queues = [
            env('RABBITMQ_QUEUE', 'event_joined'),
            // 'ach_event_join-leave', // ← REMOVIDO para evitar dois consumidores na mesma fila
        ];

        try {
            $connection = new AMQPStreamConnection(
                $rabbitmqHost,
                $rabbitmqPort,
                $rabbitmqUser,
                $rabbitmqPassword
            );
            $channel = $connection->channel();

            foreach ($queues as $queueName) {
                $channel->queue_declare($queueName, false, true, false, false);
                $channel->basic_qos(null, 1, null);

                $channel->basic_consume(
                    $queueName,
                    '',
                    false,
                    false,
                    false,
                    false,
                    function (AMQPMessage $msg) {
                        $data = json_decode($msg->body, true);

                        EventUser::firstOrCreate(
                            [
                                'event_id' => $data['event_id'],
                                'user_id'  => $data['user_id'],
                            ],
                            [
                                'event_name' => $data['event_name'],
                                'user_name'  => $data['user_name'],
                                'message'    => $data['message'],
                            ]
                        );

                        $msg->ack();
                    }
                );
            }

            while ($channel->is_consuming()) {
                $channel->wait();
            }

            $channel->close();
            $connection->close();
        } catch (\Exception $e) {
        }
    }

    // Escuta a fila 'event_concluded'
    public function listenEventConcludedQueue()
    {
        $rabbitmqHost     = env('RABBITMQ_HOST', 'rabbitmq');
        $rabbitmqPort     = env('RABBITMQ_PORT', 5672);
        $rabbitmqUser     = env('RABBITMQ_USER', 'guest');
        $rabbitmqPassword = env('RABBITMQ_PASSWORD', 'guest');
        $queueName        = 'event_concluded';

        try {
            $connection = new AMQPStreamConnection($rabbitmqHost, $rabbitmqPort, $rabbitmqUser, $rabbitmqPassword);
            $channel    = $connection->channel();
            $channel->queue_declare($queueName, false, true, false, false);
            $channel->basic_qos(null, 1, null);

            $callback = function (AMQPMessage $msg) {
                try {
                    $data = json_decode($msg->body, true);
                    $eventId   = $data['event_id']   ?? null;
                    $eventName = $data['event_name'] ?? 'Event';
                    $userId    = $data['user_id']    ?? null;
                    $userName  = $data['user_name']  ?? null;

                    $displayText = "{$eventName} - concluded";

                    EventUser::create([
                        'event_id'   => $eventId,
                        'event_name' => $eventName,
                        'user_id'    => $userId,
                        'user_name'  => $userName,
                        'message'    => $displayText,
                    ]);

                    if ($eventId) {
                        $participants = EventUser::where('event_id', $eventId)
                            ->select('user_id', 'user_name')
                            ->distinct()
                            ->get();

                        foreach ($participants as $p) {
                            UsersAchi::firstOrCreate(
                                ['user_id' => $p->user_id, 'event_id' => $eventId],
                                ['event_name' => $eventName, 'completed_at' => now()]
                            );

                            $this->resolveUserAchiAchievements($p->user_id);
                            $this->addXp($p->user_id, self::EVENT_XP);
                        }
                    }

                    $msg->ack();
                } catch (\Exception $e) {
                    $msg->nack(true);
                }
            };

            $channel->basic_consume($queueName, '', false, false, false, false, $callback);

            while ($channel->is_consuming()) {
                $channel->wait();
            }

            $channel->close();
            $connection->close();
        } catch (\Exception $e) {
        }
    }

    //───────────────────────────────────────────── OBSELETE────────────────────────────────────────────────────────────────────────────────────
    private function resolveEventAchievements(int $userId): void
    {
        $total = EventUser::where('user_id', $userId)
            ->where('message', 'like', '%- concluded')
            ->count();

        $milestones = [
            1=>'first_event',2=>'two_events',3=>'three_events',
            4=>'four_events',5=>'five_events',
        ];

        foreach ($milestones as $t=>$code) {
            if ($total >= $t) {
                $this->unlock($userId, $code);
            }
        }
    }
}
