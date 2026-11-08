<?php
declare(strict_types=1);

namespace Batoi\Press\Application;

use Batoi\Press\Content\{PageRepository, PostRepository};
use Batoi\Press\Core\{Config, FileStore, HtmlContent, Slug};
use Batoi\Press\Security\{AdminAccess, SecretStore};
use RuntimeException;

/** Private editing copies. This service has no content-save or publish operation. */
final class EditorRecovery
{
    public const FIELDS = ['title','slug','body','subtitle','parent_slug','post_type','category','layout','tags','template','seo_title','seo_description','featured_image','featured_image_alt','hero_image','hero_preload','hero_priority','lazy_images','image_sizes','show_latest_posts','latest_posts_limit','og_title','og_description','og_image','og_url','og_type','twitter_title','twitter_description','twitter_image','twitter_card','body_edit_mode','body_source_hash','body_text','block_type','block_title','block_body','block_category','block_limit','block_widget','block_form','block_plugin','block_show_image','block_show_author','block_show_date','block_show_subtitle','block_show_category'];
    private const MAX_BYTES = 1100000;
    public function __construct(private readonly Config $config, private readonly array $user) {}

    public function source(string $type, string $identifier): ?array
    {
        if (!in_array($type,['page','post'],true) || ($identifier !== '' && (Slug::normalize($identifier) !== $identifier || strlen($identifier) > 240))) throw new ContentMutationException('Invalid editing source.','validation_failed',422);
        $role = AdminAccess::role($this->user);
        if (empty($this->user['username']) || !in_array($role,['owner','admin','editor','author'],true) || ($type === 'page' && $role === 'author')) throw new ContentMutationException('Editing access is unavailable.','forbidden',403);
        if ($identifier === '') return null;
        $files = new FileStore(); $html = new HtmlContent();
        $source = $type === 'page' ? (new PageRepository($this->config->paths(),$files,$html))->findBySlug($identifier) : (new PostRepository($this->config->paths(),$files,$html))->findBySlug($identifier);
        if ($source === null || ($type === 'post' && !AdminAccess::canManagePost($this->user,$source))) throw new ContentMutationException('Editing source not found.','not_found',404);
        if (($source['status'] ?? '') === 'trashed') throw new ContentMutationException('Restore the content from Trash before editing.','content_trashed',409);
        return $source;
    }

    public function save(string $type, string $identifier, string $branch, string $baseRevision, string $expectedRevision, array $fields): array
    {
        $source = $this->source($type,$identifier);
        $this->branch($branch);
        if (($identifier === '' && $baseRevision !== '') || ($identifier !== '' && preg_match('/^sha256:[a-f0-9]{64}$/D',$baseRevision) !== 1)) throw new ContentMutationException('Invalid source revision.','validation_failed',422);
        $fields = $this->validateFields($fields);
        return $this->locked(function () use ($type,$identifier,$branch,$baseRevision,$expectedRevision,$fields,$source): array {
            $path = $this->path($branch); $files = new FileStore();
            $old = is_file($path) ? $this->read($path) : null;
            if ($old !== null && ($old['type'] !== $type || $old['identifier'] !== $identifier || $old['base_revision'] !== $baseRevision)) throw new ContentMutationException('This editing tab belongs to a different source. Reload the editor.','recovery_conflict',409);
            if ($old !== null && $old['fields'] === $fields) return $this->summary($old);
            if (($old === null && $expectedRevision !== '') || ($old !== null && !hash_equals($old['revision'],$expectedRevision))) throw new ContentMutationException('This recovery copy changed. Your current editor values have not been overwritten.','recovery_conflict',409);
            $record = ['schema'=>1,'owner'=>$this->owner(),'branch'=>$branch,'type'=>$type,'identifier'=>$identifier,'base_revision'=>$baseRevision,'updated_at'=>time(),'fields'=>$fields];
            if (($fields['body_edit_mode'] ?? '') === 'text_only') {
                if ($old !== null && isset($old['source_body'])) $record['source_body'] = $old['source_body'];
                elseif ($source !== null && hash_equals(ContentRevision::for($source),$baseRevision)) $record['source_body'] = (string)($source['body'] ?? '');
            }
            $record['revision'] = ContentRevision::for($record);
            $encrypted = (new SecretStore($this->config->paths()))->encrypt(json_encode($record,JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            $stored = array_diff_key($record,['fields'=>true,'source_body'=>true]); $stored['payload'] = $encrypted;
            $encoded = json_encode($stored,JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $all = glob($this->root() . '/*/*.json') ?: []; $own = glob(dirname($path) . '/*.json') ?: [];
            $total = $mine = 0;
            foreach ($all as $file) { if (is_link($file)) throw new RuntimeException('Linked recovery storage is unsupported.'); $size = (int)filesize($file); $total += $size; if (dirname($file) === dirname($path)) $mine += $size; }
            $previous = is_file($path) ? (int)filesize($path) : 0;
            if (($old === null && (count($all) >= 2000 || count($own) >= 100)) || $total - $previous + strlen($encoded) > 134217728 || $mine - $previous + strlen($encoded) > 16777216) throw new ContentMutationException('Recovery storage is full. Save your content normally and ask the owner to archive recovery files.','recovery_quota',413);
            $files->writeJson($path,$stored); chmod($path,0600);
            return $this->summary($record);
        });
    }

    public function listing(string $type, string $identifier): array
    {
        $source = $this->source($type,$identifier); $current = $source === null ? '' : ContentRevision::for($source);
        return $this->locked(function () use ($type,$identifier,$current): array {
            $result = [];
            foreach (glob($this->root() . '/' . $this->owner() . '/*.json') ?: [] as $path) {
                $record = $this->read($path);
                if ($record['type'] !== $type || $record['identifier'] !== $identifier) continue;
                $result[] = $this->summary($record) + ['matches_source'=>hash_equals($current,$record['base_revision'])];
            }
            usort($result,static fn(array $a,array $b): int => $b['updated_at'] <=> $a['updated_at']);
            return $result;
        });
    }

    public function load(string $branch): array
    {
        $this->branch($branch);
        $record = $this->locked(function () use ($branch): array {
            $path = $this->path($branch);
            if (!is_file($path)) throw new ContentMutationException('Recovery copy not found.','not_found',404);
            return $this->read($path);
        });
        $source = $this->source($record['type'],$record['identifier']);
        $record['matches_source'] = hash_equals($record['base_revision'],$source === null ? '' : ContentRevision::for($source));
        return $record;
    }

    public function forEditor(string $type, string $identifier, string $branch): array
    {
        $record = $this->load($branch);
        if ($record['type'] !== $type || $record['identifier'] !== $identifier) throw new ContentMutationException('Recovery source does not match this editor.','recovery_conflict',409);
        if (!$record['matches_source']) throw new ContentMutationException('The source changed after this recovery copy began. Compare the copy with the current source before applying its text.','recovery_conflict',409);
        return $record['fields'];
    }

    /** Rehydrate content fields only; identity, publication state and executable assets stay governed. */
    public static function values(?array $source, array $fields, string $type): array
    {
        $record = $source ?? []; $html = new HtmlContent();
        foreach (self::FIELDS as $name) if (isset($fields[$name]) && is_string($fields[$name]) && !str_starts_with($name,'block_') && !in_array($name,['body_edit_mode','body_source_hash'],true)) $record[$name] = $fields[$name];
        if (isset($fields['body'])) $record['body'] = $html->sanitize($fields['body']);
        if ($type === 'page' && ($fields['body_edit_mode'] ?? '') === 'text_only') {
            $body = (string)($source['body'] ?? '');
            if (!hash_equals(hash('sha256',$body),(string)($fields['body_source_hash'] ?? ''))) throw new ContentMutationException('Protected layout changed; compare before restoring.','recovery_conflict',409);
            $record['body'] = $html->replaceEditableText($body,(array)($fields['body_text'] ?? []));
            if (count($record['blocks'] ?? []) === 1 && ($record['blocks'][0]['type'] ?? '') === 'html') $record['blocks'][0]['body'] = $record['body'];
        } elseif ($type === 'page' && isset($fields['block_type'])) {
            $blocks = [];
            foreach ($fields['block_type'] as $i => $blockType) {
                if (!in_array($blockType,['html','posts','gallery','products','widget','form','plugin'],true)) throw new ContentMutationException('Recovery contains an unsupported block type.','validation_failed',422);
                $block = ['type'=>$blockType];
                foreach (['title','body','category','limit','widget','form','plugin','show_image','show_author','show_date','show_subtitle','show_category'] as $key) $block[$key === 'plugin' ? 'plugin_block' : $key] = $fields['block_' . $key][$i] ?? '';
                $block['body'] = $html->sanitize((string)$block['body']); $blocks[] = $block;
            }
            $record['blocks'] = $blocks;
            $record['body'] = implode("\n",array_column(array_filter($blocks,static fn(array $b): bool => in_array($b['type'],['html','gallery'],true)),'body'));
        }
        return $record;
    }

    private function validateFields(array $fields): array
    {
        if (array_diff(array_keys($fields),self::FIELDS) || strlen(json_encode($fields,JSON_THROW_ON_ERROR)) > self::MAX_BYTES) throw new ContentMutationException('Unsupported or oversized recovery fields.','validation_failed',422);
        foreach ($fields as $name => $value) {
            if (($name === 'body_text' || str_starts_with($name,'block_')) && !is_array($value)) throw new ContentMutationException('Recovery block fields must be indexed lists.','validation_failed',422);
            if (is_array($value)) {
                if (!($name === 'body_text' || str_starts_with($name,'block_')) || count($value) > ($name === 'body_text' ? 4000 : 30)) throw new ContentMutationException('Invalid recovery field structure.','validation_failed',422);
                foreach ($value as $key => $part) if (!is_string($part) || preg_match('/^[0-9]{1,5}$/D',(string)$key) !== 1) throw new ContentMutationException('Recovery arrays must contain indexed text values.','validation_failed',422);
            } elseif (!is_string($value)) throw new ContentMutationException('Recovery fields must contain text.','validation_failed',422);
        }
        return $fields;
    }
    private function summary(array $r): array { return array_intersect_key($r,array_flip(['branch','type','identifier','revision','base_revision','updated_at'])); }
    private function read(string $path): array
    {
        if (is_link($path) || is_link(dirname($path))) throw new RuntimeException('Linked recovery storage is unsupported.');
        $stored = (new FileStore())->readJson($path);
        $record = json_decode((new SecretStore($this->config->paths()))->decrypt((string)($stored['payload'] ?? '')),true,32,JSON_THROW_ON_ERROR);
        if (!is_array($record) || $record['owner'] !== $this->owner()) throw new RuntimeException('Recovery identity could not be verified.');
        if (basename($path,'.json') !== $record['branch']) throw new RuntimeException('Recovery identity could not be verified.');
        foreach (['schema','owner','branch','type','identifier','base_revision','updated_at','revision'] as $field) if (($stored[$field] ?? null) !== ($record[$field] ?? null)) throw new RuntimeException('Recovery integrity could not be verified.');
        return $record;
    }
    private function locked(callable $work): array
    {
        $root = $this->root();
        if (!is_dir($root) && !mkdir($root,0700,true) && !is_dir($root)) throw new RuntimeException('Unable to prepare recovery storage.');
        if (is_link($root) || is_link($root . '/storage.lock')) throw new RuntimeException('Linked recovery storage is unsupported.');
        $lock = fopen($root . '/storage.lock','c+');
        if ($lock === false || !flock($lock,LOCK_EX)) throw new RuntimeException('Unable to lock recovery storage.');
        try { return $work(); } finally { flock($lock,LOCK_UN); fclose($lock); }
    }
    private function path(string $branch): string
    {
        $this->branch($branch); $dir = $this->root() . '/' . $this->owner();
        if (is_link($dir)) throw new RuntimeException('Linked recovery storage is unsupported.');
        if (!is_dir($dir) && !mkdir($dir,0700,true) && !is_dir($dir)) throw new RuntimeException('Unable to prepare private recovery storage.');
        return $dir . '/' . $branch . '.json';
    }
    private function branch(string $branch): void { if (preg_match('/^[a-f0-9]{32}$/D',$branch) !== 1) throw new ContentMutationException('Invalid recovery identity.','validation_failed',422); }
    private function owner(): string { return hash('sha256',(string)($this->user['username'] ?? '') . "\0" . (string)($this->user['created_at'] ?? '')); }
    private function root(): string { return $this->config->paths()->dataPath('editor-recovery'); }
}
