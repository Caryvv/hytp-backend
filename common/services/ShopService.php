<?php

declare(strict_types=1);

namespace common\services;

use common\enums\ErrorCode;
use common\exceptions\BizException;
use common\models\Shop;
use common\models\ShopQualification;
use Yii;

/**
 * 商家入驻与店铺信息业务（商家端）。
 *
 * 入驻流程：register（创建账号，status=待审核）→ 上传资质 → 等管理端审核。
 */
class ShopService
{
    /**
     * 商家入驻注册。
     *
     * @param array{account:?string, password:?string, name:?string, type:?int, region:?string,
     *              contactName:?string, contactPhone:?string} $in
     * @return array 新店铺信息
     */
    public function register(array $in): array
    {
        $account = trim((string) ($in['account'] ?? ''));
        $password = (string) ($in['password'] ?? '');

        if ($account === '' || $password === '') {
            throw new BizException(ErrorCode::PARAM_INVALID, '请输入账号和密码');
        }
        if (mb_strlen($password) < 6) {
            throw new BizException(ErrorCode::PARAM_INVALID, '密码至少 6 位');
        }
        if (Shop::findByAccount($account) !== null) {
            throw new BizException(ErrorCode::SHOP_ACCOUNT_EXISTS);
        }

        $shop = new Shop();
        $shop->account = $account;
        $shop->setPassword($password);
        $shop->name = trim((string) ($in['name'] ?? ''));
        $shop->type = (int) ($in['type'] ?? Shop::TYPE_ORIGINAL);
        $shop->region = trim((string) ($in['region'] ?? ''));
        $shop->contact_name = trim((string) ($in['contactName'] ?? ''));
        $shop->contact_phone = trim((string) ($in['contactPhone'] ?? ''));
        $shop->status = Shop::STATUS_PENDING;
        $shop->credit_score = Shop::CREDIT_INITIAL;

        if (!$shop->save()) {
            // account 唯一校验失败（含并发下唯一键冲突）
            if ($shop->hasErrors('account')) {
                throw new BizException(ErrorCode::SHOP_ACCOUNT_EXISTS);
            }
            $first = $this->firstError($shop);
            throw new BizException(ErrorCode::PARAM_INVALID, $first ?? '入驻失败');
        }

        return $shop->toMerchantArray();
    }

    /**
     * 更新店铺信息（仅本店）。
     *
     * @param array<string,mixed> $in
     */
    public function updateShop(Shop $shop, array $in): array
    {
        foreach (['name', 'logo', 'region', 'contactName', 'contactPhone'] as $key) {
            if (!array_key_exists($key, $in) || $in[$key] === null) {
                continue;
            }
            $attr = match ($key) {
                'contactName' => 'contact_name',
                'contactPhone' => 'contact_phone',
                default => $key,
            };
            $shop->$attr = (string) $in[$key];
        }
        if (array_key_exists('type', $in) && $in['type'] !== null) {
            $shop->type = (int) $in['type'];
        }

        if (!$shop->save()) {
            $first = $this->firstError($shop);
            throw new BizException(ErrorCode::PARAM_INVALID, $first ?? '保存失败');
        }
        return $shop->toMerchantArray();
    }

    /**
     * 提交资质材料。
     *
     * @param array{qualType:?string, fileUrl:?string} $in
     */
    public function addQualification(Shop $shop, array $in): array
    {
        $qualType = trim((string) ($in['qualType'] ?? ''));
        $fileUrl = trim((string) ($in['fileUrl'] ?? ''));
        if ($qualType === '' || $fileUrl === '') {
            throw new BizException(ErrorCode::PARAM_INVALID, '资质类型和文件不能为空');
        }

        $qual = new ShopQualification();
        $qual->shop_id = $shop->getId();
        $qual->qual_type = $qualType;
        $qual->file_url = $fileUrl;
        $qual->status = ShopQualification::STATUS_PENDING;

        if (!$qual->save()) {
            $first = $this->firstError($qual);
            throw new BizException(ErrorCode::PARAM_INVALID, $first ?? '资质提交失败');
        }
        return $qual->toArray();
    }

    /**
     * 本店资质列表。
     *
     * @return array<int,array>
     */
    public function qualifications(Shop $shop): array
    {
        $rows = ShopQualification::find()
            ->where(['shop_id' => $shop->getId()])
            ->orderBy(['id' => SORT_DESC])
            ->all();
        return array_map(static fn (ShopQualification $q): array => $q->toArray(), $rows);
    }

    private function firstError(\yii\db\ActiveRecord $model): ?string
    {
        foreach ($model->getFirstErrors() as $msg) {
            return $msg;
        }
        return null;
    }
}
