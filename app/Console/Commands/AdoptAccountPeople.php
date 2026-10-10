<?php

namespace App\Console\Commands;

use App\Actions\People\AdoptPeopleIntoAccount;
use App\Models\Company;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;

class AdoptAccountPeople extends Command
{
    protected $signature = 'account:adopt-people {company} {actor : Stable initiating WorkOS user ID} {--apply : Persist links; default is a read-only dry run}';

    protected $description = 'Adopt existing people without sending invitations or changing access';

    public function handle(AdoptPeopleIntoAccount $adopt): int
    {
        $context = app(TenantContext::class);
        $previous = $context->get();
        try {
            $company = Company::where('public_id', $this->argument('company'))->firstOrFail();
            $context->set($company);
            $actor = User::where('workos_user_id', $this->argument('actor'))->firstOrFail();
            $results = $adopt->handle($actor, dryRun: ! $this->option('apply'));
            $this->table(['Person', 'Outcome'], array_map(fn ($row) => [$row['person_id'], $row['outcome']], $results));

            return collect($results)->contains(fn ($row) => ! in_array($row['outcome'], ['ready', 'already_linked', 'confirmed'], true)) ? self::FAILURE : self::SUCCESS;
        } finally {
            $previous ? $context->set($previous) : $context->clear();
        }
    }
}
