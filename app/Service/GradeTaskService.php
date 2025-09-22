<?php
namespace App\Service;

use App\Models\GddbSurgameGrade as GradeData;
use App\Models\Tasks;
use App\Models\UserItemLogs;
use App\Models\Users;
use App\Models\UserSurGameInfo;
use App\Models\UserTasks;
use App\Service\TaskService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GradeTaskService
{
    protected TaskService $taskService;
    public function __construct()
    {
        $this->taskService = new TaskService();
    }

    // 軍階升級
    public function updateUserGrade($uid): bool
    {
        $userGrade = UserSurGameInfo::where('uid', $uid)->first();
        try {
            $userGrade->increment('grade_level', 1);
        } catch (\Exception $e) {
            Log::info('軍階升級失敗', ['information' => $e->getMessage()]);
            return false;
        }
        return true;
    }

    // 取得玩家當前任務
    public function getUserGradeTasks(int $uid, bool $onlyCurrentGrade = false)
    {
        $gradeId = null;
        if ($onlyCurrentGrade) {
            $userSurGameInfo = UserSurGameInfo::where('uid', $uid)->first();
            $gradeId         = GradeData::where('related_level', $userSurGameInfo->grade_level)
                ->first()?->unique_id ?? 11;
        }

        $datas = UserTasks::where('uid', $uid)
            ->with(['task', 'task.grade'])
            ->whereHas('task.grade', function ($q) use ($onlyCurrentGrade, $gradeId) {
                if ($onlyCurrentGrade && $gradeId) {
                    $q->where('series_id', $gradeId);
                }
            })
            ->whereHas('task', function ($t) {
                $t->where('type', 'grade');
            })
            ->orderByRaw("
            CASE
            WHEN status = 'completed' AND reward_status = 0 THEN 1
            WHEN status = 'in_progress' THEN 2
            WHEN status = 'completed' AND reward_status = 1 THEN 3
            ELSE 4
            END
        ")
            ->orderBy('id')
            ->get()
            ->map(function ($q) {
                $q->task->reward = $q?->task?->reward
                ? $this->formatItems($q->task->reward)
                : [];
                return $q;
            });

        return $this->formatterGradeTask($datas);
    }

    public function checkAllTaskProcess($userSurGameInfo): bool
    {
        $grade = GradeData::where('related_level', $userSurGameInfo->grade_level)->first();
        if (! $grade) {
            return false;
        }

        $quests = $grade->quests;
        if (is_string($quests)) {
            $decoded = json_decode($quests, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $quests = $decoded;
            }
        }
        if (! is_array($quests) || empty($quests)) {
            return true;
        }

        $taskIds = array_values(array_unique(array_map('intval', $quests)));

        $done = UserTasks::where('uid', $userSurGameInfo->uid)
            ->whereIn('task_id', $taskIds)
            ->whereNotNull('completed_at')
            ->where('reward_status', 1)
            ->distinct('task_id')
            ->count('task_id');

        return $done === count($taskIds);
    }

    // 接取任務
    public function autoAsignGradeTask($userSurGameInfo)
    {
        $uid = $userSurGameInfo->uid;
        // 當前已接取的任務
        $UserTaskIds = UserTasks::with('task')
            ->where('uid', $uid)
            ->whereHas('task', function ($q) {
                $q->where('type', 'grade');
            })
            ->get()
            ->map(function ($userTask) {
                if (! empty($userTask?->task?->series_id)) {
                    return $userTask;
                }
            })->pluck('task_id')->toArray();

        // 可以接取的任務
        $gradeId         = GradeData::where('related_level', $userSurGameInfo->grade_level)->first()?->unique_id ?? 11;
        $availabeTaskIds = Tasks::where('series_id', $gradeId)
            ->whereNotIn('id', $UserTaskIds)
            ->get()
            ->pluck('id')
            ->toArray();

        $ttl     = 5;
        $wait    = 2;
        $taskAry = [];

        foreach ($availabeTaskIds as $i => $taskId) {
            $key = "lock:user:{$uid}:task:{$taskId}";
            try {
                Cache::lock($key, $ttl)->block($wait, function () use ($uid, $taskId, &$taskAry) {
                    $row = DB::transaction(function () use ($uid, $taskId) {
                        return UserTasks::updateOrCreate(
                            ['uid' => $uid, 'task_id' => $taskId],
                            [
                                'uid'     => $uid,
                                'task_id' => $taskId,
                            ]
                        );
                    });
                    $taskAry[] = $row->task_id;
                });
            } catch (LockTimeoutException $e) {
                Log::warning("任務 {$taskId} 取得鎖逾時，可能正在被其他程序處理，已略過。");
                continue;
            } catch (\Throwable $e) {
                Log::error("任務 {$taskId} 寫入失敗：{$e->getMessage()}");
                continue;
            }
        }

        return $taskAry;
    }

    // 檢查是否有軍階任務資料
    public function checkFirstGradeTask($userSurGameInfo)
    {
        return empty(UserTasks::with('task')
                ->whereHas('task', function ($t) {
                    $t->where('type', 'grade')
                        ->whereNotNull('series_id');
                })
                ->first());
    }

    // 取得軍階獎勵
    public function getCurrentGradeReward($gradeLevel)
    {
        $gradeData = GradeData::where('related_level', $gradeLevel)->first();

        if (empty($gradeData)) {
            return ['success' => 0, 'messages' => '查無相關軍階資料'];
        }

        if (empty($gradeData->reward) && empty($gradeData->func_key) && empty($gradeData->func_desc)) {
            return ['success' => 0, 'messages' => '查無相關獎勵或相關功能'];
        }

        $rewardAry = [];

        if (! empty($gradeData->reward)) {
            $reward = $gradeData->reward;
            if (is_string($reward)) {
                $reward = json_decode($reward, true);
            }
            $rewardAry['item_reward'] = [
                'item_id' => $reward[0],
                'amount'  => $reward[1],
            ];
        } else {
            $rewardAry['func_reward'] = [
                'func_key'  => $gradeData->func_key,
                'func_desc' => $gradeData->func_desc,
            ];
        }

        return $rewardAry;
    }

    public function formatterGradeTask($datas)
    {
        $tasksAry = [];
        foreach ($datas as $index => $data) {
            $tasksAry[] =
                [
                'process_id'        => $data->id,
                'task_id'           => $data->task_id,
                'localization_name' => $data->task->localization_name,
                'status'            => $data->status,
                'progress'          => $data->progress,
                'reward_status'     => $data->reward_status,
                'condition'         => $data->task->condition,
                'description'       => $data->task->description,
                'reward'            => $data->task->reward,
            ];
        }
        return $tasksAry;
    }

    function formatItems($input)
    {
        if (is_string($input)) {
            $input = json_decode($input, true);
        }

        if (isset($input[0]) && is_array($input[0])) {
            $result = [];
            foreach ($input as $item) {
                $result[] = [
                    'item_id' => $item[0],
                    'amount'  => $item[1],
                ];
            }
            return $result;
        } else {
            return [
                'item_id' => $input[0],
                'amount'  => $input[1],
            ];
        }
    }
    //=========== 任務系統資料
    /**
     * 提交進度
     */
    public function submitProgress(string $uid, int $taskId, array $progress)
    {
        return $this->taskService->submitProgress($uid, $taskId, $progress);
    }

    /**
     * 任務 condition action 關鍵字
     */
    public function keywords(): array
    {
        return [
            'player'    => ['player_level'],
            'mall_coin' => ['spend_mall_coin_total'],
        ];
    }

    /**
     * 根據 keyword 更新任務
     */
    public function updateByKeyword(Users $user, string $keyword, array $onlyColumns = [], $value = null)
    {
        $finishedTaskIds = [];
        $finishedTasks   = [];

        // 先檢查 keyword 是否存在
        $keywords = $this->keywords();
        if (! isset($keywords[$keyword])) {
            return $finishedTasks;
        }

        // 過濾指定欄位
        $columns = $keywords[$keyword];
        if (! empty($onlyColumns)) {
            $columns = array_intersect($columns, $onlyColumns);
        }

        foreach ($columns as $column) {
            // 計算最新統計數據
            $recordCount = $this->calculateStat($user->uid, $column, $value);
            if ($recordCount === null) {
                continue;
            }
            // 找出符合條件的任務
            $taskIds = $this->getTaskIdsByColumn($column, $user->uid);
            if (empty($taskIds)) {
                continue;
            }

            // 準備進度
            $progress = ['count' => $recordCount];

            // 更新任務
            $resultTaskIds   = $this->updateTaskData($user, $taskIds, $progress);
            $finishedTaskIds = array_merge($finishedTaskIds, $resultTaskIds);
        }

        // 回傳已完成任務
        if (! empty($finishedTaskIds)) {
            $finishedTasks = $this->taskService->getCompletedTasks($user->uid, $finishedTaskIds);
        }

        return $finishedTasks;
    }

    /**
     * 計算統計資料
     */
    public function calculateStat(string $uid, string $column, $value = null): ?int
    {
        $user = Users::where('uid', $uid)->first();
        if (! $user) {
            return null;
        }
        switch ($column) {
            case 'player_level':
                return UserSurGameInfo::where('uid', $uid)->value('main_character_level') ?? 0;
                break;
            case 'spend_mall_coin_total':
                $total = UserItemLogs::where('user_id', $user->id)
                    ->where('item_id', 100)
                    ->where('qty', '<', 0)
                    ->sum('qty');

                $total = abs($total);
                return (int) $total;
                break;
        }
        return null;
    }

    /**
     * 取得符合條件的任務 ID
     */
    private function getTaskIdsByColumn(string $column, $uid): array
    {
        return UserTasks::where('uid', $uid)
            ->leftjoin('tasks', 'user_tasks.task_id', '=', 'tasks.id')
            ->where('tasks.type', 'grade')
            ->get(['user_tasks.*', 'tasks.condition', 'tasks.type', 'tasks.id as task_id'])
            ->map(function ($userTask) {
                if (is_string($userTask->condition)) {
                    $decoded = json_decode($userTask->condition, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        $userTask->condition = $decoded;
                    } else {
                        $userTask->condition = null;
                    }
                }
                return $userTask;
            })
            ->filter(fn($userTask) =>
                is_array($userTask->condition) &&
                ($userTask->condition['action'] ?? null) === $column
                && $userTask->type === 'grade'
                && $userTask->status === 'in_progress'
            )
            ->pluck('task_id')
            ->toArray();
    }

    /**
     * 更新任務資料
     */
    public function updateTaskData(Users $user, array $taskIds, array $progress): array
    {
        $finishedTaskIds = [];
        foreach ($taskIds as $taskId) {
            try {
                $userTask = $this->submitProgress($user->uid, $taskId, $progress);

                if ($userTask->status === 'completed') {
                    $finishedTaskIds[] = $taskId;
                }
            } catch (\Throwable $e) {
                Log::error('更新玩家任務資料失敗', [
                    'uid'    => $user->uid,
                    'taskId' => $taskId,
                    'error'  => $e->getMessage(),
                ]);
            }
        }

        return $finishedTaskIds;
    }
}
