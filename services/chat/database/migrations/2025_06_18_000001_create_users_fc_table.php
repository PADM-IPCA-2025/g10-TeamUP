<?php
// database/migrations/2025_06_18_000000_create_users_fc_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('users_fc', function (Blueprint $table) {
            $table->id();                           // auto-id; no FK
            $table->unsignedBigInteger('user_id');  // whatever comes from JWT
            $table->string('fcm_token')->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users_fc');
    }
};
