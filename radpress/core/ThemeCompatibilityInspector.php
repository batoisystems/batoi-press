<?php
declare(strict_types=1);

namespace Batoi\Press\Core;

use ParseError;
use RuntimeException;
use ZipArchive;

final class ThemeCompatibilityInspector
{
    public const CONTRACT_VERSION = 'press-theme-1';

    private const REQUIRED_FILES = [
        'theme.json',
        'layouts/base.php',
        'layouts/page.php',
        'layouts/post.php',
        'layouts/blog.php',
        'layouts/archive.php',
        'layouts/404.php',
    ];
    private const MAX_FILES = 500;
    private const MAX_FILE_BYTES = 5242880;
    private const MAX_EXTRACTED_BYTES = 52428800;
    private const MAX_TEXT_INSPECTION_BYTES = 524288;
    private const SAFE_BINARY_EXTENSIONS = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'woff', 'woff2', 'ttf', 'otf', 'eot'];
    private const SAFE_TEXT_EXTENSIONS = ['php', 'json', 'css', 'js', 'mjs', 'svg', 'txt', 'md', 'map', 'html', 'htm', 'xml', 'twig', 'liquid', 'scss', 'sass', 'less', 'yml', 'yaml'];

    public function inspect(string $archivePath, string $originalName): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('Theme inspection requires the PHP Zip extension.');
        }

        $zip = new ZipArchive();
        if ($zip->open($archivePath) !== true) {
            throw new RuntimeException('Unable to open theme ZIP.');
        }

        try {
            return $this->inspectOpenArchive($zip, $originalName, hash_file('sha256', $archivePath) ?: '');
        } finally {
            $zip->close();
        }
    }

    private function inspectOpenArchive(ZipArchive $zip, string $originalName, string $checksum): array
    {
        $checks = [];
        $entries = [];
        $text = [];
        $totalBytes = 0;
        $unsafe = false;

        if ($zip->numFiles < 1 || $zip->numFiles > self::MAX_FILES) {
            $this->check($checks, 'archive.file_count', 'fail', 'Archive must contain between 1 and ' . self::MAX_FILES . ' files.');
            $unsafe = true;
        } else {
            $this->check($checks, 'archive.file_count', 'pass', 'Archive contains ' . $zip->numFiles . ' entries.');
        }

        $entryLimit = min($zip->numFiles, self::MAX_FILES + 1);
        for ($index = 0; $index < $entryLimit; $index++) {
            $stat = $zip->statIndex($index);
            $rawName = (string)($stat['name'] ?? $zip->getNameIndex($index));
            if ($this->isMetadata($rawName) || str_ends_with($rawName, '/')) {
                continue;
            }

            $name = str_replace('\\', '/', $rawName);
            if (!$this->safePath($name)) {
                $this->check($checks, 'archive.path', 'fail', 'Unsafe archive path: ' . $this->safeLabel($name));
                $unsafe = true;
                continue;
            }
            if (isset($entries[$name])) {
                $this->check($checks, 'archive.duplicate', 'fail', 'Duplicate archive path: ' . $this->safeLabel($name));
                $unsafe = true;
                continue;
            }

            $attributes = 0;
            $opsys = 0;
            $zip->getExternalAttributesIndex($index, $opsys, $attributes);
            if (($attributes & 0xF0000000) === 0xA0000000 || ((($attributes >> 16) & 0170000) === 0120000)) {
                $this->check($checks, 'archive.symlink', 'fail', 'Symbolic links are not accepted: ' . $this->safeLabel($name));
                $unsafe = true;
            }
            if (isset($stat['encryption_method']) && (int)$stat['encryption_method'] !== 0) {
                $this->check($checks, 'archive.encryption', 'fail', 'Encrypted entries are not accepted: ' . $this->safeLabel($name));
                $unsafe = true;
            }

            $size = (int)($stat['size'] ?? 0);
            $totalBytes += max(0, $size);
            if ($size < 0 || $size > self::MAX_FILE_BYTES || $totalBytes > self::MAX_EXTRACTED_BYTES) {
                $this->check($checks, 'archive.size', 'fail', 'Archive exceeds the per-file or extracted-size limit.');
                $unsafe = true;
            }

            $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!in_array($extension, array_merge(self::SAFE_TEXT_EXTENSIONS, self::SAFE_BINARY_EXTENSIONS), true)) {
                $this->check($checks, 'archive.extension', 'fail', 'Unsupported file type: .' . ($extension !== '' ? $extension : '(none)'));
                $unsafe = true;
            }

            $entries[$name] = ['index' => $index, 'size' => $size, 'extension' => $extension];
            if (in_array($extension, self::SAFE_TEXT_EXTENSIONS, true) && $size <= self::MAX_TEXT_INSPECTION_BYTES) {
                $contents = $zip->getFromIndex($index);
                if (is_string($contents)) {
                    $text[$name] = $contents;
                }
            }
        }

        if (!$unsafe) {
            $this->check($checks, 'archive.safety', 'pass', 'Paths, sizes, links, encryption, and file types passed non-executing inspection.');
        }

        $root = $this->detectRoot(array_keys($entries));
        $relativeEntries = [];
        foreach ($entries as $name => $entry) {
            $relativeEntries[$this->relative($name, $root)] = $entry;
        }
        $relativeText = [];
        foreach ($text as $name => $contents) {
            $relativeText[$this->relative($name, $root)] = $contents;
        }

        $manifest = null;
        $manifestError = '';
        if (isset($relativeText['theme.json'])) {
            $manifest = json_decode($relativeText['theme.json'], true);
            if (!is_array($manifest)) {
                $manifestError = 'theme.json does not contain valid JSON.';
            }
        }

        $missing = array_values(array_filter(self::REQUIRED_FILES, static fn(string $file): bool => !isset($relativeEntries[$file])));
        $pressShape = isset($relativeEntries['theme.json']) || $this->hasPressLayouts($relativeEntries);
        $manifestValid = false;
        if (is_array($manifest)) {
            try {
                (new ThemeManager(new Paths(dirname(__DIR__, 2))))->normalizeManifest('candidate', $manifest);
                $manifestValid = true;
                $this->check($checks, 'press.manifest', 'pass', 'theme.json matches Press manifest schema 1.');
            } catch (RuntimeException $exception) {
                $manifestError = $exception->getMessage();
            }
        }
        if (!$manifestValid) {
            $this->check($checks, 'press.manifest', $pressShape ? 'warn' : 'fail', $manifestError !== '' ? $manifestError : 'No Press theme.json manifest was found.');
        }

        if ($missing === []) {
            $this->check($checks, 'press.required_files', 'pass', 'All required Press layout files are present.');
        } else {
            $this->check($checks, 'press.required_files', $pressShape ? 'warn' : 'fail', 'Missing required Press files: ' . implode(', ', $missing));
        }

        $declaredAssetsMissing = $this->missingDeclaredAssets($manifest, $relativeEntries);
        if ($declaredAssetsMissing === []) {
            $this->check($checks, 'press.declared_assets', 'pass', 'All declared theme assets are present.');
        } else {
            $this->check($checks, 'press.declared_assets', 'warn', 'Missing declared assets: ' . implode(', ', $declaredAssetsMissing));
        }

        $restrictedPhp = [];
        $prohibitedPhp = [];
        $invalidPhp = [];
        $browserPolicyFiles = [];
        $externalDependencyFiles = [];
        $unsafeOutputFiles = [];
        foreach ($relativeText as $name => $contents) {
            $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (in_array($extension, ['php', 'html', 'htm'], true)
                && preg_match('/<script\b|\son[a-z]+\s*=|(?:href|src)\s*=\s*["\']\s*javascript:/i', $contents) === 1) {
                $browserPolicyFiles[] = $name;
            }
            if (preg_match('/<(?:script|img|source|iframe|link)\b[^>]*(?:src|href)\s*=\s*["\']\s*https?:\/\/|@import\s+(?:url\()?\s*["\']?https?:\/\/|url\(\s*["\']?https?:\/\//i', $contents) === 1) {
                $externalDependencyFiles[] = $name;
            }
            if ($extension !== 'php') {
                continue;
            }
            try {
                token_get_all($contents, TOKEN_PARSE);
            } catch (ParseError) {
                $invalidPhp[] = $name;
            }
            if (preg_match('#^(?:layouts|partials)/[^/]+\.php$#', $name) !== 1) {
                $restrictedPhp[] = $name;
            }
            if (preg_match('/\b(?:eval|assert|exec|shell_exec|system|passthru|proc_open|popen|pcntl_exec)\s*\(|`[^`]+`|\b(?:include|require)(?:_once)?\s*\(?\s*["\'](?:https?:)?\/\//i', $contents) === 1) {
                $prohibitedPhp[] = $name;
            }
            if (preg_match('/(?:echo|print)\s+\$_(?:GET|POST|REQUEST|COOKIE|SERVER)\b|<\?=\s*\$_(?:GET|POST|REQUEST|COOKIE|SERVER)\b/i', $contents) === 1) {
                $unsafeOutputFiles[] = $name;
            }
        }
        if ($restrictedPhp !== []) {
            $this->check($checks, 'press.php_location', 'warn', 'Executable PHP outside Press layouts/partials will not be imported: ' . implode(', ', array_slice($restrictedPhp, 0, 8)));
        } else {
            $this->check($checks, 'press.php_location', 'pass', 'PHP files, if any, are confined to Press layout locations.');
        }
        if ($prohibitedPhp !== []) {
            $this->check($checks, 'press.php_operations', 'fail', 'Potentially dangerous PHP operations were detected in: ' . implode(', ', array_slice($prohibitedPhp, 0, 8)));
            $unsafe = true;
        } else {
            $this->check($checks, 'press.php_operations', 'pass', 'No prohibited PHP execution operations were detected in inspected text files.');
        }
        if ($invalidPhp !== []) {
            $this->check($checks, 'press.php_syntax', 'fail', 'PHP syntax could not be parsed in: ' . implode(', ', array_slice($invalidPhp, 0, 8)));
            $unsafe = true;
        } else {
            $this->check($checks, 'press.php_syntax', 'pass', 'Inspected PHP source passed non-executing token parsing.');
        }
        if ($browserPolicyFiles !== []) {
            $this->check($checks, 'browser.inline_code', 'warn', 'Inline scripts, event handlers, or javascript URLs require conversion or manual review: ' . implode(', ', array_slice($browserPolicyFiles, 0, 8)));
        } else {
            $this->check($checks, 'browser.inline_code', 'pass', 'No inline script or event-handler patterns were detected.');
        }
        if ($externalDependencyFiles !== []) {
            $this->check($checks, 'browser.external_dependencies', 'warn', 'Remote asset dependencies require availability, privacy, integrity, and CSP review: ' . implode(', ', array_slice($externalDependencyFiles, 0, 8)));
        } else {
            $this->check($checks, 'browser.external_dependencies', 'pass', 'No remote stylesheet, script, or CSS URL dependencies were detected.');
        }
        if ($unsafeOutputFiles !== []) {
            $this->check($checks, 'press.unsafe_output', 'fail', 'Direct output from request or server input was detected in: ' . implode(', ', array_slice($unsafeOutputFiles, 0, 8)));
            $unsafe = true;
        } else {
            $this->check($checks, 'press.unsafe_output', 'pass', 'No direct output from common untrusted request globals was detected.');
        }

        $secretFiles = [];
        foreach ($relativeText as $name => $contents) {
            if (preg_match('/-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----|(?:api[_-]?key|client[_-]?secret|password|authorization|token)\s*[=:]\s*["\'][^"\']{6,}/i', $contents) === 1) {
                $secretFiles[] = $name;
            }
        }
        if ($secretFiles !== []) {
            $this->check($checks, 'content.secrets', 'fail', 'Possible credentials or private keys were detected in: ' . implode(', ', array_slice($secretFiles, 0, 8)));
            $unsafe = true;
        } else {
            $this->check($checks, 'content.secrets', 'pass', 'No common credential or private-key patterns were detected.');
        }

        $sourceType = $this->sourceType($relativeEntries, $relativeText);
        $compatible = !$unsafe && $manifestValid && $missing === [] && $declaredAssetsMissing === [] && $restrictedPhp === [] && $browserPolicyFiles === [] && $externalDependencyFiles === [];
        $repairable = !$unsafe && $pressShape && !$compatible;
        $classification = $unsafe ? 'unsafe' : ($compatible ? 'compatible' : ($repairable ? 'repairable' : 'convertible'));
        $recommendation = match ($classification) {
            'compatible' => 'Install as an inactive theme, preview every layout, then activate explicitly.',
            'repairable' => 'Repair or migrate the Press contract before installation. Do not activate the source archive directly.',
            'convertible' => 'Convert this presentation source with Batoi Platform Build, review the generated report and previews, then import the signed Press theme.',
            default => 'Reject this archive. Remove unsafe content and produce a new source package before any conversion attempt.',
        };

        return [
            'contract' => self::CONTRACT_VERSION,
            'classification' => $classification,
            'source_type' => $sourceType,
            'compatible' => $compatible,
            'installable' => $compatible,
            'requires_platform' => $classification === 'convertible',
            'original_name' => basename($originalName),
            'archive_sha256' => $checksum,
            'file_count' => count($entries),
            'extracted_bytes' => $totalBytes,
            'root' => $root,
            'missing_required_files' => $missing,
            'checks' => $checks,
            'recommendation' => $recommendation,
        ];
    }

    private function sourceType(array $entries, array $text): string
    {
        $names = array_keys($entries);
        if (isset($entries['theme.json']) || $this->hasPressLayouts($entries)) {
            return 'batoi_press';
        }
        if (isset($entries['style.css']) && isset($entries['functions.php']) && preg_match('/Theme Name\s*:/i', (string)($text['style.css'] ?? '')) === 1) {
            return 'wordpress';
        }
        foreach ($names as $name) {
            if (str_ends_with($name, '.twig')) return 'twig';
            if (str_ends_with($name, '.liquid')) return 'liquid';
        }
        if (isset($entries['index.html']) || isset($entries['index.htm'])) {
            $combinedCss = implode("\n", array_filter($text, static fn(string $value, string $name): bool => str_ends_with($name, '.css'), ARRAY_FILTER_USE_BOTH));
            return preg_match('/bootstrap(?:\.min)?\.css|--bs-[a-z-]+/i', $combinedCss) === 1 ? 'bootstrap' : 'static_html';
        }
        return 'unknown';
    }

    private function missingDeclaredAssets(?array $manifest, array $entries): array
    {
        if (!is_array($manifest)) return [];
        $missing = [];
        foreach (['styles', 'scripts'] as $group) {
            foreach ((array)(($manifest['assets'][$group] ?? [])) as $definition) {
                $file = is_array($definition) ? trim((string)($definition['file'] ?? '')) : trim((string)$definition);
                if ($file !== '' && !isset($entries['assets/' . ltrim(str_replace('\\', '/', $file), '/')])) {
                    $missing[] = 'assets/' . $file;
                }
            }
        }
        return array_values(array_unique($missing));
    }

    private function hasPressLayouts(array $entries): bool
    {
        return isset($entries['layouts/base.php']) || isset($entries['layouts/page.php']);
    }

    private function detectRoot(array $names): string
    {
        if (in_array('theme.json', $names, true) || in_array('index.html', $names, true) || in_array('style.css', $names, true)) return '';
        $firstSegments = [];
        foreach ($names as $name) {
            $parts = explode('/', $name);
            if (count($parts) < 2) return '';
            $firstSegments[$parts[0]] = true;
        }
        return count($firstSegments) === 1 ? (string)array_key_first($firstSegments) : '';
    }

    private function relative(string $name, string $root): string
    {
        return $root !== '' && str_starts_with($name, $root . '/') ? substr($name, strlen($root) + 1) : $name;
    }

    private function safePath(string $name): bool
    {
        if ($name === '' || str_starts_with($name, '/') || preg_match('/^[a-z]:/i', $name) === 1 || preg_match('/[\x00-\x1f\x7f]/', $name) === 1) return false;
        foreach (explode('/', rtrim($name, '/')) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') return false;
        }
        return true;
    }

    private function isMetadata(string $name): bool
    {
        $name = str_replace('\\', '/', $name);
        return str_starts_with($name, '__MACOSX/') || basename($name) === '.DS_Store' || str_starts_with(basename($name), '._');
    }

    private function safeLabel(string $value): string
    {
        return substr(preg_replace('/[^A-Za-z0-9._\/-]+/', '?', $value) ?: 'unknown', 0, 180);
    }

    private function check(array &$checks, string $code, string $status, string $message): void
    {
        $checks[] = ['code' => $code, 'status' => $status, 'message' => $message];
    }
}
