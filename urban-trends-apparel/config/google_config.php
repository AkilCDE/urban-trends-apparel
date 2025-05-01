<?php

$google_client_id = '627179461740-clb1pcffflvne23p2i6kjvh25pfelk9d.apps.googleusercontent.com';
$google_client_secret = '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';
$google_redirect_uri = 'http://localhost/urban-trends-apparel/urban-trends-apparel/google_callback.php';
//$google_redirect_uri = 'http://localhost/urban-trends-apparel/urban-trends-apparel/index.php';

$google_auth_url = 'https://accounts.google.com/o/oauth2/auth';
$google_token_url = 'https://oauth2.googleapis.com/token';
$google_userinfo_url = 'https://www.googleapis.com/oauth2/v3/userinfo';

// Scopes for Google OAuth
$google_scopes = [
    'https://www.googleapis.com/auth/userinfo.email',
    'https://www.googleapis.com/auth/userinfo.profile'
];


error_log("Google OAuth Configuration:");
error_log("Client ID: " . $google_client_id);
error_log("Redirect URI: " . $google_redirect_uri);
?> 