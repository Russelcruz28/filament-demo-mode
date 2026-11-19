<?php

namespace DemoMode\Resources\Pages;

use DemoMode\Resources\DemoSettingResource;
use Filament\Resources\Pages\EditRecord;

class EditDemoSetting extends EditRecord
{
    protected static string $resource = DemoSettingResource::class;

    /** The form shows the saved model list split into navigation groups. */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['model_groups'] = DemoSettingResource::groupModelSelection($data['models'] ?? []);

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['models'] = DemoSettingResource::flattenModelSelection($data['model_groups'] ?? []);
        unset($data['model_groups']);

        return $data;
    }
}
