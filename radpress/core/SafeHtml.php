<?php
declare(strict_types=1);

namespace Batoi\Press\Core;

/** Rebuild an allowlisted tree; never return unparsed source attributes. */
final class SafeHtml
{
    private const TAGS = ['main','header','footer','nav','section','article','aside','p','div','span','br','h1','h2','h3','h4','h5','h6','ul','ol','li','strong','b','em','i','del','mark','small','sub','sup','abbr','a','blockquote','code','pre','img','iframe','figure','figcaption','hr','table','thead','tbody','tfoot','tr','th','td','form','fieldset','legend','label','input','button','select','option','textarea','video','audio','source','track'];
    private const DROP = ['script','style','object','embed','svg','math','template'];
    private const VOID = ['br','hr','img','input','source','track'];

    public static function sanitize(string $source): string
    {
        if (!class_exists(\DOMDocument::class)) {
            return nl2br(htmlspecialchars(strip_tags($source), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false);
        }
        if (strlen($source) > 1048576) throw new \RuntimeException('HTML exceeds the 1 MiB limit.');
        $previous = libxml_use_internal_errors(true);
        try {
            $dom = new \DOMDocument();
            $dom->loadHTML('<?xml encoding="UTF-8"><!doctype html><html><body>' . $source . '</body></html>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
            $body = $dom->getElementsByTagName('body')->item(0);
            return $body === null ? '' : trim(self::children($body, 0));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private static function children(\DOMNode $node, int $depth): string
    {
        if ($depth > 80) return '';
        $html = '';
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMText) { $html .= self::e($child->nodeValue ?? ''); continue; }
            if (!$child instanceof \DOMElement) continue;
            $tag = strtolower($child->tagName);
            if (in_array($tag, self::DROP, true)) continue;
            if (!in_array($tag, self::TAGS, true)) { $html .= self::children($child, $depth + 1); continue; }
            $attributes = '';
            foreach ($child->attributes as $attribute) {
                $name = strtolower($attribute->name);
                $value = $attribute->value;
                if (in_array($tag, ['video','audio'], true) && in_array($name, ['controls','preload'], true)) continue;
                if (in_array($name, ['href','src','action','formaction','poster','cite'], true)) {
                    if (!self::url($value, $name === 'href') || ($tag === 'iframe' && !str_starts_with(strtolower($value), 'https://'))) continue;
                } elseif ($name === 'style') {
                    $value = self::css($value);
                    if ($value === '') continue;
                } elseif ($name === 'target') {
                    if (!in_array($value, ['_blank','_self'], true)) continue;
                } elseif (!in_array($name, ['class','id','title','alt','width','height','name','type','value','placeholder','for','rows','cols','maxlength','minlength','min','max','step','pattern','autocomplete','method','enctype','role','tabindex','colspan','rowspan','scope','datetime','lang','dir','label','kind','srclang','preload','controls','muted','loop','playsinline','default','required','checked','selected','disabled','multiple','loading','allowfullscreen'], true) && !preg_match('/^aria-[a-z-]+$/D', $name)) {
                    continue;
                }
                if ($tag === 'input' && $name === 'type' && !in_array(strtolower($value), ['text','email','tel','url','number','date','checkbox','radio','hidden','submit'], true)) $value = 'text';
                if ($name === 'method' && !in_array(strtolower($value), ['get','post'], true)) $value = 'post';
                if (strlen($value) > 8192) continue;
                $attributes .= ' ' . $name . '="' . self::e($value) . '"';
            }
            if ($tag === 'a') $attributes .= ' rel="noopener noreferrer"';
            if ($tag === 'iframe') {
                if (!$child->hasAttribute('src') || !self::url($child->getAttribute('src')) || !str_starts_with(strtolower($child->getAttribute('src')), 'https://')) continue;
                $attributes .= ' sandbox="allow-scripts allow-presentation" referrerpolicy="strict-origin-when-cross-origin" loading="lazy"';
            }
            if (in_array($tag, ['audio','video'], true)) {
                $preload = $child->getAttribute('preload');
                $attributes .= ' controls preload="' . (in_array($preload, ['none','metadata','auto'], true) ? $preload : 'none') . '"';
            }
            $html .= '<' . $tag . $attributes . '>';
            // Libxml's HTML4 parser can nest fallback content below HTML5 void tags.
            $html .= self::children($child, $depth + 1);
            if (!in_array($tag, self::VOID, true)) $html .= '</' . $tag . '>';
        }
        return $html;
    }

    private static function url(string $value, bool $link = false): bool
    {
        if ($value === '' || preg_match('/[\x00-\x20\x7f<>"\\\\]/', $value)) return false;
        if (str_starts_with($value, '//')) return false;
        if (str_starts_with($value, '#') || str_starts_with($value, '/')) return true;
        if (preg_match('#^https?://#i', $value)) return filter_var($value, FILTER_VALIDATE_URL) !== false;
        if ($link && preg_match('/^(mailto|tel):[a-z0-9@+._%?=&-]+$/iD', $value)) return true;
        return !str_contains($value, ':');
    }

    private static function css(string $value): string
    {
        // CSS escapes/comments and active legacy constructs are never accepted.
        if (preg_match('/[\\\\<>\x00-\x1f]|\/\*|expression|javascript|vbscript|@|behavior|-moz-binding/i', $value)) return '';
        foreach (explode(';', $value) as $declaration) {
            if (trim($declaration) === '') continue;
            if (preg_match('/^\s*([a-z-]+)\s*:(.*)$/iD', $declaration, $parts) !== 1) return '';
            if (!preg_match('/^(?:color|background(?:-color|-image)?|font(?:-size|-weight|-style|-family)?|text(?:-align|-decoration|-transform)|line-height|letter-spacing|white-space|display|width|height|max-width|min-height|margin(?:-(?:top|bottom|left|right))?|padding(?:-(?:top|bottom|left|right))?|border(?:-(?:color|width|style|radius|left|right|top|bottom))?|gap|grid-template-columns|align-items|justify-content|flex(?:-direction|-wrap)?|object-fit)$/D', strtolower($parts[1]))) return '';
            if (preg_match_all('/url\(\s*([\'"]?)(.*?)\1\s*\)/i', $parts[2], $urls, PREG_SET_ORDER)) {
                foreach ($urls as $url) if (!self::url($url[2])) return '';
            }
            $withoutUrls = preg_replace('/url\([^)]*\)/i', '', $parts[2]) ?? '';
            if (!preg_match('/^[a-z0-9\s#.,%()\'"+\/-]*$/iD', $withoutUrls)) return '';
        }
        return $value;
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
