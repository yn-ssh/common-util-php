<?php

namespace Ssh\CommonUtil\Middleware;

use Ssh\CommonUtil\ResponseUtil;
use support\Log;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

/**
 * 内部调用中间件
 * 校验请求来源是否为内部服务调用（通过服务间 Token 或内网 IP 白名单）
 */
class InternalMiddleware implements MiddlewareInterface
{
    public function process($request, $handler): Response
    {
        // 方案1：校验内部服务 Token（推荐）
        $internalToken = $request->header('x-internal-token', '');
        if ($internalToken && $this->verifyInternalToken($internalToken)) {
            return $handler($request);
        }

        // 方案2：校验内网 IP 白名单（备用）
        $clientIp = $request->getRealIp();
        if ($this->isInternalIp($clientIp)) {
            return $handler($request);
        }

        Log::warning('Internal API access denied', ['ip' => $clientIp, 'path' => $request->path()]);
        return ResponseUtil::unauthorized();
    }

    /**
     * 校验内部服务 Token
     */
    private function verifyInternalToken(string $token): bool
    {
        $secret = env('INTERNAL_TOKEN_SECRET', 'spms-internal-token-2026');
        return hash_equals($secret, $token);
    }

    /**
     * 检查是否为内网 IP
     */
    private function isInternalIp(string $ip): bool
    {
        // Docker 内网 / Kubernetes Pod 网络
        $privateRanges = [
            '127.0.0.0/8',      // 本地回环
            '10.0.0.0/8',       // A类私有
            '172.16.0.0/12',    // B类私有
            '192.168.0.0/16',   // C类私有
        ];

        foreach ($privateRanges as $range) {
            if ($this->ipInRange($ip, $range)) {
                return true;
            }
        }

        return false;
    }

    /**
     * IP 是否在 CIDR 范围内
     */
    private function ipInRange(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr);
        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);
        $mask = -1 << (32 - (int) $bits);

        return ($ipLong & $mask) === ($subnetLong & $mask);
    }
}
