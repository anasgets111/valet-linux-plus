<?php

namespace Valet\PackageManagers;

use ConsoleComponents\Writer;
use DomainException;
use Valet\CommandLine;
use Valet\Contracts\PackageManager;
use Valet\Contracts\ServiceManager;

class PackageKit implements PackageManager
{
    /**
     * @var CommandLine
     */
    public $cli;
    /**
     * @var ServiceManager
     */
    public $serviceManager;
    /**
     * @var array
     */
    public const PHP_FPM_PATTERN_BY_VERSION = [];

    private const PACKAGES = [
        'redis' => 'redis-server',
        'mysql' => 'mysql-server',
        'mariadb' => 'mariadb-server',
    ];

    /**
     * Create a new Apt instance.
     */
    public function __construct(CommandLine $cli, ServiceManager $serviceManager)
    {
        $this->cli = $cli;
        $this->serviceManager = $serviceManager;
    }

    /**
     * Get array of installed packages.
     */
    public function packages(string $package): array
    {
        $query = "pkcon search {$package} | grep '^In' | sed 's/\s\+/ /g' | cut -d' ' -f2 | sed 's/-[0-9].*//'";

        return explode(PHP_EOL, $this->cli->run($query));
    }

    /**
     * Determine if the given package is installed.
     */
    public function installed(string $package): bool
    {
        return in_array($package, $this->packages($package));
    }

    /**
     * Ensure that the given package is installed.
     */
    public function ensureInstalled(string $package): void
    {
        if (!$this->installed($package)) {
            $this->installOrFail($package);
        }
    }

    /**
     * Install the given package and throw an exception on failure.
     */
    public function installOrFail(string $package): void
    {
        Writer::twoColumnDetail($package, 'Installing');

        $this->cli->run(trim('pkcon install -y '.$package), function ($exitCode, $errorOutput) use ($package) {
            Writer::error(\sprintf('%s: %s', $exitCode, $errorOutput));

            throw new DomainException('PackageKit was unable to install ['.$package.'].');
        });
    }

    /**
     * Configure package manager on valet install.
     */
    public function setup(): void
    {
        // Nothing to do
    }

    /**
     * Determine if package manager is available on the system.
     */
    public function isAvailable(): bool
    {
        try {
            $output = $this->cli->run('which pkcon', function () {
                throw new DomainException('PackageKit not available');
            });

            return $output != '';
        } catch (DomainException $e) {
            return false;
        }
    }

    /**
     * Determine php fpm package name.
     */
    public function getPhpFpmName(string $version): string
    {
        $pattern = !empty(self::PHP_FPM_PATTERN_BY_VERSION[$version])
            ? self::PHP_FPM_PATTERN_BY_VERSION[$version] : 'php{VERSION}-fpm';

        return str_replace('{VERSION}', $version, $pattern);
    }

    /**
     * Get the `ca-certificates` directory
     */
    public function getCaCertificatesPath(): string
    {
        return '/usr/share/pki/ca-trust-source';
    }

    /**
     * Determine php extension pattern.
     */
    public function getPhpExtensionPrefix(string $version): string
    {
        $pattern = 'php{VERSION}-';
        return str_replace('{VERSION}', $version, $pattern);
    }

    /**
     * Restart dnsmasq in Ubuntu.
     */
    public function restartNetworkManager(): void
    {
        $this->serviceManager->restart(['network-manager']);

        $version = trim($this->cli->run('cat /etc/*release | grep DISTRIB_RELEASE | cut -d\= -f2'));

        if ($version === '17.04') {
            $this->serviceManager->enable('systemd-resolved');
            $this->serviceManager->restart('systemd-resolved');
        }
    }

    /**
     * Get package name by service.
     */
    public function packageName(string $name): string
    {
        if (isset(self::PACKAGES[$name])) {
            return self::PACKAGES[$name];
        }
        throw new \InvalidArgumentException(\sprintf('Package not found by %s', $name));
    }
}
