<?php
namespace App\Http\Controllers\Api;

use App\Service\ErrorService;
use App\Service\UserJourneyChallengeService;
use Illuminate\Http\Request;

class CharacterStarChallengeController extends Controller
{
    protected $challengeService;

    public function __construct(UserJourneyChallengeService $challengeService, Request $request)
    {
        $this->challengeService = $challengeService;
        $origin         = $request->header('Origin');
        $referer        = $request->header('Referer');
        $referrerDomain = parse_url($origin, PHP_URL_HOST) ?? parse_url($referer, PHP_URL_HOST);

        if ($referrerDomain != config('services.API_PASS_DOMAIN')) {
            $this->middleware('auth:api', ['except' => ['update', 'progress', 'rewards']]);
        }
    }

    /**
     * 更新玩家星級挑戰
     */
    public function update(Request $request)
    {
        $uid = $this->resolveUid($request);

        if (! $uid) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'AUTH:0005'), 422);
        }

        $chapterId   = $request->input('chapter_id');
        $earnedStars = $this->normalizeEarnedStars($request->input('earned_stars'));

        if (! is_numeric($chapterId) || (int) $chapterId <= 0) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'Journey:0001'), 422);
        }

        if (empty($earnedStars)) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'StarChallenge:0001'), 422);
        }

        try {
            $result = $this->challengeService->updateChallengeProgress(
                $uid,
                (int) $chapterId,
                $earnedStars
            );
        } catch (\InvalidArgumentException $exception) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'StarChallenge:0002'), 422);
        }

        return response()->json(['data' => $result]);
    }

    /**
     * 取得玩家星級挑戰進度
     */
    public function progress(Request $request)
    {
        $uid = $this->resolveUid($request);

        if (! $uid) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'AUTH:0005'), 422);
        }

        $progress = $this->challengeService->getChallengeProgress($uid);

        return response()->json(['data' => $progress]);
    }

    /**
     * 取得玩家星級挑戰獎勵列表
     */
    public function rewards(Request $request)
    {
        $uid = $this->resolveUid($request);

        if (! $uid) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'AUTH:0005'), 422);
        }

        $rewards = $this->challengeService->getChallengeRewards($uid);

        return response()->json(['data' => $rewards]);
    }

    /**
     * 解析請求中的玩家 UID
     */
    protected function resolveUid(Request $request): ?int
    {
        $authUser = auth()->guard('api')->user();

        if ($authUser?->uid) {
            return (int) $authUser->uid;
        }

        $uid = $request->input('uid', $request->query('uid'));

        return is_numeric($uid) ? (int) $uid : null;
    }

    /**
     * 將星級資料調整成整數陣列
     */
    protected function normalizeEarnedStars($input): array
    {
        if (is_string($input)) {
            $trimmed = trim($input);

            if ($trimmed === '') {
                return [];
            }

            $decoded = json_decode($trimmed, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $input = $decoded;
            } else {
                $input = preg_split('/[\s,]+/', $trimmed, -1, PREG_SPLIT_NO_EMPTY);
            }
        }

        if (! is_array($input)) {
            return [];
        }

        $stars = [];

        foreach ($input as $value) {
            if (is_bool($value)) {
                $stars[] = $value ? 1 : 0;
                continue;
            }

            if (is_numeric($value)) {
                $stars[] = (int) $value;
            }
        }

        return $stars;
    }
}
