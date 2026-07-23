<?php

declare(strict_types=1);

namespace common\services;

use common\enums\ErrorCode;
use common\exceptions\BizException;
use common\models\Feed;
use common\models\FeedTip;
use common\models\WalletTransaction;

/**
 * 动态打赏 —— 用户→用户的同袍币转账。
 *
 * 转账语义（余额在 hytp 库、流水在 hytp_trade 库，非单事务，saga）：
 *   打赏者 debit(TYPE_CONSUME) → 作者 credit(TYPE_GIFT)。credit 失败必须补偿退回打赏者。
 * 幂等：先插 feed_tip（tip_no 唯一键）占位再扣款；同 tip_no 重放直接返回既有记录。
 */
class TipService
{
    public const MIN_COIN = 1;
    public const MAX_COIN = 100000;

    /**
     * @return array{coin:int, balanceCoin:int, feedTipCount:int, feedTipCoin:int}
     */
    public function tip(int $userId, int $feedId, int $coin, string $tipNo): array
    {
        if ($coin < self::MIN_COIN || $coin > self::MAX_COIN) {
            throw new BizException(ErrorCode::TIP_AMOUNT_INVALID);
        }
        if ($tipNo === '') {
            throw new BizException(ErrorCode::PARAM_INVALID, '缺少幂等键');
        }

        // 1) 校验动态 + 作者
        $feed = Feed::findOne(['id' => $feedId]);
        if ($feed === null) {
            throw new BizException(ErrorCode::FEED_NOT_FOUND);
        }
        if ((int) $feed->status !== Feed::STATUS_NORMAL) {
            throw new BizException(ErrorCode::FEED_STATUS_INVALID);
        }
        $authorId = (int) $feed->user_id;
        if ($authorId === $userId) {
            throw new BizException(ErrorCode::TIP_SELF);
        }

        // 2) 幂等：同 tip_no 已打赏则直接返回
        $existing = FeedTip::findOne(['tip_no' => $tipNo]);
        if ($existing !== null) {
            return $this->result($feedId, (int) $existing->coin, $userId);
        }

        // 3) 先插 feed_tip 占位（唯一键挡并发/重试重复打赏）
        $tip = new FeedTip();
        $tip->tip_no = $tipNo;
        $tip->feed_id = $feedId;
        $tip->from_user_id = $userId;
        $tip->to_user_id = $authorId;
        $tip->coin = $coin;
        if (!$tip->save()) {
            // 唯一键冲突 = 并发重放，回查既有幂等返回
            $dup = FeedTip::findOne(['tip_no' => $tipNo]);
            if ($dup !== null) {
                return $this->result($feedId, (int) $dup->coin, $userId);
            }
            throw new BizException(ErrorCode::INTERNAL_ERROR, '打赏记录创建失败');
        }

        $wallet = new WalletService();

        // 4) 扣款（打赏者出账）—— 余额不足删占位行再抛
        try {
            $debitTxn = $wallet->debit($userId, $coin, WalletTransaction::TYPE_CONSUME, [
                'refType' => 'feed_tip', 'refId' => $tipNo, 'remark' => '打赏动态',
            ]);
        } catch (\Throwable $e) {
            $tip->delete();
            throw $e;
        }

        // 5) 入账（作者收款）—— 失败则补偿退回打赏者 + 删占位行
        try {
            // refType 与打赏者出账区分：credit 幂等查 ref_type+ref_id（不含 user_id），
            // 若与 debit 同 refType 会误命中出账流水导致作者漏收款。
            $wallet->credit($authorId, $coin, WalletTransaction::TYPE_GIFT, [
                'refType' => 'feed_tip_recv', 'refId' => $tipNo, 'remark' => '收到打赏',
            ]);
        } catch (\Throwable $e) {
            $wallet->credit($userId, $coin, WalletTransaction::TYPE_REFUND, [
                'refType' => 'feed_tip_refund', 'refId' => $tipNo, 'remark' => '打赏失败退回',
            ]);
            $tip->delete();
            throw new BizException(ErrorCode::INTERNAL_ERROR, '打赏失败，已退回');
        }

        // 6) 回填流水号
        $tip->txn_no = $debitTxn->txn_no;
        $tip->save(false);

        // 7) 跨库计数（feed 在 hytp_social）：最终一致，失败不影响打赏结果
        Feed::updateAllCounters(['tip_count' => 1, 'tip_coin' => $coin], ['id' => $feedId]);

        return $this->result($feedId, $coin, $userId);
    }

    /**
     * @return array{coin:int, balanceCoin:int, feedTipCount:int, feedTipCoin:int}
     */
    private function result(int $feedId, int $coin, int $userId): array
    {
        $feed = Feed::findOne(['id' => $feedId]);
        return [
            'coin' => $coin,
            'balanceCoin' => (new WalletService())->currentCoin($userId),
            'feedTipCount' => $feed !== null ? (int) $feed->tip_count : 0,
            'feedTipCoin' => $feed !== null ? (int) $feed->tip_coin : 0,
        ];
    }
}
