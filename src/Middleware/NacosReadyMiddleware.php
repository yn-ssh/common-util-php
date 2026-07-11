<?php

namespace Ssh\CommonUtil\Middleware;

use Webman\MiddlewareInterface;
use Webman\Http\Request;
use Webman\Http\Response;

/**
 * Nacos 配置就绪检查中间件
 *
 * 当 Nacos 配置中心启用（NACOS.ENABLE=1）且配置了 CONFIG_DATA_IDS 时，
 * 检查所有配置的 dataId 缓存文件是否已就绪。
 * 未就绪时返回 400，避免使用默认配置处理请求。
 */
class NacosReadyMiddleware implements MiddlewareInterface
{
    /** @var bool 进程级缓存：配置是否已就绪 */
    private static bool $ready = false;

    public function process(Request $request, callable $handler): Response
    {
        $enable = getenv('NACOS.ENABLE');
        if ($enable === false || !filter_var($enable, FILTER_VALIDATE_BOOLEAN)) {
            return $handler($request);
        }

        $dataIdsStr = getenv('NACOS.CONFIG_DATA_IDS');
        if (empty($dataIdsStr)) {
            return $handler($request);
        }

        if (self::$ready) {
            return $handler($request);
        }

        $dataIds = array_filter(array_map('trim', explode(',', $dataIdsStr)));
        $cacheDir = runtime_path() . '/nacos_cache';
        $allReady = true;

        foreach ($dataIds as $dataId) {
            $file = $cacheDir . '/' . $dataId . '.php';
            clearstatcache(false, $file);
            if (!is_file($file)) {
                $allReady = false;
                break;
            }
        }

        if ($allReady) {
            self::$ready = true;
            return $handler($request);
        }

        return new Response(
            400,
            ['Content-Type' => 'application/json; charset=utf-8'],
            json_encode([
                'code' => 400,
                'message' => '正在初始化配置，请稍后再试',
            ], JSON_UNESCAPED_UNICODE)
        );
    }
}
