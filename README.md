[![CI Status](https://github.com/sebastianbergmann/rattle/workflows/CI/badge.svg)](https://github.com/sebastianbergmann/rattle/actions)
[![codecov](https://codecov.io/gh/sebastianbergmann/rattle/branch/main/graph/badge.svg)](https://codecov.io/gh/sebastianbergmann/rattle)

# rattle

Coverage-guided fuzzer for PHP code that is built on choice sequences.

**Why "rattle"?** When you want to know whether something is put together properly, you pick it up and shake it, and listen for what rattles. rattle does the same to PHP code: it shakes inputs at it, guided by coverage, until something comes loose, such as an uncaught exception, a warning, a timeout, or a fatal error. Then it reduces the input to the smallest one that still makes the same thing rattle.

rattle runs your code with one input after another for minutes or days. It instruments your code to learn which inputs reach code that no input reached before, keeps these inputs in a corpus, and mutates them to reach further. When an input makes your code fail, rattle checks that it fails in the same way again, minimizes it, and stores it as a finding that you can run again, and that your CI can replay to check that the defect stays fixed.

The inputs of rattle are choice sequences of [`sebastian/choice-sequence`](https://github.com/sebastianbergmann/choice-sequence), the same as for PHPUnit's property-based testing. Inputs are represented, shrunk, compared, and stored in the same way by both tools.

## Background

### Fuzz testing

Fuzz testing runs code with one generated input after another and watches for failures: uncaught exceptions, warnings, timeouts, and fatal errors. It does not compare results with expected values. Its oracle is implicit: the code must not fail, whatever its input. This makes it effective where code processes data that it cannot trust, in parsers, decoders, and validators, for instance.

Coverage-guided fuzzing learns from the code that it runs. The code is instrumented, an input that reaches code that no input reached before is kept, and the inputs that are kept are mutated further. This way, the fuzzer works its way into the code instead of guessing. [Security through chaos](https://phpunit.expert/articles/security-through-chaos.html) shows how a coverage-guided fuzzer found a defect in a CSV parser whose tests covered every line and every branch.

### Why rattle fuzzes the properties of a PHPUnit test suite

A property states what must be true for every input of a kind, and generators describe these inputs ([Property-Based Testing](https://phpunit.expert/articles/property-based-testing.html)). PHPUnit checks a property with a number of random examples and shrinks a counterexample to the smallest input that still falsifies the property ([Smaller input, greater insight](https://phpunit.expert/articles/smaller-input-greater-insight.html)). Property-based testing and fuzz testing both generate inputs, and both reduce a failing input to a small one. They differ in what they know and in how long they run. A property describes its valid inputs and has an explicit oracle, its assertions, and PHPUnit checks it within seconds on every test run. A fuzzer assumes little about its inputs and runs for hours, guided by coverage.

A fuzz target needs what a property already has: a description of valid inputs and a way to tell whether the code behaves as it should. This is why rattle fuzzes the properties of a PHPUnit test suite as they are. As PHPUnit and rattle share choice sequences, rattle mutates the choices from which the generators of a property build its arguments, not the arguments themselves. Every mutated input is therefore a valid input of the property, without a grammar or an encoding of your own. A finding has the same failure signature as a counterexample of PHPUnit that fails in the same way, and `rattle regress --phpunit` replays the findings as regression tests. PHPUnit keeps checking the properties quickly on every test run, while rattle explores their inputs for as long as you let it.

### Fuzz testing and mutation testing

Mutation testing and fuzz testing both make changes on purpose, but to different things and to answer different questions. Mutation testing changes the code under test and asks whether the tests notice: it measures how strong their oracles are. Fuzz testing changes the inputs and asks whether the code fails: it searches for the inputs that the tests did not think of.

Neither replaces the other. A mutation score tells that the tests detect changes to the code for the inputs that they use, not that these are the inputs that matter: the CSV parser in [Security through chaos](https://phpunit.expert/articles/security-through-chaos.html) had a mutation score of 100% for its covered code, and fuzzing still found a defect in it. Conversely, fuzzing a property whose assertions are weak finds little more than the inputs that make the code throw an exception or trigger a warning, and mutation testing tells whether the assertions are strong enough. When rattle mutates, it changes inputs, never your code.

## Installation

You can add rattle as a development-time dependency to your project using [Composer](https://getcomposer.org/):

```
composer require --dev sebastian/rattle
```

rattle uses [`nikic/php-parser`](https://github.com/nikic/PHP-Parser) to instrument your code. When you install rattle with Composer, the version of `nikic/php-parser` that your project uses must be compatible with the one that rattle requires.

The PHAR of rattle does not have this constraint: the dependencies that it bundles are prefixed with [PHP-Scoper](https://github.com/humbug/php-scoper), so they cannot conflict with the dependencies of your project. Only rattle's own namespace, `SebastianBergmann\Rattle`, is not prefixed, so your fuzz targets work with the PHAR as they do with Composer. The PHAR is not published yet. You can build it from a clone of this repository with `ant phar-snapshot`, which writes it to `build/artifacts/rattle-snapshot.phar`, together with a Software Bill of Materials (SBOM) for its download in CycloneDX format, `build/artifacts/rattle-snapshot.phar.cdx.xml`.

The PHAR prints the packages that it bundles with `--manifest`, a Software Bill of Materials (SBOM) in CycloneDX format with `--sbom`, and the `composer.lock` file that it was built with with `--composer-lock`.

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

Raw files can be imported as the first inputs of a bytes target with `--seeds`, and a dictionary of tokens that are inserted into the strings can be used with `--dictionary` (see [The dictionary](#the-dictionary)).

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

## Properties of a PHPUnit test suite

rattle also fuzzes the properties (`#[Property]`) of a PHPUnit test suite:

```
rattle fuzz --phpunit [--configuration phpunit.xml] [--filter <pattern>]
```

This needs a version of PHPUnit that provides its API for running the examples of properties, `PHPUnit\Runner\PropertyBased\PropertyRunner`. The worker processes load it with the bootstrap file, `vendor/autoload.php` by default, and PHPUnit loads its configuration file and the bootstrap script of the configuration as for a test run. PHPUnit generates the arguments of a property from the choices of an input with the generators of the property, runs the property with its full lifecycle, from `setUpBeforeClass()` to `tearDown()`, and enforces the time limit of `--timeout`, which covers generating the arguments as well as running the property. rattle instruments the code in the source directories and keeps the inputs that reach code that no input reached before, as for a fuzz target.

Every property is a target of its own. The properties are fuzzed one after another, and Ctrl+C stops the fuzzing of all of them. They share the time of `--max-time`: every property gets an equal share of the time that is left, so a property that stops early, with `--stop-on-finding`, for instance, leaves its time to the properties after it. `--max-executions` applies to every property. `--corpus` and `--log-inputs` belong to one target, so they can only be used when `--filter` selects one property. The directories of the corpus and of the findings of a property are named after its test class and its test method, followed by a hash of its test ID, for instance `fuzz/findings/parsertest-testroundtrip-1a2b3c4d/`. A finding is identified as PHPUnit identifies the way in which an example falsifies a property, so a finding of rattle and a counterexample of PHPUnit that fail in the same way have the same failure signature.

`rattle run --phpunit <test-id> <input>` runs an input of the property with the given test ID again, which the `README.txt` of a finding shows, and `rattle minimize --phpunit <test-id> <input>` minimizes one. `rattle regress --phpunit` replays the inputs in the corpus and findings directories of every property, so the findings of the properties become regression tests as those of a fuzz target do. It shows the arguments of the example, the values that it drew with `draw()`, and the steps of its state machine as PHPUnit prints them for a counterexample, and the `finding.json` of a finding has them as well.

Unlike for a fuzz target, a warning, notice, or deprecation that a property triggers is handled by PHPUnit and is not a finding, and an example that is skipped or marked as incomplete counts as rejected. An example that PHPUnit aborts because it fails or errors without checking the property, in `setUp()` or `setUpBeforeClass()`, for instance, because a generator raises an exception while the arguments are generated, or because its state machine cannot be run, is not a finding either: as for PHPUnit, it is an error of the property, and rattle stops with exit code 2.

## Commands

```
rattle fuzz     [options] <target.php>
rattle run      [options] <target.php> <input>
rattle minimize [options] <target.php> <input>
rattle regress  [options] <target.php>
```

With `--phpunit`, these commands work on the properties of a PHPUnit test suite instead of a fuzz target, see [Properties of a PHPUnit test suite](#properties-of-a-phpunit-test-suite).

`rattle fuzz` fuzzes the target until a limit is reached (`--max-time`, `--max-executions`, `--stop-on-finding`) or until you press Ctrl+C. The seed of the run is always printed: with `--jobs 1` and `--seed <n>`, a run executes the same inputs in the same order every time. By default, rattle starts as many worker processes as your machine has cores.

`rattle run` runs one input once and shows its outcome, how it failed, the stack trace, and the values it drew. The input is a choice-sequence file, or a raw file with `--raw` for a bytes target.

`rattle minimize` minimizes an input that makes the target fail and writes the minimal input to `<input>.minimized`.

`rattle regress` replays every input in the corpus and findings directories of the target once. Its exit code is 1 when any of them fails.

Only the code in your source directories is instrumented: the directories given with `--source`, otherwise the `<directory>` and `<file>` entries in `<source><include>` of the PHPUnit configuration file, otherwise `src/`. The configuration file is the one given with `--configuration`, otherwise `phpunit.xml`, `phpunit.dist.xml`, or `phpunit.xml.dist` in the working directory, as for PHPUnit. Code in `vendor/` is never instrumented.

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

When unbounded recursion reaches the limit of PHP's call stack, PHP raises the error in whichever call of the recursion exceeds the limit. Which call that is depends on the version of PHP and on how deep the call stack already was. So that the same recursion is always the same finding, rattle identifies this error by the frame of the recursion that comes first, by file and then by line, among the frames that are repeated in its stack trace.

In stack traces, frames that are repeated at least three times in a row are shown once, followed by how often they are repeated.

The code under test runs in worker processes, which are replaced when they die. Because the supervisor knows which input a worker is executing, a worker that dies is attributed to that input.

## How coverage is measured

rattle instruments the code in your source directories while it is included: it adds code that counts how often each edge between two basic blocks is taken while an input is executed. The code is added within lines, so every line of your code keeps its number, and the files and lines in stack traces and findings are the same as without instrumentation.

How often an edge was taken is put into one of the buckets 1, 2, 3, 4–7, 8–15, 16–31, 32–127, and 128+. An input that takes an edge that no input took before, or takes it a number of times that falls into a bucket that was not seen for this edge before, is added to the corpus.

A basic block starts:

* at the start of the body of a function, a method, a closure, a property hook, a `catch`, and a `finally`
* at the start of each body of an `if`, `elseif`, `else`, `for`, `foreach`, `while`, and `do`, in braces, in alternative syntax, or as a single statement, and at the start of each `case` and `default` of a `switch`
* after an `if`, a loop, a `switch`, and a `try` statement, and after a label that `goto` jumps to
* at the right operand of `&&`, `||`, `and`, `or`, and `??`, at the alternative of `?:` and of `? :`, and at the right side of `??=`, which may not be evaluated
* at the body of an arrow function and of a `match` arm
* where a generator is resumed after `yield` and `yield from`
* at the member name after `?->`, which is not evaluated when the object is `null`

These constructs are not instrumented:

* code at the top level of a file: there is no block at the start of a file, and the start of a `try` block and the branch of a ternary that is taken when the condition is true do not start blocks of their own
* expressions that must be constant: default values of parameters and properties, constants, values of enumeration cases, attribute arguments, initializers of `static` variables, and values in `declare`, including closures within them
* the body of an arrow function that returns by reference, which cannot be wrapped without returning a value instead of a reference
* `xor`, which always evaluates both operands, a `match` without a matching arm, and expressions that are aborted by an exception: these do not lead to edges of their own
* code that is not loaded from a file below your source directories, such as code in `vendor/`, code that is passed to `eval()`, and files that are loaded before rattle registers its include hook, such as the files that Composer's autoloader includes eagerly (`autoload.files`)

## The dictionary

A string that your code compares its data with, the magic header of a file format or a keyword, for instance, is unlikely to be generated byte by byte. rattle therefore inserts tokens into the binary strings of inputs: into the bytes of a bytes target, the binary strings that a structured target draws with `bytes()`, and the binary strings that a property draws with PHPUnit's `Generators::bytes()`. The tokens are the ones of a dictionary file that you pass with `--dictionary`, which has one token per line in double quotes, and the constant operands of comparisons that rattle finds in your code while it instruments it. This needs no configuration: a defect behind a check such as `substr($data, 0, 4) !== 'RATL'` is found without a dictionary file.

Tokens are not inserted into the strings of Unicode characters that PHPUnit generates for a parameter of the type `string`, because their choices are code points rather than bytes.

The constant operands are taken from:

* `===`, `!==`, `==`, `!=`, `<`, `<=`, `>`, `>=`, and `<=>`
* the conditions of the arms of a `match` and the values of the cases of a `switch`
* the arguments of functions that compare strings or look for them, such as `str_starts_with()`, `strcmp()`, `in_array()`, and `explode()`

A constant operand is a string, an integer, which becomes its decimal representation, an array whose keys and values are, or a class constant whose value is one of these. A class constant is only resolved when it is declared in the same file and fetched through `self`, `static`, or the name of its class. Operands that are shorter than three bytes, which are generated easily, and operands that are longer than 64 bytes are left out, and at most 4,096 tokens are found. Every comparison in a file that is instrumented counts, whether an input reached it or not.

The status line shows how many tokens the dictionary has (`tokens`). When fuzzing ends, the tokens that were found in comparisons are written to `dictionary.txt` next to the corpus of the target, in the format of `--dictionary`, so that you can look at them and pass them to another fuzzer. When no tokens were found, the `dictionary.txt` of an earlier run is removed.

## Directory layout

```
.rattle.cache/                    --cache-directory
└── <target>/
    ├── corpus/<sha256>           the corpus: inputs that reached code that no input before them reached
    └── dictionary.txt            the tokens that were found in comparisons, in the format of --dictionary

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

* When rattle is installed with Composer, `nikic/php-parser` must be compatible with the version that your project uses. The PHAR does not have this constraint.
* Not every construct is instrumented, see [How coverage is measured](#how-coverage-is-measured).
* Coverage is collected by instrumented PHP code, which is slower than collecting it in the engine.
* To instrument files while they are included, rattle replaces PHP's stream wrapper for `file://` in its worker processes. Other operations of the code under test on files are passed on to PHP's own wrapper, which makes them slower. As with every stream wrapper that is written in PHP, PHP buffers what is read through it: a file that is changed through one handle may still be read as it was through another handle until that handle seeks.
