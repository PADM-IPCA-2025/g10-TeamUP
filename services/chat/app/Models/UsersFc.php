<?php
// app/Models/UsersFc.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UsersFc extends Model
{
    protected $table    = 'users_fc';
    protected $fillable = ['user_id', 'fcm_token'];
}
