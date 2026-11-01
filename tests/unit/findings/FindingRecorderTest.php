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

use function array_column;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SebastianBergmann\ChoiceSequence\ChoiceSequence;

#[CoversClass(FindingRecorder::class)]
#[UsesClass(Application::class)]
#[UsesClass(Failure::class)]
#[UsesClass(FindingStore::class)]
#[UsesClass(Job::class)]
#[UsesClass(Outcome::class)]
#[UsesClass(Result::class)]
#[UsesClass(SarifReport::class)]
#[Small]
final class FindingRecorderTest extends TestCase
{
    public function testAddsTheNewFindingsAndTheKnownFindingsThatWereFoundAgainToTheReport(): void
    {
        $handler = new class implements FindingHandler
        {
            private bool $found = false;

            public function handle(Result $result, Job $job): bool
            {
                $new = !$this->found;

                $this->found = true;

                return $new;
            }
        };

        $report   = new SarifReport('/project');
        $recorder = new FindingRecorder($handler, $report, 'ParserTest::testRoundTrip');
        $failure  = Failure::throwable(PathTraversal::class, 'src/Loader.php:3', 'message', '');

        $this->assertTrue($recorder->handle($this->failedResult($failure), Job::replay(1, [])));
        $this->assertFalse($recorder->handle($this->failedResult(Failure::timeout('timeout', '')), Job::replay(2, [])));

        $results = SarifResults::in($report->asJson());

        $this->assertSame(['path-traversal', 'timeout'], array_column($results, 'ruleId'));
        $this->assertSame(['ParserTest::testRoundTrip', 'ParserTest::testRoundTrip'], array_column($results, 'property'));
    }

    private function failedResult(Failure $failure): Result
    {
        return new Result(1, Outcome::Failed, $failure, new ChoiceSequence([]), [], null);
    }
}
