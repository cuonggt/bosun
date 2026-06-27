<?php

namespace Cuonggt\Bosun\Tests;

use PHPUnit\Framework\TestCase;
use Cuonggt\Bosun\Deployment\DeploymentRunner;
use Cuonggt\Bosun\Server;

class DeploymentRunnerTest extends TestCase
{
    private function config(array $overrides = []): array
    {
        return array_merge([
            'application' => 'app',
            'deploy_user' => 'deployer',
            'deploy_path' => '/home/deployer/app',
            'repository' => 'git@github.com:acme/app.git',
            'branch' => 'main',
            'shared_files' => ['.env'],
            'shared_dirs' => ['storage'],
            'keep_releases' => 3,
            'build_assets' => true,
            'queue_connection' => '',
            'queue_processes' => 1,
            'hooks' => ['before' => [], 'after' => []],
        ], $overrides);
    }

    private function server(): Server
    {
        return new Server(
            name: 'production',
            host: '203.0.113.10',
            port: 22,
            username: 'deployer',
            phpVersion: '8.3',
        );
    }

    public function test_it_runs_a_zero_downtime_deploy_sequence(): void
    {
        $connection = new FakeConnection();
        $runner = new DeploymentRunner($connection, $this->server(), $this->config());

        $runner->execute();

        $ran = $connection->ranAll();

        $this->assertStringContainsString(
            "git clone --depth 1 --branch 'main' 'git@github.com:acme/app.git' /home/deployer/app/releases/",
            $ran,
        );
        $this->assertStringContainsString('composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader', $ran);
        $this->assertStringContainsString('npm ci && npm run build', $ran);
        $this->assertStringContainsString('php artisan migrate --force', $ran);
        $this->assertStringContainsString('php artisan queue:restart', $ran);

        // Shared state is symlinked in.
        $this->assertStringContainsString('ln -nfs /home/deployer/app/shared/.env', $ran);
        $this->assertStringContainsString('ln -nfs /home/deployer/app/shared/storage', $ran);

        // The release is activated with an atomic symlink swap.
        $this->assertStringContainsString('mv -Tf /home/deployer/app/current.new /home/deployer/app/current', $ran);
    }

    public function test_release_is_built_before_it_is_activated(): void
    {
        $connection = new FakeConnection();
        (new DeploymentRunner($connection, $this->server(), $this->config()))->execute();

        $clone = $this->firstIndexContaining($connection->commands, 'git clone');
        $migrate = $this->firstIndexContaining($connection->commands, 'php artisan migrate --force');
        $activate = $this->firstIndexContaining($connection->commands, 'mv -Tf');

        $this->assertNotNull($clone);
        $this->assertNotNull($migrate);
        $this->assertNotNull($activate);
        $this->assertLessThan($activate, $clone, 'Clone must happen before activation.');
        $this->assertLessThan($activate, $migrate, 'Migrations must run before activation.');
    }

    public function test_keep_releases_controls_pruning(): void
    {
        $connection = new FakeConnection();
        (new DeploymentRunner($connection, $this->server(), $this->config(['keep_releases' => 5])))->execute();

        // Keep 5 => skip the 5 newest, prune the rest.
        $this->assertStringContainsString('tail -n +6', $connection->ranAll());
    }

    public function test_no_build_skips_asset_compilation(): void
    {
        $connection = new FakeConnection();
        (new DeploymentRunner($connection, $this->server(), $this->config(['build_assets' => false])))->execute();

        $this->assertStringNotContainsString('npm ci', $connection->ranAll());
    }

    public function test_first_deploy_is_detected_when_current_is_missing(): void
    {
        $connection = new FakeConnection();
        // `test -L .../current` failing means there is no current release yet.
        $connection->respondTo('test -L /home/deployer/app/current', 1);

        $runner = new DeploymentRunner($connection, $this->server(), $this->config());
        $runner->execute();

        $this->assertTrue($runner->wasFirstDeploy());
    }

    public function test_first_deploy_skips_migrations(): void
    {
        $connection = new FakeConnection();
        $connection->respondTo('test -L /home/deployer/app/current', 1);

        (new DeploymentRunner($connection, $this->server(), $this->config()))->execute();

        // No DB yet on the first deploy, so migrations must not run.
        $this->assertStringNotContainsString('php artisan migrate --force', $connection->ranAll());
    }

    public function test_subsequent_deploys_run_migrations(): void
    {
        $connection = new FakeConnection();
        // `test -L` succeeding means a current release already exists.
        $connection->respondTo('test -L /home/deployer/app/current', 0);

        (new DeploymentRunner($connection, $this->server(), $this->config()))->execute();

        $this->assertStringContainsString('php artisan migrate --force', $connection->ranAll());
    }

    public function test_hooks_run_in_their_phases(): void
    {
        $connection = new FakeConnection();
        $config = $this->config(['hooks' => [
            'before' => ['php artisan down'],
            'after' => ['php artisan up'],
        ]]);

        (new DeploymentRunner($connection, $this->server(), $config))->execute();

        $ran = $connection->ranAll();
        $this->assertStringContainsString('cd /home/deployer/app && php artisan down', $ran);
        $this->assertStringContainsString('cd /home/deployer/app/current && php artisan up', $ran);
    }

    public function test_it_fails_without_a_repository(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $connection = new FakeConnection();
        (new DeploymentRunner($connection, $this->server(), $this->config(['repository' => null])))->execute();
    }

    /**
     * @param  array<int, string>  $commands
     */
    private function firstIndexContaining(array $commands, string $needle): ?int
    {
        foreach ($commands as $index => $command) {
            if (str_contains($command, $needle)) {
                return $index;
            }
        }

        return null;
    }
}
