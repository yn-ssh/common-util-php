<?php
/**
 * @desc     国密(SM2/SM3/SM4)加解密请求处理工具类
 * @author   wrkj
 * @date     2026/6/5
 * @package  Ssh\CommonUtil
 *
 * 基于Java OutRequestDealLogic翻译而来
 * 依赖: lpilp/guomi (SM2/SM3), OpenSSL (SM4, 需1.1.1+)
 * 需要: PHP GMP扩展, OpenSSL 1.1.1+(支持SM4)
 *
 * 加密流程:
 *   1. 生成随机SM4密钥(16位随机字符串→hex→16字节)
 *   2. SM4-CBC(ZeroPadding)加密业务数据
 *   3. SM2加密SM4密钥(公钥,C1C3C2,plainEncoding)
 *   4. 生成随机SM3签名盐,SM2加密签名盐
 *   5. 排序JSON后SM3加签,SM4加密签名结果
 *   6. HTTP POST发送,解密返回数据并验签
 */

namespace Ssh\CommonUtil;

use Rtgm\sm\RtSm2;
use Rtgm\sm\RtSm3;

class SmCryptoUtil
{
    // SM2 曲线参数(用于公钥解压缩)
    private const SM2_P = 'FFFFFFFEFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFF00000000FFFFFFFFFFFFFFFF';
    private const SM2_A = 'FFFFFFFEFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFF00000000FFFFFFFFFFFFFFFC';
    private const SM2_B = '28E9FA9E9D9F5E344D5A9E4BCF6509A7F39789F515AB8F92DDBCBD414D940E93';

    // ==================== 业务方法 ====================

    /**
     * 外部请求发送接口
     *
     * @param array|string $data          需要发送的请求内容
     * @param string       $publicKey     合作方持有的公钥(压缩格式如"02xxx"或非压缩格式如"04xxx")
     * @param string       $url           接收地址
     * @param string       $appId         应用ID
     * @param string       $channelCode   渠道编码
     * @param string       $appChannelCode 应用渠道编码
     * @return array [code, msg, data]
     */
    public static function sendRequestMessage(
        $data,
        string $publicKey,
        string $url,
        string $appId = '',
        string $channelCode = '',
        string $appChannelCode = ''
    ): array {
        try {
            // 解压公钥(Java端使用压缩格式,PHP需转为非压缩格式)
            $uncompressedPublicKey = self::decompressSm2PublicKey($publicKey);

            // 1. 获取请求参数进行加密的盐值(随机16位字符串)
            $random    = self::getRandom(16);
            $randomHex = self::string2HexString($random);

            // 2. 对请求参数进行SM4-CBC加密(ZeroPadding)
            $dataStr     = is_array($data) ? json_encode($data, JSON_UNESCAPED_UNICODE) : $data;
            $encryptData = self::sm4EncryptCbc($dataStr, $randomHex, $randomHex);

            $params = [
                'appId'          => $appId,
                'channelCode'    => $channelCode,
                'appChannelCode' => $appChannelCode,
                'data'           => $data, // 暂存原始数据用于排序加签
            ];

            // 3. 对请求参数进行加密的盐值进行SM2加密
            // 注意: RtSm2::doEncrypt()的C1输出不含"04"前缀, Java hutool的SM2输出含"04"前缀
            // 服务端解密时需要"04"前缀来识别非压缩格式的椭圆曲线点
            $sm2            = new RtSm2('hex', false); // false=不使用固定中间椭圆,每次加密随机
            $encryptSecretKey = '04' . $sm2->doEncrypt($randomHex, $uncompressedPublicKey, C1C3C2);

            // 4. 获取SM3加签需要的盐
            $salt = self::getRandom(10);

            // 5. 对SM3加签需要的盐进行SM2加密
            $encryptSalt = '04' . $sm2->doEncrypt($salt, $uncompressedPublicKey, C1C3C2);

            $params['extension'] = [
                'encrypt-secret-key' => $encryptSecretKey,
                'encrypt-salt'       => $encryptSalt,
            ];

            // 6. 统一时间戳
            $timestamp            = intval(microtime(true) * 1000);
            $params['timestamp']  = $timestamp;
            $params['nonceString'] = '';

            // 7. 对整体请求进行SM3加签(排序后)
            $sortedParams  = self::getSortJson($params);
            $sortedJsonStr = self::jsonEncode($sortedParams);
            $sm3           = new RtSm3();
            $nonceString   = $sm3->digest($timestamp . $salt . $sortedJsonStr);

            // 8. 对SM3加签结果进行SM4加密
            $nonceStringEncrypted = self::sm4EncryptCbc($nonceString, $randomHex, $randomHex);

            // 替换data为加密数据
            $params['data']        = $encryptData;
            $params['nonceString'] = $nonceStringEncrypted;

            // 9. HTTP POST请求
            $result = self::httpPost($url, self::jsonEncode($params));
            if ($result === false || $result === null) {
                return ResponseUtil::toArray(500, 'HTTP请求失败');
            }

            $jsonObject = json_decode($result, true);
            if (!$jsonObject) {
                return ResponseUtil::toArray(500, '返回数据JSON解析失败');
            }

            // 10. 解密返回数据
            $resData      = $jsonObject['data'] ?? '';
            $nonceString2 = $jsonObject['nonceString'] ?? '';

            // 如果data不是字符串(如API返回错误响应时data为JSON对象),则不进行解密,直接返回原始响应
            if (!is_string($resData) || empty($resData)) {
                return ResponseUtil::toArray(200, '未加密响应', $jsonObject);
            }

            try {
                $decryptData = self::sm4DecryptCbc($resData, $randomHex, $randomHex);
                $jsonObject1 = json_decode($decryptData, true);

                // 11. 返回结果验签
                $dataStr1 = self::jsonEncode($jsonObject1);
                $sign     = $sm3->digest($timestamp . $salt . $dataStr1);
                $signRes  = self::sm4DecryptCbc($nonceString2, $randomHex, $randomHex);

                // 将加密的data替换为解密后的业务数据,保留respCode等元数据
                $jsonObject['data'] = $jsonObject1;
                if ($sign === $signRes) {
                    return ResponseUtil::toArray(200, '验签成功', $jsonObject);
                } else {
                    return ResponseUtil::toArray(200, '验签失败', $jsonObject);
                }
            } catch (\Exception $e) {
                // SM4解密失败,返回原始响应
                return ResponseUtil::toArray(200, '解密异常-返回原始响应', $jsonObject);
            }
        } catch (\Exception $e) {
            return ResponseUtil::toArray(500, '[' . $e->getCode() . ']' . $e->getMessage());
        }
    }

    /**
     * 外部请求接收接口
     *
     * @param string $json       请求参数JSON字符串
     * @param string $privateKey 合作方持有的私钥(Java BigInteger格式可能含前导"00")
     * @return array|null
     */
    public static function receiveRequestMessage(string $json, string $privateKey): ?array
    {
        try {
            $resultJson = json_decode($json, true);
            if (!$resultJson) {
                return ResponseUtil::toArray(400, 'JSON解析失败');
            }

            // 去除私钥可能的前导"00"(Java BigInteger.toByteArray()会在高位>0x7F时添加)
            $privateKey = self::stripPrivateKeyPrefix($privateKey);

            // 1. SM2私钥解密出加密key和salt
            $sm2       = new RtSm2('hex');
            $extension          = $resultJson['extension'] ?? [];
            $encryptSecretKeyRes = $extension['encrypt-secret-key'] ?? '';
            $encryptSaltRes      = $extension['encrypt-salt'] ?? '';

            // 解密出加密key和salt
            $encryptSecretKeyRes = $sm2->doDecrypt($encryptSecretKeyRes, $privateKey, true, C1C3C2);
            $encryptSaltRes      = $sm2->doDecrypt($encryptSaltRes, $privateKey, true, C1C3C2);

            // 2. SM4解密出签名
            $nonceStringRes = $resultJson['nonceString'] ?? '';
            $nonceStringRes = self::sm4DecryptCbc($nonceStringRes, $encryptSecretKeyRes, $encryptSecretKeyRes);

            // 3. SM4解密请求参数
            $dataRes     = $resultJson['data'] ?? '';
            $decryptData = self::sm4DecryptCbc($dataRes, $encryptSecretKeyRes, $encryptSecretKeyRes);

            $timestampRes           = strval($resultJson['timestamp'] ?? '');
            $resultJson['data']        = json_decode($decryptData, true);
            $resultJson['nonceString'] = '';

            // 4. 排序JSON后SM3加签
            $sortedJson    = self::getSortJson($resultJson);
            $sortedJsonStr = self::jsonEncode($sortedJson);
            $sm3           = new RtSm3();
            $body          = $timestampRes . $encryptSaltRes . $sortedJsonStr;
            $sign          = $sm3->digest($body);

            // 5. 验签
            if ($nonceStringRes === $sign) {
                // 验签通过，处理业务
                $res = []; // TODO: 业务处理结果
                $resultJson['data'] = $res;
                unset($resultJson['extension']);
                return self::dealResData($resultJson, $encryptSaltRes, $encryptSecretKeyRes);
            }

            return ResponseUtil::toArray(400, '验签失败');
        } catch (\Exception $e) {
            return ResponseUtil::toArray(500, '[' . $e->getCode() . ']' . $e->getMessage());
        }
    }

    /**
     * 处理返回数据(加密响应)
     *
     * @param array  $json        响应数据
     * @param string $encryptSalt SM3签名盐(已解密)
     * @param string $sm4KeyHex   SM4加密key(已解密,hex格式)
     * @return array
     */
    private static function dealResData(array $json, string $encryptSalt, string $sm4KeyHex): array
    {
        $sortedJson = self::getSortJson($json);
        $timestamp  = strval($json['timestamp']);
        $dataStr    = self::jsonEncode($json['data']);

        $sm3 = new RtSm3();
        // SM3签名: timestamp + salt + data
        $iv          = $sm3->digest($timestamp . $encryptSalt . $dataStr);
        // SM4加密签名
        $nonceString = self::sm4EncryptCbc($iv, $sm4KeyHex, $sm4KeyHex);
        // SM4加密数据
        $data        = self::sm4EncryptCbc($dataStr, $sm4KeyHex, $sm4KeyHex);

        $sortedJson['data']        = $data;
        $sortedJson['nonceString'] = $nonceString;

        return $sortedJson;
    }

    // ==================== SM4 加解密 ====================

    /**
     * SM4-CBC加密(ZeroPadding)
     * 对应Java: new SM4(Mode.CBC, Padding.ZeroPadding, key, iv)
     *
     * @param string $data   待加密数据
     * @param string $keyHex 密钥(hex格式,32字符=16字节)
     * @param string $ivHex  IV向量(hex格式,32字符=16字节)
     * @return string 加密结果(hex格式)
     */
    public static function sm4EncryptCbc(string $data, string $keyHex, string $ivHex): string
    {
        if (!in_array('sm4-cbc', openssl_get_cipher_methods())) {
            throw new \RuntimeException('当前OpenSSL版本不支持SM4-CBC,请升级至OpenSSL 1.1.1+');
        }

        $key = hex2bin($keyHex);
        $iv  = hex2bin($ivHex);

        // ZeroPadding: 补齐到16字节块大小(与Java hutool Padding.ZeroPadding一致)
        $blockSize = 16;
        $padLen    = $blockSize - (strlen($data) % $blockSize);
        if ($padLen !== $blockSize) {
            $data .= str_repeat("\0", $padLen);
        }

        $encrypted = openssl_encrypt($data, 'sm4-cbc', $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $iv);
        if ($encrypted === false) {
            throw new \RuntimeException('SM4-CBC加密失败: ' . openssl_error_string());
        }
        return bin2hex($encrypted);
    }

    /**
     * SM4-CBC解密(ZeroPadding)
     * 对应Java: sm4.decryptStr(encryptedHex)
     *
     * @param string $dataHex 待解密数据(hex格式)
     * @param string $keyHex  密钥(hex格式,32字符=16字节)
     * @param string $ivHex   IV向量(hex格式,32字符=16字节)
     * @return string 解密结果
     */
    public static function sm4DecryptCbc(string $dataHex, string $keyHex, string $ivHex): string
    {
        if (!in_array('sm4-cbc', openssl_get_cipher_methods())) {
            throw new \RuntimeException('当前OpenSSL版本不支持SM4-CBC,请升级至OpenSSL 1.1.1+');
        }

        $key  = hex2bin($keyHex);
        $iv   = hex2bin($ivHex);
        $data = hex2bin($dataHex);

        $decrypted = openssl_decrypt($data, 'sm4-cbc', $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $iv);
        if ($decrypted === false) {
            throw new \RuntimeException('SM4-CBC解密失败: ' . openssl_error_string());
        }
        // 移除ZeroPadding的零字节
        return rtrim($decrypted, "\0");
    }

    // ==================== SM2 密钥处理 ====================

    /**
     * 解压缩SM2公钥
     * Java端使用压缩格式公钥(以"02"或"03"开头),PHP需要转换为非压缩格式(x+y坐标)
     *
     * @param string $key 公钥hex字符串
     *                     - 压缩格式: "02"或"03" + 32字节x坐标 (66 hex字符)
     *                     - 非压缩格式: "04" + 32字节x + 32字节y (130 hex字符)
     *                     - 纯坐标格式: 32字节x + 32字节y (128 hex字符)
     * @return string 非压缩格式公钥(x+y坐标,无"04"前缀,128 hex字符)
     */
    public static function decompressSm2PublicKey(string $key): string
    {
        // 纯x+y坐标格式(64字节=128hex字符)
        if (strlen($key) === 128) {
            return $key;
        }

        // "04"前缀的非压缩格式,去掉"04"
        if (str_starts_with($key, '04') && strlen($key) === 130) {
            return substr($key, 2);
        }

        // 压缩格式("02"或"03" + 32字节x坐标 = 66 hex字符)
        if ((str_starts_with($key, '02') || str_starts_with($key, '03')) && strlen($key) === 66) {
            $prefix = substr($key, 0, 2);
            $xHex   = substr($key, 2);

            $p = gmp_init(self::SM2_P, 16);
            $a = gmp_init(self::SM2_A, 16);
            $b = gmp_init(self::SM2_B, 16);
            $x = gmp_init($xHex, 16);

            // y^2 = x^3 + ax + b (mod p)
            $x3        = gmp_powm($x, 3, $p);
            $ax        = gmp_mul($a, $x);
            $ySquared  = gmp_mod(gmp_add(gmp_add($x3, $ax), $b), $p);

            // y = (y^2)^((p+1)/4) mod p (因为p ≡ 3 mod 4, 可用简化公式)
            $exp = gmp_div_q(gmp_add($p, gmp_init(1)), gmp_init(4));
            $y   = gmp_powm($ySquared, $exp, $p);

            // 根据前缀选择y: "02"选择偶y, "03"选择奇y
            $yMod2   = gmp_mod($y, gmp_init(2));
            $isEvenY = gmp_cmp($yMod2, gmp_init(0)) === 0;

            if ($prefix === '02' && !$isEvenY) {
                $y = gmp_sub($p, $y);
            } elseif ($prefix === '03' && $isEvenY) {
                $y = gmp_sub($p, $y);
            }

            $yHex = str_pad(gmp_strval($y, 16), 64, '0', STR_PAD_LEFT);

            return $xHex . $yHex;
        }

        // 无法识别的格式,原样返回
        return $key;
    }

    /**
     * 去除私钥前导"00"
     * Java BigInteger.toByteArray()会在高位>0x7F时添加"00"前缀
     * SM2私钥应为32字节(64hex字符),Java格式可能为33字节(66hex字符,前导"00")
     *
     * @param string $privateKey 私钥hex字符串
     * @return string 处理后的私钥hex字符串(64hex字符)
     */
    public static function stripPrivateKeyPrefix(string $privateKey): string
    {
        if (strlen($privateKey) === 66 && str_starts_with($privateKey, '00')) {
            return substr($privateKey, 2);
        }
        return $privateKey;
    }

    // ==================== 工具方法 ====================

    /**
     * 生成随机字符串(字母+数字)
     *
     * @param int $length 长度
     * @return string
     */
    public static function getRandom(int $length): string
    {
        $ret = '';
        for ($i = 0; $i < $length; $i++) {
            $isChar = random_int(0, 1) === 0;
            if ($isChar) {
                $choice = random_int(0, 1) === 0 ? 65 : 97;
                $ret .= chr($choice + random_int(0, 25));
            } else {
                $ret .= random_int(0, 9);
            }
        }
        return $ret;
    }

    /**
     * 字符串转16进制字符串
     * 示例: "Ab0" → "416230"
     *
     * @param string $strPart 原始字符串
     * @return string hex字符串
     */
    public static function string2HexString(string $strPart): string
    {
        $hexString = '';
        $len       = strlen($strPart);
        for ($i = 0; $i < $len; $i++) {
            $hexString .= sprintf('%02x', ord($strPart[$i]));
        }
        return $hexString;
    }

    /**
     * 十六进制字符串转字节数组(返回二进制字符串)
     *
     * @param string $src hex字符串
     * @return string 二进制字符串
     */
    public static function hexString2Bytes(string $src): string
    {
        return hex2bin($src);
    }

    /**
     * 排序JSON(递归按键名排序)
     * 实现TreeMap类似的排序效果
     *
     * @param mixed $data 数据
     * @return array 排序后的数组
     */
    public static function getSortJson($data): array
    {
        if (!is_array($data)) {
            return [];
        }

        if (self::isAssociative($data)) {
            // 关联数组(JSONObject) - 按键名排序
            ksort($data);
            foreach ($data as $key => $value) {
                if (is_array($value)) {
                    $data[$key] = self::getSortJson($value);
                }
            }
        } else {
            // 索引数组(JSONArray) - 递归排序内部的关联数组
            foreach ($data as $key => $value) {
                if (is_array($value)) {
                    $data[$key] = self::getSortJson($value);
                }
            }
        }

        return $data;
    }

    /**
     * 判断数组是否为关联数组(JSONObject)
     *
     * @param array $arr
     * @return bool
     */
    private static function isAssociative(array $arr): bool
    {
        if (empty($arr)) {
            return false;
        }
        return array_keys($arr) !== range(0, count($arr) - 1);
    }

    /**
     * 编码 JSON字符串(保持与Java JSONObject.toJSONString一致的格式)
     *
     * @param mixed $data 数据
     * @return string JSON字符串
     */
    private static function jsonEncode($data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * HTTP POST请求
     *
     * @param string $url    请求地址
     * @param string $params JSON参数
     * @param string $token  认证token
     * @return string|false 响应内容
     */
    public static function httpPost(string $url, string $params, string $token = '')
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 600);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'token: ' . $token,
        ]);

        $result = curl_exec($ch);

        if (curl_errno($ch)) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException('HTTP请求失败: ' . $error);
        }

        curl_close($ch);
        return $result;
    }
}
