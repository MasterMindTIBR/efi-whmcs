<?php

declare(strict_types=1);

namespace Tests\Unit;

use EfiWhmcs\Pix\PixService;
use PHPUnit\Framework\TestCase;

final class PixDiscountTest extends TestCase
{
    public function testDiscountUsesTheDurableIssuedBaseRatherThanInvoiceDifference(): void
    {
        self::assertSame(1235, PixService::discountCentsForPayment([
            'base_amount_cents' => 12345,
            'discount_percent' => 10,
        ], 11111, 11111));
    }

    public function testDivergentReceivedAmountDoesNotProduceADiscount(): void
    {
        self::assertNull(PixService::discountCentsForPayment([
            'base_amount_cents' => 10000,
            'discount_percent' => 10,
        ], 9000, 8999));
    }
}
