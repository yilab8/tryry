<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserSurGameInfo;
use App\Service\ErrorService;
use App\Service\TalentService;
use Illuminate\Http\Request;

class TalentController extends Controller
{
    public function __construct(Request $request)
    {
        $origin         = $request->header('Origin');
        $referer        = $request->header('Referer');
        $referrerDomain = parse_url($origin, PHP_URL_HOST) ?? parse_url($referer, PHP_URL_HOST);
        if ($referrerDomain != config('services.API_PASS_DOMAIN')) {
            $this->middleware('auth:api', ['except' => ['']]);
        }
    }

    // 玩家天賦
    public function getUserTalents(Request $request, TalentService $talentService)
    {
        $uid = auth()->guard('api')->user()->uid;
        if (empty($uid)) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'AUTH:0005'), 422);
        }
        $results = $talentService->getUserTalents($uid);
        return response()->json(['data' => $results], 200);
    }

    // 玩家抽取天賦
    public function drawTalent(Request $request, TalentService $talentService)
    {
        $uid = auth()->guard('api')->user()->uid;
        if (empty($uid)) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'AUTH:0005'), 422);
        }

        // surgameinfo
        $surgameinfo = UserSurGameInfo::where('uid', $uid)->first();
        if ($surgameinfo === null) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'AUTH:0006'), 422);
        }

        // 檢查玩家是否還能抽獎
        $checkPool = $talentService->checkMaxLevelTalentPool($surgameinfo);

        if ($checkPool['success'] === 0) {
            return response()->json(
                ErrorService::errorCode(__METHOD__, $checkPool['error_code']),
                422
            );
        }
        // 沒有池子，建立一個
        if ($checkPool['status'] === 'pending') {
            $results = $talentService->createTalentPool($uid, $checkPool['level']);
        }

        // 拿最新可用的池子
        $talentPool = $talentService->getAvailableTalent($uid);
        if (empty($talentPool)) {
            return response()->json(
                ErrorService::errorCode(__METHOD__, 'TALENT:0002'),
                422
            );
        }

        // 抽獎
        $drawResult = $talentService->executeDraw($uid, $talentPool['session_id'], $talentPool['items'] ?? []);
        if ($drawResult['success'] === 0) {
            return response()->json(ErrorService::errorCode(__METHOD__, $drawResult['error_code']), 422);
        }
        $itemCode = $drawResult['data'];

        // 抽獎結果美化
        $formattedResults = $talentService->formatDrawResult($uid, $itemCode);
        return response()->json(['data' => $formattedResults], 200);
    }
}
