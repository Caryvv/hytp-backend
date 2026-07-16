<?php

declare(strict_types=1);

namespace common\exceptions;

use common\enums\ErrorCode;

/**
 * 业务异常。控制器/Service 抛出后由 ApiController 统一捕获，
 * 转成 { code, message, data } 响应（HTTP 仍 200）。
 */
class BizException extends \Exception
{
    /** 业务错误码（见 ErrorCode）。 */
    public int $bizCode;

    public function __construct(int $bizCode, ?string $message = null, ?\Throwable $previous = null)
    {
        $this->bizCode = $bizCode;
        parent::__construct($message ?? ErrorCode::message($bizCode), 0, $previous);
    }

    public static function of(int $bizCode, ?string $message = null): self
    {
        return new self($bizCode, $message);
    }
}
