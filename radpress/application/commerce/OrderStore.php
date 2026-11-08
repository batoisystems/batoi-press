<?php
declare(strict_types=1);

namespace Batoi\Press\Commerce;

use Batoi\Press\Application\ContentRevision;
use Batoi\Press\Content\ContentTransaction;
use Batoi\Press\Core\{FileStore, Money, Paths};
use Batoi\Press\Security\SecretStore;
use RuntimeException;

/** Lock order is product storage then commerce. Remote I/O never occurs here. */
final class OrderStore
{
    public function __construct(private readonly Paths $paths, private readonly FileStore $files = new FileStore(), private readonly ?\Closure $checkpoint = null) {}

    public function reserve(array $cart, array $customer, string $access, string $key, array $settings, ?int $now = null): array
    {
        $now ??= time();
        if (!preg_match('/^[a-f0-9]{64}$/D', $access) || !preg_match('/^[a-f0-9]{32}$/D', $key) || count($cart) < 1 || count($cart) > 20) throw new RuntimeException('Invalid checkout request.');
        ksort($cart);
        $currency = (string)$settings['currency'];
        Money::exponent($currency);
        $fingerprint = ContentRevision::for(['cart'=>$cart,'customer'=>$customer,'currency'=>$currency,'mode'=>$settings['mode'] ?? 'test','shipping_minor'=>$settings['shipping_minor'],'tax_bps'=>$settings['tax_bps']]);
        $id = 'ord_' . substr(hash('sha256', $access . ':' . $key), 0, 32);
        return $this->transaction(function (CommerceJournal $journal) use ($cart, $customer, $access, $settings, $currency, $fingerprint, $id, $now): array {
            $existing = $journal->read($this->orderPath($id));
            if ($existing !== []) {
                if (!hash_equals((string)$existing['fingerprint'], $fingerprint)) throw new RuntimeException('This checkout request was already used with different details. Return to the cart to start again.');
                return $existing;
            }
            if (count(glob($this->paths->dataPath('commerce/orders/*.json')) ?: []) >= 10000) throw new RuntimeException('Store order capacity requires operator review.');
            $this->expireLocked($journal, $now);
            $products = $this->products($journal);
            $items = []; $writes = []; $subtotal = 0;
            foreach ($cart as $slug => $quantity) {
                if (!is_string($slug) || !is_int($quantity) || $quantity < 1 || $quantity > 999) throw new RuntimeException('Invalid cart quantity.');
                $product = $products[$slug] ?? null;
                if ($product === null || ($product['status'] ?? '') !== 'published') throw new RuntimeException('A cart product is no longer available.');
                if (($product['currency'] ?? '') !== $currency) throw new RuntimeException('Cart currency does not match the store currency.');
                if ((int)($product['inventory'] ?? 0) < $quantity) throw new RuntimeException('Insufficient stock for ' . (string)$product['title'] . '.');
                $minor = Money::parse((string)$product['price'], $currency);
                if (isset($product['price_minor']) && $product['price_minor'] !== $minor) throw new RuntimeException('Product price requires owner review.');
                $subtotal = Money::add($subtotal, Money::multiply($minor, $quantity));
                $items[] = ['product_id'=>$product['id'], 'slug'=>$slug, 'title'=>$product['title'], 'sku'=>$product['sku'] ?? '', 'quantity'=>$quantity, 'unit_minor'=>$minor];
                $product['inventory'] -= $quantity;
                $product['updated_at'] = gmdate(DATE_ATOM, $now);
                $writes['products/' . $slug . '/meta.json'] = $product;
            }
            $shipping = ($customer['delivery'] ?? '') === 'pickup' ? 0 : (int)$settings['shipping_minor'];
            $beforeTax = Money::add($subtotal, $shipping);
            $rate = (int)$settings['tax_bps'];
            if ($rate < 0 || $rate > 10000) throw new RuntimeException('Invalid store tax rate.');
            $tax = intdiv($beforeTax * $rate + 5000, 10000);
            $total = Money::add($beforeTax, $tax);
            if ($total < 1) throw new RuntimeException('Online checkout requires a positive total.');
            $order = ['schema'=>1,'mode'=>$settings['mode'] ?? 'test','id'=>$id,'fingerprint'=>$fingerprint,'access_hash'=>hash('sha256', $access),'currency'=>$currency,'items'=>$items,'subtotal_minor'=>$subtotal,'shipping_minor'=>$shipping,'tax_minor'=>$tax,'total_minor'=>$total,'tax_bps'=>$rate,'customer'=>(new SecretStore($this->paths))->encrypt(json_encode($customer, JSON_THROW_ON_ERROR)),'created_at'=>$now,'updated_at'=>$now,'hold_until'=>$now + 2100,'allocation'=>'held','payment_status'=>'pending','fulfilment_status'=>'unfulfilled','stripe_session'=>'','payment_intent'=>'','refunded_minor'=>0];
            $writes[$this->orderPath($id)] = $order;
            $writes['commerce/state.json'] = ['schema'=>1,'ever_ordered'=>true];
            $journal->commit($writes, $this->checkpoint);
            return $order;
        });
    }

    public function get(string $id): ?array
    {
        $order = $this->transaction(fn(CommerceJournal $j): array => $j->read($this->orderPath($id)));
        return $order === [] ? null : $order;
    }

    public function all(): array
    {
        return $this->transaction(function (CommerceJournal $j): array {
            $orders = [];
            foreach (array_slice(glob($this->paths->dataPath('commerce/orders/ord_*.json')) ?: [], 0, 10000) as $path) $orders[] = $j->read($this->orderPath(basename($path, '.json')));
            usort($orders, static fn(array $a, array $b): int => $b['created_at'] <=> $a['created_at']);
            return $orders;
        });
    }

    public function summaries(): array
    {
        return $this->transaction(function (CommerceJournal $j): array {
            $orders = [];
            foreach (array_slice(glob($this->paths->dataPath('commerce/orders/ord_*.json')) ?: [], 0, 10000) as $path) {
                $order = $j->read($this->orderPath(basename($path, '.json')));
                $orders[] = array_intersect_key($order, array_flip(['id','created_at','total_minor','currency','payment_status','fulfilment_status','mode']));
            }
            usort($orders, static fn(array $a, array $b): int => $b['created_at'] <=> $a['created_at']);
            return $orders;
        });
    }

    public function attach(string $id, array $session): array
    {
        return $this->transaction(function (CommerceJournal $j) use ($id, $session): array {
            $order = $j->read($this->orderPath($id));
            $this->checkSession($order, $session);
            if ($order['stripe_session'] !== '' && $order['stripe_session'] !== $session['id']) throw new RuntimeException('Order already has a different payment session.');
            $order['stripe_session'] = $session['id'];
            $order['checkout_url'] = (string)($session['url'] ?? '');
            $j->commit([$this->orderPath($id)=>$order]);
            return $order;
        });
    }

    public function applySession(string $id, array $session, string $eventId, ?int $now = null): array
    {
        $now ??= time();
        if (strlen($eventId) > 200 || $eventId === '') throw new RuntimeException('Invalid payment event identity.');
        return $this->transaction(function (CommerceJournal $j) use ($id, $session, $eventId, $now): array {
            $this->expireLocked($j, $now);
            $order = $j->read($this->orderPath($id));
            $this->checkSession($order, $session);
            $eventPath = 'commerce/events/' . hash('sha256', $eventId) . '.json';
            $seen = $j->read($eventPath);
            if ($seen !== []) {
                if (($seen['order'] ?? '') !== $id) throw new RuntimeException('Payment event was already used for another order.');
                return $order;
            }
            $writes = [];
            $paid = ($session['payment_status'] ?? '') === 'paid';
            if ($paid && !in_array($order['payment_status'], ['paid','partially_refunded','refunded'], true)) {
                if ($order['allocation'] !== 'held') {
                    $products = $this->products($j);
                    $byId = []; foreach ($products as $slug => $product) $byId[$product['id']] = $slug;
                    $available = true;
                    foreach ($order['items'] as $item) {
                        $slug = $byId[$item['product_id']] ?? '';
                        if ($slug === '' || (int)$products[$slug]['inventory'] < $item['quantity']) { $available = false; break; }
                    }
                    if ($available) foreach ($order['items'] as $item) {
                        $slug = $byId[$item['product_id']];
                        $products[$slug]['inventory'] -= $item['quantity'];
                        $products[$slug]['updated_at'] = gmdate(DATE_ATOM, $now);
                        $writes['products/' . $slug . '/meta.json'] = $products[$slug];
                    }
                    $order['allocation'] = $available ? 'sold' : 'unavailable';
                } else $order['allocation'] = 'sold';
                $order['payment_status'] = 'paid';
                if ($order['allocation'] === 'unavailable') $order['fulfilment_status'] = 'needs_attention';
                $writes['commerce/outbox/' . $id . '.json'] = ['schema'=>1,'id'=>$id,'event'=>$order['allocation'] === 'sold' ? 'order.paid' : 'order.payment_exception','order'=>$id,'created_at'=>$now,'status'=>'pending'];
            }
            if (!$paid && ($session['status'] ?? '') === 'expired' && $order['allocation'] === 'held' && $order['payment_status'] === 'pending') {
                $writes = $this->release($j, $order, $now);
                $order['allocation'] = 'released'; $order['payment_status'] = 'expired';
            }
            $intent = $session['payment_intent'] ?? '';
            $intentId = is_array($intent) ? (string)($intent['id'] ?? '') : (string)$intent;
            if ($intentId !== '') $order['payment_intent'] = $intentId;
            if ($order['payment_intent'] !== '') $writes['commerce/payments/' . hash('sha256', $order['payment_intent']) . '.json'] = ['order'=>$id];
            $charge = is_array($intent) && is_array($intent['latest_charge'] ?? null) ? $intent['latest_charge'] : [];
            $refunded = $charge['amount_refunded'] ?? 0;
            if ($paid && is_int($refunded) && $refunded >= $order['refunded_minor'] && $refunded <= $order['total_minor'] && $refunded > 0) {
                $order['refunded_minor'] = $refunded;
                $order['payment_status'] = $refunded === $order['total_minor'] ? 'refunded' : 'partially_refunded';
            }
            $order['stripe_session'] = $session['id']; $order['updated_at'] = $now;
            $writes[$this->orderPath($id)] = $order;
            $writes[$eventPath] = ['order'=>$id,'processed_at'=>$now];
            $j->commit($writes, $this->checkpoint);
            return $order;
        });
    }

    public function fulfil(string $id, string $revision): array
    {
        return $this->transaction(function (CommerceJournal $j) use ($id, $revision): array {
            $order = $j->read($this->orderPath($id));
            if ($order === [] || !hash_equals(ContentRevision::for($order), $revision)) throw new RuntimeException('Order changed. Reload it before updating.');
            if ($order['payment_status'] !== 'paid' || $order['allocation'] !== 'sold' || $order['fulfilment_status'] !== 'unfulfilled') throw new RuntimeException('Only paid, allocated, unfulfilled orders can be fulfilled.');
            $order['fulfilment_status'] = 'fulfilled'; $order['updated_at'] = time();
            $j->commit([$this->orderPath($id)=>$order]);
            return $order;
        });
    }

    public function customer(array $order): array { return json_decode((new SecretStore($this->paths))->decrypt($order['customer']), true, 32, JSON_THROW_ON_ERROR); }
    public function siteId(): string { $config = \Batoi\Press\Core\Config::load($this->paths->root()); return hash('sha256', rtrim((string)($config->site()['base_url'] ?? ''), '/')); }

    private function checkSession(array $order, array $session): void
    {
        if ($order === [] || preg_match('/^cs_[A-Za-z0-9_]{8,200}$/D', (string)($session['id'] ?? '')) !== 1 || ($session['metadata']['press_order'] ?? '') !== $order['id'] || ($session['metadata']['press_site'] ?? '') !== $this->siteId() || ($session['currency'] ?? '') !== strtolower($order['currency']) || ($session['amount_total'] ?? null) !== $order['total_minor'] || ($session['livemode'] ?? null) !== (($order['mode'] ?? 'test') === 'live') || ($order['stripe_session'] !== '' && $order['stripe_session'] !== $session['id'])) throw new RuntimeException('Payment session does not match the order.');
    }

    private function expireLocked(CommerceJournal $j, int $now): void
    {
        $processed = 0;
        foreach (glob($this->paths->dataPath('commerce/orders/ord_*.json')) ?: [] as $path) {
            $order = $j->read($this->orderPath(basename($path, '.json')));
            if (($order['allocation'] ?? '') !== 'held' || ($order['hold_until'] ?? PHP_INT_MAX) > $now) continue;
            $writes = $this->release($j, $order, $now);
            $order['allocation'] = 'released'; $order['payment_status'] = 'expired'; $order['updated_at'] = $now;
            $writes[$this->orderPath($order['id'])] = $order;
            $j->commit($writes);
            if (++$processed >= 20) break;
        }
    }

    private function release(CommerceJournal $j, array $order, int $now): array
    {
        $products = $this->products($j); $byId = []; $writes = [];
        foreach ($products as $slug => $product) $byId[$product['id']] = $slug;
        foreach ($order['items'] as $item) {
            $slug = $byId[$item['product_id']] ?? '';
            if ($slug === '') throw new RuntimeException('A reserved product is missing; order recovery requires operator review.');
            $products[$slug]['inventory'] = Money::add((int)$products[$slug]['inventory'], $item['quantity']);
            if ($products[$slug]['inventory'] > 999999999) throw new RuntimeException('Stock return exceeds the catalogue limit; operator review required.');
            $products[$slug]['updated_at'] = gmdate(DATE_ATOM, $now);
            $writes['products/' . $slug . '/meta.json'] = $products[$slug];
        }
        return $writes;
    }

    private function products(CommerceJournal $j): array
    {
        $items = [];
        foreach (glob($this->paths->contentPath('products/*/meta.json')) ?: [] as $path) {
            $slug = basename(dirname($path));
            $product = $j->read('products/' . $slug . '/meta.json');
            if (($product['slug'] ?? '') === $slug && is_file(dirname($path) . '/body.html')) $items[$slug] = $product;
        }
        return $items;
    }
    private function transaction(callable $work): array { return (new ContentTransaction($this->paths, $this->files))->read('product', fn(): array => (new CommerceJournal($this->paths, $this->files))->locked($work)); }
    private function orderPath(string $id): string { if (!preg_match('/^ord_[a-f0-9]{32}$/D', $id)) throw new RuntimeException('Invalid order identifier.'); return 'commerce/orders/' . $id . '.json'; }
}
