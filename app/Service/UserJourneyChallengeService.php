<?php
namespace App\Service;

use App\Models\GddbSurgameJourneyStarReward;
use App\Models\UserJourneyStarChallenge;
use Illuminate\Support\Facades\DB;

class UserJourneyChallengeService
{
    protected $journeyService;

    public function __construct(UserJourneyService $journeyService)
    {
        $this->journeyService = $journeyService;
    }

    /**
     * 更新玩家星級挑戰進度
     *
     * @param int   $uid 玩家 UID
     * @param int   $chapterId 章節編號（允許 unique_id 或資料表 id）
     * @param array $earnedStars 本次取得的星星資訊
     * @return array
     */
    public function updateChallengeProgress(int $uid, int $chapterId, array $earnedStars): array
    {
        $journey = $this->journeyService->findJourneyByIdentifier($chapterId);

        if (! $journey) {
            throw new \InvalidArgumentException('指定的章節不存在');
        }

        $newMask = $this->buildStarMask($earnedStars);

        return DB::transaction(function () use ($uid, $journey, $newMask) {
            $progress = UserJourneyStarChallenge::query()
                ->where('uid', $uid)
                ->where('challenge_id', $journey->unique_id)
                ->lockForUpdate()
                ->first();

            if (! $progress) {
                $progress = new UserJourneyStarChallenge([
                    'uid'          => $uid,
                    'challenge_id' => $journey->unique_id,
                    'stars_mask'   => 0,
                ]);
            }

            if ($newMask > 0) {
                $progress->stars_mask |= $newMask;
            }

            $progress->save();

            $totalStars = $this->calculateTotalStars($uid);
            $this->journeyService->syncTotalStars($uid, $totalStars);

            return [
                'chapter_id'  => (int) $progress->challenge_id,
                'stars_mask'  => (int) $progress->stars_mask,
                'stars'       => $this->formatStarOutput((int) $progress->stars_mask),
                'stars_total' => $totalStars,
            ];
        });
    }

    /**
     * 取得玩家星級挑戰進度概況
     *
     * @param int $uid 玩家 UID
     * @return array
     */
    public function getChallengeProgress(int $uid): array
    {
        $challenges = UserJourneyStarChallenge::where('uid', $uid)->get();

        $chapterInfos = [];
        $totalStars   = 0;

        foreach ($challenges as $challenge) {
            $flags = $this->maskToStarFlags((int) $challenge->stars_mask);
            $chapterInfos[] = [
                'chapter_id' => (int) $challenge->challenge_id,
                'stars'      => $this->formatStarOutput((int) $challenge->stars_mask),
            ];
            $totalStars += array_sum($flags);
        }

        return [
            'stars_total'          => $totalStars,
            'chapter_informations' => $chapterInfos,
        ];
    }

    /**
     * 取得玩家可領取的星級獎勵
     *
     * @param int $uid 玩家 UID
     * @return array
     */
    public function getChallengeRewards(int $uid): array
    {
        $totalStars = $this->journeyService->getTotalStars($uid);

        return GddbSurgameJourneyStarReward::query()
            ->orderBy('star_count')
            ->get()
            ->map(function (GddbSurgameJourneyStarReward $reward) use ($totalStars) {
                return [
                    'unique_id'     => (int) $reward->unique_id,
                    'type'          => $reward->type,
                    'star_count'    => (int) $reward->star_count,
                    'reward_status' => $totalStars >= (int) $reward->star_count ? 1 : 0,
                    'rewards'       => $this->journeyService->formatRewards($reward->rewards),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * 將 payload 轉換成星星位元圖
     *
     * @param array $earnedStars 取得的星星資訊
     * @return int
     */
    protected function buildStarMask(array $earnedStars): int
    {
        $mask = 0;

        $isBooleanMap = true;
        foreach ($earnedStars as $value) {
            if (! is_numeric($value) || ! in_array((int) $value, [0, 1], true)) {
                $isBooleanMap = false;
                break;
            }
        }

        if ($isBooleanMap) {
            foreach (array_values($earnedStars) as $index => $value) {
                if ((int) $value === 1) {
                    $mask |= 1 << $index;
                }
            }

            return $mask;
        }

        foreach ($earnedStars as $value) {
            if (! is_numeric($value)) {
                continue;
            }

            $starIndex = (int) $value;
            if ($starIndex <= 0) {
                continue;
            }

            $mask |= 1 << ($starIndex - 1);
        }

        return $mask;
    }

    /**
     * 將位元圖轉成星星陣列
     *
     * @param int $mask 星星位元
     * @return array
     */
    protected function maskToStarFlags(int $mask): array
    {
        $flags = [];

        for ($i = 0; $i < 3; $i++) {
            $flags[$i] = ($mask & (1 << $i)) ? 1 : 0;
        }

        return $flags;
    }

    /**
     * 產生標準化的星級輸出格式
     *
     * @param int $mask 星星位元
     * @return array
     */
    protected function formatStarOutput(int $mask): array
    {
        $flags = $this->maskToStarFlags($mask);

        return [
            'star1' => $flags[0] ?? 0,
            'star2' => $flags[1] ?? 0,
            'star3' => $flags[2] ?? 0,
        ];
    }

    /**
     * 計算玩家所有章節的星星總數
     *
     * @param int $uid 玩家 UID
     * @return int
     */
    protected function calculateTotalStars(int $uid): int
    {
        return UserJourneyStarChallenge::where('uid', $uid)
            ->get()
            ->sum(function (UserJourneyStarChallenge $challenge) {
                return array_sum($this->maskToStarFlags((int) $challenge->stars_mask));
            });
    }
}
