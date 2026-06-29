<?php

declare(strict_types=1);

namespace Rasuvaeff\Retry\Tests\Http;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class QueueHttpClient implements ClientInterface
{
    private int $calls = 0;

    /**
     * @param non-empty-list<ResponseInterface|\Throwable> $items
     */
    public function __construct(
        private array $items,
    ) {}

    #[\Override]
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->calls++;
        $item = array_shift($this->items);
        if ($item instanceof \Throwable) {
            throw $item;
        }
        if ($item instanceof ResponseInterface) {
            return $item;
        }

        throw new \LogicException('HTTP queue is empty');
    }

    public function calls(): int
    {
        return $this->calls;
    }
}
