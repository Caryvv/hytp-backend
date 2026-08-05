<?php

declare(strict_types=1);

namespace admin\controllers;

use admin\base\AdminBaseController;
use common\models\AdminRolePermission;
use common\services\AdminLogService;
use common\services\ContentService;
use Yii;

/**
 * 管理端 文旅 + 文化传承 内容录入（运营 CRUD）。
 * 需权限点 content:manage。
 */
class ContentController extends AdminBaseController
{
    /** GET /admin/contents?type=&status=&page=&pageSize= —— 内容列表 */
    public function actionIndex(): array
    {
        $this->requirePermission(AdminRolePermission::PERM_CONTENT_MANAGE);
        $req = Yii::$app->request;
        return (new ContentService())->adminList([
            'type' => $req->get('type'),
            'status' => $req->get('status'),
            'page' => $req->get('page'),
            'pageSize' => $req->get('pageSize'),
        ]);
    }

    /** POST /admin/contents —— 新建 { type, title, cover?, images?, detail?, city?, category?, status? } */
    public function actionCreate(): array
    {
        $admin = $this->requirePermission(AdminRolePermission::PERM_CONTENT_MANAGE);
        $result = (new ContentService())->create($this->bodyParams());
        (new AdminLogService())->record($admin->getId(), 'content.create', 'content', ['id' => $result['id'] ?? null]);
        return $result;
    }

    /** PUT /admin/contents/{id} —— 编辑 */
    public function actionUpdate(int $id): array
    {
        $admin = $this->requirePermission(AdminRolePermission::PERM_CONTENT_MANAGE);
        $result = (new ContentService())->update($id, $this->bodyParams());
        (new AdminLogService())->record($admin->getId(), 'content.update', 'content', ['id' => $id]);
        return $result;
    }

    /** POST /admin/contents/{id}/toggle —— 上/下线切换 */
    public function actionToggle(int $id): array
    {
        $admin = $this->requirePermission(AdminRolePermission::PERM_CONTENT_MANAGE);
        $result = (new ContentService())->toggle($id);
        (new AdminLogService())->record($admin->getId(), 'content.toggle', 'content', ['id' => $id, 'status' => $result['status'] ?? null]);
        return $result;
    }

    /** DELETE /admin/contents/{id} —— 删除 */
    public function actionDelete(int $id): array
    {
        $admin = $this->requirePermission(AdminRolePermission::PERM_CONTENT_MANAGE);
        $result = (new ContentService())->delete($id);
        (new AdminLogService())->record($admin->getId(), 'content.delete', 'content', ['id' => $id]);
        return $result;
    }

    /**
     * 读取写操作 body 白名单。
     *
     * @return array<string,mixed>
     */
    private function bodyParams(): array
    {
        $req = Yii::$app->request;
        return [
            'type' => $req->post('type'),
            'title' => $req->post('title'),
            'cover' => $req->post('cover'),
            'images' => $req->post('images'),
            'detail' => $req->post('detail'),
            'city' => $req->post('city'),
            'category' => $req->post('category'),
            'status' => $req->post('status'),
        ];
    }
}
