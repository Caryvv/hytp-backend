<?php

declare(strict_types=1);

namespace common\services;

use common\enums\ErrorCode;
use common\exceptions\BizException;
use common\models\Product;
use common\models\TryonTask;
use common\models\User;
use common\models\UserAvatar;
use common\models\WalletTransaction;

/**
 * AI 试衣业务：提交任务、轮询结果、我的试衣历史、可复用形象管理。
 * 阿里云异步任务由 AiTryonService（经 Node 微服务）执行；本类只管落库 + 状态机。
 */
class TryonService
{
    // 每日免费试衣次数与超额单价（同袍币）。普通用户 vs 有效会员两档。
    private const FREE_QUOTA_NORMAL = 3;
    private const FREE_QUOTA_PREMIUM = 5;
    private const PRICE_NORMAL = 10;
    private const PRICE_PREMIUM = 8;

    /**
     * 提交试衣任务。服装图取商品的 tryon_model_url（商家专挂），无则不可试穿。
     *
     * @param string $personUrl 人物照 OSS URL
     * @throws BizException
     */
    public function createTask(int $userId, int $productId, string $personUrl): array
    {
        $personUrl = trim($personUrl);
        if ($personUrl === '') {
            throw new BizException(ErrorCode::TRYON_IMAGE_INVALID);
        }
        $product = Product::findOne(['id' => $productId]);
        if ($product === null) {
            throw new BizException(ErrorCode::PRODUCT_NOT_FOUND);
        }
        $garmentUrl = trim((string) $product->tryon_model_url);
        if ($garmentUrl === '') {
            throw new BizException(ErrorCode::TRYON_IMAGE_INVALID, '该商品暂不支持试穿');
        }

        // 计费：有效会员每日免费 5 次、超额 8 币；普通用户 3 次、超额 10 币。
        ['freeQuota' => $freeQuota, 'price' => $price] = $this->pricing($userId);

        // 今日已用次数含软删记录，否则删历史即可刷新免费额度白嫖
        // ponytail: count→debit 非原子，并发提交可能多蹭 1~2 次免费；debit 本身原子（不会扣负），
        //           App 单次提交场景价值低，不上行锁。要严格额度再对 user 加锁或唯一约束。
        $needCharge = $this->todayCount($userId) >= $freeQuota;

        $wallet = new WalletService();
        $charged = false;
        if ($needCharge) {
            // 先扣币：余额不足在调阿里云前就抛 BALANCE_NOT_ENOUGH，不浪费一次 API 调用
            $wallet->debit($userId, $price, WalletTransaction::TYPE_CONSUME, [
                'refType' => 'tryon',
                'remark' => 'AI 试衣超额',
            ]);
            $charged = true;
        }

        try {
            $aliyunTaskId = (new AiTryonService())->submit($personUrl, $garmentUrl);

            $task = new TryonTask();
            $task->user_id = $userId;
            $task->product_id = $productId;
            $task->person_url = $personUrl;
            $task->garment_url = $garmentUrl;
            $task->aliyun_task_id = $aliyunTaskId;
            $task->status = TryonTask::STATUS_PENDING;
            if (!$task->save()) {
                throw new BizException(ErrorCode::TRYON_FAILED);
            }
        } catch (\Throwable $e) {
            // 已扣币但提交/落库失败 → 退款，避免白扣
            if ($charged) {
                $wallet->credit($userId, $price, WalletTransaction::TYPE_REFUND, [
                    'refType' => 'tryon_refund',
                    'remark' => 'AI 试衣提交失败退款',
                ]);
            }
            throw $e;
        }
        return $task->toArray();
    }

    /**
     * 试衣配额提示（App 进页面展示："今日剩余免费 X 次 / 超额 Y 币/次"）。
     *
     * @return array{isPremium:bool, freeQuota:int, freeRemaining:int, price:int}
     */
    public function quota(int $userId): array
    {
        ['freeQuota' => $freeQuota, 'price' => $price, 'isPremium' => $isPremium] = $this->pricing($userId);
        $used = $this->todayCount($userId);
        return [
            'isPremium' => $isPremium,
            'freeQuota' => $freeQuota,
            'freeRemaining' => max(0, $freeQuota - $used),
            'price' => $price,
        ];
    }

    /**
     * 按会员状态定价。isPremiumActive() 已含到期判断（过期会员回落普通档）。
     *
     * @return array{isPremium:bool, freeQuota:int, price:int}
     */
    private function pricing(int $userId): array
    {
        $user = User::findOne(['id' => $userId]);
        $isPremium = $user !== null && $user->isPremiumActive();
        return [
            'isPremium' => $isPremium,
            'freeQuota' => $isPremium ? self::FREE_QUOTA_PREMIUM : self::FREE_QUOTA_NORMAL,
            'price' => $isPremium ? self::PRICE_PREMIUM : self::PRICE_NORMAL,
        ];
    }

    /**
     * 今日该用户提交的试衣任务数（含软删，用于免费额度判定）。
     * ponytail: strtotime('today') 用服务器时区做日界；CN 单机部署时区为 Asia/Shanghai，与用户一致。
     */
    private function todayCount(int $userId): int
    {
        return (int) TryonTask::find()
            ->where(['user_id' => $userId])
            ->andWhere(['>=', 'created_at', strtotime('today')])
            ->count();
    }

    /**
     * 轮询任务：已终态直接返回；处理中则查阿里云，成功写永久结果图 URL、失败置状态。
     *
     * @throws BizException
     */
    public function pollTask(int $userId, int $taskId): array
    {
        $task = $this->ownedTask($userId, $taskId);
        if ((int) $task->status !== TryonTask::STATUS_PENDING) {
            return $task->toArray();
        }

        $r = (new AiTryonService())->query($task->aliyun_task_id);
        switch ($r['status']) {
            case 'SUCCEEDED':
                if ($r['imageUrl'] === '') {
                    $task->status = TryonTask::STATUS_FAILED;
                    $task->fail_reason = '结果图为空';
                } else {
                    $task->status = TryonTask::STATUS_SUCCESS;
                    $task->result_url = $r['imageUrl'];
                }
                $task->save(false);
                break;
            case 'FAILED':
            case 'UNKNOWN':   // 作业不存在/状态未知，按失败处理，别让前端白轮到超时
            case 'CANCELED':
                $task->status = TryonTask::STATUS_FAILED;
                $task->fail_reason = $this->failMessage($r['failReason']);
                $task->save(false);
                break;
            // PENDING / PRE-PROCESSING / RUNNING / POST-PROCESSING：保持处理中，前端继续轮询
        }
        return $task->toArray();
    }

    /**
     * 我的试衣历史（倒序分页）。
     *
     * @param array<string,mixed> $in page, pageSize
     * @return array{list:array<int,array>, pagination:array{page:int,pageSize:int,total:int}}
     */
    public function myTasks(int $userId, array $in): array
    {
        $page = max(1, (int) ($in['page'] ?? 1));
        $pageSize = min(50, max(1, (int) ($in['pageSize'] ?? 20)));

        $query = TryonTask::find()->where(['user_id' => $userId, 'deleted' => 0]);
        $total = (int) $query->count();
        $rows = $query->orderBy(['id' => SORT_DESC])
            ->offset(($page - 1) * $pageSize)
            ->limit($pageSize)
            ->all();

        return [
            'list' => array_map(static fn (TryonTask $t): array => $t->toArray(), $rows),
            'pagination' => ['page' => $page, 'pageSize' => $pageSize, 'total' => $total],
        ];
    }

    /** 软删除自己的试衣记录（置 deleted=1，幂等）。 */
    public function deleteTask(int $userId, int $taskId): array
    {
        $task = $this->ownedTask($userId, $taskId);
        if ((int) $task->deleted !== 1) {
            $task->deleted = 1;
            $task->save(false);
        }
        return ['id' => $taskId];
    }

    // ---------------- 可复用形象 ----------------

    /**
     * 我的形象照列表（倒序）。
     *
     * @return array<int,array>
     */
    public function avatars(int $userId): array
    {
        $rows = UserAvatar::find()->where(['user_id' => $userId])->orderBy(['id' => SORT_DESC])->all();
        return array_map(static fn (UserAvatar $a): array => $a->toArray(), $rows);
    }

    /** 新增形象照。 */
    public function addAvatar(int $userId, string $imageUrl): array
    {
        $imageUrl = trim($imageUrl);
        if ($imageUrl === '') {
            throw new BizException(ErrorCode::TRYON_IMAGE_INVALID);
        }
        $avatar = new UserAvatar();
        $avatar->user_id = $userId;
        $avatar->image_url = $imageUrl;
        if (!$avatar->save()) {
            throw new BizException(ErrorCode::PARAM_INVALID, '保存形象失败');
        }
        return $avatar->toArray();
    }

    /** 删除自己的形象照。 */
    public function deleteAvatar(int $userId, int $avatarId): array
    {
        $avatar = UserAvatar::findOne(['id' => $avatarId]);
        if ($avatar === null) {
            throw new BizException(ErrorCode::NOT_FOUND);
        }
        if ((int) $avatar->user_id !== $userId) {
            throw new BizException(ErrorCode::FORBIDDEN);
        }
        $avatar->delete();
        return ['id' => $avatarId];
    }

    // ---------------- 内部 ----------------

    private function ownedTask(int $userId, int $taskId): TryonTask
    {
        $task = TryonTask::findOne(['id' => $taskId]);
        if ($task === null) {
            throw new BizException(ErrorCode::TRYON_TASK_NOT_FOUND);
        }
        if ((int) $task->user_id !== $userId) {
            throw new BizException(ErrorCode::FORBIDDEN);
        }
        return $task;
    }

    /**
     * 阿里云试衣失败错误码 → 用户可读的中文提示。未知码给通用兜底。
     */
    private function failMessage(string $code): string
    {
        return match ($code) {
            'InvalidPerson' => '照片里没有完整的人或有多个人，请换一张单人全身照',
            'InvalidGarment' => '服装图不合规，请联系商家更换试穿素材',
            'InvalidURL' => '图片无法访问，请重试',
            'InvalidInputLength' => '照片尺寸或大小不符合要求，请换一张（边长 150~4096、5KB~5MB）',
            'InvalidParameter' => '试衣参数有误，请重试',
            default => 'AI 生成失败，请重试',
        };
    }
}
