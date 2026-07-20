<?php

declare(strict_types=1);

namespace common\models;

use yii\behaviors\TimestampBehavior;
use common\base\SocialActiveRecord;

/**
 * Follow model —— 关注关系（对齐 docs/dev/02-数据库设计 §4.3）。
 * 唯一键 (user_id,follow_user_id)。仅 created_at。
 *
 * @property int $id
 * @property int $user_id 关注发起者
 * @property int $follow_user_id 被关注者
 * @property int $created_at
 */
class Follow extends SocialActiveRecord
{
    public static function tableName(): string
    {
        return '{{%follow}}';
    }

    public function behaviors(): array
    {
        return [
            [
                'class' => TimestampBehavior::class,
                'updatedAtAttribute' => false,
            ],
        ];
    }

    public function rules(): array
    {
        return [
            [['user_id', 'follow_user_id'], 'required'],
            [['user_id', 'follow_user_id'], 'integer'],
        ];
    }

    public function getId(): int
    {
        return (int) $this->getPrimaryKey();
    }
}
