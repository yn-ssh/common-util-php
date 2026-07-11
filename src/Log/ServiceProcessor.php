<?php

namespace Ssh\CommonUtil\Log;

/**
 * 服务标识 Processor — 为每条日志注入 service / server_ip / server_port 字段
 *
 * 在多实例部署场景下，仅靠 service 名称无法区分具体容器/Pod，
 * 增加 server_ip + server_port 可精确定位产生日志的实例。
 *
 * 构造时同时写入静态 $currentService，供 AuditLogService 等组件获取当前服务名。
 */
class ServiceProcessor
{
    protected string $serviceName;
    protected string $serverIp;
    protected string $serverPort;

    protected static string $currentService = '-';

    public function __construct(string $serviceName, string $serverIp = '-', string $serverPort = '-')
    {
        $this->serviceName = $serviceName;
        $this->serverIp   = $serverIp;
        $this->serverPort = $serverPort;
        self::$currentService = $serviceName;
    }

    public static function getServiceName(): string
    {
        return self::$currentService;
    }

    public function __invoke(array $record): array
    {
        $record['extra']['service']     = $this->serviceName;
        $record['extra']['server_ip']   = $this->serverIp;
        $record['extra']['server_port'] = $this->serverPort;
        return $record;
    }
}
