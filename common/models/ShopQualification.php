<?php

declare(strict_types=1);

namespace common\models;

use yii\behaviors\TimestampBehavior;
use yii\db\ActiveRecord;

/**
 * ShopQualification model —— 商家资质材料（对齐 docs/dev/02-数据库设计 §2.2）。
 * 仅 created_at（无 updated_at）。
 *
 * @property int $id
 * @property int $shop_id
 * @property string $qual_type 营业执照/原创证明/授权协议
 * @property string $file_url 材料文件URL（OSS）
 * @property int $status 0待审 1通过 2驳回
 * @property int $created_at
 */
class ShopQualification extends ActiveRecord
{
    public const STATUS_PENDING = 0;
    public const STATUS_PASS = 1;
    public const STATUS_REJECT = 2;

    public static function tableName(): string
    {
        return '{{%shop_qualification}}';
    }

    public function behaviors(): array
    {
        return [
            [
                'class' => TimestampBehavior::class,
                'updatedAtAttribute' => false, // 表无 updated_at
            ],
        ];
    }

    public function rules(): array
    {
        return [
            [['shop_id', 'qual_type', 'file_url'], 'required'],
            [['shop_id'], 'integer'],
            [['qual_type'], 'string', 'max' => 30],
            [['file_url'], 'string', 'max' => 255],
            [['status'], 'in', 'range' => [self::STATUS_PENDING, self::STATUS_PASS, self::STATUS_REJECT]],
            [['status'], 'default', 'value' => self::STATUS_PENDING],
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
            'shopId' => (int) $this->shop_id,
            'qualType' => $this->qual_type,
            'fileUrl' => $this->file_url,
            'status' => (int) $this->status,
            'createdAt' => (int) $this->created_at,
        ];
    }
}
