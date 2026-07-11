<?php

namespace Ssh\CommonUtil\Service;

use Nacos\Nacos;
use Nacos\Utils\FeignClient;
use Psr\Log\NullLogger;
use support\Log;
use support\Redis;

/**
 * Nacos 服务封装
 * 提供配置管理、服务发现、Feign 调用等能力，可在控制器中通过依赖注入使用
 */
class NacosService
{
    private ?Nacos $nacos = null;

    /**
     * 获取 Nacos SDK 实例（懒加载单例）
     */
    public function getNacos(): Nacos
    {
        if ($this->nacos === null) {
            $sdkLogEnable = filter_var(getenv('NACOS.SDK_LOG_ENABLE'), FILTER_VALIDATE_BOOLEAN);
            $logger = $sdkLogEnable ? Log::channel() : new NullLogger();

            $this->nacos = new Nacos(
                getenv('NACOS.HOST'),
                getenv('NACOS.NAMESPACE') ?: 'public',
                getenv('NACOS.ACCESS_KEY') ?: '',
                getenv('NACOS.SECRET_KEY') ?: '',
                (int)(getenv('NACOS.GRPC_PORT') ?: 0),
                $logger,
                getenv('NACOS.USERNAME') ?: '',
                getenv('NACOS.PASSWORD') ?: ''
            );
        }
        return $this->nacos;
    }

    // ==================== 配置管理 ====================

    /**
     * 读取 Nacos 配置（直接调用 SDK，实时获取）
     */
    public function getConfig(string $dataId, string $group = 'DEFAULT_GROUP'): string
    {
        return $this->getNacos()->config()->getConfig($dataId, $group);
    }

    /**
     * 从 Redis 缓存读取 Nacos 配置（由 NacosProcess 拉取并监听变更，更快）
     */
    public function getConfigFromCache(string $dataId, string $group = 'DEFAULT_GROUP'): string
    {
        $namespace = getenv('NACOS.NAMESPACE') ?: 'public';
        $key = 'nacos:config:' . $namespace . ':' . $group . ':' . $dataId;
        return Redis::get($key) ?: '';
    }

    /**
     * 从 Redis 缓存读取配置并解析为数组（JSON 格式）
     */
    public function getConfigFromCacheAsArray(string $dataId, string $group = 'DEFAULT_GROUP'): array
    {
        $content = $this->getConfigFromCache($dataId, $group);
        if (empty($content)) {
            return [];
        }
        $decoded = json_decode($content, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * 发布配置
     */
    public function publishConfig(string $dataId, string $group, string $content, string $type = 'text'): bool
    {
        return $this->getNacos()->config()->publishConfig($dataId, $group, $content, $type);
    }

    /**
     * 删除配置
     */
    public function deleteConfig(string $dataId, string $group): bool
    {
        return $this->getNacos()->config()->deleteConfig($dataId, $group);
    }

    // ==================== 服务发现 ====================

    /**
     * 获取服务所有实例
     */
    public function getAllInstances(string $serviceName, string $group = 'DEFAULT_GROUP'): array
    {
        return $this->getNacos()->discovery()->getAllInstances($serviceName, $group);
    }

    /**
     * 获取一个健康实例
     */
    public function getHealthyInstance(string $serviceName, string $group = 'DEFAULT_GROUP'): ?array
    {
        return $this->getNacos()->discovery()->selectOneHealthyInstance($serviceName, $group);
    }

    // ==================== Feign 声明式调用 ====================

    /**
     * 创建 Feign 客户端（自动发现目标服务并发起 HTTP 调用）
     */
    public function feign(string $serviceName, string $group = 'DEFAULT_GROUP'): FeignClient
    {
        return $this->getNacos()->feign($serviceName, $group);
    }

    /**
     * 快捷 GET 调用远程服务
     */
    public function get(string $serviceName, string $path, array $params = [], string $group = 'DEFAULT_GROUP'): array
    {
        return $this->feign($serviceName, $group)->get($path, $params);
    }

    /**
     * 快捷 POST 调用远程服务
     */
    public function post(string $serviceName, string $path, array $data = [], string $group = 'DEFAULT_GROUP'): array
    {
        return $this->feign($serviceName, $group)->post($path, $data);
    }
}
