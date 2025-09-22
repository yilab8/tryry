<?php
namespace App\Http\Controllers\Api;

use App\Models\Users;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\Models\NewebpayOrder;
use App\Models\NewebpayPayment;
use App\Http\Controllers\Controller;

class NewEbpayController extends Controller
{
    protected $key;
    protected $iv;

    public function __construct(Request $request)
    {
        $this->key = config('services.newebpay.HASH_KEY');
        $this->iv  = config('services.newebpay.HASH_IV');

        $origin         = $request->header('Origin');
        $referer        = $request->header('Referer');
        $referrerDomain = parse_url($origin, PHP_URL_HOST) ?? parse_url($referer, PHP_URL_HOST);
        if ($referrerDomain != config('services.API_PASS_DOMAIN')) {
            $this->middleware('auth:api', ['except' => ['preparePurchase', 'notify', 'checkNewebPay']]);
        }

    }

    public function preparePurchase(Request $request)
    {
        $userUid   = $request->input('user_uid');
        $amount    = intval($request->input('price'));
        $productId = $request->input('product_id');
        $itemDesc  = $request->input('item_desc');

        // 查 user 資料
        $user = Users::where('uid', $userUid)->first();
        if (! $user) {
            return response()->json(['message' => '查無此使用者'], 404);
        }

        $merchantID = config('services.newebpay.MERCHANT_ID');
        $time       = time();
        $orderNo    = $this->generateOrderId(); // 自定義訂單編號

        // 建立本地訂單
        NewebpayOrder::create([
            'order_no'  => $orderNo,
            'user_id'   => $user->id,
            'amount'    => $amount,
            'item_desc' => $itemDesc,
            'email'     => $user->email,
            'status'    => 'pending',
            'paid_at'   => null,
        ]);

        // 準備給藍新的資料
        $tradeData = [
            'MerchantID'      => $merchantID,
            'RespondType'     => 'JSON',
            'TimeStamp'       => $time,
            'Version'         => '1.6',
            'MerchantOrderNo' => $orderNo,
            'Amt'             => $amount,
            'ItemDesc'        => $itemDesc,
            'Email'           => $user->email,
            'ReturnURL'       => 'https://wow-dragon.com/checkPayment',
            'NotifyURL'       => route('newebpay.notify'),
            'ClientBackURL'   => 'https://wow-dragon.com/game-store',
        ];

        $queryString = http_build_query($tradeData);
        $tradeInfo   = $this->encrypt($queryString);
        $tradeSha    = strtoupper(hash('sha256', "HashKey={$this->key}&{$tradeInfo}&HashIV={$this->iv}"));

        // 回傳給前端用來 submit 到藍新
        return response()->json([
            'MerchantID' => $merchantID,
            'TradeInfo'  => $tradeInfo,
            'TradeSha'   => $tradeSha,
            'Version'    => '1.6',
            'PayGateWay' => 'https://ccore.newebpay.com/MPG/mpg_gateway',
        ]);
    }

    public function notify(Request $request)
    {
        $tradeInfo  = $request->input('TradeInfo');
        $tradeSha   = $request->input('TradeSha');
        $merchantID = env('NEWEBPAY_MERCHANT_ID');
        $hashKey    = $this->key;
        $hashIV     = $this->iv;

        // 驗證 TradeSha
        $localSha = strtoupper(hash('sha256', "HashKey={$hashKey}&{$tradeInfo}&HashIV={$hashIV}"));
        if ($localSha !== $tradeSha) {
            \Log::warning('TradeSha 驗證失敗', [
                'expected' => $localSha,
                'received' => $tradeSha,
            ]);
            return response('0|TradeSha Error', 400);
        }

        // 解密並 parse 資料
        $decrypt = $this->decrypt($tradeInfo);
        parse_str($decrypt, $data);

        // 找訂單
        $order = NewebpayOrder::where('order_no', $data['MerchantOrderNo'])->first();

        if (! $order) {
            \Log::warning('找不到訂單', ['order_no' => $data['MerchantOrderNo']]);
            return response('0|Order Not Found', 404);
        }

        if ($data['Status'] !== 'SUCCESS') {
            \Log::info('交易失敗通知', ['order_no' => $data['MerchantOrderNo'], 'data' => $data]);
            return response('0|Payment Failed', 400);
        }

        // 驗證金額是否正確
        if (intval($order->amount) !== intval($data['Amt'])) {
            \Log::error('金額不一致', [
                'order_no' => $order->order_no,
                'expected' => $order->amount,
                'received' => $data['Amt'],
            ]);
            return response('0|Amount Mismatch', 400);
        }

        // 若尚未付款才更新狀態，避免重複處理
        if ($order->status !== 'paid') {
            $order->update([
                'status'  => 'paid',
                'paid_at' => now(),
            ]);

            NewebpayPayment::create([
                'new_ebpay_order_id' => $order->id,
                'method'            => strtolower($data['PaymentType']),
                'amount'            => $data['Amt'],
                'status'            => 'success',
                'trade_no'          => $data['TradeNo'],
                'merchant_order_no' => $data['MerchantOrderNo'],
                'bank_code'         => $data['BankCode'] ?? null,
                'code_no'           => $data['CodeNo'] ?? null,
                'expire_date'       => $data['ExpireDate'] ?? null,
                'paid_at'           => now(),
                'raw_response'      => json_encode($data),
            ]);

            // 可在這裡加發獎勵邏輯
            // $this->giveRewardTo($order->user_id);
        }

        return response('1|OK');
    }

    // 檢查是否驗證成功
    public function checkNewebPay(Request $request)
    {
        $orderId = $request->input('order_id');

        if (! $orderId) {
            return response()->json(['success' => false, 'message' => '缺少訂單編號'], 400);
        }

        $order = Order::where('order_no', $orderId)->first();

        if (! $order) {
            return response()->json(['success' => false, 'message' => '查無此訂單'], 404);
        }

        if ($order->payment_status === 'paid') {
            return response()->json([
                'success' => true,
                'message' => '付款成功',
                'data'    => [
                    'reward' => $order->reward_data,
                ],
            ]);
        } else {
            return response()->json([
                'success' => false,
                'message' => '尚未付款完成',
            ]);
        }
    }

    // 加密 (AES-256-CBC + Zero Padding + HEX)
    public function encrypt($data)
    {
        $padded    = $this->addPadding($data);
        $encrypted = openssl_encrypt($padded, 'AES-256-CBC', $this->key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $this->iv);
        return bin2hex($encrypted);
    }

    // 解密 (HEX to RAW + remove padding)
    public function decrypt($data)
    {
        $raw       = hex2bin($data);
        $decrypted = openssl_decrypt($raw, 'AES-256-CBC', $this->key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $this->iv);
        return $this->stripPadding($decrypted);
    }

    // PKCS7 padding 補滿 block size
    private function addPadding($string, $blocksize = 32)
    {
        $pad = $blocksize - (strlen($string) % $blocksize);
        return $string . str_repeat(chr($pad), $pad);
    }

    private function stripPadding($string)
    {
        $len = ord(substr($string, -1));
        return substr($string, 0, -$len);
    }

    public function generateOrderId($prefix = 'WEB')
    {
        $datetime = now()->format('YmdHi');     // 202507221530
        $random   = strtoupper(Str::random(4)); // A1B2

        return $prefix . $datetime . $random;
    }
}
