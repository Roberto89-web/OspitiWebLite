<?php

namespace App\Exceptions;

class NotFoundException extends AppException {
    protected $httpStatusCode = 404;
    protected $message = 'Resource not found';
}
