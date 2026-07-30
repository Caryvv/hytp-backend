<?php

declare(strict_types=1);

namespace common\services;

use common\enums\ErrorCode;
use common\exceptions\BizException;
use common\models\Shop;
use common\models\ShopCreditLog;

/**
 * 商家处罚：扣信用分 / 封禁 / 解封 + 信用流水查询（管理端，权限点 shop:penalty）。
 *
 * 扣分复用 ShopCreditLog::record()（自动同步 shop.credit_score）；封禁置 shop.status=3，
 * 商家再登录被 MerchantAuthService 拦截（ACCOUNT_DISABLED）。
 */
class ShopPenaltyService
{
    /**
     * 扣信用分（points 取绝对值后为负）。
     *
     * @return array{creditScore:int}
     */
    public function deductCredit(int $shopId, int $points, string $reason): array
    {
        $shop = $this->findShop($shopId);
        $points = abs($points);
        if ($points <= 0) {
            throw new BizException(ErrorCode::PARAM_INVALID, '扣分值须大于 0');
        }
        ShopCreditLog::record($shopId, -$points, $reason, 'penalty');
        $shop->refresh();

        return ['creditScore' => (int) $shop->credit_score];
    }

    /**
     * 封禁店铺（置 status=3，留一条 change=0 的信用流水记因）。
     *
     * @return array{status:int}
     */
    public function ban(int $shopId, string $reason): array
    {
        $shop = $this->findShop($shopId);
        if ((int) $shop->status === Shop::STATUS_BANNED) {
            throw new BizException(ErrorCode::SHOP_STATUS_INVALID, '该店铺已是封禁状态');
        }
        $shop->status = Shop::STATUS_BANNED;
        $shop->save(false, ['status']);
        ShopCreditLog::record($shopId, 0, $reason !== '' ? $reason : '管理员封禁', 'penalty');

        return ['status' => (int) $shop->status];
    }

    /**
     * 解封（仅封禁态可解，置回 status=1）。
     *
     * @return array{status:int}
     */
    public function unban(int $shopId): array
    {
        $shop = $this->findShop($shopId);
        if ((int) $shop->status !== Shop::STATUS_BANNED) {
            throw new BizException(ErrorCode::SHOP_STATUS_INVALID, '仅封禁状态可解封');
        }
        $shop->status = Shop::STATUS_ACTIVE;
        $shop->save(false, ['status']);
        ShopCreditLog::record($shopId, 0, '管理员解封', 'penalty');

        return ['status' => (int) $shop->status];
    }

    /**
     * 某店信用流水（倒序分页）。
     *
     * @return array{list:array<int,array{id:int,change:int,reason:string,refType:string,refId:?int,createdAt:int}>, pagination:array{page:int,pageSize:int,total:int}}
     */
    public function creditLogs(int $shopId, ?int $page, ?int $pageSize): array
    {
        $page = max(1, (int) ($page ?? 1));
        $pageSize = min(50, max(1, (int) ($pageSize ?? 20)));

        $query = ShopCreditLog::find()->where(['shop_id' => $shopId]);
        $total = (int) $query->count();
        $rows = $query->orderBy(['id' => SORT_DESC])
            ->offset(($page - 1) * $pageSize)
            ->limit($pageSize)
            ->all();

        $list = array_map(static fn (ShopCreditLog $l): array => [
            'id' => (int) $l->id,
            'change' => (int) $l->change,
            'reason' => (string) $l->reason,
            'refType' => (string) $l->ref_type,
            'refId' => $l->ref_id !== null ? (int) $l->ref_id : null,
            'createdAt' => (int) $l->created_at,
        ], $rows);

        return [
            'list' => $list,
            'pagination' => ['page' => $page, 'pageSize' => $pageSize, 'total' => $total],
        ];
    }

    private function findShop(int $shopId): Shop
    {
        $shop = Shop::findOne(['id' => $shopId]);
        if ($shop === null) {
            throw new BizException(ErrorCode::SHOP_NOT_FOUND);
        }
        return $shop;
    }
}
