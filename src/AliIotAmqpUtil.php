<?php

namespace Ssh\CommonUtil;

class AliIotAmqpUtil
{
    private string $accessKey;
    private string $accessSecret;
    private string $consumerGroupId;
    private string $iotInstanceId;
    private string $userName;
    private string $passWord;

    public function __construct(string $accessKey, string $accessSecret, string $consumerGroupId, string $iotInstanceId)
    {
        $this->accessKey       = $accessKey;
        $this->accessSecret    = $accessSecret;
        $this->consumerGroupId = $consumerGroupId;
        $this->iotInstanceId   = $iotInstanceId;
    }

    /**
     * 获取实例
     *
     * @param string $accessKey
     * @param string $accessSecret
     * @param string $consumerGroupId
     * @param string $iotInstanceId
     * @return AliIotAmqpUtil
     */
    public static function getInstance(string $accessKey, string $accessSecret, string $consumerGroupId, string $iotInstanceId): AliIotAmqpUtil
    {
        return new self($accessKey, $accessSecret, $consumerGroupId, $iotInstanceId);
    }

    /**
     * 获取登录凭证
     *
     * @return $this
     */
    public function getIotLoginPasscode(): static
    {
        $accessKey       = $this->accessKey;
        $accessSecret    = $this->accessSecret;
        $consumerGroupId = $this->consumerGroupId;
        $iotInstanceId   = $this->iotInstanceId;

        // 转换为整数毫秒
        $milliseconds = (int) (hrtime(true) / 100000);
        $clientId     = $iotInstanceId . '_' . $consumerGroupId . '_' . date('Ymd') . '_' . $milliseconds;
        $timeStamp    = round(microtime(true) * 1000);

        // 签名方法：支持hmacmd5，hmacsha1和hmacsha256
        $userName = $clientId . "|authMode=aksign"
            . ",signMethod=" . 'hmacsha1'
            . ",timestamp=" . $timeStamp
            . ",authId=" . $accessKey
            . ",iotInstanceId=" . $iotInstanceId
            . ",consumerGroupId=" . $consumerGroupId
            . "|";

        $signContent = "authId=" . $accessKey . "&timestamp=" . $timeStamp;

        // 计算签名，password组装方法，请参见AMQP客户端接入说明文档
        $password = base64_encode(hash_hmac("sha1", $signContent, $accessSecret, true));

        $this->userName = $userName;
        $this->passWord = $password;

        return $this;
    }

    /**
     * 获取用户名
     *
     * @return string
     */
    public function getUserName(): string
    {
        return $this->userName;
    }

    /**
     * 获取密码
     *
     * @return string
     */
    public function getPassWord(): string
    {
        return $this->passWord;
    }
}
