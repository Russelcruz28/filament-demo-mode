<?php

namespace DemoMode\Resources\Pages;

use DemoMode\DemoManager;
use DemoMode\Models\DemoSetting;
use DemoMode\Resources\DemoSettingResource;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListDemoSettings extends ListRecords
{
    protected static string $resource = DemoSettingResource::class;

    public function mount(): void
    {
        parent::mount();
        DemoSetting::query()->firstOrCreate(['id' => 1], ['models' => array_keys(app(DemoManager::class)->modelOptions())]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('restoreDemo')->label('Restore Demo')->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->visible(fn (): bool => app(DemoManager::class)->canRestore())
                ->action(function (): void {
                    app(DemoManager::class)->restore();
                    $this->redirect(route('demo-mode.roles'));
                }),
            Action::make('startDemo')->label('Start Demo')->icon('heroicon-o-play')
                ->modal(fn (): bool => app(DemoManager::class)->canRestore())
                ->requiresConfirmation(fn (): bool => app(DemoManager::class)->canRestore())
                ->modalHeading('Start a New Demo')
                ->modalDescription('This replaces your saved demo with a fresh sandbox. Previous demo changes will no longer be restorable.')
                ->action(function (): void {
                    try {
                        app(DemoManager::class)->begin();
                    } catch (\Throwable $exception) {
                        report($exception);
                        Notification::make()->title('Demo could not start')
                            ->body('Check the selected models and server logs, then try again.')->danger()->send();

                        return;
                    }
                    $this->redirect(static::getUrl());
                }),
        ];
    }
}
