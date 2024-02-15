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
        'inboundStat',
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
    private function baseUrl(){
        $scheme = 'https';
        $port = $this->xui_port;
        $url = parse_url($this->address);
        if(isset($url['port'])){
            $port = $url['port'];
        }
        if(!isset($url['host'])){
            if(isset($url['path'])){
            $host = $url['path'];
            }else{
                return;
            }
        } else {
            $host = $url['host'];
        }

        if(isset($url['scheme'])){
            $scheme = $url['scheme'];
        } else {
            
            $isValid = filter_var($host, FILTER_VALIDATE_IP);
            if($isValid ){
                $scheme = 'http';
            }
        }


            return $scheme . '://' . $host . ':' .$port ;


    }
    private function loginAndGetSessionCookie()
    {

        $loginUrl = $this->baseUrl() . "/login";
        
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

    public function getInboundsStat()
    {
        $apiUrl = $this->baseUrl();
        $cookies = $this->sessionCookie;
        $endpoint = '/panel/api/inbounds/list';
        $url = $apiUrl . $endpoint;
        
        // If the session cookie is empty, initiate the login process to obtain a new cookie
        if (empty($cookies)) {
            $this->loginAndGetSessionCookie();
            // Attempt the API request using the existing or newly obtained session cookie
            $response = Http::withHeaders([
                'Accept' => 'application/json',
                'Cookie' => $cookies,
        ])->get($url);


            // If the retry fails, you might want to handle it based on your needs
            if ($response->failed()) {
                // Handle the second failure
                return null;
            }
        
        }else {     
            $response = Http::withHeaders([
                'Accept' => 'application/json',
                'Cookie' => $cookies,
            ])->get($url);
            // If the API request fails, attempt to get a new session cookie and retry the API request
            
            if ($response->failed()) {
                $this->loginAndGetSessionCookie();
                $response = Http::withHeaders([
                    'Accept' => 'application/json',
                    'Cookie' => $this->sessionCookie,
                ])->get($url);

                // If the retry fails, you might want to handle it based on your needs
                if ($response->failed()) {
                    // Handle the second failure
                    return null;
                }
            }


        }

    

        // If successful, update the model with the new inboundStat
        $inboundStat = $response->json();

        $this->update(['inboundStat' => $inboundStat['obj']]);

        // ... perform your additional parsing and logging logic here if needed

        return $inboundStat;
        }
    
}
