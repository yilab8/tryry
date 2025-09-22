<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserStatus extends Model
{
    use HasFactory;

    protected $table = 'user_statuses';

    protected $fillable = [
        'uid',
        'stamina',
        'stamina_max',
        'next_recover_at',
        'sweep_count',
        'sweep_max',
    ];

    protected $casts = [
        'next_recover_at' => 'datetime',
    ];
}
