<?php

namespace DemoMode\Resources\Pages;

use DemoMode\Resources\DemoSettingResource;
use Filament\Resources\Pages\EditRecord;

class EditDemoSetting extends EditRecord
{
    protected static string $resource = DemoSettingResource::class;
}
