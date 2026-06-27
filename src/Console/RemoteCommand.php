<?php

namespace Cuonggt\Bosun\Console;

use Closure;
use Illuminate\Console\Command;
use Cuonggt\Bosun\Concerns\InteractsWithServers;
use Cuonggt\Bosun\RemoteScript;
use Cuonggt\Bosun\Ssh\ConnectionException;
use Cuonggt\Bosun\Ssh\RemoteTaskException;

/**
 * Base class for the setup and deploy commands. Owns the rendering of remote
 * tasks so the orchestration classes stay free of console concerns.
 */
abstract class RemoteCommand extends Command
{
    use InteractsWithServers;

    /**
     * Execute a remote script, rendering each task and handling failures.
     */
    protected function runScript(RemoteScript $script): int
    {
        $script->runUsing($this->renderer($script));

        try {
            $script->execute();
        } catch (RemoteTaskException $e) {
            $this->newLine();
            $this->components->error("Aborted on {$script->server->host} after a failed command.");

            return Command::FAILURE;
        } catch (ConnectionException $e) {
            $this->components->error($e->getMessage());

            return Command::FAILURE;
        } catch (\InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * Build the closure that renders and runs a single task.
     */
    protected function renderer(RemoteScript $script): Closure
    {
        return function (string $description, Closure|string $command) use ($script): void {
            $action = function (?callable $onOutput = null) use ($script, $command) {
                return $command instanceof Closure ? $command() : $script->exec($command, $onOutput);
            };

            if ($this->output->isVerbose()) {
                $this->renderVerbose($description, $command, $action);

                return;
            }

            $this->renderTask($script, $description, $action);
        };
    }

    /**
     * Compact rendering: a single line per task with a DONE / FAIL marker.
     */
    protected function renderTask(RemoteScript $script, string $description, Closure $action): void
    {
        $failure = null;

        $this->components->task($description, function () use ($action, &$failure): bool {
            try {
                $action();

                return true;
            } catch (RemoteTaskException $e) {
                $failure = $e;

                return false;
            }
        });

        if ($failure !== null) {
            $this->showFailure($failure);

            throw $failure;
        }
    }

    /**
     * Verbose rendering: stream the command and its live output.
     */
    protected function renderVerbose(string $description, Closure|string $command, Closure $action): void
    {
        $this->line("<fg=cyan>➜</> <options=bold>{$description}</>");

        if (is_string($command)) {
            $this->line("  <fg=gray>{$command}</>");
        }

        try {
            $action(fn (string $chunk) => $this->output->write($chunk));
        } catch (RemoteTaskException $e) {
            $this->showFailure($e);

            throw $e;
        }
    }

    /**
     * Print the details of a failed command.
     */
    protected function showFailure(RemoteTaskException $e): void
    {
        $result = $e->result;

        $this->newLine();
        $this->components->error("Command failed (exit {$result->exitCode}):");
        $this->line("  <fg=gray>{$result->command}</>");

        $message = trim($result->errorOutput) ?: trim($result->output);

        if ($message !== '') {
            $this->newLine();
            $this->line("<fg=red>{$message}</>");
        }
    }
}
