<?php

namespace Cuonggt\Bosun\Tests;

use Illuminate\Support\Facades\Artisan;

class PackageRegistrationTest extends IntegrationTestCase
{
    public function test_it_registers_the_artisan_commands(): void
    {
        $commands = array_keys(Artisan::all());

        $this->assertContains('setup', $commands);
        $this->assertContains('deploy', $commands);
    }

    public function test_it_merges_the_default_configuration(): void
    {
        $this->assertSame('production', config('bosun.default'));
        $this->assertSame(['.env'], config('bosun.shared_files'));
        $this->assertSame(['storage'], config('bosun.shared_dirs'));
    }

    public function test_unknown_server_produces_a_helpful_error(): void
    {
        $this->artisan('deploy', ['server' => 'does-not-exist'])
            ->expectsOutputToContain('Server [does-not-exist] is not defined')
            ->assertExitCode(1);
    }
}
