<?php

// Function to fetch and display available matches
function displayMatches() {
    // GitHub JSON URL
    $github_url = 'https://raw.githubusercontent.com/drmlive/fancode-live-events/main/fancode.json';

    // Validate URL (fixed protocol typo)
    $github_url = str_replace('https://', 'https://', $github_url);
    if (!filter_var($github_url, FILTER_VALIDATE_URL)) {
        die("Error: Invalid GitHub URL\n");
    }

    // Fetch the JSON data from GitHub with error handling
    $context = stream_context_create([
        'http' => [
            'timeout' => 10, // 10 second timeout
            'ignore_errors' => true
        ]
    ]);
    
    $response = @file_get_contents($github_url, false, $context);
    if ($response === FALSE) {
        $error = error_get_last();
        die("Error: Failed to fetch JSON data from GitHub. " . ($error['message'] ?? 'Check your internet connection or the URL may be unavailable.') . "\n");
    }

    // Decode the JSON data
    $json_data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        die("Error: Failed to decode JSON data. " . json_last_error_msg() . "\n");
    }

    // Check if matches exist in the data (more thorough validation)
    if (empty($json_data) || !is_array($json_data) || !isset($json_data["matches"]) || !is_array($json_data["matches"])) {
        die("Info: No matches found in JSON data\n");
    }

    // Output M3U header
    header('Content-Type: application/vnd.apple.mpegurl');
    header('Cache-Control: no-cache');
    header('Pragma: no-cache');
    echo "#EXTM3U\n";

    // Process all available matches
    foreach ($json_data["matches"] as $match) {
        // Skip if match is not an array
        if (!is_array($match)) continue;
        
        // Initialize variables for each match with proper sanitization
        $name = isset($match["match_name"]) ? htmlspecialchars($match["match_name"], ENT_QUOTES, 'UTF-8') : '';
        $logo = isset($match["src"]) ? filter_var($match["src"], FILTER_SANITIZE_URL) : '';
        $url = isset($match["dai_url"]) ? filter_var($match["dai_url"], FILTER_SANITIZE_URL) : '';
        
        // Validate URL format
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            continue;
        }

        // Extract stream ID from URL more safely
        $streamId = '';
        if ($url && preg_match('/https:\/\/dai\.fancode\.com\/primary\/([^\/?]+)/', $url, $idMatch)) {
            $streamId = htmlspecialchars($idMatch[1], ENT_QUOTES, 'UTF-8');
        }
        
        // Only output if we have all required fields and they're valid
        if ($name && $logo && $url && $streamId && 
            filter_var($logo, FILTER_VALIDATE_URL) &&
            filter_var("https://faridflix.xyz/fan/go.php/{$streamId}.m3u8", FILTER_VALIDATE_URL)) {
            echo "#EXTINF:-1 tvg-logo=\"$logo\" group-title=\"Live Events\",$name\n";
            echo "https://faridflix.xyz/fan/go.php/{$streamId}.m3u8\n";
        }
    }
    exit;
}

// Call the function
displayMatches();
