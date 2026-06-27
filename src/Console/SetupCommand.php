<?php

namespace Cuonggt\Bosun\Console;

use Illuminate\Console\Command;
use Cuonggt\Bosun\Provisioning\Provisioner;

class SetupCommand extends RemoteCommand
{
    protected $signature = 'setup
        {server? : The configured server to provision (defaults to bosun.default)}
        {--user=root : The SSH user to connect as while provisioning}
        {--key= : Path to a public key to authorize for the deploy user}';

    protected $description = 'Provision a server with everything needed to run your Laravel application';

    public function handle(): int
    {
        try {
            $server = $this->resolveServer($this->argument('server'));
            $config = $this->deployConfig($server);

            // Provision as root (or the given user); the deploy user is created
            // during provisioning and is what `deploy` later connects as.
            $server = $server->connectAs($this->option('user'));

            if ($keyPath = $this->option('key')) {
                $config['authorized_key'] = $this->readPublicKey($keyPath);
            }
        } catch (\InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return Command::FAILURE;
        }

        $this->components->info("Provisioning <options=bold>{$server->host}</> as {$server->username}");
        $this->line("  PHP {$server->phpVersion} · Node {$server->nodeVersion} · ".
            "deploy user <options=bold>{$config['deploy_user']}</>");
        $this->newLine();

        $status = $this->runScript(new Provisioner(
            $this->makeConnection($server),
            $server,
            $config,
        ));

        if ($status === Command::SUCCESS) {
            $this->newLine();
            $this->components->info("{$server->host} is provisioned and ready.");
            $this->line("  Next: <options=bold>php artisan deploy {$server->name}</>");
        }

        return $status;
    }

    /**
     * Read and validate a public key file to authorize for the deploy user.
     */
    protected function readPublicKey(string $path): string
    {
        $path = str_starts_with($path, '~')
            ? ($_SERVER['HOME'] ?? getenv('HOME')).substr($path, 1)
            : $path;

        if (! is_file($path)) {
            throw new \InvalidArgumentException("Public key [{$path}] does not exist.");
        }

        return trim(file_get_contents($path));
    }
}
