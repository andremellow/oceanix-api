<?php

namespace App\Services\Documents;

use App\Models\Lesson;
use DOMDocument;
use Illuminate\Validation\ValidationException;

final class LessonDocumentLinks
{
    public function map(string $html, callable $url): string
    {
        if (! str_contains($html, '/lesson-documents/')) {
            return $html;
        }
        $dom = $this->parse($html);
        foreach ($dom->getElementsByTagName('a') as $anchor) {
            if ($id = $this->id($anchor->getAttribute('href'))) {
                $anchor->setAttribute('href', $url($id));
                $anchor->setAttribute('target', '_blank');
                $anchor->setAttribute('rel', 'noopener noreferrer');
                $anchor->setAttribute('title', __('PDF — opens in a new tab'));
            }
        }
        $body = $dom->getElementsByTagName('body')->item(0);

        return implode('', array_map(fn ($node) => $dom->saveHTML($node), iterator_to_array($body->childNodes)));
    }

    public function ids(string $html): array
    {
        $ids = [];
        foreach ($this->parse($html)->getElementsByTagName('a') as $anchor) {
            if ($id = $this->id($anchor->getAttribute('href'))) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    public function validate(Lesson $lesson, string $html): void
    {
        $ids = $this->ids($html);
        if ($ids !== [] && $lesson->documents()->whereIn('public_id', $ids)->count() !== count($ids)) {
            throw ValidationException::withMessages(['content_markdown' => __('A PDF link is not attached to this lesson. Upload the PDF here before inserting it.')]);
        }
    }

    public function copy(Lesson $source, Lesson $target): void
    {
        $target->documents()->syncWithoutDetaching($source->documents()->pluck('lesson_documents.id')->all());
    }

    private function id(string $href): ?string
    {
        return preg_match('~^/lesson-documents/([a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12})$~D', $href, $match) ? $match[1] : null;
    }

    private function parse(string $html): DOMDocument
    {
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $dom->loadHTML('<?xml encoding="UTF-8"><html><body>'.$html.'</body></html>', LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $dom;
    }
}
