<?php
/**
 * @desc     AES加解密工具类 - 对应Java SymmetricEncoder
 * @author   wrkj
 * @date     2026/6/5
 * @package  Ssh\CommonUtil
 *
 * 对应Java: com.abchina.fintech.smct.base.utils.SymmetricEncoder
 *
 * 关键点:
 *   1. 密钥派生: 使用Java SHA1PRNG算法从种子字符串派生128位AES密钥
 *   2. 加密模式: AES/ECB/PKCS5Padding (等同于PHP AES-128-ECB)
 *   3. 编码方式: 双重Base64 (先BASE64Encoder编码, 再Base64编码)
 */

namespace Ssh\CommonUtil;

class SymmetricEncoder
{
    private const CIPHER_MODE = 'AES-128-ECB';

    /**
     * AES加密
     * 对应Java: aesEncrypt(String encodeRules, String content)
     *
     * 流程: 明文 → AES/ECB/PKCS5Padding加密 → Base64编码 → Base64再编码
     *
     * @param string $encodeRules 加密规则(种子)
     * @param string $content     待加密内容
     * @return string|null 加密结果(双重Base64字符串), 失败返回null
     */
    public static function aesEncrypt(string $encodeRules, string $content): ?string
    {
        try {
            $key = self::deriveAesKeyFromSeed($encodeRules);

            // AES/ECB/PKCS5Padding加密
            $encrypted = openssl_encrypt($content, self::CIPHER_MODE, $key, OPENSSL_RAW_DATA);
            if ($encrypted === false) {
                return null;
            }

            // 双重Base64编码:
            // Java: new BASE64Encoder().encode(byteAes) → 第一次Base64
            // Java: Base64.getEncoder().encodeToString(base64Str.getBytes()) → 第二次Base64
            $base64Once  = base64_encode($encrypted);
            $base64Twice = base64_encode($base64Once);

            return $base64Twice;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * AES解密
     * 对应Java: aesDecrypt(String encodeRules, String content)
     *
     * 流程: 双重Base64字符串 → Base64解码 → Base64再解码 → AES/ECB/PKCS5Padding解密
     *
     * @param string $encodeRules 加密规则(种子)
     * @param string $content     待解密内容(双重Base64字符串)
     * @return string|null 解密结果, 失败返回null
     */
    public static function aesDecrypt(string $encodeRules, string $content): ?string
    {
        try {
            $key = self::deriveAesKeyFromSeed($encodeRules);

            // 双重Base64解码:
            // Java: Base64.getDecoder().decode(content) → 第一次解码
            // Java: new BASE64Decoder().decodeBuffer(decodedStr) → 第二次解码
            $base64Once = base64_decode($content);
            if ($base64Once === false) {
                return null;
            }
            $encrypted = base64_decode($base64Once);
            if ($encrypted === false) {
                return null;
            }

            // AES/ECB/PKCS5Padding解密
            $decrypted = openssl_decrypt($encrypted, self::CIPHER_MODE, $key, OPENSSL_RAW_DATA);
            if ($decrypted === false) {
                return null;
            }

            return $decrypted;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * 从种子派生AES-128密钥
     * 复现Java SecureRandom("SHA1PRNG") + setSeed() 的密钥派生逻辑
     *
     * Java原始逻辑:
     *   SecureRandom secureRandom = SecureRandom.getInstance("SHA1PRNG");
     *   secureRandom.setSeed(encodeRules.getBytes());
     *   keygen.init(128, secureRandom);
     *   SecretKey key = keygen.generateKey();
     *
     * SHA1PRNG算法复现(基于OpenJDK sun.security.provider.SecureRandom):
     *   1. 初始状态: state = 0x00 * 20 (20字节全零)
     *   2. setSeed(seed): state = SHA-1(state || seed), state[0] = 1
     *   3. nextBytes: output = SHA-1(state)
     *   4. 密钥 = output的前16字节(128位)
     *
     * @param string $seed 种子字符串
     * @return string 16字节AES密钥(二进制)
     */
    private static function deriveAesKeyFromSeed(string $seed): string
    {
        // Step 1: 初始状态为20字节全零
        $initialState = str_repeat("\0", 20);

        // Step 2: setSeed → state = SHA-1(initialState || seed)
        $state = sha1($initialState . $seed, true);

        // Step 3: state[0] = 1 (标记已播种)
        $state[0] = "\x01";

        // Step 4: 生成随机输出 → output = SHA-1(state)
        $output = sha1($state, true);

        // Step 5: 取前16字节作为128位AES密钥
        return substr($output, 0, 16);
    }

    /**
     * 获取派生密钥的十六进制表示(调试用)
     *
     * @param string $seed 种子字符串
     * @return string 32字符hex字符串
     */
    public static function getDerivedKeyHex(string $seed): string
    {
        return bin2hex(self::deriveAesKeyFromSeed($seed));
    }
}
