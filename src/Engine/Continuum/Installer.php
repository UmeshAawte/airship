<?php
declare(strict_types=1);
namespace Airship\Engine\Continuum;

use \Airship\Alerts\{
    FileSystem\FileNotFound,
    Hail\NoAPIResponse,
    Hail\SignatureFailed
};
use \Airship\Engine\{
    Bolt\Log as LogBolt,
    Bolt\Supplier as SupplierBolt,
    Continuum\Installers\InstallFile,
    Hail,
    State
};
use \GuzzleHttp\Exception\TransferException;
use \ParagonIE\Halite\{
    File,
    Util
};
use \Psr\Log\LogLevel;

/**
 * Class Installer
 *
 * This facilitates the installation process of a new Cabin, Gadget, or Motif.
 *
 * @package Airship\Engine\Continuum
 */
abstract class Installer
{
    use SupplierBolt;
    use LogBolt;

    /**
     * @var bool
     */
    protected $bypassSecurityAndJustInstall = false;

    /**
     * @var Channel[]
     */
    private static $channels;

    /**
     * @var Log
     */
    protected static $continuumLogger;

    /**
     * @var
     */
    protected $ext = 'txt';

    /**
     * @var Hail
     */
    protected $hail;

    /**
     * @var InstallFile
     */
    protected $localInstallFile;

    /**
     * @var Supplier
     */
    protected $supplier;

    /**
     * @var string
     */
    protected $type;

    /**
     * @var string
     */
    protected $package;

    /**
     * Installer constructor.
     *
     * @param Hail|null $hail
     * @param string $supplier
     * @param string $package
     */
    public function __construct(Hail $hail = null, string $supplier = '', string $package = '')
    {
        $config = State::instance();
        if (empty($hail)) {
            $this->hail = $config->hail;
        } else {
            $this->hail = $hail;
        }
        $this->supplier = $this->getSupplierDontCache($supplier);
        $this->package = $package;
        if (!self::$continuumLogger) {
            self::$continuumLogger = new Log();
        }
    }

    /**
     * This is for manual installations and update scripts. It should
     * never be invoked automatically.
     *
     * @param bool $set
     * @return Installer
     */
    public function bypassSecurityAndJustInstall(bool $set = false): self
    {
        if ($set) {
            $this->log('Disabling the security verification', LogLevel::WARNING);
        }
        $this->bypassSecurityAndJustInstall = $set;
        return $this;
    }

    /**
     * We just need to clear the template caches and the cabin data.
     *
     * @return bool
     */
    public function clearCache(): bool
    {
        \Airship\clear_cache();
        $dirs = [
            'csp_hash',
            'csp_static',
            'html_purifier',
            'markdown',
            'rst',
            'static',
            'twig'
        ];
        foreach ($dirs as $dir) {
            if (!\is_dir(ROOT . '/tmp/cache/' . $dir)) {
                continue;
            }
            foreach (\Airship\list_all_files(ROOT . '/tmp/cache/' . $dir) as $file) {
                if ($file === ROOT . '/tmp/cache/' . $dir . '/.gitignore') {
                    continue;
                }
                \unlink($file);
            }
        }
        if (\file_exists(ROOT . '/tmp/cache/cabin_data.json')) {
            \unlink(ROOT . '/tmp/cache/cabin_data.json');
        }
        \clearstatcache();
        return true;
    }

    /**
     * Download the file from the update server.
     *
     * @param array $update
     * @return InstallFile
     * @throws TransferException
     */
    public function download(array $update = []): InstallFile
    {
        if ($this->localInstallFile instanceof InstallFile) {
            return $this->localInstallFile;
        }
        // If this was not supplied, we need to get it.
        if (empty($update)) {
            $update = $this->getPackageData();
        }

        $supplierName = $this->supplier->getName();
        \uasort($update['versions'], function(array $a, array $b) {
            return $a['version'] <=> $b['version'];
        });
        $data = \array_pop($update['versions']);
        $version = $data['version'];

        $body = $this->hail->postReturnBody(
            $update['channel'] . API::get('download'),
            [
                'type' => \get_class($this),
                'supplier' => $supplierName,
                'package' => $this->package,
                'version' => $version
            ]
        );
        $outFile = \Airship\tempnam('airship-', $this->ext);
        $saved = \file_put_contents($outFile, $body);

        if ($saved === false) {
            throw new TransferException();
        }
        // To prevent TOCTOU issues down the line
        $hash = Util::hash($body);
        $body = null;
        \clearstatcache();
        return new InstallFile(
            $this->supplier,
            [
                'data' => $data,
                'hash' => $hash,
                'path' => $outFile,
                'size' => \filesize($outFile),
                'type' => $this->type,
                'version' => $version
            ]
        );
    }

    /**
     * Attempts to download and install in one go.
     *
     * @return bool Was it successful?
     */
    public function easyInstall(): bool
    {
        try {
            $install = $this->download();
            if (!$this->verifySignature($install)) {
                self::$continuumLogger->store(
                    LogLevel::ALERT,
                    'Install failed -- invalid signature',
                    $this->getLogContext($install)
                );
                return false;
            }
            if (!$this->verifyMerkleRoot($install)) {
                self::$continuumLogger->store(
                    LogLevel::ALERT,
                    'Install failed -- Merkle root mismatch',
                    $this->getLogContext($install)
                );
                return false;
            }
            if (!$this->verifyChecksum($install)) {
                return false;
            }
            if (!$this->install($install)) {
                self::$continuumLogger->store(
                    LogLevel::ALERT,
                    'Install failed -- checksum not registered in Keyggdrasil',
                    $this->getLogContext($install)
                );
                return false;
            }
            // Clear the cache, since we just installed something.
            $this->markPackageInstalled($install);
            $this->clearCache();
            return true;
        } catch (\Throwable $ex) {
            $this->log(
                $ex->getMessage(),
                LogLevel::ERROR,
                [
                    'exception_type' =>
                        \get_class($ex),
                    'exception' =>
                        \Airship\throwableToArray($ex)
                ]
            );
        }
        // Easy mode just returns false.
        return false;
    }

    /**
     * Is this a special Airship cabin? Return just the name.
     * Otherwise, return the namespace.
     *
     * @param string $cabinName
     * @return string
     */
    public function expandCabinName(string $cabinName): string
    {
        if (\strpos($cabinName, '/') !== false) {
            list ($supplier, $package) = \explode('/', $cabinName);
            if (\strtolower($supplier) === 'paragonie') {
                switch (\strtolower($package)) {
                    case 'hull':
                    case 'bridge':
                        return \ucfirst($package);
                }
            }
        } else {
            switch (\strtolower($cabinName)) {
                case 'hull':
                case 'bridge':
                    return \ucfirst($cabinName);
            }
        }
        return \trim(
            \preg_replace('/[^A-Za-z0-9\_]/', '_', $cabinName),
            '_'
        );
    }

    /**
     * Get information for logging purposes
     *
     * @param InstallFile $installFile
     * @return array
     */
    public function getLogContext(InstallFile $installFile): array
    {
        return [
            'action' => 'INSTALL',
            'name' => $this->name,
            'supplier' => $this->supplier->getName(),
            'type' => $this->type,
            'installFile' => [
                'checksum' => $installFile->getHash(),
                'filesize' => $installFile->getSize(),
                'merkleRoot' => $installFile->getMerkleRoot(),
                'signature' => $installFile->getSignature(),
                'version' => $installFile->getVersion()
            ]
        ];
    }

    /**
     * Get metadata about the package we're installing.
     *
     * @param string $minVersion
     * @return array
     * @throws NoAPIResponse
     */
    public function getPackageData(string $minVersion = ''): array
    {
        $channelsConfigured = $this->supplier->getChannels();
        if (empty($channelsConfigured)) {
            throw new NoAPIResponse(
                \trk('errors.hail.no_channel_configured', $this->supplier->getName())
            );
        }

        /**
         * HTTP POST arguments
         */
        $args = [
            'type' =>
                $this->type,
            'supplier' =>
                $this->supplier->getName(),
            'package' =>
                $this->package
        ];
        if (!empty($minVersion)) {
            $args['minimum'] = $minVersion;
        }

        /**
         * Let's try each of the channels this supplier
         * belongs to. This should in most cases only
         * run once.
         */
        foreach ($channelsConfigured as $channel) {
            $chan = $this->getChannel($channel);
            $publicKey = $chan->getPublicKey();

            // Iterate through all the available Channel URLs.
            // If the channel supports Tor, and we have tor-only
            // mode enabled, it will prioritize those URLs.
            foreach ($chan->getAllURLs() as $ch) {
                try {
                    $result = $this->hail->postSignedJSON($ch . API::get('version'), $publicKey, $args);
                    // Add the channel to this data...
                    $result['channel'] = $ch;
                    $result['minimum'] = (string) ($minVersion ?? '0.0.0');
                    return $result;
                } catch (TransferException $ex) {
                    $this->log(
                        'This channel URL did not respond.',
                        LogLevel::INFO,
                        [
                            'channel' =>
                                $channel,
                            'url' =>
                                $ch,
                            'exception' =>
                                \Airship\throwableToArray($ex)
                        ]
                    );
                } catch (SignatureFailed $ex) {
                    $this->log(
                        'Channel signature validation failed',
                        LogLevel::WARNING,
                        [
                            'channel' =>
                                $channel,
                            'url' =>
                                $ch,
                            'exception' =>
                                \Airship\throwableToArray($ex)
                        ]
                    );
                }
                // If we didn't return a result, we'll continue onto the next URL
            }
        }
        throw new NoAPIResponse(
            \trk('errors.hail.no_channel_responded')
        );
    }

    /**
     * Get the channels (cache across all instances of Installer)
     *
     * @param string $name
     * @return Channel
     * @throws NoAPIResponse
     */
    protected function getChannel(string $name): Channel
    {
        if (empty(self::$channels)) {
            $config = \Airship\loadJSON(ROOT . '/config/channels.json');
            foreach ($config as $chName => $chConfig) {
                self::$channels[$chName] = new Channel($this, $chName, $chConfig);
            }
        }
        if (isset(self::$channels[$name])) {
            return self::$channels[$name];
        }
        throw new NoAPIResponse(
            \trk('errors.hail.no_channel_configured', '')
        );
    }

    /**
     * Install the file. This is type-specific, so we leave it abstract here.
     *
     * @param InstallFile $fileInfo
     * @return bool
     */
    abstract public function install(InstallFile $fileInfo): bool;

    /**
     * Somewhat self-explanatory. This just sets the 'installed' column in the
     * database to 'TRUE' so our web UI knows that it's installed.
     *
     * @param InstallFile $install
     * @return bool
     */
    public function markPackageInstalled(InstallFile $install): bool
    {
        $db = \Airship\get_database();
        $db->beginTransaction();

        $db->update(
            'airship_package_cache',
            [
                'installed' => true,
                'current_version' => $install->getVersion()
            ],
            [
                'packagetype' => $this->type,
                'supplier' => $this->supplier->getName(),
                'name' => $this->package
            ]
        );
        return $db->commit();
    }

    /**
     * For CLI usage: Bypass the download process, use a local file instead.
     *
     * @param string $path
     * @param string $version
     * @return Installer
     * @throws FileNotFound
     */
    public function useLocalInstallFile(
        string $path,
        string $version = ''
    ): self {
        if (\file_exists($path)) {
            throw new FileNotFound();
        }
        $hash = File::checksum($path);
        $this->localInstallFile = new InstallFile(
            $this->supplier,
            [
                'path' => $path,
                'version' => $version,
                'hash' => $hash,
                'size' => \filesize($path)
            ]
        );
        return $this;
    }

    /**
     * Verify that the signature matches
     *
     * @param InstallFile $file
     * @return bool
     */
    public static function verifySignature(InstallFile $file): bool
    {
        return $file->signatureIsValid(true);
    }

    /**
     * Verify that the file has not modified since it was stored
     *
     * @param InstallFile $file
     * @return bool
     */
    public static function verifyChecksum(InstallFile $file): bool
    {
        return $file->hashMatches(File::checksum($file->getPath()));
    }

    /**
     * Verifies that the Merkle root exists, matches this package and version,
     * and has the same checksum as the one we calculated.
     *
     * @param InstallFile $file
     * @return bool
     */
    public function verifyMerkleRoot(InstallFile $file): bool
    {
        $debugArgs = [
            'supplier' =>
                $this->supplier->getName(),
            'name' =>
                $this->package
        ];
        $db = \Airship\get_database();
        $merkle = $db->row(
            'SELECT * FROM airship_tree_updates WHERE merkleroot = ?',
            $file->getMerkleRoot()
        );
        if (empty($merkle)) {
            $this->log('Merkle root not found in tree', LogLevel::DEBUG, $debugArgs);
            // Not found in Keyggdrasil
            return false;
        }
        $data = \Airship\parseJSON($merkle['data'], true);
        $instType = \strtolower($this->type);
        $keyggdrasilType = \strtolower($data['pkg_type']);
        if (!\hash_equals($instType, $keyggdrasilType)) {
            $this->log('Wrong package type', LogLevel::DEBUG, $debugArgs);
            // Wrong package type
            return false;
        }
        if (!\hash_equals($this->supplier->getName(), $data['supplier'])) {
            $this->log('Wrong supplier', LogLevel::DEBUG, $debugArgs);
            // Wrong supplier
            return false;
        }
        if (!\hash_equals($this->package, $data['name'])) {
            $this->log('Wrong package', LogLevel::DEBUG, $debugArgs);
            // Wrong package
            return false;
        }
        // Finally, we verify that the checksum matches the entry in our Merkle tree:
        return \hash_equals($file->getHash(), $data['checksum']);
    }
}
