<?php

namespace App\Enums;

enum LessonType: string
{
    case Video = 'video';

    public function label(): string
    {
        return __(match ($this) {
            self::Video => 'Video',
        });
    }
}
