<?php

namespace Ssh\CommonUtil\Bootstrap;

use support\Db;
use support\Log;
use Webman\Bootstrap;
use Webman\Config;
use Webman\Database\DatabaseManager;
use Webman\Database\Initializer;
use Workerman\Timer;
use Workerman\Worker;

/**
 * Nacos 配置热更新 Bootstrap
 *
 * 定时检查 runtime/nacos_cache/ 目录下各 dataId 缓存文件的 mtime，
 * 根据实际变更的 dataId 精准执行对应的连接池重置，避免全量重置。
 */
class ConfigReload implements Bootstrap
{
    public static function start(?Worker $worker): void
    {
        if ($worker === null) {
            return;
        }

        $lastMtimes = [];
        $lastReloadTime = 0;
        $stopping = false;

        $worker->onWorkerStop = function () use (&$stopping, &$timerId) {
            $stopping = true;
            if (isset($timerId)) {
                Timer::del($timerId);
            }
        };

        $timerId = Timer::add(1, function () use (&$lastMtimes, &$lastReloadTime, &$stopping) {
            if ($stopping) {
                return;
            }
            $cacheDir = runtime_path() . '/nacos_cache';
            if (!is_dir($cacheDir)) {
                return;
            }

            clearstatcache();
            $files = @scandir($cacheDir);
            if ($files === false) {
                return;
            }

            $currentMtimes = [];
            foreach ($files as $entry) {
                if ($entry === '.' || $entry === '..' || pathinfo($entry, PATHINFO_EXTENSION) !== 'php') {
                    continue;
                }
                $dataId = pathinfo($entry, PATHINFO_FILENAME);
                $file = $cacheDir . '/' . $entry;
                $currentMtimes[$dataId] = filemtime($file);
            }

            if (empty($currentMtimes)) {
                return;
            }

            $changedDataIds = [];
            foreach ($currentMtimes as $dataId => $mtime) {
                if (!isset($lastMtimes[$dataId]) || $lastMtimes[$dataId] !== $mtime) {
                    $changedDataIds[] = $dataId;
                }
            }

            if (empty($lastMtimes)) {
                $lastMtimes = $currentMtimes;
                return;
            }

            if (empty($changedDataIds)) {
                return;
            }

            $now = time();
            if ($now - $lastReloadTime < 5) {
                $lastMtimes = $currentMtimes;
                return;
            }
            $lastReloadTime = $now;

            Log::info("ConfigReload: changed dataIds detected", ['changed' => $changedDataIds]);

            try {
                if (function_exists("nacos_config_clear")) {
                    nacos_config_clear();
                }

                Config::clear();
                \support\App::loadAllConfig(["route"]);

                $hasDatabase = in_array('database', $changedDataIds, true);
                $hasRedis = in_array('redis', $changedDataIds, true);

                if ($hasDatabase) {
                    static::resetDatabasePools();
                    static::reinitDatabase();
                    Log::info("ConfigReload: database reloaded");
                }

                if ($hasRedis) {
                    static::resetRedisPools();
                    Log::info("ConfigReload: redis reloaded");
                }

                static::rebindListeners();

                Log::info("ConfigReload: config reloaded");
            } catch (\Throwable $e) {
                Log::error("ConfigReload reload error: " . $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine());
            }

            $lastMtimes = $currentMtimes;
        });
    }

    private static function resetDatabasePools(): void
    {
        try {
            $ref = new \ReflectionProperty(DatabaseManager::class, "pools");
            $ref->setAccessible(true);
            $pools = $ref->getValue(null);
            foreach ($pools as $pool) {
                try {
                    $pool->closeConnections();
                } catch (\Exception $e) {
                    // ignore
                }
            }
            $ref->setValue(null, []);
        } catch (\Exception $e) {
            Log::error("ConfigReload: failed to reset database pools: " . $e->getMessage());
        }
    }

    private static function reinitDatabase(): void
    {
        try {
            $ref = new \ReflectionProperty(Initializer::class, "initialized");
            $ref->setAccessible(true);
            $ref->setValue(null, false);
            Initializer::init(config("database", []));
        } catch (\Exception $e) {
            Log::error("ConfigReload: failed to reinit database: " . $e->getMessage());
        }
    }

    private static function rebindListeners(): void
    {
        if (class_exists(\Ssh\CommonUtil\Bootstrap\LaravelLog::class)) {
            \Ssh\CommonUtil\Bootstrap\LaravelLog::start(null);
        }
    }

    private static function resetRedisPools(): void
    {
        try {
            $redisManagerClass = \Webman\Redis\RedisManager::class;
            if (!class_exists($redisManagerClass)) {
                return;
            }

            $ref = new \ReflectionProperty($redisManagerClass, 'pools');
            $ref->setAccessible(true);
            $pools = $ref->getValue(null);
            foreach ($pools as $pool) {
                try {
                    $pool->closeConnections();
                } catch (\Exception $e) {
                    // ignore
                }
            }
            $ref->setValue(null, []);

            $redisClass = \support\Redis::class;
            $refInstance = new \ReflectionProperty($redisClass, 'instance');
            $refInstance->setAccessible(true);
            $refInstance->setValue(null, null);

            $refConfig = new \ReflectionProperty($redisClass, 'config');
            $refConfig->setAccessible(true);
            $refConfig->setValue(null, []);

            Log::info('ConfigReload: Redis pools reset');
        } catch (\Exception $e) {
            Log::error('ConfigReload: failed to reset Redis pools: ' . $e->getMessage());
        }
    }
}
