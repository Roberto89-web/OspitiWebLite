<?php

namespace App\Exceptions;

class UnauthorizedException extends AppException {
    protected $httpStatusCode = 401;
    protected $message = 'Unauthorized access';
}
