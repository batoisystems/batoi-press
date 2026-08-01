<?php
declare(strict_types=1);

namespace Batoi\Press\Api;

use Batoi\Press\Application\ContentMutationException;
use Batoi\Press\Application\ContentMutationService;
use Batoi\Press\Application\IdempotencyStore;
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
    private ContentMutationService $mutations;
    private AuditLog $audit;

    public function __construct(
        private readonly Config $config,
        PageRepository $pages,
        PostRepository $posts
    ) {
        $this->reads = SiteReadService::create($config, $pages, $posts);
        $this->audit = new AuditLog($config->paths(), new FileStore());
        $this->mutations = new ContentMutationService($config, $pages, $posts, $this->audit, new IdempotencyStore($config->paths()));
    }

    public function handle(Request $request): Response
    {
        $requestId = $this->requestId($request);
        $requiredScope = $this->requiredScope($request);
        if ($requiredScope === null) {
            return $this->error('method_not_allowed', 'This method is not available for the requested API route.', 405, $requestId, ['Allow' => 'GET, POST, PATCH']);
        }
        try {
            $access = (new MachineAuthenticator($this->config))->authorize($request, [$requiredScope]);
        } catch (MachineAccessException $exception) {
            return $this->error($exception->errorCode(), $exception->getMessage(), $exception->status(), $requestId, $exception->headers());
        }

        try {
            $response = $this->route($request, $requestId, $access);
        } catch (ContentMutationException $exception) {
            $response = $this->error($exception->errorCode(), $exception->getMessage(), $exception->httpStatus(), $requestId, [], $exception->details());
        }
        $this->audit->record(
            'token:' . (string)($access['id'] ?? 'unknown'),
            'machine.api.' . strtolower($request->method),
            $request->path,
            (string)($request->server['REMOTE_ADDR'] ?? ''),
            $response->status() < 400 ? 'success' : 'failed',
            ['request_id' => $requestId, 'method' => $request->method, 'status' => $response->status()]
        );
        return $response;
    }

    private function route(Request $request, string $requestId, array $access): Response
    {
        if ($request->method !== 'GET') {
            return $this->mutate($request, $requestId, $access);
        }
        if ($request->path === '/api/v2') {
            return $this->success($this->reads->discovery() + [
                'links' => [
                    'site' => '/api/v2/site',
                    'pages' => '/api/v2/pages',
                    'posts' => '/api/v2/posts',
                    'taxonomies' => '/api/v2/taxonomies',
                    'media' => '/api/v2/media',
                    'content_health' => '/api/v2/content-health',
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
        if ($request->path === '/api/v2/taxonomies') {
            return $this->success($this->reads->taxonomies(), $requestId, true);
        }
        if ($request->path === '/api/v2/media') {
            return $this->success($this->reads->listMedia($request->query), $requestId);
        }
        if ($request->path === '/api/v2/content-health') {
            return $this->success($this->reads->contentHealth(trim((string)($request->query['id'] ?? ''))), $requestId);
        }
        if ($request->path === '/api/v2/menus') {
            return $this->success(['data' => $this->reads->listMenus()], $requestId);
        }

        foreach (['/api/v2/pages/' => 'page', '/api/v2/posts/' => 'post', '/api/v2/menus/' => 'menu', '/api/v2/media/' => 'media'] as $prefix => $kind) {
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

    private function mutate(Request $request, string $requestId, array $access): Response
    {
        if (!str_contains(strtolower($request->header('Content-Type')), 'application/json')) {
            return $this->error('unsupported_media_type', 'Content-Type must be application/json.', 415, $requestId);
        }
        if (strlen($request->rawBody) > 1100000 || (int)$request->header('Content-Length', '0') > 1100000) {
            return $this->error('payload_too_large', 'Request body exceeds the API limit.', 413, $requestId);
        }
        $input = $request->json();
        if ($input === null || ($input !== [] && array_is_list($input))) {
            return $this->error('invalid_json', 'Request body must be a JSON object.', 400, $requestId);
        }
        $actor = 'token:' . (string)($access['id'] ?? 'unknown');
        foreach (['page' => '/api/v2/pages', 'post' => '/api/v2/posts'] as $type => $collection) {
            if ($request->method === 'POST' && $request->path === $collection) {
                $result = $this->mutations->createDraft($type, $input, $actor, $request->header('Idempotency-Key'), $requestId);
                return $this->success($result, $requestId, false, 201);
            }
            if (!str_starts_with($request->path, $collection . '/')) {
                continue;
            }
            $tail = rawurldecode(substr($request->path, strlen($collection . '/')));
            $publish = str_ends_with($tail, '/publish');
            $identifier = $publish ? substr($tail, 0, -8) : $tail;
            if (preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $identifier) !== 1) {
                return $this->error('invalid_identifier', 'The resource identifier is invalid.', 400, $requestId);
            }
            if ($request->method === 'PATCH' && !$publish) {
                $result = $this->mutations->updateDraft($type, $identifier, $input, $request->header('If-Match'), $actor, $requestId);
                return $this->success($result, $requestId);
            }
            if ($request->method === 'POST' && $publish) {
                $result = $this->mutations->publish($type, $identifier, $request->header('If-Match'), $actor, $request->header('Idempotency-Key'), $requestId);
                return $this->success($result, $requestId);
            }
        }
        return $this->error('not_found', 'The requested mutation route was not found.', 404, $requestId);
    }

    private function success(array $data, string $requestId, bool $etag = false, int $status = 200): Response
    {
        $headers = $this->headers($requestId);
        if ($etag) {
            $revision = (string)($data['revision'] ?? ('sha256:' . hash('sha256', json_encode($data) ?: '')));
            $headers['ETag'] = '"' . str_replace(['"', '\\'], '', $revision) . '"';
        }
        return Response::json(['data' => $data, 'request_id' => $requestId], $status, $headers);
    }

    private function error(string $code, string $message, int $status, string $requestId, array $headers = [], array $details = []): Response
    {
        $error = ['code' => $code, 'message' => $message];
        if ($details !== []) {
            $error['details'] = $details;
        }
        return Response::json([
            'error' => $error,
            'request_id' => $requestId,
        ], $status, $this->headers($requestId) + $headers);
    }

    private function requiredScope(Request $request): ?string
    {
        $content = str_starts_with($request->path, '/api/v2/pages') || str_starts_with($request->path, '/api/v2/posts') || str_starts_with($request->path, '/api/v2/media') || $request->path === '/api/v2/taxonomies' || $request->path === '/api/v2/content-health';
        if ($request->method === 'GET') {
            return $content ? 'content:read' : 'site:read';
        }
        if ($content && $request->method === 'PATCH') {
            return 'content:write';
        }
        if ($content && $request->method === 'POST') {
            return str_ends_with($request->path, '/publish') ? 'content:publish' : 'content:write';
        }
        return null;
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
