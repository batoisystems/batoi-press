<?php
declare(strict_types=1);

namespace Batoi\Press\Admin;

use Batoi\Press\Application\ContentRevision;
use Batoi\Press\Commerce\{OrderStore, StoreSettings, StripeCheckout};
use Batoi\Press\Core\{AuditLog, Config, Money, Request, Response};
use Batoi\Press\Security\{AdminAccess, Csrf, MfaRepository, Password, RateLimiter};
use RuntimeException;

final class CommerceController
{
    public function __construct(private readonly Config $config, private readonly Csrf $csrf, private readonly AuditLog $audit, private readonly array $user) {}
    public function settings(string $notice = '', bool $error = false): Response
    {
        $store = new StoreSettings($this->config); $s = $store->read();
        $body = AdminLayout::pageHeader('Commerce', 'Enable a small store with guest checkout and Stripe-hosted payments.');
        if ($notice !== '') $body .= '<p class="' . ($error ? 'bp-error' : 'bp-notice') . '" role="' . ($error ? 'alert' : 'status') . '">' . self::e($notice) . '</p>';
        $body .= '<p>Commerce is ' . ($s['enabled'] ? 'enabled' : 'disabled') . '. Start in Stripe test mode. Existing payment callbacks remain available when the storefront is disabled.</p><form method="post" action="/admin/commerce/save" class="bp-form">' . $this->csrf->field() . '<input type="hidden" name="expected_revision" value="' . self::e(ContentRevision::for($s)) . '"><div class="bp-form-grid">';
        foreach (['enabled'=>'Enable storefront checkout','pickup'=>'Allow free local pickup','tax_confirmed'=>'I have configured the applicable tax rate and supported destinations'] as $name => $label) $body .= '<label class="bp-check-option"><input type="checkbox" name="' . $name . '" value="1"' . ($s[$name] ? ' checked' : '') . '><span>' . $label . '</span></label>';
        $body .= '<label>Store currency <select name="currency">';
        foreach (Money::CURRENCIES as $code => $precision) if ($precision !== 3) $body .= '<option value="' . $code . '"' . ($s['currency'] === $code ? ' selected' : '') . '>' . $code . '</option>';
        $body .= '</select></label><label>Flat shipping charge <input name="shipping" inputmode="decimal" value="' . self::e(Money::format($s['shipping_minor'], $s['currency'])) . '" required></label><label>Tax rate in basis points <input name="tax_bps" type="number" min="0" max="10000" value="' . $s['tax_bps'] . '" required><span class="bp-field-help">100 = 1%; tax is calculated on products plus shipping. Set 0 explicitly for a zero rate.</span></label><label>Destination countries <input name="countries" value="' . self::e(implode(', ', $s['countries'])) . '" placeholder="US, IN" required><span class="bp-field-help">Comma-separated two-letter codes; only these countries can check out.</span></label><label>Stripe mode <select name="mode"><option value="test"' . ($s['mode'] === 'test' ? ' selected' : '') . '>Test</option><option value="live"' . ($s['mode'] === 'live' ? ' selected' : '') . '>Live</option></select></label></div>';
        $body .= '<fieldset><legend>Stripe credentials</legend><p>Leave blank to retain encrypted credentials. Environment-provided keys take precedence. Keys are never displayed.</p><label>Secret API key <input type="password" name="stripe_key" autocomplete="new-password" maxlength="300"></label><label>Webhook signing secret <input type="password" name="webhook_secret" autocomplete="new-password" maxlength="300"></label><p>Webhook endpoint: <code>' . self::e(rtrim((string)($this->config->site()['base_url'] ?? ''), '/') . '/commerce/stripe/webhook') . '</code></p><p>Subscribe to checkout.session.completed, checkout.session.expired, checkout.session.async_payment_succeeded and checkout.session.async_payment_failed. Refunds are reconciled from Orders.</p></fieldset><fieldset><legend>Confirm owner identity</legend><label>Current password <input type="password" name="current_password" autocomplete="current-password" required></label>';
        if ((new MfaRepository($this->config->paths()))->enabled($this->user)) $body .= '<label>MFA code or recovery code <input name="mfa_code" autocomplete="one-time-code" required></label>';
        $body .= '</fieldset><button type="submit">Save store settings</button></form>';
        return Response::html(AdminLayout::render('Commerce', $body), $error ? 422 : 200);
    }
    public function save(Request $request): Response
    {
        if (AdminAccess::role($this->user) !== 'owner') return Response::html('Owner access required.', 403);
        if (!$this->csrf->validate($request->input('csrf_token'))) return Response::html(AdminLayout::message('Commerce', 'Security token expired.', true), 400);
        $limiter = new RateLimiter($this->config->paths(), 5, 300);
        $key = 'commerce-reauth:' . (string)$this->user['username'];
        if (!$limiter->consume($key)) return Response::html(AdminLayout::message('Commerce', 'Too many identity checks. Try again later.', true), 429);
        $mfa = new MfaRepository($this->config->paths());
        if (!Password::verify($request->input('current_password'), (string)($this->user['password_hash'] ?? '')) || ($mfa->enabled($this->user) && $mfa->verify($this->user['username'], $request->input('mfa_code')) === null)) return Response::html(AdminLayout::message('Commerce', 'Owner identity could not be verified.', true), 403);
        try {
            $input = []; foreach (['enabled','currency','shipping','tax_bps','tax_confirmed','countries','pickup','mode'] as $name) $input[$name] = $request->input($name);
            (new StoreSettings($this->config))->save($input, $request->input('expected_revision'), $request->input('stripe_key'), $request->input('webhook_secret'));
            $limiter->clear($key); $this->audit->record($this->user['username'], 'commerce.settings_saved', 'store', '', 'success');
            return $this->settings('Store settings saved. Complete a Stripe sandbox order before enabling live payments.');
        } catch (RuntimeException $error) { return $this->settings($error->getMessage(), true); }
    }
    public function index(Request $request): Response
    {
        $orders = (new OrderStore($this->config->paths()))->summaries();
        $q = substr($request->input('q'), 0, 100); $status = $request->input('status');
        $orders = array_values(array_filter($orders, static fn(array $o): bool => ($q === '' || stripos($o['id'], $q) !== false) && ($status === '' || $o['payment_status'] === $status)));
        $pages = max(1, (int)ceil(count($orders) / 25)); $page = max(1, min($pages, (int)$request->input('page', '1')));
        $body = AdminLayout::pageHeader('Orders', 'Review payments, resolve exceptions and manage fulfilment.');
        $body .= '<form method="get" class="bp-filter-form bp-form"><label>Order reference <input type="search" name="q" maxlength="100" value="' . self::e($q) . '"></label><label>Payment status <select name="status"><option value="">All statuses</option>';
        foreach (['pending','paid','expired','partially_refunded','refunded'] as $option) $body .= '<option value="' . $option . '"' . ($status === $option ? ' selected' : '') . '>' . self::e(ucfirst(str_replace('_',' ', $option))) . '</option>';
        $body .= '</select></label><button type="submit">Filter</button></form><p>' . count($orders) . ' matching orders · Page ' . $page . ' of ' . $pages . '</p>';
        if ($orders === []) $body .= '<p>No matching orders.</p>';
        else {
            $body .= '<div class="bp-table-wrap"><table class="bp-table"><caption class="uif-sr-only">Store orders</caption><thead><tr><th scope="col">Order</th><th scope="col">Created</th><th scope="col">Total</th><th scope="col">Payment</th><th scope="col">Fulfilment</th></tr></thead><tbody>';
            foreach (array_slice($orders, ($page - 1) * 25, 25) as $o) $body .= '<tr><th scope="row"><a href="/admin/orders/' . $o['id'] . '">' . self::e($o['id']) . '</a></th><td>' . self::e(gmdate('Y-m-d H:i', $o['created_at'])) . ' UTC</td><td>' . self::e($o['currency'] . ' ' . Money::format($o['total_minor'], $o['currency'])) . '</td><td>' . self::e(str_replace('_',' ', $o['payment_status'])) . '</td><td>' . self::e(str_replace('_',' ', $o['fulfilment_status'])) . '</td></tr>';
            $body .= '</tbody></table></div><nav aria-label="Order pagination">';
            foreach (['Previous'=>$page - 1,'Next'=>$page + 1] as $label => $p) if ($p >= 1 && $p <= $pages) $body .= AdminLayout::buttonLink($label, '/admin/orders?' . http_build_query(['q'=>$q,'status'=>$status,'page'=>$p]), 'back', true);
            $body .= '</nav>';
        }
        return Response::html(AdminLayout::render('Orders', $body));
    }
    public function detail(string $id, string $notice = ''): Response
    {
        $store = new OrderStore($this->config->paths()); $order = $store->get($id);
        if ($order === null) return Response::html(AdminLayout::message('Orders', 'Order not found.', true), 404);
        $body = AdminLayout::pageHeader('Order ' . substr($id, -8), 'Payment and fulfilment are tracked separately.', AdminLayout::buttonLink('Back to orders', '/admin/orders', 'back', true));
        if ($notice !== '') $body .= '<p class="bp-notice" role="status">' . self::e($notice) . '</p>';
        $body .= '<p>Reference: <code>' . self::e($id) . '</code></p><p>Payment: <strong>' . self::e($order['payment_status']) . '</strong> · Fulfilment: <strong>' . self::e($order['fulfilment_status']) . '</strong> · Stock: ' . self::e($order['allocation']) . '</p><ul>';
        foreach ($order['items'] as $item) $body .= '<li>' . self::e($item['title']) . ' × ' . $item['quantity'] . ' · ' . self::e(Money::format($item['unit_minor'], $order['currency'])) . '</li>';
        $body .= '</ul><p>Total: <strong>' . self::e($order['currency'] . ' ' . Money::format($order['total_minor'], $order['currency'])) . '</strong> · Refunded: ' . self::e(Money::format($order['refunded_minor'], $order['currency'])) . '</p>';
        try { $customer = $store->customer($order); $body .= '<dl>'; foreach ($customer as $name => $value) $body .= '<dt>' . self::e(ucfirst($name)) . '</dt><dd>' . self::e((string)$value) . '</dd>'; $body .= '</dl>'; }
        catch (\Throwable) { $body .= '<p class="bp-error">Customer details require secret-key recovery.</p>'; }
        foreach (['reconcile'=>'Reconcile with Stripe','fulfil'=>'Mark fulfilled'] as $action => $label) {
            if ($action === 'reconcile' && $order['stripe_session'] === '') continue;
            if ($action === 'fulfil' && ($order['payment_status'] !== 'paid' || $order['allocation'] !== 'sold' || $order['fulfilment_status'] !== 'unfulfilled')) continue;
            $body .= '<form method="post" action="/admin/orders/' . $id . '/' . $action . '" class="bp-inline-form">' . $this->csrf->field() . '<input type="hidden" name="expected_revision" value="' . self::e(ContentRevision::for($order)) . '"><button type="submit">' . $label . '</button></form>';
        }
        $body .= '<p>Initiate refunds in the Stripe Dashboard, then reconcile here. Refunds do not automatically restock products.</p>';
        return Response::html(AdminLayout::render('Order', $body));
    }
    public function action(Request $request, string $id, string $action): Response
    {
        if (!$this->csrf->validate($request->input('csrf_token'))) return Response::html(AdminLayout::message('Orders', 'Security token expired.', true), 400);
        if (!(new RateLimiter($this->config->paths(), 20, 60))->consume('order-action:' . $this->user['username'])) return Response::html('Too many order operations.', 429);
        try {
            $store = new OrderStore($this->config->paths());
            if ($action === 'fulfil') $store->fulfil($id, $request->input('expected_revision'));
            else {
                $order = $store->get($id);
                if ($order === null || !hash_equals(ContentRevision::for($order), $request->input('expected_revision'))) throw new RuntimeException('Order changed. Reload it before reconciling.');
                $settings = new StoreSettings($this->config);
                $session = (new StripeCheckout($settings->secrets(array_replace($settings->read(), ['mode'=>$order['mode'] ?? 'test']))['key']))->retrieve($order['stripe_session']);
                $store->applySession($id, $session, 'reconcile:' . bin2hex(random_bytes(16)));
            }
            $this->audit->record($this->user['username'], 'commerce.' . $action, $id, '', 'success');
            return $this->detail($id, 'Order updated.');
        } catch (RuntimeException $error) { return Response::html(AdminLayout::message('Orders', $error->getMessage(), true), 409); }
    }
    private static function e(string $v): string { return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
