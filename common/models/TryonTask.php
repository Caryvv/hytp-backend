<?php

declare(strict_types=1);

namespace common\models;

use yii\behaviors\TimestampBehavior;
use common\base\SocialActiveRecord;

/**
 * TryonTask model —— AI 试衣任务（记阿里云异步任务状态 + 转存后的结果图）。
 *
 * @property int $id
 * @property int $user_id
 * @property int $product_id
 * @property string $person_url
 * @property string $garment_url
 * @property string $aliyun_task_id
 * @property int $status 0处理中 1成功 2失败
 * @property string $result_url
 * @property string $fail_reason
 * @property int $created_at
 * @property int $updated_at
 */
class TryonTask extends SocialActiveRecord
{
    public const STATUS_PENDING = 0; // 处理中
    public const STATUS_SUCCESS = 1; // 成功
    public const STATUS_FAILED = 2;  // 失败

    public static function tableName(): string
    {
        return '{{%tryon_task}}';
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
            [['user_id', 'product_id', 'person_url', 'garment_url'], 'required'],
            [['user_id', 'product_id', 'status'], 'integer'],
            [['person_url', 'garment_url', 'result_url'], 'string', 'max' => 500],
            [['aliyun_task_id'], 'string', 'max' => 64],
            [['fail_reason'], 'string', 'max' => 255],
            [['status'], 'in', 'range' => [self::STATUS_PENDING, self::STATUS_SUCCESS, self::STATUS_FAILED]],
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
            'productId' => (int) $this->product_id,
            'personUrl' => $this->person_url,
            'garmentUrl' => $this->garment_url,
            'status' => (int) $this->status,
            'resultUrl' => $this->result_url,
            'failReason' => $this->fail_reason,
            'createdAt' => (int) $this->created_at,
        ];
    }
}
