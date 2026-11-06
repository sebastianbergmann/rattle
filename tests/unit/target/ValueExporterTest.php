<?php declare(strict_types=1);
/*
 * This file is part of sebastian/rattle.
 *
 * (c) Sebastian Bergmann <sebastian@phpunit.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace SebastianBergmann\Rattle;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use stdClass;

#[CoversClass(ValueExporter::class)]
#[Small]
final class ValueExporterTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function strings(): array
    {
        return [
            'ASCII'                                                          => ["'abc'", 'abc'],
            'UTF-8'                                                          => ["'äöü'", 'äöü'],
            'single quote'                                                   => ["'it\\'s'", "it's"],
            'control characters'                                             => ['"a\\x00b\\n\\t\\r\\x1B"', "a\x00b\n\t\r\x1B"],
            'invalid UTF-8 and characters that are escaped in double quotes' => ['"\\xFF\\xFE \\"\\$\\\\"', "\xFF\xFE \"\$\\"],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: mixed}>
     */
    public static function otherValues(): array
    {
        return [
            'integer' => ['42', 42],
            'boolean' => ['true', true],
            'null'    => ['NULL', null],
        ];
    }

    #[DataProvider('strings')]
    public function testExportsStrings(string $expected, string $value): void
    {
        $this->assertSame($expected, (new ValueExporter)->export($value));
    }

    #[DataProvider('otherValues')]
    public function testExportsOtherValuesWithVarExport(string $expected, mixed $value): void
    {
        $this->assertSame($expected, (new ValueExporter)->export($value));
    }

    public function testExportsAValueWithCircularReferencesWithoutAnIssue(): void
    {
        $value       = new stdClass;
        $value->self = $value;

        $this->assertStringContainsString("'self' => NULL", (new ValueExporter)->export($value));
    }

    public function testExportsTheTypeOfAValueThatCannotBeExported(): void
    {
        // initializing the lazy object fails when var_export() reads its properties
        $class = new class
        {
            public int $property = 0;
        };

        $value = (new ReflectionClass($class))->newLazyGhost(
            static function (): never
            {
                throw new RuntimeException;
            },
        );

        $this->assertSame('class@anonymous', (new ValueExporter)->export($value));
    }
}
