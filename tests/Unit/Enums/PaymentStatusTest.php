<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Tests\Unit\Enums;

use PHPUnit\Framework\TestCase;
use Mifatoyeh\LaravelPaymentFramework\Enums\PaymentStatus;

/**
 * Unit tests for the PaymentStatus backed enum.
 */
class PaymentStatusTest extends TestCase
{
    /** @test */
    public function test_all_cases_have_correct_backing_values(): void
    {
        // TODO: $this->assertSame('pending', PaymentStatus::Pending->value);
        // TODO: $this->assertSame('authorized', PaymentStatus::Authorized->value);
        // TODO: ... assert all 10 cases
        $this->markTestIncomplete('TODO: Assert all PaymentStatus backing values.');
    }

    /** @test */
    public function test_from_valid_value_returns_case(): void
    {
        // TODO: $this->assertSame(PaymentStatus::Failed, PaymentStatus::from('failed'));
        $this->markTestIncomplete('TODO: Assert PaymentStatus::from() returns correct case.');
    }

    /** @test */
    public function test_try_from_invalid_value_returns_null(): void
    {
        // TODO: $this->assertNull(PaymentStatus::tryFrom('invalid_status'));
        $this->markTestIncomplete('TODO: Assert PaymentStatus::tryFrom() returns null for unknown value.');
    }
}
