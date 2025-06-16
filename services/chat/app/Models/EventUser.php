<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EventUser extends Model
{
    use HasFactory;

    /** The exact table name in MySQL */
    protected $table = 'event_user';

    /** Disable created_at / updated_at handling */
    public $timestamps = false;

    /** Fields allowed for mass-assignment */
    protected $fillable = [
        'event_id',
        'event_name',
        'user_id',
        'user_name',
        'message',
    ];

    /** (Optional) Cast IDs to integers so they’re always numeric */
    protected $casts = [
        'event_id' => 'integer',
        'user_id'  => 'integer',
    ];

    /** (Optional) Everything else stays guarded */
    protected $guarded = [];
}
