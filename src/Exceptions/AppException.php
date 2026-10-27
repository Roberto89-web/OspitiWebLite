<?php

namespace App\Exceptions;

use Exception;

class AppException extends Exception {
    protected $httpStatusCode = 500;

    public function getHttpStatusCode(): int {
        return $this->httpStatusCode;
    }

    public function setHttpStatusCode(int $code): self {
        $this->httpStatusCode = $code;
        return $this;
    }
}
