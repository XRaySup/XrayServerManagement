<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ServerResource\Pages;
use App\Models\Server;
use App\Services\ApiConnectorService;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

use Filament\Notifications\Notification;

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
                    ->relationship('owner', 'name')->createOptionForm([TextInput::make('name')
                        ->required()]),
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
                
                TextColumn::make('remark'),

                TextColumn::make('address'),
                
                TextColumn::make('ipv4'),

                TextColumn::make('ipv6'),

                TextColumn::make('ssh_user'),

                TextColumn::make('xui_port'),

                TextColumn::make('xui_username'),

                TextColumn::make('owner.name'),

                TextColumn::make('domain'),
            ])
            ->filters([
                //
            ])
            ->actions([
                EditAction::make(),
                DeleteAction::make(),
                Action::make('connectToApi')
                ->label('Connect to API')
                ->action(function (Server $record) {

            
                    // Use the injected service to connect to the API

                   $record->getInboundsStat();
                    //dd ($record->inboundStat);
                    //echo "Generated Link for ";
                    genServerLinks($record);
                    //generateLink($record->inboundStat,'tt');
                    // Notification::make()
                    // ->title($record->inboundStat->string())
                    // ->success()
                    // ->seconds(5) 
                    // ->send();
                    
                    // Optionally, you can return a message or redirect to a different page
                    return redirect()->back()->with('message', 'Connected to API successfully!');
                }),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
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
