<?php

namespace Cuonggt\Bosun\Ssh;

/**
 * The outcome of a single command executed on a remote server.
 */
class CommandResult
{
    public function __construct(
        public readonly string $command,
        public readonly string $output,
        public readonly string $errorOutput,
        public readonly int $exitCode,
    ) {
    }

    public function successful(): bool
    {
        return $this->exitCode === 0;
    }

    public function failed(): bool
    {
        return ! $this->successful();
    }
}
