#!/usr/bin/php
<?php

define('API_HOST', 'https://api.namecheap.com/xml.response');
define('CHALLENGE_PREFIX', '_acme-challenge');

if (file_exists(__DIR__.'/namecheap.config.php')) {
    require_once __DIR__.'/namecheap.config.php';
}

exit(main());

function main() {
    global $argv;

    $ret = 0;
    if (count($argv) > 1) {
        switch ($argv[1]) {
            case 'deploy_challenge':
                $ret = deploy_challenge(array_slice($argv, 2));
                if ($ret == 0) {
                    fwrite(STDERR, " + Waiting 10 seconds for DNS to update..\n");
                    sleep(10);
                }
                break;
            case 'clean_challenge':
                $ret = clean_challenge(array_slice($argv, 2));
                break;
        }
    }

    if ($ret === 0 && defined('HOOK_PASSTHROUGH') && HOOK_PASSTHROUGH) {
        $cmd = HOOK_PASSTHROUGH;
        for ($x = 1; $x < count($argv); $x++) {
            $cmd .= ' '.escapeshellarg($argv[$x]);
        }
        system($cmd, $ret);
    }

    return $ret;
}

function get_ip() {
    static $ip = false;

    if (defined('API_IP') && API_IP) {
        return API_IP;
    }

    if ($ip !== false) {
        return $ip;
    }

    if (!function_exists('curl_init')) {
        fwrite(STDERR, " + ERROR: Namecheap hook needs curl library!\n");
        return false;
    }

    $ch = curl_init();
    curl_setopt_array($ch, array(
        CURLOPT_URL => 'http://icanhazip.com/',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
        ));
    $ip = trim(curl_exec($ch));
    curl_close($ch);

    $ip = filter_var($ip, FILTER_VALIDATE_IP, array('default' => false, 'flags' => FILTER_FLAG_IPV4));
    return $ip;
}

function deploy_challenge($args) {
    if (count($args) < 3) {
        fwrite(STDERR, " + ERROR: Namecheap hook deploy expects at least 3 arguments\n");
        return 1;
    }
    if (count($args) % 2 == 0) {
        fwrite(STDERR, " + ERROR: Namecheap hook deploy expects odd number of arguments\n");
        return 1;
    }

    $domain = array_shift($args);
    if (strpos($domain, '.') === false) {
        fwrite(STDERR, " + ERROR: Namecheap hook deploy expects domain as first argument\n");
        return 1;
    }

    $hosts = namecheap_get_hosts($domain);
    if ($hosts === false) {
        return 1;
    }

    for ($x = 0; $x < count($args); $x += 2) {
        $keyAuth = $args[$x + 1];
        $subdomain = trim(substr($args[$x], 0, -1 * strlen($domain)), '.');

        if ($subdomain != '') {
            $subdomain = '.' . $subdomain;
        }

        $hosts[] = array(
            'Name' => CHALLENGE_PREFIX . $subdomain,
            'Type' => 'TXT',
            'Address' => $keyAuth,
            'MXPref' => 10,
            'TTL' => 1800,
        );
    }

    $ret = namecheap_set_hosts($domain, $hosts);
    if ($ret === false) {
        return 1;
    }

    return 0;
}

function clean_challenge($args) {
    if (count($args) < 3) {
        fwrite(STDERR, " + ERROR: Namecheap hook deploy expects at least 3 arguments\n");
        return 1;
    }
    if (count($args) % 2 == 0) {
        fwrite(STDERR, " + ERROR: Namecheap hook deploy expects odd number of arguments\n");
        return 1;
    }

    $domain = array_shift($args);
    if (strpos($domain, '.') === false) {
        fwrite(STDERR, " + ERROR: Namecheap hook deploy expects domain as first argument\n");
        return 1;
    }

    $hosts = namecheap_get_hosts($domain);
    if ($hosts === false) {
        return 1;
    }

    $ret = namecheap_set_hosts($domain, $hosts);
    if ($ret === false) {
        return 1;
    }

    return 0;
}

function namecheap_get_hosts($domain) {
    $ip = get_ip();
    if ($ip === false) {
        fwrite(STDERR, " + ERROR: Namecheap hook found no local IP address\n");
        return false;
    }
    if (!defined('API_USER')) {
        fwrite(STDERR, " + ERROR: No Namecheap User supplied to hook!\n");
        return false;
    }
    if (!defined('API_KEY')) {
        fwrite(STDERR, " + ERROR: No Namecheap Key supplied to hook!\n");
        return false;
    }

    $domain = strtolower($domain);
    $sld = substr($domain, 0, strpos($domain, '.'));
    $tld = substr($domain, strpos($domain, '.') + 1);

    $post = array(
        'ClientIP' => $ip,
        'UserName' => API_USER,
        'ApiUser' => API_USER,
        'ApiKey' => API_KEY,
        'SLD' => $sld,
        'TLD' => $tld,
        'Command' => 'namecheap.domains.dns.getHosts',
    );

    $hostXML = post_to_namecheap($post);
    if (!$hostXML) {
        fwrite(STDERR, " + ERROR: Namecheap hook could not get current hosts from Namecheap API\n");
        return false;
    }

    if (!function_exists('xml_parser_create')) {
        fwrite(STDERR, " + ERROR: Namecheap hook requires libxml in PHP\n");
        return false;
    }

    $hostParsed = xml_to_object($hostXML);
    if ($hostParsed === false) {
        fwrite(STDERR, " + ERROR: Namecheap hook cannot parse XML response from Namecheap API\n");
        return false;
    }

    $hosts = [];

    for ($x = 0; $x < count($hostParsed->children); $x++) {
        $ele = $hostParsed->children[$x];
        switch ($ele->name) {
            case 'Errors':
            case 'Warnings':
                $msgType = strtoupper(substr($ele->name, 0, -1));
                for ($y = 0; $y < count($ele->children); $y++) {
                    fwrite(STDERR, " + $msgType: Namecheap hook received \"" . $ele->children[$y]->content . "\" from Namecheap API\n");
                }
                break;
            case 'CommandResponse':
                if ($ele->attributes['Type'] != $post['Command']) {
                    break;
                }
                for ($y = 0; $y < count($ele->children); $y++) {
                    $hostsResult = $ele->children[$y];
                    if ($hostsResult->name != 'DomainDNSGetHostsResult') {
                        continue;
                    }
                    if ($hostsResult->attributes['Domain'] != $domain) {
                        continue;
                    }
                    if ($hostsResult->attributes['IsUsingOurDNS'] != 'true') {
                        fwrite(STDERR, " + ERROR: Namecheap hook found $domain is not using Namecheap DNS from Namecheap API\n");
                        return false;
                    }
                    for ($z = 0; $z < count($hostsResult->children); $z++) {
                        $hostRecord = $hostsResult->children[$z];
                        if ($hostRecord->name != 'host') {
                            continue;
                        }
                        if ($hostRecord->attributes['Type'] != 'TXT' || substr($hostRecord->attributes['Name'], 0, strlen(CHALLENGE_PREFIX)) != CHALLENGE_PREFIX) {
                            $hosts[] = $hostRecord->attributes;
                        }
                    }
                }
                break;
        }
    }

    if (!isset($hostParsed->attributes['Status']) || ($hostParsed->attributes['Status'] != 'OK')) {
        fwrite(STDERR, " + ERROR: Namecheap hook received non-OK status from Namecheap API, aborting.\n");
        return false;
    }

    return $hosts;
}

function namecheap_set_hosts($domain, $hosts) {
    $ip = get_ip();
    if ($ip === false) {
        fwrite(STDERR, " + ERROR: Namecheap hook found no local IP address\n");
        return false;
    }
    if (!defined('API_USER')) {
        fwrite(STDERR, " + ERROR: No Namecheap User supplied to hook!\n");
        return false;
    }
    if (!defined('API_KEY')) {
        fwrite(STDERR, " + ERROR: No Namecheap Key supplied to hook!\n");
        return false;
    }

    $domain = strtolower($domain);
    $sld = substr($domain, 0, strpos($domain, '.'));
    $tld = substr($domain, strpos($domain, '.') + 1);

    $post = array(
        'ClientIP' => $ip,
        'UserName' => API_USER,
        'ApiUser' => API_USER,
        'ApiKey' => API_KEY,
        'SLD' => $sld,
        'TLD' => $tld,
        'Command' => 'namecheap.domains.dns.setHosts',
    );

    for ($x = 0; $x < count($hosts); $x++) {
        $n = $x + 1;
        $post["HostName$n"] = $hosts[$x]['Name'];
        $post["RecordType$n"] = $hosts[$x]['Type'];
        $post["Address$n"] = $hosts[$x]['Address'];
        $post["MXPref$n"] = $hosts[$x]['MXPref'];
        $post["TTL$n"] = $hosts[$x]['TTL'];
    }

    $hostXML = post_to_namecheap($post);
    if (!$hostXML) {
        fwrite(STDERR, " + ERROR: Namecheap hook could not set current hosts via Namecheap API\n");
        return false;
    }

    if (!function_exists('xml_parser_create')) {
        fwrite(STDERR, " + ERROR: Namecheap hook requires libxml in PHP\n");
        return false;
    }

    $hostParsed = xml_to_object($hostXML);
    if ($hostParsed === false) {
        fwrite(STDERR, " + ERROR: Namecheap hook cannot parse XML response from Namecheap API\n");
        return false;
    }

    for ($x = 0; $x < count($hostParsed->children); $x++) {
        $ele = $hostParsed->children[$x];
        switch ($ele->name) {
            case 'Errors':
            case 'Warnings':
                $msgType = strtoupper(substr($ele->name, 0, -1));
                for ($y = 0; $y < count($ele->children); $y++) {
                    fwrite(STDERR, " + $msgType: Namecheap hook received \"" . $ele->children[$y]->content . "\" from Namecheap API\n");
                }
                break;
        }
    }

    if (!isset($hostParsed->attributes['Status']) || ($hostParsed->attributes['Status'] != 'OK')) {
        fwrite(STDERR, " + ERROR: Namecheap hook received non-OK status from Namecheap API, aborting.\n");
        return false;
    }

    return true;
}

function post_to_namecheap($post) {
    if (!function_exists('curl_init')) {
        fwrite(STDERR, " + ERROR: Namecheap hook needs curl library!\n");
        return false;
    }

    $f = false;
    $ch = curl_init();
    curl_setopt_array($ch, array(
            CURLOPT_URL => API_HOST,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_POST => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_CAINFO => __DIR__.'/ca-bundle.crt',
            CURLOPT_FAILONERROR => true,
        ));
    if (defined('CURLOPT_SAFE_UPLOAD')) {
        curl_setopt($ch, CURLOPT_SAFE_UPLOAD, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
    } else { // curl is parsing @ in front of all field values, and we can't turn it off. thanks, curl.
        $postFields = '';
        foreach ($post as $k => $v) {
            $postFields .= ($postFields == '' ? '' : '&') . rawurlencode($k) . '=' . rawurlencode($v);
        }
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
    }
    $ret = trim(curl_exec($ch));
    if ($errno = curl_errno($ch)) {
        fwrite(STDERR, " + ERROR: Namecheap hook generated curl error $errno: ".trim(curl_error($ch))."\n");
        $ret = false;
    }
    curl_close($ch);

    return $ret;
}

class XmlElement {
    var $name;
    var $attributes;
    var $content;
    var $children;
};

function xml_to_object($xml) {
    $parser = xml_parser_create();
    xml_parser_set_option($parser, XML_OPTION_CASE_FOLDING, 0);
    xml_parser_set_option($parser, XML_OPTION_SKIP_WHITE, 1);
    $ret = xml_parse_into_struct($parser, $xml, $tags);
    if ($ret != 1) {
        $err = xml_error_string(xml_get_error_code($parser));
        fwrite(STDERR, " + ERROR: Namecheap hook XML parsing error: $err\n");
        fwrite(STDERR, "\n$xml\n");
        xml_parser_free($parser);
        return false;
    }
    xml_parser_free($parser);

    $elements = array();  // the currently filling [child] XmlElement array
    $stack = array();
    foreach ($tags as $tag) {
        $index = count($elements);
        if ($tag['type'] == "complete" || $tag['type'] == "open") {
            $elements[$index] = new XmlElement;
            $elements[$index]->name = $tag['tag'];
            $elements[$index]->attributes = isset($tag['attributes']) ? $tag['attributes'] : array();
            $elements[$index]->content = isset($tag['value']) ? $tag['value'] : '';
            $elements[$index]->children = array();
            if ($tag['type'] == "open") {  // push
                $stack[count($stack)] = &$elements;
                $elements = &$elements[$index]->children;
            }
        }
        if ($tag['type'] == "close") {  // pop
            $elements = &$stack[count($stack) - 1];
            unset($stack[count($stack) - 1]);
        }
    }
    return $elements[0];  // the single top-level element
}