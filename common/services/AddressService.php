<?php

declare(strict_types=1);

namespace common\services;

use common\enums\ErrorCode;
use common\exceptions\BizException;
use common\models\Address;
use Yii;

/**
 * 收货地址（用户端，需登录）。
 */
class AddressService
{
    /**
     * @return array{list:array<int,array>}
     */
    public function list(int $userId): array
    {
        $rows = Address::find()
            ->where(['user_id' => $userId])
            ->orderBy(['is_default' => SORT_DESC, 'id' => SORT_DESC])
            ->all();
        return ['list' => array_map(static fn (Address $a): array => $a->toArray(), $rows)];
    }

    /**
     * 新建地址。
     *
     * @param array<string,mixed> $in
     */
    public function create(int $userId, array $in): array
    {
        $address = new Address();
        $address->user_id = $userId;
        $this->fill($address, $in);
        if (!$address->save()) {
            throw new BizException(ErrorCode::PARAM_INVALID, $this->firstError($address) ?? '地址保存失败');
        }
        $this->applyDefault($userId, $address);
        return $address->toArray();
    }

    /**
     * 修改地址。
     *
     * @param array<string,mixed> $in
     */
    public function update(int $userId, int $id, array $in): array
    {
        $address = $this->requireOwn($userId, $id);
        $this->fill($address, $in);
        if (!$address->save()) {
            throw new BizException(ErrorCode::PARAM_INVALID, $this->firstError($address) ?? '地址保存失败');
        }
        $this->applyDefault($userId, $address);
        return $address->toArray();
    }

    public function delete(int $userId, int $id): void
    {
        $address = $this->requireOwn($userId, $id);
        $address->delete();
    }

    /**
     * 设为默认。
     */
    public function setDefault(int $userId, int $id): array
    {
        $address = $this->requireOwn($userId, $id);
        $address->is_default = 1;
        $address->save(false);
        $this->applyDefault($userId, $address);
        return $address->toArray();
    }

    // ---------------- 内部 ----------------

    private function requireOwn(int $userId, int $id): Address
    {
        $address = Address::findOne(['id' => $id, 'user_id' => $userId]);
        if ($address === null) {
            throw new BizException(ErrorCode::ADDRESS_NOT_FOUND);
        }
        return $address;
    }

    /**
     * @param array<string,mixed> $in
     */
    private function fill(Address $address, array $in): void
    {
        foreach (['name', 'phone', 'province', 'city', 'district', 'detail'] as $f) {
            if (isset($in[$f])) {
                $address->$f = (string) $in[$f];
            }
        }
        if (isset($in['isDefault'])) {
            $address->is_default = (int) $in['isDefault'] === 1 ? 1 : 0;
        }
    }

    /**
     * 若该地址被设为默认，取消同用户其它默认。
     */
    private function applyDefault(int $userId, Address $address): void
    {
        if ((int) $address->is_default === 1) {
            Address::updateAll(
                ['is_default' => 0],
                ['and', ['user_id' => $userId], ['not', ['id' => $address->getId()]]]
            );
        }
    }

    private function firstError(Address $address): ?string
    {
        foreach ($address->getFirstErrors() as $msg) {
            return $msg;
        }
        return null;
    }
}
