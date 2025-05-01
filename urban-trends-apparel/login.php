<?php

require_once 'Database/datab.php'; // Database connection file
require_once 'config/google_config.php'; // Google OAuth configuration
// Include CSS styles
session_start();

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Log session data for debugging
error_log("Login.php - Session data: " . print_r($_SESSION, true));

class Auth {
    private $db;
    
    public function __construct($db) {
        $this->db = $db;
    }
    
    public function login($email, $password) {
        $stmt = $this->db->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['user_id'];
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['user_firstname'] = $user['firstname'];
            $_SESSION['user_lastname'] = $user['lastname'];
            $_SESSION['user_address'] = $user['address'];
            $_SESSION['is_admin'] = $user['is_admin'];
            
            // Log session data for debugging
            error_log("Login successful - Session data: " . print_r($_SESSION, true));
            
            return true;
        }
        return false;
    }
    
    public function isLoggedIn() {
        return isset($_SESSION['user_id']);
    }
    
    public function isAdmin() {
        return isset($_SESSION['is_admin']) && $_SESSION['is_admin'] == 1;
    }
    
    public function logout() {
        session_unset();
        session_destroy();
    }
}

$auth = new Auth($db);
$error = '';

// Check for Google auth error
if (isset($_GET['error'])) {
    switch ($_GET['error']) {
        case 'google_auth_failed':
            $error = 'Google authentication failed. Please try again.';
            break;
        case 'token_request_failed':
            $error = 'Failed to get access token from Google. Please try again.';
            break;
        case 'user_info_request_failed':
            $error = 'Failed to get user information from Google. Please try again.';
            break;
        case 'login_failed':
            $error = 'Failed to login or register with Google. Please try again.';
            break;
        case 'no_email':
            $error = 'No email found in Google account. Please try again.';
            break;
        case 'no_access_token':
            $error = 'Failed to get access token from Google. Please try again.';
            break;
        case 'no_code':
            $error = 'No authorization code received from Google. Please try again.';
            break;
        default:
            $error = 'An error occurred during Google authentication. Please try again.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = htmlspecialchars($_POST['email']);
    $password = htmlspecialchars($_POST['password']);
    
    if ($auth->login($email, $password)) {
        if ($auth->isAdmin()) {
            header("Location: admin/dashboard.php");
            exit;
        } else {
            header("Location: index.php");
            exit;
        }
    } else {
        $error = 'Invalid email or password';
    }
}

// Redirect if already logged in
if ($auth->isLoggedIn()) {
    if ($auth->isAdmin()) {
        header("Location: admin/dashboard.php");
    } else {
        header("Location: index.php");
    }
    
    exit;
    
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Urban Trends Apparel - Login</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #1a1a1a;
            --secondary-color: #2d2d2d;
            --accent-gold:rgb(252, 249, 231);
            --accent-silver: #C0C0C0;
            --light-color: #ffffff;
            --dark-color: #000000;
            --text-color: #ffffff;
            --text-muted: #888888;
            --success-color: #28a745;
            --error-color: #dc3545;
            --warning-color: #ffc107;
            --border-radius: 8px;
            --box-shadow: 0 10px 20px rgba(0, 0, 0, 0.3);
            --transition: all 0.3s ease;
            --header-height: 70px;
            --footer-height: auto;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        body {
            min-height: 100vh;
            background: url('assets/Login_BG.jpg') no-repeat center center fixed;
            background-size: cover;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 0;
            position: relative;
            overflow: hidden;
            margin: 0;
        }

        /* Dark overlay for realism */
        .background-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            background: rgba(20, 20, 20, 0.65);
            z-index: 1;
            pointer-events: none;
        }

        .background-animation {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 1;
            opacity: 0.1;
        }

        .background-animation::before {
            content: '';
            position: absolute;
            width: 200%;
            height: 200%;
            background: url('assets/Login_BG.jpg') repeat;
            animation: backgroundMove 20s linear infinite;
        }

        @keyframes backgroundMove {
            0% { transform: translate(0, 0); }
            100% { transform: translate(-50%, -50%); }
        }

        .login-container {
            background: rgba(1, 1, 1, 0.18);
            backdrop-filter: blur(18px) saturate(120%);
            -webkit-backdrop-filter: blur(18px) saturate(120%);
            box-shadow: 0 16px 40px 0 rgba(0,0,0,0.45), 0 1.5px 4px 0 rgba(0,0,0,0.10);
            border: 1.5px solid rgba(6, 6, 6, 0.18);
            padding: 40px;
            border-radius: var(--border-radius);
            width: 100%;
            max-width: 450px;
            position: relative;
            z-index: 2;
            transform: translateY(20px);
            opacity: 0;
            animation: slideUp 0.5s ease forwards;
            margin: 0 auto;
            max-height: 90vh;
            overflow: auto;
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        @keyframes slideUp {
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .login-container::-webkit-scrollbar {
            display: none;
        }

        .logo {
            text-align: center;
            margin-bottom: 30px;
            animation: fadeIn 0.5s ease 0.3s forwards;
            opacity: 0;
            width: 100%;
        }

        @keyframes fadeIn {
            to { opacity: 1; }
        }

        .logo h1 {
            color: var(--accent-gold);
            font-size: 2rem;
            margin-bottom: 10px;
            text-transform: uppercase;
            letter-spacing: 2px;
        }

        .logo p {
            color: var(--text-muted);
            font-size: 0.9rem;
        }

        .input-group {
            margin-bottom: 20px;
            position: relative;
            animation: fadeIn 0.5s ease 0.5s forwards;
            opacity: 0;
            width: 100%;
        }

        .input-group label {
            color: var(--accent-silver);
            font-weight: 500;
            font-size: 0.95rem;
        }

        .input-group input {
            width: 100%;
            padding: 15px;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--accent-gold);
            border-radius: var(--border-radius);
            color: var(--text-color);
            font-size: 1rem;
            transition: var(--transition);
        }

        .input-group input:focus {
            border-color: var(--accent-gold);
            box-shadow: 0 0 0 3px rgba(251, 252, 253, 0.1);
            outline: none;
        }

        .password-toggle {
            position: absolute;
            right: 15px;
            top: 45px;
            color: var(--accent-silver);
            cursor: pointer;
            transition: var(--transition);
        }

        .password-toggle:hover {
            color: var(--accent-gold);
        }

        .options {
            width: 100%;
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
        }

        .remember-me {
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--accent-silver);
        }

        .remember-me input[type="checkbox"] {
            width: 18px;
            height: 18px;
            accent-color: var(--accent-gold);
            cursor: pointer;
        }

        .forgot-password a {
            color: var(--accent-gold);
            text-decoration: none;
            transition: var(--transition);
        }

        .forgot-password a:hover {
            text-decoration: underline;
        }

        .btn {
            width: 100%;
            max-width: 400px;
            margin: 0 auto 25px;
            padding: 12px;
            background: var(--accent-gold);
            color: var(--primary-color);
            border: none;
            border-radius: var(--border-radius);
            font-size: 1rem;
            font-weight: 500;
            cursor: pointer;
            transition: var(--transition);
            animation: fadeIn 0.5s ease 0.9s forwards;
            opacity: 0;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(251, 252, 253, 0.2);
        }

        .divider {
            width: 100%;
            max-width: 400px;
            margin: 25px auto;
            text-align: center;
            position: relative;
            color: var(--accent-silver);
        }

        .divider::before,
        .divider::after {
            content: '';
            position: absolute;
            top: 50%;
            width: 45%;
            height: 1px;
            background: var(--accent-gold);
        }

        .divider::before {
            left: 0;
        }

        .divider::after {
            right: 0;
        }

        .google-btn {
            width: 100%;
            max-width: 400px;
            margin: 0 auto 25px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(255, 255, 255, 0.1);
            color: var(--text-color);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: var(--border-radius);
            padding: 12px 20px;
            font-size: 16px;
            font-weight: 500;
            cursor: pointer;
            text-decoration: none;
            animation: fadeIn 0.5s ease 1.1s forwards;
            opacity: 0;
        }
        
        .google-btn:hover {
            background: rgba(255, 255, 255, 0.15);
            transform: translateY(-2px);
        }

        .register-link {
            width: 100%;
            text-align: center;
            margin-top: 20px;
            color: var(--text-muted);
            font-size: 0.9rem;
            animation: fadeIn 0.5s ease 1.3s forwards;
            opacity: 0;
        }

        .register-link a {
            color: var(--accent-gold);
            text-decoration: none;
            font-weight: 500;
            transition: var(--transition);
        }

        .register-link a:hover {
            text-decoration: underline;
        }

        .error-message {
            background: rgba(255, 51, 51, 0.1);
            border: 1px solid var(--error-color);
            color: var(--error-color);
            padding: 15px;
            border-radius: var(--border-radius);
            margin-bottom: 25px;
            font-size: 0.95rem;
            display: none;
            animation: slideDown 0.3s ease-out;
        }

        .error-message.show {
            display: block;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .browse-products-btn {
            width: 100%;
            max-width: 400px;
            margin: 0 auto 25px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            padding: 15px;
            background: linear-gradient(90deg, rgba(255,215,0,0.2) 0%, rgba(192,192,192,0.2) 100%);
            color: var(--accent-gold);
            border: 1px solid var(--accent-gold);
            border-radius: var(--border-radius);
            font-size: 1rem;
            font-weight: 500;
            cursor: pointer;
            transition: var(--transition);
            text-decoration: none;
        }

        .browse-products-btn:hover {
            background: linear-gradient(90deg, rgba(255,215,0,0.3) 0%, rgba(192,192,192,0.3) 100%);
            box-shadow: 0 2px 12px 0 rgba(255,215,0,0.12);
        }

        .loading-screen {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(24, 24, 24, 0.95);
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            z-index: 9999;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.3s, visibility 0.3s;
            backdrop-filter: blur(5px);
        }

        .loading-screen.active {
            opacity: 1;
            visibility: visible;
        }

        .loading-spinner {
            width: 50px;
            height: 50px;
            border: 4px solid rgba(251, 252, 253, 0.2);
            border-top: 4px solid var(--accent-gold);
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin-bottom: 20px;
        }

        .loading-text {
            font-size: 1.1rem;
            color: var(--accent-silver);
            font-weight: 500;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        @media (max-width: 768px) {
            .login-container {
                padding: 30px 20px;
                margin: 20px;
                max-width: calc(100% - 40px);
            }

            form, .btn, .google-btn, .divider, .browse-products-btn {
                max-width: 100%;
            }

            .logo h1 {
                font-size: 2rem;
            }

            .options {
                flex-direction: column;
                gap: 15px;
                align-items: flex-start;
            }
        }
    </style>
</head>
<body>
    <div class="background-overlay"></div>
    <div class="background-animation"></div>
    
    <div class="login-container">
        <div class="logo">
            <h1><i class="fas fa-tshirt"></i> Urban Trends</h1>
            <p>Login to your account</p>
        </div>
        
        <a href="shop.php" class="browse-products-btn">
            <i class="fas fa-shopping-bag"></i> Browse Products
        </a>
        
        <div class="error-message <?php echo $error ? 'show' : ''; ?>">
            <?php echo $error; ?>
        </div>
        
        <form id="loginForm" method="POST">
            <div class="input-group">
                <label for="email">Email Address</label>
                <input type="email" id="email" name="email" required 
                       value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
            </div>
            
            <div class="input-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required>
                <i class="fas fa-eye password-toggle" id="togglePassword"></i>
            </div>
            
            <div class="options">
                <div class="remember-me">
                    <input type="checkbox" id="rememberMe" name="remember">
                    <label for="rememberMe">Remember me</label>
                </div>
                <div class="forgot-password">
                    <a href="forgot_password.php">Forgot password?</a>
                </div>
            </div>
            
            <button type="submit" class="btn">Login</button>
            
            <div class="divider">OR</div>
            
            <a href="google_redirect.php" class="google-btn">
                <img src="https://www.google.com/favicon.ico" alt="Google Logo">
                Continue with Google
            </a>
            
            <div class="register-link">
                Don't have an account? <a href="register.php">Register here</a>
            </div>
        </form>
    </div>

    <div class="loading-screen" id="loadingScreen">
        <div class="loading-spinner"></div>
        <div class="loading-text">Loading...</div>
    </div>

    <script>
        // Password visibility toggle
        document.getElementById('togglePassword').addEventListener('click', function() {
            const passwordInput = document.getElementById('password');
            const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
            passwordInput.setAttribute('type', type);
            this.classList.toggle('fa-eye');
            this.classList.toggle('fa-eye-slash');
        });

        // Form validation
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            const email = document.getElementById('email').value.trim();
            const password = document.getElementById('password').value.trim();
            
            if (!email || !password) {
                e.preventDefault();
                const errorMessage = document.querySelector('.error-message');
                errorMessage.textContent = 'Please fill in all fields';
                errorMessage.classList.add('show');
            }
        });

        // Show loading screen on form submission with delay
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            e.preventDefault(); // Prevent immediate form submission
            document.getElementById('loadingScreen').classList.add('active');
            
            // Store form data
            const formData = new FormData(this);
            
            // Wait for 2.5 seconds before submitting
            setTimeout(() => {
                // Create and submit a new form
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = '';
                
                // Add all form fields
                for (let [key, value] of formData.entries()) {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = key;
                    input.value = value;
                    form.appendChild(input);
                }
                
                document.body.appendChild(form);
                form.submit();
            }, 2500);
        });

        // Show loading screen on Google login with delay
        document.querySelector('.google-btn').addEventListener('click', function(e) {
            e.preventDefault(); // Prevent immediate navigation
            document.getElementById('loadingScreen').classList.add('active');
            
            // Store the href
            const href = this.href;
            
            // Wait for 2.5 seconds before redirecting
            setTimeout(() => {
                window.location.href = href;
            }, 2500);
        });
    </script>
</body>
</html>