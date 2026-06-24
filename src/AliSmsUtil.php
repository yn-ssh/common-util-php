<?php
/**
 * @desc     阿里云短信工具类
 * @author   wrkj
 * @date     2026/5/28 17:13
 * @package  Ssh\CommonUtil
 */

namespace Ssh\CommonUtil;

use AlibabaCloud\Credentials\Credential;
use AlibabaCloud\Credentials\Credential\Config as CredentialConfig;
use AlibabaCloud\Dara\Models\RuntimeOptions;
use AlibabaCloud\SDK\Dysmsapi\V20170525\Dysmsapi;
use Darabonba\OpenApi\Models\Config;

class AliSmsUtil
{
    /**
     * 创建短信客户端
     *
     * @return Dysmsapi
     */
    public static function createClient(): Dysmsapi
    {
        $credConfig = new CredentialConfig([
            'type'              => 'access_key',
            'accessKeyId'       => getenv('ALIYUN.SMS.ACCESS_KEY_ID'),
            'accessKeySecret'   => getenv('ALIYUN.SMS.ACCESS_KEY_SECRET'),
        ]);
        $credential = new Credential($credConfig);
        $config     = new Config(["credential" => $credential]);
        $config->regionId = "cn-shenzhen";
        $config->endpoint = "dysmsapi.aliyuncs.com";
        return new Dysmsapi($config);
    }

    /**
     * 发送短信
     *
     * @param string $phone        手机号
     * @param string $signName     短信签名
     * @param string $templateCode 短信模板Code
     * @param array  $templateParam 模板变量
     * @return array
     */
    public static function sendSms(string $phone, string $signName, string $templateCode, array $templateParam): array
    {
        $client       = self::createClient();
        $sendSmsRequest = new \AlibabaCloud\SDK\Dysmsapi\V20170525\Models\SendSmsRequest([
            "phoneNumbers"  => $phone,
            "signName"      => $signName,
            "templateCode"  => $templateCode,
            "templateParam" => json_encode($templateParam, JSON_UNESCAPED_UNICODE),
        ]);
        $runtime = new RuntimeOptions([]);

        try {
            $resp = $client->sendSmsWithOptions($sendSmsRequest, $runtime);
            if ($resp->body->code == "OK") {
                $data = json_decode(json_encode($resp->body), true);
                return ResponseUtil::toArray(200, $resp->body->message, $data);
            }
            return ResponseUtil::toArray(400, $resp->body->message);
        } catch (\Exception $e) {
            return ResponseUtil::toArray(500, $e->getMessage());
        }
    }
}
