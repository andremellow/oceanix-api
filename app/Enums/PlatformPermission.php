<?php

namespace App\Enums;

/** Atomic platform abilities; platform administrators currently receive the complete set. */
enum PlatformPermission: string
{
    case SharedModulesView = 'shared-modules.view';
    case SharedModulesCreate = 'shared-modules.create';
    case SharedModulesUpdate = 'shared-modules.update';
    case SharedModulesPublish = 'shared-modules.publish';
    case SharedModulesArchive = 'shared-modules.archive';
}
