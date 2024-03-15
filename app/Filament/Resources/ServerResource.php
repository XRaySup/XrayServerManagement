<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ServerResource\Pages;
use App\Models\Server;
use App\Models\Usage;
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
                TextInput::make('address'),
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

            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('address'),

                TextColumn::make('ipv4'),

                TextColumn::make('owner.name'),

                TextColumn::make('today usage'),


            ])
            ->filters([
                //
            ])
            ->actions([
                EditAction::make(),
                DeleteAction::make(),
                Action::make('lastUsage')
                    ->label('Usage')
                    ->action(function (Server $server) {
                        $usage = getServerUsage($server->id) / 1024 / 1024 / 1024;


                        Notification::make()
                            ->title('Latest usage recorded:')
                            ->success()
                            ->body('' . round($usage, 1) . 'GB')
                            ->send();
                        //dump($usage / 1024 / 1024 / 1024);
                        // $lastUsageRow = Usage::where('server_id', $server['id'])
                        // ->where('client_id', null)
                        // ->orderBy('timestamp', 'desc')
                        // ->latest()->first();
                        // dd($lastUsageRow);
            
                        // Optionally, you can return a message or redirect to a different page
                        return redirect()->back()->with('message', 'Connected to API successfully!');
                    }),
                Action::make('UpdateInbounds')
                    ->label('Update Inbounds')
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
                        $link = 'mobaxterm:' . urlencode($record->tag . '=' . '#109#0%' . $record->ipv4 . '%22%' . $record->ssh_user . '%%-1%-1%sudo su');

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
                        genMultiServersJson($servers);
                        genMultiServersLink($servers);

                    }),
            ])
            ->headerActions([
                Action::make('UpdateSubs')
                    ->label('Update Subscriptions')
                    ->action(function () {

                        $servers = Server::all();
                        genMultiServersJson($servers);
                        genMultiServersLink($servers);

                    }),
                Action::make('updateUsage')
                    ->label('Update Usage')
                    ->action(function () {
                        updateUsages();
                        //$servers = Server::all();
                        //genMultiServersJson($servers);
                        //genMultiServersLink($servers);
            

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
