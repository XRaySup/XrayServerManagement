<?php

namespace App\Filament\Resources\ServerResource\Pages;

use App\Filament\Resources\ServerResource;
use Filament\Resources\Pages\CreateRecord;

class CreateServer extends CreateRecord
{
    protected static string $resource = ServerResource::class;

    protected function getHeaderActions(): array
    {
        return [

        ];
    }
}
