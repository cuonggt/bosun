<?php

namespace Cuonggt\Bosun\Tests;

class DeployCommandTest extends IntegrationTestCase
{
    private FakeConnection $connection;

    protected function defineEnvironment($app): void
    {
        $app['config']->set('bosun.default', 'production');
        $app['config']->set('bosun.application', 'app');
        $app['config']->set('bosun.repository', 'git@github.com:acme/app.git');
        $app['config']->set('bosun.branch', 'main');
        $app['config']->set('bosun.servers.production', [
            'host' => '203.0.113.10',
            'port' => 22,
            'username' => 'deployer',
            'deploy_path' => '/home/deployer/app',
            'php' => '8.3',
        ]);

        // Swap the real SSH connection for an in-memory fake.
        $this->connection = new FakeConnection();
        $app->bind('bosun.connection', fn () => fn () => $this->connection);
    }

    public function test_the_deploy_command_drives_a_full_deployment(): void
    {
        $this->artisan('deploy', ['server' => 'production'])
            ->assertSuccessful();

        $ran = $this->connection->ranAll();
        $this->assertStringContainsString('git clone', $ran);
        $this->assertStringContainsString('mv -Tf /home/deployer/app/current.new /home/deployer/app/current', $ran);
    }

    public function test_the_deploy_command_reports_a_failed_command(): void
    {
        // Make the Composer step fail; the command should abort with exit 1.
        $this->connection->respondToCommandsContaining(
            'composer install',
            1,
            errorOutput: 'Your requirements could not be resolved.',
        );

        $this->artisan('deploy', ['server' => 'production'])
            ->assertExitCode(1);
    }
}
