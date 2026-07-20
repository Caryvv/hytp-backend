<?php

declare(strict_types=1);

namespace common\models;

use Yii;
use yii\behaviors\TimestampBehavior;
use common\base\AdminActiveRecord;
use yii\web\IdentityInterface;

/**
 * AdminUser model —— 后台管理员（对齐 docs/dev/02-数据库设计 §9.6）。
 * 鉴权走 JWT（aud=admin），RBAC 权限点见 AdminRolePermission。
 *
 * @property int $id
 * @property string $username
 * @property string $password_hash
 * @property string $real_name
 * @property int $role_id
 * @property int $status 0正常 1禁用
 * @property int|null $last_login_at
 * @property int $created_at
 * @property int $updated_at
 */
class AdminUser extends AdminActiveRecord implements IdentityInterface
{
    public const STATUS_ACTIVE = 0;
    public const STATUS_DISABLED = 1;

    public static function tableName(): string
    {
        return '{{%admin_user}}';
    }

    public function behaviors(): array
    {
        return [
            TimestampBehavior::class,
        ];
    }

    public function rules(): array
    {
        return [
            [['username'], 'required'],
            [['username', 'real_name'], 'string', 'max' => 50],
            [['username'], 'unique'],
            [['role_id'], 'integer'],
            [['role_id'], 'default', 'value' => 0],
            [['status'], 'in', 'range' => [self::STATUS_ACTIVE, self::STATUS_DISABLED]],
            [['status'], 'default', 'value' => self::STATUS_ACTIVE],
        ];
    }

    // ---------------- IdentityInterface ----------------

    public static function findIdentity($id): ?AdminUser
    {
        return static::findOne(['id' => $id, 'status' => self::STATUS_ACTIVE]);
    }

    public static function findIdentityByAccessToken($token, $type = null): ?AdminUser
    {
        return null; // 鉴权走 JWT behavior
    }

    public function getId(): int
    {
        return (int) $this->getPrimaryKey();
    }

    public function getAuthKey(): ?string
    {
        return null;
    }

    public function validateAuthKey($authKey): bool
    {
        return false;
    }

    // ---------------- 查询/密码 ----------------

    public static function findByUsername(string $username): ?AdminUser
    {
        return static::findOne(['username' => $username]);
    }

    public function validatePassword(string $password): bool
    {
        if ($this->password_hash === '') {
            return false;
        }
        return Yii::$app->security->validatePassword($password, $this->password_hash);
    }

    public function setPassword(string $password): void
    {
        $this->password_hash = Yii::$app->security->generatePasswordHash($password);
    }

    /**
     * 该管理员拥有的权限点集合。
     *
     * @return string[]
     */
    public function permissionKeys(): array
    {
        return AdminRolePermission::find()
            ->select('permission_key')
            ->where(['role_id' => $this->role_id])
            ->column();
    }

    public function hasPermission(string $key): bool
    {
        return in_array($key, $this->permissionKeys(), true);
    }

    public function toArray(array $fields = [], array $expand = [], $recursive = true): array
    {
        return [
            'id' => $this->getId(),
            'username' => $this->username,
            'realName' => $this->real_name,
            'roleId' => (int) $this->role_id,
            'status' => (int) $this->status,
            'lastLoginAt' => $this->last_login_at !== null ? (int) $this->last_login_at : null,
        ];
    }
}
