<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $app = parent::createApplication();
        $connection = $app['config']->get('database.default');
        $database = $app['config']->get("database.connections.{$connection}.database");

        if (! $app->environment('testing') || $connection !== 'mysql_testing'
            || ! is_string($database) || ! preg_match('/_(test|testing)$/D', $database)
            || $database === $app['config']->get('database.connections.mysql.database')) {
            throw new \RuntimeException('Financial tests require an isolated mysql_testing database ending in _test or _testing.');
        }

        return $app;
    }
}
