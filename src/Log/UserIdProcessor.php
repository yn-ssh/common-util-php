<?php

namespace Ssh\CommonUtil\Log;

/**
 * 用户 ID Processor — 为每条日志注入 user_id 字段
 *
 * 由 AuthMiddleware 在鉴权通过后调用 setUserId()，
 * 由 RequestIdMiddleware 在请求结束时调用 reset()。
 *
 * Workerman 单 worker 单请求模型，静态变量在请求维度安全。
 */
class UserIdProcessor
{
    protected static string $userId = '-';

    public static function setUserId(string $userId): void
    {
        self::$userId = $userId;
    }

    public static function getUserId(): string
    {
        return self::$userId;
    }

    public static function reset(): void
    {
        self::$userId = '-';
    }

    public function __invoke(array $record): array
    {
        $record['extra']['user_id'] = self::$userId;
        return $record;
    }
}
