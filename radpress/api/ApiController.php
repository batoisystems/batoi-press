<?php
declare(strict_types=1);

namespace Batoi\Press\Api;

use Batoi\Press\Application\SiteReadService;
use Batoi\Press\Content\PageRepository;
use Batoi\Press\Content\PostRepository;
use Batoi\Press\Core\AuditLog;
use Batoi\Press\Core\Config;
use Batoi\Press\Core\FileStore;
use Batoi\Press\Core\Request;
use Batoi\Press\Core\Response;
use Batoi\Press\Security\MachineAccessException;
use Batoi\Press\Security\MachineAuthenticator;

final class ApiController
{
    private SiteReadService $reads;
    private AuditLog $audit;

    public function __construct(
        private readonly Config $config,
        PageRepository $pages,
        PostRepository $posts
    ) {
        $this->reads = SiteReadService::create($config, $pages, $posts);
        $this->audit = new AuditLog($config->paths(), new FileStore());
    }

    public function handle(Request $request): Response
    {
        $requestId = $this->requestId($request);
        if ($request->method !== 'GET') {
            return $this->error('method_not_allowed', 'This API route supports GET requests only.', 405, $requestId, ['Allow' => 'GET']);
        }

        $requiredScope = str_starts_with($request->path, '/api/v2/pages') || str_starts_with($request->path, '/api/v2/posts')
            ? 'content:read'
            : 'site:read';
        try {
            $access = (new MachineAuthenticator($this->config))->authorize($request, [$requiredScope]);
        } catch (MachineAccessException $exception) {
            return $this->error($exception->errorCode(), $exception->getMessage(), $exception->status(), $requestId, $exception->headers());
        }

        $response = $this->route($request, $requestId);
        $this->audit->record(
            'token:' . (string)($access['id'] ?? 'unknown'),
            'machine.api.read',
            $request->path,
            (string)($request->server['REMOTE_ADDR'] ?? ''),
            $response->status() < 400 ? 'success' : 'failed',
            ['request_id' => $requestId, 'method' => $request->method, 'status' => $response->status()]
        );
        return $response;
    }

    private function route(Request $request, string $requestId): Response
    {
        if ($request->path === '/api/v2') {
            return $this->success($this->reads->discovery() + [
                'links' => [
                    'site' => '/api/v2/site',
                    'pages' => '/api/v2/pages',
                    'posts' => '/api/v2/posts',
                    'menus' => '/api/v2/menus',
                    'mcp' => '/mcp',
                ],
            ], $requestId);
        }
        if ($request->path === '/api/v2/site') {
            return $this->success($this->reads->site(), $requestId, true);
        }
        if ($request->path === '/api/v2/pages') {
            return $this->success($this->reads->listPages($request->query), $requestId);
        }
        if ($request->path === '/api/v2/posts') {
            return $this->success($this->reads->listPosts($request->query), $requestId);
        }
        if ($request->path === '/api/v2/menus') {
            return $this->success(['data' => $this->reads->listMenus()], $requestId);
        }

        foreach (['/api/v2/pages/' => 'page', '/api/v2/posts/' => 'post', '/api/v2/menus/' => 'menu'] as $prefix => $kind) {
            if (!str_starts_with($request->path, $prefix)) {
                continue;
            }
            $identifier = rawurldecode(substr($request->path, strlen($prefix)));
            if (preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $identifier) !== 1) {
                return $this->error('invalid_identifier', 'The resource identifier is invalid.', 400, $requestId);
            }
            $record = $this->reads->{$kind}($identifier);
            return $record === null
                ? $this->error('not_found', 'The requested resource was not found.', 404, $requestId)
                : $this->success($record, $requestId, true);
        }

        return $this->error('not_found', 'The requested API route was not found.', 404, $requestId);
    }

    private function success(array $data, string $requestId, bool $etag = false): Response
    {
        $headers = $this->headers($requestId);
        if ($etag) {
            $revision = (string)($data['revision'] ?? ('sha256:' . hash('sha256', json_encode($data) ?: '')));
            $headers['ETag'] = '"' . str_replace(['"', '\\'], '', $revision) . '"';
        }
        return Response::json(['data' => $data, 'request_id' => $requestId], 200, $headers);
    }

    private function error(string $code, string $message, int $status, string $requestId, array $headers = []): Response
    {
        return Response::json([
            'error' => ['code' => $code, 'message' => $message],
            'request_id' => $requestId,
        ], $status, $this->headers($requestId) + $headers);
    }

    private function headers(string $requestId): array
    {
        return [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'X-Request-Id' => $requestId,
        ];
    }

    private function requestId(Request $request): string
    {
        $provided = $request->header('X-Request-Id');
        return preg_match('/^[A-Za-z0-9._-]{8,128}$/D', $provided) === 1
            ? $provided
            : 'req_' . bin2hex(random_bytes(12));
    }
}
