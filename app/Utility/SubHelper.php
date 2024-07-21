<?php

use App\Models\Server;
use App\Models\Usage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Yaza\LaravelGoogleDriveStorage\Gdrive;
use Illuminate\Support\Facades\File;


function updateUsages()
{
    $servers = Server::all();
    foreach ($servers as $server) {
        $server->updateUsages();
    }
}

function updateServers()
{


    //  updateUsages();
    $servers = Server::all()->sortBy('name');
    //dump ($servers);
    //  genMultiServersJson($servers, 'all');
    //  genMultiServersJson($servers, 'VPN');
    //  genMultiServersJson($servers, 'VIP');
    genMultiServersLink($servers, 'all');
    //  genMultiServersLink($servers, 'VPN');
    //  genMultiServersLink($servers, 'VIP');


}

function genMultiServersJson($servers, $filter)
{
    if ($filter == '') {
        $filter = 'all';
    }
    $config = new JsonConf();

    $dirFragment = $config->getDirFragment();

    $FragmentA = $config->getFragmentA();

    $sockopt = $config->getSockopt();

    $dnsIranDIR = $config->getDnsIranDIR();

    $dns = $config->getDns();

    $rulesIranDIR = $config->getRulesIranDIR();

    $rules = $config->getRules();

    $rulesMagic = $config->getRulesMagic();

    $mux = $config->getMux();

    $sampleLeastPing = json_decode(File::get(base_path('/storage/leastSample-IranDir.json')), 10);
    //$sampleMagic = json_decode(File::get(base_path('/storage/leastSample.json')), 10);
    $baseOutbounds = $sampleLeastPing['outbounds'];
    $serversOutbounds = [];
    $JsonConfigs = [];
    foreach ($servers as $sid => $server) {
        //Connecting to server and get inbounds

        $server->connect();

        if ($server->inbounds == null) {
            continue;
        }

        foreach ($server->inbounds as $index => $inbound) {

            if ($inbound['enable']) {

                foreach ($inbound['settings']['clients'] as $cid => $client) {
                    if ($filter != 'all') {
                        if ($filter == "VPN" && str_contains($client['email'], "VIP")) {
                            continue;
                        } elseif ($filter == "VIP" && !str_contains($client['email'], "VIP")) {
                            continue;
                        }
                    }
                    if ($client['enable']) {
                        $clientOutbounds = $client['outbounds'];
                        if ($clientOutbounds != null) {

                            $serversOutbounds = array_merge($serversOutbounds, $clientOutbounds);
                            foreach ($clientOutbounds as $index => $bound) {
                                $clientOutbounds[$index]['tag'] = 'p' . $index;
                            }
                            //$singleConfOutbounds = array_merge($clientOutbounds,$baseOutbounds);
                            $singleConf[0] = $sampleLeastPing;
                            $singleConf[0]['outbounds'] = array_merge($clientOutbounds, $baseOutbounds);
                            $singleConf[0]['remarks'] = $server->remark . '-' . $sid;
                            unset($singleConf[0]['routing']['rules'][4]);
                            $JsonConfigs = array_merge($JsonConfigs, $singleConf);
                        }
                    }
                }
            }
        }
    }
    $FragmentA[0]['tag'] = "fragmentA";
    $FragmentB = $FragmentA;
    $FragmentB[0]['tag'] = "fragmentB";

    $FragmentB[0]['settings']['fragment']['length'] = "15-30";
    $FragmentB[0]['settings']['fragment']['interval'] = "1-1";

    $count = 0;
    $serversOutboundsfrag = [];
    foreach ($serversOutbounds as $index => $bound) {
        $serversOutbounds[$index]['tag'] = 'pr' . $index;
        $serversOutbounds[$index]['mux'] = $mux;
        $bound['mux'] = $mux;
        if ($bound['streamSettings']['security'] == 'tls') {
            $serversOutboundsfrag[$count] = $bound;
            $serversOutboundsfrag[$count]['tag'] = 'pfA' . $index;
            $serversOutboundsfrag[$count]['streamSettings']['sockopt'] = $sockopt;
            $count += 1;
            $serversOutboundsfrag[$count] = $bound;
            $serversOutboundsfrag[$count]['tag'] = 'pfB' . $index;
            $serversOutboundsfrag[$count]['streamSettings']['sockopt'] = $sockopt;
            $serversOutboundsfrag[$count]['streamSettings']['sockopt']['dialerProxy'] = 'FragmentB';
            $count += 1;
        }
    }

    //$dirFragment,$FragmentA, $FragmentB,
    $outbounds = array_merge($serversOutboundsfrag, $serversOutbounds, $dirFragment, $FragmentA, $FragmentB, $baseOutbounds);

    //dd($dirFragment);
    $sampleLeastPing['outbounds'] = $outbounds;
    $sampleLeastPing['remarks'] = "🕊Toomaj IRDIR🕊";

    $multiLeast[0] = $sampleLeastPing;

    $sampleLeastPing['remarks'] = "🕊Toomaj Special🕊";

    $sampleLeastPing['dns'] = $dns;

    $sampleLeastPing['routing']['rules'] = $rules;

    $multiLeast[1] = $sampleLeastPing;

    $sampleLeastPing['routing']['rules'] = $rulesMagic;
    $sampleLeastPing['remarks'] = "🕊Magic 4 Toomaj🕊";

    $multiLeast[2] = $sampleLeastPing;

    $jsonContent = json_encode($multiLeast, JSON_PRETTY_PRINT);

    // Write JSON content to file

    file_put_contents(public_path('storage/' . $filter . 'MultiLeast.json'), $jsonContent);
    Storage::disk('google')->put('Subs/PanelSubs/' . $filter . 'MultiLeast.json', $jsonContent);



    $jsonContent = json_encode($JsonConfigs, JSON_PRETTY_PRINT);

    //  Write JSON content to file

    file_put_contents(public_path('storage/' . $filter . '-multiConf.json'), $jsonContent);
    Storage::disk('google')->put('Subs/PanelSubs/' . $filter . '-multiConf.json', $jsonContent);
}


function genMultiServersLink($servers, $filter)
{
    if ($filter == '') {
        $filter = 'all';
    }

    $serversLinks = '';
    foreach ($servers as $sid => $server) {
        //Connecting to server and get inbounds
        $linkN = 0;
        if ($server->inbounds == null) {

            $server->connect();
            if ($server->inbounds == null) {

                continue;
            }
        }
        $serversLinks .= "\n";
        $serversLinks .= '### Server: ' . $server['remark'] . ' : ' . $server['name'] . "\n";
        foreach ($server->inbounds as $index => $inbound) {

            if ($inbound['enable']) {

                $serversLinks .= '### Inbound: ' . $inbound['remark'] . "\n";
                foreach ($inbound['settings']['clients'] as $cid => $client) {
                    if ($client['enable']) {
                        if ($filter != 'all') {
                            if ($filter == "VPN" && str_contains($client['email'], "VIP")) {
                                continue;
                            } elseif ($filter == "VIP" && !str_contains($client['email'], "VIP")) {
                                continue;
                            }
                        }
                        $clientLinks = $client['links'];
                        if ($clientLinks != null) {

                            
                            foreach ($clientLinks as $clientLink) {
                                $linkN += 1;
                                $serversLinks .= $clientLink . "-" . $linkN . "\n";
                            }
                        }
                    }
                }
            }
        }
    }
    $Testing  = Gdrive::get('Subs/' . 'Testing.txt');
    $Donatet  = Gdrive::get('Subs/' . 'Donated.txt');
    $serversLinks = $Testing->file . "\n" . $serversLinks . "\n" . $Donatet->file;
    file_put_contents(public_path('storage/' . $filter . '-links.sub'), $serversLinks);
    Storage::disk('google')->put('Subs/PanelSubs/' . $filter . '-links.sub', $serversLinks);
}
function getClientOutbounds($inbound, $client, $address)
{
    $outbounds = [];
    $baseOutbound = [];
    $params = [];
    $stream = $inbound['streamSettings'];
    $inboundSettings = $inbound['settings'];


    $baseOutbound["protocol"] = $inbound['protocol'];
    switch ($inbound['protocol']) {
        case 'trojan':
            $trojanServer['address'] = $address;
            $trojanServer['flow'] = searchKey($inboundSettings, 'flow');
            $trojanServer['level'] = 8;
            $trojanServer['method'] = 'chacha20-poly1305';
            $trojanServer['ota'] = false;
            $trojanServer['password'] = searchKey($inboundSettings, 'password');
            $trojanServer['port'] = searchKey($inboundSettings, 'port');
            $baseOutbound["settings"]['servers'][0] = $trojanServer;
            break;
        case 'vless':

            $users[0]['encryption'] = searchKey($inboundSettings, 'decryption');
            $users[0]['flow'] = searchKey($inboundSettings, 'flow');
            $users[0]['id'] = searchKey($inboundSettings, 'id');
            $users[0]['level'] = 8;
            $users[0]['security'] = 'auto';
            $vnext[0]['address'] = $address;
            $vnext[0]['port'] = $inbound['port'];
            $vnext[0]['users'] = $users;
            $baseOutbound["settings"]['vnext'] = $vnext;
            break;
        default:
            return;
    }



    $streamSettings['network'] = $stream['network'];
    $streamSettings['security'] = $stream['security'];
    $networkSetting = getNetworkSettings($stream);
    $securitySetting = getSecuritySettings($stream, $client);
    $streamSettings[$stream['network'] . 'Settings'] = $networkSetting;
    $streamSettings[$stream['security'] . 'Settings'] = $securitySetting;



    $baseOutbound['streamSettings'] = $streamSettings;

    $outbounds[0] = $baseOutbound;


    if (isset($stream['externalProxy'])) {
        $externalProxies = $stream['externalProxy'];

        if (count($externalProxies) > 0) {

            foreach ($externalProxies as $index => $externalProxy) {
                $newOutbound = $baseOutbound;

                $newSecurity = $externalProxy['forceTls'];
                $dest = $externalProxy['dest'];
                $port = $externalProxy['port'];

                switch ($inbound['protocol']) {
                    case 'trojan':

                        $newOutbound["settings"]['servers'][0]['port'] = (int) $port;
                        $newOutbound["settings"]['servers'][0]['address'] = $dest;
                        break;
                    case 'vless':

                        $newOutbound["settings"]['vnext'][0]['port'] = (int) $port;
                        $newOutbound["settings"]['vnext'][0]['address'] = $dest;
                        break;
                    default:
                        return;
                }

                if ($newSecurity !== 'same') {
                    $params['security'] = $newSecurity;
                    $newOutbound['streamSettings']['security'] = $newSecurity;
                } else {
                    $params['security'] = $stream['security'];
                }
                if ($newSecurity == 'none') {
                    unset($newOutbound['streamSettings'][$stream['security'] . 'Settings']);
                }
                $outbounds[$index] = $newOutbound;
            }
        }
    }
    return $outbounds;
}


function getClientLinks($inbound, $client, $address, $remark)
{

    switch ($inbound['protocol']) {
        case 'vmess':
            return genVmessLink($inbound, $client, $address, $remark);
        case 'vless':
            return genVlessLink($inbound, $client, $address, $remark);
        case 'trojan':
            return genTrojanLink($inbound, $client, $address, $remark);
        case 'shadowsocks':
            return genShadowsocksLink($inbound, $client, $address, $remark);
    }
    return '';
}


function genVmessLink($inbound, $client, $address, $remark)
{
    // Implementation of genVmessLink method in PHP
    // ...

    return '';  // Replace with the actual result
}

// Function to generate VLESS link
function genVlessLink($inbound, $client, $address, $remark)
{

    if ($inbound['protocol'] != 'vless') {
        return '';
    }

    $stream = $inbound['streamSettings'];



    $uuid = $client['id'];
    $port = $inbound['port'];

    $streamNetwork = $stream['network'];

    $params = ['type' => $streamNetwork];

    switch ($streamNetwork) {
        case 'tcp':
            $tcp = $stream['tcpSettings'];
            $header = $tcp['header'];
            $typeStr = $header['type'];

            if ($typeStr == 'http') {
                $request = $header['request'];
                $requestPath = $request['path'][0];
                $params['path'] = $requestPath;
                $headers = $request['headers'];
                $params['host'] = searchKey($headers, 'host');
                $params['headerType'] = 'http';
            }
            break;
        case 'kcp':
            $kcp = $stream['kcpSettings'];
            $header = $kcp['header'];
            $params['headerType'] = $header['type'];
            $params['seed'] = $kcp['seed'];
            break;
        case 'ws':
            $ws = $stream['wsSettings'];
            $params['path'] = $ws['path'];
            $headers = $ws['headers'];
            $params['host'] = searchKey($headers, 'host');
            break;
        case 'http':
            $http = $stream['httpSettings'];
            $params['path'] = $http['path'];
            $headers = $http['headers'];
            $params['host'] = searchKey($headers, 'host');
            break;
        case 'quic':
            $quic = $stream['quicSettings'];
            $params['quicSecurity'] = $quic['security'];
            $params['key'] = $quic['key'];
            $header = $quic['header'];
            $params['headerType'] = $header['type'];
            break;
        case 'grpc':
            $grpc = $stream['grpcSettings'];
            $params['serviceName'] = $grpc['serviceName'];
            if ($grpc['multiMode']) {
                $params['mode'] = 'multi';
            }
            break;
    }

    $security = $stream['security'];

    if ($security == 'tls') {
        $params['security'] = 'tls';
        $tlsSetting = $stream['tlsSettings'];
        $alpns = $tlsSetting['alpn'];

        if (!empty($alpns)) {
            $params['alpn'] = implode(',', $alpns);
        }

        $sniValue = searchKey($tlsSetting, 'serverName');
        if ($sniValue) {
            $params['sni'] = $sniValue;
        }

        $tlsSettings = searchKey($tlsSetting, 'settings');

        if ($tlsSetting !== null) {
            $fpValue = searchKey($tlsSettings, 'fingerprint');

            if ($fpValue !== null) {
                $params['fp'] = $fpValue;
            }

            $insecure = searchKey($tlsSettings, 'allowInsecure');

            if ($insecure !== null && $insecure) {
                $params['allowInsecure'] = '1';
            }
        }

        if ($streamNetwork == 'tcp' && strlen($client['flow']) > 0) {
            $params['flow'] = $client['flow'];
        }
    }

    if ($security == 'reality') {
        $params['security'] = 'reality';
        $realitySetting = $stream['realitySettings'];
        $realitySettings = searchKey($realitySetting, 'settings');

        if ($realitySetting !== null) {
            $sniValue = searchKey($realitySetting, 'serverNames');

            if ($sniValue !== null) {
                $sNames = $sniValue;
                $params['sni'] = $sNames[0];
            }

            $pbkValue = searchKey($realitySettings, 'publicKey');

            if ($pbkValue !== null) {
                $params['pbk'] = $pbkValue;
            }

            $sidValue = searchKey($realitySetting, 'shortIds');

            if ($sidValue !== null) {
                $shortIds = $sidValue;
                $params['sid'] = $shortIds[0];
            }

            $fpValue = searchKey($realitySettings, 'fingerprint');

            if ($fpValue !== null && strlen($fpValue) > 0) {
                $params['fp'] = $fpValue;
            }

            $spxValue = searchKey($realitySettings, 'spiderX');

            if ($spxValue !== null && strlen($spxValue) > 0) {
                $params['spx'] = $spxValue;
            }
        }

        if ($streamNetwork == 'tcp' && strlen($client['flow']) > 0) {
            $params['flow'] = $client['flow'];
        }
    }

    if ($security == 'xtls') {
        $params['security'] = 'xtls';
        $xtlsSetting = $stream['xtlsSettings'];
        $alpns = $xtlsSetting['alpn'];

        if (!empty($alpns)) {
            $params['alpn'] = implode(',', $alpns);
        }

        if (isset($xtlsSetting['serverName'])) {
            $params['sni'] = $xtlsSetting['serverName'];
        }

        $xtlsSettings = searchKey($xtlsSetting, 'settings');

        if ($xtlsSetting !== null) {
            $fpValue = searchKey($xtlsSettings, 'fingerprint');

            if ($fpValue !== null) {
                $params['fp'] = $fpValue;
            }

            $insecure = searchKey($xtlsSettings, 'allowInsecure');

            if ($insecure !== null && $insecure) {
                $params['allowInsecure'] = '1';
            }
        }

        if ($streamNetwork == 'tcp' && strlen($client['flow']) > 0) {
            $params['flow'] = $client['flow'];
        }
    }
    $links = [];
    if (isset($stream['externalProxy'])) {
        $externalProxies = $stream['externalProxy'];

        if (count($externalProxies) > 0) {


            foreach ($externalProxies as $index => $externalProxy) {
                $newSecurity = $externalProxy['forceTls'];
                $dest = $externalProxy['dest'];
                $port = (int) $externalProxy['port'];
                $link = sprintf('vless://%s@%s:%d', $uuid, $dest, $port);

                // Add logic for setting $params based on $newSecurity

                $url = parse_url($link);

                //parse_str($url['query'], $q);

                foreach ($params as $k => $v) {
                    if (!($newSecurity == 'none' && ($k == 'alpn' || $k == 'sni' || $k == 'fp' || $k == 'allowInsecure'))) {
                        $q[$k] = $v;
                    }
                }

                // Set the new query values on the URL
                $url['query'] = http_build_query($q, '', '&');
                $url['fragment'] = $remark; //$this->genRemark($inbound, $email, '');

                if ($externalProxy['remark'] != '') {

                    $url['fragment'] = $url['fragment'] . '-' . $externalProxy['remark'];
                }


                // if ($index > 0) {
                //     $links .= "\n";
                // }

                $links[$index] = http_build_url($url);
            }
            return $links;
        }
    }

    $link = sprintf('vless://%s@%s:%d', $uuid, $address, $port);
    $url = parse_url($link);
    //parse_str($url['query'], $q);

    foreach ($params as $k => $v) {
        $q[$k] = $v;
    }

    // Set the new query values on the URL
    $url['query'] = http_build_query($q, '', '&');
    $url['fragment'] = $remark; //$this->genRemark($inbound, $email, '');
    $links[0] = http_build_url($url);
    return $links;
}

function genTrojanLink($inbound, $client, $address, $remark)
{

    if ($inbound['protocol'] !== 'trojan') {
        return '';
    }
    $stream = $inbound['streamSettings'];


    $password = $client['password'];
    $port = $inbound['port'];

    $streamNetwork = $stream['network'];

    $params = ['type' => $streamNetwork];

    switch ($streamNetwork) {
        case 'tcp':
            $tcp = $stream['tcpSettings'];
            $header = $tcp['header'];
            $typeStr = $header['type'];

            if ($typeStr == 'http') {
                $request = $header['request'];
                $requestPath = $request['path'][0];
                $params['path'] = $requestPath;
                $headers = $request['headers'];
                $params['host'] = searchKey($headers, 'host');
                $params['headerType'] = 'http';
            }
            break;
        case 'kcp':
            $kcp = $stream['kcpSettings'];
            $header = $kcp['header'];
            $params['headerType'] = $header['type'];
            $params['seed'] = $kcp['seed'];
            break;
        case 'ws':
            $ws = $stream['wsSettings'];
            $params['path'] = $ws['path'];
            $headers = $ws['headers'];
            $params['host'] = searchKey($headers, 'host');
            break;
        case 'http':
            $http = $stream['httpSettings'];
            $params['path'] = $http['path'];
            $headers = $http['headers'];
            $params['host'] = searchKey($headers, 'host');
            break;
        case 'quic':
            $quic = $stream['quicSettings'];
            $params['quicSecurity'] = $quic['security'];
            $params['key'] = $quic['key'];
            $header = $quic['header'];
            $params['headerType'] = $header['type'];
            break;
        case 'grpc':
            $grpc = $stream['grpcSettings'];
            $params['serviceName'] = $grpc['serviceName'];
            if ($grpc['multiMode']) {
                $params['mode'] = 'multi';
            }
            break;
    }

    $security = $stream['security'];

    if ($security == 'tls') {
        $params['security'] = 'tls';
        $tlsSetting = $stream['tlsSettings'];
        $alpns = $tlsSetting['alpn'];

        if (!empty($alpns)) {
            $params['alpn'] = implode(',', $alpns);
        }
        $sniValue = searchKey($tlsSetting, 'serverName');
        if ($sniValue) {
            $params['sni'] = $sniValue;
        }

        $tlsSettings = searchKey($tlsSetting, 'settings');

        if ($tlsSetting !== null) {
            $fpValue = searchKey($tlsSettings, 'fingerprint');

            if ($fpValue !== null) {
                $params['fp'] = $fpValue;
            }

            $insecure = searchKey($tlsSettings, 'allowInsecure');

            if ($insecure !== null && $insecure) {
                $params['allowInsecure'] = '1';
            }
        }
    }

    if ($security == 'reality') {
        $params['security'] = 'reality';
        $realitySetting = $stream['realitySettings'];
        $realitySettings = searchKey($realitySetting, 'settings');
        if ($realitySetting !== null) {
            $sniValue = searchKey($realitySetting, 'serverNames');

            if ($sniValue !== null) {
                $sNames = $sniValue;
                $params['sni'] = $sNames[0];
            }

            $pbkValue = searchKey($realitySettings, 'publicKey');

            if ($pbkValue !== null) {
                $params['pbk'] = $pbkValue;
            }

            $sidValue = searchKey($realitySetting, 'shortIds');

            if ($sidValue !== null) {
                $shortIds = $sidValue;
                $params['sid'] = $shortIds[0];
            }

            $fpValue = searchKey($realitySettings, 'fingerprint');

            if ($fpValue !== null && strlen($fpValue) > 0) {
                $params['fp'] = $fpValue;
            }

            $spxValue = searchKey($realitySettings, 'spiderX');

            if ($spxValue !== null && strlen($spxValue) > 0) {
                $params['spx'] = $spxValue;
            }
        }
        $flowValue = searchKey($client, 'Flow');
        if ($streamNetwork == 'tcp' && strlen($flowValue) > 0) {
            $params['flow'] = $flowValue;
        }
    }

    if ($security == 'xtls') {
        $params['security'] = 'xtls';
        $xtlsSetting = $stream['xtlsSettings'];
        $alpns = $xtlsSetting['alpn'];
        $params['alpn'] = implode(',', $alpns);
        $sniValue = searchKey($xtlsSetting, 'serverName');
        if ($sniValue) {
            $params['sni'] = $sniValue;
        }

        $xtlsSettings = searchKey($xtlsSetting, 'settings');
        if ($xtlsSetting) {
            if ($fpValue = searchKey($xtlsSettings, 'fingerprint')) {
                $params['fp'] = $fpValue;
            }
            if ($insecure = searchKey($xtlsSettings, 'allowInsecure')) {
                if ($insecure) {
                    $params['allowInsecure'] = '1';
                }
            }
        }

        if ($streamNetwork == 'tcp' && strlen($client['Flow']) > 0) {
            $params['flow'] = $client['Flow'];
        }
    }

    if ($security !== 'tls' && $security !== 'reality' && $security !== 'xtls') {
        $params['security'] = 'none';
    }
    if (isset($stream['externalProxy'])) {
        $externalProxies = $stream['externalProxy'];

        if (count($externalProxies) > 0) {
            $links = [];
            foreach ($externalProxies as $index => $externalProxy) {
                $ep = $externalProxy;
                $newSecurity = $ep['forceTls'];
                $dest = $ep['dest'];
                $port = $ep['port'];
                $link = sprintf("trojan://%s@%s:%d", $password, $dest, $port);

                if ($newSecurity !== 'same') {
                    $params['security'] = $newSecurity;
                } else {
                    $params['security'] = $security;
                }
                $url = parse_url($link);
                $q = [];
                foreach ($params as $k => $v) {
                    if (!($newSecurity === 'none' && ($k === 'alpn' || $k === 'sni' || $k === 'fp' || $k === 'allowInsecure'))) {
                        $q[] = "$k=$v";
                    }
                }
                $url['query'] = implode('&', $q);

                $url['fragment'] = $remark; //$this->genRemark($inbound, $email, '');

                if ($externalProxy['remark'] != '') {

                    $url['fragment'] = $url['fragment'] . '-' . $externalProxy['remark'];
                }


                // if ($index > 0) {
                //     $links .= "\n";
                // }

                $links[$index] = http_build_url($url);
            }

            return $links;
        }
    }

    $link = sprintf("trojan://%s@%s:%d", $password, $address, $port);

    $url = parse_url($link);
    $q = [];
    foreach ($params as $k => $v) {
        $q[] = "$k=$v";
    }
    $url['query'] = implode('&', $q);

    $url['fragment'] = $remark; //$this->genRemark($inbound, $email, '');
    $links[0] = http_build_url($url);
    return $links;
}




function genShadowsocksLink($inbound, $email, $address, $remark)
{
    // Implementation of genShadowsocksLink method in PHP
    // ...

    return '';  // Replace with the actual result
}
function getSecuritySettings($stream, $client)
{
    switch ($stream['security']) {
        case 'reality':
            $realitySetting = $stream['realitySettings'];
            $realitySettings = searchKey($realitySetting, 'settings');
            if ($realitySetting !== null) {
                $sniValue = searchKey($realitySetting, 'serverNames');

                if ($sniValue !== null) {
                    $sNames = $sniValue;
                    $params['serverName'] = $sNames[0];
                }

                $pbkValue = searchKey($realitySettings, 'publicKey');

                if ($pbkValue !== null) {
                    $params['publicKey'] = $pbkValue;
                }

                $sidValue = searchKey($realitySetting, 'shortIds');

                if ($sidValue !== null) {
                    $shortIds = $sidValue;
                    $params['shortId'] = $shortIds[0];
                }

                $fpValue = searchKey($realitySettings, 'fingerprint');

                if ($fpValue !== null && strlen($fpValue) > 0) {
                    $params['fingerprint'] = $fpValue;
                }

                $spxValue = searchKey($realitySettings, 'spiderX');

                if ($spxValue !== null && strlen($spxValue) > 0) {
                    $params['spiderX'] = $spxValue;
                }
            }

            if ($stream['network'] == 'tcp' && strlen($client['flow']) > 0) {
                $params['flow'] = $client['flow'];
            }
            break;

        case 'tls':
            $tlsSetting = $stream['tlsSettings'];
            $alpns = $tlsSetting['alpn'];

            if (!empty($alpns)) {
                $params['alpn'] = $alpns;
            }
            $sniValue = searchKey($tlsSetting, 'serverName');
            if ($sniValue) {
                $params['serverName'] = $sniValue;
            }

            $tlsSettings = searchKey($tlsSetting, 'settings');

            if ($tlsSetting !== null) {
                $fpValue = searchKey($tlsSettings, 'fingerprint');

                if ($fpValue !== null) {
                    $params['fingerprint'] = $fpValue;
                }

                $insecure = searchKey($tlsSettings, 'allowInsecure');

                if ($insecure !== null && $insecure) {
                    $params['allowInsecure'] = '1';
                }
            }
            break;
        case 'xtls':
            $xtlsSetting = $stream['xtlsSettings'];
            $alpns = $xtlsSetting['alpn'];
            $params['alpn'] = $alpns;
            $sniValue = searchKey($xtlsSetting, 'serverName');
            if ($sniValue) {
                $params['serverName'] = $sniValue;
            }

            $xtlsSettings = searchKey($xtlsSetting, 'settings');
            if ($xtlsSetting) {
                if ($fpValue = searchKey($xtlsSettings, 'fingerprint')) {
                    $params['fingerprint'] = $fpValue;
                }
                if ($insecure = searchKey($xtlsSettings, 'allowInsecure')) {
                    if ($insecure) {
                        $params['allowInsecure'] = '1';
                    }
                }
            }

            if ($stream['network'] == 'tcp' && strlen($client['flow']) > 0) {
                $params['flow'] = $client['flow'];
            }
            break;
        default:
            $params['security'] = 'none';
            break;
    }

    return $params;
}
function getNetworkSettings($stream)
{
    //$NetworkSettings = $stream[$stream['network'].'Settings'];
    $params = [];
    switch ($stream['network']) {
        case 'tcp':
            $tcp = $stream['tcpSettings'];
            $header = $tcp['header'];
            $typeStr = $header['type'];


            if ($typeStr == 'http') {
                $request = $header['request'];
                $requestPath = $request['path'][0];
                $params['path'] = $requestPath;
                $headers = $request['headers'];
                $params['host'] = searchKey($headers, 'host');
                $params['headerType'] = 'http';
            } else {

                $params['headerType'] = 'none';
            }
            break;
        case 'kcp':
            $kcp = $stream['kcpSettings'];
            $header = $kcp['header'];
            $params['headerType'] = $header['type'];
            $params['seed'] = $kcp['seed'];
            break;
        case 'ws':

            $ws = $stream['wsSettings'];
            $params['path'] = $ws['path'];
            $headers = $ws['headers'];
            $host = searchKey($headers, 'host');
            //dump($stream);
            if ($headers) {
                //dump($headers);
                $params['headers']['Host'] = $host;
            }

            break;
        case 'http':
            $http = $stream['httpSettings'];
            $params['path'] = $http['path'];
            $headers = $http['headers'];
            //$params['host'] = searchKey($headers, 'host');
            $params['headers'] = $headers;
            break;
        case 'quic':
            $quic = $stream['quicSettings'];
            $params['quicSecurity'] = $quic['security'];
            $params['key'] = $quic['key'];
            $header = $quic['header'];
            $params['headerType'] = $header['type'];
            break;
        case 'grpc':
            $grpc = $stream['grpcSettings'];
            $params['serviceName'] = $grpc['serviceName'];
            if ($grpc['multiMode']) {
                $params['mode'] = 'multi';
            }
            break;
    }
    return $params;
}
function genRemark($inbound, $email, $extra)
{
    // Implementation of genRemark method in PHP
    // ...

    return '';  // Replace with the actual result
}

function searchKey($array, $keyToSearch)
{
    $keyToSearch = strtolower($keyToSearch);
    foreach ($array as $key => $value) {
        $key = strtolower($key);
        if ($key === $keyToSearch) {
            return $value;
        } elseif (is_array($value)) {
            $result = searchKey($value, $keyToSearch);
            if ($result !== null) {
                return $result;
            }
        }
    }
    return null;
}


if (!function_exists('http_build_url')) {
    define('HTTP_URL_REPLACE', 1);                // Replace every part of the first URL when there's one of the second URL
    define('HTTP_URL_JOIN_PATH', 2);            // Join relative paths
    define('HTTP_URL_JOIN_QUERY', 4);            // Join query strings
    define('HTTP_URL_STRIP_USER', 8);            // Strip any user authentication information
    define('HTTP_URL_STRIP_PASS', 16);            // Strip any password authentication information
    define('HTTP_URL_STRIP_AUTH', 32);            // Strip any authentication information
    define('HTTP_URL_STRIP_PORT', 64);            // Strip explicit port numbers
    define('HTTP_URL_STRIP_PATH', 128);            // Strip complete path
    define('HTTP_URL_STRIP_QUERY', 256);        // Strip query string
    define('HTTP_URL_STRIP_FRAGMENT', 512);        // Strip any fragments (#identifier)
    define('HTTP_URL_STRIP_ALL', 1024);            // Strip anything but scheme and host

    // Build an URL
    // The parts of the second URL will be merged into the first according to the flags argument. 
    // 
    // @param	mixed			(Part(s) of) an URL in form of a string or associative array like parse_url() returns
    // @param	mixed			Same as the first argument
    // @param	int				A bitmask of binary or'ed HTTP_URL constants (Optional)HTTP_URL_REPLACE is the default
    // @param	array			If set, it will be filled with the parts of the composed url like parse_url() would return 
    function http_build_url($url, $parts = array(), $flags = HTTP_URL_REPLACE, &$new_url = false)
    {
        $keys = array('user', 'pass', 'port', 'path', 'query', 'fragment');

        // HTTP_URL_STRIP_ALL becomes all the HTTP_URL_STRIP_Xs
        if ($flags & HTTP_URL_STRIP_ALL) {
            $flags |= HTTP_URL_STRIP_USER;
            $flags |= HTTP_URL_STRIP_PASS;
            $flags |= HTTP_URL_STRIP_PORT;
            $flags |= HTTP_URL_STRIP_PATH;
            $flags |= HTTP_URL_STRIP_QUERY;
            $flags |= HTTP_URL_STRIP_FRAGMENT;
        }
        // HTTP_URL_STRIP_AUTH becomes HTTP_URL_STRIP_USER and HTTP_URL_STRIP_PASS
        else if ($flags & HTTP_URL_STRIP_AUTH) {
            $flags |= HTTP_URL_STRIP_USER;
            $flags |= HTTP_URL_STRIP_PASS;
        }

        // Parse the original URL, 
        // assuming it's a valid url or an array that parse_url returns
        if (is_string($url))
            $parse_url = parse_url($url);
        else
            $parse_url = (array) $url;

        // Scheme and Host are always replaced
        if (isset($parts['scheme']))
            $parse_url['scheme'] = $parts['scheme'];
        if (isset($parts['host']))
            $parse_url['host'] = $parts['host'];

        // (If applicable) Replace the original URL with it's new parts
        if ($flags & HTTP_URL_REPLACE) {
            foreach ($keys as $key) {
                if (isset($parts[$key]))
                    $parse_url[$key] = $parts[$key];
            }
        } else {
            // Join the original URL path with the new path
            if (isset($parts['path']) && ($flags & HTTP_URL_JOIN_PATH)) {
                if (isset($parse_url['path']))
                    $parse_url['path'] = rtrim(str_replace(basename($parse_url['path']), '', $parse_url['path']), '/') . '/' . ltrim($parts['path'], '/');
                else
                    $parse_url['path'] = $parts['path'];
            }

            // Join the original query string with the new query string
            if (isset($parts['query']) && ($flags & HTTP_URL_JOIN_QUERY)) {
                if (isset($parse_url['query']))
                    $parse_url['query'] .= '&' . $parts['query'];
                else
                    $parse_url['query'] = $parts['query'];
            }
        }

        // Strips all the applicable sections of the URL
        // Note: Scheme and Host are never stripped
        foreach ($keys as $key) {
            if ($flags & (int) constant('HTTP_URL_STRIP_' . strtoupper($key)))
                unset($parse_url[$key]);
        }


        $new_url = $parse_url;

        return ((isset($parse_url['scheme'])) ? $parse_url['scheme'] . '://' : '')
            . ((isset($parse_url['user'])) ? $parse_url['user'] . ((isset($parse_url['pass'])) ? ':' . $parse_url['pass'] : '') . '@' : '')
            . ((isset($parse_url['host'])) ? $parse_url['host'] : '')
            . ((isset($parse_url['port'])) ? ':' . $parse_url['port'] : '')
            . ((isset($parse_url['path'])) ? $parse_url['path'] : '')
            . ((isset($parse_url['query'])) ? '?' . $parse_url['query'] : '')
            . ((isset($parse_url['fragment'])) ? '#' . $parse_url['fragment'] : '');
    }
}


// function getRandomKeyPair() {
//     // Access the Google Sheet
//     $spreadsheet = new Google\Spreadsheet\SpreadsheetService();
//     $spreadsheet->getClient()->setAccessToken(file_get_contents('token.json'));
//     $sheet = $spreadsheet->spreadsheetById(SERVERS_SHEET_ID)->getSheetByName('Key');

//     // Get the last row with data
//     $lastRow = $sheet->getLastRow();

//     // Randomly select a row
//     $randomRowIndex = rand(2, $lastRow);

//     // Get the private and public keys from the selected row
//     $privateKey = $sheet->getValue($randomRowIndex, 2);
//     $publicKey = $sheet->getValue($randomRowIndex, 3);

//     echo 'Randomly Selected Key Pair:' . PHP_EOL;
//     echo 'Private Key: ' . $privateKey . PHP_EOL;
//     echo 'Public Key: ' . $publicKey . PHP_EOL;

//     return ['privateKey' => $privateKey, 'publicKey' => $publicKey];
// }

function generateUUID()
{
    return sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff)
    );
}

class JsonConf
{
    // Define configurations
    private $dirFragment;
    private $FragmentA;
    private $sockopt;
    private $dnsIranDIR;
    private $dns;
    private $rulesIranDIR;
    private $rules;
    private $rulesMagic;
    private $mux;

    public function __construct()
    {
        $this->dirFragment = json_decode('[
            {
                "protocol": "freedom",
                "settings": {
                    "domainStrategy": "UseIP",
                    "fragment": {
                        "interval": "1-1",
                        "length": "10-20",
                        "packets": "tlshello"
                    }
                },
                "streamSettings": {
                    "network": "tcp",
                    "security": "",
                    "sockopt": {
                        "tcpNoDelay": true,
                        "tcpKeepAliveIdle": 100
                    }
                },
                "tag": "dir-fragment"
            }
        ]', true);

        $this->FragmentA = json_decode('[
            {
                "tag": "fragment",
                "protocol": "freedom",
                "settings": {
                    "domainStrategy": "AsIs",
                    "fragment": {
                        "packets": "tlshello",
                        "length": "100-200",
                        "interval": "10-20"
                    }
                },
                "streamSettings": {
                    "sockopt": {
                        "tcpKeepAliveIdle": 100,
                        "tcpNoDelay": true
                    }
                }
            }
        ]', true);

        $this->sockopt = json_decode('{
            "dialerProxy": "fragment",
            "tcpKeepAliveIdle": 100,
            "tcpNoDelay": true
        }', true);

        $this->dnsIranDIR = json_decode('{
            "hosts": {
                "geosite:category-ads-all": "127.0.0.1",
                "geosite:category-ads-ir": "127.0.0.1",
                "domain:googleapis.cn": "googleapis.com"
            },
            "servers": [
                "https://94.140.14.14/dns-query",
                {
                    "address": "1.1.1.1",
                    "domains": [
                        "geosite:private",
                        "geosite:category-ir",
                        "domain:.ir"
                    ],
                    "expectIPs": [
                        "geoip:cn"
                    ],
                    "port": 53
                }
            ]
        }', true);

        $this->dns = json_decode('{
            "hosts": {
                "geosite:category-ads-all": "127.0.0.1",
                "domain:googleapis.cn": "googleapis.com"
            },
            "servers": [
                "https://94.140.14.14/dns-query",
                {
                    "address": "1.1.1.1",
                    "domains": [
                        "geosite:private",
                        "domain:.ir"
                    ],
                    "port": 53
                }
            ]
        }', true);

        $this->rulesIranDIR = json_decode('[
            {
                "ip": [
                    "1.1.1.1"
                ],
                "outboundTag": "direct",
                "port": "53",
                "type": "field"
            },
            {
                "domain": [
                    "geosite:private",
                    "geosite:category-ir",
                    "domain:.ir"
                ],
                "outboundTag": "direct",
                "type": "field"
            },
            {
                "ip": [
                    "geoip:private",
                    "geoip:ir"
                ],
                "outboundTag": "direct",
                "type": "field"
            },
            {
                "domain": [
                    "geosite:category-ads-all",
                    "geosite:category-ads-ir"
                ],
                "outboundTag": "block",
                "type": "field"
            },
            {
                "balancerTag": "all",
                "type": "field",
                "network": "tcp,udp"
            }
        ]', true);

        $this->rules = json_decode('[
            {
                "ip": [
                    "1.1.1.1"
                ],
                "outboundTag": "direct",
                "port": "53",
                "type": "field"
            },
            {
                "domain": [
                    "geosite:private",
                    "domain:.ir"
                ],
                "outboundTag": "direct",
                "type": "field"
            },
            {
                "ip": [
                    "geoip:private"
                ],
                "outboundTag": "direct",
                "type": "field"
            },
            {
                "domain": [
                    "geosite:category-ads-all"
                ],
                "outboundTag": "block",
                "type": "field"
            },
            {
                "balancerTag": "all",
                "type": "field",
                "network": "tcp,udp"
            }
        ]', true);

        $this->rulesMagic = json_decode('[
            {
                "ip": [
                    "1.1.1.1"
                ],
                "outboundTag": "direct",
                "port": "53",
                "type": "field"
            },
            {
                "domain": [
                    "geosite:private",
                    "domain:.ir"
                ],
                "outboundTag": "direct",
                "type": "field"
            },
            {
                "ip": [
                    "geoip:private"
                ],
                "outboundTag": "direct",
                "type": "field"
            },
            {
                "domain": [
                    "geosite:category-ads-all"
                ],
                "outboundTag": "block",
                "type": "field"
            },
            {
                "domain": [
                    "geosite:twitter",
                    "geosite:facebook",
                    "geosite:google",
                    "geosite:telegram",
                    "domain:speedtest.net"
                ],
                "outboundTag": "dir-fragment",
                "type": "field"
            },
            {
                "balancerTag": "all",
                "type": "field",
                "network": "tcp,udp"
            }
        ]', true);

        $this->mux = json_decode('{
            "enabled": true,
            "concurrency": 8,
            "xudpConcurrency": 8,
            "xudpProxyUDP443": "reject"
        }', true);
    }

    // Getter methods to access the properties
    public function getDirFragment()
    {
        return $this->dirFragment;
    }

    public function getFragmentA()
    {
        return $this->FragmentA;
    }

    public function getSockopt()
    {
        return $this->sockopt;
    }

    public function getDnsIranDIR()
    {
        return $this->dnsIranDIR;
    }

    public function getDns()
    {
        return $this->dns;
    }

    public function getRulesIranDIR()
    {
        return $this->rulesIranDIR;
    }

    public function getRules()
    {
        return $this->rules;
    }

    public function getRulesMagic()
    {
        return $this->rulesMagic;
    }

    public function getMux()
    {
        return $this->mux;
    }
}
