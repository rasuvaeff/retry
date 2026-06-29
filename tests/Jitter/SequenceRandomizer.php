<?php

declare(strict_types=1);

namespace Rasuvaeff\Retry\Tests\Jitter;

use Rasuvaeff\Retry\Randomizer\RandomizerInterface;

final class SequenceRandomizer implements RandomizerInterface
{
    private int $index = 0;

    /**
     * @param non-empty-list<float> $fractions
     */
    public function __construct(
        private readonly array $fractions,
    ) {}

    #[\Override]
    public function float(float $min, float $max): float
    {
        $fraction = $this->fractions[$this->index % count($this->fractions)];
        $this->index++;

        return $min + (($max - $min) * $fraction);
    }
}
