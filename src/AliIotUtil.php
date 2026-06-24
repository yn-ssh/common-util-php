<?php

namespace Ssh\CommonUtil;

use AlibabaCloud\Credentials\Credential;
use AlibabaCloud\Credentials\Credential\Config as CredentialConfig;
use AlibabaCloud\Dara\Models\RuntimeOptions;
use AlibabaCloud\SDK\Iot\V20180120\Iot;
use Darabonba\OpenApi\Models\Config;

class AliIotUtil
{
    /**
     * 创建Iot客户端
     *
     * @return Iot
     */
    public static function createClient(): Iot
    {
        $credConfig = new CredentialConfig([
            'type'              => 'access_key',
            'accessKeyId'       => getenv('ALIBABA_CLOUD_ACCESS_KEY_ID'),
            'accessKeySecret'   => getenv('ALIBABA_CLOUD_ACCESS_KEY_SECRET'),
        ]);
        $credential = new Credential($credConfig);
        $config     = new Config(["credential" => $credential]);
        $config->endpoint = "iot.cn-shanghai.aliyuncs.com";
        return new Iot($config);
    }

    /**
     * 发送消息
     *
     * @param string $productKey
     * @param string $deviceName
     * @param mixed  $payload
     * @param string $iotInstanceId
     * @param string $topic
     * @param int    $qos
     * @return array
     */
    public static function publish($productKey, $deviceName, $payload, string $iotInstanceId = '', string $topic = 'get', int $qos = 0): array
    {
        $client  = self::createClient();
        $payload = is_array($payload) ? json_encode($payload, JSON_UNESCAPED_UNICODE) : $payload;

        if ($topic == 'get') {
            $topicFullName = '/' . $productKey . '/' . $deviceName . '/user/' . $topic;
        } else {
            $topicFullName = $topic;
        }

        $config = [
            'iotInstanceId'  => $iotInstanceId,
            'productKey'     => $productKey,
            'messageContent' => base64_encode($payload),
            'topicFullName'  => $topicFullName,
            'qos'            => $qos,
        ];
        $request = new \AlibabaCloud\SDK\Iot\V20180120\Models\PubRequest($config);
        $runtime = new RuntimeOptions([]);

        try {
            $response = $client->pubWithOptions($request, $runtime);
            if ($response->body->success) {
                $data = isset($response->body->data)
                    ? json_decode(json_encode($response->body->data), true)
                    : json_decode(json_encode($response->body), true);
                return ResponseUtil::toArray(200, 'success', $data);
            }
            return ResponseUtil::toArray(400, $response->body->errorMessage);
        } catch (\Exception $e) {
            return ResponseUtil::toArray(500, '[' . $e->getCode() . ']' . $e->getMessage());
        }
    }

    /**
     * 批量发送消息
     *
     * @param string $productKey
     * @param array  $deviceName
     * @param mixed  $payload
     * @param string $iotInstanceId
     * @param string $topic
     * @param string $responseTopicTemplateName
     * @param int    $qos
     * @return array
     */
    public static function batchPublish($productKey, $deviceName, $payload, string $iotInstanceId = '', string $topic = 'get', string $responseTopicTemplateName = '', int $qos = 0): array
    {
        $client  = self::createClient();
        $payload = is_array($payload) ? json_encode($payload, JSON_UNESCAPED_UNICODE) : $payload;

        if (!is_array($deviceName)) {
            return ResponseUtil::toArray(400, 'deviceName设备列表格式错误，请使用数组格式');
        }

        $config = [
            'iotInstanceId'  => $iotInstanceId,
            'productKey'     => $productKey,
            'messageContent' => base64_encode($payload),
            'topicShortName' => $topic,
            'qos'            => $qos,
            'deviceName'     => $deviceName,
        ];
        if (!empty($responseTopicTemplateName)) {
            $config['responseTopicTemplateName'] = $responseTopicTemplateName;
        }

        $request = new \AlibabaCloud\SDK\Iot\V20180120\Models\BatchPubRequest($config);
        $runtime = new RuntimeOptions([]);

        try {
            $response = $client->batchPubWithOptions($request, $runtime);
            if ($response->body->success) {
                $data = isset($response->body->data)
                    ? json_decode(json_encode($response->body->data), true)
                    : json_decode(json_encode($response->body), true);
                return ResponseUtil::toArray(200, 'success', $data);
            }
            return ResponseUtil::toArray(400, $response->body->errorMessage);
        } catch (\Exception $e) {
            return ResponseUtil::toArray(500, '[' . $e->getCode() . ']' . $e->getMessage());
        }
    }

    /**
     * 广播消息
     *
     * @param string $productKey
     * @param mixed  $payload
     * @param string $iotInstanceId
     * @param string $topicFullName
     * @return array
     */
    public static function pubBroadcast($productKey, $payload, string $iotInstanceId = '', string $topicFullName = ''): array
    {
        $client  = self::createClient();
        $payload = is_array($payload) ? json_encode($payload, JSON_UNESCAPED_UNICODE) : $payload;

        $config = [
            "productKey"     => $productKey,
            "iotInstanceId"  => $iotInstanceId,
            "messageContent" => base64_encode($payload),
        ];
        if (!empty($topicFullName)) {
            $config['topicFullName'] = $topicFullName;
        }

        $request = new \AlibabaCloud\SDK\Iot\V20180120\Models\PubBroadcastRequest($config);
        $runtime = new RuntimeOptions([]);

        try {
            $response = $client->pubBroadcastWithOptions($request, $runtime);
            if ($response->body->success) {
                $data = isset($response->body->data)
                    ? json_decode(json_encode($response->body->data), true)
                    : json_decode(json_encode($response->body), true);
                return ResponseUtil::toArray(200, 'success', $data);
            }
            return ResponseUtil::toArray(400, $response->body->errorMessage);
        } catch (\Exception $e) {
            return ResponseUtil::toArray(500, '[' . $e->getCode() . ']' . $e->getMessage());
        }
    }

    /**
     * RPC调用
     *
     * @param string $productKey
     * @param string $deviceName
     * @param mixed  $payload
     * @param string $iotInstanceId
     * @param string $topic
     * @param string $contentType
     * @param int    $timeout
     * @return array
     */
    public static function rRpc($productKey, $deviceName, $payload, string $iotInstanceId = '', string $topic = '', string $contentType = '', int $timeout = 8000): array
    {
        $client  = self::createClient();
        $payload = is_array($payload) ? json_encode($payload, JSON_UNESCAPED_UNICODE) : $payload;

        $config = [
            "iotInstanceId"      => $iotInstanceId,
            "productKey"         => $productKey,
            "deviceName"         => $deviceName,
            "timeout"            => $timeout,
            "requestBase64Byte"  => base64_encode($payload),
        ];
        if (!empty($topic))       $config['topic']       = $topic;
        if (!empty($contentType)) $config['contentType'] = $contentType;

        $request = new \AlibabaCloud\SDK\Iot\V20180120\Models\RRpcRequest($config);
        $runtime = new RuntimeOptions([]);

        try {
            $response = $client->rRpcWithOptions($request, $runtime);
            if ($response->body->success) {
                $data = isset($response->body->data)
                    ? json_decode(json_encode($response->body->data), true)
                    : json_decode(json_encode($response->body), true);
                return ResponseUtil::toArray(200, 'success', $data);
            }
            return ResponseUtil::toArray(400, $response->body->errorMessage);
        } catch (\Exception $e) {
            return ResponseUtil::toArray(500, '[' . $e->getCode() . ']' . $e->getMessage());
        }
    }

    /**
     * 异步RPC调用
     *
     * @param string $productKey
     * @param string $deviceName
     * @param mixed  $payload
     * @param string $iotInstanceId
     * @param string $topicFullName
     * @param string $extInfo
     * @return array
     */
    public static function asyncRRpc($productKey, $deviceName, $payload, string $iotInstanceId = '', string $topicFullName = '', string $extInfo = ''): array
    {
        $client  = self::createClient();
        $payload = is_array($payload) ? json_encode($payload, JSON_UNESCAPED_UNICODE) : $payload;

        $config = [
            "iotInstanceId"  => $iotInstanceId,
            "productKey"     => $productKey,
            "deviceName"     => $deviceName,
            "messageContent" => base64_encode($payload),
        ];
        if (!empty($topicFullName)) $config['topicFullName'] = $topicFullName;
        if (!empty($extInfo))       $config['extInfo']       = $extInfo;

        $request = new \AlibabaCloud\SDK\Iot\V20180120\Models\AsyncRRpcRequest($config);
        $runtime = new RuntimeOptions([]);

        try {
            $response = $client->asyncRRpcWithOptions($request, $runtime);
            if ($response->body->success) {
                $data = isset($response->body->data)
                    ? json_decode(json_encode($response->body->data), true)
                    : json_decode(json_encode($response->body), true);
                return ResponseUtil::toArray(200, 'success', $data);
            }
            return ResponseUtil::toArray(400, $response->body->errorMessage);
        } catch (\Exception $e) {
            return ResponseUtil::toArray(500, '[' . $e->getCode() . ']' . $e->getMessage());
        }
    }

    /**
     * 注册设备
     *
     * @param string $productKey
     * @param string $deviceName
     * @param string $nickname
     * @param string $iotInstanceId
     * @return array
     */
    public static function registerDevice($productKey, $deviceName, $nickname, string $iotInstanceId = ''): array
    {
        $client = self::createClient();
        $config = [
            "iotInstanceId" => $iotInstanceId,
            "productKey"    => $productKey,
            "deviceName"    => $deviceName,
        ];
        if (!empty($nickname)) $config['nickname'] = $nickname;

        $request = new \AlibabaCloud\SDK\Iot\V20180120\Models\RegisterDeviceRequest($config);
        $runtime = new RuntimeOptions([]);

        try {
            $response = $client->registerDeviceWithOptions($request, $runtime);
            if ($response->body->success) {
                $data = isset($response->body->data)
                    ? json_decode(json_encode($response->body->data), true)
                    : json_decode(json_encode($response->body), true);
                return ResponseUtil::toArray(200, 'success', $data);
            }
            return ResponseUtil::toArray(400, $response->body->errorMessage);
        } catch (\Exception $e) {
            return ResponseUtil::toArray(500, '[' . $e->getCode() . ']' . $e->getMessage());
        }
    }

    /**
     * 查询设备已注册的ClientId
     *
     * @param string $iotId
     * @param string $iotInstanceId
     * @return array
     */
    public static function queryClientIds($iotId, string $iotInstanceId = ''): array
    {
        $client = self::createClient();
        $config = ["iotId" => $iotId];
        if (!empty($iotInstanceId)) $config['iotInstanceId'] = $iotInstanceId;

        $request = new \AlibabaCloud\SDK\Iot\V20180120\Models\QueryClientIdsRequest($config);
        $runtime = new RuntimeOptions([]);

        try {
            $response = $client->queryClientIdsWithOptions($request, $runtime);
            if ($response->body->success) {
                $data = isset($response->body->data)
                    ? json_decode(json_encode($response->body->data), true)
                    : json_decode(json_encode($response->body), true);
                return ResponseUtil::toArray(200, 'success', $data);
            }
            return ResponseUtil::toArray(400, $response->body->errorMessage);
        } catch (\Exception $e) {
            return ResponseUtil::toArray(500, '[' . $e->getCode() . ']' . $e->getMessage());
        }
    }

    /**
     * 指定设备注册的ClientId
     *
     * @param string $iotId
     * @param string $clientId
     * @param string $iotInstanceId
     * @return array
     */
    public static function transformClientId($iotId, $clientId, string $iotInstanceId = ''): array
    {
        $client = self::createClient();
        $config = [
            "iotId"    => $iotId,
            "clientId" => $clientId,
        ];
        if (!empty($iotInstanceId)) $config['iotInstanceId'] = $iotInstanceId;

        $request = new \AlibabaCloud\SDK\Iot\V20180120\Models\TransformClientIdRequest($config);
        $runtime = new RuntimeOptions([]);

        try {
            $response = $client->transformClientIdWithOptions($request, $runtime);
            if ($response->body->success) {
                $data = isset($response->body->data)
                    ? json_decode(json_encode($response->body->data), true)
                    : json_decode(json_encode($response->body), true);
                return ResponseUtil::toArray(200, 'success', $data);
            }
            return ResponseUtil::toArray(400, $response->body->errorMessage);
        } catch (\Exception $e) {
            return ResponseUtil::toArray(500, '[' . $e->getCode() . ']' . $e->getMessage());
        }
    }

    /**
     * 删除设备已注册的ClientId
     *
     * @param string $iotId
     * @param string $iotInstanceId
     * @return array
     */
    public static function deleteClientIds($iotId, string $iotInstanceId = ''): array
    {
        $client = self::createClient();
        $config = ["iotId" => $iotId];
        if (!empty($iotInstanceId)) $config['iotInstanceId'] = $iotInstanceId;

        $request = new \AlibabaCloud\SDK\Iot\V20180120\Models\DeleteClientIdsRequest($config);
        $runtime = new RuntimeOptions([]);

        try {
            $response = $client->deleteClientIdsWithOptions($request, $runtime);
            if ($response->body->success) {
                $data = isset($response->body->data)
                    ? json_decode(json_encode($response->body->data), true)
                    : json_decode(json_encode($response->body), true);
                return ResponseUtil::toArray(200, 'success', $data);
            }
            return ResponseUtil::toArray(400, $response->body->errorMessage);
        } catch (\Exception $e) {
            return ResponseUtil::toArray(500, '[' . $e->getCode() . ']' . $e->getMessage());
        }
    }

    /**
     * 查询设备信息
     *
     * @param string $productKey
     * @param string $deviceName
     * @param string $iotId
     * @param string $iotInstanceId
     * @return array
     */
    public static function queryDeviceInfo($productKey, $deviceName, string $iotId = '', string $iotInstanceId = ''): array
    {
        $client = self::createClient();
        $config = [
            "productKey" => $productKey,
            "deviceName" => $deviceName,
        ];
        if (!empty($iotInstanceId)) $config['iotInstanceId'] = $iotInstanceId;
        if (!empty($iotId))         $config['iotId']         = $iotId;

        $request = new \AlibabaCloud\SDK\Iot\V20180120\Models\QueryDeviceInfoRequest($config);
        $runtime = new RuntimeOptions([]);

        try {
            $response = $client->queryDeviceInfoWithOptions($request, $runtime);
            if ($response->body->success) {
                $data = isset($response->body->data)
                    ? json_decode(json_encode($response->body->data), true)
                    : json_decode(json_encode($response->body), true);
                return ResponseUtil::toArray(200, 'success', $data);
            }
            return ResponseUtil::toArray(400, $response->body->errorMessage);
        } catch (\Exception $e) {
            return ResponseUtil::toArray(500, '[' . $e->getCode() . ']' . $e->getMessage());
        }
    }

    /**
     * 查询设备详情
     *
     * @param string $productKey
     * @param string $deviceName
     * @param string $iotId
     * @param string $iotInstanceId
     * @return array
     */
    public static function queryDeviceDetail($productKey, $deviceName, string $iotId = '', string $iotInstanceId = ''): array
    {
        $client = self::createClient();
        $config = [
            "productKey" => $productKey,
            "deviceName" => $deviceName,
        ];
        if (!empty($iotInstanceId)) $config['iotInstanceId'] = $iotInstanceId;
        if (!empty($iotId))         $config['iotId']         = $iotId;

        $request = new \AlibabaCloud\SDK\Iot\V20180120\Models\QueryDeviceDetailRequest($config);
        $runtime = new RuntimeOptions([]);

        try {
            $response = $client->queryDeviceDetailWithOptions($request, $runtime);
            if ($response->body->success) {
                $data = isset($response->body->data)
                    ? json_decode(json_encode($response->body->data), true)
                    : json_decode(json_encode($response->body), true);
                return ResponseUtil::toArray(200, 'success', $data);
            }
            return ResponseUtil::toArray(400, $response->body->errorMessage);
        } catch (\Exception $e) {
            return ResponseUtil::toArray(500, '[' . $e->getCode() . ']' . $e->getMessage());
        }
    }

    /**
     * 删除设备
     *
     * @param string $productKey
     * @param string $deviceName
     * @param string $iotId
     * @param string $iotInstanceId
     * @return array
     */
    public static function deleteDevice($productKey, $deviceName, string $iotId = '', string $iotInstanceId = ''): array
    {
        $client = self::createClient();
        $config = [
            "productKey" => $productKey,
            "deviceName" => $deviceName,
        ];
        if (!empty($iotInstanceId)) $config['iotInstanceId'] = $iotInstanceId;
        if (!empty($iotId))         $config['iotId']         = $iotId;

        $request = new \AlibabaCloud\SDK\Iot\V20180120\Models\DeleteDeviceRequest($config);
        $runtime = new RuntimeOptions([]);

        try {
            $response = $client->deleteDeviceWithOptions($request, $runtime);
            if ($response->body->success) {
                $data = isset($response->body->data)
                    ? json_decode(json_encode($response->body->data), true)
                    : json_decode(json_encode($response->body), true);
                return ResponseUtil::toArray(200, 'success', $data);
            }
            return ResponseUtil::toArray(400, $response->body->errorMessage);
        } catch (\Exception $e) {
            return ResponseUtil::toArray(500, '[' . $e->getCode() . ']' . $e->getMessage());
        }
    }

    /**
     * 查询设备状态
     *
     * @param string $productKey
     * @param string $deviceName
     * @param string $iotInstanceId
     * @return array
     */
    public static function getDeviceStatus($productKey, $deviceName, string $iotInstanceId = ''): array
    {
        $client = self::createClient();
        $config = [
            "productKey" => $productKey,
            "deviceName" => $deviceName,
        ];
        if (!empty($iotInstanceId)) $config['iotInstanceId'] = $iotInstanceId;

        $request = new \AlibabaCloud\SDK\Iot\V20180120\Models\GetDeviceStatusRequest($config);
        $runtime = new RuntimeOptions([]);

        try {
            $response = $client->getDeviceStatusWithOptions($request, $runtime);
            if ($response->body->success) {
                $data = isset($response->body->data)
                    ? json_decode(json_encode($response->body->data), true)
                    : json_decode(json_encode($response->body), true);
                return ResponseUtil::toArray(200, 'success', $data);
            }
            return ResponseUtil::toArray(400, $response->body->errorMessage);
        } catch (\Exception $e) {
            return ResponseUtil::toArray(500, '[' . $e->getCode() . ']' . $e->getMessage());
        }
    }
}
