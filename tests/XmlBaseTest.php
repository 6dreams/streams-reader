<?php
declare(strict_types = 1);

namespace SixDreams\Tests;

use PHPUnit\Framework\TestCase;
use SixDreams\StreamReader\XmlStreamReader;

/**
 * Basic test.
 */
class XmlBaseTest extends TestCase
{
    /**
     * Basic test on all thing works ok.
     */
    public function testBasic()
    {
        $collected = [];

        (new XmlStreamReader())
            ->registerCallback('/data', '/data/data', static function (string $xml) use (&$collected) {
                $collected[] = \sprintf("%s\n", $xml);
            })
            ->parse(\fopen(__DIR__ . '/data/test.xml', 'rb'));

        self::assertNotEmpty($collected);
        self::assertCount(2, $collected);
    }
}
