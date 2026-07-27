<?php

declare(strict_types=1);

namespace common\services;

use common\enums\ErrorCode;
use common\exceptions\BizException;
use common\models\Feed;
use common\models\Product;
use common\models\Shop;
use common\models\User;

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

    // ---------------- 动态巡查（先发后审：默认正常，违规下架，可恢复） ----------------

    /**
     * 动态巡查列表（默认 status=正常）。支持按状态/作者筛选。
     *
     * @param array<string,mixed> $in 支持 status/userId/page/pageSize
     * @return array{list:array<int,array>, pagination:array{page:int,pageSize:int,total:int}}
     */
    public function feedList(array $in): array
    {
        $page = max(1, (int) ($in['page'] ?? 1));
        $pageSize = min(50, max(1, (int) ($in['pageSize'] ?? 20)));

        $query = Feed::find();
        $status = $in['status'] ?? Feed::STATUS_NORMAL;
        if ($status !== '') {
            $query->andWhere(['status' => (int) $status]);
        }
        if (!empty($in['userId'])) {
            $query->andWhere(['user_id' => (int) $in['userId']]);
        }

        $total = (int) $query->count();
        /** @var Feed[] $rows */
        $rows = $query->orderBy(['id' => SORT_DESC])
            ->offset(($page - 1) * $pageSize)
            ->limit($pageSize)
            ->all();

        // 批量拼作者昵称，防 N+1
        $authorIds = array_values(array_unique(array_filter(
            array_map(static fn (Feed $f): int => (int) $f->user_id, $rows)
        )));
        $authors = [];
        if ($authorIds !== []) {
            foreach (User::find()->where(['id' => $authorIds])->all() as $u) {
                /** @var User $u */
                $authors[$u->getId()] = $u->toPublicArray();
            }
        }

        $list = array_map(static function (Feed $f) use ($authors): array {
            $view = $f->toAdminArray();
            $view['author'] = $authors[(int) $f->user_id] ?? null;
            return $view;
        }, $rows);

        return [
            'list' => $list,
            'pagination' => ['page' => $page, 'pageSize' => $pageSize, 'total' => $total],
        ];
    }

    /**
     * 巡查处置：下架违规动态 或 恢复已下架动态。
     *
     * @param bool $off true 下架（正常→下架，填理由），false 恢复（下架→正常）
     */
    public function setFeedStatus(int $feedId, bool $off, string $remark = ''): array
    {
        $feed = Feed::findOne(['id' => $feedId]);
        if ($feed === null) {
            throw new BizException(ErrorCode::FEED_NOT_FOUND);
        }

        if ($off) {
            if ((int) $feed->status !== Feed::STATUS_NORMAL) {
                throw new BizException(ErrorCode::FEED_STATUS_INVALID, '仅正常动态可下架');
            }
            if (trim($remark) === '') {
                throw new BizException(ErrorCode::PARAM_INVALID, '下架需填写理由');
            }
            $feed->status = Feed::STATUS_OFF;
            $feed->off_reason = $remark;
        } else {
            if ((int) $feed->status !== Feed::STATUS_OFF) {
                throw new BizException(ErrorCode::FEED_STATUS_INVALID, '仅已下架动态可恢复');
            }
            $feed->status = Feed::STATUS_NORMAL;
            $feed->off_reason = '';
        }

        if (!$feed->save(false)) {
            throw new BizException(ErrorCode::INTERNAL_ERROR, '处置保存失败');
        }
        return $feed->toAdminArray();
    }

    /**
     * 待审动态审核（敏感词命中转待审后的人工处置）。
     *
     * @param bool $pass true 通过（待审→正常），false 驳回（待审→下架，填理由）
     */
    public function auditFeed(int $feedId, bool $pass, string $remark = ''): array
    {
        $feed = Feed::findOne(['id' => $feedId]);
        if ($feed === null) {
            throw new BizException(ErrorCode::FEED_NOT_FOUND);
        }
        if ((int) $feed->status !== Feed::STATUS_AUDITING) {
            throw new BizException(ErrorCode::FEED_STATUS_INVALID, '仅待审动态可审核');
        }

        if ($pass) {
            $feed->status = Feed::STATUS_NORMAL;
            $feed->off_reason = '';
        } else {
            if (trim($remark) === '') {
                throw new BizException(ErrorCode::PARAM_INVALID, '驳回需填写理由');
            }
            $feed->status = Feed::STATUS_OFF;
            $feed->off_reason = $remark;
        }

        if (!$feed->save(false)) {
            throw new BizException(ErrorCode::INTERNAL_ERROR, '审核保存失败');
        }
        return $feed->toAdminArray();
    }
}
