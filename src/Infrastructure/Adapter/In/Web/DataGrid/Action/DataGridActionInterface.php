<?php

declare(strict_types=1);
/**
 * sysPass
 *
 * @author nuxsmin
 * @link https://syspass.org
 * @copyright 2012-2023, Rubén Domínguez nuxsmin@$syspass.org
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

namespace SP\Infrastructure\Adapter\In\Web\DataGrid\Action;

use SP\Domain\Core\UI\IconInterface;

/**
 * Interface DataGridActionInterface
 *
 * @package SP\Infrastructure\Adapter\In\Web\DataGrid
 */
interface DataGridActionInterface
{
    public function setName(string $name): static;

    public function getName(): ?string;

    public function setId(string $id): static;

    public function getId(): ?string;

    public function setTitle(string $title): static;

    public function getTitle(): ?string;

    public function setOnClickFunction(string $function): static;

    public function setOnClickArgs(string $args): static;

    public function getOnClick(): ?string;

    public function setIcon(IconInterface $icon): static;

    public function getIcon(): ?IconInterface;

    public function setSkip(bool $skip): static;

    public function isSkip(): ?bool;

    public function setIsHelper(bool $helper): static;

    public function isHelper(): ?bool;

    public function setFilterRowSource(string $rowSource, mixed $value = 1): static;

    /**
     * @return array<int, array{field: string, value: mixed}>|null
     */
    public function getFilterRowSource(): ?array;

    public function setType(int $type): static;

    public function getType(): ?int;

    /**
     * @return array<string, mixed>|null
     */
    public function getData(): ?array;

    /**
     * @param array<string, mixed> $data
     */
    public function setData(array $data): static;

    public function addData(string $name, mixed $data): static;

    /**
     * @return array<string, mixed>
     */
    public function getAttributes(): array;

    /**
     * @param array<string, mixed> $attributes
     */
    public function setAttributes(array $attributes): static;

    public function addAttribute(string $name, mixed $value): static;

    public function getRuntimeFilter(): ?callable;

    /**
     * Set the reflective method that determines whether the action is displayed
     */
    public function setRuntimeFilter(string $class, string $method): static;

    public function getClassesAsString(): ?string;

    /**
     * @return string[]
     */
    public function getClasses(): array;

    /**
     * @param string[] $classes
     */
    public function setClasses(array $classes): void;

    public function addClass(mixed $value): static;

    /**
     * Returns if the action is used for selecting multiple items
     */
    public function isSelection(): bool;

    /**
     * Returns the runtime function to pass in the row dato to the action
     */
    public function getRuntimeData(): ?callable;
}
