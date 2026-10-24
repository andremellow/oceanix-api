<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class CreateCompany extends Command
{
    protected $signature = 'oceanix:create-company {name} {--slug=}';

    protected $description = 'Explain how to create companies through Account';

    public function handle(): int
    {
        $this->error('Create or import companies in Account, then enable Compliance there.');

        return self::FAILURE;
    }
}
