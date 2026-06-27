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
            'database_name' => 'app',
            'database_user' => 'app',
            'database_password' => 'app-secret-pw',
            'database_root_password' => 'root-secret-pw',
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

    public function test_it_installs_mysql_non_interactively_with_a_preseeded_root_password(): void
    {
        $connection = new FakeConnection();
        (new Provisioner($connection, $this->server(), $this->config()))->execute();

        $ran = $connection->ranAll();

        // Preseed the root password through debconf so the install never blocks
        // on a prompt (the cause of the dpkg "followup error" failure).
        $this->assertStringContainsString('debconf-set-selections', $ran);
        $this->assertStringContainsString('mysql-community-server/root-pass password root-secret-pw', $ran);
        $this->assertStringContainsString('DEBIAN_FRONTEND=noninteractive', $ran);
    }

    public function test_it_bootstraps_the_application_database_and_user(): void
    {
        $connection = new FakeConnection();
        (new Provisioner($connection, $this->server(), $this->config()))->execute();

        $ran = $connection->ranAll();

        // Idempotent CREATE statements run as root over the local socket.
        // (Single quotes are rewritten by escapeshellarg, so assert on the
        // backtick-quoted identifiers and password, which survive escaping.)
        $this->assertStringContainsString('CREATE DATABASE IF NOT EXISTS `app` CHARACTER SET utf8mb4', $ran);
        $this->assertStringContainsString('CREATE USER IF NOT EXISTS', $ran);
        $this->assertStringContainsString('app-secret-pw', $ran);
        $this->assertStringContainsString('GRANT ALL PRIVILEGES ON `app`.*', $ran);
    }

    public function test_it_tunes_mysql_with_a_confd_dropin(): void
    {
        $connection = new FakeConnection();
        (new Provisioner($connection, $this->server(), $this->config()))->execute();

        $ran = $connection->ranAll();

        // Memory-scaled connection limit (floor 150) and no password expiry,
        // written to a conf.d drop-in and applied with a restart.
        $this->assertStringContainsString('/proc/meminfo', $ran);
        $this->assertStringContainsString('max_connections', $ran);
        $this->assertStringContainsString('default_password_lifetime = 0', $ran);
        $this->assertStringContainsString('/etc/mysql/mysql.conf.d/bosun.cnf', $ran);
        $this->assertStringContainsString('systemctl restart mysql', $ran);

        // Deliberately localhost-only: bosun never exposes MySQL externally.
        $this->assertStringNotContainsString('bind-address', $ran);
    }

    public function test_it_does_not_tune_mysql_for_other_engines(): void
    {
        foreach (['pgsql', 'none'] as $database) {
            $connection = new FakeConnection();
            (new Provisioner($connection, $this->server($database), $this->config()))->execute();

            $this->assertStringNotContainsString('bosun.cnf', $connection->ranAll());
        }
    }

    public function test_it_does_not_bootstrap_a_database_for_other_engines(): void
    {
        foreach (['pgsql', 'none'] as $database) {
            $connection = new FakeConnection();
            (new Provisioner($connection, $this->server($database), $this->config()))->execute();

            $this->assertStringNotContainsString('CREATE DATABASE', $connection->ranAll());
        }
    }

    public function test_it_records_database_credentials_for_deploys(): void
    {
        $connection = new FakeConnection();
        (new Provisioner($connection, $this->server(), $this->config()))->execute();

        $this->assertArrayHasKey('/home/deployer/app/shared/.bosun-database.env', $connection->files);

        $creds = $connection->files['/home/deployer/app/shared/.bosun-database.env'];
        $this->assertStringContainsString('DB_DATABASE=app', $creds);
        $this->assertStringContainsString('DB_USERNAME=app', $creds);
        $this->assertStringContainsString('DB_PASSWORD=app-secret-pw', $creds);

        // Locked down: only the deploy user may read the password.
        $this->assertStringContainsString('chmod 600 /home/deployer/app/shared/.bosun-database.env', $connection->ranAll());
    }

    public function test_it_does_not_record_credentials_for_other_engines(): void
    {
        foreach (['pgsql', 'none'] as $database) {
            $connection = new FakeConnection();
            (new Provisioner($connection, $this->server($database), $this->config()))->execute();

            $this->assertArrayNotHasKey('/home/deployer/app/shared/.bosun-database.env', $connection->files);
        }
    }

    public function test_it_recovers_half_configured_packages_so_reruns_are_clean(): void
    {
        // Provisioning starts by finishing any package a previous interrupted
        // run left half-configured; otherwise apt fails for the rest of the run
        // and `setup` can never recover. Runs regardless of the chosen database.
        foreach (['mysql', 'pgsql', 'none'] as $database) {
            $connection = new FakeConnection();
            (new Provisioner($connection, $this->server($database), $this->config()))->execute();

            $this->assertStringContainsString('dpkg --configure -a', $connection->ranAll());
        }
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

        // Config is validated before the reload, so a bad file is never applied.
        $this->assertStringContainsString('sshd -t && systemctl reload ssh', $connection->ranAll());
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
        $this->assertStringContainsString('gzip on;', $conf);
        $this->assertStringContainsString('client_max_body_size 64m;', $conf);

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
