<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Http;

class Server extends Model
{
    protected $fillable = [
        'name',
        'address',
        'remark',
        'tags',
        'ipv4',
        'ipv6',
        'ssh_user',
        'ssh_password',
        'xui_port',
        'xui_username',
        'xui_password',
        'domain',
        'owner_id',
        'project_id',
        'sessionCookie', // Use 'sessionCookie' as the column name
    ];

    protected $casts = [
        'tags' => 'array',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Owner::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function loginAndGetSessionCookie()
    {
        $loginUrl = $this->address . "/login";

        $loginPayload = [
            'username' => $this->xui_username,
            'password' => $this->xui_password,
        ];

        $loginResponse = Http::post($loginUrl, $loginPayload);

        // Check for HTTP errors
        if ($loginResponse->failed()) {
            // Handle the error or log it
            // You might want to return an empty string or handle it based on your needs
            return '';
        }

        // Extract session cookie from the response headers
        $cookies = $loginResponse->header('Set-Cookie');

        // Update the 'sessionCookie' column in the model
        $this->update(['sessionCookie' => $cookies]);

        return $cookies;
    }
}
