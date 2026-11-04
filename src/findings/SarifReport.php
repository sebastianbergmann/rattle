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

use const JSON_INVALID_UTF8_SUBSTITUTE;
use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use function array_keys;
use function array_map;
use function array_search;
use function array_values;
use function count;
use function explode;
use function file_put_contents;
use function implode;
use function json_encode;
use function preg_match;
use function rawurlencode;
use function rtrim;
use function sprintf;
use function str_starts_with;
use JsonException;

/**
 * A report of findings in SARIF 2.1.0, the Static Analysis Results
 * Interchange Format, which code scanning, such as GitHub's, shows.
 *
 * Every kind of finding is a rule. The vulnerabilities that the detectors find
 * (see Detectors) are errors with a security severity, and the other kinds of
 * findings are warnings.
 *
 * A result is reported at the location of its finding, with its stack trace,
 * and is identified by the ID of its finding (see FindingStore), so that code
 * scanning recognizes it in the reports of later runs. A finding that is
 * added more than once is reported once.
 *
 * Paths below the working directory are relative to %SRCROOT%, which is the
 * working directory.
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This class is not covered by the backward compatibility promise for sebastian/rattle
 */
final class SarifReport
{
    private const string INFORMATION_URI = 'https://github.com/sebastianbergmann/rattle';

    /**
     * The rules, by their IDs: name, description, level, security severity
     * (for errors), and CWE (for errors).
     *
     * @var array<non-empty-string, array{0: non-empty-string, 1: non-empty-string, 2: 'error'|'warning', 3: ?non-empty-string, 4: ?positive-int}>
     */
    private const array RULES = [
        'path-traversal'         => ['PathTraversal', 'An input controls the path of a file that the code under test operates on.', 'error', '7.5', 22],
        'code-injection'         => ['CodeInjection', 'An input controls code that the code under test includes or evaluates.', 'error', '9.3', 94],
        'command-injection'      => ['CommandInjection', 'An input controls a command that the code under test runs.', 'error', '9.8', 78],
        'unsafe-deserialization' => ['UnsafeDeserialization', 'An input controls the classes of the objects that the code under test unserializes.', 'error', '9.8', 502],
        'uncaught-throwable'     => ['UncaughtThrowable', 'An input makes the code under test throw a throwable that it does not expect.', 'warning', null, null],
        'issue'                  => ['Issue', 'An input makes the code under test trigger a warning, notice, deprecation, or error.', 'warning', null, null],
        'fatal-error'            => ['FatalError', 'An input makes the code under test cause a fatal error, for instance by exceeding the memory limit.', 'warning', null, null],
        'timeout'                => ['Timeout', 'An input makes the code under test exceed the time limit.', 'warning', null, null],
        'process-ended'          => ['ProcessEnded', 'An input makes the process that runs the code under test end, for instance because it calls exit().', 'warning', null, null],
        'version-difference'     => ['VersionDifference', 'An input has another outcome on another version of PHP.', 'warning', null, null],
    ];

    /**
     * The rules of the vulnerabilities, by the classes of their findings.
     *
     * @var array<class-string<Vulnerability>, key-of<self::RULES>>
     */
    private const array VULNERABILITIES = [
        PathTraversal::class         => 'path-traversal',
        CodeInjection::class         => 'code-injection',
        CommandInjection::class      => 'command-injection',
        UnsafeDeserialization::class => 'unsafe-deserialization',
    ];
    private readonly string $workingDirectory;

    /**
     * @var array<non-empty-string, array<string, mixed>>
     */
    private array $results = [];

    /**
     * @param string $workingDirectory the directory that the paths in locations are relative to
     */
    public function __construct(string $workingDirectory)
    {
        $this->workingDirectory = $workingDirectory;
    }

    /**
     * @param ?non-empty-string $property the test ID of the property whose example failed, null for a fuzz target
     * @param ?non-empty-string $target   the name of the fuzz target whose input failed, when several fuzz targets are fuzzed or replayed together
     */
    public function add(Failure $failure, ?string $property, ?string $target = null): void
    {
        $id = FindingStore::idOf($failure->signature());

        if (isset($this->results[$id])) {
            return;
        }

        $rule    = self::ruleOf($failure);
        $message = $failure->describe();

        if ($failure->message() !== '') {
            $message .= ': ' . $failure->message();
        }

        if ($property !== null) {
            $message = sprintf('The property %s fails. %s', $property, $message);
        } elseif ($target !== null) {
            $message = sprintf('The target %s fails. %s', $target, $message);
        }

        $result = [
            'ruleId'    => $rule,
            'ruleIndex' => (int) array_search($rule, array_keys(self::RULES), true),
            'level'     => self::RULES[$rule][2],
            'message'   => ['text' => $message],
        ];

        $frames   = $this->framesOf($failure->trace());
        $location = $this->locationOf($failure);

        // a timeout is reported where the code under test was executing when
        // the time limit was exceeded
        if ($location === null && isset($frames[0]['location']['physicalLocation'])) {
            $location = ['physicalLocation' => $frames[0]['location']['physicalLocation']];
        }

        if ($location !== null) {
            $result['locations'] = [$location];
        }

        $result['partialFingerprints'] = ['rattleFindingId/v1' => $id];

        if ($frames !== []) {
            $result['stacks'] = [['frames' => $frames]];
        }

        if ($property !== null) {
            $result['properties'] = ['property' => $property];
        } elseif ($target !== null) {
            $result['properties'] = ['target' => $target];
        }

        $this->results[$id] = $result;
    }

    /**
     * @return non-negative-int
     */
    public function numberOfResults(): int
    {
        return count($this->results);
    }

    /**
     * @throws JsonException
     */
    public function asJson(): string
    {
        $rules = [];

        foreach (self::RULES as $id => [$name, $description, $level, $severity, $cwe]) {
            $rule = [
                'id'                   => $id,
                'name'                 => $name,
                'shortDescription'     => ['text' => $description],
                'helpUri'              => self::INFORMATION_URI . ($severity === null ? '#what-is-a-finding' : '#detecting-vulnerabilities'),
                'defaultConfiguration' => ['level' => $level],
                'properties'           => ['tags' => ['correctness']],
            ];

            if ($severity !== null) {
                $rule['properties'] = [
                    'tags'              => ['security', sprintf('external/cwe/cwe-%d', (int) $cwe)],
                    'security-severity' => $severity,
                    'precision'         => 'very-high',
                ];
            }

            $rules[] = $rule;
        }

        return json_encode(
            [
                '$schema' => 'https://json.schemastore.org/sarif-2.1.0.json',
                'version' => '2.1.0',
                'runs'    => [
                    [
                        'tool' => [
                            'driver' => [
                                'name'           => 'rattle',
                                'version'        => Application::version(),
                                'informationUri' => self::INFORMATION_URI,
                                'rules'          => $rules,
                            ],
                        ],
                        'originalUriBaseIds' => [
                            '%SRCROOT%' => ['uri' => self::fileUri(rtrim($this->workingDirectory, '/') . '/')],
                        ],
                        'results' => array_values($this->results),
                    ],
                ],
            ],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR,
        ) . "\n";
    }

    /**
     * @throws FindingException
     */
    public function write(string $path): void
    {
        try {
            $json = $this->asJson();
        } catch (JsonException $e) {
            // @codeCoverageIgnoreStart
            throw new FindingException(sprintf('Cannot write the SARIF report: %s', $e->getMessage()), 0, $e);
            // @codeCoverageIgnoreEnd
        }

        if (@file_put_contents($path, $json) === false) {
            throw new FindingException(sprintf('Cannot write the SARIF report to "%s"', $path));
        }
    }

    /**
     * The location of a throwable, or the location of an issue or a fatal
     * error, which its description ends with.
     *
     * @return ?array<string, mixed>
     */
    private function locationOf(Failure $failure): ?array
    {
        $location = $failure->location() ?? $failure->describe();

        if (preg_match('/^(?:.* at )?(.+):(\d+)$/', $location, $matches) !== 1) {
            return null;
        }

        return $this->physicalLocation($matches[1], (int) $matches[2]);
    }

    /**
     * The frames of a stack trace, as rendered by Location, without the
     * lines that tell how often frames are repeated.
     *
     * @return list<array{location: array<string, mixed>}>
     */
    private function framesOf(string $trace): array
    {
        $frames = [];

        foreach (explode("\n", $trace) as $line) {
            if (preg_match('/^(.+):(\d+)(?: (.+))?$/', $line, $matches) !== 1) {
                continue;
            }

            $location = $this->physicalLocation($matches[1], (int) $matches[2]);

            if (isset($matches[3])) {
                $location['message'] = ['text' => $matches[3]];
            }

            $frames[] = ['location' => $location];
        }

        return $frames;
    }

    /**
     * @return array<string, mixed>
     */
    private function physicalLocation(string $file, int $line): array
    {
        $artifact = ['uri' => self::fileUri($file)];

        if (!str_starts_with($file, '/')) {
            $artifact = ['uri' => self::encode($file), 'uriBaseId' => '%SRCROOT%'];
        }

        return [
            'physicalLocation' => [
                'artifactLocation' => $artifact,
                'region'           => ['startLine' => $line],
            ],
        ];
    }

    /**
     * @return key-of<self::RULES>
     */
    private static function ruleOf(Failure $failure): string
    {
        if ($failure->kind() === Failure::THROWABLE) {
            return self::VULNERABILITIES[$failure->className() ?? ''] ?? 'uncaught-throwable';
        }

        if ($failure->isTimeout()) {
            return 'timeout';
        }

        $description = $failure->describe();

        if (str_starts_with($description, 'Difference between PHP versions: ')) {
            return 'version-difference';
        }

        if (str_starts_with($description, 'Fatal error at ')) {
            return 'fatal-error';
        }

        if (str_starts_with($description, 'Process ')) {
            return 'process-ended';
        }

        return 'issue';
    }

    private static function fileUri(string $absolutePath): string
    {
        return 'file://' . self::encode($absolutePath);
    }

    private static function encode(string $path): string
    {
        return implode('/', array_map(rawurlencode(...), explode('/', $path)));
    }
}
