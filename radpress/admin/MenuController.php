<?php
declare(strict_types=1);

namespace Batoi\Press\Admin;

use Batoi\Press\Core\AuditLog;
use Batoi\Press\Core\Config;
use Batoi\Press\Core\FileStore;
use Batoi\Press\Core\Request;
use Batoi\Press\Core\Response;
use Batoi\Press\Security\Csrf;

final class MenuController
{
    public function __construct(
        private readonly Config $config,
        private readonly FileStore $files,
        private readonly Csrf $csrf,
        private readonly AuditLog $audit,
        private readonly array $user
    ) {
    }

    public function edit(): Response
    {
        $menu = $this->menu();
        $lines = [];
        foreach (($menu['items'] ?? []) as $item) {
            $lines[] = (string)($item['label'] ?? '') . '|' . (string)($item['url'] ?? '');
        }

        $body = '<h1>Main Menu</h1><p>Use one item per line in the format <code>Label|/url</code>.</p><form method="post" action="/admin/menus/save" class="bp-form">';
        $body .= $this->csrf->field();
        $body .= '<label>Menu Items <textarea name="items" rows="12">' . $this->e(implode("\n", $lines)) . '</textarea></label>';
        $body .= '<button type="submit">Save Menu</button></form><p><a href="/admin">Back to admin</a></p>';
        return Response::html($this->layout('Menus', $body));
    }

    public function save(Request $request): Response
    {
        if (!$this->csrf->validate($request->input('csrf_token'))) {
            return Response::html($this->layout('Menus', '<p class="bp-error">Security token expired.</p>'), 400);
        }

        $items = [];
        foreach (preg_split('/\r\n|\r|\n/', $request->input('items')) ?: [] as $line) {
            $parts = array_map('trim', explode('|', $line, 2));
            if (count($parts) === 2 && $parts[0] !== '' && $parts[1] !== '') {
                $items[] = ['label' => $parts[0], 'url' => $parts[1]];
            }
        }

        $this->files->writeJson($this->config->paths()->contentPath('menus/main.json'), ['items' => $items]);
        $this->audit->record((string)($this->user['username'] ?? 'admin'), 'menu.updated', 'main', (string)($_SERVER['REMOTE_ADDR'] ?? ''));

        return Response::redirect('/admin/menus');
    }

    private function menu(): array
    {
        $path = $this->config->paths()->contentPath('menus/main.json');
        return is_file($path) ? $this->files->readJson($path) : ['items' => []];
    }

    private function layout(string $title, string $body): string
    {
        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>' . $this->e($title) . ' | Batoi Press</title><link rel="stylesheet" href="/assets/css/style.css"></head><body><main class="bp-admin">' . $body . '</main></body></html>';
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
