<?php

declare(strict_types=1);

namespace common\services;

use common\enums\ErrorCode;
use common\exceptions\BizException;
use common\models\DepositClaim;
use common\models\Shop;
use common\models\ShopCreditLog;
use common\models\ShopOrder;
use Yii;

/**
 * 品质保障金赔付（用户发起索赔 → 管理端判定 → 成立则平台先行赔付+扣商家保证金）。
 *
 * 用户侧 apply：需登录(aud=app)。管理端 listForAdmin/arbitrate：aud=admin + RBAC deposit:arbitrate。
 */
class DepositClaimService
{
    /** 保障金赔付成立时扣商家的信用分。 */
    private const CREDIT_PENALTY = 10;

    /**
     * 用户对订单发起保障金索赔（山品/质量不符）。
     * 仅已支付且已完成/售后中订单可发起；一单一次进行中的索赔。
     *
     * @param array<string,mixed> $in reason, amount?, evidence?
     */
    public function apply(int $userId, string $orderNo, array $in): array
    {
        $order = ShopOrder::findOne(['order_no' => $orderNo]);
        if ($order === null) {
            throw new BizException(ErrorCode::ORDER_NOT_FOUND);
        }
        if ((int) $order->user_id !== $userId) {
            throw new BizException(ErrorCode::FORBIDDEN);
        }
        // 已支付后（待发货及之后、非取消）才可发起
        if (in_array((int) $order->status, [ShopOrder::STATUS_UNPAID, ShopOrder::STATUS_CANCELLED], true)) {
            throw new BizException(ErrorCode::ORDER_STATUS_INVALID);
        }

        $pending = DepositClaim::findOne(['order_id' => $order->getId(), 'status' => DepositClaim::STATUS_PENDING]);
        if ($pending !== null) {
            throw new BizException(ErrorCode::DEPOSIT_CLAIM_STATUS_INVALID, '已有进行中的保障金申请');
        }

        $claim = new DepositClaim();
        $claim->order_id = $order->getId();
        $claim->shop_id = (int) $order->shop_id;
        $claim->user_id = $userId;
        $claim->reason = (string) ($in['reason'] ?? '');
        $claim->amount = isset($in['amount']) && is_numeric($in['amount'])
            ? (string) $in['amount']
            : $order->pay_amount;
        $claim->evidence = isset($in['evidence']) && is_array($in['evidence']) ? $in['evidence'] : [];
        $claim->status = DepositClaim::STATUS_PENDING;
        if (!$claim->save()) {
            throw new BizException(ErrorCode::PARAM_INVALID, '保障金申请失败');
        }

        return $claim->toArray();
    }

    /**
     * 管理端理赔队列（?status=&page=）。
     *
     * @param array<string,mixed> $in
     * @return array{list:array<int,array>, pagination:array{page:int,pageSize:int,total:int}}
     */
    public function listForAdmin(array $in): array
    {
        $page = max(1, (int) ($in['page'] ?? 1));
        $pageSize = min(50, max(1, (int) ($in['pageSize'] ?? 20)));

        $query = DepositClaim::find();
        if (isset($in['status']) && $in['status'] !== '') {
            $query->andWhere(['status' => (int) $in['status']]);
        }

        $total = (int) $query->count();
        $rows = $query->orderBy(['id' => SORT_DESC])
            ->offset(($page - 1) * $pageSize)
            ->limit($pageSize)
            ->all();

        $list = [];
        foreach ($rows as $row) {
            /** @var DepositClaim $row */
            $view = $row->toArray();
            $order = ShopOrder::findOne(['id' => $row->order_id]);
            $shop = Shop::findOne(['id' => $row->shop_id]);
            $view['orderNo'] = $order !== null ? $order->order_no : '';
            $view['shopName'] = $shop !== null ? $shop->name : '';
            $view['shopDeposit'] = $shop !== null ? $shop->deposit : '0.00';
            $list[] = $view;
        }

        return [
            'list' => $list,
            'pagination' => ['page' => $page, 'pageSize' => $pageSize, 'total' => $total],
        ];
    }

    /**
     * 管理端判定。成立(approve=true)：平台先行赔付(退用户 Mock) + 扣商家 deposit + 扣信用分 + claim 成立。
     * 驳回(false)：claim 驳回。
     *
     * @param array<string,mixed> $in approve(bool), remark
     */
    public function arbitrate(int $adminId, int $claimId, array $in): array
    {
        $claim = DepositClaim::findOne(['id' => $claimId]);
        if ($claim === null) {
            throw new BizException(ErrorCode::DEPOSIT_CLAIM_NOT_FOUND);
        }
        if ((int) $claim->status !== DepositClaim::STATUS_PENDING) {
            throw new BizException(ErrorCode::DEPOSIT_CLAIM_STATUS_INVALID);
        }

        $approve = (bool) ($in['approve'] ?? false);
        $remark = (string) ($in['remark'] ?? '');

        $tx = Yii::$app->db->beginTransaction();
        try {
            if ($approve) {
                $shop = Shop::findOne(['id' => $claim->shop_id]);
                if ($shop !== null) {
                    // 扣商家保障金（不足扣至 0）
                    $deduct = bccomp($shop->deposit, $claim->amount, 2) >= 0
                        ? $claim->amount
                        : $shop->deposit;
                    $shop->deposit = bcsub($shop->deposit, $deduct, 2);
                    $shop->save(false);
                    // 扣信用分（流水 + 同步 credit_score）
                    ShopCreditLog::record(
                        (int) $shop->id,
                        -self::CREDIT_PENALTY,
                        '品质保障金赔付：' . $claim->reason,
                        'deposit_claim',
                        $claim->getId(),
                    );
                }
                // 平台先行赔付退用户为 Mock（此处仅置状态；真实通道走退款）
                $claim->status = DepositClaim::STATUS_APPROVED;
            } else {
                $claim->status = DepositClaim::STATUS_REJECTED;
            }
            $claim->handle_remark = $remark;
            $claim->admin_id = $adminId;
            $claim->save(false);
            $tx->commit();
        } catch (\Throwable $e) {
            $tx->rollBack();
            throw $e;
        }

        return $claim->toArray();
    }
}
