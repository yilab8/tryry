<?php
namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

use App\Http\Controllers\Controller;
use App\Models\Users;
use App\Models\UserItems;
use App\Models\UserGachaTimes;
use App\Models\UserGachaOrderDetails;
use App\Models\Gachas;

use App\Service\ErrorService;
use App\Service\UserItemService;
use App\Service\UserGachaOrderService;
use App\Service\UserService;
use App\Service\FileService;
use App\Service\TaskService;
use App\Service\UserStatsService;

use Validator;
use DB;

class UserGachaOrderController extends Controller
{
    public function __construct(Request $request) {
        $origin = $request->header('Origin');
        $referer = $request->header('Referer');
        $referrerDomain = parse_url($origin, PHP_URL_HOST) ?? parse_url($referer, PHP_URL_HOST);
        if($referrerDomain  != config('services.API_PASS_DOMAIN')){
            $this->middleware('auth:api', ['except' => [] ]);
        }
    }


    public function create(Request $request)
    {
        $data = $request->input();

        $user = Users::find(auth()->guard('api')->user()->id);
        if(empty($user)) return response()->json(ErrorService::errorCode(__METHOD__, 'AUTH:0006'), 422);

        if(empty($data['gacha_id'])) return response()->json(ErrorService::errorCode(__METHOD__, 'GachaOrder:0001'), 422);

        $gacha = Gachas::with('gachaDetails')->find($data['gacha_id']);
        if(empty($gacha) || $gacha->gachaDetails->isEmpty()) return response()->json(ErrorService::errorCode(__METHOD__, 'GachaOrder:0002'), 422);

        if($gacha->start_timestamp && now()->timestamp < $gacha->start_timestamp) return response()->json(ErrorService::errorCode(__METHOD__, 'GachaOrder:0004'), 422);
        if($gacha->end_timestamp && now()->timestamp > $gacha->end_timestamp) return response()->json(ErrorService::errorCode(__METHOD__, 'GachaOrder:0004'), 422);

        $currency_item = UserItemService::getItem($gacha->currency_item_id);
        if(empty($currency_item)) return response()->json(ErrorService::errorCode(__METHOD__, 'MallOrder:0002'), 422);

        if(empty($data['times']) || !in_array($data['times'], [1,10])) return response()->json(ErrorService::errorCode(__METHOD__, 'GachaOrder:0003'), 422);

        $price = $data['times']==1?$gacha->one_price:$gacha->ten_price;

        $result = UserGachaOrderService::create($user, $gacha, $data['times'], $price);


        //============ 任務系統 ============
        // 任務Service
        $taskService = new TaskService();
        // 紀錄系統任務
        $userStatsService = new UserStatsService($taskService);
        $statsResult = $userStatsService->updateByKeyword($user, 'gacha');
        $statsResult = $userStatsService->updateByKeyword($user, 'avatar_sr');
        $statsResult = $userStatsService->updateByKeyword($user, 'avatar_ssr');
        $statsResult = $userStatsService->updateByKeyword($user, 'avatar_all');
        $statsResult = $userStatsService->updateByKeyword($user, 'furniture');
        // 玩家任務
        $taskStatsService = new UserStatsService($taskService, $taskService->keywords(), [$taskService, 'calculateStat']);
        $taskStatsService->updateByKeyword($user, 'mall_coin', ['purchase']);
        $taskStatsService->updateByKeyword($user, 'gacha');
        // 本次登入是否有完成任務
        $completedTask = $taskService->getCompletedTasks($user->uid);
        $formattedTaskResult = $taskService->formatCompletedTasks($completedTask);
        //============ 任務系統 ============

        if(empty($result['success'])){
            return response()->json(ErrorService::errorCode(__METHOD__, $result['error_code']), 422);
        }

        unset($result['success']);
        return response()->json(['data' => $result, 'finishedTask' => $formattedTaskResult], 200);
    }

    public function getLog(Request $request, $gacha_id, $page = 1, $limit = 10){
        $data = $request->input();

        $user = Users::find(auth()->guard('api')->user()->id);
        if(empty($user)) return response()->json(ErrorService::errorCode(__METHOD__, 'AUTH:0006'), 422);

        if(empty($gacha_id)) return response()->json(ErrorService::errorCode(__METHOD__, 'GachaOrder:0001'), 422);

        $offset = ($page - 1) * $limit;

        $userGachaDetails = UserGachaOrderDetails::whereHas('userGachaOrder', function ($query) use ($user, $gacha_id) {
                                    $query->where('user_id', $user->id)
                                        ->where('gacha_id', $gacha_id);
                                })
                                ->with('userGachaOrder') // 預加載關聯
                                ->orderByDesc('id')
                                ->skip($offset)
                                ->take($limit)
                                ->get();

                                

        return response()->json(['data' => $userGachaDetails,], 200);
    }


    public function getUserTimes(Request $request, $gacha_id){
        $user = Users::find(auth()->guard('api')->user()->id);
        if(empty($user)) return response()->json(ErrorService::errorCode(__METHOD__, 'AUTH:0006'), 422);

        if(empty($gacha_id)) return response()->json(ErrorService::errorCode(__METHOD__, 'GachaOrder:0001'), 422);

        $userGachaTime = UserGachaTimes::where('user_id', $user->id)->where('gacha_id', $gacha_id)->first();

        return response()->json(['data' => ['times'=>$userGachaTime->times??0] ,], 200);
    }
}
