<?php
namespace Gone\Session;

use Predis\Client as RedisClient;

class SessionHandler implements \SessionHandlerInterface
{
    static private $dirtyCheck = [];

    private $oldID;

    /** @var RedisClient */
    private $redis;

    private $keyLifeTime = 86400;

    private function useAPCU() : bool
    {
        return function_exists('apcu_store');
    }

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

    public function clearLocalCache()
    {
        if($this->useAPCU()) {
            apcu_clear_cache();
        }else{
            self::$dirtyCheck = [];
        }
    }

    public function read($id)
    {
        if($this->useAPCU()){
            if(apcu_exists('read-' . $id)){
                return apcu_fetch('read-' . $id);
            }
        }
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

        if($this->useAPCU()) {
            apcu_store('read-' . $id, $result, 30);
        }else{
            self::$dirtyCheck['read-' . $id] = crc32($result);
        }

        return $result;
    }

    public function write($id, $data)
    {
        $dirty = false;
        if($this->useAPCU()){
            $dirty = crc32(apcu_fetch('read-' . $id)) != crc32($data);
        }else{
            $dirty = self::$dirtyCheck['read-' . $id] != crc32($data);
        }
        if ($dirty) {
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
