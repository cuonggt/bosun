<?php

namespace Cuonggt\Bosun;

/**
 * An immutable description of a server: how to connect to it and what to
 * provision on it. Pure data — it has no knowledge of how SSH works.
 */
class Server
{
    public function __construct(
        public readonly string $name,
        public readonly string $host,
        public readonly int $port,
        public readonly string $username,
        public readonly ?string $privateKeyPath = null,
        public readonly ?string $privateKey = null,
        public readonly ?string $passphrase = null,
        public readonly ?string $password = null,
        public readonly string $phpVersion = '8.3',
        public readonly string $nodeVersion = '20',
    ) {
    }

    /**
     * Build a server from a config array (config/bosun.php "servers" entry).
     */
    public static function fromConfig(string $name, array $config): self
    {
        if (empty($config['host'])) {
            throw new \InvalidArgumentException(
                "Server [{$name}] is missing a host. Set DEPLOY_HOST or configure it in config/bosun.php."
            );
        }

        return new self(
            name: $name,
            host: $config['host'],
            port: (int) ($config['port'] ?? 22),
            username: $config['username'] ?? 'deployer',
            privateKeyPath: $config['key'] ?? null,
            privateKey: $config['private_key'] ?? null,
            passphrase: $config['passphrase'] ?? null,
            password: $config['password'] ?? null,
            phpVersion: (string) ($config['php'] ?? '8.3'),
            nodeVersion: (string) ($config['node'] ?? '20'),
        );
    }

    /**
     * Return a copy of the server that connects as a different user. Used by
     * the setup command to connect as root while still creating the deploy user.
     */
    public function connectAs(string $username): self
    {
        return new self(
            name: $this->name,
            host: $this->host,
            port: $this->port,
            username: $username,
            privateKeyPath: $this->privateKeyPath,
            privateKey: $this->privateKey,
            passphrase: $this->passphrase,
            password: $this->password,
            phpVersion: $this->phpVersion,
            nodeVersion: $this->nodeVersion,
        );
    }
}
