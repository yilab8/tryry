<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GddbSurgameJourneyReward extends Model
{
    protected $table = 'gddb_surgame_journey_rewards';

    public $timestamps = false;

    protected $fillable = [
        'journey_id',
        'wave',
        'rewards',
    ];
}
