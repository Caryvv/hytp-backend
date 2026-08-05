<?php

declare(strict_types=1);

namespace common\models;

use yii\behaviors\TimestampBehavior;
use common\base\SocialActiveRecord;

/**
 * ContentSignup model —— 内容报名预约关系。
 * 唯一键 (content_id,user_id)：一人对一内容一条，取消/再报名走 status 翻转。
 *
 * @property int $id
 * @property int $content_id
 * @property int $user_id
 * @property string $name 报名人姓名
 * @property string $phone 报名人手机
 * @property int $quantity 报名人数
 * @property int $status 0已取消 1报名中
 * @property int $created_at
 * @property int $updated_at
 */
class ContentSignup extends SocialActiveRecord
{
    public const STATUS_CANCELLED = 0; // 已取消
    public const STATUS_ACTIVE = 1;    // 报名中

    public static function tableName(): string
    {
        return '{{%content_signup}}';
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
            [['content_id', 'user_id', 'name', 'phone'], 'required'],
            [['content_id', 'user_id', 'quantity'], 'integer'],
            [['quantity'], 'integer', 'min' => 1],
            [['quantity'], 'default', 'value' => 1],
            [['name'], 'string', 'max' => 50],
            [['phone'], 'string', 'max' => 20],
            [['status'], 'in', 'range' => [self::STATUS_CANCELLED, self::STATUS_ACTIVE]],
            [['status'], 'default', 'value' => self::STATUS_ACTIVE],
        ];
    }

    public function getId(): int
    {
        return (int) $this->getPrimaryKey();
    }

    public function toArray(array $fields = [], array $expand = [], $recursive = true): array
    {
        return [
            'id' => $this->getId(),
            'contentId' => (int) $this->content_id,
            'name' => $this->name,
            'phone' => $this->phone,
            'quantity' => (int) $this->quantity,
            'status' => (int) $this->status,
            'createdAt' => (int) $this->created_at,
        ];
    }
}
