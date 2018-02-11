<?php
/**
 * Created by PhpStorm.
 * User: aa
 * Date: 2018/2/11
 * Time: 12:52
 */

namespace app\components;

use Yii;
use yii\base\Component;
use yii\base\InvalidConfigException;
use yii\helpers\FileHelper;
use yii\web\SessionIterator;
class Session extends Component implements \IteratorAggregate, \ArrayAccess, \Countable
{
    /* @description $savePath session存储目录，执行swoole的用户必须对目录有读和写的权限 */
    public $savePath = "/tmp/";

    public $name='yzb_';

    public $flashParam = '__flash';

    private $_started = false;      //session启动

    public $handler;

    public $timeout = null;

    private $_cookieParams = [
        'lifetime' => 1400,
        'path' => '/',
        'domain' => '',
        'secure' => false,
        'httponly' => true,
    ];

    private $_prefix = "yzb_";      //session id前缀

    public function init()
    {
        parent::init();
        if ($this->getIsActive()) {
            Yii::warning('Session is already started', __METHOD__);
            $this->updateFlashCounters();
        }
        if($this->timeout !== null) $this->_cookieParams['lifetime'] = $this->timeout;
    }

    /**
     * Returns a value indicating whether to use custom session storage.
     * This method should be overridden to return true by child classes that implement custom session storage.
     * To implement custom session storage, override these methods: [[openSession()]], [[closeSession()]],
     * [[readSession()]], [[writeSession()]], [[destroySession()]] and [[gcSession()]].
     * @return bool whether to use custom storage.
     */
    public function getUseCustomStorage()
    {
        return false;
    }

    public function getSessionFullName()
    {
        return $this->getSavePath() . $this->_prefix . $this->getId();
    }

    public function persist()
    {
        $this->open();
        $this->writeSession($this->getId(),\yii\helpers\Json::encode($_SESSION));
    }

    /**
     * swoole每隔设置的毫秒数执行此方法回收session
     */
    public function gcSession()
    {
        $handle = opendir( $this->getSavePath() );
        while (false !== ($file = readdir($handle)))
        {
            if ($file != "." && $file != ".." && (strpos($file, $this->_prefix) === 0) && is_file($this->getSavePath() . $file)) {
                if( strpos($file, $this->_prefix) !== 0 ) continue;
                $lastUpdatedAt = filemtime($this->getSavePath() . $file);
                if( time() - $lastUpdatedAt > $this->getCookieParams()['lifetime'] ){
                    unlink($this->getSavePath() . $file);
                }
            }
        }
    }

    public function open()
    {
        if ($this->getIsActive()) {
            return;
        }
        $_SESSION=$this->readSession($this->getId());
        $this->_started = true;
    }

    /**
     * Session read handler.
     * @internal Do not call this method directly.
     * @param string $id session ID
     * @return array the session data
     */
    public function readSession($id)
    {
        if( !is_dir($this->getSavePath()) ) FileHelper::createDirectory($this->getSavePath());
        if( !is_readable($this->getSavePath()) ){
            throw new InvalidConfigException("SESSION saved path {$this->savePath} is not readable");
        }
        if( !is_writable($this->getSavePath()) ){
            throw new InvalidConfigException("SESSION saved path {$this->savePath} is not writable");
        }
        $file = $this->getSavePath() . $this->_prefix .$id;
        if( file_exists($file) && is_file($file) ) {
            $data = file_get_contents($file);
            $data = json_decode($data, true);
        }else{
            $data = [];
        }
        return $data;
    }

    /**
     * Session write handler.
     * @internal Do not call this method directly.
     * @param string $id session ID
     * @param string $data session data
     * @return bool whether session write is successful
     */
    public function writeSession($id, $data)
    {
        // exception must be caught in session write handler
        // http://us.php.net/manual/en/function.session-set-save-handler.php#refsect1-function.session-set-save-handler-notes
        file_put_contents($this->savePath.$this->_prefix.$id, $data);
        return true;
    }

    public function getCookieParams()
    {
        return $this->_cookieParams;
    }

    public function setCookieParams(array $config){
        $this->_cookieParams = $config;
    }

    public function destroy()
    {
        $this->open();
        if ($this->getIsActive()) {
            $_SESSION = [];
        }
    }

    public function getIsActive()
    {
        return $this->_started;
    }

    private $_hasSessionId;

    public function getHasSessionId()
    {
        if ($this->_hasSessionId === null) {
            $name = $this->getName();
            $request = Yii::$app->getRequest();
            if (!empty($_COOKIE[$name]) && ini_get('session.use_cookies')) {
                $this->_hasSessionId = true;
            } elseif (!ini_get('session.use_only_cookies') && ini_get('session.use_trans_sid')) {
                $this->_hasSessionId = $request->get($name) != '';
            } else {
                $this->_hasSessionId = false;
            }
        }
        return $this->_hasSessionId;
    }

    public function setHasSessionId($value)
    {
        $this->_hasSessionId = $value;
    }

    public function getId()
    {
        if( isset($_COOKIE[$this->getName()]) ){
            $id = $_COOKIE[$this->getName()];
        }else{
            $id = uniqid();
        }
        return $id;
    }

    public function regenerateID($deleteOldSession = false)
    {
    }

    public function getName()
    {
        return $this->name?:'yzb_';
    }

    public function setName($name){
        return $this->name=$name;
    }

    public function getSavePath()
    {
        if( substr( $this->savePath,-1) !='/' ){
            $this->savePath .= '/';
        }
        return $this->savePath;
    }

    public function setSavePath($value)
    {
        $this->savePath = $value;
    }

    public function getIterator()
    {
        $this->open();
        return new SessionIterator();
    }

    public function getCount()
    {
        $this->open();
        return count($_SESSION);
    }

    public function count()
    {
        $this->open();
        return $this->getCount();
    }

    public function get($key, $defaultValue = null)
    {
        $this->open();
        return isset($_SESSION[$key]) ? $_SESSION[$key] : $defaultValue;
    }

    public function set($key, $value)
    {
        $this->open();
        $_SESSION[$key] = $value;
    }

    public function remove($key)
    {
        $this->open();
        if (isset($_SESSION[$key])) {
            $value = $_SESSION[$key];
            unset($_SESSION[$key]);
            return $value;
        }
        return null;
    }

    public function removeAll()
    {
        $this->open();
        foreach (array_keys($_SESSION) as $key) {
            unset($_SESSION[$key]);
        }
    }

    public function has($key)
    {
        $this->open();
        return isset($_SESSION[$key]);
    }

    protected function updateFlashCounters()
    {
        $this->open();
        $counters = $this->get($this->flashParam, []);
        if (is_array($counters)) {
            foreach ($counters as $key => $count) {
                if ($count > 0) {
                    unset($counters[$key], $_SESSION[$key]);
                } elseif ($count == 0) {
                    $counters[$key]++;
                }
            }
            $_SESSION[$this->flashParam] = $counters;
        } else {
            // fix the unexpected problem that flashParam doesn't return an array
            unset($_SESSION[$this->flashParam]);
        }
    }

    public function getFlash($key, $defaultValue = null, $delete = true)
    {
        $this->open();
        $counters = $this->get($this->flashParam, []);
        if (isset($counters[$key])) {
            $value = $this->get($key, $defaultValue);
            if ($delete) {
                $this->removeFlash($key);
            } elseif ($counters[$key] < 0) {
                // mark for deletion in the next request
                $counters[$key] = 1;
                $_SESSION[$this->flashParam] = $counters;
            }
            return $value;
        }
        return $defaultValue;
    }

    public function getAllFlashes($delete = false)
    {
        $this->open();
        $counters = $this->get($this->flashParam, []);
        $flashes = [];
        foreach (array_keys($counters) as $key) {
            if (array_key_exists($key, $_SESSION)) {
                $flashes[$key] = $_SESSION[$key];
                if ($delete) {
                    unset($counters[$key], $_SESSION[$key]);
                } elseif ($counters[$key] < 0) {
                    // mark for deletion in the next request
                    $counters[$key] = 1;
                }
            } else {
                unset($counters[$key]);
            }
        }
        $_SESSION[$this->flashParam] = $counters;
        return $flashes;
    }

    public function setFlash($key, $value = true, $removeAfterAccess = true)
    {
        $this->open();
        $counters = $this->get($this->flashParam, []);
        $counters[$key] = $removeAfterAccess ? -1 : 0;
        $_SESSION[$key] = $value;
        $_SESSION[$this->flashParam] = $counters;
    }

    public function addFlash($key, $value = true, $removeAfterAccess = true)
    {
        $this->open();
        $counters = $this->get($this->flashParam, []);
        $counters[$key] = $removeAfterAccess ? -1 : 0;
        $_SESSION[$this->flashParam] = $counters;
        if (empty($_SESSION[$key])) {
            $_SESSION[$key] = [$value];
        } else {
            if (is_array($_SESSION[$key])) {
                $_SESSION[$key][] = $value;
            } else {
                $_SESSION[$key] = [$_SESSION[$key], $value];
            }
        }
    }

    public function removeFlash($key)
    {
        $this->open();
        $counters = $this->get($this->flashParam, []);
        $value = isset($_SESSION[$key], $counters[$key]) ? $_SESSION[$key] : null;
        unset($counters[$key], $_SESSION[$key]);
        $_SESSION[$this->flashParam] = $counters;
        return $value;
    }

    public function removeAllFlashes()
    {
        $this->open();
        $counters = $this->get($this->flashParam, []);
        foreach (array_keys($counters) as $key) {
            unset($_SESSION[$key]);
        }
        unset($_SESSION[$this->flashParam]);
    }

    public function hasFlash($key)
    {
        $this->open();
        return $this->getFlash($key) !== null;
    }

    public function offsetExists($offset)
    {
        $this->open();
        return isset($_SESSION[$offset]);
    }

    public function offsetGet($offset)
    {
        $this->open();
        return isset($_SESSION[$offset]) ? $_SESSION[$offset] : null;
    }

    public function offsetSet($offset, $item)
    {
        $this->open();
        $_SESSION[$offset] = $item;
    }

    public function offsetUnset($offset)
    {
        $this->open();
        unset($_SESSION[$offset]);
    }

    public function getTimeout()
    {
        if($this->timeout==null){
            $this->timeout=1400;
        }
        return $this->timeout;
    }

    public function setTimeout($timeout){
        $this->timeout=$timeout;
    }

}