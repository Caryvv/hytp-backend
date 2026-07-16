<?php

declare(strict_types=1);

namespace api\tests\Functional;

use api\tests\Support\FunctionalTester;

final class AboutCest
{
    public function checkAbout(FunctionalTester $I): void
    {
        $I->amOnRoute('site/about');
        $I->see('About', 'h1');
    }
}
