<?php

namespace Ssh\CommonUtil\Process;

use Nacos\Nacos;
use Psr\Log\NullLogger;
use support\Log;
use Workerman\Timer;

/**
 * Nacos 服务注册、心跳与配置管理进程
 * 基于 ssh/nacos-sdk-php SDK
 *
 * 配置缓存策略：将 Nacos 配置写入本地 PHP 文件（config/nacos_cache/），
 * Worker 进程通过 include 读取，无需 Redis 中转，消除启动依赖。
 */
class NacosProcess
{
    private string $host;
    private string $namespace;
    private string $group;
    private string $accessKey;
    private string $secretKey;
    private string $serverName;
    private string $serverIP;
    private int $serverPort;
    private int $grpcPort;
    private string $username;
    private string $password;
    private bool $logEnable;
    private bool $sdkLogEnable;
    private string $configDataIds;

    /** Nacos 配置本地缓存目录 */
    private string $cacheDir;

    /** @var int|null 心跳定时器 ID */
    private ?int $heartbeatTimerId = null;

    /** @var int|null 配置轮询定时器 ID */
    private ?int $pollingTimerId = null;

    public function __construct(
        string $host,
        string $namespace,
        string $group,
        string $accessKey,
        string $secretKey,
        string $serverName = '',
        string $serverIP = '',
        int|string $serverPort = '',
        int|string $grpcPort = 9848,
        string $username = '',
        string $password = '',
        bool|string $logEnable = true,
        bool|string $sdkLogEnable = false,
        string $configDataIds = ''
    ) {
        $this->host = $host;
        $this->namespace = $namespace;
        $this->group = $group;
        $this->accessKey = $accessKey;
        $this->secretKey = $secretKey;
        $this->serverName = $serverName;
        $this->serverIP = $serverIP;
        $this->serverPort = $serverPort === '' ? 0 : (int)$serverPort;
        $this->grpcPort = (int)$grpcPort;
        $this->username = $username;
        $this->password = $password;
        $this->logEnable = filter_var($logEnable, FILTER_VALIDATE_BOOLEAN);
        $this->sdkLogEnable = filter_var($sdkLogEnable, FILTER_VALIDATE_BOOLEAN);
        $this->configDataIds = $configDataIds;
        $this->cacheDir = runtime_path() . '/nacos_cache';

        // 自动检测未配置的服务信息
        if ($this->serverName === '') {
            $this->serverName = getenv('APP.NAME') ?: 'webman-app';
        }
        if ($this->serverIP === '') {
            $this->serverIP = $this->detectLocalIP();
        }
        if ($this->serverPort === 0) {
            $this->serverPort = $this->detectServerPort();
        }
    }

    public function onWorkerStart(): void
    {
        $logger = $this->sdkLogEnable ? Log::channel() : new NullLogger();

        $nacos = new Nacos(
            $this->host,
            $this->namespace,
            $this->accessKey,
            $this->secretKey,
            $this->grpcPort,
            $logger,
            $this->username,
            $this->password
        );

        $serverName = $this->serverName;
        $group = $this->group;
        $ip = $this->serverIP;
        $port = $this->serverPort;
        $logEnable = $this->logEnable;

        if ($logEnable) {
            Log::info('Nacos process started', [
                'serverName' => $serverName,
                'host' => $this->host,
                'namespace' => $this->namespace,
            ]);
        }

        // ==================== 配置管理（优先初始化）====================
        // 必须在服务注册之前完成，确保 Worker 进程能尽早读到缓存文件
        $configCache = $this->initConfigSync($nacos, $group, $logEnable);

        // ==================== 服务注册与心跳 ====================
        $this->heartbeatTimerId = Timer::add(5, function () use ($nacos, $serverName, $group, $ip, $port, $logEnable) {
            try {
                $nacos->discovery()->registerInstance($serverName, $ip, $port, $group);

                if ($logEnable) {
                    Log::info('Nacos heartbeat', ['service' => $serverName, 'ip' => $ip, 'port' => $port]);
                }
            } catch (\Exception $e) {
                Log::error('Nacos register error: ' . $e->getMessage());
            }
        });

        // ==================== 配置变更监听（定时轮询）====================
        $this->pollingTimerId = $this->startConfigPolling($nacos, $group, $logEnable, $configCache);
    }

    /**
     * 优雅关闭：停止时取消定时器，避免 Swoole 关闭时阻塞 IO 导致死锁
     * 由 Workerman worker_bind 自动绑定到 Worker 的 onWorkerStop 回调
     */
    public function onWorkerStop(): void
    {
        if ($this->heartbeatTimerId !== null) {
            Timer::del($this->heartbeatTimerId);
        }
        if ($this->pollingTimerId !== null) {
            Timer::del($this->pollingTimerId);
        }
    }

    /**
     * 启动时同步拉取配置并写入缓存文件
     * 该方法会阻塞直到所有配置拉取完成，确保 Worker 进程能尽快读到配置
     *
     * @return array 配置内容缓存 ['dataId' => 'content']
     */
    private function initConfigSync(Nacos $nacos, string $group, bool $logEnable): array
    {
        if (empty($this->configDataIds)) {
            return [];
        }

        $dataIds = array_filter(array_map('trim', explode(',', $this->configDataIds)));
        if (empty($dataIds)) {
            return [];
        }

        // 确保缓存目录存在
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }

        // 同步拉取所有配置并写入本地 PHP 文件
        $configCache = [];
        foreach ($dataIds as $dataId) {
            try {
                $content = $nacos->config()->getConfig($dataId, $group);
                $configCache[$dataId] = $content;

                // 始终写入缓存文件（即使内容为空）
                // 空内容表示 Nacos 上没有该配置，Worker 将使用 env 默认值
                $this->writeCacheFile($dataId, $content);

                if ($logEnable) {
                    $status = $content !== '' ? 'loaded' : 'empty (using defaults)';
                    Log::info('Nacos config ' . $status, ['dataId' => $dataId, 'group' => $group]);
                }
            } catch (\Exception $e) {
                $configCache[$dataId] = '';
                Log::error('Nacos config load error: ' . $e->getMessage());
            }
        }

        return $configCache;
    }

    /**
     * 启动配置变更定时轮询
     * 每 2 秒检查一次配置是否变更，变更则更新缓存文件
     *
     * @return int|null Timer ID，用于优雅关闭时取消
     */
    private function startConfigPolling(Nacos $nacos, string $group, bool $logEnable, array &$configCache): ?int
    {
        if (empty($this->configDataIds)) {
            return null;
        }

        $dataIds = array_filter(array_map('trim', explode(',', $this->configDataIds)));
        if (empty($dataIds)) {
            return null;
        }

        return Timer::add(2, function () use ($nacos, $group, $dataIds, $logEnable, &$configCache) {
            foreach ($dataIds as $dataId) {
                try {
                    $newContent = $nacos->config()->getConfig($dataId, $group);
                    $oldContent = $configCache[$dataId] ?? '';

                    if ($newContent !== $oldContent) {
                        $this->writeCacheFile($dataId, $newContent);
                        $configCache[$dataId] = $newContent;

                        if ($logEnable) {
                            Log::info('Nacos config changed', ['dataId' => $dataId, 'group' => $group]);
                        }
                    }
                } catch (\Exception $e) {
                    Log::error('Nacos config poll error: ' . $e->getMessage());
                }
            }
        });
    }

    /**
     * 将 Nacos 配置写入本地 PHP 缓存文件
     *
     * 文件格式：<?php return [ ... ];
     * Worker 进程通过 include 直接读取为 PHP 数组
     */
    private function writeCacheFile(string $dataId, string $jsonContent): void
    {
        $file = $this->cacheDir . '/' . $dataId . '.php';
        $data = json_decode($jsonContent, true);
        if (!is_array($data)) {
            $data = [];
        }
        $phpCode = "<?php\n// Nacos config cache: {$dataId}\n// Generated: " . date('Y-m-d H:i:s') . "\nreturn " . var_export($data, true) . ";\n";
        file_put_contents($file, $phpCode, LOCK_EX);
    }

    /**
     * 自动检测本机出口 IP
     */
    private function detectLocalIP(): string
    {
        try {
            $socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
            socket_connect($socket, '8.8.8.8', 80);
            socket_getsockname($socket, $ip);
            socket_close($socket);
            if (!empty($ip) && $ip !== '0.0.0.0') {
                return $ip;
            }
        } catch (\Exception $e) {
            // fallback
        }
        return @gethostbyname(gethostname()) ?: '127.0.0.1';
    }

    /**
     * 自动从 APP.SERVER 检测服务端口
     */
    private function detectServerPort(): int
    {
        $server = getenv('APP.SERVER');
        if ($server) {
            $port = parse_url($server, PHP_URL_PORT);
            if ($port) {
                return (int)$port;
            }
        }
        return 8787;
    }
}
