<?php
declare(strict_types=1);

namespace Batoi\Press\Content;

use Batoi\Press\Core\{FileStore, HtmlContent, Paths, Slug};
use RuntimeException;

final class ContentLookup
{
    public static function find(Paths $paths, FileStore $files, HtmlContent $html, string $type, string $slug): ?array
    {
        if ($slug === '' || Slug::normalize($slug) !== $slug || strlen($slug) > 240) return null;
        $record = (new ContentTransaction($paths, $files))->read($type, static function () use ($paths, $files, $html, $type, $slug): array {
            $dir = $paths->contentPath($type . 's/' . $slug);
            if (is_link($dir) || is_link($dir . '/meta.json') || is_link($dir . '/body.html')) throw new RuntimeException('Symlinked content is unsupported.');
            if (!is_file($dir . '/meta.json') || !is_file($dir . '/body.html')) return [];
            $record = $files->readJson($dir . '/meta.json');
            if (($record['slug'] ?? '') !== $slug) return [];
            $record['body'] = $html->sanitize($files->read($dir . '/body.html'));
            return $record;
        });
        return $record === [] ? null : $record;
    }
}
