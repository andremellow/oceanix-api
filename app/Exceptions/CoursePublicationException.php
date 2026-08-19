<?php

namespace App\Exceptions;

use RuntimeException;

class CoursePublicationException extends RuntimeException
{
    /** @param list<string> $problems */
    public function __construct(public readonly array $problems)
    {
        parent::__construct(__('This version cannot be published yet.'));
    }

    public static function notEditable(): self
    {
        return new self([__('A published version is immutable. Create a new draft to make changes.')]);
    }

    public static function draftAlreadyExists(): self
    {
        return new self([__('This course already has an open draft version.')]);
    }
}
