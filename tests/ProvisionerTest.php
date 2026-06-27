<?php

namespace Cuonggt\Bosun\Tests;

use PHPUnit\Framework\TestCase;
use Cuonggt\Bosun\Provisioning\Provisioner;
use Cuonggt\Bosun\Server;

class ProvisionerTest extends TestCase
{
    private function config(array $overrides = []): array
    {
        return array_merge([
            'application' => 'app',
            'deploy_user' => 'deployer',
            'deploy_path' => '/home/deployer/app',
            'domain' => 'example.com',
            'queue_connection' => '',
            'queue_processes' => 2,
        ], $overrides);
    }

    private function server(string $database = 'mysql'): Server
    {
        return new Server(
            name: 'production',
            host: '203.0.113.10',
            port: 22,
            username: 'deployer',
            phpVersion: '8.3',
            nodeVersion: '20',
            database: $database,
        );
    }

    public function test_it_installs_the_expected_stack(): void
    {
        $connection = new FakeConnection();
        (new Provisioner($connection, $this->server(), $this->config()))->execute();

        $ran = $connection->ranAll();

        $this->assertStringContainsString('ppa:ondrej/php', $ran);
        $this->assertStringContainsString('php8.3-fpm', $ran);
        $this->assertStringContainsString('php8.3-redis', $ran);
        $this->assertStringContainsString('getcomposer.org/installer', $ran);
        $this->assertStringContainsString('nginx', $ran);
        $this->assertStringContainsString('mysql-server', $ran);
        $this->assertStringContainsString('redis-server', $ran);
        $this->assertStringContainsString('supervisor', $ran);
        $this->assertStringContainsString('deb.nodesource.com/setup_20.x', $ran);
        $this->assertStringContainsString('certbot', $ran);
    }

    public function test_it_creates_an_unprivileged_deploy_user(): void
    {
        $connection = new FakeConnection();
        (new Provisioner($connection, $this->server(), $this->config()))->execute();

        $ran = $connection->ranAll();
        $this->assertStringContainsString("id -u deployer >/dev/null 2>&1 || adduser", $ran);
        $this->assertStringContainsString('usermod -aG sudo,www-data deployer', $ran);
    }

    public function test_it_writes_an_nginx_site_for_the_domain(): void
    {
        $connection = new FakeConnection();
        (new Provisioner($connection, $this->server(), $this->config()))->execute();

        $this->assertArrayHasKey('/etc/nginx/sites-available/app', $connection->files);

        $site = $connection->files['/etc/nginx/sites-available/app'];
        $this->assertStringContainsString('server_name example.com;', $site);
        $this->assertStringContainsString('root /home/deployer/app/current/public;', $site);
        $this->assertStringContainsString('php8.3-fpm.sock', $site);
    }

    public function test_it_writes_a_supervisor_worker(): void
    {
        $connection = new FakeConnection();
        (new Provisioner($connection, $this->server(), $this->config()))->execute();

        $this->assertArrayHasKey('/etc/supervisor/conf.d/app-worker.conf', $connection->files);

        $worker = $connection->files['/etc/supervisor/conf.d/app-worker.conf'];
        $this->assertStringContainsString('[program:app-worker]', $worker);
        $this->assertStringContainsString('numprocs=2', $worker);
        $this->assertStringContainsString('user=deployer', $worker);
    }

    public function test_it_grants_passwordless_service_control(): void
    {
        $connection = new FakeConnection();
        (new Provisioner($connection, $this->server(), $this->config()))->execute();

        $this->assertArrayHasKey('/etc/sudoers.d/deployer-bosun', $connection->files);

        $sudoers = $connection->files['/etc/sudoers.d/deployer-bosun'];
        $this->assertStringContainsString('NOPASSWD: /usr/bin/systemctl reload php8.3-fpm', $sudoers);
    }

    public function test_postgres_can_be_selected(): void
    {
        $connection = new FakeConnection();
        (new Provisioner($connection, $this->server('pgsql'), $this->config()))->execute();

        $ran = $connection->ranAll();
        $this->assertStringContainsString('postgresql', $ran);
        $this->assertStringNotContainsString('mysql-server', $ran);
    }

    public function test_no_database_can_be_selected(): void
    {
        $connection = new FakeConnection();
        (new Provisioner($connection, $this->server('none'), $this->config()))->execute();

        $ran = $connection->ranAll();
        $this->assertStringNotContainsString('mysql-server', $ran);
        $this->assertStringNotContainsString('postgresql', $ran);
    }
}
