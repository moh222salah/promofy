<?php

namespace App\Support\Html;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Allow-list HTML sanitiser for rich-text content written in the admin editor.
 * Unknown tags are unwrapped, every attribute not explicitly allowed is dropped, and URLs
 * must use http(s), mailto or tel (or be relative), so scripts, event handlers and
 * javascript: links can never reach the public site.
 */
final class HtmlSanitizer
{
    private const TAGS = [
        'p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'h2', 'h3', 'h4', 'ul', 'ol', 'li', 'blockquote',
        'a', 'img', 'figure', 'figcaption', 'table', 'thead', 'tbody', 'tr', 'th', 'td', 'code', 'pre', 'hr', 'span',
    ];

    private const DROP_WITH_CONTENT = ['script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'textarea', 'select', 'svg', 'math', 'link', 'meta', 'base', 'noscript', 'template'];

    private const ATTRIBUTES = [
        'a' => ['href', 'title', 'target', 'rel'],
        'img' => ['src', 'alt', 'width', 'height', 'loading'],
        'th' => ['colspan', 'rowspan'],
        'td' => ['colspan', 'rowspan'],
        'span' => ['dir', 'lang'],
        'p' => ['dir'],
        'h2' => ['dir'], 'h3' => ['dir'], 'h4' => ['dir'], 'li' => ['dir'], 'blockquote' => ['dir'],
    ];

    public static function clean(?string $html): ?string
    {
        if ($html === null || trim($html) === '') {
            return $html;
        }

        $doc = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><div id="__root">'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $doc->getElementById('__root');
        if ($root === null) {
            return '';
        }
        self::walk($root);

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }

        return trim($out);
    }

    private static function walk(DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if (! $child instanceof DOMElement) {
                if ($child->nodeType === XML_COMMENT_NODE || $child->nodeType === XML_PI_NODE) {
                    $node->removeChild($child);
                }

                continue;
            }

            $tag = strtolower($child->tagName);

            if (in_array($tag, self::DROP_WITH_CONTENT, true)) {
                $node->removeChild($child);

                continue;
            }

            self::walk($child);

            if (! in_array($tag, self::TAGS, true)) {
                while ($child->firstChild) {
                    $node->insertBefore($child->firstChild, $child);
                }
                $node->removeChild($child);

                continue;
            }

            self::cleanAttributes($child, $tag);
        }
    }

    private static function cleanAttributes(DOMElement $el, string $tag): void
    {
        $allowed = self::ATTRIBUTES[$tag] ?? [];

        foreach (iterator_to_array($el->attributes) as $attr) {
            $name = strtolower($attr->name);
            if (! in_array($name, $allowed, true)) {
                $el->removeAttribute($attr->name);

                continue;
            }
            if (in_array($name, ['href', 'src'], true) && ! self::safeUrl($attr->value, $name === 'src')) {
                $el->removeAttribute($attr->name);
            }
        }

        if ($tag === 'a') {
            if ($el->getAttribute('target') !== '' && $el->getAttribute('target') !== '_blank') {
                $el->removeAttribute('target');
            }
            if ($el->getAttribute('target') === '_blank') {
                $el->setAttribute('rel', 'noopener noreferrer nofollow');
            }
        }
    }

    private static function safeUrl(string $url, bool $isImage): bool
    {
        $url = trim(preg_replace('/[\x00-\x20]+/', '', $url) ?? '');
        if ($url === '') {
            return false;
        }
        if (preg_match('#^(https?:)?//#i', $url) || str_starts_with($url, '/') || str_starts_with($url, '#')) {
            return true;
        }
        if (preg_match('/^([a-z][a-z0-9+.-]*):/i', $url, $m)) {
            return ! $isImage && in_array(strtolower($m[1]), ['http', 'https', 'mailto', 'tel'], true);
        }

        return true; // relative URL
    }
}
