<?php
namespace App\Models;

use App\Models\GddbSurgameGrade;
use App\Models\Users;
use App\Models\UserSlotEquipment;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserSurGameInfo extends Model
{
    use HasFactory;

    protected $table = 'user_surgame_infos';

    protected $fillable = [
        'uid',
        'main_chapter',
        'main_character_level',
        'current_exp',
        'grade_level',
    ];

    protected $hidden = [
        'id',
        'created_at',
        'updated_at',
    ];

    /**
     * 為新用戶創建初始遊戲資料
     */
    public static function createInitialData($uid)
    {
        return self::create([
            'uid'                  => $uid,
            'main_chapter'         => 1,
            'main_character_level' => 1,
            'current_exp'          => 0,
            'grade_level'          => 1,
        ]);
    }

    public function user()
    {
        return $this->belongsTo(Users::class, 'uid', 'uid');
    }

    public function gddbSurgameGrade()
    {
        return $this->belongsTo(GddbSurgameGrade::class, 'grade_level', 'related_level');
    }

    public function talentSessions()
    {
        return $this->hasMany(UserTalentPoolSession::class, 'uid', 'uid');
    }

    public function slotEquipments()
    {
        return $this->hasMany(UserSlotEquipment::class, 'uid', 'uid');
    }
}
