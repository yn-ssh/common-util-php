<?php

namespace Ssh\CommonUtil\Bootstrap;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Str;
use support\Db;
use support\Log;
use Webman\Bootstrap;
use Workerman\Worker;

/**
 * SQL 执行日志监听器
 * 通过 Db::listen 捕获所有 SQL 执行并记录到 sql 通道
 */
class LaravelLog implements Bootstrap
{
    public static function start(?Worker $worker): void
    {
        Db::listen(function (QueryExecuted $query) {
            $sql = $query->sql;
            $ms = $query->time;
            $connectionName = $query->connectionName;
            if (isset($sql) && strtolower(substr($sql, 0, 8)) != "select 1") {
                $bindings = [];
                if ($query->bindings) {
                    foreach ($query->bindings as $v) {
                        $bindings[] = '"' . strval($v) . '"';
                    }
                }
                $execute = Str::replaceArray('?', $bindings, $sql);
                Log::channel('sql')->debug('SQL', ['connection' => $connectionName, 'database' => $query->connection->getDatabaseName(), 'sql' => $execute, 'ms' => $ms]);
            }
        });
    }
}
