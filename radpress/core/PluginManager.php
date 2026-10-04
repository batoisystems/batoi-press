<?php
declare(strict_types=1);

namespace Batoi\Press\Core;

use RuntimeException;

/** Owner-installed packages remain private and are verified before activation/load. */
final class PluginManager
{
    public const API = '1.0.0';
    private const BUILTIN = [
        'forms' => ['name' => 'Custom forms', 'description' => 'Declarative forms, submissions and existing email delivery.', 'capabilities' => ['forms.email'], 'requirements' => []],
        'webhooks' => ['name' => 'Webhook delivery', 'description' => 'HTTPS integrations with signed payloads and bounded retries.', 'capabilities' => ['forms.webhook'], 'requirements' => ['http', 'encryption']],
    ];

    public function __construct(private readonly Paths $paths, private readonly FileStore $files = new FileStore()) {}

    public static function id(string $id): string
    {
        if (preg_match('/^[a-z][a-z0-9-]{0,63}$/D', $id) !== 1) throw new RuntimeException('Invalid plugin ID.');
        return $id;
    }

    public function recovery(): bool
    {
        return is_file($this->paths->dataPath('plugins/disabled.lock'));
    }

    public function all(): array
    {
        $state = $this->state();
        $plugins = [];
        foreach (self::BUILTIN as $id => $plugin) {
            $plugins[$id] = $plugin + ['id' => $id, 'version' => self::API, 'kind' => 'bundled', 'enabled' => false];
        }
        foreach (glob($this->directory() . '/*/plugin.json') ?: [] as $manifest) {
            try {
                $plugin = $this->inspectDirectory(dirname($manifest));
                if (basename(dirname($manifest)) !== $plugin['id']) throw new RuntimeException('Plugin directory does not match its identity.');
                $plugins[$plugin['id']] = $plugin + ['enabled' => false];
            } catch (RuntimeException) {
                $id = basename(dirname($manifest));
                if (preg_match('/^[a-z][a-z0-9-]{0,63}$/D', $id) === 1 && !isset(self::BUILTIN[$id])) {
                    $plugins[$id] = ['id'=>$id,'name'=>$id,'version'=>'Unverified','kind'=>'invalid','description'=>'Package verification failed. Disable, restore a verified backup or archive the package.','requirements'=>[],'capabilities'=>[],'compatibility_issues'=>['Package verification failed'],'enabled'=>false];
                }
            }
        }
        foreach ($plugins as $id => &$plugin) {
            $plugin['enabled'] = $plugin['kind'] !== 'invalid' && !$this->recovery() && ($state['enabled'][$id] ?? false) === true;
            $plugin['missing'] = array_merge(RuntimeCapabilities::missing($plugin['requirements']), $plugin['compatibility_issues'] ?? []);
            if (in_array($plugin['kind'], ['native','declarative'], true)) {
                try {
                    $configuration = (new PluginState($this->paths))->configuration($plugin);
                    if ($configuration['issues'] !== []) $plugin['missing'][] = 'Configure settings: ' . implode(', ', $configuration['issues']);
                    if ($configuration['schema'] > ($plugin['data_schema'] ?? 1)) $plugin['missing'][] = 'Stored data schema is newer than this package';
                } catch (RuntimeException) { $plugin['missing'][] = 'Private plugin state requires recovery'; }
            }
        }
        unset($plugin);
        return $plugins;
    }

    public function enabled(string $id): bool
    {
        self::id($id);
        if ($this->recovery()) return false;
        $plugin = $this->all()[$id] ?? null;
        return $plugin !== null && $plugin['enabled'] && $plugin['missing'] === [];
    }

    public function toggle(string $id, bool $enabled): void
    {
        self::id($id);
        $plugin = $this->all()[$id] ?? null;
        if ($plugin === null) throw new RuntimeException('Plugin is not installed or failed verification.');
        if ($enabled && ($this->recovery() || $plugin['missing'] !== [])) throw new RuntimeException('Resolve recovery mode or missing capabilities before activation.');
        if ($enabled && $plugin['kind'] !== 'bundled') {
            $state = new PluginState($this->paths);
            if ($state->configuration($plugin)['issues'] !== []) throw new RuntimeException('Configure required or invalid plugin settings before activation.');
            $state->migrate($plugin);
        }
        $this->files->mutateJson($this->statePath(), static function (array $state) use ($id, $enabled): array {
            $state['enabled'][$id] = $enabled;
            $state['updated_at'] = date(DATE_ATOM);
            return $state;
        });
    }

    public function inspectDirectory(string $directory): array
    {
        if (is_link($directory)) throw new RuntimeException('Plugin symlinks are not supported.');
        $manifestPath = $directory . '/plugin.json';
        if (!is_file($manifestPath) || is_link($manifestPath) || filesize($manifestPath) > 65536) throw new RuntimeException('Invalid plugin manifest.');
        $manifest = $this->files->readJson($manifestPath);
        foreach (['id', 'version', 'name', 'api', 'kind'] as $key) if (!is_string($manifest[$key] ?? null)) throw new RuntimeException('Invalid plugin manifest fields.');
        if (isset($manifest['entrypoint']) && !is_string($manifest['entrypoint'])) throw new RuntimeException('Invalid plugin entrypoint.');
        $id = self::id($manifest['id']);
        if (isset(self::BUILTIN[$id]) || ($manifest['schema'] ?? null) !== 1 || ($manifest['api'] ?? '') !== self::API) throw new RuntimeException('Unsupported plugin identity/schema/API.');
        if (!preg_match('/^\d+\.\d+\.\d+$/D', (string)($manifest['version'] ?? '')) || trim((string)($manifest['name'] ?? '')) === '') throw new RuntimeException('Plugin requires a name and semantic version.');
        if (!in_array(($manifest['kind'] ?? ''), ['native', 'declarative'], true)) throw new RuntimeException('Unsupported plugin kind.');
        $compatibility = $manifest['compatibility'] ?? null;
        if (!is_array($compatibility)) throw new RuntimeException('Declare supported PHP and Press version bounds.');
        foreach (['php', 'press'] as $runtime) {
            $range = $compatibility[$runtime] ?? null;
            if (!is_array($range) || !is_string($range['min'] ?? null) || !is_string($range['max_exclusive'] ?? null)
                || !preg_match('/^\d+\.\d+\.\d+$/D', $range['min']) || !preg_match('/^\d+\.\d+\.\d+$/D', $range['max_exclusive'])
                || version_compare($range['min'], $range['max_exclusive'], '>=')) throw new RuntimeException('Invalid plugin compatibility bounds.');
        }
        $requirements = $manifest['requirements'] ?? [];
        if (!is_array($requirements) || !array_is_list($requirements) || count(array_filter($requirements, 'is_string')) !== count($requirements) || array_diff($requirements, array_keys(RuntimeCapabilities::definitions())) !== []) throw new RuntimeException('Unsupported requirements.');
        $capabilities = $manifest['capabilities'] ?? [];
        if (!is_array($capabilities) || !array_is_list($capabilities) || count(array_filter($capabilities, 'is_string')) !== count($capabilities) || array_diff($capabilities, ['content.read','content.propose','media.read','audit.write','blocks.render','admin.routes','forms.email','forms.webhook','events.listen','state.write']) !== []) throw new RuntimeException('Unsupported capabilities.');
        PluginState::validateManifest($manifest);
        $files = $manifest['files'] ?? [];
        if (!is_array($files) || count($files) < 1 || count($files) > 200) throw new RuntimeException('Invalid package inventory.');
        $total = 0;
        foreach ($files as $relative => $checksum) {
            self::relative((string)$relative);
            $file = $directory . '/' . $relative;
            $parent = dirname($file);
            while ($parent !== $directory && strlen($parent) >= strlen($directory)) {
                if (is_link($parent)) throw new RuntimeException('Plugin symlinks are not supported.');
                $parent = dirname($parent);
            }
            if (is_link($file) || !is_file($file) || filesize($file) > 5242880 || !is_string($checksum) || preg_match('/^[a-f0-9]{64}$/D', $checksum) !== 1 || !hash_equals($checksum, (string)hash_file('sha256', $file))) throw new RuntimeException('Plugin file verification failed.');
            $total += filesize($file);
            if ($total > 52428800) throw new RuntimeException('Plugin package is too large.');
        }
        if ($manifest['kind'] === 'native' && (!isset($files[$manifest['entrypoint'] ?? '']) || !str_ends_with((string)$manifest['entrypoint'], '.php'))) throw new RuntimeException('Invalid native entrypoint.');
        $trust = $manifest['trust'] ?? [];
        if (!is_array($trust) || !is_string($trust['key_id'] ?? null) || !is_string($trust['signature'] ?? null)) throw new RuntimeException('Invalid plugin signature metadata.');
        $keyPath = $this->paths->configPath('plugin-keys.json');
        $keys = is_file($keyPath) ? $this->files->readJson($keyPath) : [];
        $key = base64_decode((string)($keys['keys'][$trust['key_id'] ?? ''] ?? ''), true);
        $signature = base64_decode((string)($trust['signature'] ?? ''), true);
        unset($manifest['trust']);
        $payload = json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (!RuntimeCapabilities::available('signatures') || !is_string($key) || strlen($key) !== 32 || !is_string($signature) || strlen($signature) !== 64 || !sodium_crypto_sign_verify_detached($signature, $payload, $key)) throw new RuntimeException('Plugin signature is not trusted.');
        return array_replace($manifest + ['description' => '', 'requirements' => [], 'capabilities' => []], ['compatibility_issues' => $this->compatibilityIssues($compatibility)]);
    }

    public function install(string $archive): array
    {
        if (!RuntimeCapabilities::available('zip') || !RuntimeCapabilities::available('signatures')) throw new RuntimeException('Plugin packages require ZIP and Sodium.');
        if (!is_file($archive) || filesize($archive) > 52428800) throw new RuntimeException('Invalid plugin archive.');
        $zip = new \ZipArchive();
        if ($zip->open($archive) !== true) throw new RuntimeException('Unable to open plugin ZIP.');
        $stage = $this->paths->dataPath('plugins/staging-' . bin2hex(random_bytes(8)));
        if (!mkdir($stage, 0700, true)) { $zip->close(); throw new RuntimeException('Unable to stage plugin.'); }
        try {
            if ($zip->numFiles < 2 || $zip->numFiles > 201) throw new RuntimeException('Invalid archive inventory.');
            $total = 0; $seen = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if (!is_array($stat)) throw new RuntimeException('Invalid archive entry.');
                $name = (string)$stat['name'];
                self::relative($name);
                if (isset($seen[$name]) || $stat['size'] > 5242880) throw new RuntimeException('Duplicate or oversized archive entry.');
                $seen[$name] = true; $total += $stat['size'];
                if ($total > 52428800) throw new RuntimeException('Archive expands beyond limit.');
                $bytes = $zip->getFromIndex($i);
                if (!is_string($bytes) || strlen($bytes) !== $stat['size']) throw new RuntimeException('Unable to read archive entry.');
                $this->files->write($stage . '/' . $name, $bytes);
            }
            $plugin = $this->inspectDirectory($stage);
            if (array_diff(array_keys($seen), array_merge(['plugin.json'], array_keys($plugin['files']))) !== []) throw new RuntimeException('Archive contains undeclared files.');
            $target = $this->directory() . '/' . $plugin['id'];
            // Persist deactivation before any live package files can change.
            if (is_dir($target)) $this->toggle($plugin['id'], false);
            $this->files->mutateJson($this->statePath(), function (array $state) use ($target, $stage, $plugin): array {
                if (!is_dir($this->directory()) && !mkdir($this->directory(), 0700, true)) throw new RuntimeException('Unable to prepare plugin directory.');
                if (is_dir($target)) {
                    $old = $this->inspectDirectory($target);
                    if (version_compare($plugin['version'], $old['version'], '<=')) throw new RuntimeException('Upgrade must increase the plugin version.');
                    $backup = $this->directory() . '/.' . $plugin['id'] . '-before-' . bin2hex(random_bytes(6));
                    if (!rename($target, $backup)) throw new RuntimeException('Unable to snapshot plugin.');
                    $state['backups'][$plugin['id']] = basename($backup);
                    if (!rename($stage, $target)) { rename($backup, $target); throw new RuntimeException('Unable to publish plugin upgrade.'); }
                } elseif (!rename($stage, $target)) throw new RuntimeException('Unable to publish plugin.');
                $state['enabled'][$plugin['id']] = false;
                return $state;
            });
            return $plugin;
        } finally {
            $zip->close();
            if (is_dir($stage)) $this->removeStage($stage);
        }
    }

    public function boot(): PluginContext
    {
        $context = new PluginContext();
        if ($this->recovery()) return $context;
        foreach ($this->all() as $plugin) {
            if (!$plugin['enabled'] || $plugin['missing'] !== [] || $plugin['kind'] !== 'native') continue;
            // Repeat verification at the execution boundary, not just installation.
            $directory = $this->directory() . '/' . $plugin['id'];
            $verified = $this->inspectDirectory($directory);
            try {
                if ((new PluginState($this->paths))->configuration($verified)['issues'] !== []) throw new RuntimeException('Plugin settings require review.');
                $register = (static fn(string $file): mixed => require $file)($directory . '/' . $verified['entrypoint']);
                if (!is_callable($register)) throw new RuntimeException('Plugin entrypoint must return a registration callback.');
                $register($context->scope($this->paths, $verified));
            } catch (\Throwable $error) {
                $this->toggle($plugin['id'], false);
                throw new RuntimeException('A plugin failed and was disabled. Use Plugins to inspect it.', 0, $error);
            }
        }
        return $context;
    }

    public function rollback(string $id): void
    {
        self::id($id);
        $this->toggle($id, false);
        $this->files->mutateJson($this->statePath(), function (array $state) use ($id): array {
            $name = (string)($state['backups'][$id] ?? '');
            if (preg_match('/^\.' . preg_quote($id, '/') . '-before-[a-f0-9]{12}$/D', $name) !== 1) throw new RuntimeException('No verified prior version is available.');
            $backup = $this->directory() . '/' . $name;
            $prior = $this->inspectDirectory($backup);
            (new PluginState($this->paths))->assertRollback($prior);
            $target = $this->directory() . '/' . $id;
            $current = $this->directory() . '/.' . $id . '-before-' . bin2hex(random_bytes(6));
            if (!rename($target, $current)) throw new RuntimeException('Unable to snapshot current plugin.');
            if (!rename($backup, $target)) { rename($current, $target); throw new RuntimeException('Unable to restore prior plugin.'); }
            $state['backups'][$id] = basename($current);
            return $state;
        });
    }

    public function uninstall(string $id): void
    {
        self::id($id);
        if (isset(self::BUILTIN[$id])) throw new RuntimeException('Bundled plugins can be disabled, not uninstalled.');
        $this->toggle($id, false);
        $this->files->mutateJson($this->statePath(), function (array $state) use ($id): array {
            $archive = $this->directory() . '/.' . $id . '-removed-' . bin2hex(random_bytes(6));
            if (!rename($this->directory() . '/' . $id, $archive)) throw new RuntimeException('Unable to archive plugin.');
            unset($state['enabled'][$id]);
            return $state;
        });
    }

    private function compatibilityIssues(array $ranges): array
    {
        $versionPath = $this->paths->configPath('update.json');
        if (!is_file($versionPath)) $versionPath = dirname(__DIR__) . '/config/update.json';
        $press = is_file($versionPath) ? ($this->files->readJson($versionPath)['current_version'] ?? '') : '';
        $issues = [];
        foreach (['php' => PHP_VERSION, 'press' => $press] as $runtime => $version) {
            if (!is_string($version) || $version === '' || version_compare($version, $ranges[$runtime]['min'], '<') || version_compare($version, $ranges[$runtime]['max_exclusive'], '>=')) {
                $issues[] = strtoupper($runtime) . ' must be >= ' . $ranges[$runtime]['min'] . ' and < ' . $ranges[$runtime]['max_exclusive'];
            }
        }
        return $issues;
    }

    public function configuration(string $id): array
    {
        $plugin = $this->all()[self::id($id)] ?? null;
        if ($plugin === null || $plugin['kind'] === 'bundled') throw new RuntimeException('Package configuration is unavailable.');
        return (new PluginState($this->paths))->configuration($plugin);
    }

    public function configure(string $id, array $input, string $revision): void
    {
        $plugin = $this->all()[self::id($id)] ?? null;
        if ($plugin === null || $plugin['kind'] === 'bundled') throw new RuntimeException('Package configuration is unavailable.');
        (new PluginState($this->paths))->saveSettings($plugin, $input, $revision);
    }

    private static function relative(string $path): void
    {
        if (preg_match('#^[a-zA-Z0-9_-]+(?:/[a-zA-Z0-9_.-]+)*\.[a-zA-Z0-9]+$#D', $path) !== 1 || str_contains($path, '..') || $path === 'plugin.json.lock') throw new RuntimeException('Unsafe plugin file path.');
    }

    private function removeStage(string $stage): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($stage, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $entry) $entry->isDir() ? rmdir((string)$entry) : unlink((string)$entry);
        rmdir($stage);
    }

    private function directory(): string { return $this->paths->root() . '/radpress/app/plugins'; }
    private function statePath(): string { return $this->paths->dataPath('plugins/registry.json'); }
    private function state(): array { return is_file($this->statePath()) ? $this->files->readJson($this->statePath()) : []; }
}
