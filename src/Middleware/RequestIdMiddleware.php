<?php

namespace Ssh\CommonUtil\Middleware;

use Ssh\CommonUtil\Log\RequestIdProcessor;
use Ssh\CommonUtil\Log\UserIdProcessor;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;
use support\Log;

/**
 * 请求追踪中间件
 *
 * 1. 从 X-Request-Id 请求头读取（支持网关透传），无则生成唯一 ID
 * 2. 注入 client_ip / client_ua 到 RequestIdProcessor
 * 3. 请求结束后记录 HTTP 访问日志并重置所有请求级静态变量
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
        RequestIdProcessor::setClientIp($request->getRealIp());
        RequestIdProcessor::setClientUa($request->header('user-agent', ''));

        $method   = $request->method();
        $uri      = $request->path();
        $startMs  = microtime(true);

        try {
            /** @var Response $response */
            $response = $handler($request);
        } finally {
            $durationMs = round((microtime(true) - $startMs) * 1000);
            $status     = isset($response) ? $response->getStatusCode() : 500;

            Log::info('HTTP ' . $status . ' ' . $method . ' ' . $uri, [
                'method'      => $method,
                'uri'         => $uri,
                'status'      => $status,
                'duration_ms' => $durationMs,
            ]);
        }

        $response->header('X-Request-Id', $requestId);

        RequestIdProcessor::reset();
        UserIdProcessor::reset();

        return $response;
    }
}
