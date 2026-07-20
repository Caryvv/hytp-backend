<?php

declare(strict_types=1);

namespace common\models;

use Yii;
use yii\behaviors\TimestampBehavior;
use common\base\ShopActiveRecord;
use yii\web\IdentityInterface;

/**
 * Shop model —— 商家/店铺（对齐 docs/dev/02-数据库设计 §2.1）。
 *
 * 商家端鉴权走 JWT（aud=merchant）。status：0待审核 1正常 2驳回 3封禁。
 *
 * @property int $id
 * @property string $account 商家登录账号
 * @property string $password_hash 密码哈希
 * @property string $name 店铺名
 * @property string $logo 店铺 logo URL
 * @property int $type 1原创品牌 2手作匠人 3租赁 4妆造 5摄影 6文旅 7非遗
 * @property string $region 产区
 * @property string $contact_name 联系人
 * @property string $contact_phone 联系电话
 * @property int $credit_score 信用分（初始100）
 * @property string $deposit 品质保障金余额
 * @property int $status 0待审核 1正常 2驳回 3封禁
 * @property string $audit_remark 驳回理由
 * @property int $created_at
 * @property int $updated_at
 */
class Shop extends ShopActiveRecord implements IdentityInterface
{
    // 审核/账号状态
    public const STATUS_PENDING = 0;  // 待审核
    public const STATUS_ACTIVE = 1;   // 正常
    public const STATUS_REJECTED = 2; // 驳回
    public const STATUS_BANNED = 3;   // 封禁

    // 商家类型
    public const TYPE_ORIGINAL = 1;   // 原创品牌
    public const TYPE_HANDMADE = 2;   // 手作匠人
    public const TYPE_RENTAL = 3;     // 租赁
    public const TYPE_MAKEUP = 4;     // 妆造
    public const TYPE_PHOTO = 5;      // 摄影
    public const TYPE_TRAVEL = 6;     // 文旅
    public const TYPE_HERITAGE = 7;   // 非遗

    public const CREDIT_INITIAL = 100;

    public static function tableName(): string
    {
        return '{{%shop}}';
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
            [['account'], 'required'],
            [['account'], 'string', 'max' => 50],
            [['account'], 'unique'],
            [['name'], 'string', 'max' => 100],
            [['logo', 'audit_remark'], 'string', 'max' => 255],
            [['region', 'contact_name'], 'string', 'max' => 50],
            [['contact_phone'], 'string', 'max' => 20],
            [['type'], 'in', 'range' => [
                self::TYPE_ORIGINAL, self::TYPE_HANDMADE, self::TYPE_RENTAL, self::TYPE_MAKEUP,
                self::TYPE_PHOTO, self::TYPE_TRAVEL, self::TYPE_HERITAGE,
            ]],
            [['type'], 'default', 'value' => self::TYPE_ORIGINAL],
            [['status'], 'in', 'range' => [
                self::STATUS_PENDING, self::STATUS_ACTIVE, self::STATUS_REJECTED, self::STATUS_BANNED,
            ]],
            [['status'], 'default', 'value' => self::STATUS_PENDING],
            [['credit_score'], 'integer'],
            [['credit_score'], 'default', 'value' => self::CREDIT_INITIAL],
            [['deposit'], 'number', 'min' => 0],
            [['deposit'], 'default', 'value' => 0],
        ];
    }

    // ---------------- IdentityInterface（商家端登录态） ----------------

    public static function findIdentity($id): ?Shop
    {
        return static::findOne(['id' => $id]);
    }

    public static function findIdentityByAccessToken($token, $type = null): ?Shop
    {
        return null; // 鉴权走 JWT behavior，自行 setIdentity
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

    // ---------------- 查询 ----------------

    public static function findByAccount(string $account): ?Shop
    {
        return static::findOne(['account' => $account]);
    }

    // ---------------- 密码 ----------------

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

    public function isActive(): bool
    {
        return (int) $this->status === self::STATUS_ACTIVE;
    }

    // ---------------- 输出 ----------------

    /**
     * 商家端自用（含审核状态、联系方式）。
     */
    public function toMerchantArray(): array
    {
        return [
            'id' => $this->getId(),
            'account' => $this->account,
            'name' => $this->name,
            'logo' => $this->logo,
            'type' => (int) $this->type,
            'region' => $this->region,
            'contactName' => $this->contact_name,
            'contactPhone' => $this->contact_phone,
            'creditScore' => (int) $this->credit_score,
            'deposit' => $this->deposit,
            'status' => (int) $this->status,
            'auditRemark' => $this->audit_remark,
            'createdAt' => (int) $this->created_at,
        ];
    }

    /**
     * 用户端公开（店铺主页，不含账号/联系方式）。
     */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->getId(),
            'name' => $this->name,
            'logo' => $this->logo,
            'type' => (int) $this->type,
            'region' => $this->region,
            'creditScore' => (int) $this->credit_score,
        ];
    }

    /**
     * 管理端列表（含审核字段）。
     */
    public function toAdminArray(): array
    {
        return [
            'id' => $this->getId(),
            'account' => $this->account,
            'name' => $this->name,
            'type' => (int) $this->type,
            'region' => $this->region,
            'contactName' => $this->contact_name,
            'contactPhone' => $this->contact_phone,
            'creditScore' => (int) $this->credit_score,
            'deposit' => $this->deposit,
            'status' => (int) $this->status,
            'auditRemark' => $this->audit_remark,
            'createdAt' => (int) $this->created_at,
        ];
    }
}
