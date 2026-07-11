<?php

namespace Ssh\CommonUtil\Middleware;

use Ssh\CommonUtil\Log\RequestIdProcessor;
use Ssh\CommonUtil\Log\UserIdProcessor;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

/**
 * 请求追踪 ID 中间件
 *
 * 从 X-Request-Id 请求头读取（支持网关透传），无则生成唯一 ID。
 * 将 ID 注入到 RequestIdProcessor，并在响应头返回。
 * 请求结束后重置 RequestIdProcessor 和 UserIdProcessor。
 */
class RequestIdMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        $requestId = $request->header('x-request-id');
        if (empty($requestId)) {
            $requestId = bin2hex(random_bytes(16));
        }

        RequestIdProcessor::setRequestId($requestId);

        /** @var Response $response */
        $response = $handler($request);

        $response->header('X-Request-Id', $requestId);

        RequestIdProcessor::reset();
        UserIdProcessor::reset();

        return $response;
    }
}
