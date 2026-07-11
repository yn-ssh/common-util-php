<?php

namespace Ssh\CommonUtil\Log;

/**
 * 请求追踪 ID Processor — 为每条日志注入 request_id 字段
 *
 * Workerman 单 worker 单请求模型，静态变量在请求维度安全。
 */
class RequestIdProcessor
{
    protected static string $requestId = '-';

    public static function setRequestId(string $id): void
    {
        self::$requestId = $id;
    }

    public static function getRequestId(): string
    {
        return self::$requestId;
    }

    public static function reset(): void
    {
        self::$requestId = '-';
    }

    public function __invoke(array $record): array
    {
        $record['extra']['request_id'] = self::$requestId;
        return $record;
    }
}
