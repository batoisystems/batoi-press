<?php
declare(strict_types=1);
namespace Batoi\Press\Application;

use Batoi\Press\Core\{Config, FileStore, UifThemeKit};
use Batoi\Press\Security\{MachineAccessPolicy, SecretStore};

/** Immutable, data-only theme drafts. No install, file-edit or activation authority. */
final class ThemeDrafts
{
    public function __construct(private readonly Config $config,private readonly ?UifThemeKit $kit = null) {}

    public static function kit(): array
    {
        return ['kit'=>UifThemeKit::VERSION,'starter'=>UifThemeKit::starter(),'schema'=>UifThemeKit::schema(),
            'workflow'=>'Return data-only JSON. Validate, then create a private draft and give its review_url to the owner. The owner downloads, uploads inactive, previews and activates through Themes. Draft status never means installed or live.',
            'targets'=>['responsive_web','mobile_browser','desktop_browser'],
            'limits'=>['definition_bytes'=>65536,'sections'=>20,'executable_input'=>false,'native_app_binaries'=>false]];
    }

    public function validate(array $definition): array
    {
        $kit = $this->kit ?? new UifThemeKit();
        try { $definition = $kit->validate($definition); }
        catch (\RuntimeException $error) { throw new ContentMutationException($error->getMessage(),'theme_invalid',422); }
        try { $files = $kit->files($definition); }
        catch (\Throwable) { throw new ContentMutationException('Bundled theme compilation is unavailable. Ask the owner to check the installation.','theme_unavailable',503); }
        $inventory = [];
        foreach ($files as $name => $body) $inventory[$name] = hash('sha256',$body);
        ksort($inventory);
        return ['valid'=>true,'definition'=>$definition,'source_revision'=>ContentRevision::for($inventory),'file_count'=>count($files),'extracted_bytes'=>array_sum(array_map('strlen',$files)),
            'review_required'=>['content_and_brand','keyboard_and_contrast','mobile_and_desktop','all_layout_previews'], 'installed'=>false,'active'=>false];
    }

    public function create(array $definition,array $access,string $key): array
    {
        $this->authority($access,['theme:read','theme:write']);
        if (!preg_match('/^[A-Za-z0-9._:-]{8,128}$/D',$key)) throw new ContentMutationException('A valid idempotency key is required.','idempotency_key_required',400);
        $validated = $this->validate($definition);
        $id = 'theme_' . substr(hash('sha256',$access['id'] . "\0" . $key),0,32);
        return $this->locked(function () use ($id,$validated,$access): array {
            $path = $this->path($id); $requestHash = ContentRevision::for($validated['definition']);
            if (is_file($path)) {
                $record = $this->read($id,$access);
                if ($record['request_hash'] !== $requestHash) throw new ContentMutationException('This theme draft key already has different input. Use a new key for a new draft.','idempotency_conflict',409);
                return $this->summary($record) + ['idempotent_replay'=>true];
            }
            $paths = glob($this->root() . '/theme_*.json') ?: []; $bytes = 0;
            foreach ($paths as $file) { if (is_link($file)) throw new \RuntimeException('Linked theme storage is unsupported.'); $bytes += (int)filesize($file); }
            if (count($paths) >= 1000 || $bytes >= 67108864) throw new ContentMutationException('Theme draft history is full. Ask the owner to archive it.','theme_quota',413);
            $expires = time() + 86400;
            if (!empty($access['expires_at'])) $expires = min($expires,strtotime($access['expires_at']) ?: 0);
            if ($expires <= time()) throw new ContentMutationException('The connection expired.','connection_revoked',403);
            $record = ['schema'=>1,'id'=>$id,'site'=>$this->site(),'connection_id'=>$access['id'],'principal'=>$access['principal'],'request_hash'=>$requestHash,
                'definition'=>$validated['definition'],'source_revision'=>$validated['source_revision'],'created_at'=>time(),'expires_at'=>$expires];
            $encoded = (new SecretStore($this->config->paths()))->encrypt(json_encode($record,JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            (new FileStore())->writeJson($path,['payload'=>$encoded]); chmod($path,0600);
            (new \Batoi\Press\Core\AuditLog($this->config->paths(),new FileStore()))->record('token:' . $access['id'],'theme.draft.created',$id,'','success',['principal'=>$access['principal'],'source_revision'=>$validated['source_revision']]);
            return $this->summary($record) + ['idempotent_replay'=>false];
        });
    }

    public function get(string $id,?array $access = null): array
    {
        return $this->locked(function () use ($id,$access): array {
            if ($access !== null) $this->authority($access,['theme:read']);
            $record = $this->read($id,$access);
            return $this->summary($record) + ['definition'=>$record['definition'],'source_revision'=>$record['source_revision'],'approval_hash'=>ContentRevision::for($record)];
        });
    }

    public function forDownload(string $id,string $hash): array
    {
        return $this->locked(function () use ($id,$hash): array {
            $record = $this->read($id);
            if (!hash_equals(ContentRevision::for($record),$hash)) throw new ContentMutationException('Review the exact theme draft before downloading.','approval_mismatch',409);
            if ($this->state($record) !== 'pending_review') throw new ContentMutationException('The draft expired or its connection no longer has permission. Create a new draft.','connection_revoked',409);
            $current = $this->validate($record['definition']);
            if (!hash_equals($record['source_revision'],$current['source_revision'])) throw new ContentMutationException('The bundled theme source changed. Create a new draft and review its generated files.','revision_conflict',409);
            return $record['definition'];
        });
    }

    private function summary(array $record): array
    {
        $url = '/admin/themes/uif-kit?draft=' . $record['id'];
        return ['id'=>$record['id'],'name'=>$record['definition']['name'],'slug'=>$record['definition']['slug'],'state'=>$this->state($record),'created_at'=>$record['created_at'],'expires_at'=>$record['expires_at'],
            'review_url'=>(function_exists('bp_url') ? \bp_url($url) : $url),'installed'=>false,'active'=>false];
    }
    private function state(array $record): string
    {
        if ($record['expires_at'] <= time()) return 'expired';
        try { $this->authority(['id'=>$record['connection_id'],'principal'=>$record['principal'],'scopes'=>['theme:read','theme:write']],['theme:read','theme:write']); }
        catch (ContentMutationException) { return 'connection_revoked'; }
        return 'pending_review';
    }
    private function authority(array $access,array $scopes): void
    {
        $current = (new MachineAccessPolicy($this->config->paths()))->connection((string)($access['id'] ?? ''));
        if ($current === null || ($current['role'] ?? '') !== 'owner' || ($current['principal'] ?? '') !== ($access['principal'] ?? '') || array_diff($scopes,(array)($access['scopes'] ?? [])) || array_diff($scopes,$current['scopes'])) throw new ContentMutationException('An active owner connection with explicit theme scopes is required.','insufficient_scope',403);
    }
    private function read(string $id,?array $access = null): array
    {
        $path = $this->path($id);
        if (!is_file($path)) throw new ContentMutationException('Theme draft not found.','not_found',404);
        if (is_link($path)) throw new \RuntimeException('Linked theme storage is unsupported.');
        $stored = (new FileStore())->readJson($path);
        $record = json_decode((new SecretStore($this->config->paths()))->decrypt((string)($stored['payload'] ?? '')),true,32,JSON_THROW_ON_ERROR);
        if (!is_array($record) || ($record['id'] ?? '') !== $id || ($record['site'] ?? '') !== $this->site()) throw new ContentMutationException('Theme draft installation mismatch.','theme_invalid',409);
        if ($access !== null && (($record['connection_id'] ?? '') !== $access['id'] || ($record['principal'] ?? '') !== $access['principal'])) throw new ContentMutationException('Theme draft not found.','not_found',404);
        return $record;
    }
    private function locked(callable $work): array
    {
        $root = $this->root();
        if (!is_dir($root) && !mkdir($root,0700,true) && !is_dir($root)) throw new \RuntimeException('Unable to prepare private theme storage.');
        if (is_link($root) || is_link($root . '/storage.lock')) throw new \RuntimeException('Linked theme storage is unsupported.');
        $lock = fopen($root . '/storage.lock','c+');
        if (!$lock || !flock($lock,LOCK_EX)) throw new \RuntimeException('Unable to lock theme drafts.');
        try { return $work(); } finally { flock($lock,LOCK_UN); fclose($lock); }
    }
    private function path(string $id): string
    {
        if (!preg_match('/^theme_[a-f0-9]{32}$/D',$id)) throw new ContentMutationException('Invalid theme draft identity.','validation_failed',422);
        return $this->root() . '/' . $id . '.json';
    }
    private function root(): string { return $this->config->paths()->dataPath('integrations/theme-drafts'); }
    private function site(): string { return rtrim((string)($this->config->site()['base_url'] ?? ''),'/'); }
}
