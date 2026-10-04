<?php
declare(strict_types=1);

namespace Batoi\Press\Admin;

use Batoi\Press\Application\ContentRevision;
use Batoi\Press\Application\FormDeliveryQueue;
use Batoi\Press\Content\FormRepository;
use Batoi\Press\Core\AuditLog;
use Batoi\Press\Core\Config;
use Batoi\Press\Core\PluginManager;
use Batoi\Press\Core\Request;
use Batoi\Press\Core\Response;
use Batoi\Press\Security\AdminAccess;
use Batoi\Press\Security\Csrf;

final class FormController
{
    public function __construct(private readonly Config $config, private readonly Csrf $csrf, private readonly AuditLog $audit, private readonly array $user) {}

    public function handle(Request $request): Response
    {
        if (!in_array(AdminAccess::role($this->user), ['owner','admin'], true)) return Response::html(AdminLayout::message('Forms', 'Form management requires an administrator.', true), 403);
        $repo = new FormRepository($this->config->paths()); $error = ''; $status = 200; $input = null;
        $id = $request->input('id');
        if ($request->method === 'GET' && $request->input('preview') !== '') {
            try {
                $form = $repo->find($request->input('preview'));
                if ($form === null) return Response::html('Form not found.', 404);
                return (new \Batoi\Press\Application\FormController($this->config))->preview($form);
            } catch (\RuntimeException) { return Response::html('Form preview unavailable.', 503); }
        }

        if ($request->method === 'GET' && $request->input('export') === 'csv' && $id !== '') {
            try {
                $form = $repo->find($id);
                if ($form === null) return Response::html('Form not found.', 404);
                $records = $this->filteredSubmissions($repo->submissions($id), $request->input('q'));
                $this->audit->record((string)$this->user['username'], 'forms.exported', $id, details: ['count' => count($records)]);
                return $this->export($form, $records);
            } catch (\RuntimeException) { return Response::html('Submission export unavailable.', 503); }
        }

        if ($request->method === 'POST') {
            if (!$this->csrf->validate($request->input('csrf_token'))) return Response::html(AdminLayout::message('Forms', 'Security token expired.', true), 400);
            try {
                if ($request->input('action') === 'process') {
                    (new FormDeliveryQueue($this->config))->process();
                    $this->audit->record((string)$this->user['username'], 'forms.delivery_processed', 'queue');
                } elseif ($request->input('action') === 'import_contact') {
                    $saved = $repo->importContact($request->input('id', 'contact-form'));
                    $this->audit->record((string)$this->user['username'], 'forms.contact_imported', $saved['id']);
                    return Response::redirect('/admin/forms?id=' . rawurlencode($saved['id']));
                } else {
                    foreach (['field_id','field_label','field_type','field_choices','field_length','field_required','field_sensitive'] as $key) {
                        $column = $request->post[$key] ?? [];
                        if (!is_array($column) || count(array_filter($column, 'is_scalar')) !== count($column)) throw new \RuntimeException('Invalid form field inputs.');
                    }
                    $fields = [];
                    foreach ((array)($request->post['field_id'] ?? []) as $i => $fieldId) {
                        if (!is_string($fieldId) || trim($fieldId) === '') continue;
                        $fields[] = ['id' => trim($fieldId), 'label' => $request->post['field_label'][$i] ?? '', 'type' => $request->post['field_type'][$i] ?? '', 'choices' => $request->post['field_choices'][$i] ?? '', 'max_length' => $request->post['field_length'][$i] ?? 254, 'required' => $request->post['field_required'][$i] ?? '0', 'sensitive' => $request->post['field_sensitive'][$i] ?? '0'];
                    }
                    $input = array_filter($request->post, 'is_scalar'); $input['fields'] = $fields;
                    $normalized = $repo->normalize($input);
                    if ($normalized['enabled']) (new FormDeliveryQueue($this->config))->assertReady($normalized);
                    $saved = $repo->save($input, $request->input('expected_revision'));
                    $this->audit->record((string)$this->user['username'], 'forms.saved', $saved['id']);
                    return Response::redirect('/admin/forms?id=' . rawurlencode($saved['id']));
                }
            } catch (\RuntimeException $exception) { $error = $exception->getMessage(); $status = str_contains($error, 'changed') ? 409 : 422; }
        }
        $html = AdminLayout::pageHeader('Forms', 'Create reusable forms and manage private submissions.', AdminLayout::buttonLink('New form', '/admin/forms?new=1', 'file'));
        if (!(new PluginManager($this->config->paths()))->enabled('forms')) $html .= '<p class="bp-notice">Custom forms is disabled. The owner can enable it in <a href="/admin/plugins">Plugins</a>. Existing contact forms continue to work.</p>';
        if ($error !== '') $html .= '<p class="bp-error" role="alert">' . AdminLayout::e($error) . '</p>';
        $rows = '<div class="bp-table-wrap"><table class="bp-table"><thead><tr><th scope="col">Form</th><th scope="col">Delivery</th><th scope="col">Status</th><th scope="col">Actions</th></tr></thead><tbody>';
        foreach ($repo->all() as $form) $rows .= '<tr><td>' . AdminLayout::e($form['title']) . '</td><td>' . AdminLayout::e($form['action']) . '</td><td>' . ($form['enabled'] ? 'Published' : 'Draft') . '</td><td><a href="/admin/forms?id=' . rawurlencode($form['id']) . '">Edit and submissions</a> · <a href="/forms/' . rawurlencode($form['id']) . '" target="_blank" rel="noopener">View form</a> · <a href="/admin/forms?preview=' . rawurlencode($form['id']) . '" target="_blank" rel="noopener">Preview</a></td></tr>';
        if ($repo->all() === []) $rows .= '<tr><td colspan="4">No forms yet. Create a form to begin.</td></tr>';
        $html .= AdminLayout::section('Your forms', $rows . '</tbody></table></div>');
        $html .= AdminLayout::section('Existing contact form', '<p>Create a draft with the existing name, email, subject and message fields. Mail and human-verification settings are reused. The current contact page continues to work.</p><form method="post" class="bp-form">' . $this->csrf->field() . '<input type="hidden" name="action" value="import_contact"><label>New form ID<input name="id" value="contact-form" pattern="[a-z][a-z0-9-]{0,63}" required></label><button type="submit">Create contact draft</button></form>');
        if ($id !== '' || $request->input('new') === '1' || $input !== null) {
            try { $stored = $id !== '' ? $repo->find($id) : null; } catch (\RuntimeException) { $stored = null; }
            $form = $input ?? $stored ?? ['id'=>'','title'=>'','description'=>'','fields'=>[['id'=>'name','label'=>'Name','type'=>'text','required'=>true],['id'=>'email','label'=>'Email','type'=>'email','required'=>true],['id'=>'message','label'=>'Message','type'=>'textarea','required'=>true]],'action'=>'store','retention_days'=>30];
            $html .= $this->editor($form, $request->input('expected_revision', ContentRevision::for($stored ?? [])));
            if ($stored !== null) {
                $query = substr($request->input('q'), 0, 200);
                $records = $this->filteredSubmissions(array_reverse($repo->submissions($id)), $query);
                $pages = max(1, (int)ceil(count($records) / 50));
                $page = max(1, min($pages, (int)$request->input('page', '1')));
                $base = '/admin/forms?' . http_build_query(['id' => $id, 'q' => $query]);
                $submissions = '<p>Expired submissions are removed on access. Sensitive fields are redacted before storage.</p><form method="get" class="bp-form"><input type="hidden" name="id" value="' . AdminLayout::e($id) . '"><label>Search stored values<input name="q" maxlength="200" value="' . AdminLayout::e($query) . '"></label><button type="submit">Search</button></form><p>' . count($records) . ' submissions · Page ' . $page . ' of ' . $pages . ' · <a href="' . AdminLayout::e($base . '&export=csv') . '">Export matching submissions (CSV)</a></p><div class="bp-table-wrap"><table class="bp-table"><thead><tr><th scope="col">Received</th><th scope="col">Values</th></tr></thead><tbody>';
                foreach (array_slice($records, ($page - 1) * 50, 50) as $submission) $submissions .= '<tr><td>' . AdminLayout::e(gmdate('Y-m-d H:i', $submission['created_at'])) . ' UTC</td><td><pre>' . AdminLayout::e(json_encode($submission['values'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . '</pre></td></tr>';
                if ($records === []) $submissions .= '<tr><td colspan="2">No submissions match this search.</td></tr>';
                $submissions .= '</tbody></table></div><nav aria-label="Submission pages">';
                if ($page > 1) $submissions .= '<a href="' . AdminLayout::e($base . '&page=' . ($page - 1)) . '">Previous</a> ';
                if ($page < $pages) $submissions .= '<a href="' . AdminLayout::e($base . '&page=' . ($page + 1)) . '">Next</a>';
                $html .= AdminLayout::section('Submissions', $submissions . '</nav>');
            }
        }
        $jobs = (new FormDeliveryQueue($this->config))->status();
        $queue = '<p>Queued delivery runs only when processed here or by an optional server task. Each click attempts one delivery, with up to five automatic retry attempts across later runs.</p><form method="post">' . $this->csrf->field() . '<input type="hidden" name="action" value="process"><button type="submit">Process next delivery</button></form><ul>';
        foreach (array_slice(array_reverse($jobs), 0, 30) as $job) $queue .= '<li>' . AdminLayout::e($job['id']) . ' — ' . AdminLayout::e($job['status']) . ' (' . (int)$job['attempts'] . ' attempts)</li>';
        $html .= AdminLayout::section('Delivery queue', $queue . '</ul>');
        return Response::html(AdminLayout::render('Forms', $html), $status);
    }

    private function filteredSubmissions(array $records, string $query): array
    {
        $query = substr($query, 0, 200);
        if ($query === '') return $records;
        return array_values(array_filter($records, static function (array $record) use ($query): bool {
            $text = implode("\n", $record['values']);
            return function_exists('mb_stripos') ? mb_stripos($text, $query, 0, 'UTF-8') !== false : stripos($text, $query) !== false;
        }));
    }

    private function export(array $form, array $records): Response
    {
        $stream = fopen('php://temp', 'w+');
        if ($stream === false) throw new \RuntimeException('Unable to prepare export.');
        try {
            $safe = static function (string $value): string {
                // Spreadsheet consumers must treat submitted text as data, including leading controls.
                return preg_match('/^[\x00-\x20]*[=+@-]/', $value) ? "'" . $value : $value;
            };
            $keys = array_column($form['fields'], 'id');
            fputcsv($stream, ['Submission ID', 'Received UTC', ...$keys], ',', '"', '');
            foreach ($records as $record) {
                $row = [$record['id'], gmdate('Y-m-d H:i:s', $record['created_at'])];
                foreach ($keys as $key) $row[] = $safe((string)($record['values'][$key] ?? ''));
                fputcsv($stream, $row, ',', '"', '');
            }
            rewind($stream);
            $csv = stream_get_contents($stream);
            if ($csv === false) throw new \RuntimeException('Unable to prepare export.');
            return Response::body($csv, 'text/csv; charset=UTF-8')->withHeader('Content-Disposition', 'attachment; filename="' . $form['id'] . '-submissions.csv"')->withHeader('Cache-Control', 'private, no-store')->withHeader('X-Content-Type-Options', 'nosniff');
        } finally { fclose($stream); }
    }

    private function editor(array $form, string $revision): string
    {
        $e = static fn(string $s): string => AdminLayout::e($s);
        $html = '<form method="post" class="bp-form" data-bp-form-builder>' . $this->csrf->field() . '<input type="hidden" name="expected_revision" value="' . $e($revision) . '"><div class="bp-form-grid"><label>Form ID<input name="id" pattern="[a-z][a-z0-9-]{0,63}" value="' . $e((string)$form['id']) . '" required></label><label>Title<input name="title" maxlength="160" value="' . $e((string)$form['title']) . '" required></label><label class="bp-field-wide">Description<textarea name="description" maxlength="1000">' . $e((string)($form['description'] ?? '')) . '</textarea></label><label>Status<select name="enabled"><option value="0">Draft</option><option value="1"' . (!empty($form['enabled']) ? ' selected' : '') . '>Published</option></select></label><label>Action<select name="action">';
        foreach (['store'=>'Store submissions','email'=>'Email through site mail settings','webhook'=>'Webhook connection'] as $value=>$label) $html .= '<option value="' . $value . '"' . (($form['action'] ?? '') === $value ? ' selected' : '') . '>' . $label . '</option>';
        $html .= '</select></label><label>Webhook connection ID<input name="connection" value="' . $e((string)($form['connection'] ?? '')) . '"></label><label>Retention (days)<input type="number" name="retention_days" min="1" max="365" value="' . (int)($form['retention_days'] ?? 30) . '"></label><label>Retain submissions<select name="store"><option value="0">No</option><option value="1"' . (!empty($form['store']) ? ' selected' : '') . '>Yes</option></select></label><label>Success message<input name="success_message" maxlength="500" value="' . $e((string)($form['success_message'] ?? 'Thank you. Your submission has been received.')) . '"></label></div><p class="bp-field-help">Link to /forms/your-form-ID or place a Form block on a page. Field IDs remain stable when labels change. Empty field IDs are ignored. Email delivery needs an email field; queued delivery needs an encryption backend.</p><div class="bp-form-field-list" data-bp-form-fields>';
        for ($i=0; $i < min(20,max(5,count($form['fields'])+2)); $i++) {
            $field = $form['fields'][$i] ?? [];
            $html .= '<fieldset><legend>Field ' . ($i+1) . '</legend><div class="bp-form-grid"><label>Field ID<input name="field_id[]" value="' . $e((string)($field['id'] ?? '')) . '"></label><label>Label<input name="field_label[]" maxlength="300" value="' . $e((string)($field['label'] ?? '')) . '"></label><label>Type<select name="field_type[]">';
            foreach (FormRepository::TYPES as $type) $html .= '<option value="' . $type . '"' . (($field['type'] ?? 'text') === $type ? ' selected' : '') . '>' . ucfirst($type) . '</option>';
            $choices = $field['choices'] ?? [];
            $html .= '</select></label><label>Length limit<input type="number" min="1" max="10000" name="field_length[]" value="' . (int)($field['max_length'] ?? 254) . '"></label><label>Required<select name="field_required[]"><option value="0">No</option><option value="1"' . (!empty($field['required']) ? ' selected' : '') . '>Yes</option></select></label><label>Redact stored value<select name="field_sensitive[]"><option value="0">No</option><option value="1"' . (!empty($field['sensitive']) ? ' selected' : '') . '>Yes</option></select></label><label>Choices (one per line)<textarea name="field_choices[]">' . $e(is_array($choices) ? implode("\n", $choices) : (string)$choices) . '</textarea></label></div></fieldset>';
        }
        return AdminLayout::section('Form builder', $html . '</div><button type="button" class="bp-button-secondary" data-bp-add-form-field hidden>Add field</button><p role="status" data-bp-field-status></p><div class="bp-form-actions"><button type="submit">Save form</button></div></form>');
    }
}
