<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsForEachCompany;
use App\Services\Requirements\AssignmentMaterializationService;
use Illuminate\Console\Command;

class MaterializeRequirements extends Command
{
    use RunsForEachCompany;

    protected $signature = 'oceanix:materialize-requirements';

    protected $description = 'Create the assignments that active training requirements currently demand';

    public function handle(AssignmentMaterializationService $service): int
    {
        $this->forEachCompany(function () use ($service): void {
            $result = $service->materializeAll();
            $this->info(sprintf(
                'Assignments created: %d. Already up to date: %d.',
                $result['created'],
                $result['skipped'],
            ));
        });

        return self::SUCCESS;
    }
}
