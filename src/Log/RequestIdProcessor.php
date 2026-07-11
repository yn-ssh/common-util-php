<?php

namespace Ssh\CommonUtil\Log;

/**
 * 请求上下文 Processor — 为每条日志注入 request_id / client_ip / app_id 字段
 *
 * 由 RequestIdMiddleware 在请求入口设置 client_ip，
 * 由业务代码按需设置 app_id（登录/OAuth 等场景才有）。
 *
 * Workerman 单 worker 单请求模型，静态变量在请求维度安全。
 */
class RequestIdProcessor
{
    protected static string $requestId = '-';
    protected static string $clientIp  = '-';
    protected static string $appId     = '-';

    public static function setRequestId(string $id): void
    {
        self::$requestId = $id;
    }

    public static function getRequestId(): string
    {
        return self::$requestId;
    }

    public static function setClientIp(string $ip): void
    {
        self::$clientIp = $ip;
    }

    public static function getClientIp(): string
    {
        return self::$clientIp;
    }

    public static function setAppId(string $appId): void
    {
        self::$appId = $appId;
    }

    public static function getAppId(): string
    {
        return self::$appId;
    }

    public static function reset(): void
    {
        self::$requestId = '-';
        self::$clientIp  = '-';
        self::$appId     = '-';
    }

    public function __invoke(array $record): array
    {
        $record['extra']['request_id'] = self::$requestId;
        $record['extra']['client_ip']  = self::$clientIp;
        $record['extra']['app_id']     = self::$appId;
        return $record;
    }
}
