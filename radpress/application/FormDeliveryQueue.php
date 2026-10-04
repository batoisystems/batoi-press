<?php
declare(strict_types=1);

namespace Batoi\Press\Application;

use Batoi\Press\Core\Config;
use Batoi\Press\Core\FileStore;
use Batoi\Press\Core\PluginManager;
use Batoi\Press\Security\SecretStore;
use RuntimeException;

final class FormDeliveryQueue
{
    public function __construct(private readonly Config $config, private readonly ?\Closure $transport = null) {}

    /** Validate local configuration without contacting a provider or sending visitor data. */
    public function assertReady(array $form): void
    {
        $integrations = $this->config->integrations();
        $siteKey = trim((string)($integrations['recaptcha_site_key'] ?? ''));
        $secret = (string)($integrations['recaptcha_secret_key'] ?? '');
        if ($siteKey !== '' || $secret !== '') {
            if ($siteKey === '' || $secret === '' || !\Batoi\Press\Core\RuntimeCapabilities::available('http')) throw new RuntimeException('Complete human-verification settings and enable cURL before publishing.');
            if ((new SecretStore($this->config->paths()))->decrypt($secret) === '') throw new RuntimeException('Human-verification credentials are unavailable.');
        }
        if ($form['action'] === 'store') return;
        if (!\Batoi\Press\Core\RuntimeCapabilities::available('encryption')) throw new RuntimeException('Queued delivery requires an encryption backend.');
        if ($form['action'] === 'email') {
            $emailFields = array_filter($form['fields'], static fn(array $field): bool => $field['type'] === 'email' && $field['required']);
            if ($emailFields === []) throw new RuntimeException('Email delivery requires a required email field.');
            $settings = $this->config->integrations();
            foreach (['mail_from', 'mail_to'] as $key) if (!filter_var($settings[$key] ?? '', FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Configure valid site mail sender and recipient addresses before publishing.');
            $provider = $settings['mail_provider'] ?? 'disabled';
            if ($provider === 'php_mail') {
                if (!function_exists('mail')) throw new RuntimeException('PHP mail is unavailable on this host.');
            } elseif ($provider === 'mailgun') {
                if (!\Batoi\Press\Core\RuntimeCapabilities::available('http') || empty($settings['mailgun_domain']) || empty($settings['mailgun_api_key'])) throw new RuntimeException('Configure Mailgun credentials and enable cURL before publishing.');
                if ((new SecretStore($this->config->paths()))->decrypt($settings['mailgun_api_key']) === '') throw new RuntimeException('Mailgun credentials are unavailable.');
            } else throw new RuntimeException('Enable a supported site mail provider before publishing.');
            return;
        }
        if (!(new PluginManager($this->config->paths()))->enabled('webhooks')) throw new RuntimeException('Enable Webhook delivery before publishing.');
        $path = $this->config->paths()->dataPath('plugins/webhooks/connections.json');
        $connection = is_file($path) ? ((new FileStore())->readJson($path)['connections'][$form['connection']] ?? null) : null;
        if (!is_array($connection) || ($connection['enabled'] ?? false) !== true) throw new RuntimeException('Select an enabled webhook connection before publishing.');
        if (strlen((new SecretStore($this->config->paths()))->decrypt($connection['secret'] ?? '')) < 32) throw new RuntimeException('The webhook signing secret is unavailable.');
    }

    public function enqueue(string $id, array $form, array $values, bool $retain = false): void
    {
        if (preg_match('/^[a-zA-Z0-9_-]{1,128}$/D', $id) !== 1) throw new RuntimeException('Invalid delivery identity.');
        $encrypted = (new SecretStore($this->config->paths()))->encrypt(json_encode(['form' => $form, 'values' => $values], JSON_THROW_ON_ERROR));
        $fingerprint = hash('sha256', json_encode([$form, $values], JSON_THROW_ON_ERROR));
        (new FileStore())->mutateJson($this->path(), static function (array $state) use ($id, $encrypted, $fingerprint, $form, $values, $retain): array {
            if (isset($state['jobs'][$id]['fingerprint']) && !hash_equals($state['jobs'][$id]['fingerprint'], $fingerprint)) throw new RuntimeException('Delivery identity is already bound to another submission.');
            if (isset($state['jobs'][$id])) return $state;
            $jobs = array_filter($state['jobs'] ?? [], static fn(array $job): bool => ($job['expires_at'] ?? 0) > time());
            if (count($jobs) >= 1000) throw new RuntimeException('Delivery queue is full.');
            if ($retain && $form['store']) {
                $records = array_values(array_filter($state['submissions'] ?? [], static fn(array $record): bool => $record['expires_at'] > time()));
                if (count($records) >= 1000) throw new RuntimeException('Queued submission storage is full.');
                foreach ($form['fields'] as $field) if ($field['sensitive']) $values[$field['id']] = '[redacted]';
                $records[] = ['id'=>$id, 'form_id'=>$form['id'], 'created_at'=>time(), 'expires_at'=>time()+$form['retention_days']*86400, 'values'=>$values];
                $state['submissions'] = $records;
            }
            $jobs[$id] = ['id' => $id, 'fingerprint'=>$fingerprint, 'status' => 'pending', 'attempts' => 0, 'next_at' => time(), 'expires_at' => time() + 604800, 'payload' => $encrypted];
            $state['jobs'] = $jobs;
            return $state;
        });
    }

    public function status(): array
    {
        $files = new FileStore();
        $state = is_file($this->path()) ? $files->readJson($this->path()) : [];
        return array_values(array_map(static function (array $job): array { unset($job['payload'], $job['lease'], $job['fingerprint']); return $job; }, $state['jobs'] ?? []));
    }

    /** At most one bounded delivery per operator invocation; lease supports worker crashes. */
    public function process(): bool
    {
        if (!(new PluginManager($this->config->paths()))->enabled('forms')) return false;
        $files = new FileStore(); $selected = null; $lease = bin2hex(random_bytes(12));
        $files->mutateJson($this->path(), static function (array $state) use (&$selected, $lease): array {
            foreach ($state['jobs'] ?? [] as $id => $job) {
                if (($job['expires_at'] ?? 0) <= time()) { unset($state['jobs'][$id]); continue; }
                if (!in_array($job['status'], ['pending','processing'], true) || $job['next_at'] > time() || ($job['lease_until'] ?? 0) > time()) continue;
                if ($job['attempts'] >= 5) {
                    $job['status'] = 'failed';
                    unset($job['payload'], $job['lease'], $job['lease_until']);
                    $state['jobs'][$id] = $job;
                    continue;
                }
                $job['status'] = 'processing'; $job['lease'] = $lease; $job['lease_until'] = time() + 60;
                $job['attempts']++; $state['jobs'][$id] = $job; $selected = $job; break;
            }
            return $state;
        });
        if ($selected === null) return false;
        $success = false;
        try {
            $payload = json_decode((new SecretStore($this->config->paths()))->decrypt($selected['payload']), true, 64, JSON_THROW_ON_ERROR);
            if ($this->transport !== null) ($this->transport)($selected['id'], $payload);
            else $this->deliver($selected['id'], $payload);
            $success = true;
        } catch (\Throwable) { /* Neutral queue status; never retain transport errors or visitor text. */ }
        $files->mutateJson($this->path(), static function (array $state) use ($selected, $success, $lease): array {
            $job = $state['jobs'][$selected['id']] ?? null;
            if ($job === null || ($job['lease'] ?? '') !== $lease) return $state;
            $job['status'] = $success ? 'sent' : ($job['attempts'] >= 5 ? 'failed' : 'pending');
            $job['next_at'] = time() + min(3600, 60 * (2 ** $job['attempts']));
            unset($job['lease'], $job['lease_until']);
            if ($job['status'] !== 'pending') unset($job['payload']);
            $state['jobs'][$selected['id']] = $job;
            return $state;
        });
        ($GLOBALS['bp_plugin_context'] ?? null)?->emit('delivery.completed', ['id' => $selected['id'], 'success' => $success]);
        return true;
    }

    private function deliver(string $id, array $payload): void
    {
        $form = $payload['form']; $values = $payload['values'];
        if ($form['action'] === 'email') {
            $email = ''; $message = [];
            foreach ($form['fields'] as $field) {
                if ($field['type'] === 'email' && $field['required'] && $email === '') $email = $values[$field['id']];
                $message[] = $field['label'] . ': ' . $values[$field['id']];
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Email delivery requires a valid email field.');
            (new MailerService($this->config->paths(), $this->config->integrations()))->sendContact((string)($values['name'] ?? 'Website form'), $email, trim((string)($values['subject'] ?? '')) ?: $form['title'], implode("\n", $message));
            return;
        }
        if ($form['action'] !== 'webhook' || !(new PluginManager($this->config->paths()))->enabled('webhooks')) throw new RuntimeException('Webhook plugin is unavailable.');
        $path = $this->config->paths()->dataPath('plugins/webhooks/connections.json');
        $connection = (new FileStore())->readJson($path)['connections'][$form['connection']] ?? null;
        if (!is_array($connection) || ($connection['enabled'] ?? false) !== true) throw new RuntimeException('Webhook connection is unavailable.');
        $secret = (new SecretStore($this->config->paths()))->decrypt($connection['secret']);
        (new WebhookTransport())->send($connection['url'], $secret, $id, ['form_id' => $form['id'], 'fields' => $values]);
    }

    private function path(): string { return $this->config->paths()->dataPath('forms/deliveries.json'); }
}
