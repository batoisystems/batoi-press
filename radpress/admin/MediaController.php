<?php
declare(strict_types=1);

namespace Batoi\Press\Admin;

use Batoi\Press\Core\AuditLog;
use Batoi\Press\Core\AssetLibraryManager;
use Batoi\Press\Core\AssetManager;
use Batoi\Press\Core\Config;
use Batoi\Press\Core\Request;
use Batoi\Press\Core\Response;
use Batoi\Press\Security\AdminAccess;
use Batoi\Press\Security\Csrf;
use Batoi\Press\Security\UploadGuard;
use RuntimeException;

final class MediaController
{
    public function __construct(
        private readonly Config $config,
        private readonly Csrf $csrf,
        private readonly AuditLog $audit,
        private readonly array $user
    ) {
    }

    public function index(): Response
    {
        $filters = [
            'q' => trim((string)($_GET['q'] ?? '')),
            'type' => trim((string)($_GET['type'] ?? '')),
            'sort' => trim((string)($_GET['sort'] ?? 'newest')),
        ];
        $body = AdminLayout::pageHeader('Media', 'Organize site assets and versioned frontend libraries without breaking their public paths.');
        $uploadLimitBytes = AssetManager::effectiveMaxBytes((int)($this->config->security()['uploads']['max_bytes'] ?? 0));
        $uploadLimit = (int)($uploadLimitBytes / 1048576);
        $extensions = AssetManager::effectiveUploadExtensions((array)($this->config->security()['uploads']['allowed_extensions'] ?? []));
        $body .= '<div class="bp-admin-grid"><section class="bp-admin-section"><header><div><h2>Upload asset</h2><p>Files are classified into typed storage automatically.</p></div></header><form method="post" action="/admin/media/upload" enctype="multipart/form-data" class="bp-form bp-compact-form">';
        $body .= $this->csrf->field();
        $body .= '<label>File <input type="file" name="media" accept="' . $this->e($this->acceptValue($extensions)) . '" required><span class="bp-field-help">Maximum upload size: ' . $this->e((string)$uploadLimit) . ' MB.</span></label>';
        $body .= AdminLayout::submitButton('Upload File', 'upload') . '</form></section>';
        $body .= AdminLayout::section('Storage policy', $this->uploadPolicy($extensions), 'New uploads use typed paths; existing flat media URLs remain compatible.');

        $assets = (new AssetManager($this->config->paths()))->all();
        $filteredAssets = $this->filterFiles($assets, $filters);
        if ($assets === []) {
            $body .= '<section class="bp-empty-state bp-admin-section-wide"><h2>No assets</h2><p>Upload an image, document, multimedia file, stylesheet, or script to begin the asset library.</p></section>';
        } elseif ($filteredAssets === []) {
            $body .= $this->filterForm($filters);
            $body .= '<section class="bp-empty-state bp-admin-section-wide"><h2>No assets match these filters</h2><p>Adjust the search, type, or sort controls to review other files.</p>' . AdminLayout::buttonLink('Reset Filters', '/admin/media', 'back') . '</section>';
        } else {
            $body .= $this->filterForm($filters);
            $body .= '<section class="bp-admin-section bp-admin-section-wide"><header><div><h2>Assets</h2><p>' . $this->e((string)count($filteredAssets)) . ' of ' . $this->e((string)count($assets)) . ' assets shown. Paths remain stable when copied into content or templates.</p></div></header><div class="bp-table-wrap"><table class="bp-table bp-content-table bp-media-table"><thead><tr><th>Asset</th><th>Type</th><th>Size</th><th>Modified</th><th>Usage</th><th>Actions</th></tr></thead><tbody>';
            foreach ($filteredAssets as $asset) {
                $name = (string)$asset['name'];
                $relative = (string)$asset['relative'];
                $url = (string)$asset['url'];
                $snippet = $this->snippet($name, $url);
                $id = 'bp-media-' . substr(hash('sha256', (string)$asset['storage'] . ':' . $relative), 0, 12);
                $legacy = (string)$asset['storage'] === 'media' ? ' - Legacy path' : '';
                $body .= '<tr><td><strong>' . $this->e($name) . '</strong><small>' . $this->e($relative) . '</small><small>' . $this->e((string)$asset['kind'] . $legacy) . '</small></td><td>' . $this->e(strtoupper(pathinfo($name, PATHINFO_EXTENSION) ?: 'FILE')) . '</td><td>' . $this->e($this->size((int)$asset['size'])) . '</td><td>' . $this->e($this->modified((int)$asset['modified'])) . '</td><td><div class="bp-media-code-stack">' . $this->copyField($id . '-url', 'Public URL', $url) . $this->copyField($id . '-embed', 'Embed snippet', $snippet) . '</div></td><td><div class="bp-table-actions bp-media-actions">' . AdminLayout::buttonLink('Edit', $this->assetEditorUrl((string)$asset['storage'], $relative), 'edit', true) . AdminLayout::buttonLink('View', $url, 'site', true) . $this->deleteForm((string)$asset['storage'], $relative) . '</div></td></tr>';
            }
            $body .= '</tbody></table></div></section>';
        }

        if (in_array(AdminAccess::role($this->user), ['owner', 'admin'], true)) {
            $body .= $this->librarySection();
        }
        $body .= '</div>';
        return Response::html($this->layout('Media', $body));
    }

    public function upload(): Response
    {
        if (!$this->csrf->validate((string)($_POST['csrf_token'] ?? ''))) {
            return Response::html($this->layout('Media', '<p class="bp-error">Security token expired.</p>'), 400);
        }

        $uploadConfig = $this->config->security()['uploads'] ?? [];
        $guard = new UploadGuard(
            AssetManager::effectiveUploadExtensions((array)($uploadConfig['allowed_extensions'] ?? [])),
            AssetManager::effectiveMaxBytes((int)($uploadConfig['max_bytes'] ?? 0))
        );
        $file = $_FILES['media'] ?? [];
        $error = is_array($file) ? $guard->validate($file) : 'Upload failed.';
        if ($error !== null) {
            return Response::html($this->layout('Media', '<p class="bp-error">' . $this->e($error) . '</p><p><a href="/admin/media">Back to media</a></p>'), 400);
        }

        $name = $guard->safeName((string)$file['name']);
        $manager = new AssetManager($this->config->paths());
        $relative = $manager->relativeUploadPath($name);
        try {
            $target = $manager->prepareTarget($relative);
        } catch (RuntimeException $exception) {
            return $this->message($exception->getMessage(), true, 500);
        }

        if (!move_uploaded_file((string)$file['tmp_name'], $target)) {
            return Response::html($this->layout('Media', '<p class="bp-error">Unable to save upload.</p>'), 500);
        }

        $this->audit->record((string)($this->user['username'] ?? 'admin'), 'asset.uploaded', $relative, (string)($_SERVER['REMOTE_ADDR'] ?? ''));
        return Response::redirect('/admin/media');
    }

    public function edit(Request $request): Response
    {
        $storage = $request->input('storage');
        $relative = $request->input('file');
        $manager = new AssetManager($this->config->paths());
        $asset = $manager->find($storage, $relative);
        if ($asset === null) {
            return $this->message('Asset was not found or cannot be managed here.', true, 404);
        }

        $name = (string)$asset['name'];
        $url = (string)$asset['url'];
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $body = AdminLayout::pageHeader(
            'Edit Asset',
            'Update this file while preserving its public URL.',
            AdminLayout::buttonLink('Back to Media', '/admin/media', 'back', true) . AdminLayout::buttonLink('View Asset', $url, 'site', true)
        );
        $body .= '<div class="bp-admin-editor bp-admin-editor-single"><div class="bp-editor-main">';
        $body .= '<section class="bp-editor-panel"><header><h2>' . $this->e($name) . '</h2><p>Changes are published at the existing path after the previous file is retained privately.</p></header>';
        $body .= '<dl class="bp-definition-list"><div><dt>Public URL</dt><dd><code>' . $this->e($url) . '</code></dd></div><div><dt>Type</dt><dd>' . $this->e(strtoupper($extension ?: 'FILE')) . '</dd></div><div><dt>Size</dt><dd>' . $this->e($this->size((int)$asset['size'])) . '</dd></div><div><dt>Modified</dt><dd>' . $this->e($this->modified((int)$asset['modified'])) . '</dd></div></dl></section>';

        if (AssetManager::isTextEditable($relative)) {
            try {
                $source = $manager->readEditableText($storage, $relative);
                $body .= '<form method="post" action="/admin/media/update-text" class="bp-form"><section class="bp-editor-panel"><header><h2>Edit source</h2><p>CSS, JavaScript, Markdown, and text assets can be edited directly up to 2 MB.</p></header>' . $this->assetIdentityFields($storage, $relative);
                $body .= '<label class="bp-field-wide bp-code-editor-label" for="bp-asset-source"><span>Source</span><small>' . $this->e(strtoupper($extension)) . ' source editor</small><textarea id="bp-asset-source" class="bp-editor-textarea bp-template-code-editor" name="source" rows="24" spellcheck="false" autocomplete="off" autocapitalize="off" autocorrect="off" wrap="off">' . $this->e($source) . '</textarea></label>';
                $body .= '<div class="bp-form-actions">' . AdminLayout::submitButton('Save Source', 'save') . '</div></section></form>';
            } catch (RuntimeException $exception) {
                $body .= '<p class="bp-error">' . $this->e($exception->getMessage()) . '</p>';
            }
        }

        $body .= '<form method="post" action="/admin/media/replace" enctype="multipart/form-data" class="bp-form" data-confirm="Replace ' . $this->e($name) . ' at its existing public URL?">';
        $body .= '<section class="bp-editor-panel"><header><h2>Replace file</h2><p>Upload another .' . $this->e($extension) . ' file. The public URL remains unchanged.</p></header>' . $this->assetIdentityFields($storage, $relative);
        $body .= '<label>Replacement file <input type="file" name="replacement" accept=".' . $this->e($extension) . '" required><span class="bp-field-help">The replacement must use the .' . $this->e($extension) . ' extension and comply with the installation upload limit.</span></label>';
        $body .= '<div class="bp-form-actions">' . AdminLayout::submitButton('Replace File', 'refresh') . '</div></section></form>';
        $body .= '</div></div>';

        return Response::html($this->layout('Edit Asset', $body));
    }

    public function updateText(Request $request): Response
    {
        if (!$this->csrf->validate($request->input('csrf_token'))) {
            return $this->message('Security token expired.', true, 400);
        }
        $storage = $request->input('storage');
        $relative = $request->input('file');
        $source = $request->post['source'] ?? '';
        if (!is_string($source)) {
            return $this->message('Asset source is invalid.', true, 400);
        }
        try {
            (new AssetManager($this->config->paths()))->updateText($storage, $relative, $source);
        } catch (RuntimeException $exception) {
            return $this->message($exception->getMessage(), true, 400);
        }
        $this->audit->record((string)($this->user['username'] ?? 'admin'), 'asset.edited', $storage . ':' . $relative, (string)($_SERVER['REMOTE_ADDR'] ?? ''));
        return Response::redirect($this->assetEditorUrl($storage, $relative));
    }

    public function replace(Request $request): Response
    {
        if (!$this->csrf->validate($request->input('csrf_token'))) {
            return $this->message('Security token expired.', true, 400);
        }
        $storage = $request->input('storage');
        $relative = $request->input('file');
        $manager = new AssetManager($this->config->paths());
        $asset = $manager->find($storage, $relative);
        if ($asset === null) {
            return $this->message('Asset was not found or cannot be managed here.', true, 404);
        }

        $uploadConfig = $this->config->security()['uploads'] ?? [];
        $extension = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
        $guard = new UploadGuard([$extension], AssetManager::effectiveMaxBytes((int)($uploadConfig['max_bytes'] ?? 0)));
        $file = $_FILES['replacement'] ?? [];
        $error = is_array($file) ? $guard->validate($file) : 'Upload failed.';
        if ($error !== null) {
            return $this->message($error, true, 400);
        }
        try {
            $manager->replace($storage, $relative, (string)$file['tmp_name']);
        } catch (RuntimeException $exception) {
            return $this->message($exception->getMessage(), true, 400);
        }
        $this->audit->record((string)($this->user['username'] ?? 'admin'), 'asset.replaced', $storage . ':' . $relative, (string)($_SERVER['REMOTE_ADDR'] ?? ''));
        return Response::redirect($this->assetEditorUrl($storage, $relative));
    }

    public function delete(Request $request): Response
    {
        if (!$this->csrf->validate($request->input('csrf_token'))) {
            return Response::html($this->layout('Media', '<p class="bp-error">Security token expired.</p>'), 400);
        }

        $storage = $request->input('storage');
        $relative = $request->input('file');
        if (!(new AssetManager($this->config->paths()))->delete($storage, $relative)) {
            return $this->message('Asset was not found or could not be deleted.', true, 404);
        }

        $this->audit->record((string)($this->user['username'] ?? 'admin'), 'asset.deleted', $storage . ':' . $relative, (string)($_SERVER['REMOTE_ADDR'] ?? ''));
        return Response::redirect('/admin/media');
    }

    public function installLibrary(Request $request): Response
    {
        if (!$this->csrf->validate($request->input('csrf_token'))) {
            return $this->message('Security token expired.', true, 400);
        }
        $file = $_FILES['library_zip'] ?? [];
        $maxBytes = (int)($this->config->security()['uploads']['library_max_bytes'] ?? 26214400);
        $error = is_array($file) ? (new UploadGuard(['zip'], $maxBytes))->validate($file) : 'Upload failed.';
        if ($error !== null) {
            return $this->message($error, true, 400);
        }
        try {
            $manifest = (new AssetLibraryManager($this->config->paths()))->installZip((string)$file['tmp_name']);
        } catch (RuntimeException $exception) {
            return $this->message($exception->getMessage(), true, 400);
        }
        $target = (string)$manifest['name'] . '@' . (string)$manifest['version'];
        $this->audit->record((string)($this->user['username'] ?? 'admin'), 'asset_library.installed', $target, (string)($_SERVER['REMOTE_ADDR'] ?? ''));
        return Response::redirect('/admin/media');
    }

    public function toggleLibrary(Request $request): Response
    {
        if (!$this->csrf->validate($request->input('csrf_token'))) {
            return $this->message('Security token expired.', true, 400);
        }
        $enabled = $request->input('enabled') === '1';
        try {
            $manifest = (new AssetLibraryManager($this->config->paths()))->setEnabled($request->input('name'), $request->input('version'), $enabled);
        } catch (RuntimeException $exception) {
            return $this->message($exception->getMessage(), true, 400);
        }
        $target = (string)$manifest['name'] . '@' . (string)$manifest['version'];
        $this->audit->record((string)($this->user['username'] ?? 'admin'), $enabled ? 'asset_library.enabled' : 'asset_library.disabled', $target, (string)($_SERVER['REMOTE_ADDR'] ?? ''));
        return Response::redirect('/admin/media');
    }

    public function deleteLibrary(Request $request): Response
    {
        if (!$this->csrf->validate($request->input('csrf_token'))) {
            return $this->message('Security token expired.', true, 400);
        }
        $name = $request->input('name');
        $version = $request->input('version');
        try {
            (new AssetLibraryManager($this->config->paths()))->delete($name, $version);
        } catch (RuntimeException $exception) {
            return $this->message($exception->getMessage(), true, 404);
        }
        $this->audit->record((string)($this->user['username'] ?? 'admin'), 'asset_library.deleted', $name . '@' . $version, (string)($_SERVER['REMOTE_ADDR'] ?? ''));
        return Response::redirect('/admin/media');
    }

    private function filterFiles(array $files, array $filters): array
    {
        $q = strtolower((string)($filters['q'] ?? ''));
        $type = (string)($filters['type'] ?? '');
        $filtered = array_values(array_filter($files, static function (array $file) use ($q, $type): bool {
            if ($type !== '' && (string)$file['type'] !== $type) {
                return false;
            }
            if ($q === '') {
                return true;
            }

            return str_contains(strtolower((string)$file['relative'] . ' ' . (string)$file['kind']), $q);
        }));

        return $this->sortFiles($filtered, (string)($filters['sort'] ?? 'newest'));
    }

    private function sortFiles(array $files, string $sort): array
    {
        usort($files, static function (array $a, array $b) use ($sort): int {
            if ($sort === 'name') {
                return strcasecmp((string)$a['relative'], (string)$b['relative']);
            }
            if ($sort === 'size') {
                $comparison = ((int)$b['size']) <=> ((int)$a['size']);
                return $comparison !== 0 ? $comparison : strcasecmp((string)$a['relative'], (string)$b['relative']);
            }

            $comparison = ((int)$b['modified']) <=> ((int)$a['modified']);
            return $comparison !== 0 ? $comparison : strcasecmp((string)$a['relative'], (string)$b['relative']);
        });

        return $files;
    }

    private function filterForm(array $filters): string
    {
        $type = (string)($filters['type'] ?? '');
        $sort = (string)($filters['sort'] ?? 'newest');
        $html = '<form method="get" action="/admin/media" class="bp-filter-form bp-filter-form-media"><div class="bp-filter-field bp-filter-field-search"><label for="bp-media-filter-q">Search</label><input id="bp-media-filter-q" type="search" name="q" value="' . $this->e((string)($filters['q'] ?? '')) . '" placeholder="Filename or extension"></div>';
        $html .= '<div class="bp-filter-field"><label for="bp-media-filter-type">Type</label><select id="bp-media-filter-type" name="type"><option value="">All files</option>';
        foreach (['images' => 'Images', 'styles' => 'CSS', 'scripts' => 'JavaScript', 'audio' => 'Audio', 'video' => 'Video', 'documents' => 'Documents'] as $value => $label) {
            $html .= '<option value="' . $this->e($value) . '"' . ($type === $value ? ' selected' : '') . '>' . $this->e($label) . '</option>';
        }
        $html .= '</select></div><div class="bp-filter-field"><label for="bp-media-filter-sort">Sort</label><select id="bp-media-filter-sort" name="sort">';
        foreach (['newest' => 'Newest first', 'name' => 'Name A-Z', 'size' => 'Largest first'] as $value => $label) {
            $html .= '<option value="' . $this->e($value) . '"' . ($sort === $value ? ' selected' : '') . '>' . $this->e($label) . '</option>';
        }
        return $html . '</select></div><div class="bp-filter-actions">' . AdminLayout::submitButton('Apply Filters', 'check') . AdminLayout::buttonLink('Reset', '/admin/media', 'back', true) . '</div></form>';
    }

    private function deleteForm(string $storage, string $relative): string
    {
        return '<form method="post" action="/admin/media/delete" class="bp-inline-form" data-confirm="Delete ' . $this->e($relative) . '? Existing pages or templates that reference it may stop working.">'
            . $this->csrf->field()
            . '<input type="hidden" name="storage" value="' . $this->e($storage) . '">'
            . '<input type="hidden" name="file" value="' . $this->e($relative) . '">'
            . AdminLayout::submitButton('Delete', 'delete', 'class="bp-button bp-button-secondary bp-button-danger"')
            . '</form>';
    }

    private function assetEditorUrl(string $storage, string $relative): string
    {
        return '/admin/media/edit?storage=' . rawurlencode($storage) . '&file=' . rawurlencode($relative);
    }

    private function assetIdentityFields(string $storage, string $relative): string
    {
        return $this->csrf->field()
            . '<input type="hidden" name="storage" value="' . $this->e($storage) . '">'
            . '<input type="hidden" name="file" value="' . $this->e($relative) . '">';
    }

    private function copyField(string $id, string $label, string $value): string
    {
        return '<div class="bp-media-code-field"><label for="' . $this->e($id) . '">' . $this->e($label) . '</label><div><input id="' . $this->e($id) . '" class="bp-code-input" readonly value="' . $this->e($value) . '"><button type="button" class="bp-button bp-button-secondary bp-copy-button" data-copy-target="' . $this->e($id) . '" aria-label="Copy ' . $this->e(strtolower($label)) . '">' . AdminLayout::icon('copy') . '<span>Copy</span></button></div></div>';
    }

    private function layout(string $title, string $body): string
    {
        return AdminLayout::render($title, $body);
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function snippet(string $name, string $url): string
    {
        $type = AssetManager::typeForName($name);
        if ($type === 'images') {
            return '<img src="' . $url . '" alt="">';
        }
        if ($type === 'styles') {
            return '<link rel="stylesheet" href="' . $url . '">';
        }
        if ($type === 'scripts') {
            if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) === 'mjs') {
                return '<script type="module" src="' . $url . '"></script>';
            }
            return '<script src="' . $url . '" defer></script>';
        }
        if ($type === 'audio') {
            return '<audio controls src="' . $url . '"></audio>';
        }
        if ($type === 'video') {
            return '<video controls src="' . $url . '"></video>';
        }

        return '<a href="' . $url . '">Download ' . $this->e($name) . '</a>';
    }

    private function acceptValue(array $extensions): string
    {
        $allowed = array_values(array_filter(array_map('strval', $extensions)));
        return implode(',', array_map(static fn (string $extension): string => '.' . ltrim(strtolower($extension), '.'), $allowed));
    }

    private function size(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 1) . ' MB';
        }
        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 1) . ' KB';
        }
        return $bytes . ' B';
    }

    private function modified(int $time): string
    {
        return $time ? date('M j, Y H:i', $time) : 'Unknown';
    }

    private function uploadPolicy(array $extensions): string
    {
        $allowed = array_values(array_filter(array_map('strval', $extensions)));
        $allowedText = $allowed === [] ? 'Configured by installation policy' : implode(', ', array_map(static fn (string $extension): string => '.' . ltrim($extension, '.'), $allowed));

        return '<ul class="bp-admin-checklist">'
            . '<li>' . AdminLayout::icon('check') . '<span>Allowed extensions: ' . $this->e($allowedText) . '.</span></li>'
            . '<li>' . AdminLayout::icon('check') . '<span>Each upload receives a unique, normalized filename so an existing asset is never silently replaced.</span></li>'
            . '<li>' . AdminLayout::icon('check') . '<span>New files are organized below <code>radpress/content/assets</code> and served from <code>/assets/{path}</code>.</span></li>'
            . '<li>' . AdminLayout::icon('check') . '<span>Legacy files remain under <code>radpress/content/media</code> so existing <code>/media/{file}</code> references continue working.</span></li>'
            . '<li>' . AdminLayout::icon('check') . '<span>Every successful upload is recorded in the audit log.</span></li>'
            . '</ul>';
    }

    private function librarySection(): string
    {
        $maxBytes = (int)($this->config->security()['uploads']['library_max_bytes'] ?? 26214400);
        $maxMegabytes = max(1, (int)floor($maxBytes / 1048576));
        $html = '<section class="bp-admin-section bp-admin-section-wide"><header><div><h2>Frontend libraries</h2><p>Install complete versioned packages so relative fonts, images, maps, CSS, and scripts stay together.</p></div></header>';
        $html .= '<form method="post" action="/admin/media/libraries/upload" enctype="multipart/form-data" class="bp-form bp-library-upload-form">' . $this->csrf->field();
        $html .= '<label>Library ZIP <input type="file" name="library_zip" accept=".zip" required><span class="bp-field-help">Maximum package size: ' . $this->e((string)$maxMegabytes) . ' MB. The ZIP must contain <code>library.json</code> at its root.</span></label>';
        $html .= AdminLayout::submitButton('Install Library', 'upload') . '</form>';

        $libraries = (new AssetLibraryManager($this->config->paths()))->all();
        if ($libraries === []) {
            return $html . '<div class="bp-inline-empty"><strong>No libraries installed</strong><span>Upload a manifest-driven ZIP when the site needs a packaged CSS or JavaScript dependency.</span></div></section>';
        }

        $html .= '<div class="bp-table-wrap"><table class="bp-table bp-content-table bp-library-table"><thead><tr><th>Library</th><th>Status</th><th>Scope</th><th>Entry points</th><th>Actions</th></tr></thead><tbody>';
        foreach ($libraries as $library) {
            $name = (string)$library['name'];
            $version = (string)$library['version'];
            $enabled = (bool)$library['enabled'];
            $entries = count((array)$library['styles']) . ' CSS / ' . count((array)$library['scripts']) . ' JS';
            $html .= '<tr><td><strong>' . $this->e($name) . '</strong><small>Version ' . $this->e($version) . '</small></td><td><span class="bp-status-badge ' . ($enabled ? 'is-published' : 'is-disabled') . '">' . ($enabled ? 'Enabled' : 'Disabled') . '</span></td><td>' . $this->e(ucfirst((string)$library['scope'])) . '</td><td>' . $this->e($entries) . '</td><td><div class="bp-table-actions bp-library-actions">';
            $html .= '<form method="post" action="/admin/media/libraries/toggle" class="bp-inline-form">' . $this->csrf->field() . '<input type="hidden" name="name" value="' . $this->e($name) . '"><input type="hidden" name="version" value="' . $this->e($version) . '"><input type="hidden" name="enabled" value="' . ($enabled ? '0' : '1') . '">' . AdminLayout::submitButton($enabled ? 'Disable' : 'Enable', 'refresh', 'class="bp-button bp-button-secondary"') . '</form>';
            $html .= '<form method="post" action="/admin/media/libraries/delete" class="bp-inline-form" data-confirm="Remove ' . $this->e($name . '@' . $version) . '? Public pages using this library may stop working.">' . $this->csrf->field() . '<input type="hidden" name="name" value="' . $this->e($name) . '"><input type="hidden" name="version" value="' . $this->e($version) . '">' . AdminLayout::submitButton('Remove', 'delete', 'class="bp-button bp-button-secondary bp-button-danger"') . '</form>';
            $html .= '</div></td></tr>';
        }
        return $html . '</tbody></table></div></section>';
    }

    private function message(string $message, bool $error = false, int $status = 200): Response
    {
        $class = $error ? 'bp-error' : 'bp-notice';
        $body = AdminLayout::pageHeader('Media', 'Asset and library management result.');
        $body .= '<p class="' . $class . '">' . $this->e($message) . '</p><p>' . AdminLayout::buttonLink('Back to Media', '/admin/media', 'back', true) . '</p>';
        return Response::html($this->layout('Media', $body), $status);
    }
}
