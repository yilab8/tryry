<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserPayOrders;
use App\Models\Users;
use App\Service\ErrorService;
use App\Service\PaymentService;
use App\Service\TaskService;
use App\Service\UserStatsService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class UserPayOrderController extends Controller
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

    /**
     * 先建立 pending 訂單
     */
    public function createOrder(Request $request)
    {
        // 取得當前使用者id
        $user = Users::find(auth()->guard('api')->user()->id);
        if (empty($user)) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'AUTH:0006'), 422);
        }

        // 驗證資料是否正確
        $validated = $request->validate([
            'payment_method' => 'required|string|in:google,apple',
            'amount'         => 'required|numeric|regex:/^\d+(\.\d{1,2})?$/',
            'package_id'     => 'required|string',
        ]);

        // 如果是apple 是 receipt, 如果是google 是 purchase_token
        if ($validated['payment_method'] === 'apple') {
            $data         = PaymentService::verifyPurchase('apple', ['receipt_data' => $request->purchase_token]);
            if (!isset($data['receipt'])) {
                return response()->json(ErrorService::errorCode(__METHOD__, 'UserPayOrder:0003'), 400);
            }
            
            $purchaseData = $data['receipt']['in_app'][0]['transaction_id'];
        } else {
            $data         = PaymentService::verifyPurchase('google', ['purchase_token' => $request->purchase_token, 'product_id' => $validated['package_id']]);
            if (!isset($data['orderId'])) {
                return response()->json(ErrorService::errorCode(__METHOD__, 'UserPayOrder:0003'), 400);
            }

            $purchaseData = $data['orderId'];    
        }

        // 檢查訂單是否存在
        $orderExist = UserPayOrders::where('transaction_id', $purchaseData)->first();
        if ($orderExist) {
            return response()->json([
                'message'  => 'Order has been created',
                'order_id' => $orderExist->order_id,
            ], 200);
        }

        // 建立訂單
        $order = UserPayOrders::create([
            'user_id'        => $user->id,
            'uid'            => $user->uid,
            'order_id'       => Str::uuid(),
            'amount'         => $validated['amount'],
            'currency'       => $request->currency ?? 'TWD',
            'status'         => 'pending',
            'payment_method' => $validated['payment_method'],
            'package_id'     => $validated['package_id'],
        ]);

        return response()->json([
            'message'  => 'Order created',
            'order_id' => $order->order_id,
        ], 200);
    }

    /**
     * 驗證付款
     */
    public function verifyPurchase(Request $request, PaymentService $paymentService)
    {

        // 取得使用者
        $user = Users::find(auth()->guard('api')->user()->id);
        if (empty($user)) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'AUTH:0006'), 422);
        }

        $validated = $request->validate([
            'order_id'   => 'required|string|exists:user_pay_orders,order_id',
            'product_id' => 'required|string',
        ]);

        $order = UserPayOrders::where('order_id', $validated['order_id'])->first();

        if ($order->status !== 'pending') {
            if ($order->status === 'success') {
                return response()->json(['message' => '購買成功!'], 200);
            } else {
                return response()->json(ErrorService::errorCode(__METHOD__, 'UserPayOrder:0001'), 400);
            }
        }

        if (empty($request->purchase_token)) {
            return response()->json(ErrorService::errorCode(__METHOD__, 'UserPayOrder:0004'), 400);
        }
        $purchaseData = $request->purchase_token;

        $result = $paymentService->verifyPayment($order, $purchaseData, $validated['product_id']);

        //============ 任務系統 ============
        // 任務Service
        $taskService = new TaskService();
        // 紀錄系統任務
        $userStatsService = new UserStatsService($taskService);
        $userStatsService->updateByKeyword($user, 'recharge');
        // 玩家任務
        // $taskStatsService = new UserStatsService($taskService, $taskService->keywords(), [$taskService, 'calculateStat']);
        // $taskStatsService->updateByKeyword($user, 'newbie');
        // 本次登入是否有完成任務
        $completedTask       = $taskService->getCompletedTasks($user->uid);
        $formattedTaskResult = $taskService->formatCompletedTasks($completedTask);
        //============ 任務系統 ============

        \Log::info('Controller驗證結果', ['result' => $result]);
        if ($result['status'] === 'success') {
            \Log::info('Controller購買成功!', ['order_id' => $order->order_id]);
            return response()->json(['message' => '購買成功!'], 200);
        } else {
            \Log::info('Controller購買失敗!', ['result' => $result, 'order_id' => $order->order_id]);
            return response()->json(ErrorService::errorCode(__METHOD__, 'UserPayOrder:0003'), 400);
        }
    }
}
