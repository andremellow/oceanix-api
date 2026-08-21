<?php

namespace Tests;

use App\Models\Company;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (Schema::hasTable('companies')) {
            $company = Company::factory()->create([
                'name' => 'Test Company',
                'slug' => 'test-company-'.Str::lower(Str::random(8)),
            ]);

            app(TenantContext::class)->set($company);
        }
    }
}
