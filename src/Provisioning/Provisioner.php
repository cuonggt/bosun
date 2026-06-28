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
        $this->task('Configuring repository access', fn () => $this->configureRepositoryAccess());
        $this->task('Granting service-control permissions', fn () => $this->configureSudoers());
        $this->task('Configuring the firewall', $this->configureFirewall());

        // Hardening runs only after the deploy user exists and has inherited the
        // SSH key, so disabling password auth can never lock anyone out.
        $this->task('Installing security packages', $this->aptInstall('fail2ban', 'unattended-upgrades'));
        $this->task('Hardening SSH access', fn () => $this->hardenSsh());
        $this->task('Enabling automatic security updates', fn () => $this->configureUnattendedUpgrades());
        $this->task('Configuring log rotation', fn () => $this->configureLogRotation());

        $this->task('Preparing the application directory', fn () => $this->prepareDeployPath());
        $this->task('Configuring the Nginx site', fn () => $this->configureNginx());
        $this->task('Configuring the queue worker', fn () => $this->configureSupervisor());
        $this->task('Enabling and restarting services', $this->restartServices());
    }

    /* ----------------------------------------------------------------------
     | Package installation helpers
     | ---------------------------------------------------------------------- */

    /**
     * Abort immediately unless the server is Ubuntu 22.04 or 24.04. Validating
     * before touching the system means an unsupported distro fails fast with a
     * clear message instead of part-way through a broken install (every later
     * step assumes apt, the ondrej PPA and these exact service names).
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
     * The directive is appended only when no active one already exists, so it's
     * idempotent and works whatever state /etc/gai.conf starts in.
     */
    protected function preferIpv4(): string
    {
        return 'grep -qE \'^precedence ::ffff:0:0/96\' /etc/gai.conf 2>/dev/null '
            .'|| echo \'precedence ::ffff:0:0/96  100\' >> /etc/gai.conf';
    }

    /**
     * Give a memory-constrained box a 1G swapfile so a heavy composer/npm step
     * can't OOM mid-provision or mid-deploy, plus matching VM tuning (swappiness
     * 30, vfs_cache_pressure 50).
     *
     * Only created when the server has no swap at all — neither a swapfile nor a
     * swap partition the provider may already have set up. fallocate is fastest,
     * with a dd fallback for filesystems where a fallocate'd file isn't valid
     * swap; the fstab entry survives reboots. The sysctl settings go in a
     * sysctl.d drop-in (overwritten each run) rather than appended to sysctl.conf.
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
     * prompt.
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
     * as a conf.d drop-in (loaded after the stock php.ini) rather than editing
     * php.ini in place — the drop-in is idempotent and leaves the distro's file
     * untouched. The settings are applied by the php-fpm restart in
     * restartServices().
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
     * Deliberately left alone: nginx.conf's worker `user`. bosun keeps the stock
     * www-data user and instead puts the deploy user in the www-data group, so
     * rewriting it here would fight the rest of the provisioner.
     */
    protected function tuneNginx(): void
    {
        // gzip is already enabled (`gzip on;`) in the stock nginx.conf http
        // block, which also includes this file — so re-declaring it here would
        // be a duplicate directive and fail `nginx -t`. We only set the tuning
        // sub-directives, which the stock config ships commented out.
        $this->connection->put('/etc/nginx/conf.d/bosun.conf', implode("\n", [
            '# Managed by bosun.',
            'server_tokens off;',
            'server_names_hash_bucket_size 128;',
            'client_max_body_size 64m;',
            '',
            'gzip_comp_level 5;',
            'gzip_min_length 256;',
            'gzip_proxied any;',
            'gzip_vary on;',
            'gzip_types',
            '    application/atom+xml application/javascript application/json application/ld+json',
            '    application/manifest+json application/rss+xml application/vnd.ms-fontobject',
            '    application/x-font-ttf application/x-web-app-manifest+json application/xhtml+xml',
            '    application/xml font/opentype image/svg+xml image/x-icon text/cache-manifest',
            '    text/css text/plain text/vcard text/vtt text/x-component text/x-cross-domain-policy;',
            '',
        ]));

        // Trust Cloudflare's edge IPs only when opted in, so $remote_addr is the
        // real visitor. Removed when disabled so toggling off is clean.
        if ($this->config['cloudflare'] ?? false) {
            $this->connection->put('/etc/nginx/conf.d/cloudflare.conf', $this->cloudflareConf());
        } else {
            $this->exec('rm -f /etc/nginx/conf.d/cloudflare.conf');
        }
    }

    /**
     * Cloudflare edge ranges plus the header carrying the real client IP. The
     * list can drift as Cloudflare changes ranges; refresh it from
     * https://www.cloudflare.com/ips/ if visitor IPs look wrong.
     */
    protected function cloudflareConf(): string
    {
        $ranges = [
            '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
            '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
            '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
            '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
            '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
            '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
        ];

        $lines = ['# Managed by bosun — trust Cloudflare so $remote_addr is the real visitor.'];
        foreach ($ranges as $range) {
            $lines[] = "set_real_ip_from {$range};";
        }
        $lines[] = 'real_ip_header CF-Connecting-IP;';
        $lines[] = '';

        return implode("\n", $lines);
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

    /**
     * Give the deploy user what it needs to clone a private repository over SSH:
     * its own ed25519 deploy key, and the git host's keys in known_hosts.
     *
     * The key is generated only when missing, so re-running setup never rotates
     * a key you've already registered as a deploy key. known_hosts is seeded for
     * the configured repository's host (or the common providers if no repository
     * is set yet) so a non-interactive clone can't fail host-key verification;
     * each host is removed and re-scanned so the seeding stays idempotent and
     * picks up rotated keys.
     */
    protected function configureRepositoryAccess(): void
    {
        $user = $this->config['deploy_user'];
        $home = "/home/{$user}";
        $key = "{$home}/.ssh/id_ed25519";
        $known = "{$home}/.ssh/known_hosts";
        $comment = "bosun-{$this->config['application']}@{$this->server->host}";

        $commands = [
            "mkdir -p {$home}/.ssh && chmod 700 {$home}/.ssh",
            "[ -f {$key} ] || ssh-keygen -t ed25519 -N '' -C ".escapeshellarg($comment)." -f {$key}",
            "touch {$known}",
        ];

        // Append each host's keys only if that exact line isn't already present,
        // so re-running setup never duplicates entries.
        foreach ($this->repositoryHosts() as $host) {
            $commands[] = 'ssh-keyscan '.escapeshellarg($host).' 2>/dev/null | while read -r l; do '
                .'grep -qxF "$l" '.$known.' || echo "$l" >> '.$known.'; done';
        }

        $commands[] = "chown -R {$user}:{$user} {$home}/.ssh";
        $commands[] = "chmod 600 {$key}";

        $this->execChain($commands);
    }

    /**
     * The git host(s) to trust in known_hosts. Derived from the configured
     * repository when possible; otherwise the common providers so a repository
     * set later still deploys.
     *
     * @return array<int, string>
     */
    protected function repositoryHosts(): array
    {
        $repo = $this->config['repository'] ?? null;

        if (! empty($repo)) {
            // scp-like syntax: git@host:owner/repo.git
            if (preg_match('/^[^@\s]+@([^:\/\s]+):/', $repo, $m)) {
                return [$m[1]];
            }
            // URL syntax: scheme://[user@]host[:port]/owner/repo.git
            if (preg_match('#^[a-z][a-z0-9+.-]*://(?:[^@/]+@)?([^:/\s]+)#i', $repo, $m)) {
                return [$m[1]];
            }
        }

        return ['github.com', 'gitlab.com', 'bitbucket.org'];
    }

    /**
     * Read the deploy user's public key off the server so `setup` can show it.
     * Returns null if the key isn't present (e.g. provisioning didn't complete).
     */
    public function deployPublicKey(): ?string
    {
        $user = $this->config['deploy_user'];
        $key = trim($this->connection->run("cat /home/{$user}/.ssh/id_ed25519.pub 2>/dev/null")->output);

        return $key !== '' ? $key : null;
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
     * 22.04+) so the distro's sshd_config stays pristine. Any missing host keys
     * are generated first (`ssh-keygen -A`); then the config is tested with
     * `sshd -t` before reload — a broken sshd_config must never be applied — and
     * reload (not restart) keeps the current session alive.
     */
    protected function hardenSsh(): void
    {
        $this->exec('mkdir -p /etc/ssh/sshd_config.d');

        $this->connection->put(
            '/etc/ssh/sshd_config.d/49-bosun.conf',
            "# Managed by bosun.\nPasswordAuthentication no\n"
        );

        $this->exec('ssh-keygen -A && sshd -t && systemctl reload ssh');
    }

    /**
     * Apply security updates automatically via unattended-upgrades. The two
     * files allow the distro's -security pocket and turn on the periodic timer
     * that actually runs the upgrades.
     *
     * Note the literal ${distro_id}/${distro_codename} tokens — unattended-
     * upgrades expands those itself at runtime. They must reach the file intact,
     * which writing it directly (rather than through a shell heredoc, which
     * would expand them to *empty* first) guarantees.
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

    /**
     * Stop a runaway log from filling the disk between rotations. Two parts that
     * only work together: cap the high-volume logs at 100M (`maxsize`), and run
     * logrotate every 5 minutes (stock is daily) so that cap is actually checked
     * often enough to matter.
     *
     * The size cap is added idempotently to the logs our stack produces, and any
     * config that isn't present on this box is skipped. The 5-minute schedule is
     * a timer drop-in that resets OnCalendar, leaving the stock unit untouched.
     */
    protected function configureLogRotation(): void
    {
        $php = $this->server->phpVersion;

        $commands = [];
        foreach (['nginx', "php{$php}-fpm", 'fail2ban', 'ufw'] as $name) {
            $path = "/etc/logrotate.d/{$name}";

            // Replace an existing maxsize, else insert one after the rotation
            // interval line (preserving its indentation). Guarded on existence.
            $commands[] = '[ -f '.$path.' ] && { '
                .'grep -q maxsize '.$path.' '
                .'&& sed -i -E \'s/^([[:space:]]*)maxsize.*/\1maxsize 100M/\' '.$path.' '
                .'|| sed -i -E \'s/^([[:space:]]*)(daily|weekly|monthly|yearly)$/\1\2\n\1maxsize 100M/\' '.$path.'; '
                .'} || true';
        }
        $this->execChain($commands);

        $this->exec('mkdir -p /etc/systemd/system/logrotate.timer.d');
        $this->connection->put('/etc/systemd/system/logrotate.timer.d/override.conf', implode("\n", [
            '[Timer]',
            // Reset the stock daily schedule, then run every 5 minutes.
            'OnCalendar=',
            'OnCalendar=*:0/5',
            '',
        ]));

        $this->exec('systemctl daemon-reload && systemctl restart logrotate.timer');
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
            'app' => $app,
        ]);

        $this->connection->put("/etc/nginx/sites-available/{$app}", $config);

        $this->execChain([
            "ln -nfs /etc/nginx/sites-available/{$app} /etc/nginx/sites-enabled/{$app}",
            'rm -f /etc/nginx/sites-enabled/default /etc/nginx/sites-available/default',
        ]);

        // Reject requests that don't match the configured domain (the bare IP,
        // spoofed Host headers, scanners) instead of serving the app. Only with
        // a real domain — without one the app intentionally answers on "_".
        if ($this->config['domain']) {
            $this->configureCatchAll();
        } else {
            $this->exec('rm -f /etc/nginx/sites-enabled/000-catch-all');
        }

        $this->exec('nginx -t');
    }

    /**
     * A default server that rejects (444) any request whose Host doesn't match a
     * configured site. The self-signed cert exists only so the HTTPS default
     * server is valid on nginx 1.18 (Ubuntu 22.04, which lacks
     * ssl_reject_handshake); it's never trusted. RSA-2048 keygen is fast, so no
     * slow dhparam is generated, and the cert is created only once.
     */
    protected function configureCatchAll(): void
    {
        $this->exec(
            'mkdir -p /etc/nginx/ssl && [ -f /etc/nginx/ssl/catch-all.crt ] || '
            .'openssl req -x509 -nodes -newkey rsa:2048 -days 3650 '
            .'-keyout /etc/nginx/ssl/catch-all.key -out /etc/nginx/ssl/catch-all.crt '
            ."-subj '/CN=catch-all' 2>/dev/null"
        );

        $this->connection->put('/etc/nginx/sites-available/000-catch-all', implode("\n", [
            'server {',
            '    listen 80 default_server;',
            '    listen [::]:80 default_server;',
            '    listen 443 ssl default_server;',
            '    listen [::]:443 ssl default_server;',
            '    server_name _;',
            '    server_tokens off;',
            '',
            '    ssl_certificate /etc/nginx/ssl/catch-all.crt;',
            '    ssl_certificate_key /etc/nginx/ssl/catch-all.key;',
            '    ssl_protocols TLSv1.2 TLSv1.3;',
            '',
            '    return 444;',
            '}',
            '',
        ]));

        $this->exec('ln -nfs /etc/nginx/sites-available/000-catch-all /etc/nginx/sites-enabled/000-catch-all');
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
