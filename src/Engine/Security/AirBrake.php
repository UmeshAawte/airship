<?php
declare(strict_types=1);
namespace Airship\Engine\Security;

use \Airship\Alerts\Security\SecurityAlert;
use \Airship\Engine\{
    Database,
    State
};
use \ParagonIE\Halite\Asymmetric\{
    Crypto as Asymmetric,
    EncryptionPublicKey
};

/**
 * Class AirBrake
 *
 * Progressive rate-limiting
 *
 * @package Airship\Engine\Security
 */
class AirBrake
{
    const ACTION_LOGIN = 'login';
    const ACTION_RECOVER = 'recovery';

    /**
     * @var Database
     */
    protected $db;

    /**
     * @var array
     */
    protected $config;

    /**
     * AirBrake constructor.
     * @param Database|null $db
     * @param array $config
     */
    public function __construct(Database $db = null, array $config = [])
    {
        if (!$db) {
            $db = \Airship\get_database();
        }
        if (empty($config)) {
            $state = State::instance();
            $config = $state->universal['rate-limiting'];
        }
        $this->db = $db;
        $this->config = $config;
    }

    /**
     * Returns true if we must fail fast and return an error message.
     *
     * @param string $username
     * @param string $ip
     * @param string $action
     * @return bool
     */
    public function failFast(
        string $username,
        string $ip,
        string $action = self::ACTION_LOGIN
    ): bool {
        if (!$this->getFastExit()) {
            return false;
        }
        // Get the current time and the anticipated delay.
        $date = new \DateTime('NOW');
        $delay = $this->getDelay($username, $ip, $action);
        if ($delay === 0) {
            return false;
        }
        $delay = \intdiv($delay, 1000);

        // Returns TRUE if the user hasn't waited long enough.
        return $this->db->exists(
            'SELECT
                 count(*)
             FROM
                 airship_failed_logins
             WHERE
                 action = ?
                 AND (
                        username = ?
                     OR subnet = ?
                 )
                 AND occurred > ?
             ',
            $action,
            $username,
            $this->getSubnet($ip),
            $date
                ->sub($this->getCutoff($delay))
                ->format(\AIRSHIP_DATE_FORMAT)
        );
    }

    /**
     * @return bool
     */
    public function getFastExit(): bool
    {
        return $this->config['fast-exit'];
    }

    /**
     * Get the number of recent account recovery attempts
     *
     * @param string $username
     * @param string $ip
     * @return int
     */
    public function getAccountRecoveryAttempts(
        string $username,
        string $ip
    ): int {
        return (int) $this->db->cell(
            'SELECT
                 count(*)
             FROM
                 airship_failed_logins
             WHERE
                 action = ?
                 AND (
                        username = ?
                     OR subnet = ?
                 )
                 AND occurred > ?
             ',
            self::ACTION_RECOVER,
            $username,
            $this->getSubnet($ip),
            (new \DateTime())
                ->sub($this->getCutoff(
                    (int) ($this->config['expire'] ?? 43200)
                ))
                ->format(\AIRSHIP_DATE_FORMAT)
        );
    }

    /**
     * Convert a number of seconds into the appropriate DateInterval
     *
     * @param int $expire
     * @return \DateInterval
     */
    public function getCutoff(int $expire): \DateInterval
    {
        $d1 = new \DateTime();
        $d2 = clone $d1;
        $d2->sub(new \DateInterval('PT' . $expire . 'S'));
        return $d2->diff($d1);
    }

    /**
     * Get the number of recent failed login attempts
     *
     * @param string $username
     * @param string $ip
     * @return int
     */
    public function getFailedLoginAttempts(
        string $username,
        string $ip
    ): int {
        return (int) $this->db->cell(
            'SELECT
                 count(*)
             FROM
                 airship_failed_logins
             WHERE
                 action = ?
                 AND (
                        username = ?
                     OR subnet = ?
                 )
                 AND occurred > ?
             ',
            self::ACTION_LOGIN,
            $username,
            $this->getSubnet($ip),
            (new \DateTime())
                ->sub($this->getCutoff(
                    (int) ($this->config['expire'] ?? 43200)
                ))
                ->format(\AIRSHIP_DATE_FORMAT)
        );
    }

    /**
     * Get the throttling delay (in milliseconds)
     *
     * @param string $username
     * @param string $ip
     * @param string $action
     * @return int
     */
    public function getDelay(
        string $username,
        string $ip,
        string $action = self::ACTION_LOGIN
    ): int {
        $attempts = (int) $this->db->cell(
            'SELECT
                 count(*)
             FROM
                 airship_failed_logins
             WHERE
                 action = ?
                 AND (
                        username = ?
                     OR subnet = ?
                 )
                 AND occurred > ?
             ',
            $action,
            $username,
            $this->getSubnet($ip),
            (new \DateTime())
                ->sub($this->getCutoff(
                    (int) ($this->config['expire'] ?? 43200)
                ))
                ->format(\AIRSHIP_DATE_FORMAT)
        );
        if ($attempts === 0) {
            return 0;
        }

        $max = (int) ($this->config['max-delay'] ?? 30);
        $value = (float) ($this->config['first-delay'] ?? 0.250);
        if ($attempts > (8 * PHP_INT_SIZE - 1))  {
            // Don't ever overflow. Just assume the max time:s
            $value = $max;
        } else {
            $value *= 2 ** $attempts;
            if ($value > $max) {
                $value = $max;
            }
        }
        return (int) \ceil($value * 1000);
    }

    /**
     * Return the given subnet for an IPv4 address and mask bits
     *
     * @param string $ip
     * @param int $maskBits
     * @return string
     */
    public function getIPv4Subnet(string $ip, int $maskBits = 32): string
    {
        $binary = \inet_pton($ip);
        for ($i = 32; $i > $maskBits; $i -= 8) {
            $j = \intdiv($i, 8) - 1;
            $k = (int) \min(8, $i - $maskBits);
            $mask = (0xff - ((2 ** $k) - 1));
            $int = \unpack('C', $binary[$j]);
            $binary[$j] = \pack('C', $int[1] & $mask);
        }
        return \inet_ntop($binary).'/'.$maskBits;
    }

    /**
     * Return the given subnet for an IPv6 address and mask bits
     *
     * @param string $ip
     * @param int $maskBits
     * @return string
     */
    public function getIPv6Subnet(string $ip, int $maskBits = 48): string
    {
        $binary = \inet_pton($ip);
        for ($i = 128; $i > $maskBits; $i -= 8) {
            $j = \intdiv($i, 8) - 1;
            $k = (int) \min(8, $i - $maskBits);
            $mask = (0xff - ((2 ** $k) - 1));
            $int = \unpack('C', $binary[$j]);
            $binary[$j] = \pack('C', $int[1] & $mask);
        }
        return \inet_ntop($binary).'/'.$maskBits;
    }

    /**
     * Get the EncryptionPublicKey used for encrypting password guesses
     * to give admins insight into the type of attack being launched.
     *
     * @param string $publicKey    Hex-encoded public key
     * @return EncryptionPublicKey
     * @throws SecurityAlert
     */
    public function getLogPublicKey(string $publicKey = ''): EncryptionPublicKey
    {
        if (!$publicKey) {
            $publicKey = $this->config['log-public-key'] ?? null;
            if (!$publicKey) {
                throw new SecurityAlert(
                    'Encryption public key not configured'
                );
            }
        }

        return new EncryptionPublicKey(
            \Sodium\hex2bin($publicKey)
        );
    }

    /**
     * Return the given subnet for an IP and the configured mask bits
     *
     * Determine if the IP is an IPv4 or IPv6 address, then pass to the correct
     * method for handling that specific type.
     *
     * @param string $ip
     * @return string
     */
    public function getSubnet(string $ip): string
    {
        if (\preg_match('/^[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}$/', $ip)) {
            return $this->getIPv4Subnet(
                $ip,
                (int) ($this->config['ipv4-subnet'] ?? 32)
            );
        }
        return $this->getIPv6Subnet(
            $ip,
            (int) ($this->config['ipv6-subnet'] ?? 128)
        );
    }

    /**
     * Register a failed login attempt
     *
     * @param string $username
     * @param string $ip
     * @param int $numFailures
     * @param HiddenString|null $password
     * @return bool
     */
    public function registerLoginFailure(
        string $username,
        string $ip,
        int $numFailures = 0,
        HiddenString $password = null
    ): bool {
        $logAfter = $this->config['log-after'] ?? null;
        if (!\is_null($logAfter)) {
            $logAfter = (int) $logAfter;
        }
        $publicKey = (string) ($this->config['log-public-key'] ?? '');

        $this->db->beginTransaction();
        $inserts = [
            'action' => self::ACTION_LOGIN,
            'occurred' => (new \DateTime())->format(\AIRSHIP_DATE_FORMAT),
            'username' => $username,
            'ipaddress' => $ip,
            'subnet' => $this->getSubnet($ip)
        ];

        if (\is_int($logAfter) && !empty($publicKey)) {
            if ($numFailures >= $logAfter) {
                // Encrypt the password guess with the admin's public key
                $inserts['sealed_password'] = Asymmetric::seal(
                    $password->getString(),
                    $this->getLogPublicKey($publicKey)
                );
            }
        }
        $this->db->insert(
            'airship_failed_logins',
            $inserts
        );

        return $this->db->commit();
    }

    /**
     * Register account recovery attempt
     *
     * @param string $username
     * @param string $ip
     * @return bool
     */
    public function registerAccountRecoveryAttempt(
        string $username,
        string $ip
    ): bool {
        $this->db->beginTransaction();
        $this->db->insert(
            'airship_failed_logins',
            [
                'action' => self::ACTION_RECOVER,
                'occurred' => (new \DateTime())->format(\AIRSHIP_DATE_FORMAT),
                'username' => $username,
                'ipaddress' => $ip,
                'subnet' => $this->getSubnet($ip)
            ]
        );

        return $this->db->commit();
    }
}
