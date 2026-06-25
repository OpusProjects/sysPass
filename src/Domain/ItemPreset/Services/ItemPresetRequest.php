<?php

declare(strict_types=1);

namespace SP\Domain\ItemPreset\Services;

use SP\Domain\ItemPreset\Models\ItemPreset;

final readonly class ItemPresetRequest
{
    public function __construct(
        private ItemPreset $itemPreset,
        private object $presetData
    ) {
    }

    public function getItemPreset(): ItemPreset
    {
        return $this->itemPreset->dehydrate($this->presetData) ?? $this->itemPreset;
    }
}
