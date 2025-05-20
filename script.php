<?php

// Fetch the JSON data from GitHub
$response = file_get_contents('https://raw.githubusercontent.com/faridmfoyez/me/main/token.json');
if ($response === FALSE) {
    die("Failed to fetch JSON data from GitHub");
}

// Decode the JSON data
$json_data = json_decode($response, true);
if ($json_data === NULL) {
    die("Failed to decode JSON data");
}

// Check if channels exist in the data
if (!isset($json_data["channels"]) || !is_array($json_data["channels"])) {
    die("No channels found in JSON data");
}

// Check and output cookies for specific channels
if (isset($json_data["channels"][0]["headers"]["cookie"])) {
    $ayna = $json_data["channels"][0]["headers"]["cookie"];
}

if (isset($json_data["channels"][1]["headers"]["cookie"])) {
    $jadoo = $json_data["channels"][1]["headers"]["cookie"];
}

// File path for the M3U file
$m3uFile = 'faridflix.m3u';

// Check if file needs to be updated (every 4 hours)
$updateFile = true;
if (file_exists($m3uFile)) {
    $fileTime = filemtime($m3uFile);
    $currentTime = time();
    $updateFile = ($currentTime - $fileTime) >= (4 * 3600);
}

if ($updateFile) {
    $validChannels = [
        'globaltv' => ['name' => 'Global TV', 'id' => '0ac6af3d-acce-4d9d-9ce5-32d4b53f9326', 'img' => 'BTV', 'provider' => 'Ayna'],
        'rtv' => ['name' => 'RTV', 'id' => '70e97db9-5a9e-4642-874e-af3d30ba9925', 'img' => 'IPL', 'provider' => 'Ayna'],
        'etv' => ['name' => 'ETV', 'id' => 'b3f0df76-15ba-4ede-a7de-bbaaf23a630e', 'img' => 'PSL', 'provider' => 'Ayna'],
        'deshtv' => ['name' => 'Desh TV', 'id' => 'dd8c50e9-5dad-4cb9-9df8-5763e95281ad', 'img' => 'Desh_TV', 'provider' => 'Ayna'],
        'tsports' => ['name' => 'T Sports', 'id' => 't_sports', 'img' => 'Tsports', 'provider' => 'Jadoo'],
        'zeebangla' => ['name' => 'Zee Bangla', 'id' => 'zee_bangla', 'img' => 'Channel_9', 'provider' => 'Jadoo'],
        'zeetv' => ['name' => 'Zee TV', 'id' => 'zee_tv', 'img' => 'zee_tv', 'provider' => 'Jadoo'],
        'zeecinemahd' => ['name' => 'Zee Cinema HD', 'id' => 'zee_cinema_hd', 'img' => 'zee_cinema', 'provider' => 'Jadoo']
    ];

    function getRandomIds($validChannels, $count = 8) {
        $keys = array_keys($validChannels);
        shuffle($keys);
        return array_slice($keys, 0, min($count, count($keys)));
    }

    // Environment variables or defaults
    $CHANNEL_API_URL = getenv("CHANNEL_API_URL") ?: "https://web.aynaott.com/api/player/streams?language=en&operator_id=1fb1b4c7-dbd9-469e-88a2-c207dc195869&device_id=72C53637404B902DB067EABA063E6B5B&density=1&client=browser&platform=web&os=windows";
    $ACCESS_TOKEN = getenv("ACCESS_TOKEN") ?: "$ayna";
    $REQUEST_TIMEOUT = 10;

    $JADOO_REFRESH_URL = "https://api.jadoodigital.com/api/v2.1/user/auth/refresh";
    $JADOO_REFRESH_TOKEN = "Bearer $jadoo";

    function reqGet($url, $authorizationHeader = null) {
        global $REQUEST_TIMEOUT;
        $curl = curl_init();
        $headers = [
            "Accept: application/json"
        ];
        if ($authorizationHeader) {
            $headers[] = "Authorization: $authorizationHeader";
        }

        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_FAILONERROR => false,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT => $REQUEST_TIMEOUT
        ]);

        $result = curl_exec($curl);
        if (curl_errno($curl)) {
            error_log('CURL Error: ' . curl_error($curl));
            curl_close($curl);
            return false;
        }

        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        if ($httpCode >= 400) {
            error_log("HTTP Error: $httpCode for URL: $url");
            curl_close($curl);
            return false;
        }

        curl_close($curl);
        return $result;
    }

    function fetchAynaStream($channel_id) {
        global $CHANNEL_API_URL, $ACCESS_TOKEN;

        $headers = "Bearer " . $ACCESS_TOKEN;
        $url = $CHANNEL_API_URL . "&media_id=" . urlencode($channel_id);
        $response = reqGet($url, $headers);
        if (!$response) return null;

        $data = json_decode($response, true);
        return $data['content'][0]['src']['url'] ?? null;
    }

    function fetchJadooStream($channel_id) {
        global $JADOO_REFRESH_URL, $JADOO_REFRESH_TOKEN;

        $refreshResponse = reqGet($JADOO_REFRESH_URL, $JADOO_REFRESH_TOKEN);
        if (!$refreshResponse) return null;

        $tokenData = json_decode($refreshResponse, true);
        if (!isset($tokenData['data']['access_token'])) return null;

        $accessToken = $tokenData['data']['access_token'];
        $channelUrl = "https://api.jadoodigital.com/api/v2.1/user/channel/{$channel_id}";
        $channelResponse = reqGet($channelUrl, "Bearer $accessToken");
        if (!$channelResponse) return null;

        $data = json_decode($channelResponse, true);
        return $data['url'] ?? null;
    }

    // Start building M3U
    $randomChannels = getRandomIds($validChannels, 8);
    $m3uContent = "#EXTM3U\n";

    foreach ($randomChannels as $channelKey) {
        $channel = $validChannels[$channelKey];
        $stream_url = null;

        if ($channel['provider'] === 'Ayna') {
            $stream_url = fetchAynaStream($channel['id']);
        } elseif ($channel['provider'] === 'Jadoo') {
            $stream_url = fetchJadooStream($channel['id']);
        }

        if ($stream_url && filter_var($stream_url, FILTER_VALIDATE_URL)) {
            $m3uContent .= "#EXTINF:-1 tvg-logo=\"https://faridflix.xyz/img/{$channel['img']}.png\" group-title=\"faridflix\",{$channel['name']}\n";
            $m3uContent .= "$stream_url\n";
        } else {
            error_log("Failed to get valid stream URL for channel {$channel['name']}");
        }
    }

    // Write to file
    if (trim($m3uContent) !== "#EXTM3U") {
        if (file_put_contents($m3uFile, $m3uContent) !== false) {
            $count = substr_count($m3uContent, '#EXTINF');
            echo "faridflix.m3u updated with $count channels!\n";
        } else {
            echo "Failed to write to faridflix.m3u.\n";
        }
    } else {
        echo "No valid channels to update M3U file.\n";
    }
} else {
    echo "faridflix.m3u is up-to-date.\n";
}

