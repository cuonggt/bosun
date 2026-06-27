<?php

namespace Cuonggt\Bosun\Ssh;

use RuntimeException;

/**
 * Thrown when a remote command exits with a non-zero status, carrying the
 * full result so the calling command can show the failure to the user.
 */
class RemoteTaskException extends RuntimeException
{
    public function __construct(public readonly CommandResult $result)
    {
        parent::__construct(sprintf(
            'Remote command failed (exit %d): %s',
            $result->exitCode,
            $result->command,
        ));
    }
}
