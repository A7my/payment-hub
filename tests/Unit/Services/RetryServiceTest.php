<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Mifatoyeh\LaravelPaymentFramework\Services\RetryService;

/**
 * Unit tests for RetryService.
 *
 * Covers: success on Nth attempt, exhausted retries, exception identity
 * preservation, non-transient short-circuiting, the disabled switch,
 * exponential backoff, the onRetry/onLog hooks, and HTTP status code
 * transience classification.
 *
 * Also contains property-based tests P16–P17.
 */
class RetryServiceTest extends TestCase
{
    /** @test */
    public function test_succeeds_on_nth_attempt(): void
    {
        $attempts = 0;
        $service  = new RetryService(3, 0, true);

        $result = $service->execute(function () use (&$attempts) {
            $attempts++;
            if ($attempts < 3) {
                throw new \RuntimeException('transient', 503);
            }
            return 'success';
        });

        $this->assertSame('success', $result);
        $this->assertSame(3, $attempts);
    }

    /** @test */
    public function test_exhausted_retries_rethrow(): void
    {
        $attempts = 0;
        $service  = new RetryService(2, 0, true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('always fails');

        try {
            $service->execute(function () use (&$attempts) {
                $attempts++;
                throw new \RuntimeException('always fails', 503);
            });
        } finally {
            $this->assertSame(2, $attempts);
        }
    }

    /** @test */
    public function test_preserves_original_exception_instance_after_exhaustion(): void
    {
        $original = new \RuntimeException('boom', 503);
        $service  = new RetryService(2, 0, true);

        try {
            $service->execute(function () use ($original) {
                throw $original;
            });
            $this->fail('Expected exception to propagate.');
        } catch (\RuntimeException $caught) {
            $this->assertSame($original, $caught);
        }
    }

    /** @test */
    public function test_non_transient_exception_is_not_retried(): void
    {
        $calls   = 0;
        $service = new RetryService(5, 0, true);

        try {
            $service->execute(function () use (&$calls) {
                $calls++;
                throw new \RuntimeException('bad request', 400);
            });
            $this->fail('Expected exception to propagate.');
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame(1, $calls);
    }

    /** @test */
    public function test_disabled_retry_executes_operation_once_without_retrying(): void
    {
        $calls   = 0;
        $service = new RetryService(5, 0, false);

        try {
            $service->execute(function () use (&$calls) {
                $calls++;
                throw new \RuntimeException('fails', 503); // transient, but retry disabled
            });
            $this->fail('Expected exception to propagate.');
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame(1, $calls);
    }

    /** @test */
    public function test_exponential_backoff_increases_delay_between_attempts(): void
    {
        $delays  = [];
        $service = new RetryService(
            maxAttempts: 4,
            delayMs: 10,
            enabled: true,
            backoffMultiplier: 2.0,
            onRetry: function (int $attempt, \Throwable $e, int $delayMs) use (&$delays) {
                $delays[] = $delayMs;
            },
        );

        $attempts = 0;
        $service->execute(function () use (&$attempts) {
            $attempts++;
            if ($attempts < 4) {
                throw new \RuntimeException('transient', 500);
            }
            return 'ok';
        });

        $this->assertSame([10, 20, 40], $delays);
    }

    /** @test */
    public function test_on_retry_callback_receives_attempt_number_and_exception(): void
    {
        $calls   = [];
        $service = new RetryService(
            maxAttempts: 3,
            delayMs: 0,
            enabled: true,
            onRetry: function (int $attempt, \Throwable $e, int $delayMs) use (&$calls) {
                $calls[] = [$attempt, $e->getMessage()];
            },
        );

        $attempts = 0;
        $service->execute(function () use (&$attempts) {
            $attempts++;
            if ($attempts < 3) {
                throw new \RuntimeException('retry-me', 429);
            }
            return 'ok';
        });

        $this->assertSame([[1, 'retry-me'], [2, 'retry-me']], $calls);
    }

    /** @test */
    public function test_log_hook_invoked_with_warning_on_retry_and_error_on_exhaustion(): void
    {
        $levels  = [];
        $service = new RetryService(
            maxAttempts: 2,
            delayMs: 0,
            enabled: true,
            onLog: function (string $level, string $message, array $context) use (&$levels) {
                $levels[] = $level;
            },
        );

        try {
            $service->execute(fn () => throw new \RuntimeException('down', 500));
            $this->fail('Expected exception to propagate.');
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame(['warning', 'error'], $levels);
    }

    /** @test */
    public function test_http_5xx_classified_as_transient(): void
    {
        $service = new RetryService(1, 0, true);

        $this->assertTrue($service->isTransient(new \RuntimeException('server error', 500)));
        $this->assertTrue($service->isTransient(new \RuntimeException('server error', 599)));
    }

    /** @test */
    public function test_http_429_classified_as_transient(): void
    {
        $service = new RetryService(1, 0, true);

        $this->assertTrue($service->isTransient(new \RuntimeException('rate limited', 429)));
    }

    /** @test */
    public function test_http_4xx_not_transient(): void
    {
        $service = new RetryService(1, 0, true);

        $this->assertFalse($service->isTransient(new \RuntimeException('bad request', 400)));
        $this->assertFalse($service->isTransient(new \RuntimeException('not found', 404)));
    }

    // -------------------------------------------------------------------------
    // Property 16: Retry on Transient Errors
    // Feature: laravel-payment-framework, Property 16: Retry on transient errors
    // -------------------------------------------------------------------------
    /** @test */
    public function test_property_16_retry_on_transient_errors(): void
    {
        // Feature: laravel-payment-framework, Property 16: Retry on transient errors
        // For any configured max_attempts N >= 1, a callable that throws a
        // transient exception exactly N-1 times and succeeds on attempt N
        // returns the successful result without exception.
        foreach (range(1, 10) as $n) {
            $attempts = 0;
            $service  = new RetryService($n, 0, true);

            $result = $service->execute(function () use (&$attempts, $n) {
                $attempts++;
                if ($attempts < $n) {
                    throw new \RuntimeException('transient', 503);
                }
                return 'success-' . $n;
            });

            $this->assertSame('success-' . $n, $result);
            $this->assertSame($n, $attempts);
        }
    }

    // -------------------------------------------------------------------------
    // Property 17: HTTP Status Code Transience Classification
    // Feature: laravel-payment-framework, Property 17: HTTP status code transience classification
    // -------------------------------------------------------------------------
    /** @test */
    public function test_property_17_http_status_code_transience_classification(): void
    {
        // Feature: laravel-payment-framework, Property 17: HTTP status code transience classification
        $service = new RetryService(1, 0, true);

        // Any HTTP code 500-599 is transient.
        foreach (range(500, 599) as $code) {
            $this->assertTrue(
                $service->isTransient(new \RuntimeException('server error', $code)),
                "Expected HTTP {$code} to be classified as transient.",
            );
        }

        // Any HTTP code 400-499 except 429 is not transient.
        foreach (range(400, 499) as $code) {
            if ($code === 429) {
                continue;
            }

            $this->assertFalse(
                $service->isTransient(new \RuntimeException('client error', $code)),
                "Expected HTTP {$code} to not be classified as transient.",
            );
        }

        // HTTP 429 is explicitly transient.
        $this->assertTrue($service->isTransient(new \RuntimeException('rate limited', 429)));
    }
}
