<?php
namespace App\Service;

use App\Models\ItemPrices;
use App\Models\UserPayOrders;
use App\Models\Users;
use Google\Client;
use Illuminate\Support\Facades\Http;

class PaymentService
{

    /**
     * 驗證付款（Google 或 Apple）
     */
    public function verifyPayment(UserPayOrders $order, $purchaseToken, $productId)
    {
        if ($order->payment_method === 'google') {
            return $this->verifyGooglePlay($order, $purchaseToken, $productId);
        } elseif ($order->payment_method === 'apple') {
            return $this->verifyApplePay($order, $purchaseToken, $productId);
        }
        return ['status' => 'failed', 'error' => '當前僅接受 Google 或 Apple 付款'];
    }
    /**
     * Apple 付款驗證
     */
    private function verifyApplePay(UserPayOrders $order, string $encryptedReceipt, string $productId)
    {
        \Log::debug('ApplePay 驗證開始', [
            'order_id'     => $order->order_id,
            'product_id'   => $productId,
            'receipt_data' => substr($encryptedReceipt, 0, 30) . '...',
        ]);

        if (empty($productId)) {
            \Log::warning('缺少商品 ID', ['order_id' => $order->order_id]);
            return ['status' => 'failed', 'error' => '缺少商品 ID'];
        }

        if (empty($encryptedReceipt)) {
            \Log::warning('缺少收據資料', ['order_id' => $order->order_id]);
            return ['status' => 'failed', 'error' => '缺少收據資料'];
        }

        $data = self::verifyPurchase('apple', ['receipt_data' => $encryptedReceipt], $order);
        if (isset($data['status']) && $data['status'] === 'failed') {
            return ['status' => 'failed', 'error' => $data['error']];
        }

        \Log::debug('Apple 回傳資料', ['order_id' => $order->order_id, 'data' => $data]);
        
        if (empty($data) || $data['status'] != 0) {
            \Log::warning('Apple 收據狀態異常', [
                'order_id'  => $order->order_id,
                'status'    => $data['status'] ?? '未提供',
                'full_data' => $data,
            ]);
            return ['status' => 'failed', 'error' => '訂單未付款或狀態異常'];
        }

        $latestReceipt = end($data['receipt']['in_app']);
        \Log::debug('取出的 Apple 收據', ['receipt' => $latestReceipt]);

        if ($latestReceipt['product_id'] !== $productId) {
            \Log::warning('商品 ID 不一致', [
                'order_id' => $order->order_id,
                'expected' => $productId,
                'actual'   => $latestReceipt['product_id'],
            ]);
            return ['status' => 'failed', 'error' => '訂單商品 ID 不一致'];
        }

        $order->update([
            'status'         => 'success',
            'transaction_id' => $latestReceipt['transaction_id'] ?? null,
            'purchase_token' => $encryptedReceipt,
            'purchase_time'  => isset($latestReceipt['purchase_date_ms']) ? now()->createFromTimestampMs($latestReceipt['purchase_date_ms']) : null,
            'raw_response'   => json_encode($data),
        ]);

        $user = $order->user;
        \Log::debug('準備發送道具', ['uid' => $user->uid, 'product_id' => $productId]);

        $result = $this->sendItem($user, $productId);

        if ($result['status'] !== 'success') {
            \Log::error('發送道具失敗', [
                'order_id' => $order->order_id,
                'result'   => $result,
            ]);
            return $result;
        }

        \Log::info('ApplePay 處理完成', ['order_id' => $order->order_id]);
        return ['status' => 'success'];
    }

    /**
     * Google Play 付款驗證
     */
    private function verifyGooglePlay(UserPayOrders $order, string $purchaseToken, string $productId)
    {
        if (empty($productId)) {
            return ['status' => 'failed', 'error' => '缺少商品 ID'];
        }

        if (empty($purchaseToken)) {
            return ['status' => 'failed', 'error' => '缺少交易憑證'];
        }

        // 驗證訂單資訊一致
        $validation = $this->validateGooglePurchaseData($order, $productId);
        if ($validation['status'] !== 'ok') {
            return $validation;
        }

        $data = self::verifyPurchase('google', [
            'purchase_token' => $purchaseToken,
            'product_id'     => $productId,
        ], $order);
        
        if (isset($data['status']) && $data['status'] === 'failed') {
            return ['status' => 'failed', 'error' => $data['error']];
        }   

        if (empty($data)) {
            return ['status' => 'failed', 'error' => 'Google 回傳資料異常'];
        }

        if (! isset($data['purchaseState']) || $data['purchaseState'] != 0) {
            \Log::warning('Google 訂單未付款或狀態異常', [
                'order_id'      => $order->order_id,
                'purchaseState' => $data['purchaseState'] ?? '未提供',
                'full_data'     => $data,
            ]);
            return ['status' => 'failed', 'error' => '訂單未付款'];
        }

        // 取得產品
        $product = $this->getProduct($productId);

        // 更新訂單資料
        $order->update([
            'status'         => 'success',
            'transaction_id' => $data['orderId'] ?? null,
            'purchase_token' => $purchaseToken,
            'purchase_time'  => isset($data['purchaseTimeMillis']) ? now()->createFromTimestampMs($data['purchaseTimeMillis']) : null,
            'raw_response'   => json_encode($data),
        ]);

        // 確認 acknowledge 狀態
        if (empty($data['acknowledgementState']) || $data['acknowledgementState'] == 0) {
            $ackUrl      = "{$url}:acknowledge";
            $ackResponse = Http::withToken($accessToken)->post($ackUrl, []);
            if ($ackResponse->failed()) {
                \Log::warning('Google acknowledge 失敗', [
                    'order_id' => $order->order_id,
                    'response' => $ackResponse->body(),
                ]);
            } else {
                $order->update(['acknowledged_at' => now()]);
            }
        }

        // 發送道具
        $user   = $order->user;
        $result = $this->sendItem($user, $productId);
        if ($result['status'] !== 'success') {
            return $result;
        }

        return ['status' => 'success'];
    }

    public static function verifyPurchase(string $platform, array $options, UserPayOrders $order = null)
    {
        if ($platform === 'apple') {
            $url     = 'https://buy.itunes.apple.com/verifyReceipt';
            $payload = [
                'receipt-data'             => $options['receipt_data'],
                'password'                 => config('services.APPLE.SHARED_SECRET'),
                'exclude-old-transactions' => true,
            ];

            \Log::debug('發送 Apple 驗證請求', ['url' => $url]);

            $response = \Http::post($url, $payload);
            $body     = $response->body();

            if ($response->failed() || strpos($body, '"status":21002') !== false) {
                if ($order) {
                    \Log::error('Apple API 驗證失敗', [
                        'url'      => $url,
                        'order_id' => $order->order_id ?? 'null',
                        'response' => $body,
                        'uid'      => $order->uid ?? 'null',
                    ]);
                    $order->update([
                        'error_info' => json_encode([
                            'url'          => $url,
                            'order_id'     => $order->order_id ?? 'null',
                            'response'     => $body,
                            'uid'          => $order->uid ?? 'null',
                            'receipt_data' => substr($options['receipt_data'], 0, 30) . '...',
                        ]),
                    ]);
                } else {
                    \Log::error('Apple API 驗證失敗', [
                        'url'      => $url,
                        'response' => $body,
                    ]);
                }

                return ['status' => 'failed', 'error' => 'apple_verification_failed'];
            }

            $data = $response->json();

            // fallback to sandbox if needed
            if (($data['status'] ?? null) === 21007) {
                if ($order) {
                    \Log::debug('轉向 Apple Sandbox 驗證', ['order_id' => $order->order_id]);
                    $sandbox = \Http::post('https://sandbox.itunes.apple.com/verifyReceipt', $payload);
                    $data    = $sandbox->json();

                    \Log::debug('Apple Sandbox 回傳資料', ['order_id' => $order->order_id, 'data' => $data]);
                }else{
                    \Log::debug('轉向 Apple Sandbox 驗證');
                    $sandbox = \Http::post('https://sandbox.itunes.apple.com/verifyReceipt', $payload);
                    $data    = $sandbox->json();
                }
                \Log::debug('Apple Sandbox 回傳資料', ['data' => $data]);
            }

            if (empty($data) || ! isset($data['status']) || $data['status'] !== 0) {
                \Log::warning('Apple 收據狀態異常', [
                    'order_id'  => $order->order_id ?? 'null',
                    'status'    => $data['status'] ?? '無',
                    'full_data' => $data,
                ]);

                return ['status' => 'failed', 'error' => 'apple_receipt_invalid'];
            }

            return $data;
        }

        if ($platform === 'google') {
            $packageName   = config('services.GOOGLE_PLAY.PACKAGE_NAME');
            $productId     = $options['product_id'];
            $purchaseToken = $options['purchase_token'];
            $url           = "https://androidpublisher.googleapis.com/androidpublisher/v3/applications/{$packageName}/purchases/products/{$productId}/tokens/{$purchaseToken}";

            $accessToken = static::getGooglePlayToken();

            \Log::debug('發送 Google Play 驗證請求', ['url' => $url]);

            $response = \Http::withToken($accessToken)->get($url);
            $body     = $response->body();

            if ($response->failed()) {
                if ($order) {
                    \Log::error('Google Play API 驗證失敗', [
                        'url'      => $url,
                        'order_id' => $order->order_id ?? 'null',
                        'response' => $body,
                        'uid'      => $order->uid ?? 'null',
                    ]);

                    $order->update([
                        'error_info' => json_encode([
                            'url'            => $url,
                            'order_id'       => $order->order_id ?? 'null',
                            'response'       => $body,
                            'uid'            => $order->uid ?? 'null',
                            'purchase_token' => $purchaseToken,
                        ]),
                    ]);
                } else {
                    \Log::error('Google Play API 驗證失敗', [
                        'url'      => $url,
                        'response' => $body,
                    ]);
                }

                return ['status' => 'failed', 'error' => 'google_verification_failed'];
            }

            $data = $response->json();
            if (empty($data)) {
                return ['status' => 'failed', 'error' => 'google_response_empty'];
            }

            return $data;
        }

        return ['status' => 'failed', 'error' => 'unsupported_platform'];
    }

    /**
     * 取得google token
     */
    protected static function getGooglePlayToken()
    {
        $path = storage_path(config('services.GOOGLE_PLAY.SERVICE_ACCOUNT'));
        if (! file_exists($path)) {
            throw new \Exception("找不到服務帳戶金鑰: {$path}");
        }

        $client = new Client();
        $client->setAuthConfig($path);
        $client->addScope('https://www.googleapis.com/auth/androidpublisher');
        $tokenResponse = $client->fetchAccessTokenWithAssertion();
        if (isset($tokenResponse['access_token'])) {
            return $tokenResponse['access_token'];
        }
        throw new \Exception("Google Token 無法取得：" . json_encode($tokenResponse));
    }

    /**
     * 驗證 訂單商品 ID 是否一致
     */
    private function validateGooglePurchaseData(UserPayOrders $order, string $productId): array
    {
        if ($order->package_id !== $productId) {
            return ['status' => 'failed', 'error' => '訂單商品 ID 不一致'];
        }

        return ['status' => 'ok'];
    }

    /** 取得產品 */
    private function getProduct(string $productId)
    {
        // $productId 如果有非數字只保留數字
        $productId = preg_replace('/^(gp\d+).*/', '$1', $productId);

        $data = ItemPrices::where('tag', 'Cash')->where('product_id', $productId)->first();
        if (empty($data)) {
            \Log::warning('商品不存在', ['product_id' => $productId]);
            return ['status' => 'failed', 'error' => '商品不存在', 'product_id' => $productId];
        }

        return [
            'product_id' => $data->product_id,
            'item_id'    => $data->item_id,
            'qty'        => $data->qty,
        ];
    }

    /**
     * 發送儲值道具
     */
    protected function sendItem(Users $user, $packageId)
    {
        $getProduct = $this->getProduct($packageId);
        if (isset($getProduct['status']) && $getProduct['status'] == 'failed') {
            \Log::warning('取得商品失敗', [
                'uid'     => $user->uid,
                'item_id' => $getProduct['product_id'],
                'qty'     => $getProduct['qty'] ?? 0,
            ]);
        }
        $product = $getProduct;
        // type = 12 是儲值, is_lock = 1 為綁定道具
        $item = UserItemService::addItem(12, $user->id, $user->uid, $product['item_id'], $product['qty'], 1, '儲值購買');

        if (empty($item['success'])) {
            return ['status' => 'failed', 'error' => $item['error_code']];
        }

        return [
            'status' => 'success',
        ];
    }

    /** Google 查訂單，並檢查是否 acknowledge */
    protected function checkGoogleOrder(UserPayOrders $order)
    {
        $productId     = $order->product_id;
        $purchaseToken = $order->purchase_token;
        $packageName   = config('services.GOOGLE_PLAY.PACKAGE_NAME');

        $url = "https://androidpublisher.googleapis.com/androidpublisher/v3/applications/{$packageName}/purchases/products/{$productId}/tokens/{$purchaseToken}";

        $accessToken = static::getGooglePlayToken();
        $response    = Http::withToken($accessToken)->get($url);

        if ($response->failed()) {
            return [
                'status'    => 'failed',
                'error'     => 'Google 驗證失敗',
                'http_code' => $response->status(),
                'body'      => $response->body(),
            ];
        }

        $data = $response->json();

        return [
            'full_data'            => $data,
            'status'               => 'success',
            'orderId'              => $data['orderId'] ?? null,
            'purchaseState'        => $data['purchaseState'] ?? null,
            'acknowledgementState' => $data['acknowledgementState'] ?? null,
            'consumptionState'     => $data['consumptionState'] ?? null,
            'purchaseTimeMillis'   => $data['purchaseTimeMillis'] ?? null,
        ];
    }
}
