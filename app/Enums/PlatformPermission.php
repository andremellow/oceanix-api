<?php

namespace App\Enums;

/** Atomic platform abilities; platform administrators currently receive the complete set. */
enum PlatformPermission: string
{
    case SharedCoursesView = 'shared-courses.view';
    case SharedCoursesUpdate = 'shared-courses.update';
    case SharedCoursesGeneratePreviewLink = 'shared-courses.preview-links.generate';

    case SharedModulesView = 'shared-modules.view';
    case SharedModulesCreate = 'shared-modules.create';
    case SharedModulesUpdate = 'shared-modules.update';
    case SharedModulesPublish = 'shared-modules.publish';
    case SharedModulesDiscardDraft = 'shared-modules.drafts.discard';
    case SharedModulesArchive = 'shared-modules.archive';

    /** @return list<self> */
    public function prerequisites(): array
    {
        return match ($this) {
            self::SharedCoursesGeneratePreviewLink => [self::SharedCoursesView, self::SharedCoursesUpdate],
            self::SharedModulesDiscardDraft => [self::SharedModulesView, self::SharedModulesUpdate],
            self::SharedCoursesUpdate => [self::SharedCoursesView],
            default => [],
        };
    }
}
