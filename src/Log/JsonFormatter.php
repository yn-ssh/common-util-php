<?php

namespace Ssh\CommonUtil\Log;

use Monolog\Formatter\JsonFormatter as MonologJsonFormatter;

/**
 * JSON 结构化日志格式化器
 *
 * 输出示例：
 * {"time":"2026-07-11 16:05:46","level_name":"ERROR","service":"spms-auth","request_id":"a1b2c3d4","message":"服务器出错","context":{...}}
 */
class JsonFormatter extends MonologJsonFormatter
{
    public function format(array $record): string
    {
        if (isset($record['datetime']) && $record['datetime'] instanceof \DateTimeInterface) {
            $record['datetime'] = $record['datetime']->format('Y-m-d H:i:s');
        }

        $service   = $record['extra']['service']    ?? '-';
        $requestId = $record['extra']['request_id'] ?? '-';

        $normalized = [
            'time'        => $record['datetime'] ?? date('Y-m-d H:i:s'),
            'level_name'  => $record['level_name'] ?? 'INFO',
            'service'     => $service,
            'request_id'  => $requestId,
            'message'     => $record['message'] ?? '',
            'context'     => $record['context'] ?? [],
        ];

        $extra = array_diff_key($record['extra'] ?? [], array_flip(['service', 'request_id']));
        if (!empty($extra)) {
            $normalized['extra'] = $extra;
        }

        return json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    }
}
