<?php

declare(strict_types=1);

namespace merchant\tests\Functional;

use merchant\tests\Support\FunctionalTester;

final class AboutCest
{
    public function checkAbout(FunctionalTester $I): void
    {
        $I->amOnRoute('site/about');
        $I->see('About', 'h1');
    }
}
