<?php
declare(strict_types=1);

namespace Batoi\Press\Core;

/** Native PHP is trusted code. This API is a contract, not a sandbox. */
final class PluginContext
{
    private array $listeners = [];
    private array $blocks = [];
    private array $adminPages = [];
    public const EVENTS = ['form.accepted', 'delivery.completed', 'content.changed'];

    public function __construct(private readonly ?Paths $paths = null, private readonly array $plugin = [], private readonly ?self $bus = null) {}

    public function scope(Paths $paths, array $plugin): self { return new self($paths, $plugin, $this); }

    public function settings(): array
    {
        $configuration = $this->state()->configuration($this->plugin);
        if ($configuration['issues'] !== [] || $configuration['schema'] !== ($this->plugin['data_schema'] ?? 1)) throw new \RuntimeException('Plugin configuration requires reload or recovery.');
        return $configuration['values'];
    }

    public function data(): array
    {
        $state = $this->state()->read($this->plugin['id']);
        if ($state['schema'] !== ($this->plugin['data_schema'] ?? 1)) throw new \RuntimeException('Plugin data schema changed. Reload the plugin.');
        return ['values'=>$state['data'], 'revision'=>\Batoi\Press\Application\ContentRevision::for($state)];
    }

    public function replaceData(array $values, string $revision): void
    {
        $this->requireCapability('state.write');
        $this->state()->replaceData($this->plugin['id'], $values, $revision, $this->plugin['data_schema'] ?? 1);
    }

    public function publishedPages(): array
    {
        $this->requireCapability('content.read');
        $pages = (new \Batoi\Press\Content\PageRepository($this->paths, new FileStore(), new HtmlContent()))->allPublished();
        return array_map(static fn(array $page): array => array_intersect_key($page, array_flip(['slug','title','seo_description','status'])), array_slice($pages, 0, 200));
    }

    /** Same bearer identity, scope, revision and approval policy used by API/MCP. */
    public function proposeContent(Request $request, string $type, string $identifier, array $changes, string $revision, string $key, string $action = 'retain'): array
    {
        $this->requireCapability('content.propose');
        if ($request->method !== 'POST') throw new \RuntimeException('Proposals require POST.');
        $config = Config::load($this->paths->root());
        $required = $action === 'retain' ? ['content:write'] : ['content:publish'];
        if ($changes !== []) $required[] = 'content:write';
        $access = (new \Batoi\Press\Security\MachineAuthenticator($config))->authorize($request, array_unique($required), true);
        $files = new FileStore(); $html = new HtmlContent();
        $service = new \Batoi\Press\Application\ContentMutationService($config,
            new \Batoi\Press\Content\PageRepository($this->paths, $files, $html),
            new \Batoi\Press\Content\PostRepository($this->paths, $files, $html),
            new AuditLog($this->paths, $files), new \Batoi\Press\Application\IdempotencyStore($this->paths));
        return $service->propose($type, $identifier, $changes, $revision, $access, $key, $action, $request->requestId);
    }

    public function media(Request $request, array $filters = []): array
    {
        $this->requireCapability('media.read');
        $config = Config::load($this->paths->root());
        (new \Batoi\Press\Security\MachineAuthenticator($config))->authorize($request, ['media:read'], true);
        $files = new FileStore(); $html = new HtmlContent();
        return \Batoi\Press\Application\SiteReadService::create($config,
            new \Batoi\Press\Content\PageRepository($this->paths, $files, $html),
            new \Batoi\Press\Content\PostRepository($this->paths, $files, $html))->listMedia($filters);
    }

    public function audit(Request $request, string $action, string $target): void
    {
        $this->requireCapability('audit.write');
        if ($request->method !== 'POST' || preg_match('/^[a-z][a-z0-9_.-]{0,39}$/D', $action) !== 1 || preg_match('/^[a-zA-Z0-9_-]{1,128}$/D', $target) !== 1) throw new \RuntimeException('Invalid plugin audit action.');
        $access = (new \Batoi\Press\Security\MachineAuthenticator(Config::load($this->paths->root())))->authorize($request, ['audit:read'], true);
        (new AuditLog($this->paths, new FileStore()))->record('token:' . $access['id'], 'plugin.' . $this->plugin['id'] . '.' . $action, $target, '', 'success', ['request_id'=>$request->requestId]);
    }

    /** Uses configured form actions, CSRF, quotas, CAPTCHA and the existing mail queue. */
    public function submitForm(string $id, Request $request): Response
    {
        if ($this->paths === null) throw new \RuntimeException('Form services require a scoped context.');
        $form = (new \Batoi\Press\Content\FormRepository($this->paths))->find($id);
        if ($form === null || $request->method !== 'POST') throw new \RuntimeException('A configured form POST is required.');
        $this->requireCapability($form['action'] === 'webhook' ? 'forms.webhook' : 'forms.email');
        return (new \Batoi\Press\Application\FormController(Config::load($this->paths->root())))->handle($id, $request);
    }

    public function registerBlock(string $name, callable $renderer): void
    {
        $this->requireCapability('blocks.render');
        $name = PluginManager::id($name);
        $key = $this->plugin['id'] . ':' . $name;
        if ($this->bus === null || isset($this->bus->blocks[$key]) || count($this->bus->blocks) >= 100) throw new \RuntimeException('Invalid or duplicate plugin block registration.');
        $this->bus->blocks[$key] = $renderer;
    }

    public function renderBlock(string $key, array $settings): string
    {
        if ($this->bus !== null) throw new \RuntimeException('Core rendering requires the event bus.');
        if (!isset($this->blocks[$key])) return '';
        try {
            $html = ($this->blocks[$key])($settings);
            return is_string($html) && strlen($html) <= 65536 ? (new HtmlContent())->sanitize($html) : '';
        } catch (\Throwable) { error_log('Batoi Press: optional plugin block failed.'); return ''; }
    }

    public function registerAdminPage(string $name, string $title, callable $handler): void
    {
        $this->requireCapability('admin.routes');
        $key = $this->plugin['id'] . '/' . PluginManager::id($name);
        if ($this->bus === null || trim($title) === '' || strlen($title) > 160 || isset($this->bus->adminPages[$key]) || count($this->bus->adminPages) >= 100) throw new \RuntimeException('Invalid or duplicate admin page registration.');
        $this->bus->adminPages[$key] = ['title'=>$title, 'handler'=>$handler];
    }

    /** Router calls only after authenticated owner/admin, CSRF and quota checks. */
    public function adminPage(string $key, Request $request, \Batoi\Press\Security\Csrf $csrf): Response
    {
        if ($this->bus !== null || !isset($this->adminPages[$key])) return Response::html('Extension page unavailable.', 404);
        $page = $this->adminPages[$key];
        $body = ($page['handler'])($request, $csrf);
        if (!is_string($body) || strlen($body) > 65536) throw new \RuntimeException('Invalid extension page output.');
        return Response::html(\Batoi\Press\Admin\AdminLayout::render($page['title'], \Batoi\Press\Admin\AdminLayout::pageHeader($page['title'], 'Installed extension') . $body))->withHeader('Cache-Control', 'private, no-store');
    }

    private function state(): PluginState
    {
        if ($this->paths === null || !isset($this->plugin['id'])) throw new \RuntimeException('Plugin state requires a scoped context.');
        return new PluginState($this->paths);
    }

    private function requireCapability(string $capability): void
    {
        if ($this->paths === null || !in_array($capability, $this->plugin['capabilities'] ?? [], true)) throw new \RuntimeException('Plugin did not declare this service capability.');
    }


    public function on(string $event, callable $listener): void
    {
        if (!in_array($event, self::EVENTS, true)) throw new \RuntimeException('Unsupported plugin event.');
        if ($this->bus !== null) { $this->requireCapability('events.listen'); $this->bus->listeners[$event][] = $listener; }
        else $this->listeners[$event][] = $listener;
    }

    public function emit(string $event, array $metadata): void
    {
        if ($this->bus !== null) throw new \RuntimeException('Only core services emit plugin events.');
        if (!in_array($event, self::EVENTS, true) || !is_string($metadata['id'] ?? null) || preg_match('/^[a-zA-Z0-9_-]{1,128}$/D', $metadata['id']) !== 1) throw new \RuntimeException('Invalid plugin event metadata.');
        $payload = ['id' => $metadata['id']];
        if ($event === 'form.accepted') {
            if (!is_string($metadata['form_id'] ?? null)) throw new \RuntimeException('Invalid form event metadata.');
            $payload['form_id'] = PluginManager::id($metadata['form_id']);
        } elseif ($event === 'delivery.completed') {
            if (!is_bool($metadata['success'] ?? null)) throw new \RuntimeException('Invalid delivery event metadata.');
            $payload['success'] = $metadata['success'];
        } else {
            if (!in_array($metadata['type'] ?? '', ['page','post'], true) || !is_string($metadata['action'] ?? null) || preg_match('/^[a-z][a-z_.]{0,63}$/D', $metadata['action']) !== 1) throw new \RuntimeException('Invalid content event metadata.');
            $payload += ['type'=>$metadata['type'], 'action'=>$metadata['action']];
        }
        foreach ($this->listeners[$event] ?? [] as $listener) {
            try { $listener($payload); }
            catch (\Throwable) { error_log('Batoi Press: optional plugin event callback failed.'); }
        }
    }
}
