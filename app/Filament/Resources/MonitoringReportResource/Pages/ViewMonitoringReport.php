<?php

namespace App\Filament\Resources\MonitoringReportResource\Pages;

use App\Filament\Resources\MonitoringReportResource;
use App\Services\MonitoringReportService;
use Filament\Actions;
use Filament\Infolists\Components;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;

class ViewMonitoringReport extends ViewRecord
{
    protected static string $resource = MonitoringReportResource::class;

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Components\Section::make('Report Details')
                ->schema([
                    Components\TextEntry::make('subject')->label('Subject')->columnSpanFull(),
                    Components\TextEntry::make('recipient')->label('Recipient'),
                    Components\TextEntry::make('triggered_by')->label('Triggered By')->badge()
                        ->color(fn($state) => $state === 'command' ? 'info' : 'warning'),
                    Components\TextEntry::make('status')->badge()
                        ->color(fn($state) => match($state) {
                            'sent'    => 'success',
                            'failed'  => 'danger',
                            'pending' => 'warning',
                            default   => 'gray',
                        }),
                    Components\TextEntry::make('sent_at')->label('Sent At')->dateTime()->placeholder('Not sent yet'),
                    Components\TextEntry::make('created_at')->label('Generated At')->dateTime(),
                    Components\TextEntry::make('error_message')->label('Error')->color('danger')
                        ->visible(fn($record) => !empty($record->error_message))
                        ->columnSpanFull(),
                ])
                ->columns(3),

            Components\Section::make('Issues Summary')
                ->schema([
                    Components\TextEntry::make('summary_display')
                        ->label('')
                        ->state(function ($record): string {
                            $data = $record->summary ?? [];
                            if (!$data) return 'No data.';
                            $data['content_changed'] = $data['content_changed'] ?? ($data['contentChanged'] ?? []);
                            $data['broken_assets'] = $data['broken_assets'] ?? ($data['brokenAssets'] ?? []);

                            $html = '<div style="font-size:13px;line-height:1.8">';

                            $sections = [
                                'down'           => ['label' => '🔴 Sites Down',               'color' => '#dc2626'],
                                'expiring'       => ['label' => '⏰ Domain Expiring ≤7 Days',   'color' => '#d97706'],
                                'content_changed' => ['label' => '📄 Significant Content Change', 'color' => '#d97706'],
                                'broken_assets'   => ['label' => '🔗 Broken Assets (404)',        'color' => '#d97706'],
                            ];

                            foreach ($sections as $key => $meta) {
                                $items = $data[$key] ?? [];
                                if (empty($items)) continue;
                                $html .= "<div style='margin-bottom:16px'>";
                                $html .= "<strong style='color:{$meta['color']}'>{$meta['label']} (" . count($items) . ")</strong><ul style='margin:4px 0 0 16px'>";
                                foreach ($items as $item) {
                                    $url = e($item['url']);
                                    $detail = match ($key) {
                                        'down'           => ' — HTTP ' . e($item['status_code'] ?? '?') . (isset($item['error']) ? ': ' . e($item['error']) : ''),
                                        'expiring'       => ' — expires ' . e($item['expires_at']) . " ({$item['days']}d)",
                                        'content_changed' => ' — ' . implode(', ', array_map(fn($p) => e($p['slug']) . ': ' . e($p['change_percent']) . '%', $item['pages'] ?? [])),
                                        'broken_assets'   => ' — ' . count($item['assets'] ?? []) . ' broken file(s)',
                                        default          => '',
                                    };
                                    $html .= "<li><a href='{$url}' style='color:#2563eb'>{$url}</a>{$detail}</li>";
                                }
                                $html .= '</ul></div>';
                            }

                            $html .= '</div>';
                            return $html;
                        })
                        ->html()
                        ->columnSpanFull(),
                ])
                ->collapsible(),

            Components\Section::make('Email Preview')
                ->schema([
                    Components\ViewEntry::make('body_html')
                        ->label('')
                        ->view('filament.infolists.email-preview')
                        ->viewData(fn($record) => ['bodyHtml' => $record->body_html])
                        ->columnSpanFull(),
                ])
                ->collapsible()
                ->collapsed(),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('resend')
                ->label('Resend')
                ->icon('heroicon-o-paper-airplane')
                ->color('primary')
                ->requiresConfirmation()
                ->modalDescription(fn($record) => "Resend this report to {$record->recipient}?")
                ->action(function ($record) {
                    $report = app(MonitoringReportService::class)->send($record);

                    if ($report->isSent()) {
                        \Filament\Notifications\Notification::make()
                            ->title('Report Resent')
                            ->body("Report sent to {$report->recipient}")
                            ->success()->send();
                    } else {
                        \Filament\Notifications\Notification::make()
                            ->title('Send Failed')
                            ->body($report->error_message)
                            ->danger()->send();
                    }

                    $this->refreshFormData(['status', 'sent_at', 'error_message']);
                }),
        ];
    }
}
