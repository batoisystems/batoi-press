<?php
declare(strict_types=1);

namespace Batoi\Press\Content;

use Batoi\Press\Core\FileStore;
use Batoi\Press\Core\HtmlContent;
use Batoi\Press\Core\Paths;

final class PostRepository
{
    public function __construct(
        private readonly Paths $paths,
        private readonly FileStore $files,
        private readonly HtmlContent $html
    ) {
    }

    public function findBySlug(string $slug): ?array
    {
        foreach ($this->allPublished() as $post) {
            if (($post['slug'] ?? '') === $slug) {
                return $post;
            }
        }

        return null;
    }

    public function allPublished(): array
    {
        $base = $this->paths->contentPath('posts');
        if (!is_dir($base)) {
            return [];
        }

        $items = [];
        foreach (glob($base . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $metaFile = $dir . '/meta.json';
            $bodyFile = $dir . '/body.html';
            if (!is_file($metaFile) || !is_file($bodyFile)) {
                continue;
            }

            $meta = $this->files->readJson($metaFile);
            if (($meta['status'] ?? '') !== 'published') {
                continue;
            }

            $meta['body'] = $this->html->sanitize($this->files->read($bodyFile));
            $items[] = $meta;
        }

        usort($items, static fn (array $a, array $b): int => strcmp((string)($b['published_at'] ?? ''), (string)($a['published_at'] ?? '')));
        return $items;
    }
}

