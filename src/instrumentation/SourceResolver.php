<?php declare(strict_types=1);
/*
 * This file is part of sebastian/rattle.
 *
 * (c) Sebastian Bergmann <sebastian@phpunit.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace SebastianBergmann\Rattle\Instrumentation;

use const DIRECTORY_SEPARATOR;
use function dirname;
use function is_dir;
use function is_file;
use function libxml_clear_errors;
use function libxml_use_internal_errors;
use function str_starts_with;
use function trim;
use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Determines the directories and files whose code is instrumented: those
 * given with --source, otherwise the <directory> and <file> entries in
 * <source><include> of the PHPUnit configuration file, otherwise src/. The
 * configuration file is the one given with --configuration, or the one that
 * PHPUnit looks for in the working directory: phpunit.xml, phpunit.dist.xml,
 * or phpunit.xml.dist. Relative paths are relative to the directory of the
 * configuration file, as for PHPUnit.
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/rattle
 *
 * @internal This class is not covered by the backward compatibility promise for sebastian/rattle
 */
final readonly class SourceResolver
{
    /**
     * @param list<string>      $sources           the directories and files given with --source
     * @param ?non-empty-string $configurationFile the PHPUnit configuration file given with --configuration, null to look for one in the working directory
     *
     * @return list<string>
     */
    public function resolve(array $sources, string $workingDirectory, ?string $configurationFile = null): array
    {
        if ($sources !== []) {
            return $sources;
        }

        if ($configurationFile !== null) {
            $sources = $this->fromConfiguration($configurationFile, dirname($configurationFile));

            if ($sources !== []) {
                return $sources;
            }
        } else {
            foreach (['phpunit.xml', 'phpunit.dist.xml', 'phpunit.xml.dist'] as $configuration) {
                $path = $workingDirectory . DIRECTORY_SEPARATOR . $configuration;

                if (is_file($path)) {
                    $sources = $this->fromConfiguration($path, $workingDirectory);

                    if ($sources !== []) {
                        return $sources;
                    }

                    break;
                }
            }
        }

        if (is_dir($workingDirectory . DIRECTORY_SEPARATOR . 'src')) {
            return [$workingDirectory . DIRECTORY_SEPARATOR . 'src'];
        }

        return [];
    }

    /**
     * @param non-empty-string $path
     *
     * @return list<string>
     */
    private function fromConfiguration(string $path, string $directory): array
    {
        $document = new DOMDocument;

        $previous = libxml_use_internal_errors(true);
        $loaded   = $document->load($path);

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            return [];
        }

        $entries = (new DOMXPath($document))->query('/phpunit/source/include/directory|/phpunit/source/include/file');

        if ($entries === false) {
            // @codeCoverageIgnoreStart
            return [];
            // @codeCoverageIgnoreEnd
        }

        $sources = [];

        foreach ($entries as $entry) {
            if (!$entry instanceof DOMElement) {
                // @codeCoverageIgnoreStart
                continue;
                // @codeCoverageIgnoreEnd
            }

            $value = trim($entry->textContent);

            if ($value === '') {
                continue;
            }

            if (!str_starts_with($value, '/')) {
                $value = $directory . DIRECTORY_SEPARATOR . $value;
            }

            $sources[] = $value;
        }

        return $sources;
    }
}
