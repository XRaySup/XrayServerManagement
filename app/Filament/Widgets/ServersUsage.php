<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\ServerResource;
use App\Models\Server;
//use Filament\Tables;
use Filament\Tables\Columns\Summarizers\Summarizer;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class ServersUsage extends BaseWidget
{
    protected int | string | array $columnSpan = "full";
    public function table(Table $table): Table
    {
        ini_set('max_execution_time', 120);
        $servers = Server::all();
        $totalTodayUsage = 0;
        $totalYesterdayUsage = 0;
        $totalWeeklyUsage = 0;

        foreach ($servers as $server) {

            $totalTodayUsage += $server->todayUsage;
            $totalYesterdayUsage += $server->yesterdayUsage;
            $totalWeeklyUsage += $server->weeklyUsage;
        }


        return $table
            ->query(ServerResource::getEloquentQuery())
            ->columns([

                TextColumn::make('remark')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('today usage')
                    ->sortable()
                    ->summarize(Summarizer::make()
                        ->label($totalTodayUsage)),
                TextColumn::make('yesterday usage')
                    ->sortable()
                    ->summarize(Summarizer::make()
                        ->label($totalYesterdayUsage)),
                TextColumn::make('weekly usage')
                    ->sortable()
                    ->summarize(Summarizer::make()
                        ->label($totalWeeklyUsage))
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(Server::stats())
                    ->default('ONLINE')
            ]);
    }
}
