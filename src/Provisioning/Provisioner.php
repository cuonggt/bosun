<?php

namespace Cuonggt\Bosun\Provisioning;

use Cuonggt\Bosun\RemoteScript;

/**
 * Provisions a fresh Ubuntu server (22.04 / 24.04) with everything a Laravel
 * application needs: PHP-FPM, Nginx, Composer, Node, Redis, Supervisor,
 * Certbot, a firewall and an unprivileged deploy user.
 *
 * Databases are intentionally out of scope — point the app at a managed or
 * external database via shared/.env yourself.
 *
 * Connects as root. Every step is idempotent, so re-running `setup` is safe.
 */
class Provisioner extends RemoteScript
{
    public function execute(): void
    {
        $php = $this->server->phpVersion;
        $user = $this->config['deploy_user'];

        $this->task('Checking the operating system', $this->assertSupportedOs());
        $this->task('Preferring IPv4 for reliable networking', $this->preferIpv4());
        $this->task('Configuring swap', fn () => $this->configureSwap());
        $this->task('Preparing apt for unattended installs', $this->configureUnattendedApt());
        $this->task('Updating package lists', $this->aptUpdate());
        $this->task('Upgrading installed packages', $this->aptUpgrade());
        $this->task('Installing base packages', $this->aptInstall(
            // Connectivity, archives and repository management.
            'software-properties-common', 'curl', 'wget', 'git', 'unzip', 'zip', 'gnupg', 'ca-certificates',
            // Permissions (setfacl) and firewall.
            'acl', 'ufw',
            // Build toolchain — `npm run build` and pecl routinely compile native
            // modules (node-gyp), which fail without a compiler and pkg-config.
            'build-essential', 'pkg-config',
            // Laravel runtime needs: cron drives the scheduler, sqlite3 backs the
            // default/local DB, jq parses JSON in deploy hooks.
            'cron', 'sqlite3', 'jq'
        ));

        $this->task('Adding the PHP repository (ondrej/php)', $this->phpRepository());
        $this->task("Installing PHP {$php} and extensions", $this->aptInstall(...$this->phpPackages($php)));
        $this->task("Tuning PHP {$php}", fn () => $this->tunePhp());
        $this->task('Installing Composer', $this->installComposer());

        $this->task('Installing Nginx', $this->aptInstall('nginx'));
        $this->task('Tuning Nginx', fn () => $this->tuneNginx());

        $this->task('Installing Redis', $this->aptInstall('redis-server'));
        $this->task('Installing Supervisor', $this->aptInstall('supervisor'));
        $this->task("Installing Node.js {$this->server->nodeVersion}", $this->installNode());
        $this->task('Installing Certbot (Let\'s Encrypt)', $this->aptInstall('certbot', 'python3-certbot-nginx'));

        $this->task("Creating deploy user [{$user}]", fn () => $this->createDeployUser());
        $this->task('Granting service-control permissions', fn () => $this->configureSudoers());
        $this->task('Configuring the firewall', $this->configureFirewall());

        // Hardening runs only after the deploy user exists and has inherited the
        // SSH key, so disabling password auth can never lock anyone out.
        $this->task('Installing security packages', $this->aptInstall('fail2ban', 'unattended-upgrades'));
        $this->task('Hardening SSH access', fn () => $this->hardenSsh());
        $this->task('Enabling automatic security updates', fn () => $this->configureUnattendedUpgrades());

        $this->task('Preparing the application directory', fn () => $this->prepareDeployPath());
        $this->task('Configuring the Nginx site', fn () => $this->configureNginx());
        $this->task('Configuring the queue worker', fn () => $this->configureSupervisor());
        $this->task('Enabling and restarting services', $this->restartServices());
    }

    /* ----------------------------------------------------------------------
     | Package installation helpers
     | ---------------------------------------------------------------------- */

    /**
     * Abort immediately unless the server is Ubuntu 22.04 or 24.04. Like Forge,
     * we validate before touching the system so an unsupported distro fails fast
     * with a clear message instead of part-way through a broken install (every
     * later step assumes apt, the ondrej PPA and these exact service names).
     *
     * /etc/os-release is shell-sourceable, so reading $ID/$VERSION_ID from it is
     * cleaner and more reliable than scraping the file with awk.
     */
    protected function assertSupportedOs(): string
    {
        return '. /etc/os-release; '
            .'if [ "$ID" != "ubuntu" ] || '
            .'{ [ "$VERSION_ID" != "22.04" ] && [ "$VERSION_ID" != "24.04" ]; }; then '
            .'echo "bosun only supports Ubuntu 22.04 and 24.04 (detected: ${PRETTY_NAME:-unknown})." >&2; '
            .'exit 1; fi';
    }

    /**
     * Make glibc prefer IPv4 over IPv6 in getaddrinfo()'s address selection.
     * Many cloud images come up with an IPv6 address but no working IPv6 route;
     * with IPv6 preferred, every network call (apt, composer, git, npm) tries it
     * first and stalls until it times out before falling back to IPv4. Raising
     * the precedence of IPv4-mapped addresses avoids those mystery hangs.
     *
     * Forge un-comments the example line in /etc/gai.conf, which silently does
     * nothing if that exact line isn't present. We append the directive when no
     * active one exists, so it works whatever state the file starts in.
     */
    protected function preferIpv4(): string
    {
        return 'grep -qE \'^precedence ::ffff:0:0/96\' /etc/gai.conf 2>/dev/null '
            .'|| echo \'precedence ::ffff:0:0/96  100\' >> /etc/gai.conf';
    }

    /**
     * Give a memory-constrained box a 1G swapfile so a heavy composer/npm step
     * can't OOM mid-provision or mid-deploy, plus the VM tuning Forge pairs with
     * it (swappiness 30, vfs_cache_pressure 50).
     *
     * Only created when the server has no swap at all — a swapfile or a swap
     * partition the provider may already have set up — which is a touch smarter
     * than Forge's "does /swapfile exist" check. fallocate is fastest, with a dd
     * fallback for filesystems where a fallocate'd file isn't valid swap; the
     * fstab entry survives reboots. The sysctl settings go in a sysctl.d drop-in
     * (overwritten each run) rather than appended to sysctl.conf.
     */
    protected function configureSwap(): void
    {
        $this->exec(implode(' ', [
            'if [ -z "$(swapon --show 2>/dev/null)" ] && [ ! -f /swapfile ]; then',
            'fallocate -l 1G /swapfile || dd if=/dev/zero of=/swapfile bs=1M count=1024;',
            'chmod 600 /swapfile;',
            'mkswap /swapfile;',
            'swapon /swapfile;',
            'grep -q "^/swapfile " /etc/fstab || echo "/swapfile none swap sw 0 0" >> /etc/fstab;',
            'fi',
        ]));

        $this->connection->put('/etc/sysctl.d/99-bosun.conf', implode("\n", [
            '# Managed by bosun.',
            'vm.swappiness = 30',
            'vm.vfs_cache_pressure = 50',
            '',
        ]));

        $this->exec('sysctl --system >/dev/null');
    }

    protected function aptUpdate(): string
    {
        // Wait for any apt/dpkg lock first, then `dpkg --configure -a` finishes
        // configuring anything a previous interrupted run left half-installed.
        // Without it, that leftover state makes every later apt step fail with
        // "Sub-process /usr/bin/dpkg returned an error code (1)", so re-running
        // `setup` could never recover. This runs as the first apt task (and
        // again after the PHP repository is added), making re-runs self-healing.
        return $this->aptWait()
            .' && export DEBIAN_FRONTEND=noninteractive && dpkg --configure -a && apt-get update -y';
    }

    /**
     * Bring every already-installed package up to date. A "fresh" cloud image is
     * often weeks or months stale, so this closes the initial patch gap that
     * unattended-upgrades only covers going forward. Plain `upgrade` (never
     * `dist-upgrade`) so nothing is removed and the kernel isn't swapped.
     *
     * The conf flags matter most here: an upgrade is exactly when dpkg meets a
     * changed config file and would otherwise stop on a "keep or replace?"
     * prompt. (Forge runs the upgrade without them — arguably a gap.)
     */
    protected function aptUpgrade(): string
    {
        return $this->aptWait()
            .' && export DEBIAN_FRONTEND=noninteractive && apt-get upgrade -y '
            .'-o Dpkg::Options::="--force-confdef" -o Dpkg::Options::="--force-confold"';
    }

    protected function aptInstall(string ...$packages): string
    {
        // --force-confold/--force-confdef keep existing config files (or use the
        // package default) instead of opening an interactive "keep or replace?"
        // prompt, which would otherwise hang an unattended run on any upgrade.
        return $this->aptWait()
            .' && export DEBIAN_FRONTEND=noninteractive && apt-get install -y '
            .'-o Dpkg::Options::="--force-confdef" -o Dpkg::Options::="--force-confold" '
            .'--no-install-recommends '
            .implode(' ', $packages);
    }

    /**
     * A self-contained shell snippet that blocks until no other process holds an
     * apt or dpkg lock. On a fresh boot `unattended-upgrades` often runs for the
     * first few minutes; without this, the first apt command races it and dies
     * with "Could not get lock /var/lib/dpkg/lock-frontend". Bounded to ~5
     * minutes so a genuinely stuck lock can't hang provisioning forever, and
     * wrapped in a subshell so it chains cleanly with `&&`.
     */
    protected function aptWait(): string
    {
        return '( n=0; while fuser /var/lib/dpkg/lock /var/lib/dpkg/lock-frontend '
            .'/var/lib/apt/lists/lock /var/cache/apt/archives/lock >/dev/null 2>&1; do '
            .'n=$((n+1)); [ "$n" -ge 60 ] && break; '
            .'echo "Waiting for apt/dpkg lock..."; sleep 5; done )';
    }

    /**
     * Make apt fully non-interactive before the first install. Ubuntu 22.04
     * ships needrestart in interactive mode, which pops a full-screen prompt
     * when a library upgrade wants services restarted — stalling an automated
     * run. Switching it to automatic ('a') lets installs proceed unattended.
     */
    protected function configureUnattendedApt(): string
    {
        return '[ -f /etc/needrestart/needrestart.conf ] && '
            .'sed -i "s/^#\$nrconf{restart} = \'i\';/\$nrconf{restart} = \'a\';/" '
            .'/etc/needrestart/needrestart.conf || true';
    }

    protected function phpRepository(): string
    {
        // ondrej/php provides current PHP versions on Ubuntu. add-apt-repository
        // is idempotent, so this is safe to re-run.
        return 'add-apt-repository -y ppa:ondrej/php && '.$this->aptUpdate();
    }

    /**
     * @return array<int, string>
     */
    protected function phpPackages(string $php): array
    {
        return array_map(
            fn (string $ext) => $ext === '' ? "php{$php}" : "php{$php}-{$ext}",
            ['', 'fpm', 'cli', 'common', 'mysql', 'pgsql', 'sqlite3', 'xml', 'curl', 'mbstring', 'zip', 'bcmath', 'gd', 'intl', 'redis', 'readline', 'gmp']
        );
    }

    protected function installComposer(): string
    {
        return implode(' && ', [
            'if ! command -v composer >/dev/null 2>&1; then '
                .'curl -sS https://getcomposer.org/installer | php -- '
                .'--install-dir=/usr/local/bin --filename=composer; fi',
            'composer self-update --no-interaction 2>/dev/null || true',
        ]);
    }

    /**
     * Apply Laravel-friendly PHP defaults to both the FPM and CLI SAPIs. Written
     * as a conf.d drop-in (loaded after the stock php.ini) rather than sed-ing
     * php.ini in place the way Forge does — the drop-in is idempotent and leaves
     * the distro's file untouched. The settings are applied by the php-fpm
     * restart in restartServices().
     *
     *  - memory_limit 512M: Composer and artisan are memory-hungry.
     *  - cgi.fix_pathinfo=0: stops PHP guessing a script when the exact path
     *    doesn't exist — a long-standing remote-code-execution hardening.
     *  - upload/post sizes raised together, matched by Nginx client_max_body_size.
     *  - date.timezone set so PHP stops emitting warnings.
     */
    protected function tunePhp(): void
    {
        $php = $this->server->phpVersion;

        $ini = implode("\n", [
            '; Managed by bosun.',
            'memory_limit = 512M',
            'cgi.fix_pathinfo = 0',
            'upload_max_filesize = 64M',
            'post_max_size = 64M',
            'date.timezone = UTC',
            '',
        ]);

        // CLI gets the same baseline so artisan/composer behave like FPM.
        foreach (['fpm', 'cli'] as $sapi) {
            $this->connection->put("/etc/php/{$php}/{$sapi}/conf.d/99-bosun.ini", $ini);
        }
    }

    /**
     * Global Nginx tuning via an http-context drop-in: gzip for text responses
     * and a body-size limit matched to PHP's upload limit. The site server block
     * itself is rendered separately in configureNginx(); this file only carries
     * server-wide defaults, and configureNginx()'s `nginx -t` validates both.
     *
     * Deliberately NOT ported: Forge rewrites nginx.conf's `user` to the deploy
     * user (and does the same for the FPM pool) so everything runs as one
     * account. bosun keeps the stock www-data user and instead puts the deploy
     * user in the www-data group — a different, equally valid ownership model,
     * and changing it here would fight the rest of the provisioner.
     */
    protected function tuneNginx(): void
    {
        $this->connection->put('/etc/nginx/conf.d/bosun.conf', implode("\n", [
            '# Managed by bosun.',
            'server_names_hash_bucket_size 128;',
            'client_max_body_size 64m;',
            '',
            'gzip on;',
            'gzip_comp_level 5;',
            'gzip_min_length 256;',
            'gzip_proxied any;',
            'gzip_vary on;',
            'gzip_types application/json application/javascript application/xml '
                .'application/rss+xml text/css text/plain image/svg+xml;',
            '',
        ]));
    }

    protected function installNode(): string
    {
        $version = $this->server->nodeVersion;

        return 'if ! command -v node >/dev/null 2>&1; then '
            ."curl -fsSL https://deb.nodesource.com/setup_{$version}.x | bash - && "
            .$this->aptInstall('nodejs').'; fi';
    }

    /* ----------------------------------------------------------------------
     | Server configuration helpers
     | ---------------------------------------------------------------------- */

    protected function createDeployUser(): void
    {
        $user = $this->config['deploy_user'];
        $home = "/home/{$user}";

        $this->execChain([
            // Create the user only if it does not already exist.
            "id -u {$user} >/dev/null 2>&1 || adduser --disabled-password --gecos '' {$user}",
            "usermod -aG sudo,www-data {$user}",
            "mkdir -p {$home}/.ssh",
            "chmod 700 {$home}/.ssh",
            // Reuse the key that connected as root so the deploy user is reachable.
            "[ -f /root/.ssh/authorized_keys ] && cp -n /root/.ssh/authorized_keys {$home}/.ssh/authorized_keys || true",
            "touch {$home}/.ssh/authorized_keys",
            "chmod 600 {$home}/.ssh/authorized_keys",
            "chown -R {$user}:{$user} {$home}/.ssh",
        ]);

        // Authorize an additional public key if one was provided.
        if (! empty($this->config['authorized_key'])) {
            $key = trim($this->config['authorized_key']);
            $this->exec(sprintf(
                'grep -qxF %s %s 2>/dev/null || echo %s >> %s',
                escapeshellarg($key),
                "{$home}/.ssh/authorized_keys",
                escapeshellarg($key),
                "{$home}/.ssh/authorized_keys",
            ));
        }
    }

    protected function configureSudoers(): void
    {
        $user = $this->config['deploy_user'];
        $php = $this->server->phpVersion;

        // Allow the deploy user to reload services after a deploy without a
        // password, but nothing more.
        $rules = implode(', ', [
            "/usr/bin/systemctl reload php{$php}-fpm",
            "/usr/bin/systemctl restart php{$php}-fpm",
            '/usr/bin/systemctl reload nginx',
            '/usr/bin/supervisorctl',
        ]);

        $path = "/etc/sudoers.d/{$user}-bosun";

        $this->connection->put($path, "{$user} ALL=(ALL) NOPASSWD: {$rules}\n");

        // Validate before trusting it — a malformed sudoers file is dangerous.
        $this->execChain([
            "chmod 440 {$path}",
            "visudo -cf {$path}",
        ]);
    }

    protected function configureFirewall(): string
    {
        return implode(' && ', [
            'ufw allow OpenSSH',
            "ufw allow 'Nginx Full'",
            'ufw --force enable',
        ]);
    }

    /**
     * Turn off SSH password authentication, leaving only key-based login. By
     * this point the deploy user has inherited the key that connected as root,
     * so this closes the password brute-force vector without risking lockout.
     *
     * Written as a drop-in under sshd_config.d (Include-d by default on Ubuntu
     * 22.04+) so the distro's sshd_config stays pristine. The config is tested
     * with `sshd -t` before reload — a broken sshd_config must never be applied
     * — and reload (not restart) keeps the current provisioning session alive.
     */
    protected function hardenSsh(): void
    {
        $this->exec('mkdir -p /etc/ssh/sshd_config.d');

        $this->connection->put(
            '/etc/ssh/sshd_config.d/49-bosun.conf',
            "# Managed by bosun.\nPasswordAuthentication no\n"
        );

        $this->exec('sshd -t && systemctl reload ssh');
    }

    /**
     * Apply security updates automatically via unattended-upgrades. The two
     * files mirror Forge's setup: allow the distro's -security pocket, and turn
     * on the periodic timer that actually runs the upgrades.
     *
     * Note the literal ${distro_id}/${distro_codename} tokens — unattended-
     * upgrades expands those itself at runtime. Forge writes this through an
     * unquoted shell heredoc, where the shell expands them to *empty* first;
     * bosun writes the file directly, so the tokens survive intact.
     */
    protected function configureUnattendedUpgrades(): void
    {
        $this->connection->put('/etc/apt/apt.conf.d/50unattended-upgrades', implode("\n", [
            'Unattended-Upgrade::Allowed-Origins {',
            '    "${distro_id}:${distro_codename}-security";',
            '};',
            'Unattended-Upgrade::Package-Blacklist {',
            '};',
            '',
        ]));

        $this->connection->put('/etc/apt/apt.conf.d/20auto-upgrades', implode("\n", [
            'APT::Periodic::Update-Package-Lists "1";',
            'APT::Periodic::Download-Upgradeable-Packages "1";',
            'APT::Periodic::AutocleanInterval "7";',
            'APT::Periodic::Unattended-Upgrade "1";',
            '',
        ]));
    }

    protected function prepareDeployPath(): void
    {
        $user = $this->config['deploy_user'];
        $path = $this->config['deploy_path'];

        $this->execChain([
            "mkdir -p {$path}/releases {$path}/shared",
            // Owned by the deploy user, group www-data so Nginx can read it.
            "chown -R {$user}:www-data {$path}",
            "chmod -R 2755 {$path}",
        ]);
    }

    protected function configureNginx(): void
    {
        $app = $this->config['application'];
        $domain = $this->config['domain'] ?: '_';
        $root = rtrim($this->config['deploy_path'], '/').'/current/public';

        $config = $this->renderStub('nginx.stub', [
            'server_name' => $domain,
            'root' => $root,
            'php_version' => $this->server->phpVersion,
        ]);

        $this->connection->put("/etc/nginx/sites-available/{$app}", $config);

        $this->execChain([
            "ln -nfs /etc/nginx/sites-available/{$app} /etc/nginx/sites-enabled/{$app}",
            'rm -f /etc/nginx/sites-enabled/default',
            'nginx -t',
        ]);
    }

    protected function configureSupervisor(): void
    {
        $app = $this->config['application'];
        $connection = $this->config['queue_connection'];

        $config = $this->renderStub('supervisor.stub', [
            'app' => $app,
            'deploy_path' => rtrim($this->config['deploy_path'], '/'),
            'user' => $this->config['deploy_user'],
            'processes' => (string) $this->config['queue_processes'],
            'connection' => $connection,
        ]);

        $this->connection->put("/etc/supervisor/conf.d/{$app}-worker.conf", $config);

        // reread/update may report an error until the first deploy creates
        // "current"; that is expected and harmless, so don't fail on it.
        $this->exec('supervisorctl reread; supervisorctl update; true');
    }

    protected function restartServices(): string
    {
        $php = $this->server->phpVersion;

        return implode(' && ', [
            'systemctl enable nginx',
            'systemctl restart nginx',
            "systemctl enable php{$php}-fpm",
            "systemctl restart php{$php}-fpm",
            'systemctl enable redis-server',
            'systemctl enable supervisor',
            'systemctl restart supervisor',
            // fail2ban's default jail bans repeated SSH auth failures.
            'systemctl enable fail2ban',
            'systemctl restart fail2ban',
        ]);
    }

    /**
     * Render a stub file, replacing {{ token }} placeholders.
     *
     * @param  array<string, string>  $replacements
     */
    protected function renderStub(string $stub, array $replacements): string
    {
        $contents = file_get_contents(__DIR__.'/../../stubs/'.$stub);

        foreach ($replacements as $key => $value) {
            $contents = str_replace('{{ '.$key.' }}', $value, $contents);
        }

        return $contents;
    }
}
