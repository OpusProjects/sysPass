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

namespace SP\Tests\Unit\Infrastructure\Adapter\In\Web\View;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A form must not invite more characters than the column it is stored in can hold.
 *
 * Nothing validates a length anywhere: no form checks one, and `Filter::getString()` does not
 * truncate. The column is the only limit there is, and the database enforces it by refusing the row
 * — `STRICT_TRANS_TABLES` is set, so a value that is too long is `ERROR 1406 (22001) Data too long
 * for column 'name'`, not a quietly shortened value.
 *
 * So `maxlength` in the template is what actually stops somebody typing a name that cannot be
 * saved, and three of them were wrong: the tag form and the profile form each offered 50 characters
 * for a `varchar(45)`, and the user form offered 80 for a `varchar(50)` login. Filling the field is
 * the natural thing to do with a length limit, and the reward was a failed save.
 *
 * The rule is one-directional. A limit *below* the column is not a defect — it stores fine — so
 * this only asserts that no form asks for more than fits. `Database` covers the other side: a value
 * that reaches the driver too long now comes back as which field was too long, for the callers that
 * never see a form at all.
 */
#[Group('unitary')]
class FormLimitsFitTheColumnTest extends TestCase
{
    private const VIEWS = REAL_APP_ROOT . '/public/themes/material-blue/views/itemshow';
    private const SCHEMA = REAL_APP_ROOT . '/schemas/dbstructure.sql';

    /**
     * Each text field of an entity form, and the column it is saved to.
     *
     * Written out rather than derived: a field is not always named after its column — the profile
     * form posts `profile_name` into `UserProfile.name` — and guessing the mapping is how a test
     * ends up quietly checking nothing.
     *
     * @return array<string, array{string, string, string, string}>
     */
    public static function fieldProvider(): array
    {
        $fields = [
            ['category', 'name', 'Category', 'name'],
            ['category', 'description', 'Category', 'description'],
            ['client', 'name', 'Client', 'name'],
            ['client', 'description', 'Client', 'description'],
            ['tag', 'name', 'Tag', 'name'],
            ['user', 'name', 'User', 'name'],
            ['user', 'login', 'User', 'login'],
            ['user', 'email', 'User', 'email'],
            ['user_group', 'name', 'UserGroup', 'name'],
            ['user_group', 'description', 'UserGroup', 'description'],
            ['user_profile', 'profile_name', 'UserProfile', 'name'],
        ];

        $cases = [];

        foreach ($fields as [$view, $field, $table, $column]) {
            $cases[sprintf('%s.%s -> %s.%s', $view, $field, $table, $column)] = [$view, $field, $table, $column];
        }

        return $cases;
    }

    #[Test]
    #[DataProvider('fieldProvider')]
    public function aFormNeverAsksForMoreThanTheColumnHolds(
        string $view,
        string $field,
        string $table,
        string $column
    ): void {
        $maxLength = self::maxLengthOf($view, $field);
        $width = self::columnWidth($table, $column);

        self::assertNotNull(
            $maxLength,
            sprintf(
                '%s.inc offers no maxlength for %s, so the browser accepts any length and %s.%s '
                . '(varchar(%d)) refuses the row rather than truncating it',
                $view,
                $field,
                $table,
                $column,
                $width
            )
        );

        self::assertLessThanOrEqual(
            $width,
            $maxLength,
            sprintf(
                '%s.inc lets somebody type %d characters into %s.%s, which holds %d. The save '
                . 'fails at the database with "Data too long for column".',
                $view,
                $maxLength,
                $table,
                $column,
                $width
            )
        );
    }

    /**
     * The `maxlength` of a named field, or null when it has none.
     *
     * PHP blocks are removed before the markup is parsed: these templates interpolate into
     * attributes, and a `?>` inside a tag ends the element as far as a regular expression is
     * concerned — which silently truncates the tag and loses the attributes after it. Getting that
     * wrong reports a field as having no limit when it has one.
     */
    private static function maxLengthOf(string $view, string $field): ?int
    {
        $markup = (string)file_get_contents(sprintf('%s/%s.inc', self::VIEWS, $view));
        $markup = (string)preg_replace('/<\?php.*?\?>/s', ' ', $markup);

        preg_match_all('/<(?:input|textarea)\b[^>]*>/s', $markup, $tags);

        foreach ($tags[0] as $tag) {
            if (preg_match(sprintf('/name="%s"/', preg_quote($field, '/')), $tag) !== 1) {
                continue;
            }

            if (preg_match('/maxlength="(\d+)"/', $tag, $matches) === 1) {
                return (int)$matches[1];
            }

            return null;
        }

        self::fail(sprintf('No <input> or <textarea> named %s in %s.inc', $field, $view));
    }

    private static function columnWidth(string $table, string $column): int
    {
        $schema = (string)file_get_contents(self::SCHEMA);

        self::assertSame(
            1,
            preg_match(sprintf('/CREATE TABLE `%s`(.*?)\n\)/s', preg_quote($table, '/')), $schema, $definition),
            sprintf('No CREATE TABLE for %s', $table)
        );

        self::assertSame(
            1,
            preg_match(sprintf('/`%s`\s+varchar\((\d+)\)/', preg_quote($column, '/')), $definition[1], $matches),
            sprintf('%s.%s is not a varchar', $table, $column)
        );

        return (int)$matches[1];
    }
}
