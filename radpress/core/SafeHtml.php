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
            // Rebuild only the native presentation widget contract. Do not pass
            // through arbitrary UIF attributes (requests, routes, actions, etc.).
            foreach (self::presentationAttributes($child) as $name => $value) {
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

    /** Native presentation only: never admit arbitrary UIF actions or configuration. */
    private static function presentationAttributes(\DOMElement $element): array
    {
        $attributes = self::galleryAttributes($element);
        $tag = strtolower($element->tagName);
        $icon = $element->getAttribute('data-uif-icon');
        if (in_array($tag, ['span', 'i'], true) && preg_match('/^[a-z][a-z0-9-]{0,63}$/D', $icon)) {
            $attributes['data-uif-icon'] = $icon;
            foreach (['title', 'class'] as $suffix) {
                $value = $element->getAttribute('data-uif-icon-' . $suffix);
                if ($value !== '' && strlen($value) <= 512 && ($suffix !== 'class' || preg_match('/^[a-z0-9_ -]+$/iD', $value))) $attributes['data-uif-icon-' . $suffix] = $value;
            }
            $size = $element->getAttribute('data-uif-icon-size');
            if (preg_match('/^[1-9]\d{0,2}$/D', $size) && (int)$size <= 256) $attributes['data-uif-icon-size'] = $size;
            $hidden = $element->getAttribute('data-uif-icon-hidden');
            if (in_array($hidden, ['true', 'false'], true)) $attributes['data-uif-icon-hidden'] = $hidden;
        }

        $root = $element;
        while (($kind = self::presentationRoot($root)) === null) {
            if (self::galleryRoot($root)) return $attributes;
            if (!$root->parentNode instanceof \DOMElement) return $attributes;
            $root = $root->parentNode;
        }
        if ($element === $root) {
            $attributes['data-uif'] = $kind;
            $states = $kind === 'accordion' ? ['expanded', 'collapsed'] : [];
            $state = $root->getAttribute('data-uif-state');
            if (in_array($state, $states, true)) $attributes['data-uif-state'] = $state;
            if ($kind === 'carousel') {
                foreach (['data-uif-items-per-slide', 'data-uif-step'] as $name) {
                    $value = $root->getAttribute($name);
                    if (preg_match('/^[1-9]\d{0,2}$/D', $value) && (int)$value <= 100) $attributes[$name] = $value;
                }
            }
            return $attributes;
        }
        $role = $element->getAttribute('data-uif-role');
        if ($kind === 'carousel') {
            if (in_array($tag, ['article', 'div', 'figure', 'li'], true) && ($role === 'slide' || self::hasClass($element, 'uif-carousel-slide'))) {
                $attributes['data-uif-role'] = 'slide';
                $state = $element->getAttribute('data-uif-state');
                if (in_array($state, ['active', 'inactive'], true)) {
                    $attributes['data-uif-state'] = $state;
                    if ($state === 'inactive') $attributes['hidden'] = '';
                }
            }
            if (in_array($tag, ['p', 'span', 'div'], true) && $role === 'status') $attributes['data-uif-role'] = 'status';
            if ($tag === 'button') {
                $index = $element->getAttribute('data-uif-slide-to');
                if (preg_match('/^(?:0|[1-9]\d{0,3})$/D', $index)) $attributes['data-uif-slide-to'] = $index;
                elseif ($element->parentNode instanceof \DOMElement && self::hasClass($element->parentNode, 'uif-carousel-indicators')) {
                    $index = 0;
                    for ($sibling = $element->previousElementSibling; $sibling !== null; $sibling = $sibling->previousElementSibling) if (strtolower($sibling->tagName) === 'button') $index++;
                    $attributes['data-uif-slide-to'] = (string)$index;
                }
                $action = $element->getAttribute('data-uif-action') ?: strtolower(trim($element->textContent));
                if (in_array($action, ['previous', 'next'], true)) $attributes['data-uif-action'] = $action;
            }
        } else {
            if ($tag === 'button' && ($role === 'trigger' || self::hasClass($element, 'uif-accordion-trigger') || $element->parentNode === $root)) $attributes['data-uif-role'] = 'trigger';
            $followsTrigger = $element->parentNode === $root && $element->previousElementSibling instanceof \DOMElement && strtolower($element->previousElementSibling->tagName) === 'button';
            if (in_array($tag, ['div', 'section'], true) && ($role === 'panel' || self::hasClass($element, 'uif-accordion-panel') || $followsTrigger)) {
                $attributes['data-uif-role'] = 'panel';
                if ($root->getAttribute('data-uif-state') !== 'expanded') $attributes['hidden'] = '';
            }
        }
        return $attributes;
    }

    private static function presentationRoot(\DOMElement $element): ?string
    {
        if (!in_array(strtolower($element->tagName), ['div', 'section', 'article'], true)) return null;
        if (self::hasClass($element, 'uif-carousel') || $element->getAttribute('data-uif') === 'carousel') return 'carousel';
        if (self::hasClass($element, 'uif-accordion') || self::hasClass($element, 'component-accordion') || $element->getAttribute('data-uif') === 'accordion') return 'accordion';
        return null;
    }

    /** Restore legacy galleries, including bodies previously saved without data attributes. */
    private static function galleryAttributes(\DOMElement $element): array
    {
        $root = $element;
        while (!self::galleryRoot($root)) {
            if (!$root->parentNode instanceof \DOMElement) return [];
            $root = $root->parentNode;
        }
        $tag = strtolower($element->tagName);
        if ($element === $root) return ['data-uif' => 'lightbox'];

        $role = strtolower($element->getAttribute('data-uif-role'));
        if ($tag === 'div' && (self::hasClass($element, 'uif-lightbox-dialog') || $role === 'dialog')) {
            return ['data-uif-role' => 'dialog', 'hidden' => ''];
        }
        if ($tag === 'img' && (self::hasClass($element, 'uif-lightbox-image') || $role === 'image')) {
            return ['data-uif-role' => 'image'];
        }
        if (in_array($tag, ['p', 'div', 'span'], true) && ($role === 'caption' || self::hasClass($element, 'uif-lightbox-caption'))) {
            return ['data-uif-role' => 'caption'];
        }
        if ($tag === 'a' && ($role === 'item' || ($element->parentNode instanceof \DOMElement && self::hasClass($element->parentNode, 'uif-masonry-item')))) {
            $attributes = ['data-uif-role' => 'item'];
            foreach (['data-uif-caption', 'data-uif-alt', 'data-uif-src'] as $name) {
                $value = $element->getAttribute($name);
                if ($element->hasAttribute($name) && strlen($value) <= 8192 && ($name !== 'data-uif-src' || self::url($value))) $attributes[$name] = $value;
            }
            return $attributes;
        }
        if ($tag === 'button') {
            $parent = $element->parentNode;
            while ($parent instanceof \DOMElement && $parent !== $root) {
                if (self::hasClass($parent, 'uif-lightbox-dialog') || $parent->getAttribute('data-uif-role') === 'dialog') {
                    $action = strtolower($element->getAttribute('data-uif-action') ?: trim($element->textContent));
                    return in_array($action, ['previous', 'close', 'next'], true) ? ['data-uif-action' => $action] : [];
                }
                $parent = $parent->parentNode;
            }
        }
        return [];
    }

    private static function galleryRoot(\DOMElement $element): bool
    {
        return in_array(strtolower($element->tagName), ['div', 'section', 'figure'], true)
            && (self::hasClass($element, 'uif-lightbox') || $element->getAttribute('data-uif') === 'lightbox');
    }

    private static function hasClass(\DOMElement $element, string $class): bool
    {
        return in_array($class, preg_split('/\s+/', trim($element->getAttribute('class'))) ?: [], true);
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
        $safe = [];
        foreach (explode(';', $value) as $declaration) {
            if (trim($declaration) === '') continue;
            if (preg_match('/^\s*([a-z-]+)\s*:(.*)$/iD', $declaration, $parts) !== 1) continue;
            $property = strtolower($parts[1]);
            if (!preg_match('/^(?:color|background(?:-color|-image|-size|-position|-repeat|-attachment|-origin|-clip)?|position|z-index|font(?:-size|-weight|-style|-family)?|text(?:-align|-decoration|-transform)|line-height|letter-spacing|white-space|display|width|height|max-width|min-height|margin(?:-(?:top|bottom|left|right))?|padding(?:-(?:top|bottom|left|right))?|border(?:-(?:color|width|style|radius|left|right|top|bottom))?|gap|grid-template-columns|align-items|justify-content|flex(?:-direction|-wrap)?|object-fit)$/D', $property)) continue;
            if ($property === 'position' && !in_array(trim(strtolower($parts[2])), ['static', 'relative'], true)) continue;
            if ($property === 'z-index' && !preg_match('/^\s*(?:auto|-?\d{1,2}|100|-100)\s*$/D', $parts[2])) continue;
            $valid = true;
            if (preg_match_all('/url\(\s*([\'"]?)(.*?)\1\s*\)/i', $parts[2], $urls, PREG_SET_ORDER)) {
                foreach ($urls as $url) if (!self::url($url[2])) $valid = false;
            }
            $withoutUrls = preg_replace('/url\([^)]*\)/i', '', $parts[2]) ?? '';
            if (!$valid || !preg_match('/^[a-z0-9\s#.,%()\'"+\/-]*$/iD', $withoutUrls)) continue;
            $safe[] = $declaration;
        }
        return implode(';', $safe);
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
