<?php

namespace Ssh\CommonUtil\Audit;

use support\Model;

/**
 * 操作审计日志模型
 *
 * 对应 sys_audit_log 表，记录关键业务操作的 who/what/when/where/result。
 * 三微服务共享同一数据库，通过 service 字段区分来源。
 */
class AuditLog extends Model
{
    protected $table = 'sys_audit_log';

    protected $primaryKey = 'id';

    public $timestamps = false;

    protected $fillable = [
        'service',
        'module',
        'action',
        'method',
        'uri',
        'params',
        'user_id',
        'org_id',
        'client_ip',
        'client_ua',
        'app_id',
        'request_id',
        'status',
        'error_msg',
    ];

    protected $casts = [
        'params' => 'json',
        'status' => 'integer',
    ];
}
