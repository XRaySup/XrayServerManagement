<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ServerResource\Pages;
use App\Models\Server;
//use App\Models\Usage;
//use App\Services\ApiConnectorService;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
//use Filament\Notifications\Collection;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\BulkAction;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;



class ServerResource extends Resource
{
    protected static ?string $model = Server::class;

    protected static ?string $slug = 'servers';

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('name')
                    ->required(),
                TextInput::make('address')
                    ->required(),
                TextInput::make('remark'),
                TagsInput::make('tags'),
                TextInput::make('ipv4')
                    ->required(),
                TextInput::make('ipv6'),
                TextInput::make('ssh_user'),
                TextInput::make('ssh_password'),
                TextInput::make('xui_port')->integer(),
                TextInput::make('xui_username'),
                TextInput::make('xui_password'),
                TextInput::make('domain'),
                Select::make('owner_id')
                    ->relationship('owner', 'name')->createOptionForm([
                            TextInput::make('name')
                                ->required()
                        ]),
                Select::make('project_id')
                    ->relationship('project', 'name'),
                Select::make('status')
                    ->options(Server::stats())
                    ->default('DRAFT')

            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('remark'),

                TextColumn::make('owner.name'),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        'ONLINE' => 'success',
                        'OFFLINE' => 'danger',
                        'ARCHIVED' => 'gray',
                        'DRAFT' => 'warning'
                    }),

                TextColumn::make('today usage'),


            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(Server::stats())
                    ->default('ONLINE')
            ])
            ->actions([
                EditAction::make(),
                DeleteAction::make(),
                Action::make('cHost')
                    ->label('Ping')
                    ->action(function (Server $server) {
                        $message = $server->ipv4;
                        $message .= "\n";
                        $CheckHost = new \Alirezax5\CheckHost\CheckHost($server->ipv4);
                        $nodes = ['ir1' => [], 'ir3' => [], 'ir5' => [], 'ir6' => []];
                        foreach ($nodes as $nodeTag => $node) {

                            $CheckHost->node($nodeTag);
                        }
                        $result = $CheckHost->ping();
                        
                        foreach ($result as $nID => $node) {
                            $message .= $nID . ' :  ';
                            
                            $count = 0;
                            if ($node != null) {
                                foreach ($node[0] as $try) {
                                    if ($try[0] == 'OK') {
                                        $count += 1;

                                    }
                                    //dump($try[0]);
                                }
                            }
                            $message .= "$count/4\n";
                        }

                        Notification::make()
                            ->title('Check-Host Results:')
                            ->success()
                            ->body($message)
                            ->send();

                        // Optionally, you can return a message or redirect to a different page
                        return redirect()->back()->with('message', 'Connected to API successfully!');
                    }),
                Action::make('UpdateInbounds')
                    ->label('Reset Inbounds')
                    ->action(function (Server $server) {
                        $server->updateInbounds();

                        // Optionally, you can return a message or redirect to a different page
                        return redirect()->back()->with('message', 'Connected to API successfully!');
                    }),
                Action::make('OpenServerPanel')
                    ->label('Panel')
                    ->url(function (Server $record) {
                        return $record->baseUrl;
                    })
                    ->openUrlInNewTab(),
                Action::make('OpenMobaSSH')
                    ->label('Moba')
                    ->url(function (Server $record) {
                        $link = 'mobaxterm:' . rawurlencode($record->name . '=' . '#109#0%' . $record->ipv4 . '%22%' . $record->ssh_user . '%%-1%-1%sudo su');

                        return $link;
                    })
                    ->openUrlInNewTab(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
                BulkAction::make('newtest')
                    ->label('Test Bulk Action')
                    ->action(function (Collection $servers) {
                        //dd($records);
                        genMultiServersJson($servers,"all");
                        genMultiServersLink($servers,"all");


                    }),
            ])
            ->headerActions([
                Action::make('UpdateSubs')
                    ->label('Update Subscriptions')
                    ->action(function () {

                        $servers = Server::all();
                        $activeServers = $servers->filter(function ($server) {
                            return $server->status === 'ONLINE' || $server->status === 'OFFLINE';
                        });
                        updateServers();
                        Notification::make()
                            ->title('Subscription Updated!')
                            ->success()
                            ->send();

                    }),
                Action::make('updateUsage')
                    ->label('Update Usage')
                    ->action(function () {
                        updateUsages();
                        Notification::make()
                            ->title('Usage Updated!')
                            ->success()
                            ->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListServers::route('/'),
        ];
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['name'];
    }
}
