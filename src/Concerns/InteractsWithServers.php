<?php

namespace Cuonggt\Bosun\Concerns;

use InvalidArgumentException;
use Cuonggt\Bosun\Server;
use Cuonggt\Bosun\Ssh\Connection;
use Cuonggt\Bosun\Ssh\PhpseclibConnection;

/**
 * Shared helpers for the console commands: turning configuration into Server
 * objects, building connections, and assembling the merged deploy config.
 */
trait InteractsWithServers
{
    /**
     * Resolve a configured server by name, falling back to the default.
     */
    protected function resolveServer(?string $name): Server
    {
        $name = $name ?: config('bosun.default');

        $config = config("bosun.servers.{$name}");

        if (! is_array($config)) {
            $available = implode(', ', array_keys(config('bosun.servers', [])));

            throw new InvalidArgumentException(
                "Server [{$name}] is not defined in config/bosun.php. Available: {$available}."
            );
        }

        return Server::fromConfig($name, $config);
    }

    /**
     * Create an SSH connection to the given server.
     *
     * A factory may be bound in the container under "bosun.connection" to
     * swap the implementation (used in tests); otherwise a real phpseclib
     * connection is created.
     */
    protected function makeConnection(Server $server): Connection
    {
        if (function_exists('app') && app()->bound('bosun.connection')) {
            return call_user_func(app('bosun.connection'), $server);
        }

        return new PhpseclibConnection($server);
    }

    /**
     * Build the merged deployment/provisioning config for a server, layering
     * the per-server overrides on top of the package-wide defaults.
     *
     * @return array<string, mixed>
     */
    protected function deployConfig(Server $server): array
    {
        $serverConfig = config("bosun.servers.{$server->name}", []);
        $application = config('bosun.application', 'laravel');

        $deployPath = $serverConfig['deploy_path'] ?? config('bosun.deploy_path', '/home/deployer/{application}');
        $deployPath = str_replace('{application}', $application, $deployPath);

        return [
            'application' => $application,
            'deploy_user' => $server->username,
            'deploy_path' => $deployPath,
            'domain' => $serverConfig['domain'] ?? config('bosun.domain'),
            'repository' => $serverConfig['repository'] ?? config('bosun.repository'),
            'branch' => $serverConfig['branch'] ?? config('bosun.branch', 'main'),
            'database' => $serverConfig['database'] ?? config('bosun.database'),
            'shared_files' => config('bosun.shared_files', ['.env']),
            'shared_dirs' => config('bosun.shared_dirs', ['storage']),
            'keep_releases' => config('bosun.keep_releases', 5),
            'build_assets' => config('bosun.build_assets', true),
            'queue_connection' => config('bosun.queue.connection', ''),
            'queue_processes' => config('bosun.queue.processes', 1),
            'hooks' => config('bosun.hooks', ['before' => [], 'after' => []]),
        ];
    }
}
