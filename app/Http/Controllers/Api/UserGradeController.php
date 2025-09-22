<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tasks;
use App\Models\UserItemLogs;
use App\Models\Users;
use App\Models\UserSurGameFunc;
use App\Models\UserSurGameInfo;
use App\Service\ErrorService;
use App\Service\GradeTaskService;
use App\Service\TaskService;
use App\Service\UserItemService;
use Illuminate\Http\Request;

class UserGradeController extends Controller
{
    public function __construct(Request $request)
    {
        $origin         = $request->header('Origin');
        $referer        = $request->header('Referer');
        $referrerDomain = parse_url($origin, PHP_URL_HOST) ?? parse_url($referer, PHP_URL_HOST);
        if ($referrerDomain != config('services.API_PASS_DOMAIN')) {
            $this->middleware('auth:api', ['except' => []]);
        }
    }

    // 取得軍階任務
    public function getUserGradeTask(Request $request, GradeTaskService $gradeTaskService)
    {
        $user = auth()->guard('api')->user();
        $uid  = $user->uid;

        // 僅允許測試環境
        $allowedUrls = [
            'https://laravel.test/api',
            'https://clang-party-dev.wow-dragon.com.tw/api',
            'https://clang_party_dev.wow-dragon.com.tw/api',
        ];

        if (in_array(config('services.API_URL'), $allowedUrls)) {
            // 給予軍階任務
            $userSurgameInfo = UserSurGameInfo::where('uid', $uid)->first();
            $gradeSerivce    = new GradeTaskService();
            $gradeSerivce->autoAsignGradeTask($userSurgameInfo);

            //============ 任務系統 ============
            // 玩家軍階任務
            $gradeSerivce->updateByKeyword($user, 'player');
        }

        $userTasks = $gradeTaskService->getUserGradeTasks($uid, true);
        return response()->json(['data' => $userTasks], 200);
    }

    // 軍階任務進度
    public function updateProgress(Request $request)
    {
        $taskService      = new TaskService();
        $gradeTaskService = new GradeTaskService();
        $uid              = auth()->guard('api')->user()->uid;
        if (empty($uid)) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'AUTH:0005'), 422);
        }

        $taskId = $request->input('task_id');
        if (empty($taskId)) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'TASK:0001'), 422);
        }
        $id = $request->input('process_id');
        // 檢查是否有接任務
        $userTask = $taskService->getUserTask($uid, $taskId, $id);
        if (! $userTask) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'TASK:0001'), 422);
        }

        $progress = $request->input('progress');
        try {
            // 提交進度
            $taskService->submitProgress($uid, $taskId, $progress);
            $result = $gradeTaskService->getUserGradeTasks($uid);
            return response()->json($result, 200);
        } catch (\Exception $e) {

            \Log::error('任務進度更新失敗', [
                'message' => $e->getMessage(),
                'data'    => $userTask,
            ]);
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    // 領取獎勵
    public function claminGradeReward(Request $request)
    {
        $taskService      = new TaskService();
        $gradeTaskService = new GradeTaskService();

        $uid = auth()->guard('api')->user()->uid;
        if (empty($uid)) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'AUTH:0005'), 422);
        }

        $user = Users::where('uid', $uid)->first();
        if (empty($user)) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'AUTH:0006'), 422);
        }

        // 取得當前玩家surgame資料
        $userSurGameInfo = UserSurGameInfo::where('uid', $uid)->first();

        $taskId = $request->input('task_id');
        if (empty($taskId)) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'TASK:0001'), 422);
        }
        $id = $request->input('process_id');
        // 檢查任務是否已完成
        $userTask = $taskService->getUserTask($uid, $taskId, $id);
        if (empty($userTask)) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'TASK:0001'), 422);
        }
        if ($userTask->status !== 'completed') {
            return response()->json(ErrorService::errorCode(__METHOD__, 'TASK:0003'), 422);
        }

        // 檢查任務是否已領取獎勵
        if ($userTask->reward_status) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'TASK:0005'), 422);
        }

        try {
            $currenGradeReward = $gradeTaskService->getCurrentGradeReward($userSurGameInfo->grade_level);
            // 發放道具
            $reward      = Tasks::find($taskId)?->reward;
            $reward      = $this->convertRewards($reward);
            $addFailed   = false;
            $finalReward = [];
            $result      = UserItemService::addItem(UserItemLogs::TYPE_GRADE_TASK, $user->id, $uid, $reward['item_id'], $reward['amount'], 1, '軍階任務獎勵領取');
            if ($result['success'] == 0) {
                $addFailed = true;
            } elseif ($result['success'] == 1 && isset($result['item_id'])) {
                $finalReward[] = [
                    'item_id' => $result['item_id'],
                    'amount'  => $result['qty'],
                ];
            } else {
                $finalReward[] = $reward;
            }

            $reward = $finalReward;
            if ($addFailed) {
                return response()->json(ErrorService::errorCode(__METHOD__, 'UserItem:0002'), 422);
            }
            $changeResults = $taskService->changeRewardStatus($uid, $id);
            $result        = $this->formatterClaimResult($uid);

            $canUpgradeGrade = $gradeTaskService->checkAllTaskProcess($userSurGameInfo);
            // 有升階回傳升階
            if ($canUpgradeGrade) {
                if (is_array($currenGradeReward)) {
                    if (isset($currenGradeReward['item_reward'])) {
                        // 發送升階獎勵
                        foreach ($currenGradeReward as $item) {
                            userItemService::addItem(UserItemLogs::TYPE_GRADE_UPGRADE, $user->id, $user->uid, $item['item_id'], $item['amount'], 1, '主角軍階獎勵');
                        }
                    }

                    if (isset($currenGradeReward['func_reward'])) {
                        // 開放相關功能
                        $userFunc = UserSurGameFunc::firstOrCreate(
                            ['uid' => $uid, 'func_key' => $currenGradeReward['func_reward']['func_key']],
                            ['uid' => $uid, 'func_key' => $currenGradeReward['func_reward']['func_key']]
                        );
                    }

                    $userSurGameInfo->grade_level += 1;
                    $userSurGameInfo->save();
                    $userSurGameInfo = $userSurGameInfo->refresh();
                    // 新增任務
                    $autoSignResult = $gradeTaskService->autoAsignGradeTask($userSurGameInfo);
                    if (empty($autoSignResult)) {
                        return response()->json(ErrorService::errorCode(__METHOD__, 'GRADE:0004'), 422);
                    }
                    $result = $this->formatterClaimResult($uid, $currenGradeReward, $canUpgradeGrade);
                }
            }
            return response()->json($result, 200);
        } catch (\Exception $e) {
            \Log::error('任務獎勵領取失敗', [
                'message' => $e->getMessage(),
                'data'    => $userTask,
            ]);
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    // 軍階獎勵
    public function formatterClaimResult($uid, $gradeUpgradeReward = null, $canUpgradeGrade = false)
    {
        $service         = new GradeTaskService();
        $userSurGameInfo = UserSurGameInfo::with('gddbSurgameGrade')->where('uid', $uid)->first();

        $itemReward = [];
        $funcReward = [];
        if (is_array($gradeUpgradeReward)) {
            if (isset($gradeUpgradeReward['item_reward'])) {
                $itemReward = $gradeUpgradeReward['item_reward'];
            }

            if (isset($gradeUpgradeReward['func_reward'])) {
                $funcReward = $gradeUpgradeReward['func_reward'];
            }
        }

        $result                  = [];
        $result['data']          = $service->getUserGradeTasks($uid, true);
        $result['upgrade_grade'] = [
            'can_upgrade_grade'        => $canUpgradeGrade,
            'current_grade_manager_id' => $userSurGameInfo?->gddbSurgameGrade?->unique_id ?? 1,
        ];
        if (! empty($itemReward)) {
            $result['upgrade_grade']['item_reward'] = $itemReward;
        }
        if (! empty($funcReward)) {
            $result['upgrade_grade']['func_reward'] = $funcReward;
        }

        return $result;
    }
    // 轉換資料
    private function convertRewards($input)
    {
        $output = [];
        if (! empty($input)) {
            $output = [
                'item_id' => $input[0],
                'amount'  => $input[1],
            ];
        }
        return $output;
    }
}
