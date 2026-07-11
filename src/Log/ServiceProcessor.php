<?php

namespace Ssh\CommonUtil\Log;

/**
 * 服务标识 Processor — 为每条日志注入 service 字段
 */
class ServiceProcessor
{
    protected string $serviceName;

    public function __construct(string $serviceName)
    {
        $this->serviceName = $serviceName;
    }

    public function __invoke(array $record): array
    {
        $record['extra']['service'] = $this->serviceName;
        return $record;
    }
}
