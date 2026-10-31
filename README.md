[![CI Status](https://github.com/sebastianbergmann/rattle/workflows/CI/badge.svg)](https://github.com/sebastianbergmann/rattle/actions)
[![codecov](https://codecov.io/gh/sebastianbergmann/rattle/branch/main/graph/badge.svg)](https://codecov.io/gh/sebastianbergmann/rattle)

# rattle

Coverage-guided fuzzer for PHP code that is built on choice sequences.

**Why "rattle"?** When you want to know whether something is put together properly, you pick it up and shake it, and listen for what rattles. rattle does the same to PHP code: it shakes inputs at it, guided by coverage, until something comes loose, such as an uncaught exception, a warning, a timeout, or a fatal error. Then it reduces the input to the smallest one that still makes the same thing rattle.

rattle runs your code with one input after another for minutes or days. It instruments your code to learn which inputs reach code that no input reached before, keeps these inputs in a corpus, and mutates them to reach further. When an input makes your code fail, rattle checks that it fails in the same way again, minimizes it, and stores it as a finding that you can run again, and that your CI can replay to check that the defect stays fixed.

The inputs of rattle are choice sequences of [`sebastian/choice-sequence`](https://github.com/sebastianbergmann/choice-sequence), the same as for PHPUnit's property-based testing. Inputs are represented, shrunk, compared, and stored in the same way by both tools.

## Installation

You can add rattle as a development-time dependency to your project using [Composer](https://getcomposer.org/):

```
composer require --dev sebastian/rattle
```

rattle uses [`nikic/php-parser`](https://github.com/nikic/PHP-Parser) to instrument your code, so the version of `nikic/php-parser` that your project uses must be compatible with the one that rattle requires. A PHAR of rattle that does not have this constraint is planned.

## Fuzz targets

A fuzz target is a PHP file that returns a target: a name and a closure that is run with one input after another. Any throwable that the closure throws is a finding, unless it is an instance of one of the classes that you declare as expected with `expect()`. Any warning, notice, deprecation, or user error that is triggered is a finding as well, unless it is suppressed with `@`.

The name of a target must match `[a-z0-9-]+`. It names the directories of the target's corpus and findings.

### Bytes targets

A bytes target takes one binary string of at most a given length. This is what you need to fuzz a parser:

#### `fuzz/csv-parser.php`
```php
<?php declare(strict_types=1);
use SebastianBergmann\Rattle\Target;

return Target::bytes(
    'csv-parser',
    static function (string $bytes): void {
        (new Parser)->parse($bytes);
    },
    4096,
)->expect(ParseException::class);
```

Raw files can be imported as the first inputs of a bytes target with `--seeds`, and a dictionary of tokens that are inserted into the strings can be used with `--dictionary`.

### Structured targets

A structured target draws primitive values from an `Input` object:

#### `fuzz/csv-round-trip.php`
```php
<?php declare(strict_types=1);
use SebastianBergmann\Rattle\Input;
use SebastianBergmann\Rattle\Target;

return Target::structured(
    'csv-round-trip',
    static function (Input $input): void {
        $separator = $input->pick([',', ';', "\t"]);
        $data      = $input->bytes(1024);

        // throwing any throwable that is not expected is a finding
    },
);
```

`Input` has these methods:

| Method | Draws |
|---|---|
| `bytes(int $maxLength): string` | a binary string of at most `$maxLength` bytes, the empty string is the simplest one |
| `int(int $min, int $max): int` | an integer in the range, the value closest to 0 is the simplest one |
| `bool(): bool` | a boolean, `false` is the simplest one |
| `pick(array $elements): mixed` | one of the elements, the first one is the simplest one |
| `reject(): never` | rejects the input, which is not a finding |

The values are drawn from choices in the same way as PHPUnit's generators draw them. Rich, typed values are what PHPUnit's property-based testing is for.

The target file is loaded after the bootstrap file, by default `vendor/autoload.php`, so it can use the classes of your project.

## Commands

```
rattle fuzz     [options] <target.php>
rattle run      [options] <target.php> <input>
rattle minimize [options] <target.php> <input>
rattle regress  [options] <target.php>
```

`rattle fuzz` fuzzes the target until a limit is reached (`--max-time`, `--max-executions`, `--stop-on-finding`) or until you press Ctrl+C. The seed of the run is always printed: with `--jobs 1` and `--seed <n>`, a run executes the same inputs in the same order every time. By default, rattle starts as many worker processes as your machine has cores.

`rattle run` runs one input once and shows its outcome, how it failed, the stack trace, and the values it drew. The input is a choice-sequence file, or a raw file with `--raw` for a bytes target.

`rattle minimize` minimizes an input that makes the target fail and writes the minimal input to `<input>.minimized`.

`rattle regress` replays every input in the corpus and findings directories of the target once. Its exit code is 1 when any of them fails.

Only the code in your source directories is instrumented: the directories given with `--source`, otherwise the `<directory>` and `<file>` entries in `<source><include>` of `phpunit.xml` or `phpunit.xml.dist`, otherwise `src/`. Code in `vendor/` is never instrumented.

Run `rattle --help` for all options. The exit code is 0 when there is no new finding, 1 when there is at least one new finding, and 2 when the usage or the configuration is invalid.

## What is a finding?

| What happened | Finding |
|---|---|
| a throwable that is not expected | yes, identified by its class and where it was raised |
| a warning, notice, deprecation (unless `--ignore-deprecations`), or user error | yes, identified by its type and where it was triggered |
| the time limit (`--timeout`, 1 second by default) was exceeded | yes |
| a fatal error, for instance because the memory limit was exceeded | yes, identified by where it happened |
| the process ended, because the code called `exit()` or was killed by a signal | yes, identified by the exit status or the signal |
| `reject()` was called, or the input needs too many choices | no |

Two findings are the same when their failure signatures, as defined by `sebastian/choice-sequence`, are the same. A finding that is the same as one that is known is counted, but not stored again.

The paths in locations, and in stack traces, are relative to the working directory in which rattle is started, when the file is below it. This way, findings do not depend on where a project is checked out: a finding that was found on your machine is known in CI as well. Run rattle in the root directory of your project.

The code under test runs in worker processes, which are replaced when they die. Because the supervisor knows which input a worker is executing, a worker that dies is attributed to that input.

## Directory layout

```
.rattle.cache/                    --cache-directory
└── <target>/corpus/<sha256>      the corpus: inputs that reached code that no input before them reached

fuzz/findings/                    --findings-directory
└── <target>/<id>/
    ├── input                     the minimal input
    ├── original                  the input as it was found
    ├── input.bin                 the minimal input as raw bytes (bytes targets only)
    ├── finding.json              how the input failed, the values it drew, the seed, and versions
    └── README.txt                the command that reproduces the finding
```

Inputs are stored in the format of `sebastian/choice-sequence`: the line `choice-sequence v1` followed by one choice per line. The name of a corpus file is the SHA-256 of its content, and the name of a finding's directory is the SHA-256 of its failure signature.

When rattle starts, it replays the corpus to continue where it left off, and treats the findings that are stored as known.

## From findings to regression tests

Commit the findings directory, `fuzz/findings/` by default, to your repository. Once you have fixed a defect, its finding becomes a regression test: run

```
rattle regress fuzz/csv-parser.php
```

in CI. It replays the minimal input of every finding and every input of the corpus once, and fails while any of them still makes the target fail.

## Limitations

* `nikic/php-parser` must be compatible with the version that your project uses, until rattle is distributed as a PHAR.
* Files that are loaded before rattle registers its include hook, such as the files that Composer's autoloader includes eagerly (`autoload.files`), are not instrumented.
* Coverage is collected by instrumented PHP code, which is slower than collecting it in the engine.
