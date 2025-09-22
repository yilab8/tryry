<?php

namespace App\Console\Commands;

use App\Models\UserStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
class ResetStatus extends Command
{
    protected $signature = 'status:reset';

    protected $description = '重置使用者狀態';

    public function handle()
    {
        // 掃蕩次數不足max的 補回max
        $this->info('開始重置掃蕩次數...');
        Log::info('【掃蕩次數】開始重置');

        $userStatuses = UserStatus::whereColumn('sweep_count', '<', 'sweep_max')->get();
        foreach ($userStatuses as $userStatus) {
            Log::info('【掃蕩次數】重置使用者', [
                'uid' => $userStatus->uid,
                'before' => $userStatus->sweep_count,
                'after' => $userStatus->sweep_max
            ]);

            $userStatus->sweep_count = $userStatus->sweep_max;
            $userStatus->save();
        }

        $this->info("已重置 {$userStatuses->count()} 位使用者的掃蕩次數");
        Log::info('【掃蕩次數】重置完成', ['count' => $userStatuses->count()]);
    }
}
