<?php
declare(strict_types=1);
namespace Airship\Engine\Bolt;

use \Airship\Alerts\{
    Security\SecurityAlert,
    Security\LongTermAuthAlert,
    Security\UserNotLoggedIn
};
use \Airship\Engine\{
    AutoPilot,
    Gears,
    Landing,
    Security\Authentication,
    Security\Permissions,
    State
};
use \ParagonIE\Halite\Cookie;
use \Psr\Log\LogLevel;

/**
 * Bolt Security
 *
 * Common security features. Mostly access controls.
 *
 * @package Airship\Engine\Bolt
 */
trait Security
{
    /**
     * @var Authentication
     */
    public $airship_auth;
    /**
     * @var Cookie
     */
    public $airship_cookie;
    /**
     * @var Permissions
     */
    public $airship_perms;

    /**
     * After loading the Security bolt in place, configure it.
     */
    public function tightenSecurityBolt()
    {
        static $tightened = false;
        if ($tightened) {
            // This was already run once.
            return;
        }
        $state = State::instance();
        $db = isset($this->db)
            ? $this->db
            : \Airship\get_database();
        
        $this->airship_auth = Gears::get(
            'Authentication',
            $state->keyring['auth.password_key'],
            $db
        );
        
        $this->airship_cookie = new Cookie(
            $state->keyring['cookie.encrypt_key']
        );
        
        $this->airship_perms = Gears::get('Permissions', $db);
    }

    /**
     * Perform a permissions check
     *
     * @param string $action action label (e.g. 'read')
     * @param string $context context regex (in perm_contexts)
     * @param string $cabin (defaults to current cabin)
     * @param integer $userID (defaults to current user)
     * @return boolean
     */
    public function can(
        string $action,
        string $context = '',
        string $cabin = '',
        int $userID = 0
    ): bool {
        if (!($this->airship_perms instanceof Permissions)) {
            $this->tightenSecurityBolt();
        }
        return $this->airship_perms->can(
            $action,
            $context,
            $cabin,
            $userID
        );
    }

    /**
     * Get the current user ID. Throws a UserNotLoggedIn exception if you aren't logged in.
     *
     * @return int
     * @throws UserNotLoggedIn
     */
    public function getActiveUserId(): int
    {
        if (empty($_SESSION['userid'])) {
            throw new UserNotLoggedIn(
                \trk('errors.security.not_authenticated')
            );
        }
        return (int) $_SESSION['userid'];
    }


    /**
     * Are we currently logged in as an admin?
     * @param integer $user_id (defaults to current user)
     * @return boolean
     */
    public function isSuperUser(int $user_id = 0) {
        if (!($this->airship_perms instanceof Permissions)) {
            $this->tightenSecurityBolt();
        }
        if (empty($user_id)) {
            try {
                $user_id = $this->getActiveUserId();
            } catch (SecurityAlert $e) {
                return false;
            }
        }
        return $this->airship_perms->isSuperUser($user_id);
    }
    
    /**
     * Are we logged in to a user account?
     * 
     * @return boolean
     */
    public function isLoggedIn()
    {
        if (!($this->airship_cookie instanceof Cookie)) {
            $this->tightenSecurityBolt();
        }
        if (!empty($_SESSION['userid'])) {
            // We're logged in!
            if ($this instanceof Landing && $this->config('password-reset.logout')) {
                return $this->verifySessionCanary($_SESSION['userid']);
            }
            return true;
        } elseif (isset($_COOKIE['airship_token'])) {
            // We're not logged in, but we have a long-term
            // authentication token, so we should do an automatic
            // login and, if successful, respond affirmatively.
            $token = $this->airship_cookie->fetch('airship_token');
            if (!empty($token)) {
                return $this->doAutoLogin($token, 'userid', 'airship_token');
            }
        }
        return false;
    }

    /**
     * Let's do an automatic login
     *
     * @param string $token
     * @param string $uid_idx
     * @param string $token_idx
     * @return bool
     */
    protected function doAutoLogin(
        string $token,
        string $uid_idx,
        string $token_idx
    ): bool {
        if (
            !($this->airship_cookie instanceof Cookie)
                ||
            !($this->airship_auth instanceof Authentication)
        ) {
            $this->tightenSecurityBolt();
        }
        $state = State::instance();
        try {
            $userId = $this->airship_auth->loginByToken($token);
            // Regenerate session ID:
            \session_regenerate_id(true);

            // Set session variable
            $_SESSION[$uid_idx] = $userId;

            $autoPilot = Gears::getName('AutoPilot');
            if (IDE_HACKS) {
                $autoPilot = new AutoPilot();
            }
            $httpsOnly = (bool) $autoPilot::isHTTPSConnection();

            // Rotate the authentication token:
            $this->airship_cookie->store(
                $token_idx,
                $this->airship_auth->rotateToken($token, $userId),
                \time() + ($state->universal['long-term-auth-expire'] ?? self::DEFAULT_LONGTERMAUTH_EXPIRE),
                '/',
                '',
                $httpsOnly ?? false,
                true
            );
            return true;
        } catch (LongTermAuthAlert $e) {
            // Let's wipe our long-term authentication cookies
            $this->airship_cookie->store($token_idx, null);

            // Let's log this incident
            if (\property_exists($this, 'log')) {
                $this->log(
                    $e->getMessage(),
                    LogLevel::CRITICAL,
                    [
                        'exception' => \Airship\throwableToArray($e)
                    ]
                );
            } else {
                $state = State::instance();
                $state->logger->log(
                    LogLevel::CRITICAL,
                    $e->getMessage(),
                    [
                        'exception' => \Airship\throwableToArray($e)
                    ]
                );
            }
        }
        return false;
    }

    /**
     * Completely wipe all authentication mechanisms (Session, Cookie)
     *
     * @return bool
     */
    public function completeLogOut(): bool
    {
        if (!($this->airship_cookie instanceof Cookie)) {
            $this->tightenSecurityBolt();
        }
        $state = State::instance();
        $_SESSION = [];
        $this->airship_cookie->store('airship_token', null);
        return \session_regenerate_id(true);
    }

    /**
     * If another session triggered a password reset, we should be logged out
     * as per the Bridge configuration. (This /is/ an optional feature.)
     *
     * @param int $userID
     * @return bool
     */
    public function verifySessionCanary(int $userID): bool
    {
        if (empty($_SESSION['session_canary'])) {
            return false;
        }
        $db = \Airship\get_database();
        $canary = $db->cell(
            'SELECT session_canary FROM airship_users WHERE userid = ?',
            $userID
        );
        if (empty($canary)) {
            $this->log(
                'What is this even.',
                LogLevel::DEBUG,
                [
                    'database' => $canary,
                    'session' => $_SESSION['session_canary']
                ]
            );
            $this->completeLogOut();
            return false;
        }
        if (!\hash_equals($canary, $_SESSION['session_canary'])) {
            $this->log(
                'User was logged out for having the wrong canary.',
                LogLevel::DEBUG,
                [
                    'expected' => $canary,
                    'possessed' => $_SESSION['session_canary']
                ]
            );
            $this->completeLogOut();
            return false;
        }
        return true;
    }
}
