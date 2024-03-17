<?php

namespace App\Filament\Resources;

use App\Filament\Actions\SmsAction;
use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Table;
use Filament\Tables\Actions\BulkAction;
use Filament\Tables\Columns\BooleanColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Collection;
use STS\FilamentImpersonate\Impersonate;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $slug = "users";

    protected static ?string $recordTitleAttribute = "name";
    protected static ?string $navigationGroup = "تعاریف";
    protected static ?string $modelLabel = "کاربر";
    protected static ?string $pluralModelLabel = "کاربران";
    protected static ?string $navigationIcon = "heroicon-o-users";

    public static function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make("name")
                ->label("نام")
                ->required(),

            TextInput::make("email")
                ->label("ایمیل")
                ->email()
                ->required()
                ->unique(ignoreRecord: true),

            TextInput::make("mobile")
                ->label("موبایل")
                ->required()
                ->extraAttributes(["style" => "direction:ltr"])
                ->unique(ignoreRecord: true),

            TextInput::make("password")
                ->password()
                ->dehydrateStateUsing(fn($state) => \Hash::make($state))
                ->dehydrated(fn($state) => filled($state))
                ->required(fn(string $context): bool => $context === "create")
                ->label("پسورد"),

//            Toggle::make("active")
//                ->label("فعال")
//                ->default(fn() => true),
//            CheckboxList::make("roles")
//                ->label("نقش")
//                ->relationship("roles", "label"),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make("name")
                    ->label("نام")
                    ->searchable()
                    ->sortable(),

                TextColumn::make("email")
                    ->label("ایمیل")
                    ->searchable()
                    ->sortable(),

//                TextColumn::make("roles.label")
//                    ->label("نقش‌ها")
//                    ->searchable()
//                    ->sortable(),

                TextColumn::make("mobile")
                    ->searchable()
                    ->label("موبایل"),

//                IconColumn::make("active")
//                    ->boolean()
//                    ->sortable()
//                    ->label("فعال"),
            ])
            ->actions([
//                Impersonate::make("جعل"),
                \Filament\Tables\Actions\EditAction::make("edit-user"),
                DeleteAction::make(),
            ])
            ->defaultSort("id", "desc")
            ->bulkActions([
//                SmsAction::make("users-sms"),
//                BulkAction::make("notification")
//                    ->label("ارسال Notification ")
//                    ->icon("heroicon-o-chat-bubble-oval-left-ellipsis")
//                    ->modalHeading("ارسال Notification به کاربران سامانه")
//                    ->modalSubmitActionLabel("ارسال")
//                    ->form([
//                        Textarea::make("message")
//                            ->label("پیام")
//                            ->required(),
//                    ])
//                    ->action(function (Collection $records, array $data): void {
//                        $records->each->notify(
//                            Notification::make()
//                                ->title(\Auth::user()->name)
//                                ->body($data["message"])
//                                ->success()
//                                ->toDatabase()
//                        );
//                        Notification::make()
//                            ->title("ارسال Notification به کاربران انجام شد.")
//                            ->success()
//                            ->send();
//                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            "index" => Pages\ListUsers::route("/"),
        ];
    }
}
