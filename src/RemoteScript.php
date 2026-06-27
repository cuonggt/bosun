<?php

namespace Cuonggt\Bosun;

use Closure;
use Cuonggt\Bosun\Ssh\CommandResult;
use Cuonggt\Bosun\Ssh\Connection;
use Cuonggt\Bosun\Ssh\RemoteTaskException;

/**
 * Base class for a scripted sequence of remote tasks (provisioning, deploying).
 *
 * Subclasses describe their work in execute() by calling $this->task(). The
 * console command supplies a "runner" closure that renders each task and
 * decides how failures and verbose output are presented, keeping all I/O
 * concerns out of the orchestration logic.
 */
abstract class RemoteScript
{
    /** @var Closure(string, Closure|string): void */
    protected Closure $runner;

    public function __construct(
        public readonly Connection $connection,
        public readonly Server $server,
        public readonly array $config,
    ) {
        $this->runner = fn (string $description, Closure|string $command) => is_string($command)
            ? $this->exec($command)
            : $command();
    }

    /**
     * Describe the work this script performs.
     */
    abstract public function execute(): void;

    /**
     * Path to the file where provisioning records the application database
     * credentials, so deploys can write them into shared/.env. Lives in
     * "shared" (outside any release) and is readable only by the deploy user.
     */
    protected function databaseCredentialsPath(): string
    {
        return rtrim($this->config['deploy_path'], '/').'/shared/.bosun-database.env';
    }

    /**
     * Provide the closure used to render and run each task.
     *
     * @param  Closure(string, Closure|string): void  $runner
     */
    public function runUsing(Closure $runner): void
    {
        $this->runner = $runner;
    }

    /**
     * Register a task. The command is either a shell string to run on the
     * server, or a closure performing several steps.
     */
    protected function task(string $description, Closure|string $command): void
    {
        ($this->runner)($description, $command);
    }

    /**
     * Run a command on the server, throwing if it fails.
     */
    public function exec(string $command, ?callable $onOutput = null): CommandResult
    {
        $result = $this->connection->run($command, $onOutput);

        if ($result->failed()) {
            throw new RemoteTaskException($result);
        }

        return $result;
    }

    /**
     * Run a sequence of commands as a single chained shell invocation, so that
     * a `cd` or environment export carries across them (each exec() otherwise
     * runs in its own shell).
     *
     * @param  array<int, string>  $commands
     */
    public function execChain(array $commands, ?callable $onOutput = null): CommandResult
    {
        return $this->exec(implode(' && ', array_filter($commands)), $onOutput);
    }
}
