<?php

declare(strict_types=1);

namespace common\models;

use Yii;
use yii\base\NotSupportedException;
use yii\behaviors\TimestampBehavior;
use yii\db\ActiveRecord;
use yii\web\IdentityInterface;

/**
 * User model —— 汉韵同袍用户（对齐 docs/dev/02-数据库设计 §1.1）。
 *
 * 注意 status 语义：0 正常、1 封禁（与 Yii 脚手架默认相反）。
 * 身份鉴权走 JWT（见 common\services\JwtService / behaviors\JwtAuthBehavior），
 * 不使用 session；findIdentityByAccessToken 不启用。
 *
 * @property int $id
 * @property string $phone 手机号（登录）
 * @property string $password_hash 密码哈希
 * @property string $nickname 昵称
 * @property string $avatar 头像 URL
 * @property int $gender 0未知 1男 2女
 * @property string|null $birthday 生日 (Y-m-d)
 * @property string $city 常驻城市
 * @property int $member_level 0普通 1高级会员
 * @property int|null $member_expire_at 会员到期时间戳
 * @property int $status 0正常 1封禁
 * @property int $reg_source 注册来源渠道
 * @property string $auth_key 登录态 key
 * @property int $follower_count 粉丝数
 * @property int $following_count 关注数
 * @property int $feed_count 动态数
 * @property int $created_at
 * @property int $updated_at
 * @property string $password write-only password
 */
class User extends ActiveRecord implements IdentityInterface
{
    // 账号状态
    public const STATUS_ACTIVE = 0;   // 正常
    public const STATUS_BANNED = 1;   // 封禁

    // 性别
    public const GENDER_UNKNOWN = 0;
    public const GENDER_MALE = 1;
    public const GENDER_FEMALE = 2;

    // 会员等级
    public const MEMBER_NORMAL = 0;   // 普通
    public const MEMBER_PREMIUM = 1;  // 高级会员

    public static function tableName(): string
    {
        return '{{%user}}';
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
            [['phone'], 'required'],
            [['phone'], 'string', 'max' => 20],
            [['phone'], 'unique'],
            [['nickname', 'city'], 'string', 'max' => 50],
            [['avatar'], 'string', 'max' => 255],
            [['birthday'], 'date', 'format' => 'php:Y-m-d'],
            [['gender'], 'in', 'range' => [self::GENDER_UNKNOWN, self::GENDER_MALE, self::GENDER_FEMALE]],
            [['gender'], 'default', 'value' => self::GENDER_UNKNOWN],
            [['member_level'], 'in', 'range' => [self::MEMBER_NORMAL, self::MEMBER_PREMIUM]],
            [['member_level'], 'default', 'value' => self::MEMBER_NORMAL],
            [['status'], 'in', 'range' => [self::STATUS_ACTIVE, self::STATUS_BANNED]],
            [['status'], 'default', 'value' => self::STATUS_ACTIVE],
            [['reg_source'], 'integer'],
            [['reg_source'], 'default', 'value' => 0],
        ];
    }

    // ---------------- IdentityInterface ----------------

    /**
     * {@inheritdoc}
     */
    public static function findIdentity($id): ?User
    {
        return static::findOne(['id' => $id, 'status' => self::STATUS_ACTIVE]);
    }

    /**
     * 不通过 access token 查找身份（鉴权走 JWT behavior，自行 setIdentity）。
     * {@inheritdoc}
     */
    public static function findIdentityByAccessToken($token, $type = null): never
    {
        throw new NotSupportedException('"findIdentityByAccessToken" is not implemented; use JWT.');
    }

    public function getId(): int
    {
        return (int) $this->getPrimaryKey();
    }

    public function getAuthKey(): string
    {
        return (string) $this->auth_key;
    }

    public function validateAuthKey($authKey): bool
    {
        return $this->getAuthKey() === $authKey;
    }

    // ---------------- 查询 ----------------

    /**
     * 按手机号查正常状态用户。
     */
    public static function findByPhone(string $phone): ?User
    {
        return static::findOne(['phone' => $phone, 'status' => self::STATUS_ACTIVE]);
    }

    // ---------------- 密码与登录态 ----------------

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

    public function generateAuthKey(): void
    {
        $this->auth_key = Yii::$app->security->generateRandomString();
    }

    /**
     * 是否设置了登录密码（验证码注册可先不设密码）。
     */
    public function hasPassword(): bool
    {
        return $this->password_hash !== '';
    }

    /**
     * 会员是否有效。
     */
    public function isPremiumActive(): bool
    {
        return $this->member_level === self::MEMBER_PREMIUM
            && $this->member_expire_at !== null
            && $this->member_expire_at >= time();
    }

    /**
     * 对外安全字段（用于登录/资料响应，不含 password_hash/auth_key）。
     */
    public function toProfileArray(): array
    {
        return [
            'id' => $this->getId(),
            'phone' => $this->maskPhone(),
            'nickname' => $this->nickname,
            'avatar' => $this->avatar,
            'gender' => (int) $this->gender,
            'birthday' => $this->birthday,
            'city' => $this->city,
            'memberLevel' => (int) $this->member_level,
            'memberExpireAt' => $this->member_expire_at !== null ? (int) $this->member_expire_at : null,
        ];
    }

    /**
     * 同袍公开主页字段（对外，不含手机号/生日/会员信息）。
     * 含社交统计计数；关注态 isFollowed/isSelf 由 Service 拼接。
     */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->getId(),
            'nickname' => $this->nickname,
            'avatar' => $this->avatar,
            'gender' => (int) $this->gender,
            'city' => $this->city,
            'followerCount' => (int) $this->follower_count,
            'followingCount' => (int) $this->following_count,
            'feedCount' => (int) $this->feed_count,
        ];
    }

    /**
     * 手机号脱敏（138****8000）。
     */
    public function maskPhone(): string
    {
        $p = (string) $this->phone;
        if (strlen($p) !== 11) {
            return $p;
        }
        return substr($p, 0, 3) . '****' . substr($p, 7);
    }
}
