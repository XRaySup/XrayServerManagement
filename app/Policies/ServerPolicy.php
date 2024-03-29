<?php

namespace App\Policies;

use App\Models\Server;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class ServerPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Server $server): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Server $server): bool
    {
        return true;
    }

    public function delete(User $user, Server $server): bool
    {
        return in_array($server->status, ['ARCHIVED', 'DRAFT']);
    }

}
