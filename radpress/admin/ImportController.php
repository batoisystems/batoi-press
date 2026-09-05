<?php
declare(strict_types=1);

namespace Batoi\Press\Admin;

use Batoi\Press\Content\PageRepository;
use Batoi\Press\Content\PostRepository;
use Batoi\Press\Core\AssetManager;
use Batoi\Press\Core\AuditLog;
use Batoi\Press\Core\Config;
use Batoi\Press\Core\Response;
use Batoi\Press\Core\Slug;
use Batoi\Press\Security\Csrf;
use Batoi\Press\Security\UploadGuard;
use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;

final class ImportController
{
    public function __construct(private readonly Config $config, private readonly PageRepository $pages, private readonly PostRepository $posts, private readonly Csrf $csrf, private readonly AuditLog $audit, private readonly array $user)
    {
    }

    public function xml(): Response
    {
        if (!$this->csrf->validate((string)($_POST['csrf_token'] ?? ''))) return Response::html(AdminLayout::message('Import', 'Security token expired.', true), 400);
        $upload = $_FILES['site_xml'] ?? [];
        if (!is_array($upload) || (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || (int)($upload['size'] ?? 0) < 1 || (int)($upload['size'] ?? 0) > 10485760) {
            return Response::html(AdminLayout::message('Import', 'Choose a non-empty Batoi Press XML file no larger than 10 MiB.', true), 400);
        }
        $source = (string)file_get_contents((string)($upload['tmp_name'] ?? ''));
        try {
            $counts = $this->import($source);
        } catch (RuntimeException $exception) {
            return Response::html(AdminLayout::message('Import', $exception->getMessage(), true), 422);
        }
        $summary = 'Imported ' . $counts['pages'] . ' pages, ' . $counts['posts'] . ' posts, and ' . $counts['media'] . ' media files; skipped ' . $counts['skipped'] . ' existing or invalid items.';
        $this->audit->record((string)($this->user['username'] ?? 'admin'), 'site.xml_imported', $summary, (string)($_SERVER['REMOTE_ADDR'] ?? ''));
        return Response::html(AdminLayout::render('Import Complete', AdminLayout::pageHeader('Import Complete', $summary, AdminLayout::buttonLink('Back to Settings', '/admin/settings', 'back', true))));
    }

    private function import(string $xml): array
    {
        if (!class_exists(DOMDocument::class)) throw new RuntimeException('XML import requires the PHP DOM extension.');
        if ($xml === '' || strlen($xml) > 10485760) throw new RuntimeException('XML content must be no larger than 10 MiB.');
        if (stripos($xml, '<!doctype') !== false || stripos($xml, '<!entity') !== false) throw new RuntimeException('XML document type and entity declarations are not allowed.');
        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument();
        $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded || $document->documentElement?->tagName !== 'batoi-press') throw new RuntimeException('This is not a valid Batoi Press site-content XML document.');
        if ($document->doctype !== null) throw new RuntimeException('XML document type declarations are not allowed.');
        $xpath = new DOMXPath($document);
        $counts = ['pages' => 0, 'posts' => 0, 'media' => 0, 'skipped' => 0];
        foreach (['page' => $this->pages, 'post' => $this->posts] as $type => $repository) {
            $pending = [];
            foreach ($xpath->query('/batoi-press/' . $type . 's/' . $type) ?: [] as $node) {
                if (!$node instanceof DOMElement) continue;
                $data = $this->contentData($node, $type);
                if ($data['slug'] === '' || $data['title'] === '' || isset($pending[$data['slug']]) || $repository->findBySlug($data['slug']) !== null) { $counts['skipped']++; continue; }
                $pending[$data['slug']] = $data;
            }
            // Resolve parents before children, regardless of their order in the XML.
            do {
                $progress = false;
                foreach ($pending as $slug => $data) {
                    if ($data['parent_slug'] !== '' && $repository->findBySlug($data['parent_slug']) === null) continue;
                    $repository->save($data, (string)($this->user['username'] ?? 'admin'));
                    unset($pending[$slug]);
                    $counts[$type . 's']++;
                    $progress = true;
                }
            } while ($progress && $pending !== []);
            $counts['skipped'] += count($pending);
        }
        foreach ($xpath->query('/batoi-press/media/file') ?: [] as $node) {
            if (!$node instanceof DOMElement || $node->getAttribute('encoding') !== 'base64') { $counts['skipped']++; continue; }
            $bytes = base64_decode(trim($node->textContent), true);
            $extensions = AssetManager::effectiveUploadExtensions((array)($this->config->security()['uploads']['allowed_extensions'] ?? []));
            $maxBytes = min(10485760, AssetManager::effectiveMaxBytes((int)($this->config->security()['uploads']['max_bytes'] ?? 0)));
            $guard = new UploadGuard($extensions, $maxBytes);
            $name = $guard->safeName($node->getAttribute('name'));
            if (!is_string($bytes) || $bytes === '' || strlen($bytes) > $maxBytes) { $counts['skipped']++; continue; }
            $inspection = $this->config->paths()->dataPath('tmp/import-media-' . bin2hex(random_bytes(5)));
            if (!is_dir(dirname($inspection))) mkdir(dirname($inspection), 0775, true);
            if (file_put_contents($inspection, $bytes, LOCK_EX) === false) throw new RuntimeException('Unable to inspect imported media.');
            $validationError = $guard->validate(['error'=>UPLOAD_ERR_OK,'size'=>strlen($bytes),'name'=>$name,'tmp_name'=>$inspection]);
            unlink($inspection);
            if ($validationError !== null) { $counts['skipped']++; continue; }
            $manager = new AssetManager($this->config->paths());
            $relative = $manager->relativeUploadPath($name);
            $target = $manager->prepareTarget($relative);
            if (file_put_contents($target, $bytes, LOCK_EX) === false) throw new RuntimeException('Unable to write imported media.');
            $counts['media']++;
        }
        return $counts;
    }

    private function contentData(DOMElement $node, string $type): array
    {
        $value = static function(DOMElement $element, string $tag): string {
            $nodes = $element->getElementsByTagName($tag);
            return $nodes->length > 0 ? trim((string)$nodes->item(0)?->textContent) : '';
        };
        $data = ['title'=>$value($node,'title'),'slug'=>Slug::normalize($value($node,'slug')),'status'=>$value($node,'status') ?: 'draft','body'=>$value($node,'body'),'parent_slug'=>Slug::normalize($value($node,'parent_slug')),'category'=>$value($node,'category'),'tags'=>$value($node,'tags'),'seo_title'=>$value($node,'seo_title'),'seo_description'=>$value($node,'seo_description')];
        if ($type === 'page') $data['template'] = $value($node, 'template') ?: 'page';
        return $data;
    }
}
