<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Users;
use App\Models\GddbSurgameHeroes;

class UserCharacter extends Model
{
    use HasFactory;

    protected $table = 'user_characters';

    protected $fillable = [
        'uid',
        'character_id',
        'star_level',
        'has_use',
        'slot_index',
    ];

    protected $casts = [
        'uid' => 'integer',
        'character_id' => 'integer',
        'star_level' => 'integer',
        'has_use' => 'integer',
        'slot_index' => 'integer',
    ];

    protected $hidden = [
        'uid', 'created_at', 'updated_at', 'id'
    ];

    public function user()
    {
        return $this->belongsTo(Users::class, 'uid', 'uid');
    }

    public function character()
    {
        return $this->belongsTo(GddbSurgameHeroes::class, 'character_id', 'unique_id');
    }
}
