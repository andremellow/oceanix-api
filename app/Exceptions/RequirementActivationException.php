<?php

namespace App\Exceptions;

use RuntimeException;

class RequirementActivationException extends RuntimeException
{
    public static function withoutAudience(): self
    {
        return new self(__('Add at least one audience target before activating this requirement.'));
    }

    public static function withoutPublishedVersion(): self
    {
        return new self(__('The course has no published version, so this requirement cannot assign anything yet.'));
    }
}
