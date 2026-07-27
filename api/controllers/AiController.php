<?php

declare(strict_types=1);

namespace api\controllers;

use common\base\ApiController;
use common\behaviors\JwtAuthBehavior;
use common\services\AiQaService;
use common\services\JwtService;
use Yii;

/**
 * AI 能力（需登录 aud=app）。
 * 智能问答：汉服知识问答，多轮上下文，AI 不可用时返兜底引导。
 */
class AiController extends ApiController
{
    public function behaviors(): array
    {
        $behaviors = parent::behaviors();
        $behaviors['authenticator'] = [
            'class' => JwtAuthBehavior::class,
            'aud' => JwtService::AUD_APP,
        ];
        return $behaviors;
    }

    /**
     * POST /ai/qa —— { question, history?:[{role,content}] } → { answer, hitKnowledge }
     */
    public function actionQa(): array
    {
        $req = Yii::$app->request;
        $question = trim((string) $req->post('question', ''));
        if ($question === '') {
            return ['answer' => '想问点什么呢？说说你的汉服疑问吧～', 'hitKnowledge' => false];
        }

        $rawHistory = $req->post('history', []);
        $history = [];
        if (is_array($rawHistory)) {
            foreach ($rawHistory as $turn) {
                if (!is_array($turn)) {
                    continue;
                }
                $role = (string) ($turn['role'] ?? '');
                $content = (string) ($turn['content'] ?? '');
                if (($role === 'user' || $role === 'assistant') && $content !== '') {
                    $history[] = ['role' => $role, 'content' => $content];
                }
            }
        }

        return (new AiQaService())->ask($question, $history);
    }
}
