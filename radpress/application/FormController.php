<?php
declare(strict_types=1);

namespace Batoi\Press\Application;

use Batoi\Press\Content\FormRepository;
use Batoi\Press\Core\Config;
use Batoi\Press\Core\PluginManager;
use Batoi\Press\Core\Request;
use Batoi\Press\Core\Response;
use Batoi\Press\Security\Csrf;
use Batoi\Press\Security\RateLimiter;
use Batoi\Press\Security\Session;

final class FormController
{
    public function __construct(private readonly Config $config) {}

    public function handle(string $id, Request $request): Response
    {
        $repository = new FormRepository($this->config->paths());
        try { $form = $repository->find($id); } catch (\RuntimeException) { $form = null; }
        if ($form === null || !$form['enabled'] || !(new PluginManager($this->config->paths()))->enabled('forms')) return Response::html('Form unavailable.', 404);
        $session = new Session((string)($this->config->security()['session_name'] ?? 'batoi_press_session'), $this->config->paths()->dataPath('sessions'));
        $csrf = new Csrf($session); $errors = []; $values = []; $status = 200;
        $nonces = array_filter((array)$session->get('form_nonces', []), static fn(array $nonce): bool => $nonce['expires'] > time());
        if ($request->method === 'POST') {
            $nonce = $request->input('submission_id');
            if (!$csrf->validate($request->input('csrf_token')) || !isset($nonces[$nonce]) || $nonces[$nonce]['form'] !== $id) {
                $errors['_form'] = 'This form expired. Review it and submit again.'; $status = 400;
            } elseif (!(new RateLimiter($this->config->paths(), 5, 600))->consume('form:' . ($request->server['REMOTE_ADDR'] ?? 'unknown'))) {
                $errors['_form'] = 'Too many submissions. Please try again later.'; $status = 429;
            } else {
                $checked = $repository->validate($form, (array)($request->post['fields'] ?? []));
                $errors = $checked['errors']; $values = $checked['values'];
                if ($errors === [] && $request->input('website') === '' && !(new ContactController($this->config))->verifyHuman($request)) $errors['_form'] = 'Human verification failed. Please try again.';
                if ($request->input('website') !== '') $errors = [];
                if ($errors === []) {
                    try {
                        if ($request->input('website') === '') {
                            (new FormDeliveryQueue($this->config))->assertReady($form);
                            // A stable browser nonce also binds the queued job identity.
                            if ($form['action'] === 'store') $repository->retain($form, $nonce, $values);
                            else (new FormDeliveryQueue($this->config))->enqueue($nonce, $form, $values, true);
                        }
                        unset($nonces[$nonce]); $session->set('form_nonces', $nonces);
                        ($GLOBALS['bp_plugin_context'] ?? null)?->emit('form.accepted', ['id' => $nonce, 'form_id' => $id]);
                        return Response::redirect('/forms/' . $id . '?sent=1')->withHeader('Cache-Control', 'private, no-store');
                    } catch (\RuntimeException) { $errors['_form'] = 'We could not accept the submission. Please try again later.'; $status = 503; }
                } else $status = 422;
            }
        }
        // Preserve the identity after an acceptance failure so a retry cannot duplicate a committed job.
        $nonce = $status === 503 && isset($nonce, $nonces[$nonce]) ? $nonce : bin2hex(random_bytes(16));
        $nonces[$nonce] = ['form' => $id, 'expires' => time() + 3600];
        $session->set('form_nonces', array_slice($nonces, -20, null, true));
        return $this->render($form, $csrf, $nonce, $errors, $values, $status, $request->input('sent') === '1');
    }

    /** Called only by the authenticated administrator controller; no session or delivery work. */
    public function preview(array $form): Response
    {
        return $this->render($form, null, '', [], [], 200, false, true);
    }

    private function render(array $form, ?Csrf $csrf, string $nonce, array $errors, array $values, int $status, bool $sent, bool $preview = false): Response
    {
        $id = $form['id'];
        $html = '<h1>' . self::e($form['title']) . '</h1><p>' . self::e($form['description']) . '</p>';
        if ($preview) $html .= '<p class="bp-notice" role="status">Draft preview. Submissions are disabled.</p>';
        if ($sent) $html .= '<p class="bp-notice" role="status">' . self::e($form['success_message']) . '</p>';
        if (isset($errors['_form'])) $html .= '<p class="bp-error" role="alert">' . self::e($errors['_form']) . '</p>';
        $html .= '<form method="post" class="bp-custom-form" action="' . self::e(\bp_url('/forms/' . $id)) . '">' . ($csrf?->field() ?? '') . '<input type="hidden" name="submission_id" value="' . $nonce . '">';
        foreach ($form['fields'] as $field) {
            $key = $field['id']; $value = $values[$key] ?? ''; $name = 'fields[' . $key . ']';
            $attributes = ' id="field-' . $key . '" name="' . $name . '"' . ($field['required'] ? ' required' : '') . ($preview ? ' disabled' : '') . (isset($errors[$key]) ? ' aria-invalid="true" aria-describedby="error-' . $key . '"' : '');
            $label = self::e($field['label']) . ($field['required'] ? ' <span aria-hidden="true">*</span>' : '');
            if (in_array($field['type'], ['checkbox','consent'], true)) $html .= '<label class="bp-consent"><input type="checkbox" value="1"' . $attributes . ($value === '1' ? ' checked' : '') . '><span>' . $label . '</span></label>';
            else {
                $html .= '<label for="field-' . $key . '">' . $label;
                if ($field['type'] === 'textarea') $html .= '<textarea rows="5" maxlength="' . $field['max_length'] . '"' . $attributes . '>' . self::e($value) . '</textarea>';
                elseif ($field['type'] === 'select') {
                    $html .= '<select' . $attributes . '><option value="">Choose an option</option>';
                    foreach ($field['choices'] as $choice) $html .= '<option value="' . self::e($choice) . '"' . ($value === $choice ? ' selected' : '') . '>' . self::e($choice) . '</option>';
                    $html .= '</select>';
                } else $html .= '<input type="' . $field['type'] . '" maxlength="' . $field['max_length'] . '" value="' . self::e($value) . '"' . $attributes . '>';
                $html .= '</label>';
            }
            if (isset($errors[$key])) $html .= '<p class="bp-error" id="error-' . $key . '">' . self::e($errors[$key]) . '</p>';
        }
        $siteKey = trim((string)($this->config->integrations()['recaptcha_site_key'] ?? ''));
        if ($siteKey !== '' && !$preview) $html .= '<div class="g-recaptcha" data-sitekey="' . self::e($siteKey) . '"></div><script src="https://www.google.com/recaptcha/api.js" async defer></script>';
        $html .= '<label class="bp-honeypot" aria-hidden="true">Website<input name="website" tabindex="-1" autocomplete="off"></label><p>Fields marked * are required.</p><button class="bp-button" type="submit"' . ($preview ? ' disabled' : '') . '>Submit</button></form>';
        return Response::html('<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . self::e($form['title']) . '</title><link rel="stylesheet" href="' . self::e(\bp_url('/assets/css/style.css')) . '"><link rel="stylesheet" href="' . self::e(\bp_url('/theme-assets/default/css/theme.css')) . '"></head><body class="bp-public-body bp-theme-versatile"><main class="bp-main">' . $html . '</main></body></html>', $status)->withHeader('Cache-Control', 'private, no-store');
    }

    private static function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
