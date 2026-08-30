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

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use SP\Domain\Core\Bootstrap\UriContextInterface;
use SP\Domain\Core\Exceptions\FileNotFoundException;
use SP\Domain\Core\UI\ThemeIconsInterface;
use SP\Domain\CustomField\Services\CustomFieldItem;
use SP\Infrastructure\Adapter\In\Web\View\OutputHandler;
use SP\Infrastructure\Adapter\In\Web\View\Template;
use SP\Infrastructure\Adapter\In\Web\View\TemplateResolverInterface;
use SP\Tests\Support\UnitaryTestCase;

/**
 * Whether a custom field's value is a secret is decided by the row, not by the field's type.
 *
 * `isEncrypted` is a property of the *definition* and the type is a separate select beside it, so
 * "Encrypted" can be — and is — turned on for a textarea holding a recovery phrase, a text field
 * holding an API key, or a url holding a signed one. `ItemTrait::getCustomFieldsForItem()` decrypts
 * every such row before the view sees it, so by the time it reaches this partial the plaintext is
 * simply sitting in `$field->value`.
 *
 * The partial masked inside the `typeName === 'password'` branch alone, so every other type
 * rendered that plaintext in full to anybody who could open the item, whatever
 * `CUSTOMFIELD_VIEW_PASS` said about them. The API is the sibling that has always had it right:
 * `CustomField::valueFor()` masks on `isValueEncrypted` and never looks at the type — so the same
 * field was withheld from a REST caller and handed over by the web page.
 *
 * These render the real partial through a real `Template`, the way `ViewpassEscapesTest` does,
 * because the masking is a property of the template file and of nothing else.
 */
#[Group('unitary')]
class EncryptedCustomFieldsAreMaskedTest extends UnitaryTestCase
{
    private const SECRET = 'correct-horse-battery-staple';

    /**
     * Every type an encrypted value can be stored under is masked, not just `password`.
     *
     * `text`, `url`, `number`, `email` and `date` all fall through to the same generic `<input>`;
     * one of them stands for that branch and `textarea` and `color` have branches of their own.
     *
     * @throws FileNotFoundException
     */
    #[Test]
    #[TestWith(['textarea'])]
    #[TestWith(['text'])]
    #[TestWith(['url'])]
    #[TestWith(['color'])]
    #[TestWith(['password'])]
    public function anEncryptedValueIsMaskedWhateverItsType(string $typeName): void
    {
        $html = $this->render($this->field($typeName, isEncrypted: true, isValueEncrypted: true));

        self::assertStringNotContainsString(self::SECRET, $html);
        self::assertStringContainsString('***', $html);
    }

    /**
     * The permission is what the mask is for, so with it granted the value is shown — otherwise
     * every assertion above would also be satisfied by a partial that never printed a value at all.
     *
     * @throws FileNotFoundException
     */
    #[Test]
    #[TestWith(['textarea'])]
    #[TestWith(['text'])]
    #[TestWith(['url'])]
    #[TestWith(['color'])]
    #[TestWith(['password'])]
    public function theValueIsShownToSomebodyWhoMayViewIt(string $typeName): void
    {
        $html = $this->render(
            $this->field($typeName, isEncrypted: true, isValueEncrypted: true),
            showViewCustomPass: true
        );

        self::assertStringContainsString(self::SECRET, $html);
    }

    /**
     * A field that is not a secret is not masked either. Reading `isValueEncrypted` type-agnostically
     * would be worth nothing if it also swallowed the ordinary text fields most items are made of.
     *
     * @throws FileNotFoundException
     */
    #[Test]
    #[TestWith(['textarea'])]
    #[TestWith(['text'])]
    #[TestWith(['url'])]
    public function anUnencryptedValueIsLeftAlone(string $typeName): void
    {
        $html = $this->render($this->field($typeName, isEncrypted: false, isValueEncrypted: false));

        self::assertStringContainsString(self::SECRET, $html);
    }

    /**
     * A `password` field is masked whether or not the row was encrypted. Somebody who chose that
     * type meant the value to be hidden, and this is what the partial did before the rule was
     * widened — so widening it must not narrow this.
     *
     * @throws FileNotFoundException
     */
    #[Test]
    public function aPasswordTypedFieldIsMaskedEvenWhenItsValueIsNotEncrypted(): void
    {
        $html = $this->render($this->field('password', isEncrypted: false, isValueEncrypted: false));

        self::assertStringNotContainsString(self::SECRET, $html);
        self::assertStringContainsString('***', $html);
    }

    /**
     * An empty field stays empty rather than being masked into a value that was never there —
     * `***` in an edit form's input would be saved back as the literal secret on the next save.
     *
     * @throws FileNotFoundException
     */
    #[Test]
    public function anEmptyEncryptedFieldIsNotMaskedIntoAValue(): void
    {
        $html = $this->render(
            new CustomFieldItem(
                required: false,
                showInList: false,
                help: '',
                definitionId: 1,
                definitionName: 'Recovery phrase',
                typeId: 1,
                typeName: 'textarea',
                typeText: 'Text area',
                moduleId: 10,
                formId: 'recovery_phrase',
                value: '',
                isEncrypted: true,
                isValueEncrypted: false
            )
        );

        self::assertStringNotContainsString('***', $html);
    }

    private function field(string $typeName, bool $isEncrypted, bool $isValueEncrypted): CustomFieldItem
    {
        return new CustomFieldItem(
            required: false,
            showInList: false,
            help: '',
            definitionId: 1,
            definitionName: 'Recovery phrase',
            typeId: 1,
            typeName: $typeName,
            typeText: ucfirst($typeName),
            moduleId: 10,
            formId: 'recovery_phrase',
            value: self::SECRET,
            isEncrypted: $isEncrypted,
            isValueEncrypted: $isValueEncrypted
        );
    }

    /**
     * @throws FileNotFoundException
     */
    private function render(CustomFieldItem $field, bool $showViewCustomPass = false): string
    {
        $resolver = self::createStub(TemplateResolverInterface::class);
        $resolver
            ->method('getTemplateFor')
            ->willReturn(REAL_APP_ROOT . '/public/themes/material-blue/views/common/aux-customfields.inc');

        $template = new Template(
            new OutputHandler(),
            $resolver,
            self::createStub(ThemeIconsInterface::class),
            self::createStub(UriContextInterface::class),
            $this->config->getConfigData(),
            'test'
        );

        $template->addTemplate('aux-customfields');

        // `isView` is what puts a `color` field down its own branch rather than the generic input.
        $template->assign('customFields', [$field]);
        $template->assign('showViewCustomPass', $showViewCustomPass);
        $template->assign('isView', true);
        $template->assign('readonly', '');

        return $template->render();
    }
}
