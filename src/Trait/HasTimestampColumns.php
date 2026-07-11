<?php
/**
 * 统一时间戳列名 Trait
 * 所有模型统一使用 gmt_create/gmt_modified/delete_time 作为时间戳列名
 */

namespace Ssh\CommonUtil\Traits;

trait HasTimestampColumns
{
    const CREATED_AT = 'gmt_create';
    const UPDATED_AT = 'gmt_modified';
    const DELETED_AT = 'delete_time';
}
