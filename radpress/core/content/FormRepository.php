<?php
declare(strict_types=1);

namespace Batoi\Press\Content;

use Batoi\Press\Application\ContentRevision;
use Batoi\Press\Core\FileStore;
use Batoi\Press\Core\Paths;
use Batoi\Press\Core\PluginManager;
use RuntimeException;

final class FormRepository
{
    public const TYPES = ['text', 'email', 'textarea', 'select', 'checkbox', 'consent'];
    public function __construct(private readonly Paths $paths, private readonly FileStore $files = new FileStore()) {}

    public function all(): array
    {
        $path = $this->path();
        return is_file($path) ? ($this->files->readJson($path)['forms'] ?? []) : [];
    }

    public function find(string $id): ?array { return $this->all()[PluginManager::id($id)] ?? null; }

    public function save(array $input, string $expected): array
    {
        $form = $this->normalize($input);
        $id = $form['id'];
        $this->files->mutateJson($this->path(), function (array $document) use ($id, $form, $expected): array {
            $before = $document['forms'][$id] ?? [];
            if (!hash_equals(ContentRevision::for($before), $expected)) throw new RuntimeException('This form changed. Reload before saving.');
            $history = $this->paths->dataPath('versions/forms/' . $id . '/' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.json');
            if ($before !== []) $this->files->writeJson($history, $before);
            $document['schema'] = 1;
            $document['forms'][$id] = $form;
            return $document;
        });
        return $form;
    }

    /** Explicit opt-in conversion; the existing contact route/settings are preserved. */
    public function importContact(string $id): array
    {
        return $this->save(['id'=>$id,'title'=>'Contact','description'=>'Send us a message.','enabled'=>false,'action'=>'email','store'=>false,'fields'=>[
            ['id'=>'name','label'=>'Name','type'=>'text','required'=>true,'max_length'=>120],
            ['id'=>'email','label'=>'Email','type'=>'email','required'=>true,'max_length'=>254],
            ['id'=>'subject','label'=>'Subject','type'=>'text','max_length'=>160],
            ['id'=>'message','label'=>'Message','type'=>'textarea','required'=>true,'max_length'=>10000],
        ]], ContentRevision::for([]));
    }

    public function normalize(array $input): array
    {
        foreach (['id','title','description','action','connection','success_message'] as $key) if (isset($input[$key]) && !is_scalar($input[$key])) throw new RuntimeException('Invalid form text field.');
        $id = PluginManager::id((string)($input['id'] ?? ''));
        $title = trim((string)($input['title'] ?? ''));
        if ($title === '' || strlen($title) > 160) throw new RuntimeException('Enter a form title of up to 160 bytes.');
        $fields = $input['fields'] ?? [];
        if (!is_array($fields) || count($fields) < 1 || count($fields) > 20) throw new RuntimeException('A form needs 1–20 fields.');
        $normalized = []; $seen = [];
        foreach ($fields as $field) {
            if (!is_array($field)) throw new RuntimeException('Invalid field.');
            foreach (['id','type','label'] as $key) if (isset($field[$key]) && !is_scalar($field[$key])) throw new RuntimeException('Invalid field text.');
            if (isset($field['choices']) && (!is_string($field['choices']) && (!is_array($field['choices']) || count(array_filter($field['choices'], 'is_string')) !== count($field['choices'])))) throw new RuntimeException('Choices must be text.');
            $key = (string)($field['id'] ?? '');
            if (preg_match('/^[a-z][a-z0-9_]{0,39}$/D', $key) !== 1 || isset($seen[$key])) throw new RuntimeException('Field IDs must be unique lowercase names.');
            $seen[$key] = true;
            $type = (string)($field['type'] ?? 'text');
            $label = trim((string)($field['label'] ?? ''));
            if (!in_array($type, self::TYPES, true) || $label === '' || strlen($label) > 300) throw new RuntimeException('Invalid field type or label.');
            $choices = array_values(array_filter(array_map('trim', is_array($field['choices'] ?? null) ? $field['choices'] : explode("\n", (string)($field['choices'] ?? '')))));
            if (count($choices) > 30 || ($type === 'select' && $choices === [])) throw new RuntimeException('Select fields need 1–30 choices.');
            foreach ($choices as $choice) if (strlen($choice) > 200) throw new RuntimeException('A choice exceeds 200 bytes.');
            $normalized[] = ['id' => $key, 'type' => $type, 'label' => $label, 'required' => $type === 'consent' || filter_var($field['required'] ?? false, FILTER_VALIDATE_BOOLEAN), 'sensitive' => filter_var($field['sensitive'] ?? false, FILTER_VALIDATE_BOOLEAN), 'choices' => $choices, 'max_length' => max(1, min(10000, (int)($field['max_length'] ?? ($type === 'textarea' ? 5000 : 254))))];
        }
        $action = (string)($input['action'] ?? 'store');
        if (!in_array($action, ['store','email','webhook'], true)) throw new RuntimeException('Invalid form action.');
        $connection = trim((string)($input['connection'] ?? ''));
        if ($action === 'webhook') PluginManager::id($connection);
        return ['schema' => 1, 'id' => $id, 'title' => $title, 'description' => substr((string)($input['description'] ?? ''), 0, 1000), 'enabled' => filter_var($input['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN), 'fields' => $normalized, 'action' => $action, 'connection' => $connection, 'store' => $action === 'store' || filter_var($input['store'] ?? false, FILTER_VALIDATE_BOOLEAN), 'retention_days' => max(1, min(365, (int)($input['retention_days'] ?? 30))), 'success_message' => substr(trim((string)($input['success_message'] ?? 'Thank you. Your submission has been received.')), 0, 500)];
    }

    public function validate(array $form, array $input): array
    {
        $values = []; $errors = [];
        foreach ($form['fields'] as $field) {
            $raw = $input[$field['id']] ?? '';
            $value = is_string($raw) ? trim($raw) : '';
            if (($field['required'] && $value === '') || strlen($value) > $field['max_length'] || preg_match('//u', $value) !== 1) $errors[$field['id']] = 'Complete this field within its length limit.';
            elseif ($value !== '' && $field['type'] === 'email' && !filter_var($value, FILTER_VALIDATE_EMAIL)) $errors[$field['id']] = 'Enter a valid email address.';
            elseif ($value !== '' && $field['type'] === 'select' && !in_array($value, $field['choices'], true)) $errors[$field['id']] = 'Choose an available option.';
            elseif (in_array($field['type'], ['checkbox','consent'], true) && !in_array($value, ['', '1'], true)) $errors[$field['id']] = 'Select this checkbox to confirm.';
            $values[$field['id']] = $value;
        }
        return ['values' => $values, 'errors' => $errors];
    }

    public function submissions(string $id): array
    {
        $form = $this->find($id);
        if ($form === null) return [];
        $path = $this->paths->dataPath('forms/' . $id . '/submissions.json');
        $records = [];
        if (is_file($path)) {
            $document = $this->files->mutateJson($path, static fn(array $data): array => ['submissions' => array_values(array_filter($data['submissions'] ?? [], static fn(array $s): bool => ($s['expires_at'] ?? 0) > time()))]);
            foreach ($document['submissions'] as $record) $records[$record['id']] = $record;
        }
        $queue = $this->paths->dataPath('forms/deliveries.json');
        if (is_file($queue)) {
            $document = $this->files->mutateJson($queue, static function(array $data): array {
                $data['submissions'] = array_values(array_filter($data['submissions'] ?? [], static fn(array $record): bool => $record['expires_at'] > time()));
                return $data;
            });
            foreach ($document['submissions'] as $record) if ($record['form_id'] === $id) {
                unset($record['form_id']);
                $records[$record['id']] = $record;
            }
        }
        return array_values($records);
    }

    public function retain(array $form, string $id, array $values): void
    {
        if (!$form['store']) return;
        foreach ($form['fields'] as $field) if ($field['sensitive']) $values[$field['id']] = '[redacted]';
        $this->files->mutateJson($this->paths->dataPath('forms/' . $form['id'] . '/submissions.json'), static function (array $data) use ($form, $id, $values): array {
            $records = array_values(array_filter($data['submissions'] ?? [], static fn(array $s): bool => ($s['expires_at'] ?? 0) > time()));
            foreach ($records as $record) if ($record['id'] === $id) return ['submissions' => $records];
            if (count($records) >= 1000) throw new RuntimeException('Form submission storage is full.');
            $records[] = ['id' => $id, 'created_at' => time(), 'expires_at' => time() + $form['retention_days'] * 86400, 'values' => $values];
            return ['submissions' => $records];
        });
    }

    private function path(): string { return $this->paths->contentPath('forms/forms.json'); }
}
