<?php
/**
 * 业务异常
 * 携带统一错误码，用于控制器/服务层抛出业务异常
 */

namespace Ssh\CommonUtil\Exception;

use Exception;
use Ssh\CommonUtil\ErrorCode;

class BusinessException extends Exception
{
    private int $errorCode;

    /**
     * @param int $errorCode ErrorCode 常量
     * @param string|null $message 自定义消息，null 时使用默认消息
     * @param Exception|null $previous
     */
    public function __construct(int $errorCode, ?string $message = null, ?Exception $previous = null)
    {
        $this->errorCode = $errorCode;
        parent::__construct($message ?? '', 0, $previous);
    }

    /**
     * 获取业务错误码
     */
    public function getErrorCode(): int
    {
        return $this->errorCode;
    }

    /**
     * 快速创建"资源不存在"异常
     */
    public static function notFound(string $message = '资源不存在'): self
    {
        return new self(ErrorCode::NOT_FOUND, $message);
    }

    /**
     * 快速创建"用户不存在"异常
     */
    public static function userNotFound(): self
    {
        return new self(ErrorCode::USER_NOT_FOUND, '用户不存在');
    }

    /**
     * 快速创建"数据已存在"异常
     */
    public static function dataExists(string $message = '数据已存在'): self
    {
        return new self(ErrorCode::DATA_EXISTS, $message);
    }

    /**
     * 快速创建"版本冲突"异常
     */
    public static function versionConflict(): self
    {
        return new self(ErrorCode::VERSION_CONFLICT, '数据已变更，请刷新后重试');
    }
}
