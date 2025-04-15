<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Connection\AMQPSSLConnection;
class AuthController extends Controller
{
    // Register a new user
    public function register(Request $request)
    {
        $validatedData = $request->validate([
            'name' => 'required|string|unique:users|regex:/^\w+$/|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:6|confirmed',
            'sports' => 'array',
        ]);

        $user = User::create([
            'name' => $validatedData['name'],
            'email' => $validatedData['email'],
            'password' => bcrypt($validatedData['password']),
            'sport' => $validatedData['sport'] ?? null,
        ]);

        if ($request->has('sports')) {
            $user->sports()->sync($request->sports);
        }

        // Send email verification notification
        $user->sendEmailVerificationNotification();

        return response()->json([
            'message' => 'User registered successfully. Please check your email for verification instructions.',
        ], 201);
    }

    // User login
    public function login(Request $request)
    {
        $credentials = $request->only('email', 'password');

        // Attempt to authenticate the user
        if (!auth()->attempt($credentials)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        // Get the authenticated user
        $user = auth()->user();

        // Check if the user's email is verified
        if (!$user->hasVerifiedEmail()) {
            return response()->json(['error' => 'Email not verified'], 403);
        }

        // Include the user's name in the token claims
        $customClaims = ['name' => $user->name];

        // Generate a token with custom claims
        $token = JWTAuth::claims($customClaims)->fromUser($user);

        // Return the token including user's name and id
        return $this->respondWithToken($token, [
            'id' => $user->id,
            'name' => $user->name,
        ]);
    }

    // Helper function to respond with token
    protected function respondWithToken($token, array $additionalPayload = [])
    {
        return response()->json([
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => JWTAuth::factory()->getTTL() * 60,
            'payload' => $additionalPayload,
        ]);
    }

    // Get the authenticated user
    public function me()
    {
        $user = JWTAuth::user()->load('sports');
        return response()->json($user);
    }

    public function logout()
    {
        try {
            $token = JWTAuth::getToken();
            JWTAuth::invalidate($token);

            // Optionally store the invalidated token in Redis for additional validation
            $redis = app('redis');
            $redis->setex('blacklist:' . $token, config('jwt.ttl') * 60, true);

            // Publish the blacklisted token to RabbitMQ
            $this->publishToRabbitMQ($token);

            return response()->json(['message' => 'Successfully logged out']);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Something went wrong'], 500);
        }
    }

    // Helper function to publish to RabbitMQ and initialize Redis state
    protected function publishToRabbitMQ($token)
    {
        try {
            // Configurações de SSL/TLS
            $ssl_options = [
                'cafile' => base_path('Certificados/ca_certificate.pem'), // Certificado da CA
                'verify_peer' => true,                                   // Validar o certificado do RabbitMQ
                'verify_peer_name' => true,                              // Validar o nome do certificado
                
            ];
    
            // Criar a conexão com RabbitMQ via AMQPS
            $connection = new AMQPSSLConnection(
                'rabbitmq',   // RabbitMQ host
                5671,         // RabbitMQ porta AMQPS
                'guest',      // RabbitMQ user
                'guest',      // RabbitMQ password
                '/',          // Virtual host
                $ssl_options  // Configurações de SSL
            );
    
            // Verificar se a conexão foi bem-sucedida
            if (!$connection->isConnected()) {
                logger()->error("Failed to connect to RabbitMQ server.");
                return;
            }
    
            $channel = $connection->channel();
    
            // Declarar uma exchange do tipo fan-out
            $exchange = 'fanout_exchange';
            $channel->exchange_declare($exchange, 'fanout', false, true, false);
    
            // Criar uma nova mensagem com o token como payload
            $msg = new AMQPMessage($token);
    
            // Publicar a mensagem na exchange
            $channel->basic_publish($msg, $exchange);
    
            // Armazenar o token no Redis com um contador para o número de consumidores
            $redis = app('redis');
            $redis->setex("blacklisted:{$token}", config('jwt.ttl') * 60, 3); // 3 serviços
    
            // Fechar o canal e a conexão
            $channel->close();
            $connection->close();
    
            logger()->info("Published and initialized token: {$token}");
        } catch (\Exception $e) {
            // Logar o erro com mais informações
            logger()->error("Failed to publish message to RabbitMQ: " . $e->getMessage(), [
                'token' => $token,  // Adiciona o token para fins de debug
                'exception' => $e
            ]);
        }
    }


    // Delete user account
    public function delete()
    {
        $user = JWTAuth::user();

        // Check if the user is an admin
        if ($user->is_admin) {
            return response()->json([
                'message' => 'Cannot delete admin.'
            ], 403); // 403 Forbidden
        }

        JWTAuth::invalidate(JWTAuth::getToken());
        $user->delete();

        return response()->json(['message' => 'User deleted successfully']);
    }

    // Update user details
    public function update(Request $request)
    {
        // Validate the incoming request
        $validatedData = $request->validate([
            'name' => [
                'sometimes',
                'required',
                'string',
                'unique:users,name,' . auth()->id(),
                'regex:/^\w+$/',
                'max:255'
            ],
            'email' => [
                'sometimes',
                'required',
                'string',
                'email',
                'max:255',
                'unique:users,email,' . auth()->id()
            ],
            'sports' => 'sometimes|array',
            'sports.*' => 'exists:sports,id', // Validate that each sport ID exists
        ]);

        // Get the authenticated user
        $user = auth()->user();

        // Update name and email if they are provided
        if (isset($validatedData['name'])) {
            $user->name = $validatedData['name'];
        }
        if (isset($validatedData['email'])) {
            $user->email = $validatedData['email'];
        }

        // Sync sports if provided
        if (isset($validatedData['sports'])) {
            $user->sports()->sync($validatedData['sports']);
        }

        // Save the changes
        $user->save();

        // Return the updated user data
        return response()->json([
            'message' => 'User updated successfully',
            'user' => $user->load('sports'), // Include sports in the response
        ], 200);
    }
}