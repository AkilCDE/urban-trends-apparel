<?php
require_once 'includes/config.php';
require_once 'includes/google_auth.php';

// Check if user is already logged in
if (isset($_SESSION['user_id'])) {
    if ($_SESSION['is_admin']) {
        header('Location: admin/dashboard.php');
    } else {
        header('Location: index.php');
    }
    exit();
}

$error = '';
$success = '';

// Get Google user info if available
$google_user = isset($_SESSION['google_user']) ? $_SESSION['google_user'] : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $firstname = trim($_POST['firstname']);
    $lastname = trim($_POST['lastname']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $address = trim($_POST['address']);
    $country_code = trim($_POST['country_code']);
    $phone = trim($_POST['phone']);
    
    // Validate input
    if (empty($firstname)) {
        $error = "First name is required";
    } elseif (empty($lastname)) {
        $error = "Last name is required";
    } elseif (empty($email)) {
        $error = "Email is required";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Invalid email format";
    } elseif (empty($password)) {
        $error = "Password is required";
    } elseif (strlen($password) < 8) {
        $error = "Password must be at least 8 characters long";
    } elseif ($password !== $confirm_password) {
        $error = "Passwords do not match";
    } elseif (empty($address)) {
        $error = "Address is required";
    } elseif (empty($phone)) {
        $error = "Phone number is required";
    } elseif (empty($country_code)) {
        $error = "Country code is required";
    } elseif (!preg_match("/^\+[0-9]{1,4}$/", $country_code)) {
        $error = "Invalid country code format";
    } else {
        // Format the full phone number with country code
        $full_phone = $country_code . $phone;
        
        // Check if email already exists
        $stmt = $db->prepare("SELECT user_id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->rowCount() > 0) {
            $error = "Email already registered";
        } else {
            // Hash password
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            
            // Insert new user
            $stmt = $db->prepare("INSERT INTO users (email, password, firstname, lastname, address, phone, is_admin, created_at) VALUES (?, ?, ?, ?, ?, ?, 0, NOW())");
            if ($stmt->execute([$email, $hashed_password, $firstname, $lastname, $address, $full_phone])) {
                // Get the user ID of the newly created user
                $user_id = $db->lastInsertId();
                
                // Set session variables
                $_SESSION['user_id'] = $user_id;
                $_SESSION['user_email'] = $email;
                $_SESSION['user_firstname'] = $firstname;
                $_SESSION['user_lastname'] = $lastname;
                $_SESSION['user_address'] = $address;
                $_SESSION['user_phone'] = $full_phone;
                $_SESSION['is_admin'] = 0;
                
                // Log the registration
                $ip_address = $_SERVER['REMOTE_ADDR'];
                $stmt = $db->prepare("INSERT INTO audit_logs (user_id, action, table_name, record_id, new_values, ip_address) VALUES (?, 'register', 'users', ?, ?, ?)");
                $new_values = json_encode([
                    'email' => $email,
                    'firstname' => $firstname,
                    'lastname' => $lastname,
                    'address' => $address,
                    'phone' => $full_phone
                ]);
                $stmt->execute([$user_id, $user_id, $new_values, $ip_address]);
                
                // Redirect to welcome page or dashboard
                header('Location: index.php?registered=1');
                exit();
            } else {
                $error = "Registration failed. Please try again.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Urban Trends Apparel - Register</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #181818;
            --secondary-color: #232323;
            --accent-gold: rgb(251, 252, 253);
            --accent-silver: #C0C0C0;
            --light-color: #f8f9fa;
            --dark-color: #101010;
            --text-color: #e0e0e0;
            --text-muted: #b0b0b0;
            --success-color: #4bb543;
            --error-color: #ff3333;
            --warning-color: rgb(247, 247, 249);
            --border-radius: 12px;
            --box-shadow: 0 10px 32px rgba(0,0,0,0.45);
            --transition: all 0.4s cubic-bezier(.4,0,.2,1);
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

        .register-container {
            background: rgba(1, 1, 1, 0.18);
            backdrop-filter: blur(18px) saturate(120%);
            -webkit-backdrop-filter: blur(18px) saturate(120%);
            box-shadow: 0 16px 40px 0 rgba(0,0,0,0.45), 0 1.5px 4px 0 rgba(0,0,0,0.10);
            border: 1.5px solid rgba(6, 6, 6, 0.18);
            padding: 40px;
            border-radius: var(--border-radius);
            width: 100%;
            max-width: 600px;
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

        .register-container::-webkit-scrollbar {
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
            font-size: 2.5rem;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            text-shadow: 0 2px 8px #000, 0 0 2px var(--accent-gold);
        }

        .logo i {
            color: var(--accent-gold);
            text-shadow: 0 2px 8px #000, 0 0 2px var(--accent-gold);
            animation: shimmer 2.5s infinite linear;
        }

        @keyframes shimmer {
            0% { filter: brightness(1.1) drop-shadow(0 0 2px var(--accent-gold)); }
            50% { filter: brightness(2) drop-shadow(0 0 12px var(--accent-gold)); }
            100% { filter: brightness(1.1) drop-shadow(0 0 2px var(--accent-gold)); }
        }

        .logo p {
            color: var(--accent-silver);
            font-size: 1.1rem;
        }

        form {
            width: 100%;
            max-width: 500px;
            margin: 0 auto;
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
            margin-bottom: 8px;
            display: block;
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

        .form-row {
            display: flex;
            gap: 20px;
            margin-bottom: 20px;
            width: 100%;
        }

        .form-row .input-group {
            flex: 1;
            margin-bottom: 0;
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

        .btn {
            width: 100%;
            max-width: 500px;
            margin: 0 auto 25px;
            padding: 15px;
            background: linear-gradient(90deg, rgba(255,215,0,0.2) 0%, rgba(192,192,192,0.2) 100%);
            border: 1px solid var(--accent-gold);
            color: var(--accent-gold);
            border-radius: var(--border-radius);
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }

        .btn:hover {
            background: linear-gradient(90deg, rgba(255,215,0,0.3) 0%, rgba(192,192,192,0.3) 100%);
            box-shadow: 0 2px 12px 0 rgba(255,215,0,0.12);
        }

        .error-message, .success-message {
            width: 100%;
            max-width: 500px;
            margin: 0 auto 25px;
            padding: 15px;
            border-radius: var(--border-radius);
            font-size: 0.95rem;
            display: none;
            animation: slideDown 0.3s ease-out;
        }

        .error-message {
            background: rgba(255, 51, 51, 0.1);
            border: 1px solid var(--error-color);
            color: var(--error-color);
        }

        .success-message {
            background: rgba(75, 181, 67, 0.1);
            border: 1px solid var(--success-color);
            color: var(--success-color);
        }

        .error-message.show,
        .success-message.show {
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

        .login-link {
            width: 100%;
            text-align: center;
            margin-top: 20px;
            color: var(--accent-silver);
            font-size: 0.95rem;
            animation: fadeIn 0.5s ease 1.1s forwards;
            opacity: 0;
        }

        .login-link a {
            color: var(--accent-gold);
            text-decoration: none;
            font-weight: 500;
            transition: var(--transition);
        }

        .login-link a:hover {
            text-decoration: underline;
        }

        .password-strength-meter {
            height: 4px;
            background: rgba(255, 255, 255, 0.1);
            margin-top: 8px;
            border-radius: 2px;
            overflow: hidden;
            width: 100%;
        }

        .strength-bar {
            height: 100%;
            width: 0;
            transition: width 0.3s ease, background-color 0.3s ease;
        }

        .strength-bar.weak { background-color: var(--error-color); width: 25%; }
        .strength-bar.medium { background-color: var(--warning-color); width: 50%; }
        .strength-bar.strong { background-color: var(--success-color); width: 75%; }
        .strength-bar.very-strong { background-color: #007E33; width: 100%; }

        .password-requirements {
            margin-top: 10px;
            font-size: 0.85rem;
            width: 100%;
        }

        .requirement {
            color: var(--text-muted);
            margin: 4px 0;
            display: flex;
            align-items: center;
        }

        .requirement::before {
            content: '×';
            margin-right: 8px;
            color: var(--error-color);
        }

        .requirement.valid::before {
            content: '✓';
            color: var(--success-color);
        }

        .requirement.valid {
            color: var(--text-color);
        }

        @media (max-width: 768px) {
            .register-container {
                padding: 30px 20px;
                margin: 20px;
                max-width: calc(100% - 40px);
            }

            .form-row {
                flex-direction: column;
                gap: 20px;
            }

            form, .btn, .error-message, .success-message {
                max-width: 100%;
            }

            .logo h1 {
                font-size: 2rem;
            }
        }
    </style>
</head>
<body>
    <div class="background-overlay"></div>
    <div class="background-animation"></div>
    
    <div class="register-container">
        <div class="logo">
            <h1><i class="fas fa-tshirt"></i> Urban Trends</h1>
            <p>Create your account</p>
        </div>
        
        <div class="error-message <?php echo $error ? 'show' : ''; ?>">
            <?php echo $error; ?>
        </div>
        
        <div class="success-message <?php echo $success ? 'show' : ''; ?>">
            <?php echo $success; ?>
        </div>
        
        <form id="registerForm" method="POST">
            <div class="form-row">
                <div class="input-group">
                    <label for="firstname">First Name</label>
                    <input type="text" id="firstname" name="firstname" required 
                           value="<?php echo isset($_POST['firstname']) ? htmlspecialchars($_POST['firstname']) : ($google_user ? htmlspecialchars(explode(' ', $google_user['name'])[0]) : ''); ?>">
                </div>
                
                <div class="input-group">
                    <label for="lastname">Last Name</label>
                    <input type="text" id="lastname" name="lastname" required 
                           value="<?php echo isset($_POST['lastname']) ? htmlspecialchars($_POST['lastname']) : ($google_user && count(explode(' ', $google_user['name'])) > 1 ? htmlspecialchars(explode(' ', $google_user['name'])[1]) : ''); ?>">
                </div>
            </div>
            
            <div class="input-group">
                <label for="email">Email Address</label>
                <input type="email" id="email" name="email" required 
                       value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ($google_user ? htmlspecialchars($google_user['email']) : ''); ?>">
            </div>
            
            <div class="input-group phone-group">
                <label for="phone">Phone Number</label>
                <div class="phone-input-container">
                    <select name="country_code" id="country_code" required>
                        <option value="">Select Code</option>
                        <option value="+63">PH +63</option>
                        <option value="+1">US +1</option>
                        <option value="+44">UK +44</option>
                        <option value="+61">AU +61</option>
                        <option value="+81">JP +81</option>
                        <option value="+82">KR +82</option>
                        <option value="+86">CN +86</option>
                        <option value="+91">IN +91</option>
                        <option value="+65">SG +65</option>
                        <option value="+60">MY +60</option>
                    </select>
                    <input type="tel" id="phone" name="phone" required 
                           value="<?php echo isset($_POST['phone']) ? htmlspecialchars($_POST['phone']) : ''; ?>"
                           placeholder="Enter number without code">
                </div>
            </div>
            
            <div class="input-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required>
                <i class="fas fa-eye password-toggle" id="togglePassword"></i>
                <div class="password-strength-meter">
                    <div class="strength-bar"></div>
                </div>
                <div class="password-requirements">
                    <p class="requirement" data-requirement="length">8-24 characters</p>
                    <p class="requirement" data-requirement="uppercase">At least one uppercase letter</p>
                    <p class="requirement" data-requirement="lowercase">At least one lowercase letter</p>
                    <p class="requirement" data-requirement="number">At least one number</p>
                    <p class="requirement" data-requirement="special">At least one special character</p>
                </div>
            </div>
            
            <div class="input-group">
                <label for="confirm_password">Confirm Password</label>
                <input type="password" id="confirm_password" name="confirm_password" required>
                <i class="fas fa-eye password-toggle" id="toggleConfirmPassword"></i>
            </div>
            
            <div class="input-group">
                <label for="address">Address</label>
                <input type="text" id="address" name="address" required 
                       value="<?php echo isset($_POST['address']) ? htmlspecialchars($_POST['address']) : ''; ?>">
            </div>
            
            <button type="submit" class="btn">Create Account</button>
            
            <div class="login-link" style="margin-bottom: 20px;">
                Already have an account? <a href="login.php">Login here</a>
            </div>
        </form>
    </div>

    <script>
        // Password validation rules
        const passwordRules = {
            length: /^.{8,24}$/,
            uppercase: /[A-Z]/,
            lowercase: /[a-z]/,
            number: /[0-9]/,
            special: /[!@#$%^&*(),.?":{}|<>]/
        };

        // Password strength check
        function checkPasswordStrength(password) {
            let strength = 0;
            let validRules = 0;

            // Check each requirement
            Object.keys(passwordRules).forEach(rule => {
                const requirement = document.querySelector(`[data-requirement="${rule}"]`);
                if (passwordRules[rule].test(password)) {
                    requirement.classList.add('valid');
                    validRules++;
                } else {
                    requirement.classList.remove('valid');
                }
            });

            // Calculate strength based on valid rules
            if (validRules === 5) strength = 4; // Very strong
            else if (validRules === 4) strength = 3; // Strong
            else if (validRules === 3) strength = 2; // Medium
            else if (validRules === 2) strength = 1; // Weak
            else strength = 0; // Very weak

            return strength;
        }

        // Update strength meter
        function updateStrengthMeter(strength) {
            const strengthBar = document.querySelector('.strength-bar');
            strengthBar.className = 'strength-bar';
            
            if (strength === 4) strengthBar.classList.add('very-strong');
            else if (strength === 3) strengthBar.classList.add('strong');
            else if (strength === 2) strengthBar.classList.add('medium');
            else if (strength === 1) strengthBar.classList.add('weak');
        }

        // Password input handler
        document.getElementById('password').addEventListener('input', function(e) {
            const strength = checkPasswordStrength(this.value);
            updateStrengthMeter(strength);
        });

        // Password visibility toggle
        document.querySelectorAll('.password-toggle').forEach(toggle => {
            toggle.addEventListener('click', function() {
                const input = this.previousElementSibling;
                const type = input.getAttribute('type') === 'password' ? 'text' : 'password';
                input.setAttribute('type', type);
                this.classList.toggle('fa-eye');
                this.classList.toggle('fa-eye-slash');
            });
        });

        // Phone number validation by country code
        const phoneLengths = {
            '+63': 10, // Philippines
            '+1': 10,  // USA/Canada
            '+44': 10, // UK
            '+61': 9,  // Australia
            '+81': 10, // Japan
            '+82': 9,  // South Korea
            '+86': 11, // China
            '+91': 10, // India
            '+65': 8,  // Singapore
            '+60': 9   // Malaysia
        };

        // Disable phone input until country code is selected
        const phoneInput = document.getElementById('phone');
        const countryCodeSelect = document.getElementById('country_code');
        
        phoneInput.disabled = true;
        phoneInput.placeholder = 'Select country code first';

        countryCodeSelect.addEventListener('change', function(e) {
            const selectedCode = this.value;
            if (selectedCode) {
                phoneInput.disabled = false;
                phoneInput.placeholder = `Enter ${phoneLengths[selectedCode]} digits`;
                phoneInput.focus();
            } else {
                phoneInput.disabled = true;
                phoneInput.placeholder = 'Select country code first';
                phoneInput.value = '';
            }
        });

        // Form validation
        document.getElementById('registerForm').addEventListener('submit', function(e) {
            const password = document.getElementById('password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            const countryCode = document.getElementById('country_code').value;
            const phone = document.getElementById('phone').value;
            
            let isValid = true;
            const errorMessage = document.querySelector('.error-message');
            
            // Password validation
            if (!passwordRules.length.test(password)) {
                errorMessage.textContent = 'Password must be 8-24 characters long';
                errorMessage.classList.add('show');
                isValid = false;
                document.getElementById('password').focus();
            } else if (!passwordRules.uppercase.test(password)) {
                errorMessage.textContent = 'Password must contain at least one uppercase letter';
                errorMessage.classList.add('show');
                isValid = false;
                document.getElementById('password').focus();
            } else if (!passwordRules.lowercase.test(password)) {
                errorMessage.textContent = 'Password must contain at least one lowercase letter';
                errorMessage.classList.add('show');
                isValid = false;
                document.getElementById('password').focus();
            } else if (!passwordRules.number.test(password)) {
                errorMessage.textContent = 'Password must contain at least one number';
                errorMessage.classList.add('show');
                isValid = false;
                document.getElementById('password').focus();
            } else if (!passwordRules.special.test(password)) {
                errorMessage.textContent = 'Password must contain at least one special character';
                errorMessage.classList.add('show');
                isValid = false;
                document.getElementById('password').focus();
            } else if (password !== confirmPassword) {
                errorMessage.textContent = 'Passwords do not match';
                errorMessage.classList.add('show');
                isValid = false;
                document.getElementById('confirm_password').focus();
            }
            // ... rest of the validation code ...
            
            if (!isValid) {
                e.preventDefault();
            }
        });

        // Auto-format phone number as user types
        phoneInput.addEventListener('input', function(e) {
            // Remove any non-numeric characters
            this.value = this.value.replace(/\D/g, '');
            
            // Get the selected country code
            const countryCode = countryCodeSelect.value;
            
            // Check if the number exceeds the maximum length for the selected country
            if (countryCode && phoneLengths[countryCode]) {
                if (this.value.length > phoneLengths[countryCode]) {
                    this.value = this.value.slice(0, phoneLengths[countryCode]);
                }
            }
        });
    </script>
</body>
</html>