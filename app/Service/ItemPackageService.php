<?php
namespace App\Service;

use App\Models\GddbSurgameItemPackage;

// use Carbon\Carbon;
use App\Models\UserItemLogs;
use App\Models\Users;
use App\Service\UserItemService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ItemPackageService
{
    // 檢查是否為禮包/寶箱
    public function isPackageItem($packageItemId)
    {
        return GddbSurgameItemPackage::where('item_id', $packageItemId)->exists();
    }

    // 開啟禮包
    public function openPackage(Users $user, $packageItemId, $selectedItemIds, $amount = 1)
    {
        return DB::transaction(function () use ($user, $packageItemId, $selectedItemIds, $amount) {
            // 1. 扣掉禮包數量
            // $removeResult = UserItemService::removeItem(UserItemLogs::TYPE_ITEM_PACKAGE, $user->id, $user->uid, $packageItemId, -1, 1, '使用禮包');
            // if ($removeResult['success'] === 0) {
            //     throw new \Exception('禮包數量不足');
            // }

            // 2. 取得禮包獎勵
            $allRewards = [];

            // 判斷是否為自選禮包
            if (! empty($selectedItemIds)) {
                // ===== 自選禮包邏輯 =====
                // 自選禮包不應該受 $amount 影響，固定只開啟一次
                $rewards = $this->getPackageRewards($packageItemId, $selectedItemIds);
                if (! $rewards) {
                    Log::error('無效的自選禮包或獎勵', ['packageItemId' => $packageItemId, 'selectedItemIds' => $selectedItemIds]);
                    throw new \Exception('無效的自選禮包或獎勵');
                }

                // 因為只開一次，直接將獎勵賦值給 allRewards
                foreach ($rewards as $reward) {
                    $allRewards[$reward['item_id']] = $reward;
                }
            } else {
                // ===== 隨機/固定禮包邏輯 =====
                // 照舊執行 $amount 次迴圈，來取得多次的隨機獎勵
                for ($i = 0; $i < $amount; $i++) {
                    // 注意：這裡的 selectedItemIds 傳入空陣列
                    $rewards = $this->getPackageRewards($packageItemId, []);
                    if (! $rewards) {
                        Log::error('無效的隨機禮包或獎勵', ['packageItemId' => $packageItemId]);
                        throw new \Exception('無效的隨機禮包或獎勵');
                    }

                    // 將每次開出的獎勵加總到 allRewards
                    foreach ($rewards as $reward) {
                        if (! isset($allRewards[$reward['item_id']])) {
                            $allRewards[$reward['item_id']] = $reward;
                        } else {
                            $allRewards[$reward['item_id']]['qty'] += $reward['qty'];
                        }
                    }
                }
            }

            // 統一發獎
            foreach ($allRewards as $reward) {
                $addResult = UserItemService::addItem(
                    UserItemLogs::TYPE_ITEM_PACKAGE,
                    $user->id,
                    $user->uid,
                    $reward['item_id'],
                    $reward['qty'],
                    1,
                    '開啟禮包獲得'
                );

                if ($addResult['success'] === 0) {
                    Log::error('獎勵發放失敗: item_id = ' . $reward['item_id'] . ', qty = ' . $reward['qty']);
                    throw new \Exception('獎勵發放失敗: item_id = ' . $reward['item_id']);
                }
            }

            return ['success' => 1, 'data' => array_values($allRewards)];
        });
    }

    // 是否為自動開啟禮包
    public function isAutoOpen($packageItemId)
    {
        return GddbSurgameItemPackage::where('item_id', $packageItemId)->first()?->auth_use === 1;
    }

    // 所需數量是否足夠
    public function hasEnoughForOpen($uid, $packageItemId)
    {
        $currentCount = UserItems::where('uid', $uid)->where('item_id', $packageItemId)->first()?->qty ?? 0;
        $necessary    = GddbSurgameItemPackage::where('item_id', $packageItemId)->first()?->use_necessary ?? 1;
        return $CurrentCount >= $necessary;
    }

    // 是否為自選禮包
    public function isChoiceBox($packageItemId)
    {
        return GddbSurgameItemPackage::where('item_id', $packageItemId)->first()?->choice_box === 1;
    }

    // 取得禮包獎勵(自選禮包需有item_id, 非自選為隨機)
    public function getPackageRewards($packageItemId, $selectedItemIds = [])
    {
        $package = GddbSurgameItemPackage::where('item_id', $packageItemId)->first();
        if (! $package) {
            return null; // 找不到禮包
        }

        // 轉換 JSON
        $contents = json_decode("[" . $package->contents . "]", true);
        $contents = $this->transformContents($contents);
        if (! is_array($contents)) {
            return null; // 內容格式錯誤
        }

        if (! empty($selectedItemIds)) {
            // ===== 自選禮包 =====
            if (! $package->choice_box) {
                return null; // 不是自選禮包
            }

            $rewards = [];
            foreach ($contents as $content) {
                if (in_array($content['item_id'], $selectedItemIds)) {
                    $rewards[] = $content;
                }
            }

            return ! empty($rewards) ? $rewards : null;
        } else {
            // ===== 隨機禮包 =====
            if ($package->choice_box) {
                return null; // 不是隨機禮包
            }

            $randomTimes = (int) $package->random_times; // 抽取次數

            if ($randomTimes === 0) {
                // 0代表全拿
                return $contents;
            }

            // 每次呼叫都要重新打亂
            shuffle($contents);

            // 從打亂後的內容取 randomTimes 個
            return array_slice($contents, 0, $randomTimes);
        }
        return null;
    }

    // 資料轉換
    private function transformContents($contents)
    {
        $result = [];

        if (! is_array($contents)) {
        }

        foreach ($contents as $content) {
            if (is_array($content) && count($content) == 2) {
                $result[] = [
                    'item_id' => (int) $content[0],
                    'qty'     => (int) $content[1],
                ];
            }
        }

        return $result;
    }

}
