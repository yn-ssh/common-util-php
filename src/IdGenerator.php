<?php
/**
 * @Author SSH
 * @Email 694711507@qq.com
 * @Date 2026/7/14
 * @Description 唯一号生成器（Redis原子自增 + MySQL兜底 + 协程异步回写）
 *
 * 格式: YYYYMMDD + 类型码 + 当日序号(补零)
 * 示例: 202607140100000001 (18位, 类型01=用户, 序号1)
 */

namespace Ssh\CommonUtil;

use support\Db;
use support\Log;
use support\Redis;

class IdGenerator
{
    /** 类型码映射 */
    public const TYPE_USER   = '01';
    public const TYPE_ORG    = '02';
    public const TYPE_APP    = '03';

    /** 人类可读名 → 类型码 */
    private const TYPE_MAP = [
        'user' => self::TYPE_USER,
        'org'  => self::TYPE_ORG,
        'app'  => self::TYPE_APP,
    ];

    /** 默认总长度 */
    private const DEFAULT_TOTAL_LENGTH = 18;

    /** 日期部分固定长度 YYYYMMDD */
    private const DATE_LENGTH = 8;

    /** 类型码固定长度 */
    private const TYPE_CODE_LENGTH = 2;

    /** Redis Key 前缀 */
    private const REDIS_PREFIX = 'spms:idgen:';

    /** Redis Key TTL（秒），2 天 */
    private const REDIS_TTL = 172800;

    /**
     * 生成唯一号
     *
     * @param string      $type        类型名（user/org/app）或类型码（01/02/03）
     * @param int         $totalLength 总长度，序号部分自动补零
     * @param string|null $date        指定日期 Y-m-d，默认今天
     * @return string 唯一号（字符串，避免大整数溢出）
     *
     * @throws \InvalidArgumentException 类型无效或长度不足
     * @throws \OverflowException        当日序号超出上限
     * @throws \RuntimeException         Redis 和 MySQL 均不可用
     */
    public static function next(string $type, int $totalLength = self::DEFAULT_TOTAL_LENGTH, ?string $date = null): string
    {
        $typeCode  = self::resolveTypeCode($type);
        $dateStr   = $date ? date('Ymd', strtotime($date)) : date('Ymd');
        $seqDigits = $totalLength - self::DATE_LENGTH - self::TYPE_CODE_LENGTH;

        if ($seqDigits < 1) {
            throw new \InvalidArgumentException(
                "总长度 {$totalLength} 不足，至少需要 " . (self::DATE_LENGTH + self::TYPE_CODE_LENGTH + 1) . " 位"
            );
        }

        // 获取当日序号
        $seq = self::getSequence($typeCode, $dateStr, $seqDigits);

        // 序号溢出检查
        $maxSeq = (10 ** $seqDigits) - 1;
        if ($seq > $maxSeq) {
            throw new \OverflowException(
                "类型 {$typeCode} 在 {$dateStr} 的序号已超出上限 {$maxSeq}，请扩展总长度"
            );
        }

        return $dateStr . $typeCode . str_pad((string) $seq, $seqDigits, '0', STR_PAD_LEFT);
    }

    /**
     * 批量生成唯一号
     *
     * @param string $type        类型名或类型码
     * @param int    $count       生成数量（1-1000）
     * @param int    $totalLength 总长度
     * @return string[]
     */
    public static function batch(string $type, int $count, int $totalLength = self::DEFAULT_TOTAL_LENGTH): array
    {
        if ($count < 1 || $count > 1000) {
            throw new \InvalidArgumentException('批量生成数量须在 1-1000 之间');
        }

        $typeCode  = self::resolveTypeCode($type);
        $dateStr   = date('Ymd');
        $seqDigits = $totalLength - self::DATE_LENGTH - self::TYPE_CODE_LENGTH;

        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $seq    = self::getSequence($typeCode, $dateStr, $seqDigits);
            $maxSeq = (10 ** $seqDigits) - 1;
            if ($seq > $maxSeq) {
                throw new \OverflowException("序号已超出上限 {$maxSeq}");
            }
            $ids[] = $dateStr . $typeCode . str_pad((string) $seq, $seqDigits, '0', STR_PAD_LEFT);
        }

        return $ids;
    }

    /**
     * 解析唯一号，提取日期、类型码、序号
     *
     * @param string $id 唯一号
     * @return array{date: string, type_code: string, sequence: int, type_name: string|null}
     */
    public static function parse(string $id): array
    {
        $date     = substr($id, 0, self::DATE_LENGTH);
        $typeCode = substr($id, self::DATE_LENGTH, self::TYPE_CODE_LENGTH);
        $seqStr   = substr($id, self::DATE_LENGTH + self::TYPE_CODE_LENGTH);

        $typeName = array_search($typeCode, self::TYPE_MAP, true) ?: null;

        return [
            'date'      => substr($date, 0, 4) . '-' . substr($date, 4, 2) . '-' . substr($date, 6, 2),
            'type_code' => $typeCode,
            'sequence'  => (int) $seqStr,
            'type_name' => $typeName,
        ];
    }

    // ──────────────────────────── 私有方法 ────────────────────────────

    /**
     * 解析类型名/类型码 → 固定 2 位类型码
     */
    private static function resolveTypeCode(string $type): string
    {
        if (isset(self::TYPE_MAP[$type])) {
            return self::TYPE_MAP[$type];
        }

        if (preg_match('/^\d{2}$/', $type)) {
            return $type;
        }

        throw new \InvalidArgumentException(
            "未知的类型: {$type}，可用: " . implode(', ', array_keys(self::TYPE_MAP))
        );
    }

    /**
     * 获取当日序号（Redis 优先，MySQL 兜底）
     */
    private static function getSequence(string $typeCode, string $dateStr, int $seqDigits): int
    {
        $seq = self::getSequenceFromRedis($typeCode, $dateStr);
        if ($seq !== null) {
            // Redis 生成成功，异步回写 MySQL 保持同步
            self::asyncSyncToMysql($typeCode, $dateStr, $seq);
            return $seq;
        }

        Log::warning('IdGenerator: Redis 不可用，回退到 MySQL', [
            'type_code' => $typeCode,
            'date'      => $dateStr,
        ]);

        return self::getSequenceFromMysql($typeCode, $dateStr);
    }

    /**
     * Redis 原子自增获取序号
     *
     * @return int|null 序号，Redis 不可用时返回 null
     */
    private static function getSequenceFromRedis(string $typeCode, string $dateStr): ?int
    {
        try {
            $key = self::REDIS_PREFIX . $typeCode . ':' . $dateStr;

            // INCR：key 不存在时自动初始化为 0 再 +1 = 1
            $seq = Redis::incr($key);

            // 首次创建 key：从 MySQL 校准，防止 Redis 重启后序号冲突
            if ($seq === 1) {
                $mysqlMax = self::getMaxSequenceFromMysql($typeCode, $dateStr);
                if ($mysqlMax > 0) {
                    $seq = $mysqlMax + 1;
                    Redis::set($key, $seq);
                }
                Redis::expire($key, self::REDIS_TTL);
            }

            return $seq;
        } catch (\Throwable $e) {
            Log::warning('IdGenerator: Redis 操作失败', [
                'error' => $e->getMessage(),
                'type'  => $typeCode,
                'date'  => $dateStr,
            ]);
            return null;
        }
    }

    /**
     * 异步回写 MySQL，保持 sys_id_sequence 与 Redis 同步
     *
     * 优先使用 Swoole 协程，不阻塞主请求；
     * 无 Swoole 环境时降级为同步写入（单次 upsert 开销很小）。
     */
    private static function asyncSyncToMysql(string $typeCode, string $dateStr, int $seq): void
    {
        $callback = static function () use ($typeCode, $dateStr, $seq): void {
            try {
                Db::statement(
                    "INSERT INTO sys_id_sequence (type_code, biz_date, current_seq)
                     VALUES (?, ?, ?)
                     ON DUPLICATE KEY UPDATE current_seq = GREATEST(current_seq, VALUES(current_seq))",
                    [$typeCode, $dateStr, $seq]
                );
            } catch (\Throwable $e) {
                Log::warning('IdGenerator: MySQL 异步回写失败（不影响业务）', [
                    'error'     => $e->getMessage(),
                    'type_code' => $typeCode,
                    'date'      => $dateStr,
                    'seq'       => $seq,
                ]);
            }
        };

        // 优先 Swoole 协程
        if (class_exists(\Swoole\Coroutine::class)) {
            \Swoole\Coroutine::create($callback);
            return;
        }

        // 降级：同步写入（单次 upsert 开销极小）
        $callback();
    }

    /**
     * MySQL 兜底：INSERT ... ON DUPLICATE KEY UPDATE 原子自增
     */
    private static function getSequenceFromMysql(string $typeCode, string $dateStr): int
    {
        try {
            // 先尝试 MySQL 8.x RETURNING 语法
            $result = Db::selectOne(
                "INSERT INTO sys_id_sequence (type_code, biz_date, current_seq)
                 VALUES (?, ?, 1)
                 ON DUPLICATE KEY UPDATE current_seq = current_seq + 1
                 RETURNING current_seq",
                [$typeCode, $dateStr]
            );

            if ($result && isset($result->current_seq)) {
                return (int) $result->current_seq;
            }

            // 兼容不支持 RETURNING 的 MySQL 版本
            Db::statement(
                "INSERT INTO sys_id_sequence (type_code, biz_date, current_seq)
                 VALUES (?, ?, 1)
                 ON DUPLICATE KEY UPDATE current_seq = current_seq + 1",
                [$typeCode, $dateStr]
            );

            $row = Db::selectOne(
                "SELECT current_seq FROM sys_id_sequence WHERE type_code = ? AND biz_date = ?",
                [$typeCode, $dateStr]
            );

            return (int) ($row->current_seq ?? 1);
        } catch (\Throwable $e) {
            Log::error('IdGenerator: MySQL 序号生成失败', [
                'error' => $e->getMessage(),
                'type'  => $typeCode,
                'date'  => $dateStr,
            ]);
            throw new \RuntimeException('ID 生成服务不可用: ' . $e->getMessage(), 500001, $e);
        }
    }

    /**
     * 从 MySQL 查询指定类型+日期的最大序号（用于 Redis 初始化校准）
     */
    private static function getMaxSequenceFromMysql(string $typeCode, string $dateStr): int
    {
        try {
            $row = Db::selectOne(
                "SELECT current_seq FROM sys_id_sequence WHERE type_code = ? AND biz_date = ?",
                [$typeCode, $dateStr]
            );
            return (int) ($row->current_seq ?? 0);
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
