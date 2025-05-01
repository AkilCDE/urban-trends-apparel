<?php
// Simple error handling page for Google OAuth
session_start();

$error_message = "An unknown error occurred during Google authentication.";

if (isset($_GET['error'])) {
    switch ($_GET['error']) {
        case 'google_auth_failed':
            $error_message = 'Google authentication failed. Please try again.';
            break;
        case 'token_request_failed':
            $error_message = 'Failed to get access token from Google. Please try again.';
            break;
        case 'user_info_request_failed':
            $error_message = 'Failed to get user information from Google. Please try again.';
            break;
        case 'login_failed':
            $error_message = 'Failed to login or register with Google. Please try again.';
            break;
        case 'no_email':
            $error_message = 'No email found in Google account. Please try again.';
            break;
        case 'no_access_token':
            $error_message = 'Failed to get access token from Google. Please try again.';
            break;
        case 'no_code':
            $error_message = 'No authorization code received from Google. Please try again.';
            break;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Google Authentication Error - Urban Trends Apparel</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            font-family: 'Arial', sans-serif;
            background-color: #f5f5f5;
            margin: 0;
            padding: 0;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
        }
        
        .error-container {
            background-color: white;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            padding: 30px;
            max-width: 500px;
            width: 90%;
            text-align: center;
        }
        
        .error-icon {
            font-size: 48px;
            color: #e74c3c;
            margin-bottom: 20px;
        }
        
        h1 {
            color: #333;
            margin-bottom: 20px;
        }
        
        p {
            color: #666;
            margin-bottom: 30px;
            line-height: 1.6;
        }
        
        .btn {
            display: inline-block;
            background-color: #4285F4;
            color: white;
            padding: 12px 24px;
            border-radius: 4px;
            text-decoration: none;
            font-weight: 500;
            transition: background-color 0.3s;
        }
        
        .btn:hover {
            background-color: #3367d6;
        }
    </style>
</head>
<body>
    <div class="error-container">
        <div class="error-icon">
            <i class="fas fa-exclamation-circle"></i>
        </div>
        <h1>Google Authentication Error</h1>
        <p><?php echo htmlspecialchars($error_message); ?></p>
        <a href="login.php" class="btn">Return to Login</a>
    </div>
</body>
</html> 