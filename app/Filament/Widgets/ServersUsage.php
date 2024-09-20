<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\ServerResource;
use App\Models\Server;
use App\Models\Usage;
use Carbon\Carbon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\Summarizers\Summarizer;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class ServersUsage extends BaseWidget
{
    protected int | string | array $columnSpan = "full";

    public function table(Table $table): Table
    {
        ini_set('max_execution_time', 120); // Increase to 120 seconds
        // Aggregate the total usage from the Usage model instead of Server model
        $totalTodayUsage = Usage::selectRaw('SUM(upIncrease + downIncrease) / 1024 / 1024 / 1024 as totalTodayUsage')
            ->whereBetween('created_at', [Carbon::today(), now()])
            ->value('totalTodayUsage');

        $totalYesterdayUsage = Usage::selectRaw('SUM(upIncrease + downIncrease) / 1024 / 1024 / 1024 as totalYesterdayUsage')
            ->whereBetween('created_at', [Carbon::yesterday(), Carbon::today()])
            ->value('totalYesterdayUsage');

        $totalWeeklyUsage = Usage::selectRaw('SUM(upIncrease + downIncrease) / 1024 / 1024 / 1024 as totalWeeklyUsage')
            ->whereBetween('created_at', [Carbon::now()->subWeek(), Carbon::today()])
            ->value('totalWeeklyUsage');

        return $table
            ->query(ServerResource::getEloquentQuery())
            ->columns([
                TextColumn::make('remark')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('today usage')
                    ->sortable()
                    ->label('Today Usage')
                    ->summarize(Summarizer::make()
                        ->label('Total: ' . round($totalTodayUsage, 1) . ' GB')),
                TextColumn::make('yesterday usage')
                    ->sortable()
                    ->label('Yesterday Usage')
                    ->summarize(Summarizer::make()
                        ->label('Total: ' . round($totalYesterdayUsage, 1) . ' GB')),
                TextColumn::make('weekly usage')
                    ->sortable()
                    ->label('Weekly Usage')
                    ->summarize(Summarizer::make()
                        ->label('Total: ' . round($totalWeeklyUsage, 1) . ' GB')),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(Server::stats())
                    ->default('ONLINE')
            ]);
            
    }
}