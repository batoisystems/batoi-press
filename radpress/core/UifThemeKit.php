<?php
declare(strict_types=1);

namespace Batoi\Press\Core;

use RuntimeException;
use ZipArchive;

/** Data-only theme generation. All executable source comes from the bundled theme. */
final class UifThemeKit
{
    public const VERSION = '1.0.0';
    public function __construct(private readonly string $sourceTheme = __DIR__ . '/../theme/default') {}
    public static function starter(): array
    {
        return ['kit'=>self::VERSION,'slug'=>'consultancy','name'=>'Consultancy','version'=>'1.0.0','author'=>'Your Studio','preset'=>'corporate','width'=>76,'tokens'=>['body_bg'=>'#FFFFFF','body_text'=>'#111827','header_bg'=>'#FFFFFF','header_text'=>'#111827','primary_button'=>'#0E68B0'],'sections'=>[['type'=>'heading','text'=>'Practical expertise for your next step'],['type'=>'text','text'=>'Describe your services and the people you help.'],['type'=>'button','text'=>'Contact us','url'=>'/contact']]];
    }
    public static function schema(): array
    {
        $tokens = []; foreach (array_keys(Appearance::LABELS) as $name) $tokens[$name] = ['type'=>'string','pattern'=>'^#[0-9A-Fa-f]{6}$'];
        return ['$schema'=>'https://json-schema.org/draft/2020-12/schema','title'=>'Press Theme Kit for Batoi UIF','type'=>'object','additionalProperties'=>false,'required'=>['kit','slug','name','version','author','preset'],'properties'=>['kit'=>['const'=>self::VERSION],'slug'=>['type'=>'string','pattern'=>'^[a-z][a-z0-9-]{0,63}$'],'name'=>['type'=>'string','minLength'=>1,'maxLength'=>100],'author'=>['type'=>'string','minLength'=>1,'maxLength'=>100],'version'=>['type'=>'string','pattern'=>'^[0-9]+\\.[0-9]+\\.[0-9]+$'],'preset'=>['enum'=>['corporate','editorial','shop']],'width'=>['type'=>'integer','minimum'=>60,'maximum'=>90],'tokens'=>['type'=>'object','additionalProperties'=>false,'properties'=>$tokens],'sections'=>['type'=>'array','maxItems'=>20,'items'=>['type'=>'object','additionalProperties'=>false,'required'=>['type','text'],'properties'=>['type'=>['enum'=>['heading','text','button']],'text'=>['type'=>'string','minLength'=>1,'maxLength'=>1000],'url'=>['type'=>'string','pattern'=>'^/(?!/)[A-Za-z0-9/_-]*$']]]]]];
    }
    public function validate(array $definition): array
    {
        if (preg_match('/-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----|\b(?:sk|rk)_(?:test|live)_[A-Za-z0-9]{12,}/', json_encode($definition, JSON_THROW_ON_ERROR))) throw new RuntimeException('Remove private keys and credentials from the theme definition.');
        if (strlen(json_encode($definition, JSON_THROW_ON_ERROR)) > 65536 || array_diff(array_keys($definition), ['kit','slug','name','version','author','preset','width','tokens','sections'])) throw new RuntimeException('Theme definition contains unsupported fields or exceeds 64 KiB.');
        if (($definition['kit'] ?? '') !== self::VERSION || !is_string($definition['slug'] ?? null) || !preg_match('/^[a-z][a-z0-9-]{0,63}$/D', $definition['slug']) || in_array($definition['slug'], ['default','assets','layouts','partials'], true)) throw new RuntimeException('Select kit 1.0.0 and a new theme slug (letters, numbers and hyphens).');
        foreach (['name','author'] as $name) if (!is_string($definition[$name] ?? null) || trim($definition[$name]) === '' || strlen($definition[$name]) > 100 || preg_match('/[\x00-\x1f\x7f]/', $definition[$name])) throw new RuntimeException('Theme ' . $name . ' must contain 1–100 text bytes.');
        if (!is_string($definition['version'] ?? null) || !preg_match('/^[0-9]{1,5}\.[0-9]{1,5}\.[0-9]{1,5}$/D', $definition['version']) || !in_array($definition['preset'] ?? null, ['corporate','editorial','shop'], true)) throw new RuntimeException('Choose a supported preset and a three-component numeric version.');
        $definition += ['width'=>76,'tokens'=>[],'sections'=>[]];
        if (!is_int($definition['width']) || $definition['width'] < 60 || $definition['width'] > 90) throw new RuntimeException('Theme width must be 60–90 rem.');
        if (!is_array($definition['tokens']) || array_diff(array_keys($definition['tokens']), array_keys(Appearance::LABELS))) throw new RuntimeException('Use only documented appearance tokens.');
        foreach ($definition['tokens'] as $color) if (!is_string($color) || !preg_match('/^#[0-9a-f]{6}$/iD', $color)) throw new RuntimeException('Colors must be six-digit hexadecimal values.');
        if (!is_array($definition['sections']) || !array_is_list($definition['sections']) || count($definition['sections']) > 20) throw new RuntimeException('A theme supports up to 20 introduction sections.');
        foreach ($definition['sections'] as $section) {
            if (!is_array($section) || array_diff(array_keys($section), ['type','text','url']) || !in_array($section['type'] ?? null, ['heading','text','button'], true) || !is_string($section['text'] ?? null) || trim($section['text']) === '' || strlen($section['text']) > 1000 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $section['text'])) throw new RuntimeException('Sections require an approved type and bounded plain text.');
            if ($section['type'] === 'button' && (!is_string($section['url'] ?? null) || !preg_match('#^/(?!/)[A-Za-z0-9/_-]*$#D', $section['url']))) throw new RuntimeException('Buttons require a local URL without query strings or credentials.');
            if ($section['type'] !== 'button' && isset($section['url'])) throw new RuntimeException('Only buttons accept a URL.');
        }
        return $definition;
    }
    public function files(array $definition): array
    {
        $definition = $this->validate($definition);
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->sourceTheme, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isLink()) throw new RuntimeException('Bundled theme contains an unsupported link.');
            if (!$file->isFile()) continue;
            $path = str_replace(DIRECTORY_SEPARATOR,'/',substr($file->getPathname(),strlen($this->sourceTheme) + 1));
            $files[$path] = (string)file_get_contents($file->getPathname());
        }
        $manifest = json_decode($files['theme.json'],true,32,JSON_THROW_ON_ERROR);
        foreach (['slug','name','version','author'] as $name) $manifest[$name] = $definition[$name];
        $manifest['tokens']['light'] = array_replace((array)($manifest['tokens']['light'] ?? []),$definition['tokens']);
        $manifest['assets']['styles'][] = ['file'=>'css/kit.css','media'=>'all'];
        $files['layouts/base.php'] = preg_replace('#<script\b[\s\S]*?</script>#i', '', $files['layouts/base.php']) ?? throw new RuntimeException('Unable to prepare document shell.');
        // Generated themes use governed Forms blocks/links; no embedded remote CAPTCHA code.
        $files['layouts/contact.php'] = $files['layouts/page.php'];
        $uifRoot = dirname(__DIR__, 2) . '/public_html/assets/uif/';
        $files['assets/js/uif.iife.js'] = (string)file_get_contents($uifRoot . 'uif.iife.js');
        $files['assets/js/uif-init.js'] = (string)file_get_contents($uifRoot . 'uif.js');
        $manifest['assets']['scripts'] = array_merge([['file'=>'js/uif.iife.js','defer'=>true],['file'=>'js/uif-init.js','defer'=>true]], (array)$manifest['assets']['scripts']);
        $files['theme.json'] = json_encode($manifest,JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        // Every CSS value is numeric/enum/hexadecimal; arbitrary CSS and URLs are unavailable.
        $files['assets/css/kit.css'] = '.bp-shell{max-width:' . $definition['width'] . 'rem;margin-inline:auto}.bp-kit-intro{padding:clamp(1rem,4vw,3rem);display:grid;gap:1rem}.bp-kit-intro p{max-width:65ch}.bp-kit-intro h2{font-size:clamp(1.5rem,3vw,2.5rem);line-height:1.15}.bp-kit-intro .bp-button{justify-self:start}@media(max-width:48rem){.bp-kit-intro{padding:1rem}}' . "\n";
        $files['assets/css/kit.css'] .= match ($definition['preset']) {
            'editorial'=>'.bp-post-content{max-width:72ch;margin-inline:auto;line-height:1.75}.bp-post-card h2{line-height:1.25}',
            'shop'=>'.bp-product-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,15rem),1fr));gap:1rem}.bp-price{font-variant-numeric:tabular-nums}',
            default=>'.bp-kit-intro{border-block-start:1px solid var(--uif-border,#e5e7eb)}',
        } . "\n";
        $intro = '<section class="bp-kit-intro" aria-label="Introduction">';
        foreach ($definition['sections'] as $section) {
            $text = htmlspecialchars($section['text'],ENT_QUOTES | ENT_SUBSTITUTE,'UTF-8');
            $intro .= match ($section['type']) {
                'heading'=>'<h2>' . $text . '</h2>',
                'button'=>'<a class="bp-button" href="' . htmlspecialchars($section['url'],ENT_QUOTES,'UTF-8') . '">' . $text . '</a>',
                default=>'<p>' . $text . '</p>',
            };
        }
        $intro .= '</section>';
        if ($definition['sections'] !== []) {
            // Emit escaped markup as a PHP string literal: even PHP delimiters in text are data.
            $files['layouts/landing.php'] = "<?php\ndeclare(strict_types=1);\nrequire __DIR__ . '/page.php';\necho " . var_export($intro,true) . ";\n";
        }
        $files['uif-kit-provenance.json'] = json_encode(['kit'=>self::VERSION,'definition_sha256'=>hash('sha256',json_encode($definition,JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),'source_manifest_sha256'=>hash('sha256',(string)file_get_contents($this->sourceTheme . '/theme.json')),'preset'=>$definition['preset'],'note'=>'Generated from bundled Press templates; inspect, install inactive and preview before activation.'],JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
        ksort($files);
        return $files;
    }
    public function writeZip(array $definition, string $path): void
    {
        if (!class_exists(ZipArchive::class)) throw new RuntimeException('Theme packaging requires PHP ZIP.');
        if (file_exists($path)) throw new RuntimeException('Refusing to replace an existing theme package.');
        $files = $this->files($definition); $zip = new ZipArchive();
        if ($zip->open($path,ZipArchive::CREATE | ZipArchive::EXCL) !== true) throw new RuntimeException('Unable to create theme package.');
        foreach ($files as $name => $body) {
            if (!$zip->addFromString($name,$body)) throw new RuntimeException('Unable to write theme package.');
            $zip->setMtimeName($name, 946684800);
        }
        if (!$zip->close()) throw new RuntimeException('Unable to finish theme package.');
    }
}
