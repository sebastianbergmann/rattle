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

use function assert;

/**
 * Adds the findings of a run to a SARIF report, the new findings as well as
 * the known ones that the run found again, and passes them on to the handler
 * that stores them.
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This class is not covered by the backward compatibility promise for sebastian/rattle
 */
final readonly class FindingRecorder implements FindingHandler
{
    private FindingHandler $handler;
    private SarifReport $report;

    /**
     * @var ?non-empty-string
     */
    private ?string $property;

    /**
     * @param ?non-empty-string $property the test ID of the property, null for a fuzz target
     */
    public function __construct(FindingHandler $handler, SarifReport $report, ?string $property)
    {
        $this->handler  = $handler;
        $this->report   = $report;
        $this->property = $property;
    }

    public function handle(Result $result, Job $job): bool
    {
        $new = $this->handler->handle($result, $job);

        $failure = $result->failure();

        assert($failure !== null);

        $this->report->add($failure, $this->property);

        return $new;
    }
}
