<?php
// Simple redirect page for Google OAuth
require_once 'config/google_config.php';

// Generate Google OAuth URL with proper parameters
$google_auth_params = [
    'client_id' => $google_client_id,
    'redirect_uri' => $google_redirect_uri,
    'response_type' => 'code',
    'scope' => implode(' ', $google_scopes),
    'access_type' => 'online',
    'prompt' => 'select_account'
];

// Create the Google OAuth URL
$google_auth_url_full = $google_auth_url . '?' . http_build_query($google_auth_params);

// Redirect to Google OAuth
header("Location: " . $google_auth_url_full);
exit;
?>