<?php

namespace Ssh\CommonUtil\Enum;

/**
 * 角色常量
 *
 * 统一管理跨服务共享的角色 ID 约定。
 * 跨服务共享：spms-app、spms-system 的 PermissionService
 */
class RoleConstant
{
    /** 超级管理员角色 ID，拥有所有权限 */
    const SUPER_ADMIN_ROLE_ID = 2;
}
