<?php

declare(strict_types=1);

namespace common\services;

use common\models\AdminOperationLog;
use Yii;

/**
 * 后台操作日志（审核/处罚等写操作留痕，对齐 docs/dev/12-管理员端）。
 */
class AdminLogService
{
    /**
     * 记录一条操作日志。
     *
     * @param array<string,mixed>|string|null $detail 结构化详情，数组自动转 JSON
     */
    public function record(int $adminId, string $action, string $module, array|string|null $detail = null): void
    {
        $log = new AdminOperationLog();
        $log->admin_id = $adminId;
        $log->action = $action;
        $log->module = $module;
        if (is_array($detail)) {
            $log->detail = json_encode($detail, JSON_UNESCAPED_UNICODE);
        } else {
            $log->detail = $detail;
        }
        $log->ip = (string) (Yii::$app->request->userIP ?? '');
        // 日志失败不应阻断主流程
        if (!$log->save()) {
            Yii::error('admin op log save fail: ' . json_encode($log->getErrors(), JSON_UNESCAPED_UNICODE), __METHOD__);
        }
    }

    /**
     * 操作日志分页查询。
     *
     * @param array{adminId:?int, module:?string, page:?int, pageSize:?int} $in
     * @return array{list:array<int,array>, pagination:array{page:int,pageSize:int,total:int}}
     */
    public function list(array $in): array
    {
        $page = max(1, (int) ($in['page'] ?? 1));
        $pageSize = min(50, max(1, (int) ($in['pageSize'] ?? 20)));

        $query = AdminOperationLog::find();
        if (!empty($in['adminId'])) {
            $query->andWhere(['admin_id' => (int) $in['adminId']]);
        }
        if (!empty($in['module'])) {
            $query->andWhere(['module' => (string) $in['module']]);
        }

        $total = (int) $query->count();
        $rows = $query->orderBy(['id' => SORT_DESC])
            ->offset(($page - 1) * $pageSize)
            ->limit($pageSize)
            ->all();

        return [
            'list' => array_map(static fn (AdminOperationLog $l): array => $l->toArray(), $rows),
            'pagination' => ['page' => $page, 'pageSize' => $pageSize, 'total' => $total],
        ];
    }
}
