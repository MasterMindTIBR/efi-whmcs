<?php

declare(strict_types=1);

namespace Tests\Unit;

use EfiWhmcs\Boleto\BoletoService;
use PHPUnit\Framework\TestCase;

final class BoletoAmountTest extends TestCase
{
    public function testDiscountEqualsTheDifferenceBetweenBaseAndIssuedAmountWhenReceivedMatches(): void
    {
        self::assertSame(1000, BoletoService::discountCentsForPayment(
            ['base_amount_cents' => 10000],
            9000,
            9000
        ));
    }

    public function testDivergentReceivedAmountNeverProducesADiscount(): void
    {
        self::assertNull(BoletoService::discountCentsForPayment(
            ['base_amount_cents' => 10000],
            9000,
            8999
        ));
    }

    public function testMissingBaseAmountMetadataNeverProducesADiscount(): void
    {
        self::assertNull(BoletoService::discountCentsForPayment([], 9000, 9000));
    }

    public function testNegativeDifferenceIsTreatedAsNoDiscountRatherThanASurcharge(): void
    {
        self::assertNull(BoletoService::discountCentsForPayment(
            ['base_amount_cents' => 9000],
            10000,
            10000
        ));
    }
}
