<?php
declare(strict_types=1);

namespace Batoi\Press\Commerce;

use Batoi\Press\Application\ContentRevision;
use Batoi\Press\Core\{Config, FileStore, Money};
use Batoi\Press\Security\SecretStore;
use RuntimeException;

final class StoreSettings
{
    public function __construct(private readonly Config $config) {}
    public function read(): array
    {
        $path = $this->config->paths()->dataPath('commerce/settings.json');
        $settings = is_file($path) ? (new FileStore())->readJson($path) : [];
        return $settings + ['schema'=>1,'enabled'=>false,'currency'=>'USD','shipping_minor'=>0,'tax_bps'=>0,'tax_confirmed'=>false,'countries'=>[],'pickup'=>true,'mode'=>'test'];
    }
    public function save(array $input, string $revision, string $apiKey = '', string $webhookSecret = ''): void
    {
        $currency = strtoupper(trim((string)($input['currency'] ?? '')));
        Money::exponent($currency);
        if (Money::exponent($currency) === 3) throw new RuntimeException('This Stripe Checkout profile supports zero- and two-decimal store currencies. Three-decimal prices remain supported in the catalogue.');
        $shipping = Money::parse((string)($input['shipping'] ?? '0'), $currency);
        $tax = (string)($input['tax_bps'] ?? '0');
        if (!preg_match('/^(0|[1-9][0-9]{0,4})$/D', $tax) || (int)$tax > 10000) throw new RuntimeException('Tax must be 0–10000 basis points (100 basis points = 1%).');
        $countries = array_values(array_unique(array_filter(array_map('trim', explode(',', strtoupper((string)($input['countries'] ?? '')))))));
        if (count($countries) > 20 || array_filter($countries, static fn(string $country): bool => preg_match('/^[A-Z]{2}$/D', $country) !== 1)) throw new RuntimeException('Use up to 20 comma-separated two-letter destination country codes.');
        $mode = ($input['mode'] ?? '') === 'live' ? 'live' : 'test';
        if ($apiKey !== '' && preg_match('/^(?:sk|rk)_' . $mode . '_[A-Za-z0-9]{12,250}$/D', $apiKey) !== 1) throw new RuntimeException('Stripe API key does not match the selected mode.');
        if ($webhookSecret !== '' && preg_match('/^whsec_[A-Za-z0-9]{12,250}$/D', $webhookSecret) !== 1) throw new RuntimeException('Invalid Stripe endpoint signing secret.');
        $path = $this->config->paths()->dataPath('commerce/settings.json');
        (new FileStore())->mutateJson($path, function (array $old) use ($input, $revision, $currency, $shipping, $tax, $countries, $mode, $apiKey, $webhookSecret): array {
            $current = $old + ['schema'=>1,'enabled'=>false,'currency'=>'USD','shipping_minor'=>0,'tax_bps'=>0,'tax_confirmed'=>false,'countries'=>[],'pickup'=>true,'mode'=>'test'];
            if (!hash_equals(ContentRevision::for($current), $revision)) throw new RuntimeException('Store settings changed. Reload before saving.');
            $next = ['schema'=>1,'enabled'=>($input['enabled'] ?? '') === '1','currency'=>$currency,'shipping_minor'=>$shipping,'tax_bps'=>(int)$tax,'tax_confirmed'=>($input['tax_confirmed'] ?? '') === '1','countries'=>$countries,'pickup'=>($input['pickup'] ?? '') === '1','mode'=>$mode];
            $vault = new SecretStore($this->config->paths());
            foreach (['test','live'] as $savedMode) foreach (['stripe_key','webhook_secret'] as $name) {
                if (isset($old[$name . '_' . $savedMode])) $next[$name . '_' . $savedMode] = $old[$name . '_' . $savedMode];
            }
            foreach (['stripe_key','webhook_secret'] as $name) if (isset($old[$name])) $next[$name . '_' . ($old['mode'] ?? 'test')] = $old[$name];
            foreach (['stripe_key'=>$apiKey,'webhook_secret'=>$webhookSecret] as $name => $value) {
                if ($value !== '') $next[$name . '_' . $mode] = $vault->encrypt($value);
            }
            return $next;
        });
    }
    public function secrets(?array $settings = null): array
    {
        $settings ??= $this->read();
        $vault = new SecretStore($this->config->paths());
        $mode = ($settings['mode'] ?? '') === 'live' ? 'live' : 'test';
        $key = trim((string)getenv('BATOI_PRESS_STRIPE_SECRET_KEY_' . strtoupper($mode)));
        $secret = trim((string)getenv('BATOI_PRESS_STRIPE_WEBHOOK_SECRET_' . strtoupper($mode)));
        $generalKey = trim((string)getenv('BATOI_PRESS_STRIPE_SECRET_KEY'));
        if ($key === '' && preg_match('/^(?:sk|rk)_' . $mode . '_/', $generalKey)) $key = $generalKey;
        if ($secret === '' && ($this->read()['mode'] === $mode)) $secret = trim((string)getenv('BATOI_PRESS_STRIPE_WEBHOOK_SECRET'));
        if ($key === '' && isset($settings['stripe_key_' . $mode])) $key = $vault->decrypt($settings['stripe_key_' . $mode]);
        if ($secret === '' && isset($settings['webhook_secret_' . $mode])) $secret = $vault->decrypt($settings['webhook_secret_' . $mode]);
        if ($key === '' && isset($settings['stripe_key']) && $this->read()['mode'] === $mode) $key = $vault->decrypt($settings['stripe_key']);
        if ($secret === '' && isset($settings['webhook_secret']) && $this->read()['mode'] === $mode) $secret = $vault->decrypt($settings['webhook_secret']);
        if (preg_match('/^(?:sk|rk)_' . $mode . '_[A-Za-z0-9]{12,250}$/D', $key) !== 1 || preg_match('/^whsec_[A-Za-z0-9]{12,250}$/D', $secret) !== 1) throw new RuntimeException('Configure Stripe API and webhook signing credentials for the selected mode.');
        return ['key'=>$key,'webhook'=>$secret];
    }
    public function ready(): array
    {
        $settings = $this->read();
        if (!$settings['enabled']) throw new RuntimeException('Commerce is disabled.');
        if (Money::exponent($settings['currency']) === 3) throw new RuntimeException('Select a supported Stripe Checkout store currency.');
        if (!$settings['tax_confirmed'] || $settings['countries'] === []) throw new RuntimeException('The owner must configure destinations and confirm the store tax policy.');
        $url = (string)($this->config->site()['base_url'] ?? '');
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) throw new RuntimeException('Online checkout requires a canonical HTTPS site URL.');
        if (!\Batoi\Press\Core\RuntimeCapabilities::available('http') || !\Batoi\Press\Core\RuntimeCapabilities::available('encryption')) throw new RuntimeException('Checkout requires cURL and encrypted-secret support.');
        $this->secrets($settings);
        return $settings;
    }
}
