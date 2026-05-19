<?php

namespace Simsoft\HttpClient\Exceptions;

use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Throwable;

/**
 * NetworkException class
 */
class NetworkException extends ClientException implements NetworkExceptionInterface
{
    public function __construct(
        private RequestInterface $request,
        string     $message = '',
        int        $code = 0,
        ?Throwable $previous = null
    )
    {
        parent::__construct($message, $code, $previous);
    }

    public function getRequest(): RequestInterface
    {
        return $this->request;
    }
}
