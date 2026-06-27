<?php

namespace Cuonggt\Bosun\Tests;

use Cuonggt\Bosun\Ssh\CommandResult;
use Cuonggt\Bosun\Ssh\Connection;

/**
 * An in-memory Connection that records every command and written file, so
 * tests can assert what a script would do on a server without touching SSH.
 */
class FakeConnection implements Connection
{
    /** @var array<int, string> */
    public array $commands = [];

    /** @var array<string, string> */
    public array $files = [];

    /** @var array<string, CommandResult> Exact-match canned responses. */
    public array $responses = [];

    /** @var array<string, CommandResult> Substring-matched canned responses. */
    public array $patternResponses = [];

    public function __construct(public int $defaultExit = 0)
    {
    }

    public function run(string $command, ?callable $onOutput = null): CommandResult
    {
        $this->commands[] = $command;

        if (isset($this->responses[$command])) {
            return $this->responses[$command];
        }

        foreach ($this->patternResponses as $needle => $result) {
            if (str_contains($command, $needle)) {
                return new CommandResult($command, $result->output, $result->errorOutput, $result->exitCode);
            }
        }

        return new CommandResult($command, '', '', $this->defaultExit);
    }

    public function put(string $path, string $contents, bool $sudo = false): bool
    {
        $this->files[$path] = $contents;
        $this->commands[] = "PUT {$path}";

        return true;
    }

    public function disconnect(): void
    {
    }

    /**
     * Queue a specific result for an exact command string.
     */
    public function respondTo(string $command, int $exitCode, string $output = '', string $errorOutput = ''): void
    {
        $this->responses[$command] = new CommandResult($command, $output, $errorOutput, $exitCode);
    }

    /**
     * Queue a result for any command containing the given substring.
     */
    public function respondToCommandsContaining(string $needle, int $exitCode, string $output = '', string $errorOutput = ''): void
    {
        $this->patternResponses[$needle] = new CommandResult($needle, $output, $errorOutput, $exitCode);
    }

    public function ranAll(): string
    {
        return implode("\n", $this->commands);
    }
}
