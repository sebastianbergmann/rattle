<?php declare(strict_types=1);
/*
 * This file is part of sebastian/rattle.
 *
 * (c) Sebastian Bergmann <sebastian@phpunit.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace PHPUnit\Runner\PropertyBased;

use function array_keys;
use function count;
use function getcwd;
use function in_array;
use function min;
use function preg_match;
use function sprintf;
use function str_starts_with;
use function strlen;
use function strpos;
use function substr;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;
use RuntimeException;
use SebastianBergmann\Rattle\Fixture\Phpunit\Classifier;
use Throwable;

/**
 * Stands in for PHPUnit's API for running the examples of properties, which
 * the version of PHPUnit that rattle is developed with does not provide. Its
 * properties take a list of digits that is drawn as PHPUnit's generators draw
 * lists: the choice 1 and a digit for each element, followed by the choice 0.
 */
final class PropertyRunner
{
    private const array PROPERTIES = [
        'Fixture\ClassifierTest::testClassifiesEveryList'  => true,
        'Fixture\ClassifierTest::testCountsAreNotNegative' => false,
    ];

    /**
     * @var list<string>
     */
    private array $properties;

    public static function boot(?string $configurationFile = null, ?string $filter = null): self
    {
        $properties = [];

        foreach (array_keys(self::PROPERTIES) as $id) {
            if ($filter === null || preg_match('/' . $filter . '/i', $id) === 1) {
                $properties[] = $id;
            }
        }

        return new self($properties);
    }

    /**
     * @param list<string> $properties
     */
    private function __construct(array $properties)
    {
        $this->properties = $properties;
    }

    /**
     * @return list<array{id: string, class: string, method: string, file: string}>
     */
    public function properties(): array
    {
        $properties = [];

        foreach ($this->properties as $id) {
            $properties[] = ['id' => $id, 'class' => 'Fixture\ClassifierTest', 'method' => substr($id, (int) strpos($id, '::') + 2), 'file' => __FILE__];
        }

        return $properties;
    }

    /**
     * @param list<int> $choices
     *
     * @return array<string, mixed>
     */
    public function replay(string $id, array $choices, int $timeLimit): array
    {
        if (!in_array($id, $this->properties, true)) {
            throw new RuntimeException(sprintf('Property "%s" does not exist', $id));
        }

        $values   = [];
        $consumed = [];
        $maxima   = [];
        $spans    = [['start' => 0, 'end' => 1, 'label' => 'list', 'parent' => null, 'kind' => null]];

        for ($index = 0; ; $index += 2) {
            $start      = count($consumed);
            $another    = min($choices[$index] ?? 0, 1);
            $consumed[] = $another;
            $maxima[]   = 1;

            if ($another === 0) {
                $spans[] = ['start' => $start, 'end' => $start + 1, 'label' => 'element', 'parent' => 0, 'kind' => 'element'];

                break;
            }

            $values[]   = min($choices[$index + 1] ?? 0, 9);
            $consumed[] = $values[count($values) - 1];
            $maxima[]   = 9;
            $spans[]    = ['start' => $start, 'end' => $start + 2, 'label' => 'element', 'parent' => 0, 'kind' => 'element'];
        }

        $spans[0]['end'] = count($consumed);
        $failure         = null;

        try {
            if (self::PROPERTIES[$id]) {
                new Classifier()->classify($values);
            }
        } catch (Throwable $t) {
            $location = self::relative($t->getFile()) . ':' . $t->getLine();
            $failure  = [
                'kind'        => 'throwable',
                'signature'   => $t::class . ' at ' . $location,
                'class'       => $t::class,
                'location'    => $location,
                'description' => $t::class . ' at ' . $location,
                'message'     => $t->getMessage(),
            ];
        }

        return [
            'outcome' => $failure === null ? 'passed' : 'errored',
            'failure' => $failure,
            'choices' => $consumed,
            'spans'   => $spans,
            'maxima'  => $maxima,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function generate(string $id, int $seed, int $timeLimit): array
    {
        $randomizer = new Randomizer(new Xoshiro256StarStar($seed));
        $choices    = [];

        while ($randomizer->getInt(0, 4) !== 0) {
            $choices[] = 1;
            $choices[] = $randomizer->getInt(0, 9);
        }

        $choices[] = 0;

        return $this->replay($id, $choices, $timeLimit);
    }

    private static function relative(string $file): string
    {
        $prefix = getcwd() . '/';

        return str_starts_with($file, $prefix) ? substr($file, strlen($prefix)) : $file;
    }
}
