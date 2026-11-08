<?php
declare(strict_types=1);

namespace Batoi\Press\Commerce;

use RuntimeException;

/** Fixed-origin, bounded Stripe adapter. Tests may inject transport, never requests. */
final class StripeCheckout
{
    public const API_VERSION = '2026-10-28.endive';
    public function __construct(private readonly string $key, private readonly ?\Closure $transport = null) {}

    public function create(array $order, array $customer, string $baseUrl, string $siteId): array
    {
        $lines = [];
        foreach ($order['items'] as $item) $lines[] = ['price_data'=>['currency'=>strtolower($order['currency']),'unit_amount'=>$item['unit_minor'],'product_data'=>['name'=>$item['title']]],'quantity'=>$item['quantity']];
        foreach (['shipping_minor'=>'Shipping','tax_minor'=>'Tax'] as $field => $label) if ($order[$field] > 0) $lines[] = ['price_data'=>['currency'=>strtolower($order['currency']),'unit_amount'=>$order[$field],'product_data'=>['name'=>$label]],'quantity'=>1];
        $metadata = ['press_order'=>$order['id'],'press_site'=>$siteId];
        return $this->request('POST', '/v1/checkout/sessions', ['mode'=>'payment','payment_method_types'=>['card'],'line_items'=>$lines,'customer_email'=>$customer['email'],'billing_address_collection'=>'required','client_reference_id'=>$order['id'],'metadata'=>$metadata,'payment_intent_data'=>['metadata'=>$metadata],'success_url'=>rtrim($baseUrl, '/') . '/commerce/order/' . $order['id'],'cancel_url'=>rtrim($baseUrl, '/') . '/commerce/cart','expires_at'=>$order['created_at'] + 1800], $order['id']);
    }
    public function retrieve(string $id): array
    {
        if (preg_match('/^cs_[A-Za-z0-9_]{8,200}$/D', $id) !== 1) throw new RuntimeException('Invalid Stripe session identifier.');
        return $this->request('GET', '/v1/checkout/sessions/' . $id, ['expand'=>['payment_intent.latest_charge']]);
    }
    public static function verifyEvent(string $body, string $header, string $secret, ?int $now = null): array
    {
        $now ??= time();
        if (strlen($body) > 262144 || strlen($header) > 4096 || strlen($secret) < 16) throw new RuntimeException('Invalid payment callback.');
        $timestamp = null; $signatures = [];
        foreach (explode(',', $header) as $part) {
            [$name, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($name === 't' && preg_match('/^[0-9]{10,12}$/D', $value)) $timestamp = (int)$value;
            if ($name === 'v1' && preg_match('/^[a-f0-9]{64}$/D', $value)) $signatures[] = $value;
        }
        if ($timestamp === null || abs($now - $timestamp) > 300) throw new RuntimeException('Payment callback timestamp is invalid.');
        $expected = hash_hmac('sha256', $timestamp . '.' . $body, $secret); $valid = false;
        foreach ($signatures as $signature) if (hash_equals($expected, $signature)) $valid = true;
        if (!$valid) throw new RuntimeException('Payment callback signature is invalid.');
        $event = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($event) || preg_match('/^evt_[A-Za-z0-9]{8,200}$/D', (string)($event['id'] ?? '')) !== 1 || !is_string($event['type'] ?? null)) throw new RuntimeException('Invalid payment event.');
        return $event;
    }
    private function request(string $method, string $path, array $data, string $idempotency = ''): array
    {
        if ($this->transport !== null) return ($this->transport)($method, $path, $data, $idempotency);
        if (!function_exists('curl_init')) throw new RuntimeException('Stripe transport is unavailable.');
        $query = http_build_query($data, '', '&', PHP_QUERY_RFC3986);
        $url = 'https://api.stripe.com' . $path . ($method === 'GET' ? '?' . $query : '');
        $handle = curl_init($url); $body = '';
        if ($handle === false) throw new RuntimeException('Unable to initialize payment transport.');
        $headers = ['Authorization: Bearer ' . $this->key,'Stripe-Version: ' . self::API_VERSION,'Content-Type: application/x-www-form-urlencoded'];
        if ($idempotency !== '') $headers[] = 'Idempotency-Key: press-' . $idempotency;
        try {
            $options = [CURLOPT_HTTPHEADER=>$headers,CURLOPT_PROXY=>'',CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>12,CURLOPT_WRITEFUNCTION=>static function ($curl, string $chunk) use (&$body): int { if (strlen($body) + strlen($chunk) > 262144) return 0; $body .= $chunk; return strlen($chunk); }];
            if ($method === 'POST') { $options[CURLOPT_POST] = true; $options[CURLOPT_POSTFIELDS] = $query; }
            if (!curl_setopt_array($handle, $options) || curl_exec($handle) === false || ($status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE)) < 200 || $status >= 300) throw new RuntimeException('Stripe is unavailable or rejected checkout. The order remains pending; retry the same checkout or reconcile it in Orders.');
            $result = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($result)) throw new RuntimeException('Invalid payment provider response.');
            return $result;
        } finally { curl_close($handle); }
    }
}
