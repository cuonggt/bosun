<?php

namespace Cuonggt\Bosun\Ssh;

use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SSH2;
use Throwable;
use Cuonggt\Bosun\Server;

/**
 * An SSH connection backed by phpseclib. Pure PHP — no native `ssh` binary or
 * shell escaping of the remote command through a local process is required.
 */
class PhpseclibConnection implements Connection
{
    protected ?SSH2 $ssh = null;

    public function __construct(protected Server $server)
    {
    }

    public function run(string $command, ?callable $onOutput = null): CommandResult
    {
        $ssh = $this->connect();

        $stdout = '';

        $callback = $onOutput === null
            ? null
            : function (string $chunk) use (&$stdout, $onOutput): void {
                $stdout .= $chunk;
                $onOutput($chunk);
            };

        $result = $ssh->exec($command, $callback);

        // With a streaming callback exec() returns true; otherwise the output.
        if ($callback === null) {
            $stdout = is_string($result) ? $result : '';
        }

        return new CommandResult(
            command: $command,
            output: rtrim($stdout),
            errorOutput: rtrim($ssh->getStdError()),
            exitCode: ($code = $ssh->getExitStatus()) === false ? 0 : $code,
        );
    }

    public function put(string $path, string $contents, bool $sudo = false): bool
    {
        // base64 round-trips arbitrary content safely through a single exec()
        // without worrying about quoting newlines, quotes or shell tokens.
        $sink = $sudo
            ? 'sudo tee '.escapeshellarg($path).' > /dev/null'
            : 'cat > '.escapeshellarg($path);

        $command = sprintf(
            'echo %s | base64 -d | %s',
            escapeshellarg(base64_encode($contents)),
            $sink,
        );

        return $this->run($command)->successful();
    }

    public function disconnect(): void
    {
        $this->ssh?->disconnect();
        $this->ssh = null;
    }

    /**
     * Lazily establish and authenticate the connection.
     */
    protected function connect(): SSH2
    {
        if ($this->ssh !== null) {
            return $this->ssh;
        }

        try {
            $ssh = new SSH2($this->server->host, $this->server->port);
            $ssh->setTimeout(0); // long-running commands (apt, composer) must not time out

            if (! $ssh->login($this->server->username, $this->credentials())) {
                throw new ConnectionException(
                    "Authentication failed for {$this->server->username}@{$this->server->host}."
                );
            }

            $ssh->enableQuietMode(); // keep stderr out of stdout; read it via getStdError()
        } catch (ConnectionException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new ConnectionException(
                "Unable to connect to {$this->server->host}:{$this->server->port} — {$e->getMessage()}",
                previous: $e,
            );
        }

        return $this->ssh = $ssh;
    }

    /**
     * Resolve the credentials phpseclib should authenticate with: a loaded
     * private key when one is available, otherwise a password.
     */
    protected function credentials(): mixed
    {
        if ($this->server->privateKey) {
            return PublicKeyLoader::load($this->server->privateKey, $this->server->passphrase ?: false);
        }

        if ($this->server->privateKeyPath) {
            $path = $this->expandHome($this->server->privateKeyPath);

            if (is_file($path)) {
                return PublicKeyLoader::load(file_get_contents($path), $this->server->passphrase ?: false);
            }
        }

        if ($this->server->password !== null && $this->server->password !== '') {
            return $this->server->password;
        }

        throw new ConnectionException(
            "No usable SSH credentials for server [{$this->server->name}]. ".
            'Provide a readable private key or a password.'
        );
    }

    /**
     * Expand a leading ~ in a path to the current user's home directory.
     */
    protected function expandHome(string $path): string
    {
        if (str_starts_with($path, '~')) {
            $home = $_SERVER['HOME'] ?? getenv('HOME') ?: '';

            return $home.substr($path, 1);
        }

        return $path;
    }
}
