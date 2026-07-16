<?php

// 用户 fixture 数据（对齐 common\models\User / user 表：phone/nickname/status...）。
// 旧脚手架 username/email/password_reset_token 字段已随账号体系改造移除。
// 需要时按新表结构补充测试数据，例如：
// return [
//     [
//         'phone' => '13800000001',
//         'password_hash' => '$2y$13$...', // Yii\Security::generatePasswordHash
//         'nickname' => '测试同袍',
//         'status' => 0, // 0正常 1封禁
//         'auth_key' => 'test_auth_key_0001',
//         'created_at' => 1700000000,
//         'updated_at' => 1700000000,
//     ],
// ];
return [];
