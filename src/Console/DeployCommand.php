<?php

namespace Cuonggt\Bosun\Console;

use Illuminate\Console\Command;
use Cuonggt\Bosun\Deployment\DeploymentRunner;

class DeployCommand extends RemoteCommand
{
    protected $signature = 'deploy
        {server? : The configured server to deploy to (defaults to bosun.default)}
        {--branch= : Override the branch to deploy}
        {--no-build : Skip building front-end assets}';

    protected $description = 'Deploy the Laravel application to a server with zero downtime';

    public function handle(): int
    {
        try {
            $server = $this->resolveServer($this->argument('server'));
            $config = $this->deployConfig($server);
        } catch (\InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return Command::FAILURE;
        }

        if ($branch = $this->option('branch')) {
            $config['branch'] = $branch;
        }

        if ($this->option('no-build')) {
            $config['build_assets'] = false;
        }

        $this->components->info("Deploying to <options=bold>{$server->host}</> ({$server->name})");
        $this->line("  {$config['repository']} · branch <options=bold>{$config['branch']}</>");
        $this->newLine();

        $runner = new DeploymentRunner(
            $this->makeConnection($server),
            $server,
            $config,
        );

        $status = $this->runScript($runner);

        if ($status === Command::SUCCESS) {
            $this->newLine();
            $this->components->info('Deployment complete.');

            if ($runner->wasFirstDeploy()) {
                $this->components->warn(
                    "This was the first deploy. Configure {$config['deploy_path']}/shared/.env ".
                    'on the server (it was seeded from .env.example), then deploy again.'
                );
            }
        }

        return $status;
    }
}
