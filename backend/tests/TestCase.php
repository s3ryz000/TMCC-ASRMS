<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    /**
     * The suite runs on SQLite (locally and on GitHub) and can run on MySQL
     * (#61). A few tests only make sense on SQLite: they replay a one-time
     * repair of the SQLite data, whose schema changes MySQL commits at once
     * (ending the test's wrapping transaction), or they check SQLite itself.
     * On MySQL those are skipped with the reason.
     */
    protected function onlyOnSqlite(string $why): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped("SQLite only: {$why}");
        }
    }
}
