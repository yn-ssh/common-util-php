<?php
/**
 * @desc     阿里云OSS工具类
 * @author   wrkj
 * @date     2026/5/28 14:18
 * @package  Ssh\CommonUtil
 */

namespace Ssh\CommonUtil;

class AliOssUtil
{
    /**
     * 创建OSS客户端
     *
     * @return \AlibabaCloud\Oss\V2\Client
     */
    public static function createOssClient(): \AlibabaCloud\Oss\V2\Client
    {
        $credentialsProvider = new \AlibabaCloud\Oss\V2\Credentials\StaticCredentialsProvider(
            getenv('ALIYUN.OSS.ACCESS_KEY_ID'),
            getenv('ALIYUN.OSS.ACCESS_KEY_SECRET')
        );
        $cfg = \AlibabaCloud\Oss\V2\Config::loadDefault();
        $cfg->setCredentialsProvider($credentialsProvider);
        $cfg->setRegion(getenv('ALIYUN.OSS.REGION_ID'));
        return new \AlibabaCloud\Oss\V2\Client($cfg);
    }

    /**
     * 获取存储空间下的所有对象
     *
     * @param string      $objectName Bucket名称
     * @param string|null $prefix     对象前缀过滤
     * @return array
     */
    public static function listObjects(string $objectName, string $prefix = null): array
    {
        $client  = self::createOssClient();
        $objects = [];

        try {
            $paginator = new \AlibabaCloud\Oss\V2\Paginator\ListObjectsV2Paginator($client);
            $iter = $paginator->iterPage(
                new \AlibabaCloud\Oss\V2\Models\ListObjectsV2Request(bucket: $objectName, prefix: $prefix)
            );

            foreach ($iter as $page) {
                foreach ($page->contents ?? [] as $object) {
                    $objects[] = [
                        'key'  => $object->key,
                        'type' => $object->type,
                        'size' => $object->size,
                    ];
                }
            }
            return ResponseUtil::toArray(200, '', $objects);
        } catch (\Exception $e) {
            return ResponseUtil::toArray(500, $e->getMessage());
        }
    }

    /**
     * 判断对象是否存在
     *
     * @param string $bucketName Bucket名称
     * @param string $bucketKey  对象Key
     * @return array
     */
    public static function isObjectExist(string $bucketName, string $bucketKey): array
    {
        $client = self::createOssClient();

        try {
            $result = $client->isObjectExist($bucketName, $bucketKey);
            return ResponseUtil::toArray(200, '', ['exist' => $result]);
        } catch (\Exception $e) {
            return ResponseUtil::toArray(500, $e->getMessage());
        }
    }

    /**
     * 简单上传对象
     *
     * @param string $bucketName Bucket名称
     * @param string $bucketKey  对象Key
     * @param string $file       本地文件路径
     * @return array
     */
    public static function putObject(string $bucketName, string $bucketKey, string $file): array
    {
        $client = self::createOssClient();

        try {
            $body    = \AlibabaCloud\Oss\V2\Utils::streamFor(fopen($file, 'r'));
            $request = new \AlibabaCloud\Oss\V2\Models\PutObjectRequest(bucket: $bucketName, key: $bucketKey);
            $request->body = $body;

            $result = $client->putObject($request);
            if ($result->statusCode == 200) {
                $result->etag = str_replace('"', '', $result->etag);
                $data = [
                    'requestId' => $result->requestId,
                    'etag'      => $result->etag,
                ];
                return ResponseUtil::toArray(200, $result->status, $data);
            }
            return ResponseUtil::toArray(400, $result->status);
        } catch (\Exception $e) {
            return ResponseUtil::toArray(500, $e->getMessage());
        }
    }

    /**
     * 获取上传预签名URL
     *
     * @param string $bucketName Bucket名称
     * @param string $bucketKey  对象Key
     * @return array
     */
    public static function putObjectSignUrl(string $bucketName, string $bucketKey): array
    {
        $client = self::createOssClient();

        try {
            $request = new \AlibabaCloud\Oss\V2\Models\PutObjectRequest(bucket: $bucketName, key: $bucketKey);
            $result  = $client->presign($request);

            return ResponseUtil::toArray(200, '', ['url' => $result->url]);
        } catch (\Exception $e) {
            return ResponseUtil::toArray(500, $e->getMessage());
        }
    }

    /**
     * 删除对象
     *
     * @param string $bucketName Bucket名称
     * @param string $bucketKey  对象Key
     * @return array
     */
    public static function deleteObject(string $bucketName, string $bucketKey): array
    {
        $client = self::createOssClient();

        try {
            $request = new \AlibabaCloud\Oss\V2\Models\DeleteObjectRequest(bucket: $bucketName, key: $bucketKey);
            $result  = $client->deleteObject($request);

            if ($result->statusCode == 204) {
                return ResponseUtil::toArray(200, $result->status, ['requestId' => $result->requestId]);
            }
            return ResponseUtil::toArray(400, $result->status);
        } catch (\Exception $e) {
            return ResponseUtil::toArray(500, $e->getMessage());
        }
    }

    /**
     * 获取对象（返回Base64编码内容）
     *
     * @param string $bucketName Bucket名称
     * @param string $bucketKey  对象Key
     * @return array
     */
    public static function getObject(string $bucketName, string $bucketKey): array
    {
        $client = self::createOssClient();

        try {
            $request = new \AlibabaCloud\Oss\V2\Models\GetObjectRequest(bucket: $bucketName, key: $bucketKey);
            $result  = $client->getObject($request);

            if ($result->statusCode == 200) {
                $data = [
                    'requestId'  => $result->requestId,
                    'contentType' => $result->contentType,
                    'size'       => $result->body->getSize(),
                    'base64File' => base64_encode($result->body->getContents()),
                ];
                return ResponseUtil::toArray(200, $result->status, $data);
            }
            return ResponseUtil::toArray(400, $result->status);
        } catch (\Exception $e) {
            return ResponseUtil::toArray(500, $e->getMessage());
        }
    }

    /**
     * 获取对象签名URL
     *
     * @param string $bucketName Bucket名称
     * @param string $bucketKey  对象Key
     * @param int    $expire     过期时间（秒），默认900秒
     * @return array
     */
    public static function getObjectSignUrl(string $bucketName, string $bucketKey, int $expire = 900): array
    {
        $client = self::createOssClient();

        try {
            $request = new \AlibabaCloud\Oss\V2\Models\GetObjectRequest(bucket: $bucketName, key: $bucketKey);
            $result  = $client->presign($request, [
                'expires' => new \DateInterval("PT{$expire}S"),
            ]);

            return ResponseUtil::toArray(200, '', ['url' => $result->url]);
        } catch (\Exception $e) {
            return ResponseUtil::toArray(500, $e->getMessage());
        }
    }
}
