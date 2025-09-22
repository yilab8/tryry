<?php
namespace App\Service;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Intervention\Image\Facades\Image;
use App\Models\Users;
use App\Models\UserItems;
use App\Models\UserItemLogs;
use App\Models\UserGachaOrders;
use App\Models\UserGachaOrderDetails;
use App\Models\UserGachaTimes;
use App\Models\Settings;

use App\Service\FileService;
use App\Service\UserItemService;

use Carbon\Carbon;
use DB;
use Cache;

class UserGachaOrderService extends AppService
{
    public function __construct(){

    }


    public static $map_tickets = [

    ];

    /**
    *
    **/
    public static function create($user, $gacha, $times, $price){

        $result = [
            'success' => 1,
            'error_code' => '',
        ];

        // 檢查用戶貨幣
        $userCurrencyItem = UserItems::where('user_id', $user->id)
            ->where('item_id', $gacha->currency_item_id)
            ->select('id', 'qty')
            ->first();

        if (!$userCurrencyItem || $userCurrencyItem->qty < $price) {
            return ['success' => 0, 'error_code' => 'MallOrder:0010'];
        }

        // 快取設定值，減少對 Settings 查詢
        $ticket_mapping = Cache::remember('avatar_to_ticket', 600, function () {
            $setting = Settings::where('name', 'avatar_to_ticket')->first();
            return empty($setting) ? ['SSR' => 30, 'SR' => 7, 'R' => 1, 'N' => 1] : json_decode($setting->value, true);
        });

        $get_items = [];
        DB::beginTransaction();
        try {
            // 建立扭蛋主紀錄
            $userGachaOrder = UserGachaOrders::create([
                'user_id' => $user->id,
                'uid' => $user->uid,
                'gacha_id' => $gacha->id,
                'times' => $times,
                'currency_item_id' => $gacha->currency_item_id,
                'currency_amount' => $price,
            ]);

            // 扣除貨幣
            $currencyResult = UserItemService::addItem(
                UserItemLogs::TYPE_ITEM_USE,
                $user->id,
                $user->uid,
                $gacha->currency_item_id,
                ($price * -1),
                1,
                '扭蛋抽取使用貨幣',
                null,
                null,
                $userGachaOrder->id
            );

            if (!$currencyResult['success']) {
                DB::rollBack();
                return ['success' => 0, 'error_code' => $currencyResult['error_code']];
            }

            // 抓保底次數，使用 `firstOrCreate()`
            $userGachaTime = UserGachaTimes::firstOrCreate(
                ['user_id' => $user->id, 'gacha_id' => $gacha->id],
                ['uid' => $user->uid, 'times' => 0]
            );


            // // 批量查詢玩家已有的道具
            // $userItemList = UserItems::where('user_id', $user->id)
            //     ->whereIn('item_id', function ($query) use ($gacha) {
            //         $query->select('item_id')->from('gacha_details')->where('gacha_id', $gacha->id);
            //     })
            //     ->pluck('id', 'item_id')
            //     ->toArray(); // 轉成關聯陣列，提升效能

            // 查詢使用者已擁有的道具
            $userItemList = UserItems::where('user_id', $user->id)
                ->whereIn('item_id', function ($query) use ($gacha) {
                    $query->select('item_id')->from('gacha_details')->where('gacha_id', $gacha->id);
                })
                ->get()
                ->keyBy('item_id');
            $get_items = [];
            $userGachaDetails = [];


            for ($i = 1; $i <= $times; $i++) {
                $is_guaranteed = $userGachaTime->times >= $gacha->max_times;

                $gachaDetail = self::drawGacha($gacha, $is_guaranteed);
                if (!$gachaDetail) {
                    DB::rollBack();
                    return ['success' => 0, 'error_code' => 'GachaOrder:0005'];
                }

                // 更新保底次數
                if ($gachaDetail->guaranteed) {
                    $userGachaTime->update(['times' => 0]);
                } else {
                    $userGachaTime->increment('times');
                }

                // 取得玩家是否已擁有該 item
                $ownedItem = $userItemList[$gachaDetail->item_id] ?? null;
                $alreadyOwned = !is_null($ownedItem);
                $isAvatar = $ownedItem && $ownedItem->region === UserItems::REGION_AVATAR;
                $isSpecialItem = in_array($gachaDetail->item_id, ['101', '102']);  // 101, 102是遊戲票券
                $qty = $isSpecialItem ? $gachaDetail->qty : 1;

                if ($alreadyOwned && $isAvatar) {
                    // 抽到已擁有的 Avatar，轉換票券
                    $ticket_currency_item_id = 102;
                    $ticket_qty = $ticket_mapping[$gachaDetail->itemDetail->rarity] ?? 1;

                    $get_items[] = [
                        'item_id' => $gachaDetail->item_id,
                        'qty' => $qty,
                        'is_change' => $isSpecialItem ? 0 : 1,
                        'ticket_currency_item_id' => $ticket_currency_item_id,
                        'ticket_qty' => $isSpecialItem ? 0 : $ticket_qty,
                    ];

                    $userGachaDetails[] = [
                        'user_gacha_order_id' => $userGachaOrder->id,
                        'item_id' => $gachaDetail->item_id,
                        'is_change' => $isSpecialItem ? 1 : 0,
                        'change_item_id' => $ticket_currency_item_id,
                        'change_qty' => $isSpecialItem ? 0 : $ticket_qty,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];

                } else {
                    // 抽到新道具 or 非 avatar 類型道具，正常給
                    $get_items[] = [
                        'item_id' => $gachaDetail->item_id,
                        'qty' => $qty,
                        'is_change' => 0,
                        'ticket_currency_item_id' => 0,
                        'ticket_qty' => 0,
                    ];

                    $userGachaDetails[] = [
                        'user_gacha_order_id' => $userGachaOrder->id,
                        'item_id' => $gachaDetail->item_id,
                        'is_change' => 0,
                        'change_item_id' => null,
                        'change_qty' => null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];

                    // 若玩家未擁有該 item，加入已擁有清單，避免重複
                    if (!$alreadyOwned) {
                        $userItemList[$gachaDetail->item_id] = new UserItems([
                            'item_id' => $gachaDetail->item_id,
                            'region' => $gachaDetail->itemDetail->region ?? null,
                        ]);
                    }

                }
            }

            // 批量插入 `user_gacha_order_details`
            if ($userGachaDetails) {
                UserGachaOrderDetails::insert($userGachaDetails);
            }

            // 批量處理 `UserItemService::addItem`
            foreach ($get_items as $item) {
                $get_item_id  = $item['is_change'] ? $item['ticket_currency_item_id'] : $item['item_id'];
                $get_item_qty = $item['is_change'] ? $item['ticket_qty'] : $item['qty'];

                // 如果已存在就累加 ()
                if (isset($addItems[$get_item_id])) {
                    $addItems[$get_item_id]['qty'] += $get_item_qty;
                } else {
                    $addItems[$get_item_id] = [
                        'type'                => UserItemLogs::TYPE_GACHA,
                        'user_id'             => $user->id,
                        'uid'                 => $user->uid,
                        'item_id'             => $get_item_id,
                        'qty'                 => $get_item_qty,
                        'is_lock'             => 1,
                        'memo'                => '扭蛋抽取',
                        'user_mall_order_id'  => null,
                        'user_pay_order_id'   => null,
                        'user_gacha_order_id' => $userGachaOrder->id,
                    ];
                }
            }

            $result = UserItemService::addItems($addItems);
            if (!$result['success']) {
                DB::rollBack();
                return ['success' => 0, 'error_code' => $result['error_code']];
            }

            DB::commit();
            return ['success' => 1, 'get_items' => $get_items];

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('扭蛋抽取失敗', [
                'message' => $e->getMessage(),
                'user_id' => $user->id,
                'gacha_id' => $gacha->id,
                'times' => $times,
                'price' => $price,
            ]);
            return ['success' => 0, 'error_code' => 'other'];
        }
    }

    protected static function drawGacha($gacha, $is_guaranteed)
    {
        $details = $gacha->gachaDetails; // 取得所有獎勵
        $totalWeight = 0;
        $weightedPool = [];

        //計算總權重並建立加權陣列
        foreach ($details as $detail) {
            if(!$is_guaranteed || ($is_guaranteed && $detail->guaranteed)){
                $totalWeight += round($detail->percent * 100); // 轉換為整數
                $weightedPool[] = ['item' => $detail, 'weight' => $totalWeight]; // 累積機率範圍
            }
        }

        if($totalWeight<=0){
            return null;
        }

        //產生隨機數 (1 ~ 總機率範圍)
        $randomNumber = mt_rand(1, $totalWeight);

        //根據隨機數選擇對應的獎勵
        foreach ($weightedPool as $entry) {
            if ($randomNumber <= $entry['weight']) {
                return $entry['item']; // 抽到該獎勵
            }
        }

        return null; // 預防意外錯誤
    }

}
