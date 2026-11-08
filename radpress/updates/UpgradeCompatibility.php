<?php
declare(strict_types=1);

namespace Batoi\Press\Update;

use Batoi\Press\Core\FileStore;
use Batoi\Press\Core\Paths;
use Batoi\Press\Content\WebsiteDocumentStore;
use Batoi\Press\Application\ContentRevision;
use RuntimeException;

/** Installation migrations performed inside the updater's backup/rollback boundary. */
final class UpgradeCompatibility
{
    public function __construct(private readonly Paths $paths) {}

    public function preflight(array $manifest): void
    {
        if (!$this->usesBundledThemeStore() || $this->themeHashes($manifest) === []) return;
        if (is_link($this->paths->themePath())) throw new RuntimeException('Linked theme storage cannot be preserved automatically.');
        foreach (array_keys($this->themeHashes($manifest)) as $slug) {
            $source = $this->paths->themePath($slug);
            if (is_dir($source)) $this->inventory($source);
        }
    }

    public function migrate(array $manifest, array $server, array &$targets, array &$directories): array
    {
        $sitePath = $this->paths->configPath('site.json');
        $site = $this->readJson($sitePath);
        // Honor document recovery and revision checks on current runtimes. Older
        // installations being bootstrapped predate this store and use FileStore.
        $documents = class_exists(WebsiteDocumentStore::class) ? new WebsiteDocumentStore($this->paths) : null;
        if ($documents !== null) $site = $documents->read('site');
        $before = $site;
        $active = (string)($site['theme'] ?? 'default');
        if (!preg_match('/^[a-z0-9][a-z0-9_-]*$/D', $active) || !is_file($this->paths->themePath($active . '/theme.json'))) $active = 'default';
        $incoming = $this->themeHashes($manifest);
        $baseline = $this->installedThemeHashes();
        $changes = ['themes'=>[], 'preserved_prefixes'=>[]];
        // Release targets are rooted here; a separately configured theme store is untouched.
        if ($this->usesBundledThemeStore()) {
            if ($incoming !== [] && is_link($this->paths->themePath())) throw new RuntimeException('Linked theme storage cannot be preserved automatically.');
            foreach ($incoming as $slug => $hashes) {
                $source = $this->paths->themePath($slug);
                if (!is_dir($source)) continue;
                $actual = $this->inventory($source);
                if ($actual === []) continue;
                if ($actual === $hashes || (isset($baseline[$slug]) && $actual === $baseline[$slug])) continue;
                $theme = $this->readJson($source . '/theme.json');
                // Legacy hard-coded paths cannot safely follow a renamed theme. Keep that
                // complete directory in place rather than mixing it with packaged files.
                if ($this->hasFixedThemePaths($source, $slug, array_keys($actual))) {
                    $changes['preserved_prefixes'][] = 'radpress/theme/' . $slug . '/';
                    $changes['themes'][] = ['from'=>$slug, 'to'=>$slug, 'mode'=>'in_place'];
                    continue;
                }
                $copy = $slug . '-preserved';
                for ($suffix = 2; isset($incoming[$copy]) || file_exists($this->paths->themePath($copy)) || is_link($this->paths->themePath($copy)); $suffix++) $copy = $slug . '-preserved-' . $suffix;
                $destination = $this->paths->themePath($copy);
                $this->copyTheme($source, $destination, $targets, $directories);
                $theme['slug'] = $copy;
                $theme['name'] = (string)($theme['name'] ?? $slug) . ' (preserved)';
                $this->writeJson($destination . '/theme.json', $theme);
                if ($active === $slug) $site['theme'] = $copy;
                $changes['themes'][] = ['from'=>$slug, 'to'=>$copy, 'mode'=>'isolated'];
            }
        } else $changes['preserved_prefixes'][] = 'radpress/theme/';
        $corrected = $this->correctBaseUrl((string)($site['base_url'] ?? ''), $server);
        if (is_string($corrected)) {
            $changes['base_url'] = ['from'=>$site['base_url'], 'to'=>$corrected];
            $site['base_url'] = $corrected;
        } elseif ($corrected === null) {
            $changes['base_url_review'] = true;
        }
        if ($site !== $before) {
            $targets[$sitePath] = is_file($sitePath);
            if ($documents !== null) $documents->commit('site', $site, ContentRevision::for($before));
            else $this->writeJson($sitePath, $site);
        }
        return $changes;
    }

    public function recordInstalledRelease(array $manifest, array &$targets, array &$directories): void
    {
        $path = $this->paths->dataPath('updates/installed-release.json');
        if (!is_dir(dirname($path))) $this->createDirectory(dirname($path), $directories, 0700);
        $targets[$path] = is_file($path);
        $this->writeJson($path, $manifest);
        if (!chmod($path, 0600)) throw new RuntimeException('Unable to protect the installed release record.');
    }

    private function installedThemeHashes(): array
    {
        $config = $this->readJson($this->paths->configPath('update.json'));
        foreach ([$this->paths->dataPath('updates/installed-release.json'), $this->paths->root() . '/release.json'] as $path) {
            if (!is_file($path) || is_link($path)) continue;
            try {
                $manifest = $this->readJson($path);
                if (($manifest['version'] ?? '') !== ($config['current_version'] ?? null)) continue;
                if (ReleaseSignature::verify($manifest, (array)($config['release_public_keys'] ?? []), true, 'package-manifest') !== null) continue;
                return $this->themeHashes($manifest);
            } catch (RuntimeException) { /* Unknown provenance: preserve conservatively. */ }
        }
        return [];
    }

    private function usesBundledThemeStore(): bool
    {
        $configured = realpath($this->paths->themePath());
        $bundled = realpath($this->paths->root() . '/radpress/theme');
        if ($configured === false || $bundled === false) {
            $configured = $this->paths->themePath();
            $bundled = $this->paths->root() . '/radpress/theme';
        }
        $configured = rtrim(str_replace('\\', '/', $configured), '/');
        $bundled = rtrim(str_replace('\\', '/', $bundled), '/');
        return DIRECTORY_SEPARATOR === '\\' ? strtolower($configured) === strtolower($bundled) : $configured === $bundled;
    }

    private function themeHashes(array $manifest): array
    {
        $themes = [];
        foreach ((array)($manifest['files'] ?? []) as $file) {
            if (!is_array($file)) continue;
            $target = str_replace('\\', '/', (string)($file['target'] ?? $file['path'] ?? ''));
            if (preg_match('#^radpress/theme/([a-z0-9][a-z0-9_-]*)/(.+)$#D', $target, $match)) {
                $themes[$match[1]][$match[2]] = preg_match('/^[a-f0-9]{64}$/D', (string)($file['sha256'] ?? '')) ? $file['sha256'] : null;
            }
        }
        foreach ($themes as &$hashes) ksort($hashes);
        return $themes;
    }

    private function inventory(string $directory): array
    {
        if (is_link($directory)) throw new RuntimeException('Linked theme directories cannot be preserved automatically.');
        $hashes = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $entry) {
            if ($entry->isLink()) throw new RuntimeException('Theme contains a linked entry; upgrade stopped before replacement.');
            if ($entry->isDir()) continue;
            if (!$entry->isFile()) throw new RuntimeException('Theme contains an unsupported entry; upgrade stopped before replacement.');
            $relative = str_replace('\\', '/', substr($entry->getPathname(), strlen($directory) + 1));
            $hash = hash_file('sha256', $entry->getPathname());
            if (!is_string($hash)) throw new RuntimeException('Unable to fingerprint the installed theme.');
            $hashes[$relative] = $hash;
        }
        ksort($hashes);
        return $hashes;
    }

    private function hasFixedThemePaths(string $source, string $slug, array $files): bool
    {
        foreach ($files as $file) {
            if (!in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), ['php','css','js','html','tpl'], true)) continue;
            $body = file_get_contents($source . '/' . $file);
            if ($body === false) throw new RuntimeException('Unable to inspect installed theme references.');
            if (str_contains($body, '/theme-assets/' . $slug . '/') || str_contains(str_replace('\\', '/', $body), '/theme/' . $slug . '/')) return true;
        }
        return false;
    }

    private function copyTheme(string $source, string $target, array &$targets, array &$directories): void
    {
        $this->createDirectory($target, $directories, fileperms($source) & 0777);
        foreach (new \DirectoryIterator($source) as $entry) {
            if ($entry->isDot()) continue;
            if ($entry->isLink()) throw new RuntimeException('Linked theme entries cannot be preserved automatically.');
            $destination = $target . '/' . $entry->getFilename();
            if ($entry->isDir()) $this->copyTheme($entry->getPathname(), $destination, $targets, $directories);
            else {
                $targets[$destination] = false;
                if (!copy($entry->getPathname(), $destination) || !chmod($destination, $entry->getPerms() & 0777) || hash_file('sha256', $destination) !== hash_file('sha256', $entry->getPathname())) throw new RuntimeException('Unable to preserve installed theme files.');
            }
        }
    }

    /** false: no repair needed; null: legacy-looking URL without enough evidence. */
    private function correctBaseUrl(string $url, array $server): string|false|null
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !in_array($parts['scheme'] ?? '', ['http','https'], true) || !filter_var($url, FILTER_VALIDATE_URL) || isset($parts['query']) || isset($parts['fragment']) || isset($parts['user']) || isset($parts['pass'])) return false;
        $path = (string)($parts['path'] ?? '');
        if (!str_ends_with(rtrim($path, '/'), '/public_html')) return false;
        if (!empty($server['HTTP_HOST'])) {
            $host = strtolower((string)$parts['host']);
            $port = (int)($parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80));
            $hosts = [$host . ':' . $port];
            if ($port === ($parts['scheme'] === 'https' ? 443 : 80)) $hosts[] = $host;
            if (!in_array(strtolower((string)$server['HTTP_HOST']), $hosts, true)) return null;
        }
        $documentRoot = (string)($server['DOCUMENT_ROOT'] ?? '');
        if ($documentRoot === '' || !empty($server['HTTP_X_FORWARDED_PREFIX'])) return null;
        $documentRoot = realpath($documentRoot);
        $public = realpath($this->paths->publicPath());
        $publicName = basename(rtrim(str_replace('\\', '/', $this->paths->publicPath()), '/'));
        if ($documentRoot === false || $public === false || !is_dir($documentRoot) || !is_dir($public) || $publicName !== 'public_html') return null;
        $documentRoot = rtrim(str_replace('\\', '/', $documentRoot), '/');
        $public = rtrim(str_replace('\\', '/', $public), '/');
        $compareRoot = DIRECTORY_SEPARATOR === '\\' ? strtolower($documentRoot) : $documentRoot;
        $comparePublic = DIRECTORY_SEPARATOR === '\\' ? strtolower($public) : $public;
        if ($comparePublic !== $compareRoot && !str_starts_with($comparePublic, $compareRoot . '/')) return null;
        $mount = substr($public, strlen($documentRoot));
        if (rtrim($path, '/') === $mount) return false;
        if (rtrim($path, '/') !== $mount . '/public_html') return null;
        // The physical directory alone cannot establish a reverse proxy's public prefix.
        if (isset($server['REQUEST_URI'])) {
            $requestPath = (string)parse_url((string)$server['REQUEST_URI'], PHP_URL_PATH);
            if ($requestPath !== $mount . '/admin' && !str_starts_with($requestPath, $mount . '/admin/')) return null;
        }
        return substr($url, 0, strlen($url) - strlen($path)) . $mount;
    }

    private function readJson(string $path): array
    {
        if (!is_file($path) || is_link($path) || is_link(dirname($path))) throw new RuntimeException('Required upgrade configuration is missing or linked.');
        $decoded = json_decode((string)file_get_contents($path), true);
        if (!is_array($decoded)) throw new RuntimeException('Required upgrade configuration is invalid.');
        return $decoded;
    }

    private function writeJson(string $path, array $value): void
    {
        if (is_link($path) || is_link(dirname($path))) throw new RuntimeException('Linked upgrade configuration is not supported.');
        // FileStore's atomic write contract is also used by the rest of Press.
        (new FileStore())->writeJson($path, $value);
        // A bootstrapped updater may still have an older FileStore class loaded.
        $expected = json_decode(json_encode($value, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        if ($this->readJson($path) !== $expected) throw new RuntimeException('Unable to verify migrated upgrade configuration.');
    }

    private function createDirectory(string $path, array &$directories, int $mode): void
    {
        if (!@mkdir($path, $mode)) throw new RuntimeException('Unable to create preserved theme storage.');
        $directories[] = $path;
        if (!chmod($path, $mode)) throw new RuntimeException('Unable to preserve theme directory permissions.');
    }
}
