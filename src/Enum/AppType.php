<?php

namespace Ssh\CommonUtil\Enum;

/**
 * 应用类型枚举
 *
 * 统一管理 sys_app.app_type 的类型常量与平台标识映射。
 * 跨服务共享：spms-auth（第三方登录）、spms-app（应用管理、token刷新）
 */
class AppType
{
    // ==================== 应用类型常量 ====================

    /** 平台 */
    const PLATFORM = 0;

    /** 支付宝小程序 */
    const ALIPAY_MINI = 10;

    /** 支付宝ISV（第三方应用） */
    const ALIPAY_ISV = 11;

    /** 支付宝网站/移动应用 */
    const ALIPAY_WEB_MOBILE = 12;

    /** 微信小程序 */
    const WECHAT_MINI = 20;

    /** 微信公众号 */
    const WECHAT_OFFICIAL = 21;

    /** 微信视频号 */
    const WECHAT_CHANNEL = 22;

    /** 微信移动应用 */
    const WECHAT_MOBILE = 23;

    /** 微信网站应用 */
    const WECHAT_WEB = 24;

    /** 微信开放平台 */
    const WECHAT_OPEN = 25;

    /** 百度 */
    const BAIDU = 30;

    // ==================== 平台标识（用于第三方登录） ====================

    /** 微信小程序 */
    const PLATFORM_MP = 'wechat_mp';

    /** 微信公众号 */
    const PLATFORM_OA = 'wechat_official';

    /** 微信网站应用/PC扫码 */
    const PLATFORM_PC = 'wechat_open';

    // ==================== 平台标识 → app_type 映射 ====================

    /**
     * 平台标识到 app_type 的映射表
     */
    private const PLATFORM_MAP = [
        self::PLATFORM_MP => self::WECHAT_MINI,       // wechat_mp      → 20
        self::PLATFORM_OA => self::WECHAT_OFFICIAL,    // wechat_official → 21
        self::PLATFORM_PC => self::WECHAT_WEB,         // wechat_open     → 24
    ];

    /**
     * 类型标签
     */
    private const LABELS = [
        self::PLATFORM          => '平台',
        self::ALIPAY_MINI       => '支付宝小程序',
        self::ALIPAY_ISV        => '支付宝ISV',
        self::ALIPAY_WEB_MOBILE => '支付宝网站/移动',
        self::WECHAT_MINI       => '微信小程序',
        self::WECHAT_OFFICIAL   => '微信公众号',
        self::WECHAT_CHANNEL    => '微信视频号',
        self::WECHAT_MOBILE     => '微信移动应用',
        self::WECHAT_WEB       => '微信网站应用',
        self::WECHAT_OPEN       => '微信开放平台',
        self::BAIDU             => '百度',
    ];

    // ==================== 平台相关方法 ====================

    /**
     * 检查平台标识是否受支持
     *
     * @param string $platform 平台标识 (wechat_mp/wechat_official/wechat_open)
     * @return bool
     */
    public static function isSupported(string $platform): bool
    {
        return isset(self::PLATFORM_MAP[$platform]);
    }

    /**
     * 根据平台标识获取 app_type
     *
     * @param string $platform 平台标识
     * @return int app_type 值，不存在返回 -1
     */
    public static function getAppType(string $platform): int
    {
        return self::PLATFORM_MAP[$platform] ?? -1;
    }

    /**
     * 根据 app_type 获取平台标识
     *
     * @param int $appType 应用类型
     * @return string|null 平台标识，不存在返回 null
     */
    public static function getPlatform(int $appType): ?string
    {
        $platform = array_search($appType, self::PLATFORM_MAP, true);
        return $platform !== false ? $platform : null;
    }

    // ==================== 类型分组方法 ====================

    /**
     * 获取支付宝相关类型
     */
    public static function getAlipayTypes(): array
    {
        return [
            self::ALIPAY_MINI,
            self::ALIPAY_ISV,
            self::ALIPAY_WEB_MOBILE,
        ];
    }

    /**
     * 获取微信相关类型
     */
    public static function getWechatTypes(): array
    {
        return [
            self::WECHAT_MINI,
            self::WECHAT_OFFICIAL,
            self::WECHAT_CHANNEL,
            self::WECHAT_MOBILE,
            self::WECHAT_WEB,
            self::WECHAT_OPEN,
        ];
    }

    /**
     * 判断是否为支付宝类型
     */
    public static function isAlipay(int $appType): bool
    {
        return in_array($appType, self::getAlipayTypes(), true);
    }

    /**
     * 判断是否为微信类型
     */
    public static function isWechat(int $appType): bool
    {
        return in_array($appType, self::getWechatTypes(), true);
    }

    // ==================== 标签方法 ====================

    /**
     * 获取类型标签
     *
     * @param int $appType 应用类型
     * @return string 类型名称
     */
    public static function label(int $appType): string
    {
        return self::LABELS[$appType] ?? '未知';
    }

    /**
     * 获取所有类型标签映射
     *
     * @return array<int, string>
     */
    public static function getLabels(): array
    {
        return self::LABELS;
    }
}
