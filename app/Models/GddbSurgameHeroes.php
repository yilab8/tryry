<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GddbSurgameHeroes extends Model
{
    use HasFactory;

    protected $table = 'gddb_surgame_heroes';
    public $timestamps = false;

    protected $fillable = [
        'name',
        'icon',
        'card',
        'prefab',
        'skill_01',
        'skill_02',
        'skill_02_evo',
        'rarity',
        'style_group',
        'rank_up_group',
        'rank_func_group',
        'level_group',
        'chain_skill',
        'icon_main_skill',
        'icon_talent',
        'icon_passive',
        'unique_id',
        'convert_item_id',
        'element'
    ];

    protected $casts = [
        'name' => 'string',
        'icon' => 'string',
        'card' => 'string',
        'prefab' => 'string',
        'skill_01' => 'string',
        'skill_02' => 'string',
        'skill_02_evo' => 'string',
        'rarity' => 'string',
        'style_group' => 'string',
        'rank_up_group' => 'integer',
        'rank_func_group' => 'integer',
        'level_group' => 'integer',
        'chain_skill' => 'string',
        'icon_main_skill' => 'string',
        'icon_talent' => 'string',
        'icon_passive' => 'string',
        'convert_item_id' => 'integer',
        'element' => 'integer',
        'unique_id' => 'string'
    ];

    public function users()
    {
          return $this->hasMany(UserCharacter::class, 'character_id', 'unique_id');
    }
}
