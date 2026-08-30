<?php

namespace App\Services\Courses;

use DOMDocument;
use DOMElement;
use DOMNode;

class LessonContentSanitizer
{
    /** @var array<string, list<string>> */
    private const ALLOWED = [
        'p' => [], 'h1' => [], 'h2' => [], 'h3' => [], 'strong' => [], 'em' => [],
        'u' => [], 's' => [], 'ul' => [], 'ol' => [], 'li' => [], 'blockquote' => [],
        'br' => [], 'hr' => [], 'pre' => [], 'code' => [],
        'a' => ['href', 'target', 'rel'],
        'img' => ['src', 'alt', 'data-align', 'data-width'],
        'div' => ['data-oceanix-video'],
    ];

    public function sanitize(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8" ?><div data-oceanix-root>'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $document->getElementsByTagName('div')->item(0);
        if (! $root instanceof DOMElement) {
            return '';
        }

        $this->cleanChildren($root);

        $output = '';
        foreach ($root->childNodes as $child) {
            $output .= $document->saveHTML($child);
        }

        return $output;
    }

    private function cleanChildren(DOMNode $parent): void
    {
        foreach (iterator_to_array($parent->childNodes) as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($node->tagName);
            if (! array_key_exists($tag, self::ALLOWED)) {
                if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed'], true)) {
                    $node->parentNode?->removeChild($node);

                    continue;
                }
                $this->unwrap($node);

                continue;
            }

            foreach (iterator_to_array($node->attributes) as $attribute) {
                if (! in_array(strtolower($attribute->name), self::ALLOWED[$tag], true)) {
                    $node->removeAttribute($attribute->name);
                }
            }

            if ($tag === 'a') {
                $href = $node->getAttribute('href');
                if (! $this->safeUrl($href, allowRelative: true)) {
                    $node->removeAttribute('href');
                }
                $node->setAttribute('rel', 'noopener noreferrer');
            }

            if ($tag === 'img') {
                if (! $this->safeUrl($node->getAttribute('src'), allowRelative: true)) {
                    $node->parentNode?->removeChild($node);

                    continue;
                }

                $align = $node->getAttribute('data-align');
                $width = $node->getAttribute('data-width');
                $node->setAttribute('data-align', in_array($align, ['left', 'center', 'right'], true) ? $align : 'center');
                $node->setAttribute('data-width', in_array($width, ['25', '40', '50', '75', '100'], true) ? $width : '50');
            }

            if ($tag === 'div' && ! $node->hasAttribute('data-oceanix-video')) {
                $this->unwrap($node);

                continue;
            }

            $this->cleanChildren($node);
        }
    }

    private function safeUrl(string $url, bool $allowRelative): bool
    {
        if ($allowRelative && str_starts_with($url, '/')) {
            return ! str_starts_with($url, '//');
        }

        return filter_var($url, FILTER_VALIDATE_URL) !== false && str_starts_with($url, 'https://');
    }

    private function unwrap(DOMElement $element): void
    {
        $parent = $element->parentNode;
        if ($parent === null) {
            return;
        }

        while ($element->firstChild !== null) {
            $parent->insertBefore($element->firstChild, $element);
        }

        $parent->removeChild($element);
        $this->cleanChildren($parent);
    }
}
