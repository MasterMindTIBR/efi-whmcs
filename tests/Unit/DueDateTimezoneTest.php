<?php

declare(strict_types=1);

namespace Tests\Unit;

use DateTime;
use DateTimeZone;
use EfiWhmcs\Support\DueDate;
use PHPUnit\Framework\TestCase;

final class DueDateTimezoneTest extends TestCase
{
    public function testForCreationNeverReturnsADateInThePastRegardlessOfServerTimezone(): void
    {
        date_default_timezone_set('Pacific/Kiritimati'); // UTC+14: the furthest-ahead civil timezone.

        $todaySaoPaulo = (new DateTime('today', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d');
        $result = DueDate::forCreation($todaySaoPaulo, 0);

        self::assertGreaterThanOrEqual($todaySaoPaulo, $result);

        date_default_timezone_set('UTC');
    }

    public function testIsAcceptableByEfiComparesAgainstSaoPauloTodayNotServerToday(): void
    {
        date_default_timezone_set('Pacific/Kiritimati');

        $tomorrowSaoPaulo = (new DateTime('tomorrow', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d');

        self::assertTrue(DueDate::isAcceptableByEfi($tomorrowSaoPaulo));

        date_default_timezone_set('UTC');
    }
}
