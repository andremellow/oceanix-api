<?php

namespace App\Console\Commands;

use App\Actions\Platform\CreateCompany as CreateCompanyAction;
use App\Models\Company;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class CreateCompany extends Command
{
    protected $signature = 'oceanix:create-company {name} {--slug=}';

    protected $description = 'Create a tenant and its baseline access profiles';

    public function handle(CreateCompanyAction $action): int
    {
        $name = trim((string) $this->argument('name'));
        $slug = Str::slug((string) ($this->option('slug') ?: $name));

        if ($name === '' || $slug === '') {
            $this->error('A valid company name and slug are required.');

            return self::FAILURE;
        }

        if (Company::query()->where('slug', $slug)->exists()) {
            $this->error("The company slug '{$slug}' is already in use.");

            return self::FAILURE;
        }

        $company = $action->handle($name, $slug);

        $this->info('Company created. Login URL: '.route('tenant.login', $company));

        return self::SUCCESS;
    }
}
