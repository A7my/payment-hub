<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Tests\Unit\Exceptions;

use PHPUnit\Framework\TestCase;
use Mifatoyeh\LaravelPaymentFramework\Exceptions\UnsupportedOperationException;

/**
 * Unit tests for the payment framework exception hierarchy.
 *
 * Also contains property-based test P23.
 */
class ExceptionTest extends TestCase
{
    /** @test */
    public function test_unsupported_operation_message_contains_driver_and_operation(): void
    {
        // TODO: $e = UnsupportedOperationException::forOperation('createSubscription', 'qr_pay');
        // TODO: $this->assertStringContainsString('createSubscription', $e->getMessage());
        // TODO: $this->assertStringContainsString('qr_pay', $e->getMessage());
        $this->markTestIncomplete('TODO: Assert UnsupportedOperationException message contains driver and operation.');
    }

    // -------------------------------------------------------------------------
    // Property 23: UnsupportedOperationException Message Content
    // Feature: laravel-payment-framework, Property 23: UnsupportedOperationException message content
    // -------------------------------------------------------------------------
    /** @test */
    public function test_property_23_unsupported_operation_exception_message_content(): void
    {
        // Feature: laravel-payment-framework, Property 23: UnsupportedOperationException message content
        // TODO: Use Set::strings()->atLeast(1) for both driver name and operation name.
        // TODO: Assert getMessage() contains both strings.
        $this->markTestIncomplete('TODO: Implement property test P23 with innmind/black-box.');
    }
}
