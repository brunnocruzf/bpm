<?php

namespace App\Forms;

use DOMDocument;
use DOMElement;
use DOMNode;

/** Sanitização conservadora de HTML de componentes de conteúdo (lista branca). */
final class HtmlSanitizer
{
    private const ALLOWED_TAGS = ['p', 'br', 'strong', 'b', 'em', 'i', 'u', 'ul', 'ol', 'li', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'span', 'div', 'small', 'a', 'table', 'thead', 'tbody', 'tr', 'th', 'td', 'hr', 'blockquote'];

    private const ALLOWED_ATTRS = ['class', 'href', 'title', 'colspan', 'rowspan'];

    public static function clean(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }
        $doc = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><div id="__root">'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $doc->getElementById('__root');
        if (! $root) {
            return htmlspecialchars(strip_tags($html), ENT_QUOTES);
        }
        self::walk($root);

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }

        return $out;
    }

    private static function walk(DOMNode $node): void
    {
        $children = [];
        foreach ($node->childNodes as $c) {
            $children[] = $c;
        }
        foreach ($children as $child) {
            if ($child instanceof DOMElement) {
                $tag = strtolower($child->tagName);
                if (! in_array($tag, self::ALLOWED_TAGS, true)) {
                    // Remove por completo tags perigosas; desembrulha as demais.
                    if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'svg', 'math', 'link', 'meta', 'base'], true)) {
                        $node->removeChild($child);

                        continue;
                    }
                    while ($child->firstChild) {
                        $node->insertBefore($child->firstChild, $child);
                    }
                    $node->removeChild($child);

                    continue;
                }
                $attrs = [];
                foreach ($child->attributes as $attr) {
                    $attrs[] = $attr->name;
                }
                foreach ($attrs as $name) {
                    $value = (string) $child->getAttribute($name);
                    if (! in_array(strtolower($name), self::ALLOWED_ATTRS, true)) {
                        $child->removeAttribute($name);
                    } elseif (strtolower($name) === 'href' && ! preg_match('#^(https://|mailto:|/)#i', trim($value))) {
                        $child->removeAttribute($name);
                    }
                }
                if ($tag === 'a') {
                    $child->setAttribute('rel', 'noopener noreferrer');
                    $child->setAttribute('target', '_blank');
                }
                self::walk($child);
            }
        }
    }
}
