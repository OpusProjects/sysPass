<?php

declare(strict_types=1);
/*
 * sysPass
 *
 * @author nuxsmin
 * @link https://syspass.org
 * @copyright 2012-2024, Rubén Domínguez nuxsmin@$syspass.org
 *
 * This file is part of sysPass.
 *
 * sysPass is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * sysPass is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with sysPass.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace SP\Tests\Unit\Infrastructure\Adapter\In\Api;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SP\Domain\Api\Ports\HelpInterface;

/**
 * The API's own documentation is the only description of it a caller ever sees.
 *
 * Every REST controller registers a Help class with `setHelpClass()`, and the help it returns is
 * what `/api/docs` publishes and what an `ApiResponse` carries back when a call is malformed. The
 * two are written separately: the controller reads its parameters with `getParam*()`, the Help
 * class declares them with `getItem()`, and nothing connects the two. A parameter renamed in the
 * controller leaves the documentation describing one that no longer exists, and a parameter added
 * leaves callers with no way to discover it — neither of which fails anything at runtime.
 *
 * This is the same class of defect as a route that resolves to no controller: two things that must
 * agree, with nothing making them. It is asserted here against the real sources, by reading the
 * arguments of the calls themselves rather than by invoking anything.
 *
 * Three places may consume a parameter, and all three count as "read":
 *
 *  - the action's own controller,
 *  - the context's `*Base` controller, whose parameters are shared by every action in it,
 *  - and `Api` itself, which consumes `tokenPass` on behalf of every action that needs the master
 *    password — documented per action, because whether it is needed depends on the action.
 */
#[Group('unitary')]
class ApiHelpMatchesControllersTest extends TestCase
{
    private const CONTROLLERS = __DIR__ . '/../../../../../../src/Infrastructure/Adapter/In/Api/Controllers';
    private const API_SERVICE = __DIR__ . '/../../../../../../src/Application/Api/Services/Api.php';

    /**
     * @return array<string, array{string, string, array<string, bool>, array<string, bool>}>
     */
    public static function actionProvider(): array
    {
        $helps = self::helpDefinitions();
        $consumedByApiService = self::parametersIn(file_get_contents(self::API_SERVICE));

        $cases = [];

        foreach (self::registrations() as $context => $helpClass) {
            $base = sprintf('%s/%s/%sBase.php', self::CONTROLLERS, $context, $context);
            $shared = is_file($base) ? self::parametersIn(file_get_contents($base)) : [];

            foreach ($helps[$helpClass] ?? [] as $action => $declared) {
                $controller = sprintf(
                    '%s/%s/%sController.php',
                    self::CONTROLLERS,
                    $context,
                    ucfirst($action)
                );

                $actual = is_file($controller)
                    ? array_merge($shared, self::parametersIn(file_get_contents($controller)))
                    : [];

                $cases[sprintf('%s/%s', $context, $action)] =
                    [$controller, $action, $declared, $actual, $consumedByApiService, $helpClass];
            }
        }

        return $cases;
    }

    /**
     * @param array<string, bool> $declared
     * @param array<string, bool> $actual
     * @param array<string, bool> $apiLevel
     */
    #[Test]
    #[DataProvider('actionProvider')]
    public function everyDocumentedParameterIsOneTheActionActuallyReads(
        string $controller,
        string $action,
        array  $declared,
        array  $actual,
        array  $apiLevel,
        string $helpClass
    ): void {
        self::assertFileExists(
            $controller,
            sprintf('The help declares a "%s" action, but no controller resolves it', $action)
        );

        // Documented but never read: a caller is told to send something that is ignored. The
        // framework-level parameters are consumed by Api itself rather than by the controller, so
        // an action is free to document them — `tokenPass` is documented exactly on the actions
        // that need the master password.
        self::assertSame(
            [],
            array_values(array_diff(array_keys($declared), array_keys($actual), array_keys($apiLevel))),
            sprintf('%s documents parameters nothing reads', basename($controller))
        );

        // Read but undocumented: a caller has no way to discover it.
        self::assertSame(
            [],
            array_values(array_diff(array_keys($actual), array_keys($declared))),
            sprintf('%s reads parameters its help does not document', basename($controller))
        );

        // A parameter documented as optional but read as required fails at call time with no
        // warning from the documentation, which is the worse direction of the two.
        foreach (array_intersect_key($declared, $actual) as $name => $required) {
            self::assertSame(
                $actual[$name],
                $required,
                sprintf('%s: "%s" is documented required=%s but read required=%s',
                    basename($controller), $name,
                    $required ? 'true' : 'false',
                    $actual[$name] ? 'true' : 'false')
            );
        }
    }

    /**
     * Every Help class is registered by exactly one controller context, and every context that
     * registers one has a file for it — otherwise an action publishes no documentation at all.
     */
    #[Test]
    public function everyHelpClassIsRegisteredAndEveryRegistrationResolves(): void
    {
        $helps = array_keys(self::helpDefinitions());
        $registered = array_values(self::registrations());

        self::assertSame([], array_values(array_diff($registered, $helps)), 'Registered help class has no definition');
        self::assertSame([], array_values(array_diff($helps, $registered)), 'Help class is never registered');

        foreach ($helps as $helpClass) {
            $fqcn = 'SP\\Infrastructure\\Adapter\\In\\Api\\Controllers\\Help\\' . $helpClass;

            self::assertTrue(
                is_subclass_of($fqcn, HelpInterface::class),
                sprintf('%s does not implement HelpInterface, so setHelpClass() would reject it', $helpClass)
            );
        }
    }

    /**
     * The help the API would actually answer with, obtained the way `Api::getHelp()` obtains it.
     *
     * This is what exercises the Help classes rather than only reading their source, and it pins
     * the shape `/api/docs` and every error response depend on: a `help` key holding one entry per
     * parameter, each a description and a required flag.
     *
     * @param array<string, bool> $declared
     */
    #[Test]
    #[DataProvider('actionProvider')]
    public function theHelpTheApiAnswersWithHasTheDocumentedShape(
        string $controller,
        string $action,
        array  $declared,
        array  $actual,
        array  $apiLevel,
        string $helpClass
    ): void {
        /** @var class-string<HelpInterface> $fqcn */
        $fqcn = 'SP\\Infrastructure\\Adapter\\In\\Api\\Controllers\\Help\\' . $helpClass;

        $help = $fqcn::getHelpFor($action);

        self::assertArrayHasKey('help', $help, sprintf('%s answers nothing for "%s"', $helpClass, $action));

        $names = [];

        foreach ($help['help'] as $entry) {
            foreach ($entry as $name => $definition) {
                $names[] = $name;

                self::assertIsString($definition['description']);
                self::assertNotSame('', $definition['description']);
                self::assertIsBool($definition['required']);
            }
        }

        self::assertSame(
            array_keys($declared),
            $names,
            sprintf('%s::%s() answers with different parameters than its source declares', $helpClass, $action)
        );
    }

    /**
     * An action reached through a route rather than a bare name resolves to the same help, because
     * `HelpTrait` strips the context before looking the method up.
     */
    #[Test]
    public function aRoutedActionResolvesToTheSameHelpAsABareOne(): void
    {
        $fqcn = 'SP\\Infrastructure\\Adapter\\In\\Api\\Controllers\\Help\\CategoryHelp';

        self::assertSame($fqcn::getHelpFor('view'), $fqcn::getHelpFor('category/view'));
        self::assertSame([], $fqcn::getHelpFor('noSuchAction'));
    }

    /**
     * @return array<string, array<string, array<string, bool>>> help class => action => param => required
     */
    private static function helpDefinitions(): array
    {
        $out = [];

        foreach (glob(self::CONTROLLERS . '/Help/*Help.php') as $file) {
            $source = file_get_contents($file);

            preg_match_all(
                '/public static function (\w+)\(\): array\s*\{(.*?)\n    \}/s',
                $source,
                $matches,
                PREG_SET_ORDER
            );

            foreach ($matches as [, $action, $body]) {
                $items = [];

                foreach (self::argumentsOf($body, 'getItem') as $arguments) {
                    $name = trim($arguments[0], " '\"");
                    $items[$name] = isset($arguments[2]) && trim($arguments[2]) === 'true';
                }

                $out[basename($file, '.php')][$action] = $items;
            }
        }

        return $out;
    }

    /**
     * @return array<string, string> controller context => help class name
     */
    private static function registrations(): array
    {
        $out = [];

        foreach (self::phpFilesUnder(self::CONTROLLERS) as $file) {
            if (preg_match('/setHelpClass\((\w+)::class\)/', file_get_contents($file), $m) === 1) {
                $out[basename(dirname($file))] = $m[1];
            }
        }

        return $out;
    }

    /**
     * The parameters a source reads, as name => required.
     *
     * @return array<string, bool>
     */
    private static function parametersIn(string $source): array
    {
        $out = [];

        foreach (self::argumentsOf($source, 'getParam') as $arguments) {
            $name = trim($arguments[0], " '\"");

            // Skip a call whose name is not a literal — there is nothing to compare it against.
            if (preg_match('/^\w+$/', $name) !== 1) {
                continue;
            }

            $out[$name] = isset($arguments[1]) && trim($arguments[1]) === 'true';
        }

        return $out;
    }

    /**
     * The argument list of every `$name…(` call in the source, split at top level.
     *
     * Written by hand rather than with a regex because an argument is routinely `__('Account Id')`
     * — a nested call containing both parentheses and a quoted comma, which is what makes a naive
     * pattern silently return the wrong argument count and every `required` flag false.
     *
     * @return array<int, array<int, string>>
     */
    private static function argumentsOf(string $source, string $name): array
    {
        $calls = [];
        $offset = 0;

        while (preg_match('/' . preg_quote($name, '/') . '\w*\(/', $source, $m, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $start = $m[0][1] + strlen($m[0][0]);
            $depth = 1;
            $i = $start;
            $quote = null;

            while ($i < strlen($source) && $depth > 0) {
                $char = $source[$i];

                if ($quote !== null) {
                    // A quoted argument may contain an escaped quote — `__('Token\'s password')`
                    // is real. Treating it as a terminator corrupts every argument after it.
                    if ($char === '\\') {
                        $i += 2;

                        continue;
                    }

                    if ($char === $quote) {
                        $quote = null;
                    }
                } elseif ($char === "'" || $char === '"') {
                    $quote = $char;
                } elseif ($char === '(') {
                    $depth++;
                } elseif ($char === ')') {
                    $depth--;
                }

                $i++;
            }

            $calls[] = self::splitTopLevel(substr($source, $start, $i - $start - 1));
            $offset = $i;
        }

        return $calls;
    }

    /**
     * @return array<int, string>
     */
    private static function splitTopLevel(string $arguments): array
    {
        $out = [];
        $depth = 0;
        $current = '';
        $quote = null;

        $escaped = false;

        foreach (str_split($arguments) as $char) {
            if ($quote !== null) {
                $current .= $char;

                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === "'" || $char === '"') {
                $quote = $char;
                $current .= $char;

                continue;
            }

            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
            }

            if ($char === ',' && $depth === 0) {
                $out[] = trim($current);
                $current = '';

                continue;
            }

            $current .= $char;
        }

        if (trim($current) !== '') {
            $out[] = trim($current);
        }

        return $out;
    }

    /**
     * @return array<int, string>
     */
    private static function phpFilesUnder(string $directory): array
    {
        $files = [];

        /** @var \SplFileInfo $file */
        foreach (
            new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory)) as $file
        ) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
