<?php
// Add detailed debugging at the very beginning
error_log("=== GOOGLE CALLBACK STARTED ===");
error_log("Request URI: " . $_SERVER['REQUEST_URI']);
error_log("Script filename: " . $_SERVER['SCRIPT_FILENAME']);
error_log("Document root: " . $_SERVER['DOCUMENT_ROOT']);
error_log("GET parameters: " . print_r($_GET, true));
error_log("POST parameters: " . print_r($_POST, true));

require_once 'Database/datab.php';
require_once 'config/google_config.php';
session_start();

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Log the request for debugging
error_log("Google callback received: " . print_r($_GET, true));
error_log("Google callback server: " . print_r($_SERVER, true));

// Simple function to handle Google login
function handleGoogleLogin($db, $email, $google_id, $firstname, $lastname, $profile_picture = null) {
    try {
        // Check if user exists
        $stmt = $db->prepare("SELECT * FROM users WHERE email = ? OR google_id = ?");
        $stmt->execute([$email, $google_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user) {
            // Update user with Google ID if not already set
            if (empty($user['google_id'])) {
                $stmt = $db->prepare("UPDATE users SET google_id = ? WHERE user_id = ?");
                $stmt->execute([$google_id, $user['user_id']]);
            }
            
            // Set session variables
            $_SESSION['user_id'] = $user['user_id'];
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['user_firstname'] = $user['firstname'];
            $_SESSION['user_lastname'] = $user['user_lastname'];
            $_SESSION['user_address'] = $user['address'];
            $_SESSION['is_admin'] = $user['is_admin'];
            
            // Debug session
            error_log("User logged in: " . print_r($_SESSION, true));
            
            return true;
        } else {
            // Create new user
            $stmt = $db->prepare("INSERT INTO users (email, google_id, firstname, lastname, profile_picture, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
            $stmt->execute([$email, $google_id, $firstname, $lastname, $profile_picture]);
            
            $user_id = $db->lastInsertId();
            
            // Set session variables
            $_SESSION['user_id'] = $user_id;
            $_SESSION['user_email'] = $email;
            $_SESSION['user_firstname'] = $firstname;
            $_SESSION['user_lastname'] = $lastname;
            $_SESSION['is_admin'] = 0;
            
            // Debug session
            error_log("New user created: " . print_r($_SESSION, true));
            
            return true;
        }
    } catch (PDOException $e) {
        error_log("Database error in handleGoogleLogin: " . $e->getMessage());
        return false;
    }
}

// Check if code is present in the URL
if (isset($_GET['code'])) {
    $code = $_GET['code'];
    error_log("Authorization code received: " . $code);
    
    // Exchange code for access token
    $post_data = [
        'code' => $code,
        'client_id' => $google_client_id,
        'client_secret' => $google_client_secret,
        'redirect_uri' => $google_redirect_uri,
        'grant_type' => 'authorization_code'
    ];
    
    error_log("Token request data: " . print_r($post_data, true));
    
    $ch = curl_init($google_token_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post_data));
    $response = curl_exec($ch);
    
    if (curl_errno($ch)) {
        error_log("Curl error in token request: " . curl_error($ch));
        header("Location: google_error.php?error=token_request_failed");
        exit;
    }
    
    curl_close($ch);
    
    error_log("Token response: " . $response);
    
    $token_data = json_decode($response, true);
    
    if (isset($token_data['access_token'])) {
        // Get user info with access token
        $ch = curl_init($google_userinfo_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $token_data['access_token']
        ]);
        $response = curl_exec($ch);
        
        if (curl_errno($ch)) {
            error_log("Curl error in user info request: " . curl_error($ch));
            header("Location: google_error.php?error=user_info_request_failed");
            exit;
        }
        
        curl_close($ch);
        
        error_log("User info response: " . $response);
        
        $user_data = json_decode($response, true);
        
        if (isset($user_data['email'])) {
            // Login or register user
            if (handleGoogleLogin(
                $db,
                $user_data['email'],
                $user_data['sub'],
                $user_data['given_name'] ?? '',
                $user_data['family_name'] ?? '',
                $user_data['picture'] ?? null
            )) {
                // Debug session before redirect
                error_log("Session before redirect: " . print_r($_SESSION, true));
                
                // Force session write
                session_write_close();
                
                // Simple redirect based on user role
                if (isset($_SESSION['is_admin']) && $_SESSION['is_admin'] == 1) {
                    error_log("Redirecting to admin dashboard");
                    header("Location: admin/dashboard.php");
                } else {
                    error_log("Redirecting to index.php");
                    header("Location: index.php");
                }
                exit;
            } else {
                error_log("Failed to login/register user with Google");
                header("Location: google_error.php?error=login_failed");
                exit;
            }
        } else {
            error_log("No email in user data");
            header("Location: google_error.php?error=no_email");
            exit;
        }
    } else {
        error_log("No access token in response: " . $response);
        header("Location: google_error.php?error=no_access_token");
        exit;
    }
} else {
    error_log("No code parameter in callback");
    header("Location: google_error.php?error=no_code");
    exit;
}
?>