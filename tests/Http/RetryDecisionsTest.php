<?php

declare(strict_types=1);

namespace Rasuvaeff\Retry\Tests\Http;

use Rasuvaeff\Retry\Http\RetryDecisions;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(RetryDecisions::class)]
final class RetryDecisionsTest
{
    public function serverErrorsMatchesFiveHundredRange(): void
    {
        $predicate = RetryDecisions::serverErrors();

        Assert::true($predicate(new FakeResponse(statusCode: 500)));
        Assert::true($predicate(new FakeResponse(statusCode: 503)));
        Assert::true($predicate(new FakeResponse(statusCode: 599)));
    }

    public function serverErrorsRejectsOtherStatuses(): void
    {
        $predicate = RetryDecisions::serverErrors();

        Assert::false($predicate(new FakeResponse(statusCode: 200)));
        Assert::false($predicate(new FakeResponse(statusCode: 404)));
        Assert::false($predicate(new FakeResponse(statusCode: 429)));
        Assert::false($predicate(new FakeResponse(statusCode: 600)));
    }

    public function rateLimitedMatchesOnly429(): void
    {
        $predicate = RetryDecisions::rateLimited();

        Assert::true($predicate(new FakeResponse(statusCode: 429)));
        Assert::false($predicate(new FakeResponse(statusCode: 428)));
        Assert::false($predicate(new FakeResponse(statusCode: 430)));
        Assert::false($predicate(new FakeResponse(statusCode: 503)));
    }

    public function transientMatchesRetryableStatuses(): void
    {
        $predicate = RetryDecisions::transient();

        foreach ([408, 425, 429, 500, 502, 503, 504] as $code) {
            Assert::true($predicate(new FakeResponse(statusCode: $code)), sprintf('Expected %d to be transient', $code));
        }
    }

    public function transientRejectsNonRetryableStatuses(): void
    {
        $predicate = RetryDecisions::transient();

        foreach ([200, 301, 400, 401, 403, 404, 422, 501, 505] as $code) {
            Assert::false($predicate(new FakeResponse(statusCode: $code)), sprintf('Expected %d to NOT be transient', $code));
        }
    }

    public function predicatesAreReusableAcrossInvocations(): void
    {
        $predicate = RetryDecisions::serverErrors();

        Assert::true($predicate(new FakeResponse(statusCode: 500)));
        Assert::true($predicate(new FakeResponse(statusCode: 503)));
        Assert::false($predicate(new FakeResponse(statusCode: 200)));
    }

    public function onlyIdempotentRetriesIdempotentMethods(): void
    {
        $predicate = RetryDecisions::onlyIdempotent(RetryDecisions::serverErrors());

        Assert::true($predicate(new FakeResponse(statusCode: 503), new FakeRequest(method: 'GET')));
        Assert::true($predicate(new FakeResponse(statusCode: 503), new FakeRequest(method: 'DELETE')));
    }

    public function onlyIdempotentSkipsNonIdempotentMethods(): void
    {
        $predicate = RetryDecisions::onlyIdempotent(RetryDecisions::serverErrors());

        Assert::false($predicate(new FakeResponse(statusCode: 503), new FakeRequest(method: 'POST')));
        Assert::false($predicate(new FakeResponse(statusCode: 503), new FakeRequest(method: 'PATCH')));
    }

    public function onlyIdempotentStillHonorsInnerPredicate(): void
    {
        $predicate = RetryDecisions::onlyIdempotent(RetryDecisions::serverErrors());

        Assert::false($predicate(new FakeResponse(statusCode: 200), new FakeRequest(method: 'GET')));
    }

    public function onlyIdempotentNormalizesMethodCase(): void
    {
        $predicate = RetryDecisions::onlyIdempotent(RetryDecisions::serverErrors());

        Assert::true($predicate(new FakeResponse(statusCode: 503), new FakeRequest(method: 'get')));
    }
}
