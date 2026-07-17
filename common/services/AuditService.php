<?php

declare(strict_types=1);

namespace common\services;

use common\enums\ErrorCode;
use common\exceptions\BizException;
use common\models\Product;
use common\models\Shop;

/**
 * 管理端审核业务：商家入驻审核、商品上架审核。
 *
 * - 商家：status 0待审核 → 1正常(通过) / 2驳回(填 audit_remark)。
 * - 商品：status 2审核中 → 1在售(通过) / 3违规下架(驳回，填 audit_remark)。
 *
 * 所有写操作经 AdminLogService 记 operation_log（在控制器层调用，Service 只返数据）。
 */
class AuditService
{
    // ---------------- 商家审核 ----------------

    /**
     * 商家列表（管理端，支持按状态/类型/关键词筛选）。
     *
     * @param array<string,mixed> $in 支持 status/type/keyword/page/pageSize
     * @return array{list:array<int,array>, pagination:array{page:int,pageSize:int,total:int}}
     */
    public function shopList(array $in): array
    {
        $page = max(1, (int) ($in['page'] ?? 1));
        $pageSize = min(50, max(1, (int) ($in['pageSize'] ?? 20)));

        $query = Shop::find();
        if (isset($in['status']) && $in['status'] !== '') {
            $query->andWhere(['status' => (int) $in['status']]);
        }
        if (!empty($in['type'])) {
            $query->andWhere(['type' => (int) $in['type']]);
        }
        if (!empty($in['keyword'])) {
            $query->andWhere(['like', 'name', (string) $in['keyword']]);
        }

        $total = (int) $query->count();
        $rows = $query->orderBy(['id' => SORT_DESC])
            ->offset(($page - 1) * $pageSize)
            ->limit($pageSize)
            ->all();

        return [
            'list' => array_map(static fn (Shop $s): array => $s->toAdminArray(), $rows),
            'pagination' => ['page' => $page, 'pageSize' => $pageSize, 'total' => $total],
        ];
    }

    /**
     * 审核商家。
     *
     * @param bool $pass true 通过（status=正常），false 驳回（status=驳回 + 理由）
     */
    public function auditShop(int $shopId, bool $pass, string $remark = ''): array
    {
        $shop = Shop::findOne(['id' => $shopId]);
        if ($shop === null) {
            throw new BizException(ErrorCode::SHOP_NOT_FOUND);
        }
        if ((int) $shop->status !== Shop::STATUS_PENDING) {
            throw new BizException(ErrorCode::SHOP_STATUS_INVALID, '仅待审核商家可审核');
        }

        if ($pass) {
            $shop->status = Shop::STATUS_ACTIVE;
            $shop->audit_remark = '';
        } else {
            if (trim($remark) === '') {
                throw new BizException(ErrorCode::PARAM_INVALID, '驳回需填写理由');
            }
            $shop->status = Shop::STATUS_REJECTED;
            $shop->audit_remark = $remark;
        }

        if (!$shop->save(false)) {
            throw new BizException(ErrorCode::INTERNAL_ERROR, '审核保存失败');
        }
        return $shop->toAdminArray();
    }

    // ---------------- 商品审核 ----------------

    /**
     * 商品审核队列（默认 status=审核中）。
     *
     * @param array<string,mixed> $in 支持 status/shopId/page/pageSize
     * @return array{list:array<int,array>, pagination:array{page:int,pageSize:int,total:int}}
     */
    public function productList(array $in): array
    {
        $page = max(1, (int) ($in['page'] ?? 1));
        $pageSize = min(50, max(1, (int) ($in['pageSize'] ?? 20)));

        $query = Product::find();
        $status = $in['status'] ?? Product::STATUS_AUDITING;
        if ($status !== '') {
            $query->andWhere(['status' => (int) $status]);
        }
        if (!empty($in['shopId'])) {
            $query->andWhere(['shop_id' => (int) $in['shopId']]);
        }

        $total = (int) $query->count();
        $rows = $query->orderBy(['id' => SORT_ASC]) // 先到先审
            ->offset(($page - 1) * $pageSize)
            ->limit($pageSize)
            ->all();

        return [
            'list' => array_map(static fn (Product $p): array => $p->toListArray(), $rows),
            'pagination' => ['page' => $page, 'pageSize' => $pageSize, 'total' => $total],
        ];
    }

    /**
     * 审核商品。
     *
     * @param bool $pass true 通过（status=在售），false 驳回（status=违规下架 + 理由）
     */
    public function auditProduct(int $productId, bool $pass, string $remark = ''): array
    {
        $product = Product::findOne(['id' => $productId]);
        if ($product === null) {
            throw new BizException(ErrorCode::PRODUCT_NOT_FOUND);
        }
        if ((int) $product->status !== Product::STATUS_AUDITING) {
            throw new BizException(ErrorCode::PRODUCT_STATUS_INVALID, '仅审核中商品可审核');
        }

        if ($pass) {
            $product->status = Product::STATUS_ON;
            $product->audit_remark = '';
        } else {
            if (trim($remark) === '') {
                throw new BizException(ErrorCode::PARAM_INVALID, '驳回需填写理由');
            }
            $product->status = Product::STATUS_VIOLATION;
            $product->audit_remark = $remark;
        }

        if (!$product->save(false)) {
            throw new BizException(ErrorCode::INTERNAL_ERROR, '审核保存失败');
        }
        return $product->toDetailArray();
    }
}
