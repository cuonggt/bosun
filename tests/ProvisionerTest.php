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

    private function server(): Server
    {
        return new Server(
            name: 'production',
            host: '203.0.113.10',
            port: 22,
            username: 'deployer',
            phpVersion: '8.3',
            nodeVersion: '20',
        );
    }

    public function test_it_checks_the_os_before_touching_packages(): void
    {
        $connection = new FakeConnection();
        (new Provisioner($connection, $this->server(), $this->config()))->execute();

        $ran = $connection->ranAll();

        $this->assertStringContainsString('/etc/os-release', $ran);
        $this->assertStringContainsString('Ubuntu 22.04 and 24.04', $ran);
        $this->assertLessThan(
            strpos($ran, 'apt-get'),
            strpos($ran, '/etc/os-release'),
            'The OS check must run before any apt command.',
        );
    }

    public function test_it_aborts_on_an_unsupported_operating_system(): void
    {
        $connection = new FakeConnection();
        // Make the OS check fail (e.g. Ubuntu 20.04 or a non-Ubuntu distro).
        $connection->respondToCommandsContaining('/etc/os-release', 1);

        try {
            (new Provisioner($connection, $this->server(), $this->config()))->execute();
            $this->fail('Provisioning should abort on an unsupported OS.');
        } catch (\Cuonggt\Bosun\Ssh\RemoteTaskException $e) {
            // Nothing should have been installed before the gate failed.
            $this->assertStringNotContainsString('apt-get install', $connection->ranAll());
        }
    }

    public function test_it_prefers_ipv4_before_any_network_step(): void
    {
        $connection = new FakeConnection();
        (new Provisioner($connection, $this->server(), $this->config()))->execute();

        $ran = $connection->ranAll();

        // Raises IPv4 precedence in gai.conf, appended only if not already set.
        $this->assertStringContainsString('precedence ::ffff:0:0/96  100', $ran);
        $this->assertStringContainsString('/etc/gai.conf', $ran);

        // Must precede apt (its purpose is to keep network steps from stalling).
        $this->assertLessThan(
            strpos($ran, 'apt-get'),
            strpos($ran, '/etc/gai.conf'),
            'IPv4 preference must be set before any apt command.',
        );
    }

    public function test_it_configures_swap_and_vm_tuning(): void
    {
        $connection = new FakeConnection();
        (new Provisioner($connection, $this->server(), $this->config()))->execute();

        $ran = $connection->ranAll();

        // A swapfile, created only when the box has no swap yet, persisted in fstab.
        $this->assertStringContainsString('swapon --show', $ran);
        $this->assertStringContainsString('mkswap /swapfile', $ran);
        $this->assertStringContainsString('/etc/fstab', $ran);

        // VM tuning lands in a sysctl.d drop-in, then is applied.
        $this->assertArrayHasKey('/etc/sysctl.d/99-bosun.conf', $connection->files);
        $sysctl = $connection->files['/etc/sysctl.d/99-bosun.conf'];
        $this->assertStringContainsString('vm.swappiness = 30', $sysctl);
        $this->assertStringContainsString('vm.vfs_cache_pressure = 50', $sysctl);
        $this->assertStringContainsString('sysctl --system', $ran);
    }

    public function test_it_upgrades_base_packages_before_installing(): void
    {
        $connection = new FakeConnection();
        (new Provisioner($connection, $this->server(), $this->config()))->execute();

        $ran = $connection->ranAll();

        // Plain upgrade (not dist-upgrade) with the conf flags that keep an
        // upgrade from stalling on a changed-config-file prompt.
        $this->assertStringContainsString('apt-get upgrade -y', $ran);
        $this->assertStringNotContainsString('dist-upgrade', $ran);
        $this->assertMatchesRegularExpression('/apt-get upgrade -y.*--force-confold/', $ran);

        // The upgrade runs after refreshing lists but before any package install.
        $this->assertLessThan(
            strpos($ran, 'apt-get install'),
            strpos($ran, 'apt-get upgrade'),
            'Base packages must be upgraded before installing the stack.',
        );
    }

    public function test_it_installs_a_build_toolchain_and_runtime_deps(): void
    {
        $connection = new FakeConnection();
        (new Provisioner($connection, $this->server(), $this->config()))->execute();

        $ran = $connection->ranAll();

        // Compiler toolchain so deploy-time `npm run build` can build native modules.
        $this->assertStringContainsString('build-essential', $ran);
        $this->assertStringContainsString('pkg-config', $ran);
        // cron for the scheduler, jq for deploy hooks.
        $this->assertStringContainsString('jq', $ran);
        $this->assertMatchesRegularExpression('/\bcron\b/', $ran);

        // Forge's bloat we deliberately leave out.
        $this->assertStringNotContainsString('sendmail', $ran);
        $this->assertStringNotContainsString('libmcrypt4', $ran);
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
        $this->assertStringContainsString('redis-server', $ran);
        $this->assertStringContainsString('supervisor', $ran);
        $this->assertStringContainsString('deb.nodesource.com/setup_20.x', $ran);
        $this->assertStringContainsString('certbot', $ran);
    }

    public function test_apt_runs_without_blocking_on_locks_or_prompts(): void
    {
        $connection = new FakeConnection();
        (new Provisioner($connection, $this->server(), $this->config()))->execute();

        $ran = $connection->ranAll();

        // Waits out unattended-upgrades instead of racing it to the dpkg lock.
        $this->assertStringContainsString('fuser /var/lib/dpkg/lock', $ran);
        $this->assertStringContainsString('/var/lib/dpkg/lock-frontend', $ran);
        // Keeps existing config files instead of opening an interactive prompt.
        $this->assertStringContainsString('--force-confold', $ran);
        // Switches needrestart to automatic so library upgrades don't stall.
        $this->assertStringContainsString('/etc/needrestart/needrestart.conf', $ran);
    }

    public function test_apt_lock_wait_precedes_the_first_install(): void
    {
        $connection = new FakeConnection();
        (new Provisioner($connection, $this->server(), $this->config()))->execute();

        // Every install command carries its own lock wait, so the snippet
        // appears at least as often as there are apt-get install invocations.
        $waits = substr_count($connection->ranAll(), 'Waiting for apt/dpkg lock');
        $installs = substr_count($connection->ranAll(), 'apt-get install');

        $this->assertGreaterThanOrEqual($installs, $waits);
    }

    public function test_it_recovers_half_configured_packages_so_reruns_are_clean(): void
    {
        // Provisioning starts by finishing any package a previous interrupted
        // run left half-configured; otherwise apt fails for the rest of the run
        // and `setup` can never recover.
        $connection = new FakeConnection();
        (new Provisioner($connection, $this->server(), $this->config()))->execute();

        $this->assertStringContainsString('dpkg --configure -a', $connection->ranAll());
    }

    public function test_it_hardens_ssh_to_key_only_auth(): void
    {
        $connection = new FakeConnection();
        (new Provisioner($connection, $this->server(), $this->config()))->execute();

        $this->assertArrayHasKey('/etc/ssh/sshd_config.d/49-bosun.conf', $connection->files);
        $this->assertStringContainsString(
            'PasswordAuthentication no',
            $connection->files['/etc/ssh/sshd_config.d/49-bosun.conf'],
        );

        // Missing host keys are generated, then the config is validated before
        // the reload, so a bad file is never applied.
        $this->assertStringContainsString('ssh-keygen -A && sshd -t && systemctl reload ssh', $connection->ranAll());
    }

    public function test_it_installs_and_enables_fail2ban(): void
    {
        $connection = new FakeConnection();
        (new Provisioner($connection, $this->server(), $this->config()))->execute();

        $ran = $connection->ranAll();
        $this->assertStringContainsString('fail2ban', $ran);
        $this->assertStringContainsString('systemctl enable fail2ban', $ran);
    }

    public function test_it_enables_automatic_security_updates(): void
    {
        $connection = new FakeConnection();
        (new Provisioner($connection, $this->server(), $this->config()))->execute();

        $this->assertStringContainsString('unattended-upgrades', $connection->ranAll());

        $this->assertArrayHasKey('/etc/apt/apt.conf.d/20auto-upgrades', $connection->files);
        $this->assertStringContainsString(
            'APT::Periodic::Unattended-Upgrade "1";',
            $connection->files['/etc/apt/apt.conf.d/20auto-upgrades'],
        );

        // The ${distro_id} tokens are expanded by unattended-upgrades at runtime,
        // so they must reach the file literally (not be interpolated away).
        $origins = $connection->files['/etc/apt/apt.conf.d/50unattended-upgrades'];
        $this->assertStringContainsString('${distro_id}:${distro_codename}-security', $origins);
    }

    public function test_it_tunes_php_for_fpm_and_cli(): void
    {
        $connection = new FakeConnection();
        (new Provisioner($connection, $this->server(), $this->config()))->execute();

        foreach (['fpm', 'cli'] as $sapi) {
            $path = "/etc/php/8.3/{$sapi}/conf.d/99-bosun.ini";
            $this->assertArrayHasKey($path, $connection->files);
            $this->assertStringContainsString('memory_limit = 512M', $connection->files[$path]);
            // RCE hardening — PHP must not guess a script for a missing path.
            $this->assertStringContainsString('cgi.fix_pathinfo = 0', $connection->files[$path]);
        }
    }

    public function test_it_tunes_nginx_with_an_http_dropin(): void
    {
        $connection = new FakeConnection();
        (new Provisioner($connection, $this->server(), $this->config()))->execute();

        $this->assertArrayHasKey('/etc/nginx/conf.d/bosun.conf', $connection->files);

        $conf = $connection->files['/etc/nginx/conf.d/bosun.conf'];
        $this->assertStringContainsString('gzip_comp_level 5;', $conf);
        $this->assertStringContainsString('client_max_body_size 64m;', $conf);

        // gzip is already enabled by the stock nginx.conf; re-declaring it here
        // would be a duplicate directive that fails `nginx -t`.
        $this->assertStringNotContainsString('gzip on', $conf);

        // We keep the stock www-data worker user, so the tuning never rewrites it.
        $this->assertStringNotContainsString('user ', $conf);
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

    public function test_it_does_not_provision_a_database(): void
    {
        $connection = new FakeConnection();
        (new Provisioner($connection, $this->server(), $this->config()))->execute();

        $ran = $connection->ranAll();

        // Databases are out of scope — bosun installs no DB server and creates
        // no database. (The php-mysql/pgsql PDO drivers are still installed so
        // the app can reach an external database.)
        $this->assertStringNotContainsString('mysql-server', $ran);
        $this->assertStringNotContainsString('postgresql', $ran);
        $this->assertStringNotContainsString('CREATE DATABASE', $ran);
        $this->assertArrayNotHasKey('/home/deployer/app/shared/.bosun-database.env', $connection->files);
    }
}
