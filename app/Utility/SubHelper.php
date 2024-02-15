<?php
use Illuminate\Support\Facades\URL;

function genServerLinks($record)
{
    $links = '';
    //dd($record->inboundStat);
    $url = parse_url($record->address);
    if (isset($url['host'])) {
        $address = $url['host'];
    } elseif (isset($url['path'])) {
        $address = $url['path'];
    } else {
        return;
    }

    foreach ($record->inboundStat as $inbound) {
        if ($inbound['enable']) {
            $settings = json_decode($inbound['settings'], true);
            foreach ($settings['clients'] as $client) {
                if ($client['enable']) {
                    $links .= getLink($inbound, $client, $address, $record->remark) . "\n";
                    // Do something with $link, for example, print it

                }

            }
        }
    }
    dd($links);
}
if (!function_exists('getLink')) {
    function getLink($inbound, $client, $address, $remark)
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

    $stream = json_decode($inbound['streamSettings'], true);



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
            $headers = $http ['headers'];
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
    if (isset($stream['externalProxy'])) {
        $externalProxies = $stream['externalProxy'];

        if (count($externalProxies) > 0) {
            $links = '';

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


                if ($index > 0) {
                    $links .= "\n";
                }

                $links .= http_build_url($url);
            }
        }

        return $links;

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

    return http_build_url($url);
}

function genTrojanLink($inbound, $client, $address, $remark)
{

    if ($inbound['protocol'] !== 'trojan') {
        return '';
    }
    $stream = json_decode($inbound['streamSettings'], true);


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
            $headers = $http ['headers'];
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

        if ($streamNetwork == 'tcp' && strlen($client['Flow']) > 0) {
            $params['flow'] = $client['Flow'];
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
            $links = '';
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


                if ($index > 0) {
                    $links .= "\n";
                }
                $links .= http_build_url($url);
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
    return http_build_url($url);
}




function genShadowsocksLink($inbound, $email,$address, $remark)
{
    // Implementation of genShadowsocksLink method in PHP
    // ...

    return '';  // Replace with the actual result
}

function genRemark($inbound, $email, $extra)
{
    // Implementation of genRemark method in PHP
    // ...

    return '';  // Replace with the actual result
}

function searchKey($array, $keyToSearch)
{
    foreach ($array as $key => $value) {
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
    define('HTTP_URL_REPLACE', 1);				// Replace every part of the first URL when there's one of the second URL
    define('HTTP_URL_JOIN_PATH', 2);			// Join relative paths
    define('HTTP_URL_JOIN_QUERY', 4);			// Join query strings
    define('HTTP_URL_STRIP_USER', 8);			// Strip any user authentication information
    define('HTTP_URL_STRIP_PASS', 16);			// Strip any password authentication information
    define('HTTP_URL_STRIP_AUTH', 32);			// Strip any authentication information
    define('HTTP_URL_STRIP_PORT', 64);			// Strip explicit port numbers
    define('HTTP_URL_STRIP_PATH', 128);			// Strip complete path
    define('HTTP_URL_STRIP_QUERY', 256);		// Strip query string
    define('HTTP_URL_STRIP_FRAGMENT', 512);		// Strip any fragments (#identifier)
    define('HTTP_URL_STRIP_ALL', 1024);			// Strip anything but scheme and host

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

        return
            ((isset($parse_url['scheme'])) ? $parse_url['scheme'] . '://' : '')
            . ((isset($parse_url['user'])) ? $parse_url['user'] . ((isset($parse_url['pass'])) ? ':' . $parse_url['pass'] : '') . '@' : '')
            . ((isset($parse_url['host'])) ? $parse_url['host'] : '')
            . ((isset($parse_url['port'])) ? ':' . $parse_url['port'] : '')
            . ((isset($parse_url['path'])) ? $parse_url['path'] : '')
            . ((isset($parse_url['query'])) ? '?' . $parse_url['query'] : '')
            . ((isset($parse_url['fragment'])) ? '#' . $parse_url['fragment'] : '')
        ;
    }
}