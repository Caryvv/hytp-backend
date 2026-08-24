<?php

declare(strict_types=1);

namespace common\models;

use common\base\SocialActiveRecord;
use yii\behaviors\TimestampBehavior;

/**
 * 用户举报动态（hytp_social 库）。同一用户对同一动态只能举报一次（唯一键 feed_id+user_id）。
 * 处置复用 feed:audit 权限：成立则下架该动态。
 *
 * @property int $id
 * @property int $feed_id
 * @property int $user_id 举报人
 * @property int $reason 举报类型
 * @property string $detail 补充说明
 * @property int $status 0待处理 1成立 2已忽略
 * @property string $handle_remark 处理备注
 * @property int $handled_by 处理管理员 id
 * @property int $created_at
 * @property int $updated_at
 */
class FeedReport extends SocialActiveRecord
{
    // 举报类型
    public const REASON_AD = 1;       // 广告营销
    public const REASON_ILLEGAL = 2;  // 违法违规
    public const REASON_PORN = 3;     // 色情低俗
    public const REASON_ATTACK = 4;   // 人身攻击
    public const REASON_OTHER = 5;    // 其他

    // 处理状态
    public const STATUS_PENDING = 0;  // 待处理
    public const STATUS_ACCEPTED = 1; // 举报成立（动态已下架）
    public const STATUS_IGNORED = 2;  // 已忽略

    public static function tableName(): string
    {
        return '{{%feed_report}}';
    }

    public function behaviors(): array
    {
        return [TimestampBehavior::class];
    }

    public function rules(): array
    {
        return [
            [['feed_id', 'user_id', 'reason'], 'required'],
            [['feed_id', 'user_id', 'reason', 'status', 'handled_by'], 'integer'],
            [['reason'], 'in', 'range' => [
                self::REASON_AD, self::REASON_ILLEGAL, self::REASON_PORN, self::REASON_ATTACK, self::REASON_OTHER,
            ]],
            [['detail', 'handle_remark'], 'string', 'max' => 255],
            [['status'], 'default', 'value' => self::STATUS_PENDING],
        ];
    }

    public function toArray(array $fields = [], array $expand = [], $recursive = true): array
    {
        return [
            'id' => (int) $this->id,
            'feedId' => (int) $this->feed_id,
            'userId' => (int) $this->user_id,
            'reason' => (int) $this->reason,
            'detail' => $this->detail,
            'status' => (int) $this->status,
            'handleRemark' => $this->handle_remark,
            'createdAt' => (int) $this->created_at,
        ];
    }
}
