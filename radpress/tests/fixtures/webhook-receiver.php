<?php
declare(strict_types=1);

// Synthetic receiver contract; no HTTP endpoint and no external side effects.
return static function (string $body, string $timestamp, string $signature, string $idempotencyKey, string $secret, string $store, int $now): bool {
    if (strlen($body) > 65536 || strlen($secret) < 32 || preg_match('/^[0-9]{10}$/D', $timestamp) !== 1 || abs($now - (int)$timestamp) > 300) throw new RuntimeException('Invalid delivery envelope.');
    if (!hash_equals('sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret), $signature)) throw new RuntimeException('Invalid delivery signature.');
    $event = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
    if (($event['event'] ?? '') !== 'form.submitted' || preg_match('/^[a-zA-Z0-9_-]{1,128}$/D', (string)($event['id'] ?? '')) !== 1 || $event['id'] !== $idempotencyKey || !is_array($event['data']['fields'] ?? null)) throw new RuntimeException('Invalid delivery event.');
    $accepted = false;
    (new \Batoi\Press\Core\FileStore())->mutateJson($store, static function (array $state) use ($event, $body, &$accepted): array {
        $id = $event['id']; $digest = hash('sha256', $body);
        if (isset($state[$id])) {
            if (!hash_equals($state[$id]['digest'], $digest)) throw new RuntimeException('Delivery identity conflicts.');
            return $state;
        }
        if (count($state) >= 1000) throw new RuntimeException('Receiver capacity exceeded.');
        // Store the mapped synthetic operation and receipt atomically.
        $state[$id] = ['digest'=>$digest, 'operation'=>['form_id'=>(string)($event['data']['form_id'] ?? ''), 'field_names'=>array_keys($event['data']['fields'])]];
        $accepted = true;
        return $state;
    });
    return $accepted;
};
