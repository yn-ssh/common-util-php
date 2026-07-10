# ssh/common-util

基于 Webman 框架的 PHP 常用工具类集合，包含阿里云 IoT/OSS/SMS、国密 SM2/SM3/SM4 加解密、AES 加解密、商米云打印等工具。

## 安装

```bash
composer require ssh/common-util
```

## 环境要求

- PHP >= 8.1
- workerman/webman-framework ^2.0
- ext-curl
- ext-openssl (1.1.1+，SM4 需要)
- ext-gmp
- ext-gd（商米云打印图片处理需要，可选）
- ext-redis（IdGenerator 需要，可选）
- webman/redis + webman/database（IdGenerator 使用 `support\Redis` 和 `support\Db`，可选）

## 工具类一览

| 类名 | 说明 |
|------|------|
| `ResponseUtil` | 统一响应输出 |
| `AliIotAmqpUtil` | 阿里云 IoT AMQP 认证凭证生成 |
| `AliIotUtil` | 阿里云 IoT 消息推送、设备管理 |
| `AliOssUtil` | 阿里云 OSS 对象存储操作 |
| `AliSmsUtil` | 阿里云短信发送 |
| `SmCryptoUtil` | 国密 SM2/SM3/SM4 加解密 |
| `SunmiCloudPrinter` | 商米云打印机 ESC/POS 指令 |
| `SymmetricEncoder` | AES 加解密（兼容 Java SHA1PRNG 密钥派生） |
| `IdGenerator` | 唯一号生成器（Redis 原子自增 + MySQL 兜底） |

---

## ResponseUtil - 统一响应输出

基于 Webman 的 `support\Response`，构建统一格式的响应数组，可直接作为控制器返回值。

```php
use Ssh\CommonUtil\ResponseUtil;

// 基础格式: ['code' => 200, 'msg' => 'success', 'data' => [...]]
$result = ResponseUtil::toArray(200, 'success', ['id' => 1]);

// 返回 webman Response 对象（直接在控制器中 return）
return ResponseUtil::success('操作成功', ['id' => 1]);   // 200
return ResponseUtil::fail('参数错误');                     // 400
return ResponseUtil::error();                              // 500
return ResponseUtil::notFound();                           // 404
return ResponseUtil::unLoggedIn();                         // 401
return ResponseUtil::notBind();                            // 402
return ResponseUtil::unauthorized();                       // 403
return ResponseUtil::notFoundController();                 // 404
return ResponseUtil::notImplemented();                     // 501
return ResponseUtil::serviceUnavailable();                 // 503
return ResponseUtil::tooManyRequests();                    // 429
```

> `error()`、`notFound()`、`unLoggedIn()` 等方法内部使用 `trans()` 读取国际化翻译，需配合 `symfony/translation` 使用。

---

## AliIotAmqpUtil - 阿里云 IoT AMQP 认证

用于生成阿里云 IoT 平台 AMQP 客户端连接所需的用户名和密码。

```php
use Ssh\CommonUtil\AliIotAmqpUtil;

$amqp = AliIotAmqpUtil::getInstance(
    accessKey: 'your-access-key',
    accessSecret: 'your-access-secret',
    consumerGroupId: 'your-consumer-group-id',
    iotInstanceId: 'your-iot-instance-id'
);

// 生成凭证
$amqp->getIotLoginPasscode();

$userName = $amqp->getUserName();
$passWord = $amqp->getPassWord();

// 将 $userName 和 $passWord 传入 AMQP 客户端连接配置中
```

---

## AliIotUtil - 阿里云 IoT 消息推送与设备管理

需要配置环境变量：

```
ALIBABA_CLOUD_ACCESS_KEY_ID=your-key-id
ALIBABA_CLOUD_ACCESS_KEY_SECRET=your-key-secret
```

```php
use Ssh\CommonUtil\AliIotUtil;

// 发送消息到单个设备
$result = AliIotUtil::publish(
    productKey: 'your-product-key',
    deviceName: 'your-device-name',
    payload: ['cmd' => 'restart'],  // 数组或字符串
    iotInstanceId: 'your-instance-id',
    topic: 'get',   // 自定义 Topic 后缀，默认 'get'
    qos: 0          // QoS0 或 QoS1
);

// 批量发送消息（deviceName 必须为数组）
$result = AliIotUtil::batchPublish(
    productKey: 'your-product-key',
    deviceName: ['device1', 'device2'],
    payload: ['cmd' => 'update'],
    iotInstanceId: 'your-instance-id'
);

// 广播消息
$result = AliIotUtil::pubBroadcast(
    productKey: 'your-product-key',
    payload: ['msg' => 'hello all'],
    iotInstanceId: 'your-instance-id'
);

// RPC 同步调用
$result = AliIotUtil::rRpc(
    productKey: 'your-product-key',
    deviceName: 'your-device-name',
    payload: ['cmd' => 'status'],
    iotInstanceId: 'your-instance-id',
    timeout: 8000
);

// 异步 RPC 调用
$result = AliIotUtil::asyncRRpc(
    productKey: 'your-product-key',
    deviceName: 'your-device-name',
    payload: ['cmd' => 'notify']
);

// 注册设备
$result = AliIotUtil::registerDevice('product-key', 'device-name', 'nickname');

// 查询设备信息
$result = AliIotUtil::queryDeviceInfo('product-key', 'device-name');

// 查询设备详情
$result = AliIotUtil::queryDeviceDetail('product-key', 'device-name');

// 查询设备状态
$result = AliIotUtil::getDeviceStatus('product-key', 'device-name');

// 删除设备
$result = AliIotUtil::deleteDevice('product-key', 'device-name');

// ClientId 管理
$result = AliIotUtil::queryClientIds('iot-id');
$result = AliIotUtil::transformClientId('iot-id', 'client-id');
$result = AliIotUtil::deleteClientIds('iot-id');
```

> 所有方法返回统一格式数组：`['code' => 200/400/500, 'msg' => '...', 'data' => [...]]`

---

## AliOssUtil - 阿里云 OSS 对象存储

需要配置环境变量：

```
ALIYUN.OSS.ACCESS_KEY_ID=your-key-id
ALIYUN.OSS.ACCESS_KEY_SECRET=your-key-secret
ALIYUN.OSS.REGION_ID=cn-shenzhen
```

```php
use Ssh\CommonUtil\AliOssUtil;

// 列出 Bucket 下所有对象
$result = AliOssUtil::listObjects('my-bucket', prefix: 'images/');
// $result['data'] => [['key' => 'images/a.jpg', 'type' => 'Normal', 'size' => 1024], ...]

// 判断对象是否存在
$result = AliOssUtil::isObjectExist('my-bucket', 'images/a.jpg');
// $result['data'] => ['exist' => true]

// 上传文件
$result = AliOssUtil::putObject('my-bucket', 'images/a.jpg', '/local/path/a.jpg');

// 获取上传预签名 URL（前端直传用）
$result = AliOssUtil::putObjectSignUrl('my-bucket', 'images/b.jpg');
// $result['data'] => ['url' => 'https://...']

// 下载对象（返回 Base64 编码内容）
$result = AliOssUtil::getObject('my-bucket', 'images/a.jpg');
// $result['data'] => ['requestId' => '...', 'contentType' => '...', 'size' => 1024, 'base64File' => '...']

// 获取下载预签名 URL
$result = AliOssUtil::getObjectSignUrl('my-bucket', 'images/a.jpg', expire: 3600);

// 删除对象
$result = AliOssUtil::deleteObject('my-bucket', 'images/a.jpg');
```

---

## AliSmsUtil - 阿里云短信

需要配置环境变量：

```
ALIYUN.SMS.ACCESS_KEY_ID=your-key-id
ALIYUN.SMS.ACCESS_KEY_SECRET=your-key-secret
```

```php
use Ssh\CommonUtil\AliSmsUtil;

$result = AliSmsUtil::sendSms(
    phone: '13800138000',
    signName: '你的签名',
    templateCode: 'SMS_123456789',
    templateParam: ['code' => '123456']
);

if ($result['code'] == 200) {
    echo '发送成功';
}
```

---

## SmCryptoUtil - 国密 SM2/SM3/SM4 加解密

完整实现了与 Java 端互通的国密加解密流程，支持请求发送和接收两种场景。

**依赖：** `lpilp/guomi`、PHP GMP 扩展、OpenSSL 1.1.1+

```php
use Ssh\CommonUtil\SmCryptoUtil;

// ===== 发送加密请求 =====
$result = SmCryptoUtil::sendRequestMessage(
    data: ['orderId' => '123', 'amount' => 100],
    publicKey: '02a1b2c3d4...',     // 对方公钥（压缩格式或非压缩格式均可）
    url: 'https://api.example.com/endpoint',
    appId: 'your-app-id',
    channelCode: 'your-channel',
    appChannelCode: 'your-app-channel'
);
// $result => ['code' => 200, 'msg' => '验签成功', 'data' => [...]]

// ===== 接收并解密请求 =====
$decrypted = SmCryptoUtil::receiveRequestMessage(
    json: $requestBodyJson,          // 收到的请求 JSON 字符串
    privateKey: 'your-private-key'   // 自己的私钥
);
```

**单独使用 SM4 加解密：**

```php
// SM4-CBC 加密
$encrypted = SmCryptoUtil::sm4EncryptCbc('hello world', $keyHex, $ivHex);

// SM4-CBC 解密
$decrypted = SmCryptoUtil::sm4DecryptCbc($encryptedHex, $keyHex, $ivHex);
```

**SM2 公钥处理：**

```php
// 压缩公钥 → 非压缩公钥
$uncompressed = SmCryptoUtil::decompressSm2PublicKey('02a1b2c3...');

// 去除 Java 私钥前导 "00"
$cleanKey = SmCryptoUtil::stripPrivateKeyPrefix('00abcdef...');
```

**工具方法：**

```php
$random    = SmCryptoUtil::getRandom(16);              // 随机字符串
$hex       = SmCryptoUtil::string2HexString('Ab0');     // "416230"
$sorted    = SmCryptoUtil::getSortJson($array);          // 递归按键名排序
```

---

## SunmiCloudPrinter - 商米云打印机

支持 ESC/POS 指令构建打印内容，并通过商米云 API 推送到打印机。

构造时可传入 PSR-3 Logger 实现日志记录：

```php
use Ssh\CommonUtil\SunmiCloudPrinter;

// 不传 logger 则不记录日志
$printer = new SunmiCloudPrinter(dots_per_line: 384, logger: $psr3Logger);

// ===== 构建打印内容 =====
$printer->restoreDefaultSettings();
$printer->setUtf8Mode(1);

// 标题居中、放大
$printer->setAlignment(SunmiCloudPrinter::ALIGN_CENTER);
$printer->setCharacterSize(2, 2);
$printer->appendText("收银小票");
$printer->lineFeed();

// 正文左对齐、正常大小
$printer->restoreDefaultSettings();
$printer->setUtf8Mode(1);
$printer->setAlignment(SunmiCloudPrinter::ALIGN_LEFT);
$printer->appendText("商品: 拿铁咖啡  x1  ¥28.00");
$printer->lineFeed();

// 分列打印
$printer->setupColumns(
    [192, SunmiCloudPrinter::ALIGN_LEFT, 0],
    [192, SunmiCloudPrinter::ALIGN_RIGHT, 0]
);
$printer->printInColumns("合计:", "¥28.00");
$printer->lineFeed(2);

// 二维码
$printer->setAlignment(SunmiCloudPrinter::ALIGN_CENTER);
$printer->appendQRcode(4, 1, "https://example.com/order/123");
$printer->lineFeed(3);

$printer->cutPaper(true);

// ===== 推送到打印机 =====
$printer->pushContent(
    sn: '打印机SN号',
    trade_no: 'ORDER_001',
    order_type: 1,
    count: 1
);

// ===== 其他设备管理接口 =====
$printer->onlineStatus('打印机SN号');      // 查询在线状态
$printer->clearPrintJob('打印机SN号');     // 清除打印队列
$printer->printStatus('ORDER_001');        // 查询打印状态
$printer->bindShop('打印机SN号', 'shop1'); // 绑定店铺
$printer->unbindShop('打印机SN号', 'shop1'); // 解绑店铺
```

---

## SymmetricEncoder - AES 加解密

兼容 Java `SHA1PRNG` 密钥派生 + `AES/ECB/PKCS5Padding` + 双重 Base64 编码的加解密逻辑。

```php
use Ssh\CommonUtil\SymmetricEncoder;

$seed = 'your-secret-seed';

// 加密
$encrypted = SymmetricEncoder::aesEncrypt($seed, 'hello world');
// 返回双重 Base64 字符串

// 解密
$decrypted = SymmetricEncoder::aesDecrypt($seed, $encrypted);
// 返回 "hello world"

// 调试：查看派生密钥的十六进制值
$keyHex = SymmetricEncoder::getDerivedKeyHex($seed);
// 返回 32 字符 hex 字符串，如 "a1b2c3d4..."
```

---

## IdGenerator - 唯一号生成器

基于 Redis 原子自增 + MySQL 兜底的分布式唯一号生成器，支持 Swoole 协程异步回写。

**生成格式：** `YYYYMMDD` + `类型码(2位)` + `当日序号(补零)`
**示例：** `202607140100000001`（18位，类型 01=用户，序号 1）

**依赖：** `webman/redis`、`webman/database`（可选，Redis 不可用时自动回退 MySQL）

### MySQL 表结构

使用前需创建序号表：

```sql
CREATE TABLE `sys_id_sequence` (
    `type_code`   VARCHAR(10)  NOT NULL COMMENT '类型码',
    `biz_date`    VARCHAR(8)   NOT NULL COMMENT '业务日期 YYYYMMDD',
    `current_seq` BIGINT       NOT NULL DEFAULT 0 COMMENT '当前序号',
    `updated_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`type_code`, `biz_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='唯一号序列表';
```

### 基本用法

```php
use Ssh\CommonUtil\IdGenerator;

// 生成单个唯一号（类型名方式）
$id = IdGenerator::next('user');
// => "202607140100000001"

// 生成单个唯一号（类型码方式）
$id = IdGenerator::next('01');
// => "202607140100000001"

// 指定总长度（默认 18 位）
$id = IdGenerator::next('org', totalLength: 20);
// => "20260714020000000001"（20 位）

// 指定日期
$id = IdGenerator::next('user', date: '2026-07-15');
// => "202607150100000001"
```

### 内置类型码

| 类型名 | 类型码 | 常量 |
|--------|--------|------|
| `user` | `01` | `IdGenerator::TYPE_USER` |
| `org`  | `02` | `IdGenerator::TYPE_ORG` |
| `app`  | `03` | `IdGenerator::TYPE_APP` |

也支持直接传入 2 位数字类型码（如 `'99'`）来自定义类型。

### 批量生成

```php
// 批量生成 10 个用户 ID
$ids = IdGenerator::batch('user', count: 10);
// => ["202607140100000001", "202607140100000002", ...]
```

### 解析唯一号

```php
$info = IdGenerator::parse('202607140100000001');
// => [
//     'date'      => '2026-07-14',
//     'type_code' => '01',
//     'sequence'  => 1,
//     'type_name' => 'user',
// ]
```

### 工作原理

1. **Redis 优先**：通过 `INCR` 原子自增获取序号，高性能无锁
2. **Redis 初始化校准**：首次创建 key 时从 MySQL 读取最大序号，防止 Redis 重启后序号冲突
3. **异步回写 MySQL**：Redis 生成成功后异步回写 `sys_id_sequence` 表保持同步（Swoole 协程优先，无协程时同步写入）
4. **MySQL 兜底**：Redis 不可用时自动回退到 MySQL `INSERT ... ON DUPLICATE KEY UPDATE` 原子自增

---

## 许可证

MIT License
