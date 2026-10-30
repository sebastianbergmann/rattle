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
use const PHP_VERSION;
use function file_get_contents;
use function file_put_contents;
use function glob;
use function hash;
use function is_array;
use function is_dir;
use function json_decode;
use function json_encode;
use function mkdir;
use function sort;
use function sprintf;
use JsonException;
use SebastianBergmann\ChoiceSequence\ChoiceSequence;
use SebastianBergmann\ChoiceSequence\Encoder;

/**
 * Stores findings in the findings directory of a target, one directory per
 * finding, whose name is the hexadecimal SHA-256 of the kind and the text of
 * its failure signature:
 *
 * - input:        the minimal input, in the format "choice-sequence v1"
 * - original:     the input as it was found
 * - input.bin:    the minimal input as raw bytes, for bytes targets only
 * - finding.json: how the input made the target fail, the values it draws,
 *                 the seed of the run, and the versions of rattle and PHP
 * - README.txt:   the command that reproduces the finding
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This class is not covered by the backward compatibility promise for sebastian/rattle
 */
final readonly class FindingStore
{
    private string $directory;

    /**
     * @return non-empty-string
     */
    public static function idOf(Failure $failure): string
    {
        return hash('sha256', $failure->kind() . "\n" . $failure->describe());
    }

    /**
     * @param string $directory the findings directory of the target
     */
    public function __construct(string $directory)
    {
        $this->directory = $directory;
    }

    public function directory(): string
    {
        return $this->directory;
    }

    /**
     * @param ?positive-int $maximumLength the maximum length of a bytes target, null for a structured target
     *
     * @throws FindingException
     *
     * @return non-empty-string the directory of the finding
     */
    public function store(Minimization $minimization, string $targetPath, ?int $maximumLength, int $seed): string
    {
        $failure   = $minimization->failure();
        $directory = $this->directory . '/' . self::idOf($failure);
        $encoder   = new Encoder;

        if (!is_dir($directory) && !@mkdir($directory, 0o777, true) && !is_dir($directory)) {
            throw new FindingException(sprintf('Cannot create the directory "%s"', $directory));
        }

        $this->write($directory . '/input', $encoder->encode(new ChoiceSequence($minimization->minimal()->choices())));
        $this->write($directory . '/original', $encoder->encode(new ChoiceSequence($minimization->original()->choices())));

        if ($maximumLength !== null) {
            $this->write($directory . '/input.bin', BytesEncoding::bytesFor($minimization->minimal()->choices(), $maximumLength));
        }

        $shrinking = $minimization->shrinking();

        $this->write(
            $directory . '/finding.json',
            json_encode(
                [
                    'kind'        => $failure->kind(),
                    'signature'   => $failure->describe(),
                    'failure'     => $failure->asArray(),
                    'message'     => $failure->message(),
                    'trace'       => $failure->trace(),
                    'drawnValues' => $minimization->drawnValues(),
                    'flaky'       => $minimization->isFlaky(),
                    'minimized'   => $minimization->minimalInputReproduced(),
                    'shrinking'   => $shrinking === null ? null : [
                        'attempts'        => $shrinking->numberOfAttempts(),
                        'steps'           => $shrinking->numberOfSteps(),
                        'budgetExhausted' => $shrinking->budgetExhausted(),
                        'interrupted'     => $shrinking->interrupted(),
                    ],
                    'seed'          => $seed,
                    'rattleVersion' => Application::version(),
                    'phpVersion'    => PHP_VERSION,
                ],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR,
            ) . "\n",
        );

        $this->write($directory . '/README.txt', $this->readme($minimization, $targetPath, $directory));

        return $directory;
    }

    /**
     * The failures of the findings that are stored, which are known.
     *
     * @return list<Failure>
     */
    public function knownFailures(): array
    {
        $failures = [];

        foreach ($this->findingFiles() as $file) {
            try {
                $data = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);

                if (is_array($data) && isset($data['failure']) && is_array($data['failure'])) {
                    $failures[] = Failure::fromArray($data['failure']);
                }
            } catch (JsonException|ProtocolException) {
                // a finding that cannot be read is not known
            }
        }

        return $failures;
    }

    /**
     * The minimal inputs of the findings that are stored.
     *
     * @return list<string>
     */
    public function inputs(): array
    {
        $inputs = glob($this->directory . '/*/input');

        if ($inputs === false) {
            // @codeCoverageIgnoreStart
            return [];
            // @codeCoverageIgnoreEnd
        }

        sort($inputs);

        return $inputs;
    }

    /**
     * @return list<string>
     */
    private function findingFiles(): array
    {
        $files = glob($this->directory . '/*/finding.json');

        if ($files === false) {
            // @codeCoverageIgnoreStart
            return [];
            // @codeCoverageIgnoreEnd
        }

        sort($files);

        return $files;
    }

    private function readme(Minimization $minimization, string $targetPath, string $directory): string
    {
        $readme = $minimization->failure()->describe() . "\n\n";

        if ($minimization->isFlaky()) {
            $readme .= "The input did not fail in the same way again when it was replayed, and was not minimized.\n\n";
        } elseif (!$minimization->minimalInputReproduced()) {
            $readme .= "The minimal input did not fail in the same way again when it was replayed, so the input as it was found is kept.\n\n";
        }

        return $readme . sprintf(
            "Reproduce it with:\n\n    rattle run %s %s/input\n",
            $targetPath,
            $directory,
        );
    }

    /**
     * @throws FindingException
     */
    private function write(string $path, string $content): void
    {
        if (@file_put_contents($path, $content) === false) {
            throw new FindingException(sprintf('Cannot write "%s"', $path));
        }
    }
}
