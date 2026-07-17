<?php

declare(strict_types=1);

namespace common\services;

use common\models\ProductCategory;

/**
 * 商品分类查询（用户端只读）。
 */
class CatalogService
{
    /**
     * 返回分类树（按 parent_id 组装）。
     *
     * @return array<int,array>
     */
    public function tree(): array
    {
        $rows = ProductCategory::find()
            ->orderBy(['sort' => SORT_ASC, 'id' => SORT_ASC])
            ->all();

        $byParent = [];
        foreach ($rows as $row) {
            $node = $row->toArray();
            $node['children'] = [];
            $byParent[(int) $row->parent_id][] = $node;
        }

        return $this->buildTree($byParent, 0);
    }

    /**
     * @param array<int,array<int,array>> $byParent
     * @return array<int,array>
     */
    private function buildTree(array $byParent, int $parentId): array
    {
        $nodes = $byParent[$parentId] ?? [];
        foreach ($nodes as &$node) {
            $node['children'] = $this->buildTree($byParent, (int) $node['id']);
        }
        return $nodes;
    }
}
