<?php

namespace App\Console\Commands;

class AdoptAccountPeople extends \Oceanix\Platform\Console\AdoptAccountPeople
{
    protected $signature = 'account:adopt-people {company} {actor : Stable initiating WorkOS user ID} {--apply : Persist links; default is a read-only dry run}';
}
