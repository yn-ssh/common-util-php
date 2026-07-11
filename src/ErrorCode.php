<?php
/**
 * 统一错误码常量
 *
 * 错误码规则：
 * - 0       成功
 * - 400xx   客户端参数错误
 * - 401xx   认证失败（未登录/Token过期）
 * - 403xx   授权失败（无权限）
 * - 404xx   资源不存在
 * - 409xx   业务冲突（重复/状态冲突）
 * - 500xx   服务端内部错误
 * - 502xx   下游服务调用失败
 */

namespace Ssh\CommonUtil;

class ErrorCode
{
    // 成功
    public const SUCCESS = 0;

    // 客户端参数错误 400xx
    public const PARAM_INVALID       = 40001; // 参数校验失败
    public const PARAM_MISSING      = 40002; // 缺少必填参数

    // 认证失败 401xx
    public const UNLOGGED_IN        = 40100; // 未登录
    public const TOKEN_EXPIRED      = 40101; // Token 已过期
    public const TOKEN_INVALID      = 40102; // Token 无效

    // 授权失败 403xx
    public const UNAUTHORIZED       = 40300; // 无权限
    public const ACCOUNT_DISABLED   = 40301; // 账号已禁用
    public const ACCOUNT_LOCKED     = 40302; // 账号已锁定

    // 资源不存在 404xx
    public const NOT_FOUND          = 40400; // 通用资源不存在
    public const USER_NOT_FOUND     = 40401; // 用户不存在
    public const THIRD_PARTY_NOT_FOUND = 40402; // 第三方账号记录不存在

    // 业务冲突 409xx
    public const DATA_EXISTS        = 40900; // 数据已存在
    public const VERSION_CONFLICT   = 40901; // 数据版本冲突（乐观锁）
    public const STATUS_CONFLICT    = 40902; // 状态冲突

    // 服务端错误 500xx
    public const SERVER_ERROR       = 50000; // 系统内部错误
    public const SERVICE_UNAVAILABLE = 50300; // 服务不可用

    // 下游服务调用失败 502xx
    public const SERVICE_CALL_FAILED = 50200; // Feign 调用下游服务超时或异常

    /**
     * 错误码到 HTTP 状态码的映射
     */
    private static array $httpStatusMap = [
        self::SUCCESS             => 200,
        self::PARAM_INVALID       => 400,
        self::PARAM_MISSING       => 400,
        self::UNLOGGED_IN         => 401,
        self::TOKEN_EXPIRED       => 401,
        self::TOKEN_INVALID       => 401,
        self::UNAUTHORIZED        => 403,
        self::ACCOUNT_DISABLED    => 403,
        self::ACCOUNT_LOCKED      => 403,
        self::NOT_FOUND           => 404,
        self::USER_NOT_FOUND      => 404,
        self::THIRD_PARTY_NOT_FOUND => 404,
        self::DATA_EXISTS         => 409,
        self::VERSION_CONFLICT    => 409,
        self::STATUS_CONFLICT     => 409,
        self::SERVER_ERROR        => 500,
        self::SERVICE_UNAVAILABLE => 503,
        self::SERVICE_CALL_FAILED => 502,
    ];

    /**
     * 根据业务错误码获取 HTTP 状态码
     */
    public static function toHttpStatus(int $code): int
    {
        return self::$httpStatusMap[$code] ?? 500;
    }
}
