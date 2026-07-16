<?php

declare(strict_types=1);

namespace common\base;

use common\enums\ErrorCode;
use common\exceptions\BizException;
use Yii;
use yii\web\Controller;
use yii\web\Response;
use yii\base\InvalidArgumentException;
use yii\web\HttpException;

/**
 * API 基类控制器：统一 JSON 响应、异常兜底、参数读取。
 * 业务 action 直接 `return $data`（自动包成 code=0），
 * 或 `throw new BizException(...)`（自动包成对应错误码）。
 */
class ApiController extends Controller
{
    public $enableCsrfValidation = false;

    /**
     * 统一把 action 返回值/异常包装成 { code, message, data }。
     */
    public function afterAction($action, $result)
    {
        $result = parent::afterAction($action, $result);
        // 已是标准结构则不再包裹
        if (is_array($result) && array_key_exists('code', $result) && array_key_exists('message', $result)) {
            return $result;
        }
        return $this->success($result);
    }

    /** 成功响应结构。 */
    public function success($data = null, string $message = 'success'): array
    {
        return ['code' => ErrorCode::SUCCESS, 'message' => $message, 'data' => $data];
    }

    /** 失败响应结构。 */
    public function fail(int $code, ?string $message = null, $data = null): array
    {
        return ['code' => $code, 'message' => $message ?? ErrorCode::message($code), 'data' => $data];
    }

    /**
     * 捕获未处理异常，统一为 JSON 业务结构（HTTP 保持 200）。
     */
    public function runAction($id, $params = [])
    {
        try {
            return parent::runAction($id, $params);
        } catch (\Throwable $e) {
            // 统一按类型分类映射业务错误码（HTTP 保持 200）。
            // 注：parent::runAction 的 phpdoc 仅声明 InvalidRouteException，
            // 业务 action 抛出的 BizException 等经动态分发冒泡至此，故统一在
            // Throwable 分支内按 instanceof 归类，避免 phpstan 误判死 catch。
            Yii::$app->response->statusCode = 200;

            if ($e instanceof BizException) {
                return $this->fail($e->bizCode, $e->getMessage());
            }
            if ($e instanceof InvalidArgumentException) {
                return $this->fail(ErrorCode::PARAM_INVALID, $e->getMessage());
            }
            if ($e instanceof HttpException) {
                $code = $e->statusCode === 401 ? ErrorCode::UNAUTHORIZED
                    : ($e->statusCode === 403 ? ErrorCode::FORBIDDEN
                    : ($e->statusCode === 404 ? ErrorCode::NOT_FOUND : ErrorCode::INTERNAL_ERROR));
                return $this->fail($code, $e->getMessage());
            }

            Yii::error((string) $e, __METHOD__);
            $msg = YII_DEBUG ? $e->getMessage() : ErrorCode::message(ErrorCode::INTERNAL_ERROR);
            return $this->fail(ErrorCode::INTERNAL_ERROR, $msg);
        }
    }
}
