<?php
namespace Segura\Session;

use Predis\Client as RedisClient;

class SessionHandler implements \SessionHandlerInterface
{
    static private $dirtyCheck = [];

    private $oldID;

    /** @var RedisClient */
    private $redis;

    private $keyLifeTime = 86400;

    public function __construct(RedisClient $redis, $keyLifeTime = 86400)
    {
        $this->redis = $redis;
        $this->keyLifeTime = $keyLifeTime;
    }

    public function open($savePath, $sessionName)
    {
        return true;
    }

    public function close()
    {
        return true;
    }

    public function read($id)
    {
        // shall we regenerate
        if (!empty($this->oldID)) {
            $id = $this->oldID ? $this->oldID : $id;
        }
        $serialised = $this->redis->get("session_{$id}");
        if ($serialised != null) {
            if (!empty($this->oldID)) {
                // clean up old session after regenerate
                $this->redis->del("session_{$id}");
                $this->oldID = null;
            }
            $result = unserialize($serialised);
        } else {
            $result = '';
        }

        self::$dirtyCheck['read-' . $id] = crc32($result);

        return $result;
    }

    public function write($id, $data)
    {
        if (self::$dirtyCheck['read-' . $id] != crc32($data)) {
            $this->redis->set("session_{$id}", serialize($data));
            $this->redis->expire("session_{$id}", $this->keyLifeTime);
        }
        return true;
    }

    public function destroy($id)
    {
        // do not delete redis data on destroy, allow regeneration to read old session
        $this->oldID = $id;
        return true;
    }

    public function gc($maxlifetime)
    {
        return true;
    }
}
