<?php

namespace App\Console\Commands;

use App\Services\Requirements\AssignmentMaterializationService;
use Illuminate\Console\Command;

class MaterializeRequirements extends Command
{
    protected $signature = 'oceanix:materialize-requirements';

    protected $description = 'Create the assignments that active training requirements currently demand';

    public function handle(AssignmentMaterializationService $service): int
    {
        $result = $service->materializeAll();

        $this->info(sprintf(
            'Assignments created: %d. Already up to date: %d.',
            $result['created'],
            $result['skipped'],
        ));

        return self::SUCCESS;
    }
}
