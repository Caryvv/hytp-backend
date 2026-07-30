<?php

declare(strict_types=1);

namespace common\services;

use common\enums\ErrorCode;
use common\exceptions\BizException;
use common\models\SysConfig;

/**
 * 平台配置（sys_config）读写 —— 通用 KV，但对已知 key 附带含义映射（label/desc/type）。
 *
 * 新增业务参数只需往 KNOWN 补一行；未在 KNOWN 的 key 仍可自由增删改（type=string）。
 */
class ConfigService
{
    /**
     * 已知配置含义映射。type：rate=0~1小数 / int=整数 / string=纯文本。
     *
     * @var array<string, array{label:string, desc:string, type:string, default:string}>
     */
    private const KNOWN = [
        'trade.commission_rate' => [
            'label' => '平台佣金比例',
            'desc' => '下单按订单金额抽成，0.06 = 6%',
            'type' => 'rate',
            'default' => '0.06',
        ],
    ];

    /**
     * 全部配置项（库中已有 + KNOWN 中库里还没有的，后者标 persisted=false 并给默认值）。
     *
     * @return array{list:array<int,array{key:string,value:?string,label:string,desc:string,type:string,persisted:bool}>}
     */
    public function list(): array
    {
        $rows = SysConfig::find()->orderBy(['config_key' => SORT_ASC])->all();

        $list = [];
        $seen = [];
        foreach ($rows as $row) {
            /** @var SysConfig $row */
            $key = $row->config_key;
            $seen[$key] = true;
            $list[] = $this->decorate($key, $row->config_value, true);
        }
        // KNOWN 里库中尚未落库的 key，补进来（带默认值，可直接编辑保存）
        foreach (self::KNOWN as $key => $meta) {
            if (!isset($seen[$key])) {
                $list[] = $this->decorate($key, $meta['default'], false);
            }
        }

        return ['list' => $list];
    }

    /**
     * 新增或更新一个配置项。已知 key 按 type 校验值。
     *
     * @return array{key:string,value:?string,label:string,desc:string,type:string,persisted:bool}
     */
    public function save(string $key, ?string $value): array
    {
        $key = trim($key);
        if ($key === '') {
            throw new BizException(ErrorCode::PARAM_INVALID, '配置键不能为空');
        }
        $this->validateValue($key, $value);

        $row = SysConfig::findOne(['config_key' => $key]);
        if ($row === null) {
            $row = new SysConfig();
            $row->config_key = $key;
            $row->remark = self::KNOWN[$key]['label'] ?? '';
        }
        $row->config_value = $value;
        if (!$row->save()) {
            throw new BizException(ErrorCode::PARAM_INVALID, '保存失败：' . json_encode($row->getErrors(), JSON_UNESCAPED_UNICODE));
        }

        return $this->decorate($key, $row->config_value, true);
    }

    /**
     * 删除一个配置项（已知 key 删除后回落到代码默认值）。
     */
    public function remove(string $key): void
    {
        SysConfig::deleteAll(['config_key' => $key]);
    }

    /**
     * 按已知 key 的 type 校验值。
     */
    private function validateValue(string $key, ?string $value): void
    {
        $type = self::KNOWN[$key]['type'] ?? 'string';
        if ($value === null || $value === '') {
            return;
        }
        if ($type === 'rate') {
            if (!is_numeric($value) || (float) $value < 0 || (float) $value > 1) {
                throw new BizException(ErrorCode::PARAM_INVALID, '该项须为 0~1 之间的小数');
            }
        }
        // ponytail: 仅 rate 一种带校验的 type（KNOWN 现只有佣金率）；加 int/枚举等 type 时在此补分支
    }

    /**
     * @return array{key:string,value:?string,label:string,desc:string,type:string,persisted:bool}
     */
    private function decorate(string $key, ?string $value, bool $persisted): array
    {
        $meta = self::KNOWN[$key] ?? null;
        return [
            'key' => $key,
            'value' => $value,
            'label' => $meta['label'] ?? $key,
            'desc' => $meta['desc'] ?? '',
            'type' => $meta['type'] ?? 'string',
            'persisted' => $persisted,
        ];
    }
}
