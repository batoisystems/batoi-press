<?php
declare(strict_types=1);

namespace Batoi\Press\Admin;

use Batoi\Press\Application\{CollectionImport, CollectionSpreadsheet};
use Batoi\Press\Content\{CollectionConflict, CollectionRepository, CollectionSchema};
use Batoi\Press\Core\{AuditLog, CollectionView, Config, Request, Response, RuntimeCapabilities, ThemeManager};
use Batoi\Press\Security\{AdminAccess, Csrf};
use InvalidArgumentException;
use RuntimeException;

final class CollectionController
{
    private const ROUTE = '/admin/collections';
    private readonly CollectionRepository $collections;
    private readonly CollectionImport $imports;
    private readonly bool $manager;

    public function __construct(private readonly Config $config, private readonly Csrf $csrf, private readonly AuditLog $audit, private readonly array $user)
    {
        $this->collections = new CollectionRepository($config->paths());
        $this->imports = new CollectionImport($config->paths(), (string)$user['username']);
        $this->manager = in_array(AdminAccess::role($user), ['owner', 'admin'], true);
    }

    public function handle(Request $request, array $files = []): Response
    {
        if (!in_array($request->method, ['GET', 'POST'], true)) return Response::html('Method not allowed.', 405)->withHeader('Allow', 'GET, POST');
        if (!$this->manager && AdminAccess::role($this->user) !== 'editor') return $this->page('Collections', '<p>Access denied.</p>', 403);
        if ($request->method === 'POST' && !$this->csrf->validate($request->input('csrf_token'))) return $this->page('Collections', '<p>Invalid request token.</p>', 400);
        $id = $request->input('id');
        $action = $request->input('action');
        $collection = null;
        try {
            $collection = $this->collections->get($id);
            if ($collection !== null && !$this->manager && !$collection['editors']) return $this->page('Collections', '<p>Access denied.</p>', 403);
            if (in_array($action, ['schema', 'save_schema', 'export', 'review', 'publish', 'publish_selected', 'publish_latest_import', 'restore'], true) && !$this->manager) {
                return $this->page('Collections', '<p>Only administrators can manage schemas, publication, and exports.</p>', 403);
            }
            if ($request->method === 'GET') {
                if ($id === '' && $action === '') return $this->index();
                if ($action === 'schema' && ($id === '' || $collection !== null)) return $this->schema($collection);
                if ($collection === null) return $this->page('Collections', '<p>Collection not found.</p>', 404);
                return match ($action) {
                    '' => $this->records($request, $collection),
                    'record' => $this->recordForm($collection, $request->input('record')),
                    'import' => $this->importForm($collection),
                    'preview' => $this->preview($collection, $request->input('layout', 'cards')),
                    'review' => $this->publicationReview($collection, max(1, (int)$request->input('page', '1'))),
                    'export' => $this->export($collection, $request->input('format', 'csv')),
                    default => $this->page('Collections', '<p>Unknown collection view.</p>', 404),
                };
            }
            if ($action === 'save_schema') {
                $this->collections->saveSchema($id, $request->input('revision'), $request->input('title'), (array)($request->post['fields'] ?? []), $request->input('editors') === '1');
                return $this->saved($id, 'schema_saved');
            }
            if ($collection === null) return $this->page('Collections', '<p>Collection not found.</p>', 404);
            $revision = $request->input('revision');
            switch ($action) {
                case 'save_record':
                    $this->collections->saveRecord($id, $revision, $request->input('record'), (array)($request->post['values'] ?? []));
                    return $this->saved($id, 'record_saved');
                case 'archive':
                case 'unarchive':
                    $this->collections->archive($id, $revision, $request->input('record'), $action === 'archive');
                    return $this->saved($id, $action === 'archive' ? 'record_archived' : 'record_reactivated');
                case 'publish':
                    $this->collections->publish($id, $revision);
                    return $this->saved($id, 'published');
                case 'publish_selected':
                case 'publish_latest_import':
                    $selection = $action === 'publish_selected' ? (array)($request->post['records'] ?? []) : null;
                    $this->collections->publishRecords($id, $revision, $selection);
                    return $this->saved($id, $action === 'publish_selected' ? 'selected_published' : 'import_published');
                case 'restore':
                    $this->collections->restore($id, $revision);
                    return $this->saved($id, 'publication_restored');
                case 'upload':
                    return $this->upload($collection, $request, $files['spreadsheet'] ?? []);
                case 'map':
                case 'inspect':
                case 'apply_import':
                    return $this->importStep($collection, $request, $action);
                default:
                    return $this->page('Collections', '<p>Unknown collection action.</p>', 404);
            }
        } catch (CollectionConflict $error) {
            return $this->problem($collection, $error->getMessage(), 409);
        } catch (InvalidArgumentException $error) {
            if ($action === 'save_record' && $collection !== null) return $this->recordForm($collection, $request->input('record'), (array)($request->post['values'] ?? []), $error->getMessage());
            if ($action === 'save_schema') return $this->schema($collection, $request, $error->getMessage());
            return $this->problem($collection, $error->getMessage(), 422);
        } catch (RuntimeException) {
            return $this->problem($collection, 'Collection storage is unavailable. Check the server’s private storage and retry.', 503);
        }
    }

    private function index(): Response
    {
        $rows = '';
        foreach ($this->collections->summaries() as $collection) {
            if (!$this->manager && !$collection['editors']) continue;
            $rows .= '<tr><th scope="row"><a href="' . $this->url($collection['id']) . '">' . self::e($collection['title']) . '</a></th><td>' . $collection['count'] . '</td><td>' . ($collection['published'] ? 'Published' : 'Draft') . '</td></tr>';
        }
        $actions = $this->manager ? AdminLayout::buttonLink('New collection', self::ROUTE . '?action=schema', 'plus') : '';
        $body = AdminLayout::pageHeader('Collections', 'Manage structured records for public pages.', $actions);
        $body .= $rows === '' ? '<p class="bp-notice">No collections are available.</p>' : '<div class="bp-table-wrap"><table class="bp-table"><thead><tr><th scope="col">Collection</th><th scope="col">Records</th><th scope="col">Publication</th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
        return $this->page('Collections', $body);
    }

    private function schema(?array $collection, ?Request $request = null, string $error = ''): Response
    {
        $title = $collection !== null ? 'Edit fields' : 'New collection';
        $id = $collection['id'] ?? $request?->input('id') ?? '';
        $rows = [];
        foreach ($collection['fields'] ?? [] as $key => $field) $rows[] = ['key' => $key, 'choices' => implode("\n", $field['choices'])] + $field;
        if ($request !== null) $rows = array_slice(array_filter((array)($request->post['fields'] ?? []), 'is_array'), 0, CollectionSchema::MAX_FIELDS);
        if ($rows === []) $rows = [[]];
        $body = AdminLayout::pageHeader($title, 'Choose the fields editors can enter and the fields approved for publication.', AdminLayout::buttonLink('Back to records', $this->path($collection['id'] ?? ''), 'back', true));
        $body .= $this->notice($error) . $this->form('save_schema', $collection ?? [], ' data-bp-collection-schema="' . CollectionSchema::MAX_FIELDS . '"');
        if ($collection === null) $body .= '<label>Collection key<input name="id" required maxlength="64" pattern="[a-z][a-z0-9_\-]{0,63}" value="' . self::e($id) . '" placeholder="members"><span class="bp-field-help">Permanent key used by page blocks.</span></label>';
        $body .= '<label>Collection name<input name="title" required maxlength="120" value="' . self::e($request?->input('title') ?? ($collection['title'] ?? '')) . '"></label>';
        $body .= '<label class="bp-checkbox-label"><input type="checkbox" name="editors" value="1"' . (($request !== null ? $request->input('editors') === '1' : ($collection['editors'] ?? false)) ? ' checked' : '') . '> Allow editors to manage draft records</label>';
        $body .= '<div data-bp-collection-schema-fields>';
        foreach (array_values($rows) as $index => $row) $body .= $this->fieldRow((string)$index, $row, isset($collection['fields'][$this->scalar($row['key'] ?? '')]));
        $body .= '</div><template data-bp-collection-schema-template>' . $this->fieldRow('__INDEX__', [], false) . '</template>';
        $body .= '<div class="bp-form-actions"><button class="bp-button bp-button-secondary" type="button" data-bp-collection-add-field>Add field</button>' . AdminLayout::submitButton('Save collection', 'save') . '</div></form>';
        return $this->page($title, $body, $error !== '' ? 422 : 200);
    }

    private function fieldRow(string $index, array $row, bool $existing): string
    {
        $name = 'fields[' . $index . ']';
        $key = self::e($this->scalar($row['key'] ?? ''));
        $body = '<fieldset class="bp-collection-fieldset"><legend>Field</legend><div class="bp-form-grid"><label>Key<input name="' . $name . '[key]" maxlength="64" pattern="[a-z][a-z0-9_\-]{0,63}" value="' . $key . '"' . ($existing ? ' readonly' : '') . '></label>';
        $body .= '<label>Label<input name="' . $name . '[label]" maxlength="120" value="' . self::e($this->scalar($row['label'] ?? '')) . '"></label>';
        $body .= '<label>Type<select name="' . $name . '[type]" data-bp-collection-field-type>' . $this->options(CollectionSchema::TYPES, $this->scalar($row['type'] ?? 'text')) . '</select></label>';
        $body .= '<label data-bp-collection-choices>Choice options<textarea name="' . $name . '[choices]" rows="3" maxlength="12200" placeholder="One choice per line">' . self::e($this->scalar($row['choices'] ?? '')) . '</textarea></label></div>';
        $body .= '<div class="bp-collection-field-flags">';
        foreach (['required' => 'Required', 'unique' => 'Unique', 'public' => 'Public'] as $flag => $label) {
            $body .= '<label><input type="checkbox" name="' . $name . '[' . $flag . ']" value="1"' . (!empty($row[$flag]) ? ' checked' : '') . '> ' . $label . '</label>';
        }
        if (!$existing) $body .= '<button type="button" class="bp-button bp-button-secondary" data-bp-collection-remove-field>Remove field</button>';
        return $body . '</div></fieldset>';
    }

    private function records(Request $request, array $collection): Response
    {
        $id = $collection['id'];
        $fields = $collection['fields'];
        $query = substr($request->input('q'), 0, 160);
        $filter = $request->input('field');
        $filterValue = $request->input('value');
        $sort = $request->input('sort');
        $state = $request->input('state', 'active');
        $records = array_filter($collection['records'], static function (array $record) use ($query, $filter, $filterValue, $state, $fields): bool {
            if ($state !== 'all' && $record['archived'] !== ($state === 'archived')) return false;
            if (isset($fields[$filter]) && (string)$record['values'][$filter] !== $filterValue) return false;
            if ($query === '') return true;
            foreach ($record['values'] as $value) {
                if ((function_exists('mb_stripos') && preg_match('//u', $query) ? mb_stripos((string)$value, $query, 0, 'UTF-8') : stripos((string)$value, $query)) !== false) return true;
            }
            return false;
        });
        if (isset($fields[$sort])) uasort($records, static fn (array $a, array $b): int => strnatcasecmp((string)$a['values'][$sort], (string)$b['values'][$sort]));
        $pages = max(1, (int)ceil(count($records) / 25));
        $page = min($pages, max(1, (int)$request->input('page', '1')));
        $visible = array_slice($fields, 0, 4, true);
        $body = AdminLayout::pageHeader($collection['title'], 'Edit draft records and review how they will appear on the site.',
            AdminLayout::buttonLink('Add record', $this->path($id, ['action' => 'record']), 'plus') . AdminLayout::buttonLink('Import or paste', $this->path($id, ['action' => 'import']), 'upload', true));
        $messages = ['schema_saved' => 'Collection saved.', 'record_saved' => 'Draft record saved.', 'record_archived' => 'Record archived in the draft.', 'record_reactivated' => 'Record reactivated in the draft.', 'published' => 'Collection published.', 'publication_restored' => 'Previous publication restored.', 'imported' => 'Import applied to the draft.'];
        $messages += ['selected_published' => 'Selected records published.', 'import_published' => 'Pending records from the latest import published.'];
        $message = $messages[$request->input('notice')] ?? '';
        if ($message !== '') $body .= '<p class="bp-notice" role="status">' . self::e($message) . '</p>';
        $actions = AdminLayout::buttonLink('All collections', self::ROUTE, 'back', true) . AdminLayout::buttonLink('Preview draft', $this->path($id, ['action' => 'preview']), 'site', true);
        if ($this->manager) {
            $actions .= AdminLayout::buttonLink('Edit fields', $this->path($id, ['action' => 'schema']), 'edit', true)
                . AdminLayout::buttonLink('Export CSV', $this->path($id, ['action' => 'export']), 'download', true);
            if (RuntimeCapabilities::available('collection_xlsx')) $actions .= AdminLayout::buttonLink('Export XLSX', $this->path($id, ['action' => 'export', 'format' => 'xlsx']), 'download', true);
        }
        $body .= '<div class="bp-form-actions bp-collection-toolbar">' . $actions . '</div>';
        $projection = CollectionRepository::projection($collection);
        $published = $collection['published'];
        $pending = $published === null || $projection !== array_intersect_key($published, $projection);
        if ($pending && $published !== null) {
            $review = CollectionRepository::publicationReview($collection);
            $pending = $review['error'] !== '' || $review['changes'] !== [];
        }
        $status = $published ? 'Published ' . (function_exists('bp_date') ? \bp_date($published['at']) : $published['at']) : 'Unpublished';
        $body .= '<div class="bp-collection-publication"><p><strong>' . self::e($status) . '</strong>' . ($pending && $published ? ' · Draft changes pending' : '') . '</p>';
        if ($this->manager) {
            if ($pending && ($projection['fields'] !== [] || $published !== null)) $body .= AdminLayout::buttonLink('Review draft changes', $this->path($id, ['action' => 'review']), 'check');
            if ($collection['previous'] !== null) $body .= $this->form('restore', $collection) . AdminLayout::submitButton('Restore previous publication', 'history', 'class="bp-button bp-button-secondary"') . '</form>';
        }
        $body .= '</div>';
        $labels = array_column($fields, 'label');
        $labels = array_combine(array_keys($fields), $labels);
        $body .= '<form method="get" action="' . $this->url() . '" class="bp-collection-filters"><input type="hidden" name="id" value="' . self::e($id) . '">'
            . '<label>Search<input name="q" maxlength="160" value="' . self::e($query) . '"></label><label>Status<select name="state">' . $this->options(['active' => 'Active', 'archived' => 'Archived', 'all' => 'All'], $state) . '</select></label>'
            . '<label>Filter field<select name="field">' . $this->options(['' => 'Any field'] + $labels, $filter) . '</select></label><label>Equals<input name="value" maxlength="2000" value="' . self::e($filterValue) . '"></label>'
            . '<label>Sort by<select name="sort">' . $this->options(['' => 'Entry order'] + $labels, $sort) . '</select></label><button class="bp-button bp-button-secondary">Apply</button></form>';
        $body .= '<p>' . count($records) . ' matching records</p>';
        if ($records === []) return $this->page($collection['title'], $body . '<p class="bp-notice">No records match this view.</p>');
        $body .= '<div class="bp-table-wrap"><table class="bp-table"><thead><tr>';
        foreach ($visible as $field) $body .= '<th scope="col">' . self::e($field['label']) . '</th>';
        $body .= '<th scope="col">Status</th><th scope="col">Actions</th></tr></thead><tbody>';
        foreach (array_slice($records, ($page - 1) * 25, 25, true) as $key => $record) {
            $body .= '<tr>';
            foreach ($visible as $field => $definition) $body .= '<td>' . self::e((string)$record['values'][$field]) . '</td>';
            $body .= '<td>' . ($record['archived'] ? 'Archived' : 'Active') . '</td><td><a href="' . $this->url($id, ['action' => 'record', 'record' => $key]) . '">Edit</a></td></tr>';
        }
        $body .= '</tbody></table></div>';
        $params = ['q' => $query, 'state' => $state, 'field' => $filter, 'value' => $filterValue, 'sort' => $sort];
        if ($pages > 1) {
            $body .= '<nav class="bp-form-actions" aria-label="Record pages">';
            if ($page > 1) $body .= '<a href="' . $this->url($id, $params + ['page' => $page - 1]) . '">Previous</a>';
            $body .= '<span>Page ' . $page . ' of ' . $pages . '</span>';
            if ($page < $pages) $body .= '<a href="' . $this->url($id, $params + ['page' => $page + 1]) . '">Next</a>';
            $body .= '</nav>';
        }
        return $this->page($collection['title'], $body);
    }

    private function publicationReview(array $collection, int $page): Response
    {
        $id = $collection['id'];
        $review = CollectionRepository::publicationReview($collection);
        $changes = $review['changes'];
        $pages = max(1, (int)ceil(count($changes) / 25));
        $page = min($page, $pages);
        $body = AdminLayout::pageHeader('Draft vs. Published', $collection['title'], AdminLayout::buttonLink('Back to records', $this->path($id), 'back', true));
        if ($review['error'] !== '') $body .= '<p class="bp-notice">' . self::e($review['error']) . '</p>';
        elseif ($changes === []) $body .= '<p>No pending changes to public records.</p>';
        else {
            $body .= '<p>' . count($changes) . (count($changes) === 1 ? ' pending record.' : ' pending records.')
                . ' Choose changes from this page to publish. Other records keep their published values.</p>';
            $body .= $this->form('publish_selected', $collection) . '<div class="bp-table-wrap"><table class="bp-table bp-collection-review"><thead><tr>'
                . '<th scope="col">Select</th><th scope="col">Change</th><th scope="col">Published</th><th scope="col">Draft</th></tr></thead><tbody>';
            foreach (array_slice($changes, ($page - 1) * 25, 25, true) as $recordId => $change) {
                $before = $change['before'];
                $after = $change['after'];
                $first = array_key_first($review['fields']);
                $label = $after[$first] ?? $before[$first] ?? $recordId;
                $fields = $review['fields'];
                if ($before !== null && $after !== null) $fields = array_filter($fields, static fn (string $key): bool => ($before[$key] ?? null) !== ($after[$key] ?? null), ARRAY_FILTER_USE_KEY);
                $operation = $before === null ? 'Add' : ($after === null ? 'Remove' : 'Update');
                $body .= '<tr><td><input type="checkbox" name="records[]" value="' . self::e($recordId) . '" aria-label="' . self::e('Select ' . $label) . '"></td>'
                    . '<th scope="row">' . self::e($label) . '<br><small>' . $operation . (in_array($recordId, $review['latest'], true) ? ' · Latest import' : '') . '</small></th>'
                    . '<td>' . $this->reviewValues($before, $fields) . '</td><td>' . $this->reviewValues($after, $fields) . '</td></tr>';
            }
            $body .= '</tbody></table></div>' . AdminLayout::submitButton('Publish selected records', 'check') . '</form>';
            if ($pages > 1) {
                $body .= '<nav class="bp-form-actions" aria-label="Review pages">';
                if ($page > 1) $body .= '<a href="' . $this->url($id, ['action' => 'review', 'page' => $page - 1]) . '">Previous</a>';
                $body .= '<span>Page ' . $page . ' of ' . $pages . '</span>';
                if ($page < $pages) $body .= '<a href="' . $this->url($id, ['action' => 'review', 'page' => $page + 1]) . '">Next</a>';
                $body .= '</nav>';
            }
            if ($review['latest'] !== []) $body .= $this->form('publish_latest_import', $collection)
                . '<p>Publish ' . count($review['latest']) . (count($review['latest']) === 1 ? ' pending record' : ' pending records')
                . ' from the latest import. Review records with other draft edits individually.</p>'
                . AdminLayout::submitButton('Publish latest import', 'check', 'class="bp-button bp-button-secondary"') . '</form>';
        }
        if (($review['error'] !== '' || $changes !== []) && ($review['fields'] !== [] || $collection['published'] !== null)) {
            $body .= $this->form('publish', $collection) . '<p>Publishes all pending public records and schema changes.</p>'
                . AdminLayout::submitButton('Publish entire draft', 'check', 'class="bp-button bp-button-secondary"') . '</form>';
        }
        return $this->page('Draft vs. Published', $body);
    }

    private function reviewValues(?array $values, array $fields): string
    {
        if ($values === null) return '—';
        $html = '<dl>';
        foreach ($fields as $key => $field) $html .= '<dt>' . self::e($field['label']) . '</dt><dd>' . self::e($values[$key] ?? '—') . '</dd>';
        return $html . '</dl>';
    }

    private function recordForm(array $collection, string $key, ?array $submitted = null, string $error = ''): Response
    {
        $record = $collection['records'][$key] ?? null;
        if ($key !== '' && $record === null) return $this->problem($collection, 'Record not found.', 404);
        $title = $record === null ? 'Add record' : 'Edit record';
        $body = AdminLayout::pageHeader($title, $collection['title'], AdminLayout::buttonLink('Back to records', $this->path($collection['id']), 'back', true));
        $body .= $this->notice($error) . $this->form('save_record', $collection) . $this->hidden('record', $key) . '<div class="bp-form-grid">';
        foreach ($collection['fields'] as $fieldKey => $field) {
            $name = 'values[' . $fieldKey . ']';
            $value = $this->scalar(($submitted ?? $record['values'] ?? [])[$fieldKey] ?? '');
            $attr = ' name="' . $name . '"' . ($field['required'] ? ' required' : '');
            $body .= '<label>' . self::e($field['label']);
            $body .= match ($field['type']) {
                'choice' => '<select' . $attr . '>' . $this->options(['' => 'Select a value'] + array_combine($field['choices'], $field['choices']), $value) . '</select>',
                'multiline' => '<textarea' . $attr . ' maxlength="2000" rows="4">' . self::e($value) . '</textarea>',
                default => '<input' . $attr . ' type="' . ($field['type'] === 'date' ? 'date' : 'text') . '" maxlength="2000" value="' . self::e($value) . '"' . ($field['type'] === 'number' ? ' inputmode="decimal"' : '') . '>',
            };
            if (!$field['public']) $body .= '<span class="bp-field-help">Private field</span>';
            if ($field['type'] === 'media') $body .= '<span class="bp-field-help">Paste a site asset URL from <a href="' . self::e(\bp_url('/admin/media')) . '" target="_blank" rel="noopener">Media</a>.</span>';
            $body .= '</label>';
        }
        $body .= '</div><div class="bp-form-actions">' . AdminLayout::submitButton('Save draft record', 'save') . '</div></form>';
        if ($record !== null) {
            $body .= $this->form($record['archived'] ? 'unarchive' : 'archive', $collection) . $this->hidden('record', $key)
                . '<button class="bp-button bp-button-secondary">' . ($record['archived'] ? 'Reactivate in draft' : 'Archive in draft') . '</button></form>';
        }
        return $this->page($title, $body, $error !== '' ? 422 : 200);
    }

    private function importForm(array $collection): Response
    {
        $xlsx = RuntimeCapabilities::available('collection_xlsx');
        $body = '<div class="bp-form-grid"><section><h2>Upload a file</h2>' . $this->form('upload', $collection, ' enctype="multipart/form-data"')
            . '<label>Spreadsheet<input type="file" name="spreadsheet" accept=".csv' . ($xlsx ? ',.xlsx' : '') . '" required></label>'
            . $this->delimiter('csv') . '<p class="bp-field-help">Up to 2 MB and 2,000 rows. Use the first row for column headers.' . ($xlsx ? '' : ' Excel import is unavailable on this host. Save the workbook as CSV to continue.') . '</p>'
            . AdminLayout::submitButton('Continue to mapping', 'upload') . '</form></section>'
            . '<section><h2>Paste multiple records</h2>' . $this->form('upload', $collection)
            . '<label>Rows with headers<textarea name="pasted" rows="8" required placeholder="Paste cells from your spreadsheet"></textarea></label>'
            . $this->delimiter('tsv') . AdminLayout::submitButton('Continue to mapping', 'arrow-right') . '</form></section></div>';
        return $this->importPage($collection, 'Import records', $body);
    }

    private function upload(array $collection, Request $request, array $file): Response
    {
        if ($request->input('revision') !== $collection['revision']) throw new CollectionConflict('The collection changed. Reopen the import form to continue.');
        $format = $request->input('delimiter', 'csv');
        if (!in_array($format, ['csv', 'tsv', 'semicolon'], true)) throw new InvalidArgumentException('Choose a supported delimiter.');
        if ($file !== [] && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $path = $file['tmp_name'] ?? '';
            if (($file['error'] ?? null) !== UPLOAD_ERR_OK || !is_string($path) || !is_uploaded_file($path) || filesize($path) > CollectionSpreadsheet::MAX_UPLOAD) {
                throw new InvalidArgumentException('Upload a readable spreadsheet up to 2 MB.');
            }
            $extension = strtolower(pathinfo($this->scalar($file['name'] ?? ''), PATHINFO_EXTENSION));
            if (!in_array($extension, ['csv', 'xlsx'], true)) throw new InvalidArgumentException('Choose a CSV or XLSX file.');
            if ($extension === 'xlsx') $format = 'xlsx';
            $bytes = file_get_contents($path);
        } else $bytes = $request->post['pasted'] ?? '';
        if (!is_string($bytes) || trim($bytes) === '' || strlen($bytes) > CollectionSpreadsheet::MAX_UPLOAD) throw new InvalidArgumentException('Provide a spreadsheet or pasted rows up to 2 MB.');
        $stage = $this->imports->start($collection, $bytes, $format);
        if ($format !== 'xlsx') return $this->mapping($collection, $stage, 1);
        $body = $this->form('map', $collection) . $this->hidden('token', $stage['token']) . '<label>Worksheet<select name="sheet">' . $this->options($stage['sheets'], '') . '</select></label>' . AdminLayout::submitButton('Map columns', 'arrow-right') . '</form>';
        return $this->importPage($collection, 'Choose worksheet', $body);
    }

    private function importStep(array $collection, Request $request, string $action): Response
    {
        $stage = $this->imports->read($collection, $request->input('token'));
        $sheet = (int)$request->input('sheet', (string)($stage['sheet'] ?? 1));
        if ($action === 'map') return $this->mapping($collection, $stage, $sheet);
        if ($action === 'apply_import' && !isset($stage['mapping'], $stage['sheet'], $stage['match'])) throw new InvalidArgumentException('Review the import before applying it.');
        $table = CollectionImport::table($stage, $action === 'apply_import' ? $stage['sheet'] : $sheet);
        $mapping = $action === 'apply_import' ? $stage['mapping'] : (array)($request->post['mapping'] ?? []);
        $match = $action === 'apply_import' ? $stage['match'] : $request->input('match');
        $plan = CollectionImport::plan($collection, $table, $mapping, $match);
        if ($action === 'apply_import') {
            if ($plan['errors'] !== [] || $plan['changes'] === []) throw new InvalidArgumentException('This import has no valid changes to apply.');
            $this->collections->applyBatch($collection['id'], $stage['revision'], $plan['changes']);
            try { $this->imports->clear($stage['token']); }
            catch (RuntimeException) { error_log('Press could not clear a completed collection import.'); }
            return $this->saved($collection['id'], 'imported', ['added' => $plan['adds'], 'updated' => $plan['updates']]);
        }
        $stage = $this->imports->prepare($stage, $sheet, $mapping, $match);
        $body = '<p>' . $plan['adds'] . ' to add · ' . $plan['updates'] . ' to update · ' . $plan['unchanged'] . ' unchanged</p>';
        $body .= $this->form('map', $collection) . $this->hidden('token', $stage['token']) . $this->hidden('sheet', (string)$sheet) . '<button class="bp-button bp-button-secondary">Back to mapping</button></form>';
        if ($plan['errors'] !== []) {
            $body .= '<p class="bp-error" role="alert">Resolve ' . count($plan['errors']) . ' errors before importing. Showing up to 30.</p><ul>';
            foreach (array_slice($plan['errors'], 0, 30, true) as $line => $message) $body .= '<li>' . ($line ? 'Row ' . $line . ' — ' : '') . self::e($message) . '</li>';
            return $this->importPage($collection, 'Import preview', $body . '</ul>', 422);
        }
        if ($plan['changes'] === []) return $this->importPage($collection, 'Import preview', $body . '<p>No changes to apply.</p>');
        $body .= '<h2>Sample changes</h2><div class="bp-table-wrap"><table class="bp-table"><thead><tr><th scope="col">Row</th><th scope="col">Action</th>';
        foreach ($collection['fields'] as $field) $body .= '<th scope="col">' . self::e($field['label']) . '</th>';
        $body .= '</tr></thead><tbody>';
        foreach (array_slice($plan['changes'], 0, 8, true) as $line => $change) {
            $body .= '<tr><th scope="row">' . $line . '</th><td>' . ($change['id'] === null ? 'Add' : 'Update') . '</td>';
            foreach ($change['values'] as $value) $body .= '<td>' . self::e((string)$value) . '</td>';
            $body .= '</tr>';
        }
        $body .= '</tbody></table></div>' . $this->form('apply_import', $collection) . $this->hidden('token', $stage['token']) . AdminLayout::submitButton('Apply import to draft', 'check') . '</form>';
        return $this->importPage($collection, 'Import preview', $body);
    }

    private function mapping(array $collection, array $stage, int $sheet): Response
    {
        $table = CollectionImport::table($stage, $sheet);
        $labels = ['_record_id' => 'Record ID (for matching)'] + array_combine(array_keys($collection['fields']), array_column($collection['fields'], 'label'));
        $match = ['' => 'Add new records only', '_record_id' => 'Record ID'];
        foreach ($collection['fields'] as $key => $field) if ($field['unique']) $match[$key] = $field['label'];
        $body = '<p>Map source columns to collection fields. During an update, unmapped fields keep their existing values and mapped blank cells clear optional values.</p>';
        $body .= $this->form('inspect', $collection) . $this->hidden('token', $stage['token']) . $this->hidden('sheet', (string)$sheet)
            . '<label>Match existing records by<select name="match">' . $this->options($match, $stage['match'] ?? '') . '</select></label><fieldset class="bp-collection-fieldset"><legend>Column mapping</legend><div class="bp-form-grid">';
        $used = [];
        $savedMapping = ($stage['sheet'] ?? null) === $sheet ? $stage['mapping'] : [];
        foreach ($table['headers'] as $index => $label) {
            $candidate = strtolower(trim($label));
            $selected = $savedMapping[$index] ?? (isset($labels[$candidate]) ? $candidate : array_search($label, $labels, true));
            if (!is_string($selected) || isset($used[$selected])) $selected = '';
            elseif ($selected !== '') $used[$selected] = true;
            $body .= '<label>' . self::e($label) . ' <span class="bp-field-help">Column ' . ($index + 1) . '</span><select name="mapping[' . $index . ']">' . $this->options(['' => 'Skip'] + $labels, $selected) . '</select></label>';
        }
        $body .= '</div></fieldset>' . AdminLayout::submitButton('Preview changes', 'arrow-right') . '</form>';
        if ($stage['format'] === 'xlsx' && count($stage['sheets']) > 1) {
            $body .= $this->form('map', $collection) . $this->hidden('token', $stage['token']) . '<label>Switch worksheet<select name="sheet">' . $this->options($stage['sheets'], (string)$sheet) . '</select></label><button class="bp-button bp-button-secondary">Load worksheet</button></form>';
        }
        return $this->importPage($collection, 'Map columns', $body);
    }

    private function preview(array $collection, string $layout): Response
    {
        $site = $this->config->site();
        $manager = new ThemeManager($this->config->paths());
        $layouts = $manager->collectionLayouts($manager->activeSlug($site));
        $body = AdminLayout::pageHeader('Preview draft', 'Only fields marked public appear here.', AdminLayout::buttonLink('Back to records', $this->path($collection['id']), 'back', true));
        $body .= '<form method="get" class="bp-form" action="' . $this->url() . '">' . $this->hidden('id', $collection['id']) . $this->hidden('action', 'preview')
            . '<label>Layout<select name="layout">' . $this->options($layouts, $layout) . '</select></label><button class="bp-button bp-button-secondary">Preview layout</button></form>';
        $markup = (new CollectionView($this->config->paths(), $site))->render(['collection_layout' => $layout, 'title' => $collection['title']], CollectionRepository::projection($collection));
        return $this->page('Preview draft', $body . ($markup ?: '<p class="bp-notice">Mark at least one field public to preview this collection.</p>'));
    }

    private function export(array $collection, string $format): Response
    {
        if (!in_array($format, ['csv', 'xlsx'], true)) throw new InvalidArgumentException('Choose CSV or XLSX export.');
        $bytes = CollectionSpreadsheet::export($collection, $format);
        $this->audit->record($this->user['username'], 'collection.export', $collection['id'], '', 'success', ['format' => $format]);
        return Response::body($bytes, $format === 'csv' ? 'text/csv; charset=UTF-8' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $collection['id'] . '.' . $format . '"')->withHeader('X-Content-Type-Options', 'nosniff')->withHeader('Cache-Control', 'private, no-store');
    }

    private function delimiter(string $value): string
    {
        return '<label>Text delimiter<select name="delimiter">' . $this->options(['csv' => 'Comma', 'tsv' => 'Tab', 'semicolon' => 'Semicolon'], $value) . '</select></label>';
    }

    private function importPage(array $collection, string $title, string $body, int $status = 200): Response
    {
        return $this->page($title, AdminLayout::pageHeader($title, $collection['title'], AdminLayout::buttonLink('Back to records', $this->path($collection['id']), 'back', true)) . $body, $status);
    }

    private function form(string $action, array $collection, string $attributes = ''): string
    {
        $html = '<form method="post" action="' . $this->url() . '" class="bp-form"' . $attributes . '>' . $this->csrf->field() . $this->hidden('action', $action);
        if ($collection !== []) $html .= $this->hidden('id', $collection['id']) . $this->hidden('revision', $collection['revision']);
        return $html;
    }

    private function hidden(string $key, string $value): string { return '<input type="hidden" name="' . self::e($key) . '" value="' . self::e($value) . '">'; }

    private function options(array $options, string $current): string
    {
        $html = '';
        foreach ($options as $key => $label) $html .= '<option value="' . self::e((string)$key) . '"' . ((string)$key === $current ? ' selected' : '') . '>' . self::e((string)$label) . '</option>';
        return $html;
    }

    private function saved(string $id, string $action, array $details = []): Response
    {
        try { $this->audit->record($this->user['username'], 'collection.' . $action, $id, '', 'success', $details); }
        catch (RuntimeException) { error_log('Press could not log a completed collection change.'); }
        return Response::redirect($this->path($id, ['notice' => $action]), 303);
    }

    private function problem(?array $collection, string $message, int $status): Response
    {
        return $this->page('Collections', AdminLayout::pageHeader('Collections', '', AdminLayout::buttonLink('Back to collections', $this->path($collection['id'] ?? ''), 'back', true)) . $this->notice($message), $status);
    }

    private function notice(string $message): string { return $message === '' ? '' : '<p class="bp-error" role="alert">' . self::e($message) . '</p>'; }
    private function page(string $title, string $body, int $status = 200): Response { return Response::html(AdminLayout::render($title, $body), $status)->withHeader('Cache-Control', 'private, no-store'); }
    private function path(string $id = '', array $params = []): string { $query = ($id === '' ? [] : ['id' => $id]) + $params; return self::ROUTE . ($query === [] ? '' : '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986)); }
    private function url(string $id = '', array $params = []): string { return self::e(\bp_url($this->path($id, $params))); }
    private function scalar(mixed $value): string { return is_scalar($value) ? (string)$value : ''; }
    private static function e(string $value): string { return AdminLayout::e($value); }
}
