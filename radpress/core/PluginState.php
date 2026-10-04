<?php
declare(strict_types=1);

namespace Batoi\Press\Core;

use Batoi\Press\Application\ContentRevision;
use RuntimeException;

/** Bounded, private plugin-owned state; no executable migration expressions. */
final class PluginState
{
    public function __construct(private readonly Paths $paths, private readonly FileStore $files = new FileStore()) {}

    public static function validateManifest(array $plugin): void
    {
        $schema = $plugin['settings_schema'] ?? [];
        if (!is_array($schema) || count($schema) > 20) throw new RuntimeException('Invalid plugin settings schema.');
        foreach ($schema as $key => $field) {
            self::key((string)$key);
            if (!is_array($field) || !is_string($field['label'] ?? null) || strlen($field['label']) > 160 || !in_array($field['type'] ?? '', ['string','integer','boolean','enum'], true)) throw new RuntimeException('Invalid plugin setting definition.');
            if ($field['type'] === 'enum' && (!is_array($field['choices'] ?? null) || !array_is_list($field['choices']) || count($field['choices']) < 1 || count($field['choices']) > 30 || count(array_filter($field['choices'], 'is_string')) !== count($field['choices']))) throw new RuntimeException('Invalid plugin setting choices.');
            if (isset($field['required']) && !is_bool($field['required'])) throw new RuntimeException('Invalid setting requirement.');
            if ($field['type'] === 'integer') {
                foreach (['min','max'] as $bound) if (isset($field[$bound]) && (!is_int($field[$bound]) || abs($field[$bound]) > 2147483647)) throw new RuntimeException('Invalid setting number bounds.');
                if (($field['min'] ?? -2147483647) > ($field['max'] ?? 2147483647)) throw new RuntimeException('Invalid setting number bounds.');
            }
            if (array_key_exists('default', $field)) self::value($field, $field['default']);
        }
        $version = $plugin['data_schema'] ?? 1;
        if (!is_int($version) || $version < 1 || $version > 100) throw new RuntimeException('Invalid plugin data schema.');
        $steps = $plugin['migrations'] ?? [];
        if (!is_array($steps) || !array_is_list($steps) || count($steps) > 99) throw new RuntimeException('Invalid plugin migrations.');
        $seen = [];
        foreach ($steps as $step) {
            if (!is_array($step) || !is_int($step['from'] ?? null) || ($step['to'] ?? null) !== $step['from'] + 1 || $step['from'] < 1 || $step['to'] > $version || isset($seen[$step['from']])) throw new RuntimeException('Invalid plugin migration sequence.');
            $seen[$step['from']] = true;
            self::data($step['defaults'] ?? []);
            $renames = $step['renames'] ?? [];
            if (!is_array($renames) || count($renames) > 40) throw new RuntimeException('Invalid migration renames.');
            foreach ($renames as $from => $to) { self::key((string)$from); if (!is_string($to)) throw new RuntimeException('Invalid migration rename.'); self::key($to); }
            if (array_diff(array_keys($step), ['from','to','defaults','renames']) !== []) throw new RuntimeException('Executable or unsupported migrations are not accepted.');
        }
    }

    public function read(string $id): array
    {
        $path = $this->path($id);
        $state = is_file($path) ? $this->files->readJson($path) : ['schema'=>1,'settings'=>[],'data'=>[]];
        if (!is_int($state['schema'] ?? null) || $state['schema'] < 1 || !is_array($state['settings'] ?? null) || !is_array($state['data'] ?? null)) throw new RuntimeException('Invalid plugin state.');
        self::data($state['data'] ?? []);
        return $state;
    }

    public function configuration(array $plugin): array
    {
        $state = $this->read($plugin['id']);
        $settings = []; $issues = [];
        foreach ($plugin['settings_schema'] ?? [] as $key => $field) {
            $value = $state['settings'][$key] ?? $field['default'] ?? ($field['type'] === 'boolean' ? false : '');
            try {
                if (($field['required'] ?? false) && $value === '') throw new RuntimeException('Required setting is missing.');
                $settings[$key] = self::value($field, $value);
            } catch (RuntimeException) {
                $settings[$key] = $field['default'] ?? ($field['type'] === 'boolean' ? false : '');
                $issues[] = $key;
            }
        }
        return ['values'=>$settings, 'revision'=>ContentRevision::for($state), 'schema'=>$state['schema'], 'issues'=>$issues];
    }

    public function saveSettings(array $plugin, array $input, string $revision): void
    {
        $definitions = $plugin['settings_schema'] ?? [];
        if (array_diff(array_keys($input), array_keys($definitions)) !== []) throw new RuntimeException('Unknown plugin setting.');
        $values = [];
        foreach ($definitions as $key => $field) {
            $value = $input[$key] ?? $field['default'] ?? ($field['type'] === 'boolean' ? false : '');
            if (($field['required'] ?? false) && $value === '') throw new RuntimeException('Complete required plugin settings.');
            $values[$key] = self::value($field, $value);
        }
        $this->files->mutateJson($this->path($plugin['id']), static function (array $state) use ($values, $revision): array {
            if (!hash_equals(ContentRevision::for($state), $revision)) throw new RuntimeException('Plugin configuration changed. Reload before saving.');
            $state['settings'] = $values;
            return $state;
        }, ['schema'=>1,'settings'=>[],'data'=>[]]);
    }

    public function migrate(array $plugin): void
    {
        $target = $plugin['data_schema'] ?? 1;
        $this->files->mutateJson($this->path($plugin['id']), function (array $state) use ($plugin, $target): array {
            $from = $state['schema'] ?? null;
            if (!is_int($from) || $from > $target) throw new RuntimeException('Plugin data is newer than this package. Restore a reviewed snapshot before activating an older schema.');
            if (!is_array($state['settings'] ?? null)) throw new RuntimeException('Invalid plugin settings storage.');
            self::data($state['data'] ?? null);
            $before = $state;
            while ($from < $target) {
                $step = null;
                foreach ($plugin['migrations'] ?? [] as $candidate) if ($candidate['from'] === $from) $step = $candidate;
                if ($step === null) throw new RuntimeException('Plugin migration path is incomplete. Data was not changed.');
                $data = $state['data'] ?? [];
                foreach ($step['renames'] ?? [] as $old => $new) {
                    if ($old === $new || !array_key_exists($old, $data)) continue;
                    if (array_key_exists($new, $data)) throw new RuntimeException('Plugin migration has conflicting fields. Data was not changed.');
                    $data[$new] = $data[$old]; unset($data[$old]);
                }
                $data += $step['defaults'] ?? [];
                self::data($data); $state['data'] = $data;
                $state['schema'] = ++$from;
            }
            if ($before !== $state) {
                $history = $this->paths->dataPath('plugins/' . PluginManager::id($plugin['id']) . '/history/' . bin2hex(random_bytes(12)) . '.json');
                $this->files->writeJson($history, ['before'=>$before,'after_revision'=>ContentRevision::for($state)]);
                @chmod($history, 0600);
            }
            return $state;
        }, ['schema'=>1,'settings'=>[],'data'=>[]]);
    }

    public function replaceData(string $id, array $data, string $revision, ?int $schema = null): void
    {
        self::data($data);
        $this->files->mutateJson($this->path($id), static function (array $state) use ($data, $revision, $schema): array {
            if ($schema !== null && ($state['schema'] ?? null) !== $schema) throw new RuntimeException('Plugin data schema changed. Reload the plugin before writing.');
            if (!hash_equals(ContentRevision::for($state), $revision)) throw new RuntimeException('Plugin data changed. Read the current revision before writing.');
            $state['data'] = $data;
            return $state;
        }, ['schema'=>1,'settings'=>[],'data'=>[]]);
    }

    public function assertRollback(array $plugin): void
    {
        if ($this->read($plugin['id'])['schema'] > ($plugin['data_schema'] ?? 1)) throw new RuntimeException('Rollback would use an older data schema. Data snapshots are retained for reviewed operator recovery.');
    }

    private static function key(string $key): void
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,39}$/D', $key) !== 1) throw new RuntimeException('Invalid plugin state key.');
    }

    private static function data(mixed $data): void
    {
        if (!is_array($data) || count($data) > 40) throw new RuntimeException('Plugin data must be a bounded flat object.');
        foreach ($data as $key => $value) {
            self::key((string)$key);
            if (!is_string($value) && !is_int($value) && !is_bool($value) && $value !== null) throw new RuntimeException('Plugin data values must be scalar.');
            if (is_string($value) && (strlen($value) > 4096 || preg_match('//u', $value) !== 1)) throw new RuntimeException('Plugin data text is too large or invalid.');
        }
        if (strlen(json_encode($data, JSON_THROW_ON_ERROR)) > 65536) throw new RuntimeException('Plugin data exceeds its storage limit.');
    }

    private static function value(array $field, mixed $value): string|int|bool
    {
        if ($field['type'] === 'boolean') {
            if (!in_array($value, [true,false,0,1,'0','1'], true)) throw new RuntimeException('Plugin boolean settings require yes or no.');
            return in_array($value, [true,1,'1'], true);
        }
        if ($field['type'] === 'integer') {
            $parsed = filter_var($value, FILTER_VALIDATE_INT);
            if ($parsed === false || $parsed < ($field['min'] ?? -2147483648) || $parsed > ($field['max'] ?? 2147483647)) throw new RuntimeException('Plugin number is outside its bounds.');
            return $parsed;
        }
        if (!is_string($value) || strlen($value) > 2000 || preg_match('//u', $value) !== 1 || ($field['type'] === 'enum' && !in_array($value, $field['choices'], true))) throw new RuntimeException('Invalid plugin text setting.');
        return $value;
    }

    private function path(string $id): string { return $this->paths->dataPath('plugins/' . PluginManager::id($id) . '/state.json'); }
}
