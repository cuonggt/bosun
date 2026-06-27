<?php

namespace Cuonggt\Bosun\Ssh;

/**
 * A connection to a remote server capable of running commands and writing files.
 *
 * Implementations are intentionally thin so they can be faked in tests.
 */
interface Connection
{
    /**
     * Run a command on the server and return its result.
     *
     * When an $onOutput callback is given it receives stdout chunks as they
     * stream in (used for verbose output).
     */
    public function run(string $command, ?callable $onOutput = null): CommandResult;

    /**
     * Write the given contents to a file on the server.
     *
     * When $sudo is true the file is written through `sudo tee`, allowing
     * privileged paths such as /etc/nginx to be written by a non-root user.
     */
    public function put(string $path, string $contents, bool $sudo = false): bool;

    /**
     * Close the underlying connection.
     */
    public function disconnect(): void;
}
