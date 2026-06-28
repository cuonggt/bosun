# Bosun

> A bosun (boatswain) is the crew member who provisions, maintains, and readies the ship before it sails. This package does the same for your Laravel app.

Provision servers and deploy Laravel applications with zero downtime — straight from `artisan`.

This package gives you two commands:

| Command | What it does |
| --- | --- |
| `php artisan setup` | Provisions a fresh Ubuntu server: PHP-FPM, Nginx, Composer, Node, Redis, Supervisor, Certbot, a firewall and a non-root deploy user. |
| `php artisan deploy` | Deploys your app with zero downtime using timestamped releases, shared `.env`/`storage`, atomic symlink swaps and automatic rollback-ready release pruning. |

It talks to your servers over SSH using [phpseclib](https://phpseclib.com/) — there's no dependency on a local `ssh` binary, and the whole thing is unit-tested against a fake connection.

---

## Requirements

- PHP 8.1+
- Laravel 10, 11 or 12
- A target server running **Ubuntu 22.04 or 24.04** that you can reach over SSH as `root` (most cloud providers give you this on a fresh box)

## Installation

```bash
composer require cuonggt/bosun
```

The service provider is auto-discovered. Publish the config file:

```bash
php artisan vendor:publish --tag=bosun-config
```

This creates `config/bosun.php`.

## Configuration

Everything is driven by `config/bosun.php`, which reads from your `.env`. A minimal setup:

```dotenv
DEPLOY_HOST=203.0.113.10
DEPLOY_USER=deployer
DEPLOY_KEY=~/.ssh/id_rsa
DEPLOY_DOMAIN=example.com
DEPLOY_REPOSITORY=git@github.com:acme/app.git
DEPLOY_BRANCH=main
DEPLOY_PATH=/home/deployer/app
```

Each server is an entry under `servers` in the config file, so you can define `production`, `staging`, and more. Key options:

| Option | Description | Default |
| --- | --- | --- |
| `host` / `port` | Where to connect. | — / `22` |
| `username` | The **deploy user** — created during `setup`, connected as during `deploy`. | `deployer` |
| `key` / `passphrase` / `password` | Auth. A readable key file wins; otherwise the password is used. | `~/.ssh/id_rsa` |
| `domain` | Server name for the generated Nginx site. | — |
| `deploy_path` | Where the app lives on the server. `{application}` is substituted. | `/home/deployer/{application}` |
| `php` / `node` | Stack to provision. | `8.3` / `20` |

App-wide options (outside `servers`): `repository`, `branch`, `shared_files`, `shared_dirs`, `keep_releases`, `build_assets`, `queue`, and `hooks`.

## Provisioning a server

Point the package at a fresh server and run:

```bash
php artisan setup production
```

By default it connects as `root` (override with `--user`). It will:

1. Update apt and install base utilities.
2. Install PHP (with the common Laravel extensions, including the MySQL/Postgres PDO drivers), Composer, Nginx, Redis, Supervisor, Node and Certbot.
3. Create the unprivileged **deploy user**, authorizing the same SSH key you connected with (add another with `--key`).
4. Generate an SSH **deploy key** for that user and trust your Git host, so it can clone a private repo (see below).
5. Grant that user *passwordless* permission to reload PHP-FPM/Nginx and control Supervisor — nothing more.
6. Configure the firewall (UFW: SSH + HTTP/HTTPS).
7. Lay out the deploy directory, write the Nginx site and the Supervisor queue worker, then start everything.

Every step is **idempotent**, so re-running `setup` to add an extension or change a setting is safe.

### Private repositories

Use an **SSH** repository URL (`git@github.com:you/app.git`). At the end of `setup`, bosun prints the deploy user's public key:

```
Add this read-only deploy key to your Git repository, then deploy:

  ssh-ed25519 AAAA… bosun-app@203.0.113.10
```

Register it as a **read-only deploy key** (GitHub: *Settings → Deploy keys*; GitLab: *Settings → Repository → Deploy keys*), then `php artisan deploy`. The key is per-server, read-only, and regenerated only if absent — so re-running `setup` never invalidates a key you've already registered.

> **Databases are out of scope.** bosun installs the MySQL/Postgres PDO drivers but does not provision a database server. Point your app at a managed or external database by setting `DB_*` in the server's `shared/.env`.

```bash
php artisan setup production --user=root --key=~/.ssh/deploy_key.pub
```

## Deploying

```bash
php artisan deploy production
```

This performs a zero-downtime deploy:

```
/home/deployer/app/
├── current -> releases/20260627T120000   # atomic symlink
├── releases/
│   ├── 20260627T120000/
│   └── …                                  # previous releases kept for rollback
└── shared/
    ├── .env                               # persists across deploys
    └── storage/                           # persists across deploys
```

The sequence:

1. Clone the repo into a fresh, timestamped release and record the commit in a `REVISION` file.
2. Symlink shared files/dirs (`.env`, `storage`) into the release.
3. `composer install --no-dev`, then (unless `--no-build`) `npm ci && npm run build`.
4. `storage:link`, cache config/routes/views/events, and run `php artisan migrate --force`.
5. **Atomically** swap the `current` symlink to the new release.
6. Reload PHP-FPM (to refresh OPcache) and restart queue workers.
7. Prune old releases beyond `keep_releases`.

Because the symlink is swapped only after the release is fully built and migrated, no request ever hits a half-deployed app.

### First deploy

On the very first deploy there's no `.env` yet, so the package seeds `shared/.env` from your repo's `.env.example`, skips migrations, and reminds you to fill it in. Configure it on the server, then deploy again:

```bash
ssh deployer@203.0.113.10
nano /home/deployer/app/shared/.env   # set APP_KEY, DB creds, etc.
exit

php artisan deploy production          # this run will migrate
```

### Useful options

```bash
php artisan deploy staging                 # deploy to a different server
php artisan deploy production --branch=hotfix
php artisan deploy production --no-build    # skip front-end asset build
php artisan deploy production -v            # stream live command output
```

`-v` also works on `setup`, streaming the raw server output for each step.

## Deployment hooks

Run extra commands on the server during a deploy via `config/bosun.php`:

```php
'hooks' => [
    'before' => ['php artisan down'],                 // runs in the deploy path, before building
    'after'  => ['php artisan up', 'php artisan horizon:terminate'], // runs in the new release
],
```

## How it fits together

```
SetupCommand ─┐                          ┌─ Provisioner ──┐
              ├─ RemoteCommand (rendering)┤                ├─ RemoteScript ─ Connection (SSH)
DeployCommand ┘                          └─ DeploymentRunner ┘
```

- **`Connection`** is a tiny interface (`run`, `put`, `disconnect`). The real one uses phpseclib; tests use an in-memory fake.
- **`RemoteScript`** orchestrates a sequence of tasks but knows nothing about the console.
- **`RemoteCommand`** renders those tasks (compact ticks, or streamed output with `-v`) and reports failures.

That separation is why the provisioning and deployment logic is fully unit-tested without ever opening a socket.

## Testing

```bash
composer install
composer test
```

## Security notes

- The deploy user is unprivileged. Its only `sudo` rights are passwordless reloads of PHP-FPM/Nginx and Supervisor control, written to `/etc/sudoers.d` and validated with `visudo -c`.
- Provisioning enables UFW and opens only SSH and HTTP/HTTPS.
- For HTTPS, run `sudo certbot --nginx -d example.com` on the server after the first deploy (Certbot is already installed).

## License

MIT — see [LICENSE.md](LICENSE.md).
