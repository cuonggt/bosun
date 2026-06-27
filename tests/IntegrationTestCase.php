<?php

namespace Cuonggt\Bosun\Tests;

use Orchestra\Testbench\TestCase;
use Cuonggt\Bosun\BosunServiceProvider;

abstract class IntegrationTestCase extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [BosunServiceProvider::class];
    }
}
