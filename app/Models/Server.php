<?php

namespace App\Models;


use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

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

    public $inbounds;

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



    public static function stats(): array
    {
        return ['ONLINE' => 'ONLINE', 'OFFLINE' => 'OFFLINE', 'ARCHIVED' => 'ARCHIVED', 'DRAFT' => 'DRAFT'];
    }




    public function getBaseUrlAttribute()
    {
        $scheme = 'https';
        $port = $this->xui_port;

        $url = parse_url($this->address);
        if (isset($url['port'])) {
            $port = $url['port'];
        }
        if (!isset($url['host'])) {
            if (isset($url['path'])) {
                $host = $url['path'];
            } else {
                return;
            }
        } else {
            $host = $url['host'];
        }

        if (isset($url['scheme'])) {
            $scheme = $url['scheme'];
        } else {

            $isValid = filter_var($host, FILTER_VALIDATE_IP);
            if ($isValid) {
                $scheme = 'http';
            }
        }
        //dump($port);

        return $scheme . '://' . $host . ':' . $port;
    }

    //public $usage;// = getServerUsage($this->id)/ 1024 / 1024 / 1024;

    // function _() {
    //     print "In BaseClass constructor\n";
    //     dump($this);
    //     $this->usage = getServerUsage($this->id)/ 1024 / 1024 / 1024;
    //     dump($this->usage);
    // }
    // function __construct() {
    //     parent::__construct();
    //     print "In SubClass constructor\n";
    //     $this->usage = getServerUsage($this->id)/ 1024 / 1024 / 1024;
    //     dump($this);
    // }
    public function getTodayUsageAttribute()
    {
        return round($this->getServerUsage(Carbon::today(), now()) / 1024 / 1024 / 1024, 1);
    }
    public function getYesterdayUsageAttribute()
    {
        return round($this->getServerUsage(Carbon::yesterday(), Carbon::today()) / 1024 / 1024 / 1024, 1);
    }

    public function getWeeklyUsageAttribute()
    {
        return round($this->getServerUsage(Carbon::now()->subWeeks(1), Carbon::today()) / 1024 / 1024 / 1024, 1);
    }

    function getServerUsage($start, $end)
    {

        $usageRecords = Usage::where('server_id', $this->id)
            ->whereBetween('created_at', [$start, $end])
            ->where('client_id', null)
            ->get();

        $totalUsage = 0;

        foreach ($usageRecords as $record) {
            // Calculate the increase in usage
            $totalUsage += $record->upIncrease + $record->downIncrease;
        }

        return $totalUsage;
    }

    public function connect()
    {
        if ($this->status == "ARCHIVED") {
            return;
        }
        $inboundStat = $this->getInboundsStat();
        if ($inboundStat == null) {
            $this->update(['status' => 'OFFLINE']);
            return;
        }
        $this->update(['status' => 'ONLINE']);
        $this->update(['inboundStat' => $inboundStat]);
        $url = parse_url($this->address);
        if (isset($url['host'])) {
            $address = $url['host'];
        } elseif (isset($url['path'])) {
            $address = $url['path'];
        } else {
            return;
        }
        foreach ($inboundStat as $index => $inbound) {

            $inbound['settings'] = json_decode($inbound['settings'], true);
            $inbound['streamSettings'] = json_decode($inbound['streamSettings'], true);

            foreach ($inbound['settings']['clients'] as $cid => $client) {
                $clientOutbounds = getClientOutbounds($inbound, $client, $address);
                $clientLinks = getClientLinks($inbound, $client, $address, $this->remark);
                $inbound['settings']['clients'][$cid]['outbounds'] = $clientOutbounds;
                $inbound['settings']['clients'][$cid]['links'] = $clientLinks;
            }
            $inboundStat[$index] = $inbound;
        }
        $this->inbounds = $inboundStat;
    }
    private function getInboundsStat()
    {
        // If the session cookie is empty, initiate the login process to obtain a new cookie
        if (empty($this->sessionCookie)) {
            log::info('No cookies available, login again!');
            $loginResponse = $this->loginAndGetSessionCookie();
            if ($loginResponse['success'] == false) {
                log::error($loginResponse['error']);
                return;
            }
        }
        //$apiUrl = $this->baseUrl;
        $endpoint = '/panel/api/inbounds/list';
        $url = $this->baseUrl . $endpoint;
        $response = $this->makeApiRequest($url, $this->sessionCookie, '', 'GET');
        if ($response['success'] == false) {
            log::error($response['error']);
            $this->update(['status' => 'OFFLINE']);
            return;
        }
        if ($response['data'] == null) {

            Log::info("First wrong cookie. login again!");
            $loginResponse = $this->loginAndGetSessionCookie();
            if ($loginResponse['success'] == false) {
                log::error($loginResponse['error']);

                return;
            }

            $response = $this->makeApiRequest($url, $this->sessionCookie, '', 'GET');
        }

        if ($response['success'] == false) {
            log::error('Login failed for the second time!');

            return;
        }
        if ($response['data'] == null) {

            Log::error("2nd wrong cookie!");
            return;
        }

        return $response['data']['obj'];
    }

    private function loginAndGetSessionCookie()
    {
        $loginUrl = $this->baseUrl . "/login";

        $loginPayload = [
            'username' => $this->xui_username,
            'password' => $this->xui_password,
        ];

        try {
            $loginResponse = Http::post($loginUrl, $loginPayload);
        } catch (ConnectionException $e) {
            // Handle SSL connection error

            Log::error("SSL connection error during login: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        } catch (RequestException $e) {
            // Handle other request errors
            Log::error("Request error during login: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }

        // Check for HTTP errors
        if ($loginResponse->failed()) {
            // Log the error or handle it appropriately
            Log::error("HTTP error during login: " . $loginResponse->status());
            return [
                'success' => false,
                'error' => $loginResponse,
            ];
        }

        $responseJson = $loginResponse->json();

        if ($responseJson['success'] == false) {

            Log::error("Login failed: " . $responseJson['msg']);
            return [
                'success' => false,
                'error' => $responseJson['msg'],
            ];
        }
        // Extract session cookie from the response headers
        $cookies = $loginResponse->header('Set-Cookie');

        // Update the 'sessionCookie' column in the model
        $this->update(['sessionCookie' => $cookies]);

        return [
            'success' => true,
            'error' => null,
        ];
    }

    private function makeApiRequest($url, $cookies, $data = null, $method = 'GET')
    {

        try {
            $response = Http::withHeaders([
                'Accept' => 'application/json',
                'Cookie' => $cookies,
            ])->$method($url, $data);
        } catch (\Exception $e) {
            // Handle exceptions, log errors, or return false as needed
            // You can access the exception message with $e->getMessage()

            return [
                'data' => null,
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }

        $responseJson = $response->json();

        return [
            'data' => $responseJson,
            'success' => true,
            'error' => null,
        ];
    }

    private function updateInbound($newInbound)
    {
        $url = $this->baseUrl . "/panel/api/inbounds/update/" . $newInbound['id'];

        $headers = [
            "Content-Type: application/json",
            "Accept: application/json",
            "Cookie: " . $this->sessionCookie,
            // Add any other headers as needed
        ];

        $options = [
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => json_encode($newInbound),
            ],
        ];

        try {
            $context = stream_context_create($options);
            $response = file_get_contents($url, false, $context);
            $result = json_decode($response, true);
        } catch (Exception $error) {
            echo 'Error: ' . $error->getMessage() . PHP_EOL;
        }
    }
    public function updateInbounds()
    {
        if ($this->inbounds == null) {
            $this->connect();
            if ($this->inbounds == null) {
                return;
            }
        }
        foreach ($this->inbounds as $id => $inbound) {

            $jsonInbound = $this->inboundStat[$id];
            if ($inbound['streamSettings']['security'] == 'reality') {
                // $pair = getRandomKeyPair();

                // echo 'privat Key old: ' . $inbound['parsedStream']['realitySettings']['privateKey'] . ' New: ' . $pair['privateKey'] . ' .' . PHP_EOL;
                // $inbound['parsedStream']['realitySettings']['privateKey'] = $pair['privateKey'];

                // echo 'Public Key old: ' . $inbound['parsedStream']['realitySettings']['settings']['publicKey'] . ' New: ' . $pair['publicKey'] . ' .' . PHP_EOL;
                // $inbound['parsedStream']['realitySettings']['settings']['publicKey'] = $pair['publicKey'];

                // $shortID = bin2hex(random_bytes(4));
                // echo 'shortID old: ' . $inbound['parsedStream']['realitySettings']['shortIds'][0] . ' New: ' . $shortID . ' .' . PHP_EOL;
                // $inbound['parsedStream']['realitySettings']['shortIds'][0] = $shortID;
            }


            foreach ($inbound['settings']['clients'] as $cid => $client) {
                $inbound['settings']['clients'][$cid]['id'] = generateUUID();
                unset($inbound['settings']['clients'][$cid]['outbounds']);
                unset($inbound['settings']['clients'][$cid]['links']);
            }

            $jsonInbound['settings'] = json_encode($inbound['settings'], JSON_PRETTY_PRINT);

            $this->updateInbound($jsonInbound);
        }
    }

    public function updateUsages()
    {

        if ($this->inbounds == null) {
            $this->connect();
            if ($this->inbounds == null) {
                return;
            }
        }

        foreach ($this->inbounds as $index => $inbound) {

            if ($inbound['enable']) {
                $lastUsageRow = Usage::where('server_id', $this->id)
                    ->where('inbound_id', $inbound['id'])
                    ->where('client_id', null)
                    ->orderBy('timestamp', 'desc')
                    ->latest()
                    ->first();
                if ($lastUsageRow === null) {
                    $lastUp = 0;
                    $lastDown = 0;
                } else {
                    $lastUp = $lastUsageRow->up;
                    $lastDown = $lastUsageRow->down;
                }

                // Calculate the increase in usage
                $upIncrease = max(0, $inbound['up'] - $lastUp);
                $downIncrease = max(0, $inbound['down'] - $lastDown);

                Usage::create([
                    'server_id' => $this->id,
                    'inbound_id' => $inbound['id'],
                    'client_id' => null,
                    'up' => $inbound['up'],
                    'down' => $inbound['down'],
                    'upIncrease' => $upIncrease,
                    'downIncrease' => $downIncrease,

                ]);
                foreach ($inbound['clientStats'] as $cid => $client) {
                    if ($client['enable']) {
                        $lastUsageRow = Usage::where('server_id', $this->id)
                            ->where('inbound_id', $inbound['id'])
                            ->where('client_id', $client['id'])
                            ->orderBy('timestamp', 'desc')
                            ->latest()
                            ->first();
                        if ($lastUsageRow === null) {
                            $lastUp = 0;
                            $lastDown = 0;
                        } else {
                            $lastUp = $lastUsageRow->up;
                            $lastDown = $lastUsageRow->down;
                        }
                        // Calculate the increase in usage
                        $upIncrease = max(0, $client['up'] - $lastUp);
                        $downIncrease = max(0, $client['down'] - $lastDown);

                        Usage::create([
                            'server_id' => $this->id,
                            'inbound_id' => $inbound['id'],
                            'client_id' => $client['id'],
                            'up' => $client['up'],
                            'down' => $client['down'],
                            'upIncrease' => $upIncrease,
                            'downIncrease' => $downIncrease,
                        ]);
                    }
                }
            }
        }
    }
}
