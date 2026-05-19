<?php

namespace Simsoft\HttpClient\Exceptions;

use Psr\Http\Client\ClientExceptionInterface;
use RuntimeException;

/**
 * ClientException class
 */
class ClientException extends RuntimeException implements ClientExceptionInterface
{
}
