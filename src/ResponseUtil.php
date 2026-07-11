<?php
/**
 * @Author SSH
 * @Email 694711507@qq.com
 * @Date 2026/3/27 22:12
 * @Description 统一响应输出
 */

namespace Ssh\CommonUtil;

use Ssh\CommonUtil\Exception\BusinessException;
use support\Response;

class ResponseUtil
{
    public static function toArray($code, $msg = '', $data = []): array
    {
        $result = [];
        $result['code'] = $code;
        $result['msg']  = $msg;
        if (count($data) > 0) {
            $result['data'] = $data;
        }
        return $result;
    }

    public static function success($msg = '', $data = []): Response
    {
        return self::json(200, self::toArray(200, $msg, $data));
    }

    public static function error($data = []): Response
    {
        return self::json(500, self::toArray(500, trans('error'), $data));
    }

    public static function fail($msg = '', $data = []): Response
    {
        return self::json(400, self::toArray(400, $msg, $data));
    }

    public static function notFound($data = []): Response
    {
        return self::json(404, self::toArray(404, trans('notFound'), $data));
    }

    public static function unLoggedIn($data = []): Response
    {
        return self::json(401, self::toArray(401, trans('notLoggedIn'), $data));
    }

    public static function notBind($data = []): Response
    {
        return self::json(402, self::toArray(402, trans('notBind'), $data));
    }

    public static function unauthorized($data = []): Response
    {
        return self::json(403, self::toArray(403, trans('unauthorized'), $data));
    }

    public static function notFoundController(): Response
    {
        return self::json(404, self::toArray(404, trans('notFoundController')));
    }

    public static function notImplemented(): Response
    {
        return self::json(501, self::toArray(501, trans('notImplemented')));
    }

    public static function serviceUnavailable(): Response
    {
        return self::json(503, self::toArray(503, trans('serviceUnavailable')));
    }

    public static function tooManyRequests(): Response
    {
        return self::json(429, self::toArray(429, trans('tooManyRequests')));
    }

    /**
     * 返回业务错误码响应
     * @param int $errorCode ErrorCode 常量
     * @param string $message 自定义消息
     * @param array $data 附加数据
     */
    public static function failWithCode(int $errorCode, string $message = '', $data = []): Response
    {
        $httpStatus = ErrorCode::toHttpStatus($errorCode);
        $msg = $message !== '' ? $message : trans('error');
        return self::json($httpStatus, self::toArray($errorCode, $msg, $data));
    }

    /**
     * 从 BusinessException 创建响应
     */
    public static function fromException(BusinessException $e, $data = []): Response
    {
        return self::failWithCode($e->getErrorCode(), $e->getMessage(), $data);
    }

    /**
     * 统一异常处理
     * BusinessException 返回对应业务错误码，其他异常返回 500
     */
    public static function handleException(\Exception $e): Response
    {
        if ($e instanceof BusinessException) {
            return self::fromException($e);
        }
        return self::error();
    }

    private static function json($code, $data, int $options = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR): Response
    {
        return new Response($code, ['Content-Type' => 'application/json'], json_encode($data, $options));
    }
}
