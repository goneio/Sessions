<?php
namespace Segura\Session;

use Predis\Client as RedisClient;

class Session
{
    private static $_instance;
    private static $_session_handler;
    const lifetime = 43200;
    const REGENERATE_TIME = 5;
    const ALERT_SUCCESS = 'success';
    const ALERT_INFO = 'info';
    const ALERT_WARNING = 'warning';
    const ALERT_DANGER = 'danger';

    private $redis;

    public function __construct(RedisClient $redis)
    {
        $this->redis = $redis;
        self::$_session_handler = new SessionHandler($redis, Session::lifetime);

        // server should keep session data for AT LEAST 1 day
        ini_set('session.gc_maxlifetime', Session::lifetime);

        // each client should remember their session id for EXACTLY 1 day
        session_set_cookie_params(Session::lifetime);
        session_set_save_handler(self::$_session_handler, true);

        // Prevent session from influencing the slim headers sent back to the browser.
        session_cache_limiter(null);

        // Begin the Session
        session_start();
    }

    public static function getSessionHandler() : SessionHandler
    {
        return self::$_session_handler;
    }

    public static function start(\Predis\Client $redis)
    {
        self::$_instance = new Session($redis);
        return self::$_instance;
    }

    /**
     * @return Session
     */
    public static function get_session()
    {
        return self::$_instance;
    }

    public static function regenerate()
    {
        return self::get_session()->_regenerate();
    }

    public static function isSet($key)
    {
        return isset($_SESSION[$key]);
    }

    public static function getAll()
    {
        return Session::get_session()->_getAll();
    }

    public static function get($key)
    {
        return Session::get_session() ? Session::get_session()->_get($key) : false;
    }

    public static function set($key, $value)
    {
        return Session::get_session()->_set($key, $value);
    }

    public static function dispose($key)
    {
        return Session::get_session()->_dispose($key);
    }

    public function _regenerate()
    {
        $ts = $this->_get('regenerationTimestamp');
        if (!$ts || $ts + self::REGENERATE_TIME < time()) {
            session_regenerate_id(true);
            $this->_set('regenerationTimestamp', time());
            return true;
        }
        return false;
    }

    public function _getAll()
    {
        $all = [];
        foreach ($_SESSION as $key => $value) {
            $all[$key] = unserialize($value);
        }
        ksort($all);
        return $all;
    }

    public function _get($key)
    {
        if (isset($_SESSION[$key])) {
            return unserialize($_SESSION[$key]);
        } else {
            return false;
        }
    }

    public function _set($key, $value)
    {
        $_SESSION[$key] = serialize($value);
        return true;
    }

    public function _dispose($key)
    {
        if (isset($_SESSION[$key])) {
            unset($_SESSION[$key]);
            return true;
        } else {
            return false;
        }
    }

    public function addAlert($alertType = self::ALERT_INFO, $message)
    {
        $err = $this->getAlerts();
        if (!is_array($err)) {
            $err = [];
        }
        $err[] = ['type' => $alertType, 'message' => $message];
        return $this->set("storedAlerts", $err);
    }

    public function getAlerts()
    {
        return $this->get("storedAlerts");
    }

    public function clearAlerts()
    {
        $this->set("storedAlerts", []);
    }
}
