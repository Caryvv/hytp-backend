<?php

declare(strict_types=1);

namespace common\services;

use common\enums\ErrorCode;
use common\exceptions\BizException;
use common\models\Product;
use common\models\TryonTask;
use common\models\UserAvatar;

/**
 * AI 试衣业务：提交任务、轮询结果、我的试衣历史、可复用形象管理。
 * 阿里云异步任务由 AiTryonService（经 Node 微服务）执行；本类只管落库 + 状态机。
 */
class TryonService
{
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
        return $task->toArray();
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
                $task->status = TryonTask::STATUS_FAILED;
                $task->fail_reason = 'AI 生成失败';
                $task->save(false);
                break;
            // PENDING / RUNNING：保持处理中，前端继续轮询
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

        $query = TryonTask::find()->where(['user_id' => $userId]);
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
}
