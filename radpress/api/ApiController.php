<?php
declare(strict_types=1);

namespace Batoi\Press\Api;

use Batoi\Press\Application\ContentMutationException;
use Batoi\Press\Application\ActivityReadService;
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
            $access = (new MachineAuthenticator($this->config))->authorize($request, $requiredScope === 'media:read' ? [] : [$requiredScope], false, $requiredScope === 'media:read' ? ['media:read', 'content:read'] : []);
        } catch (MachineAccessException $exception) {
            return $this->error($exception->errorCode(), $exception->getMessage(), $exception->status(), $requestId, $exception->headers());
        }

        try {
            $response = $this->route($request, $requestId, $access);
        } catch (ContentMutationException $exception) {
            $response = $this->error($exception->errorCode(), $exception->getMessage(), $exception->httpStatus(), $requestId, [], $exception->details());
        } catch (\Throwable $exception) {
            $response = $this->error('operation_failed', 'The operation could not be completed. Check proposal status before retrying a mutation.', 500, $requestId);
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
                    'activity' => '/api/v2/activity',
                    'widgets' => '/api/v2/widgets',
                    'public_settings' => '/api/v2/public-settings',
                    'mcp' => '/mcp',
                ],
            ], $requestId);
        }
        if ($request->path === '/api/v2/site') {
            return $this->success($this->reads->site(), $requestId, true);
        }
        if ($request->path === '/api/v2/widgets') return $this->success((new \Batoi\Press\Content\WidgetRepository($this->config->paths()))->load(), $requestId, true);
        if (preg_match('#^/api/v2/media-proposals/(proposal_[a-f0-9]{32})$#D', $request->path, $match)) return $this->success($this->mutations->menuProposalSummary($match[1], $access, 'media'), $requestId);
        if ($request->path === '/api/v2/public-settings') return $this->success((new \Batoi\Press\Content\PublicSettingsRepository($this->config->paths()))->load(), $requestId, true);
        if (preg_match('#^/api/v2/settings-proposals/(proposal_[a-f0-9]{32})$#D', $request->path, $match)) return $this->success($this->mutations->menuProposalSummary($match[1], $access, 'site'), $requestId);
        if (preg_match('#^/api/v2/widget-proposals/(proposal_[a-f0-9]{32})$#D', $request->path, $match)) return $this->success($this->mutations->menuProposalSummary($match[1], $access, 'widgets'), $requestId);
        if (preg_match('#^/api/v2/menu-proposals/(proposal_[a-f0-9]{32})$#D', $request->path, $match)) {
            return $this->success($this->mutations->menuProposalSummary($match[1], $access), $requestId);
        }
        if ($request->path === '/api/v2/activity') {
            return $this->success((new ActivityReadService($this->config->paths()))->report($request->query), $requestId);
        }
        if (preg_match('#^/api/v2/proposals/(proposal_[a-f0-9]{32})$#D', $request->path, $match)) {
            return $this->success($this->mutations->proposalSummary($this->mutations->proposal($match[1], $access)), $requestId);
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
        if ($request->method === 'POST' && $request->path === '/api/v2/media/uploads') return $this->success($this->mutations->proposeMediaUpload($input, $access, $request->header('Idempotency-Key'), $requestId), $requestId, false, 202);
        if ($request->method === 'POST' && preg_match('#^/api/v2/media/(asset_[a-f0-9]{24})/proposals$#D', $request->path, $match)) return $this->success($this->mutations->proposeMediaMetadata($match[1], $input, $request->header('If-Match'), $access, $request->header('Idempotency-Key'), $requestId), $requestId, false, 202);
        if ($request->method === 'POST' && $request->path === '/api/v2/public-settings/proposals') {
            return $this->success($this->mutations->proposePublicSettings($input, $request->header('If-Match'), $access, $request->header('Idempotency-Key'), $requestId), $requestId, false, 202);
        }
        if ($request->method === 'POST' && $request->path === '/api/v2/widgets/proposals') {
            if (array_keys($input) !== ['widgets'] || !is_array($input['widgets'])) return $this->error('validation_failed', 'Supply an ordered widgets list.', 422, $requestId);
            return $this->success($this->mutations->proposeWidgets($input['widgets'], $request->header('If-Match'), $access, $request->header('Idempotency-Key'), $requestId), $requestId, false, 202);
        }
        if ($request->method === 'POST' && preg_match('#^/api/v2/menus/([a-z][a-z0-9_-]{0,63})/proposals$#D', $request->path, $match)) {
            $revision = trim($request->header('If-Match'), '" ');
            if (!preg_match('/^[0-9]{1,9}$/D', $revision)) return $this->error('revision_required', 'If-Match must contain the integer menu revision.', 422, $requestId);
            return $this->success($this->mutations->proposeMenu($match[1], $input, (int)$revision, $access, $request->header('Idempotency-Key'), $requestId), $requestId, false, 202);
        }
        if ($request->method === 'POST' && preg_match('#^/api/v2/proposals/(proposal_[a-f0-9]{32})/restore$#D', $request->path, $match)) {
            if ($input !== []) return $this->error('validation_failed', 'Restoration accepts no content overrides.', 422, $requestId);
            return $this->success($this->mutations->proposeRestoration($match[1], $request->header('If-Match'), $access, $request->header('Idempotency-Key'), $requestId), $requestId, false, 202);
        }
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
            $propose = str_ends_with($tail, '/proposals');
            $identifier = $publish ? substr($tail, 0, -8) : ($propose ? substr($tail, 0, -10) : $tail);
            if (preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $identifier) !== 1) {
                return $this->error('invalid_identifier', 'The resource identifier is invalid.', 400, $requestId);
            }
            if ($request->method === 'PATCH' && !$publish && !$propose) {
                $result = $this->mutations->updateDraft($type, $identifier, $input, $request->header('If-Match'), $actor, $requestId);
                return $this->success($result, $requestId);
            }
            if ($request->method === 'POST' && $publish) {
                $result = $this->mutations->propose($type, $identifier, [], $request->header('If-Match'), $access, $request->header('Idempotency-Key'), 'publish', $requestId);
                return $this->success($result, $requestId, false, 202);
            }
            if ($request->method === 'POST' && $propose) {
                if (!is_array($input['changes'] ?? null) || array_diff(array_keys($input), ['changes', 'action']) !== []) return $this->error('validation_failed', 'Supply changes and an optional action.', 422, $requestId);
                $result = $this->mutations->propose($type, $identifier, $input['changes'], $request->header('If-Match'), $access, $request->header('Idempotency-Key'), (string)($input['action'] ?? 'retain'), $requestId);
                return $this->success($result, $requestId, false, 202);
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
        if ($request->path === '/api/v2/media/uploads' || preg_match('#^/api/v2/media/asset_[a-f0-9]{24}/proposals$#D', $request->path)) return $request->method === 'POST' ? 'media:write' : null;
        if ($request->path === '/api/v2/media' || str_starts_with($request->path, '/api/v2/media/') || str_starts_with($request->path, '/api/v2/media-proposals/')) return $request->method === 'GET' ? 'media:read' : null;
        if ($request->path === '/api/v2/public-settings/proposals') return $request->method === 'POST' ? 'site:write' : null;
        if ($request->path === '/api/v2/public-settings' || str_starts_with($request->path, '/api/v2/settings-proposals/')) return $request->method === 'GET' ? 'site:read' : null;
        if ($request->path === '/api/v2/widgets/proposals') return $request->method === 'POST' ? 'site:write' : null;
        if ($request->path === '/api/v2/widgets' || str_starts_with($request->path, '/api/v2/widget-proposals/')) return $request->method === 'GET' ? 'site:read' : null;
        if (str_starts_with($request->path, '/api/v2/menu-proposals/')) return $request->method === 'GET' ? 'site:read' : null;
        if (preg_match('#^/api/v2/menus/[a-z][a-z0-9_-]{0,63}/proposals$#D', $request->path)) return $request->method === 'POST' ? 'site:write' : null;
        if ($request->path === '/api/v2/activity') return $request->method === 'GET' ? 'audit:read' : null;
        if (str_starts_with($request->path, '/api/v2/proposals/') && $request->method === 'GET') return 'content:read';
        if (str_starts_with($request->path, '/api/v2/proposals/') && $request->method === 'POST') return 'content:write';
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
