<?php

namespace Ssh\CommonUtil\Audit;

use Ssh\CommonUtil\Log\RequestIdProcessor;
use Ssh\CommonUtil\Log\UserIdProcessor;
use Ssh\CommonUtil\Log\ServiceProcessor;
use support\Log;
use Webman\Http\Request;

/**
 * 审计日志服务
 *
 * 通过静态 record() 方法记录关键业务操作，自动从 Processor 上下文注入：
 *   - service:     ServiceProcessor::getServiceName()
 *   - user_id:     UserIdProcessor::getUserId()
 *   - org_id:      UserIdProcessor::getOrgId()
 *   - client_ip:   RequestIdProcessor::getClientIp()
 *   - client_ua:   RequestIdProcessor::getClientUa()
 *   - app_id:      RequestIdProcessor::getAppId()
 *   - request_id:  RequestIdProcessor::getRequestId()
 *
 * 用法：
 *   AuditLogService::record('用户管理', '创建', $request->all());
 *   AuditLogService::record('用户管理', '创建', $request->all(), 0, $e->getMessage());
 */
class AuditLogService
{
    /**
     * 敏感字段——脱敏为 ****
     */
    protected const SENSITIVE_FIELDS = [
        'password',
        'old_password',
        'new_password',
        'confirm_password',
        'app_secret',
        'private_key',
        'token',
        'access_token',
        'session_key',
        'captcha_code',
        'captcha_key',
        'slider_x',
        'trajectory',
    ];

    /**
     * 记录审计日志（fire-and-forget，写入失败不影响业务）
     *
     * @param string    $module   功能模块（用户管理/角色管理/...）
     * @param string    $action   操作类型（登录/登出/创建/更新/删除/...）
     * @param array     $params   请求参数（自动脱敏）
     * @param int       $status   0=失败 1=成功
     * @param string    $errorMsg 错误信息（失败时记录）
     * @param int|null  $userId   操作人ID（不传则从上下文获取）
     * @param int|null  $orgId    机构ID（不传则从上下文获取）
     */
    public static function record(
        string $module,
        string $action,
        array $params = [],
        int $status = 1,
        string $errorMsg = '',
        ?int $userId = null,
        ?int $orgId = null,
    ): void {
        try {
            /** @var Request|null $request */
            $request = self::getCurrentRequest();

            $userId = $userId ?? (int) UserIdProcessor::getUserId();
            $orgId  = $orgId  ?? (int) UserIdProcessor::getOrgId();

            AuditLog::create([
                'service'    => ServiceProcessor::getServiceName(),
                'module'     => $module,
                'action'     => $action,
                'method'     => $request?->method() ?? '',
                'uri'        => $request?->path() ?? '',
                'params'     => self::sanitize($params),
                'user_id'    => $userId > 0 ? $userId : null,
                'org_id'     => $orgId > 0 ? $orgId : null,
                'client_ip'  => RequestIdProcessor::getClientIp(),
                'client_ua'  => RequestIdProcessor::getClientUa(),
                'app_id'     => RequestIdProcessor::getAppId() !== '-' ? RequestIdProcessor::getAppId() : '',
                'request_id' => RequestIdProcessor::getRequestId(),
                'status'     => $status,
                'error_msg'  => $errorMsg ?: null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('审计日志写入失败', [
                'module' => $module,
                'action' => $action,
                'error'  => $e->getMessage(),
            ]);
        }
    }

    /**
     * 脱敏处理——将敏感字段值替换为 ****
     */
    protected static function sanitize(array $params): array
    {
        foreach ($params as $key => &$value) {
            if (in_array(strtolower($key), self::SENSITIVE_FIELDS, true)) {
                $value = '****';
            } elseif (is_array($value)) {
                $value = self::sanitize($value);
            }
        }
        return $params;
    }

    /**
     * 获取当前请求对象（兼容不同 Webman 版本）
     */
    protected static function getCurrentRequest(): ?Request
    {
        try {
            return request();
        } catch (\Throwable $e) {
            return null;
        }
    }
}
