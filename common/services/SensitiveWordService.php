<?php

declare(strict_types=1);

namespace common\services;

use Yii;

/**
 * 敏感词扫描。词库来自 common/config/sensitive-words.php（运营可改词表不动代码）。
 *
 * ponytail: 逐词 mb_stripos，词库小够用；上千词改 AC 自动机一次扫描。
 */
class SensitiveWordService
{
    /** @var string[]|null 进程内缓存，避免每次 require */
    private static ?array $words = null;

    /** @return string[] */
    private static function words(): array
    {
        if (self::$words === null) {
            $file = Yii::getAlias('@common/config/sensitive-words.php');
            /** @var string[] $loaded */
            $loaded = is_file($file) ? (array) require $file : [];
            // 过滤空词，防空串命中一切
            self::$words = array_values(array_filter($loaded, static fn ($w): bool => trim((string) $w) !== ''));
        }
        return self::$words;
    }

    /** 命中的第一个敏感词；无命中返回 null。大小写不敏感。 */
    public function firstHit(string $text): ?string
    {
        if ($text === '') {
            return null;
        }
        foreach (self::words() as $word) {
            if (mb_stripos($text, $word) !== false) {
                return $word;
            }
        }
        return null;
    }

    /** 是否命中任一敏感词。 */
    public function hasHit(string $text): bool
    {
        return $this->firstHit($text) !== null;
    }
}
