<?php

// Function to generate a secure hash for TS URLs
function generateSecureHash($data, $secret = 'farid') {
    return base64_encode(hash_hmac('sha256', $data, $secret, true));
}

// Function to validate the hash for TS URLs
function validateSecureHash($data, $hash, $secret = 'farid') {
    $calculatedHash = generateSecureHash($data, $secret);
    return hash_equals($calculatedHash, $hash);
}

// Function to fetch and display available matches
function displayMatches() {
    // GitHub JSON URL
    $github_url = 'https://raw.githubusercontent.com/drmlive/fancode-live-events/main/fancode.json';

    // Validate URL
    if (!filter_var($github_url, FILTER_VALIDATE_URL)) {
        die("Error: Invalid GitHub URL\n");
    }

    // Fetch the JSON data from GitHub with error handling
    $response = @file_get_contents($github_url);
    if ($response === FALSE) {
        die("Error: Failed to fetch JSON data from GitHub. Check your internet connection or the URL may be unavailable.\n");
    }

    // Decode the JSON data
    $json_data = json_decode($response, true);
    if ($json_data === NULL) {
        die("Error: Failed to decode JSON data. The content might be malformed.\n");
    }

    // Check if matches exist in the data
    if (!isset($json_data["matches"]) || !is_array($json_data["matches"]) || empty($json_data["matches"])) {
        die("Info: No matches found in JSON data\n");
    }

    // Output M3U header
    header('Content-Type: application/vnd.apple.mpegurl');
    echo "#EXTM3U\n";

    // Process all available matches
    foreach ($json_data["matches"] as $index => $match) {
        // Initialize variables for each match
        $name = isset($match["match_name"]) ? htmlspecialchars($match["match_name"]) : '';
        $logo = isset($match["src"]) ? htmlspecialchars($match["src"]) : '';
        $url = isset($match["dai_url"]) ? htmlspecialchars($match["dai_url"]) : '';
        
        // Extract stream ID from URL
        $streamId = '';
        if ($url) {
            preg_match('/https:\/\/dai\.fancode\.com\/primary\/([^\/]+)/', $url, $idMatch);
            $streamId = $idMatch[1] ?? '';
        }
        
        // Only output if we have all required fields
        if ($name && $logo && $url && $streamId) {
            echo "#EXTINF:-1 tvg-logo=\"$logo\" group-title=\"Live Events\",$name\n";
            echo "https://" . $_SERVER['HTTP_HOST'] . ($_SERVER['SCRIPT_NAME']) . "/{$streamId}.m3u8\n";
        }
    }
    exit;
}

// Handle TS segment requests
if (isset($_GET['id']) && isset($_GET['ts']) && isset($_GET['hash'])) {
    // Get parameters
    $id = $_GET['id'] ?? '';
    $tsEncoded = $_GET['ts'] ?? '';
    $hash = $_GET['hash'] ?? '';

    // Validate input
    if (empty($id) || empty($tsEncoded) || empty($hash)) {
        http_response_code(400);
        die("Invalid request");
    }

    // Decode TS URL
    $tsUrl = base64_decode($tsEncoded);
    if ($tsUrl === false) {
        http_response_code(400);
        die("Invalid TS URL encoding");
    }

    // Validate hash
    if (!validateSecureHash($id . $tsUrl, $hash)) {
        http_response_code(403);
        die("Invalid security hash");
    }

    // Construct the actual TS URL
    $actualUrl = "{$tsUrl}";

    // Proxy the request
    $ch = curl_init($actualUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

    // Set appropriate headers
    header('Content-Type: application/vnd.apple.mpegurl');
    $content = curl_exec($ch);
    curl_close($ch);

    echo $content;
    exit;
}

// If no ID parameter, show available streams
if (!isset($_SERVER['REQUEST_URI']) || basename($_SERVER['REQUEST_URI']) == basename(__FILE__)) {
    displayMatches();
}

// Get the ID from the URL path
$requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$id = basename($requestUri, '.m3u8');

if (empty($id)) {
    displayMatches();
}

// Check if this is a player request (ends with .m3u8)
if (preg_match('/\.m3u8$/', $requestUri)) {
    // Construct the URL
    $url = "https://tv.onlinetvbd.com/fancode/global/primary/{$id}/720p.m3u8";

    // Set headers
    $headers = [
        'User-Agent: plaYtv/7.1.3 (Linux;Android 14) ExoPlayerLib/2.11.7',
        'Accept: application/x-mpegURL, application/vnd.apple.mpegurl',
        'Accept-Language: en-US,en;q=0.9'
    ];

    // Initialize cURL
    $ch = curl_init();

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_FAILONERROR => true,
        CURLOPT_ENCODING => '' // Enable automatic content decoding
    ]);

    $streamResponse = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($httpCode === 200 && !empty($streamResponse)) {
        // Process the stream response to fix relative TS URLs and add security
        $finalResponse = preg_replace_callback(
            '/(?<!https:\/\/)([^\s]+\.ts)/',
            function($matches) use ($id) {
                $tsUrl = $matches[1];
                // Generate secure hash for the TS URL
                $hash = generateSecureHash($id . $tsUrl);
                // Return the secured URL with hash parameter
                return $_SERVER['SCRIPT_NAME'] . "?id=" . urlencode($id) . 
                       "&ts=" . urlencode(base64_encode($tsUrl)) . 
                       "&hash=" . urlencode($hash);
            },
            $streamResponse
        );
        
        if ($finalResponse === null) {
            error_log("Regex processing failed for channel {$id}");
            http_response_code(500);
            header('Content-Type: application/json');
            die(json_encode(["error" => "Internal server error"]));
        }
        
        header('Content-Type: application/vnd.apple.mpegurl');
        echo $finalResponse;
        exit;
    } else {
        // Handle error cases
        http_response_code($httpCode ?: 500);
        header('Content-Type: application/json');
        echo json_encode([
            "error" => "Failed to fetch stream",
            "details" => $error ?: "Unknown error",
            "url_attempted" => $url
        ]);
        exit;
    }
}

// If we reach here, show the player
showPlayer($id);

function showPlayer($id) {
    // Generate the stream URL - use the correct path
    $streamUrl = "https://" . $_SERVER['HTTP_HOST'] . ($_SERVER['SCRIPT_NAME']) . "/{$id}.m3u8";
    ?>
   <!DOCTYPE html>
    <html>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1"/>
        <title>FARIDFLIX | <?php echo htmlspecialchars($id, ENT_QUOTES); ?></title>
        <link rel="stylesheet" type="text/css" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"/>
        <script src="https://cdn.jsdelivr.net/clappr/latest/clappr.min.js"></script>
        <script src="https://cdn.jsdelivr.net/clappr.level-selector/latest/level-selector.min.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/hls.js@latest"></script>
        <script src="https://cdn.jsdelivr.net/npm/@clappr/hlsjs-playback@1.0.1/dist/hlsjs-playback.min.js"></script>
        
        <style>
            body {
                margin: 0;
                padding: 0;
                background-color: #000;
                height: 100vh;
                overflow: hidden;
                font-family: Arial, sans-serif;
            }

            #player-wrapper {
                position: relative;
                width: 100%;
                height: 100%;
            }

            #player {
                width: 100%;
                height: 100%;
            }

            .watermark {
                position: absolute;
                bottom: 15px;
                left: 50%;
                transform: translateX(-50%);
                background-color: rgba(0, 0, 0, 0.7);
                color: #fff;
                padding: 5px 15px;
                border-radius: 20px;
                font-size: 14px;
                z-index: 1000;
            }

            .watermark a {
                color: #ff5722;
                text-decoration: none;
                font-weight: bold;
            }
        </style>
    </head>
    <body>
        <div id="player"></div>
        <div class="watermark">
            Powered by <a href="https://t.me/faridiptv" target="_blank">FARIDFLIX</a>
        </div>

        <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
        <script>
            $(document).ready(function() {
                var player = new Clappr.Player({
                    source: '<?php echo $streamUrl; ?>',
                    parentId: "#player",
                    width: '100%',
                    height: '100%',
                    aspectratio: '16:9',
                    stretching: 'exactfit',
                    autoPlay: true,
                    plugins: [HlsjsPlayback, LevelSelector],
                    mimeType: "application/x-mpegURL",
                    hlsjsConfig: {
                        enableWorker: true,
                        maxBufferLength: 30,
                        maxMaxBufferLength: 600,
                        maxBufferSize: 60 * 1000 * 1000,
                        maxBufferHole: 0.5
                    },
                    mediacontrol: { 
                        seekbar: "#ff5722", 
                        buttons: "#ffffff" 
                    },
                    playback: {
                        playInline: true,
                        preload: 'auto',
                        crossOrigin: 'anonymous'
                    }
                });
                
                player.on('error', function(e) {
                    console.error('Player error:', e);
                    alert('Stream error occurred. Please try again later.');
                });
            });
        </script>
        <script disable-devtool-auto src='https://cdn.jsdelivr.net/npm/disable-devtool@latest'></script> 
    </body>
    </html>
    <?php
}
