<?php
declare(strict_types=1);
namespace Airship\Engine\Continuum\Updaters;

use \ParagonIE\Halite\File;

/**
 * Class UpdateFile
 *
 * Encapsulates an update file (.phar or .zip)
 *
 * @package Airship\Engine\Continuum
 */
class UpdateFile
{
    /**
     * @var string
     */
    protected $path;

    /**
     * @var int
     */
    protected $size;

    /**
     * @var string
     */
    protected $hash;

    /**
     * @var string
     */
    protected $version;

    /**
     * UpdateFile constructor.
     * @param array $data
     */
    public function __construct(array $data)
    {
        $this->path = $data['path'];
        $this->version = $data['version'];
        $this->size = (int) ($data['size'] ?? \filesize($data['path']));
        $this->hash = File::checksum($data['path']);
    }

    /**
     * Get the hex-encoded hash of the file contents
     *
     * @return string
     */
    public function getHash(): string
    {
        return $this->hash;
    }

    /**
     * Get the name of the file
     *
     * @return string
     */
    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * Get the size of the file
     *
     * @return int
     */
    public function getSize(): int
    {
        return $this->size;
    }

    /**
     * Does the given hash match the file?
     *
     * @param string $hash
     * @return bool
     */
    public function hashMatches(string $hash): bool
    {
        return \hash_equals($this->hash, $hash);
    }

    /**
     * Get the version of a particular update file.
     *
     * @return string
     */
    public function getVersion(): string
    {
        return $this->version;
    }
}
