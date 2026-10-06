<?php

namespace Tests\Concerns;

use Illuminate\Foundation\Testing\RefreshDatabase;

trait RefreshFinancialDatabase
{
    use RefreshDatabase;

    protected function migrateDatabases(): void
    {
        // Ordinary migrations only: never reset or seed even the isolated test database.
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
    }
}
