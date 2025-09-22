<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GddbSurgameTalent extends Model
{
    protected $table = 'gddb_surgame_talent';
    public $timestamps = false;
    protected $fillable = [
        'card_id',
        'lv',
        'icon',
        'name',
        'description',
        'func',
        'parament',
    ];
}
