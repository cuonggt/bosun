<?php

use Illuminate\Support\Str;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Server
    |--------------------------------------------------------------------------
    |
    | The server that the `setup` and `deploy` commands target when you don't
    | pass a server name explicitly (e.g. `php artisan deploy staging`).
    |
    */

    'default' => env('DEPLOY_SERVER', 'production'),

    /*
    |--------------------------------------------------------------------------
    | Servers
    |--------------------------------------------------------------------------
    |
    | Each entry describes one server you can provision and deploy to. The
    | "username" is the unprivileged deploy user that owns the application and
    | that the `deploy` command connects as. Provisioning connects as root by
    | default (override with `--user`) and creates this user for you.
    |
    | Authentication uses a private key when "key" points at a readable file,
    | otherwise it falls back to "password".
    |
    */

    'servers' => [

        'production' => [
            // Connection
            'host' => env('DEPLOY_HOST'),
            'port' => (int) env('DEPLOY_PORT', 22),
            'username' => env('DEPLOY_USER', 'deployer'),
            'key' => env('DEPLOY_KEY', '~/.ssh/id_rsa'),
            'passphrase' => env('DEPLOY_KEY_PASSPHRASE'),
            'password' => env('DEPLOY_PASSWORD'),

            // The domain Nginx will serve (used to generate the site config).
            'domain' => env('DEPLOY_DOMAIN'),

            // Where the application lives on the server.
            'deploy_path' => env('DEPLOY_PATH', '/home/deployer/{application}'),

            // Provisioning preferences.
            'php' => env('DEPLOY_PHP', '8.3'),
            'node' => env('DEPLOY_NODE', '20'),
            'database' => env('DEPLOY_DATABASE', 'mysql'), // mysql, pgsql or none
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Application
    |--------------------------------------------------------------------------
    |
    | A slug used to name the generated Nginx site and Supervisor program, and
    | available as the {application} token inside "deploy_path".
    |
    */

    'application' => Str::slug(env('APP_NAME', 'laravel')),

    /*
    |--------------------------------------------------------------------------
    | Repository
    |--------------------------------------------------------------------------
    |
    | The git repository the `deploy` command clones, and the branch to deploy.
    | Use an SSH URL (git@github.com:you/app.git) and make sure the server's
    | deploy user can read it (deploy key or SSH agent forwarding).
    |
    */

    'repository' => env('DEPLOY_REPOSITORY'),

    'branch' => env('DEPLOY_BRANCH', 'main'),

    /*
    |--------------------------------------------------------------------------
    | Shared Files & Directories
    |--------------------------------------------------------------------------
    |
    | These persist across releases. They live in "<deploy_path>/shared" and are
    | symlinked into every release, so things like your .env and uploaded files
    | survive deployments. On the first deploy they are seeded from the release
    | (shared/.env is seeded from .env.example).
    |
    */

    'shared_files' => ['.env'],

    'shared_dirs' => ['storage'],

    /*
    |--------------------------------------------------------------------------
    | Releases To Keep
    |--------------------------------------------------------------------------
    |
    | How many timestamped releases to retain for instant rollback. Older
    | releases are pruned at the end of each successful deploy.
    |
    */

    'keep_releases' => 5,

    /*
    |--------------------------------------------------------------------------
    | Front-end Assets
    |--------------------------------------------------------------------------
    |
    | When true, `npm ci && npm run build` runs during deployment. Disable it
    | for API-only apps or pass --no-build on the command line.
    |
    */

    'build_assets' => true,

    /*
    |--------------------------------------------------------------------------
    | Queue Workers
    |--------------------------------------------------------------------------
    |
    | Supervisor configuration generated during provisioning. "connection" maps
    | to `queue:work {connection}` (leave empty for the default connection).
    |
    */

    'queue' => [
        'connection' => env('DEPLOY_QUEUE_CONNECTION', ''),
        'processes' => (int) env('DEPLOY_QUEUE_PROCESSES', 1),
    ],

    /*
    |--------------------------------------------------------------------------
    | Deployment Hooks
    |--------------------------------------------------------------------------
    |
    | Extra shell commands to run on the server during a deploy. "before" runs
    | in the deploy path before the new release is built; "after" runs in the
    | freshly activated release. Example: 'php artisan horizon:terminate'.
    |
    */

    'hooks' => [
        'before' => [],
        'after' => [],
    ],

];
