<?php

namespace DemoMode\Resources\Pages;

use DemoMode\DemoManager;
use DemoMode\Models\DemoSetting;
use DemoMode\ProvisioningFailed;
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
            // Look around the live demo as a presenter; demo users are unaffected.
            Action::make('restoreDemo')->label('Open Demo')->icon('heroicon-o-eye')
                ->color('gray')
                ->visible(fn (): bool => app(DemoManager::class)->canRestore())
                ->action(function (): void {
                    app(DemoManager::class)->restore();
                    $this->redirect(route('demo-mode.roles'));
                }),
            Action::make('startDemo')
                ->label(fn (): string => app(DemoManager::class)->liveDemo() ? 'Reset Demo' : 'Start Demo')
                ->icon(fn (): string => app(DemoManager::class)->liveDemo() ? 'heroicon-o-arrow-path' : 'heroicon-o-play')
                ->modal(fn (): bool => app(DemoManager::class)->liveDemo() !== null)
                ->requiresConfirmation(fn (): bool => app(DemoManager::class)->liveDemo() !== null)
                ->modalHeading('Reset the Demo')
                ->modalDescription('This prepares a fresh copy of the selected data. Changes made in the current demo, including by demo users, are discarded once the new copy is ready. You stay on this page.')
                ->action(function (): void {
                    try {
                        app(DemoManager::class)->begin();
                    } catch (\Throwable $exception) {
                        report($exception);
                        Notification::make()->title('Demo could not start')
                            ->body($exception instanceof ProvisioningFailed ? $exception->getMessage()
                                : 'Check the selected models and server logs, then try again.')->danger()->send();

                        return;
                    }
                    $this->redirect(static::getUrl());
                }),
            Action::make('stopDemo')->label('Stop Demo')->icon('heroicon-o-stop')->color('danger')
                ->visible(fn (): bool => app(DemoManager::class)->liveDemo() !== null)
                ->requiresConfirmation()
                ->modalHeading('Stop the Demo')
                ->modalDescription('The demo and every change made in it are deleted. Demo users are signed out on their next request until a new demo is started.')
                ->action(function (): void {
                    app(DemoManager::class)->end();
                    Notification::make()->title('Demo stopped')->success()->send();
                    $this->redirect(static::getUrl());
                }),
        ];
    }
}
