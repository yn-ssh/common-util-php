<?php

namespace Ssh\CommonUtil\Log;

/**
 * 请求上下文 Processor — 为每条日志注入请求维度字段
 *
 * request_id / client_ip / client_ua / app_id
 *
 * Workerman 单 worker 单请求模型，静态变量在请求维度安全。
 */
class RequestIdProcessor
{
    /** @var int User-Agent 最大截取长度 */
    protected const MAX_UA_LENGTH = 256;

    protected static string $requestId = '-';
    protected static string $clientIp  = '-';
    protected static string $clientUa  = '-';
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

    public static function setClientUa(string $ua): void
    {
        self::$clientUa = strlen($ua) > self::MAX_UA_LENGTH
            ? substr($ua, 0, self::MAX_UA_LENGTH) . '...'
            : $ua;
    }

    public static function getClientUa(): string
    {
        return self::$clientUa;
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
        self::$clientUa  = '-';
        self::$appId     = '-';
    }

    public function __invoke(array $record): array
    {
        $record['extra']['request_id'] = self::$requestId;
        $record['extra']['client_ip']  = self::$clientIp;
        $record['extra']['client_ua']  = self::$clientUa;
        $record['extra']['app_id']     = self::$appId;
        return $record;
    }
}
