<?php
declare(strict_types=1);

namespace Batoi\Press\Commerce;

use Batoi\Press\Content\ProductRepository;
use Batoi\Press\Core\{AuditLog, Config, FileStore, HtmlContent, Money, Request, Response, Theme};
use Batoi\Press\Security\{Csrf, RateLimiter, Session};
use RuntimeException;

final class StorefrontController
{
    private StoreSettings $settings;
    private OrderStore $orders;
    public function __construct(private readonly Config $config, private readonly Theme $theme)
    {
        $this->settings = new StoreSettings($config); $this->orders = new OrderStore($config->paths());
    }
    public function handle(Request $request): Response
    {
        if ($request->path === '/commerce/stripe/webhook') return $this->webhook($request);
        if (!$this->settings->read()['enabled']) return $this->page('Store unavailable', '<p>The store is currently unavailable.</p>', 404);
        $session = new Session('bp_shop_' . substr(hash('sha256', $this->config->paths()->root()), 0, 12), $this->config->paths()->dataPath('sessions'));
        $csrf = new Csrf($session);
        $access = $session->get('shop_access');
        if (!is_string($access) || !preg_match('/^[a-f0-9]{64}$/D', $access)) { $access = bin2hex(random_bytes(32)); $session->set('shop_access', $access); }
        if (preg_match('#^/commerce/order/(ord_[a-f0-9]{32})$#D', $request->path, $match)) {
            if ($request->method !== 'GET') return $this->page('Order', '<p>GET required.</p>', 405);
            $order = $this->orders->get($match[1]);
            if ($order === null || !hash_equals($order['access_hash'], hash('sha256', $access))) return $this->page('Order', '<p>Order not found in this browser session.</p>', 404);
            if (in_array($order['payment_status'], ['paid','partially_refunded','refunded'], true) && $session->get('shop_pending_order') === $order['id']) {
                $session->set('shop_cart', []); $session->remove('shop_pending_order'); $session->remove('shop_checkout_key');
            }
            $body = '<p>Order reference: <code>' . self::e($order['id']) . '</code></p><p><strong>Payment:</strong> ' . self::e(str_replace('_', ' ', $order['payment_status'])) . '</p><p><strong>Fulfilment:</strong> ' . self::e(str_replace('_', ' ', $order['fulfilment_status'])) . '</p><p><strong>Total:</strong> ' . self::e($order['currency'] . ' ' . Money::format($order['total_minor'], $order['currency'])) . '</p>';
            if ($order['payment_status'] === 'pending') $body .= '<p>Your payment is being confirmed. This page does not mark an order paid. Refresh to see the latest status.</p>';
            if ($order['fulfilment_status'] === 'needs_attention') $body .= '<p>Your payment was received, but the merchant must resolve stock availability. Contact the store with your order reference.</p>';
            return $this->page('Order status', $body);
        }
        if (!in_array($request->path, ['/commerce/cart','/commerce/checkout'], true)) return $this->page('Store', '<p>Page not found.</p>', 404);
        if (!in_array($request->method, ['GET','POST'], true)) return $this->page('Store', '<p>Method not allowed.</p>', 405);
        $cart = (array)$session->get('shop_cart', []);
        if ($request->method === 'POST') {
            if (!$csrf->validate($request->input('csrf_token'))) return $this->page('Cart', '<p>Security token expired. Reload the cart before trying again.</p>', 400);
            if (!(new RateLimiter($this->config->paths(), 30, 300))->consume('shop:' . (string)($request->server['REMOTE_ADDR'] ?? 'unknown'))) return $this->page('Cart', '<p>Too many attempts. Try again later.</p>', 429);
            if ($request->path === '/commerce/checkout') return $this->checkout($request, $session, $cart, $access);
            $slug = $request->input('product');
            if (preg_match('/^[a-z0-9-]{1,240}$/D', $slug) !== 1 || !preg_match('/^(0|[1-9][0-9]{0,2})$/D', $request->input('quantity'))) return $this->page('Cart', '<p>Choose a product and a whole quantity between 0 and 999.</p>', 422);
            $product = $this->products()->findBySlug($slug);
            $quantity = (int)$request->input('quantity');
            if ($quantity === 0) unset($cart[$slug]);
            elseif ($product === null || ($product['status'] ?? '') !== 'published' || (int)$product['inventory'] < $quantity) return $this->page('Cart', '<p>The requested quantity is unavailable.</p>', 422);
            else $cart[$slug] = $quantity;
            if (count($cart) > 20) return $this->page('Cart', '<p>A cart supports at most 20 different products.</p>', 422);
            $session->set('shop_cart', $cart); $session->set('shop_checkout_key', bin2hex(random_bytes(16)));
            $session->remove('shop_pending_order');
            return Response::redirect('/commerce/cart')->withHeader('Cache-Control', 'private, no-store');
        }
        return $this->cart($request, $csrf, $session, $cart);
    }

    private function cart(Request $request, Csrf $csrf, Session $session, array $cart, string $error = '', array $values = []): Response
    {
        $settings = $this->settings->read();
        $pending = null;
        $pendingId = $session->get('shop_pending_order');
        if (is_string($pendingId) && preg_match('/^ord_[a-f0-9]{32}$/D', $pendingId)) {
            $candidate = $this->orders->get($pendingId);
            if ($candidate !== null && hash_equals($candidate['access_hash'], hash('sha256', (string)$session->get('shop_access')))) {
                $pending = $candidate;
                if ($values === []) $values = $this->orders->customer($candidate);
            }
        }
        $body = $error !== '' ? '<p class="bp-error" role="alert">' . self::e($error) . '</p>' : '';
        $selected = $request->input('product');
        if ($selected !== '') {
            $product = $this->products()->findBySlug($selected);
            if ($product !== null && ($product['status'] ?? '') === 'published') $body .= '<form method="post" action="/commerce/cart" class="bp-form">' . $csrf->field() . '<input type="hidden" name="product" value="' . self::e($selected) . '"><p><strong>' . self::e($product['title']) . '</strong> · ' . self::e($product['currency'] . ' ' . $product['price']) . '</p><label>Quantity <input type="number" name="quantity" min="1" max="999" value="1" required></label><button type="submit">Add to cart</button></form>';
        }
        if ($cart === []) return $this->page('Cart', $body . '<p>Your cart is empty.</p><p><a href="' . self::e(\bp_url('/shop')) . '">Browse products</a></p>');
        $subtotal = 0; $valid = true;
        $body .= '<div class="bp-table-wrap"><table class="bp-table"><caption>Items in your cart</caption><thead><tr><th scope="col">Product</th><th scope="col">Price</th><th scope="col">Quantity</th></tr></thead><tbody>';
        foreach ($cart as $slug => $quantity) {
            $product = $this->products()->findBySlug($slug);
            $held = 0;
            if ($pending !== null && $pending['allocation'] === 'held' && $pending['hold_until'] >= time()) foreach ($pending['items'] as $item) if ($item['slug'] === $slug) $held = $item['quantity'];
            if ($product === null || ($product['status'] ?? '') !== 'published' || $product['currency'] !== $settings['currency'] || $product['inventory'] + $held < $quantity) $valid = false;
            else $subtotal = Money::add($subtotal, Money::multiply(Money::parse($product['price'], $product['currency']), $quantity));
            $body .= '<tr><th scope="row">' . self::e((string)($product['title'] ?? $slug)) . '</th><td>' . self::e((string)($product['currency'] ?? '') . ' ' . (string)($product['price'] ?? 'Unavailable')) . '</td><td><form method="post" action="/commerce/cart">' . $csrf->field() . '<input type="hidden" name="product" value="' . self::e($slug) . '"><label>Quantity for ' . self::e((string)($product['title'] ?? $slug)) . '<input type="number" name="quantity" min="0" max="999" value="' . $quantity . '" required></label><button type="submit">Update</button><small>Use 0 to remove.</small></form></td></tr>';
        }
        $body .= '</tbody></table></div>';
        if (!$valid) return $this->page('Cart', $body . '<p role="alert">A cart item changed or is unavailable. Update quantities or remove unavailable items before checkout.</p>', 422);
        $body .= '<p><strong>Subtotal:</strong> ' . self::e($settings['currency'] . ' ' . Money::format($subtotal, $settings['currency'])) . '</p><p>Shipping: ' . self::e(Money::format($settings['shipping_minor'], $settings['currency'])) . '; pickup: free. Tax: ' . self::e((string)($settings['tax_bps'] / 100)) . '% on items and shipping. Stripe shows the final total before payment.</p>';
        try { $this->settings->ready(); }
        catch (RuntimeException) { return $this->page('Cart', $body . '<p>Online checkout is not available yet. Contact the store.</p>'); }
        $key = $session->get('shop_checkout_key');
        if (!is_string($key) || !preg_match('/^[a-f0-9]{32}$/D', $key)) { $key = bin2hex(random_bytes(16)); $session->set('shop_checkout_key', $key); }
        $body .= '<form method="post" action="/commerce/checkout" class="bp-form">' . $csrf->field() . '<input type="hidden" name="checkout_key" value="' . $key . '"><h2>Guest checkout</h2>';
        foreach (['name'=>'Full name','email'=>'Email','address'=>'Delivery address','city'=>'City','postcode'=>'Postal code'] as $name => $label) $body .= '<label>' . $label . '<input name="' . $name . '" type="' . ($name === 'email' ? 'email' : 'text') . '" maxlength="' . ($name === 'email' ? 254 : 300) . '" value="' . self::e((string)($values[$name] ?? '')) . '"' . (in_array($name, ['name','email'], true) ? ' required' : '') . '></label>';
        $body .= '<label>Country <select name="country" required>';
        foreach ($settings['countries'] as $country) $body .= '<option value="' . self::e($country) . '"' . (($values['country'] ?? '') === $country ? ' selected' : '') . '>' . self::e($country) . '</option>';
        $body .= '</select></label><label>Delivery <select name="delivery"><option value="shipping">Flat-rate shipping</option>' . ($settings['pickup'] ? '<option value="pickup"' . (($values['delivery'] ?? '') === 'pickup' ? ' selected' : '') . '>Local pickup</option>' : '') . '</select></label><button type="submit">Continue to Stripe Checkout</button><p>Payment details are entered on Stripe. Your order contact details are stored privately for fulfilment.</p></form>';
        return $this->page('Cart and checkout', $body);
    }

    private function checkout(Request $request, Session $session, array $cart, string $access): Response
    {
        $values = []; foreach (['name','email','address','city','postcode','country','delivery'] as $name) $values[$name] = $request->input($name);
        try {
            $settings = $this->settings->ready();
            if (!hash_equals((string)$session->get('shop_checkout_key', ''), $request->input('checkout_key'))) throw new RuntimeException('Your cart changed. Reload before checking out.');
            if ($values['name'] === '' || strlen($values['name']) > 160 || !filter_var($values['email'], FILTER_VALIDATE_EMAIL) || strlen($values['email']) > 254 || array_filter($values, static fn(string $v): bool => strlen($v) > 300 || preg_match('//u', $v) !== 1 || preg_match('/[\x00-\x1f\x7f]/', $v))) throw new RuntimeException('Enter valid contact details.');
            if (!in_array($values['country'], $settings['countries'], true) || !in_array($values['delivery'], ['shipping','pickup'], true) || ($values['delivery'] === 'pickup' && !$settings['pickup'])) throw new RuntimeException('Choose a supported delivery destination and method.');
            if ($values['delivery'] === 'shipping' && ($values['address'] === '' || $values['city'] === '' || $values['postcode'] === '')) throw new RuntimeException('Enter your complete delivery address.');
            $order = $this->orders->reserve($cart, $values, $access, $request->input('checkout_key'), $settings);
            $session->set('shop_pending_order', $order['id']);
            if (in_array($order['payment_status'], ['paid','partially_refunded','refunded'], true)) return Response::redirect('/commerce/order/' . $order['id'])->withHeader('Cache-Control', 'private, no-store');
            if ($order['hold_until'] < time() || $order['payment_status'] === 'expired') throw new RuntimeException('This checkout expired. Update the cart to begin a new checkout.');
            if ($order['stripe_session'] === '') {
                $provider = new StripeCheckout($this->settings->secrets($settings)['key']);
                $stripe = $provider->create($order, $values, (string)$this->config->site()['base_url'], $this->orders->siteId());
                $order = $this->orders->attach($order['id'], $stripe);
            }
            $url = (string)($order['checkout_url'] ?? '');
            $parts = parse_url($url);
            if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') !== 'checkout.stripe.com' || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || preg_match('/[\r\n]/', $url)) throw new RuntimeException('Payment session needs merchant review.');
            return Response::redirect($url)->withHeader('Cache-Control', 'private, no-store')->withHeader('Referrer-Policy', 'no-referrer');
        } catch (RuntimeException $error) { return $this->cart($request, new Csrf($session), $session, $cart, $error->getMessage(), $values); }
    }

    private function webhook(Request $request): Response
    {
        if ($request->method !== 'POST') return Response::json(['error'=>'POST required'], 405, ['Allow'=>'POST']);
        try {
            if (strlen($request->rawBody) > 262144) return Response::json(['error'=>'Payment callback exceeds its limit.'], 413);
            $envelope = json_decode($request->rawBody, true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($envelope) || !is_bool($envelope['livemode'] ?? null)) return Response::json(['error'=>'Invalid payment event.'], 400);
            $settings = array_replace($this->settings->read(), ['mode'=>$envelope['livemode'] ? 'live' : 'test']);
            $secrets = $this->settings->secrets($settings);
            $event = StripeCheckout::verifyEvent($request->rawBody, $request->header('Stripe-Signature'), $secrets['webhook']);
            if (!empty($event['account'])) throw new RuntimeException('Unexpected Stripe connected account.');
            if (!in_array($event['type'], ['checkout.session.completed','checkout.session.async_payment_succeeded','checkout.session.async_payment_failed','checkout.session.expired'], true)) return Response::json(['received'=>true]);
            $object = $event['data']['object'] ?? [];
            $id = (string)($object['metadata']['press_order'] ?? '');
            if (($object['metadata']['press_site'] ?? '') !== $this->orders->siteId()) return Response::json(['received'=>true]);
            if (!preg_match('/^ord_[a-f0-9]{32}$/D', $id)) throw new RuntimeException('Invalid payment order.');
            $session = (new StripeCheckout($secrets['key']))->retrieve((string)($object['id'] ?? ''));
            $this->orders->applySession($id, $session, $event['id']);
            (new AuditLog($this->config->paths(), new FileStore()))->record('stripe', 'commerce.payment_event', $id, '', 'success', ['request_id'=>$request->requestId]);
            return Response::json(['received'=>true]);
        } catch (\Throwable) {
            // A transient lookup or storage error must retry; never acknowledge uncommitted work.
            return Response::json(['error'=>'Payment event could not be accepted.','request_id'=>$request->requestId], 503, ['Cache-Control'=>'no-store']);
        }
    }
    private function page(string $title, string $body, int $status = 200): Response { return $this->theme->render('page', ['title'=>$title,'page'=>['title'=>$title,'body'=>$body]], $status)->withHeader('Cache-Control','private, no-store')->withHeader('Referrer-Policy','no-referrer'); }
    private function products(): ProductRepository { return new ProductRepository($this->config->paths(), new FileStore(), new HtmlContent()); }
    private static function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
