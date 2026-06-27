<?php

namespace Cuonggt\Bosun\Deployment;

use Cuonggt\Bosun\RemoteScript;

/**
 * Performs a zero-downtime deployment.
 *
 * Each deploy creates a fresh, timestamped release; shared state (.env,
 * storage) is symlinked in; the release is built in full; and only then is the
 * "current" symlink swapped atomically to point at it. Old releases are kept
 * for instant rollback and pruned beyond the configured limit.
 *
 *   <deploy_path>/
 *   ├── current -> releases/20260627T120000
 *   ├── releases/
 *   └── shared/  (.env, storage)
 */
class DeploymentRunner extends RemoteScript
{
    protected bool $firstDeploy = false;

    protected string $releaseName;

    public function execute(): void
    {
        $path = $this->path();
        $release = $this->releasePath();
        $branch = $this->config['branch'];
        $repository = $this->config['repository'];

        if (empty($repository)) {
            throw new \InvalidArgumentException(
                'No repository configured. Set DEPLOY_REPOSITORY or "repository" in config/bosun.php.'
            );
        }

        $this->firstDeploy = $this->connection->run("test -L {$path}/current")->failed();

        $this->task('Preparing deployment paths', "mkdir -p {$path}/releases {$path}/shared");

        $this->runHooks('before', $path);

        $this->task(
            "Cloning {$repository} ({$branch})",
            sprintf(
                'git clone --depth 1 --branch %s %s %s',
                escapeshellarg($branch),
                escapeshellarg($repository),
                $release,
            ),
        );

        $this->task('Recording the deployed commit', fn () => $this->recordRevision($release));
        $this->task('Linking shared files and directories', fn () => $this->linkShared($release));
        $this->task(
            'Installing Composer dependencies',
            "cd {$release} && composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader"
        );

        if ($this->config['build_assets']) {
            $this->task('Building front-end assets', "cd {$release} && npm ci && npm run build");
        }

        $this->task('Caching configuration and routes', fn () => $this->optimize($release));

        if ($this->firstDeploy) {
            // shared/.env was just seeded from .env.example and isn't configured
            // yet, so the database isn't reachable. Migrations run on the next
            // deploy, once the operator has filled in shared/.env.
            $this->task('Skipping migrations until .env is configured', fn () => null);
        } else {
            $this->task('Running database migrations', "cd {$release} && php artisan migrate --force");
        }

        $this->task('Activating the new release', fn () => $this->activate($release));

        $this->runHooks('after', "{$path}/current");

        $this->task('Reloading PHP-FPM', $this->reloadFpm());
        $this->task('Restarting queue workers', "cd {$path}/current && php artisan queue:restart");
        $this->task('Pruning old releases', fn () => $this->pruneReleases());
    }

    public function wasFirstDeploy(): bool
    {
        return $this->firstDeploy;
    }

    /* ----------------------------------------------------------------------
     | Steps
     | ---------------------------------------------------------------------- */

    protected function recordRevision(string $release): void
    {
        $this->exec("cd {$release} && git rev-parse HEAD > REVISION");
    }

    protected function linkShared(string $release): void
    {
        $path = $this->path();
        $commands = [];

        foreach ($this->config['shared_dirs'] as $dir) {
            $dir = trim($dir, '/');
            $shared = "{$path}/shared/{$dir}";

            $commands[] = "mkdir -p {$shared}";
            // Seed the shared directory from the release on first deploy (e.g.
            // the storage skeleton) so the app has the structure it expects.
            $commands[] = "if [ -z \"$(ls -A {$shared} 2>/dev/null)\" ] && [ -d {$release}/{$dir} ]; "
                ."then cp -a {$release}/{$dir}/. {$shared}/; fi";
            $commands[] = "rm -rf {$release}/{$dir}";
            $commands[] = "ln -nfs {$shared} {$release}/{$dir}";
        }

        foreach ($this->config['shared_files'] as $file) {
            $file = ltrim($file, '/');
            $shared = "{$path}/shared/{$file}";

            $commands[] = 'mkdir -p '.dirname($shared);
            // Seed .env-style files from the release's *.example on first deploy.
            $commands[] = "if [ ! -f {$shared} ]; then "
                ."if [ -f {$release}/{$file}.example ]; then cp {$release}/{$file}.example {$shared}; "
                ."else touch {$shared}; fi; fi";
            $commands[] = "rm -f {$release}/{$file}";
            $commands[] = "ln -nfs {$shared} {$release}/{$file}";
        }

        $this->execChain($commands);
    }

    protected function optimize(string $release): void
    {
        $this->execChain([
            "cd {$release}",
            'chmod -R ug+rwx storage bootstrap/cache 2>/dev/null || true',
            'php artisan storage:link',
            'php artisan config:cache',
            'php artisan route:cache',
            'php artisan view:cache',
            'php artisan event:cache',
        ]);
    }

    protected function activate(string $release): void
    {
        $path = $this->path();

        // Atomic swap: build the new symlink beside "current", then rename it
        // over the old one. rename(2) is atomic, so no request ever sees a
        // missing "current".
        $this->execChain([
            "ln -nfs {$release} {$path}/current.new",
            "mv -Tf {$path}/current.new {$path}/current",
        ]);
    }

    protected function reloadFpm(): string
    {
        // Picks up the new code under OPcache. Allowed without a password via
        // the sudoers rule installed during provisioning; tolerate its absence.
        $php = $this->server->phpVersion;

        return "sudo -n systemctl reload php{$php}-fpm 2>/dev/null || true";
    }

    protected function pruneReleases(): void
    {
        $keep = max(1, (int) $this->config['keep_releases']);
        $path = $this->path();

        // Newest-first, skip the ones we keep, remove the rest.
        $this->exec(
            "cd {$path}/releases && ls -1dt */ 2>/dev/null | tail -n +".($keep + 1).' | xargs -r rm -rf'
        );
    }

    /* ----------------------------------------------------------------------
     | Hooks & helpers
     | ---------------------------------------------------------------------- */

    protected function runHooks(string $when, string $workingDir): void
    {
        $hooks = $this->config['hooks'][$when] ?? [];

        foreach ($hooks as $command) {
            $this->task("Running {$when} hook: {$command}", "cd {$workingDir} && {$command}");
        }
    }

    protected function path(): string
    {
        return rtrim($this->config['deploy_path'], '/');
    }

    protected function releasePath(): string
    {
        return $this->path().'/releases/'.$this->releaseName();
    }

    protected function releaseName(): string
    {
        // Computed once per run so every step references the same release.
        return $this->releaseName ??= date('Ymd\THis');
    }
}
