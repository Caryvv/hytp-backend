<?php

declare(strict_types=1);

namespace common\services;

use common\models\Feed;
use common\models\HomeBanner;
use common\models\User;

/**
 * 首页数据服务（banner + 推荐流）。
 *
 * 推荐流目前纯时间倒序（不接 AI），
 * 附带作者公开信息，无互动状态（免登录浏览）。
 */
class HomeService
{
    /**
     * 启用的 banner 按排序顺序。
     *
     * @return array<int,array>
     */
    public function getBanners(): array
    {
        $banners = HomeBanner::find()
            ->where(['status' => 1])
            ->orderBy(['sort_order' => SORT_ASC, 'id' => SORT_DESC])
            ->all();

        return array_map(static fn (HomeBanner $b): array => $b->toArray(), $banners);
    }

    /**
     * 首页推荐流：最新动态，带作者公开信息。
     *
     * @return array{list:array<int,array>, pagination:array{page:int,pageSize:int,total:int}}
     */
    public function getFeed(int $page = 1, int $pageSize = 10): array
    {
        $page = max(1, $page);
        $pageSize = min(50, max(1, $pageSize));

        $query = Feed::find()->where(['status' => Feed::STATUS_NORMAL]);
        $total = (int) $query->count();

        /** @var Feed[] $feeds */
        $feeds = $query->orderBy(['id' => SORT_DESC])
            ->offset(($page - 1) * $pageSize)
            ->limit($pageSize)
            ->all();

        $list = $this->decorateAuthors($feeds);

        return [
            'list' => $list,
            'pagination' => ['page' => $page, 'pageSize' => $pageSize, 'total' => $total],
        ];
    }

    /**
     * 批量给动态附作者公开信息。
     *
     * @param Feed[] $feeds
     * @return array<int,array>
     */
    private function decorateAuthors(array $feeds): array
    {
        if ($feeds === []) {
            return [];
        }

        $authorIds = array_unique(array_map(static fn (Feed $f): int => (int) $f->user_id, $feeds));
        $users = User::find()->where(['id' => array_values($authorIds)])->all();
        $authorMap = [];
        foreach ($users as $u) {
            /** @var User $u */
            $authorMap[$u->getId()] = $u->toPublicArray();
        }

        $list = [];
        foreach ($feeds as $f) {
            $view = $f->toDetailArray();
            $view['author'] = $authorMap[(int) $f->user_id] ?? null;
            $list[] = $view;
        }
        return $list;
    }
}
