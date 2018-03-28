<?php

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/aliyun-log-php-sdk/Log_Autoload.php';

file_exists(__DIR__ . '/.env') && (new Dotenv\Dotenv(__DIR__))->load();

class sync
{
    /**
     * @var Aliyun_Log_Client
     */

    protected $slsClient;

    /**
     * @var array|false|string
     */

    protected $project;

    /**
     * @var array|false|string
     */

    protected $logStore;

    /**
     * @var
     */

    protected $logTopic;

    /**
     * @var \Predis\Client
     */

    protected $redisClient;

    /**
     * @var \OSS\OssClient
     */

    protected $ossClient;

    /**
     * @var
     */

    protected $ossBucket;

    /**
     * @var string
     */

    protected $logPop;

    /**
     * @var int
     */

    protected $trySleepTimes = 3;

    /**
     * @var int
     */

    protected $hasTryTimes = 0;

    /**
     * @var int
     */

    protected $sleepTime = 1;

    /**
     * @var array
     */

    protected $tempLogList = [];

    /**
     * @var int
     */

    protected $tempLogLength = 0;

    /**
     * @var int
     */

    protected $maxTrySendTimes = 3;

    /**
     * @var int
     */

    protected $hasTrySendTimes = 0;

    /**
     * @var
     */

    protected $lastFlushTime = 0;

    /**
     * sync constructor.
     */

    public function __construct()
    {
        $this->redisClient = new \Predis\Client([
            'host' => getenv('REDIS_HOST') ?: '127.0.0.1',
            'password' => getenv('REDIS_PASSWORD') ?: null,
            'port' => getenv('REDIS_PORT') ?: 6379,
            'database' => getenv('REDIS_DB') ?: 0
        ]);
        $this->logPop = getenv('REDIS_POP_NAME') ?: 'RedisMonolog';
        $this->slsClient = new Aliyun_Log_Client(getenv('SLS_ENDPOINT'), getenv('SLS_ACCESS_KEY'), getenv('SLS_ACCESS_SECRET'));
        $this->project = getenv('SLS_PROJECT');
        $this->logStore = getenv('SLS_LOG_STORE');
        $this->logTopic = getenv('SLS_TOPIC');
        $this->ossBucket = getenv('OSS_BUCKET');
        try {
            $this->ossClient = new \OSS\OssClient(getenv('OSS_ACCESS_KEY'), getenv('OSS_ACCESS_SECRET'), getenv('OSS_ENDPOINT'));
        } catch (\OSS\Core\OssException $exception) {
            $this->ossClient = null;
        }
    }

    /**
     * run sync
     */

    public function run()
    {
        while (true) {
            try {
                if (!$log = $this->redisClient->lpop($this->logPop)) {
                    $this->report('no log:' . microtime(true));
                    $this->pushLog();
                    $this->hasTryTimes += 1;
                    if ($this->hasTryTimes >= $this->trySleepTimes) {
                        $this->report('sleep');
                        sleep($this->sleepTime);
                        $this->hasTryTimes = 0;
                        continue;
                    }
                    continue;
                }
                $this->report('has log:' . microtime(true));
                $this->pushLog($log);
            } catch (Exception $exception) {
                $this->report($exception->__toString());
                $this->pushLog();
                $this->redisClient->disconnect();
                continue;
            } catch (Throwable $exception) {
                $this->report($exception->__toString());
                $this->pushLog();
                $this->redisClient->disconnect();
                continue;
            }
        }
    }

    /**
     * output run log
     *
     * @param $log
     */

    protected function report($log)
    {
        echo $log . "\n";
    }

    /**
     * push log to temp
     *
     * @param null $log
     */


    public function pushLog($log = null)
    {
        if ($log === null && $this->tempLogLength > 0) {
            $this->flushTempLog();
            return;
        }
        if (strlen($log) > 0 && strlen($log) <= $this->convertSize(1)) {
            $this->report('log less to 1mb ' . microtime(true));
            $this->writeLogToTemp(json_decode($log, true));
        } elseif (strlen($log) > $this->convertSize(1) && strlen($log) <= $this->convertSize(3)) {
            $this->report('log between 1mb and 3mb ' . microtime(true));
            $this->flushTempLog();
            $this->writeLogToTemp(json_decode($log, true));
        } elseif (strlen($log) > $this->convertSize(3)) {
            $this->report('log between more then 3mb ' . microtime(true));
            $this->writeLogToTemp($this->sendLogToOss(json_decode($log, true)));
        }
        $this->report('log length:' . $this->tempLogLength . ' ' . microtime(true));
        if ($this->tempLogLength >= $this->convertSize(2) || count($this->tempLogList) >= 4095) {
            $this->report('flush one' . microtime(true));
            $this->flushTempLog();
            return;
        }
        if (count($this->tempLogList) > 0 && microtime(true) - $this->lastFlushTime >= 0.3) {
            $this->report('flush two' . microtime(true));
            $this->flushTempLog();
            return;
        }
    }

    /**
     * @param array $log
     */

    protected function writeLogToTemp(array $log)
    {
        $logItem = new Aliyun_Log_Models_LogItem();
        $logItem->setTime(isset($log['microTime']) ? $log['microTime'] : microtime(true));
        $logItem->setContents($log);
        $this->tempLogLength += strlen(json_encode($log));
        $this->tempLogList[] = $logItem;
    }

    /**
     * flush temp log
     */

    protected function flushTempLog()
    {
        $this->report('flush temp log ' . microtime(true));
        try {
            $this->hasTrySendTimes += 1;
            if ($this->hasTrySendTimes >= $this->maxTrySendTimes) {
                $this->hasTrySendTimes = 0;
                $this->clearTempLog();
                return;
            }
            if (!$this->sendLogToSls()) {
                $this->report('send to sls failed');
                $this->flushTempLog();
            }
            $this->clearTempLog();
        } catch (Exception $exception) {
            $this->report('send to sls exception');
            $this->flushTempLog();
        } catch (Throwable $exception) {
            $this->report('send to sls exception');
            $this->flushTempLog();
        }
    }

    /**
     * send long log to oss
     *
     * @param $log
     * @return array
     */

    protected function sendLogToOss(array $log)
    {
        $returnLog = $this->unsetLongLog($log);
        try {
            $this->report('try to send to oss');
            if ($this->ossClient === null) {
                throw new Exception('oss client invalid.');
            }
            $filename = uniqid();
            $this->ossClient->putObject($this->ossBucket, $filename . '.log', json_encode($log));
            $returnLog['ossObject'] = $filename;
        } catch (Exception $exception) {
            $returnLog['message'] = 'long log send to oss failed';
            $returnLog['ossException'] = $exception->__toString();
        } catch (Throwable $exception) {
            $returnLog['message'] = 'long log send to oss failed';
            $returnLog['ossException'] = $exception->__toString();
        }
        return $returnLog;
    }

    /**
     * @param array $log
     * @return array
     */

    protected function unsetLongLog(array $log)
    {
        $keys = ['context', 'extra', 'message'];
        foreach ($keys as $key) {
            $this->checkAndUnsetLog($log, $key);
        }
        return $log;
    }

    /**
     * @param $log
     * @param $key
     */

    protected function checkAndUnsetLog(&$log, $key)
    {
        if (isset($log[$key]) && strlen($log[$key]) > $this->convertSize(1)) {
            unset($log[$key]);
            $log[$key] = 'too long to send to oss';
        }
    }

    /**
     * send log to sls
     *
     * @return bool
     */

    protected function sendLogToSls()
    {
        try {
            $request = new Aliyun_Log_Models_PutLogsRequest($this->project, $this->logStore, $this->logTopic, null, $this->tempLogList);
            $this->slsClient->putLogs($request);
            return true;
        } catch (Aliyun_Log_Exception $ex) {
            echo $ex->getTraceAsString();
            return false;
        } catch (Exception $ex) {
            echo $ex->getTraceAsString();
            return false;
        }
    }

    /**
     * clear temp log
     */

    protected function clearTempLog()
    {
        $this->lastFlushTime = microtime(true);
        $this->tempLogLength = 0;
        $this->tempLogList = [];
    }

    /**
     * convert log size
     *
     * @param $mb
     * @return float|int
     */

    protected function convertSize($mb)
    {
        return $mb * 1024 * 1024;
    }
}

(new sync())->run();