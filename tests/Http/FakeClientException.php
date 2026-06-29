<?php

declare(strict_types=1);

namespace Rasuvaeff\Retry\Tests\Http;

use Psr\Http\Client\ClientExceptionInterface;

final class FakeClientException extends \RuntimeException implements ClientExceptionInterface {}
