<?php
// Database configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'urban_trends');

// Create database connection
try {
    $db = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME, DB_USER, DB_PASS);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

// Start session
session_start();

class Auth {
    private $db;
    
    public function __construct($db) {
        $this->db = $db;
    }
    
    public function isLoggedIn() {
        return isset($_SESSION['user_id']);
    }
    
    public function getCurrentUser() {
        if (!$this->isLoggedIn()) return null;
        $stmt = $this->db->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
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

// Redirect if not logged in
if (!$auth->isLoggedIn()) {
    header("Location: login.php");
    exit;
}

$user = $auth->getCurrentUser();
$page_title = 'Profile';
$message = '';

// Helper functions
function sanitize($data) {
    return htmlspecialchars(strip_tags(trim($data)));
}

function display_success($msg) {
    return '<div class="message message-success"><i class="fas fa-check-circle"></i> '.$msg.'</div>';
}

function display_error($msg) {
    return '<div class="message message-error"><i class="fas fa-exclamation-circle"></i> '.$msg.'</div>';
}

function getWishlistItems($db, $user_id) {
    $stmt = $db->prepare("SELECT p.* FROM wishlist w JOIN products p ON w.product_id = p.id WHERE w.user_id = ?");
    $stmt->execute([$user_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $firstname = sanitize($_POST['firstname']);
    $lastname = sanitize($_POST['lastname']);
    $address = sanitize($_POST['address']);
    
    // Update profile
    $stmt = $db->prepare("UPDATE users SET firstname = ?, lastname = ?, address = ? WHERE id = ?");
    if ($stmt->execute([$firstname, $lastname, $address, $user['id']])) {
        // Update session
        $_SESSION['user_firstname'] = $firstname;
        $_SESSION['user_lastname'] = $lastname;
        $_SESSION['user_address'] = $address;
        
        $message = display_success('Profile updated successfully!');
    } else {
        $message = display_error('Error updating profile.');
    }
    
    // Handle password change if provided
    if (!empty($_POST['new_password']) && !empty($_POST['confirm_password'])) {
        if ($_POST['new_password'] === $_POST['confirm_password']) {
            $hashed_password = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
            $stmt = $db->prepare("UPDATE users SET password = ? WHERE id = ?");
            if ($stmt->execute([$hashed_password, $user['id']])) {
                $message .= display_success('Password changed successfully!');
            } else {
                $message .= display_error('Error changing password.');
            }
        } else {
            $message .= display_error('Passwords do not match.');
        }
    }
}

// Get user's orders
$stmt = $db->prepare("SELECT * FROM orders WHERE user_id = ? ORDER BY order_date DESC");
$stmt->execute([$user['id']]);
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get wishlist items
$wishlist = getWishlistItems($db, $user['id']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - Urban Trends</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #2a2a2a;
            --secondary-color: #1a1a1a;
            --accent-color: #ff6b6b;
            --light-color: #f8f9fa;
            --dark-color: #121212;
            --text-color: #e0e0e0;
            --text-muted: #b0b0b0;
            --success-color: #4bb543;
            --error-color: #ff3333;
            --warning-color: #ffcc00;
            --border-radius: 8px;
            --box-shadow: 0 10px 20px rgba(0, 0, 0, 0.3);
            --transition: all 0.3s ease;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background-color: var(--dark-color);
            color: var(--text-color);
            line-height: 1.6;
        }

        /* Header Styles */
        header {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            padding: 1rem 2rem;
            box-shadow: var(--box-shadow);
            position: sticky;
            top: 0;
            z-index: 1000;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .logo {
            font-size: 1.8rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .logo a {
            color: white;
            text-decoration: none;
        }

        .logo i {
            color: var(--accent-color);
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.1); }
            100% { transform: scale(1); }
        }

        nav ul {
            display: flex;
            list-style: none;
            gap: 2rem;
        }

        nav a {
            color: white;
            text-decoration: none;
            font-weight: 500;
            padding: 0.5rem 1rem;
            border-radius: var(--border-radius);
            transition: var(--transition);
            position: relative;
        }

        nav a:hover {
            background-color: rgba(255, 255, 255, 0.1);
        }

        nav a::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 50%;
            transform: translateX(-50%);
            width: 0;
            height: 2px;
            background-color: var(--accent-color);
            transition: var(--transition);
        }

        nav a:hover::after {
            width: 70%;
        }

        .user-actions {
            display: flex;
            align-items: center;
            gap: 1.5rem;
        }

        .user-actions a {
            color: white;
            text-decoration: none;
            font-size: 1.2rem;
            transition: var(--transition);
        }

        .user-actions a:hover {
            color: var(--accent-color);
            transform: translateY(-2px);
        }

        .cart-count {
            position: relative;
        }

        .cart-count span {
            position: absolute;
            top: -10px;
            right: -10px;
            background-color: var(--accent-color);
            color: white;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.7rem;
            font-weight: bold;
        }

        /* Main Content */
        .profile-container {
            max-width: 1200px;
            margin: 2rem auto;
            padding: 0 1rem;
            display: grid;
            grid-template-columns: 250px 1fr;
            gap: 2rem;
        }

        .profile-sidebar {
            background: var(--primary-color);
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            padding: 1.5rem;
            height: fit-content;
        }

        .profile-sidebar h2 {
            font-size: 1.5rem;
            margin-bottom: 1.5rem;
            color: var(--accent-color);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .profile-sidebar h2 i {
            color: var(--accent-color);
        }

        .profile-sidebar ul {
            list-style: none;
        }

        .profile-sidebar li {
            margin-bottom: 0.5rem;
        }

        .profile-sidebar a {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 0.8rem 1rem;
            color: var(--text-color);
            text-decoration: none;
            border-radius: var(--border-radius);
            transition: var(--transition);
        }

        .profile-sidebar a:hover {
            background-color: rgba(255, 107, 107, 0.1);
            color: var(--accent-color);
            transform: translateX(5px);
        }

        .profile-sidebar a.active {
            background-color: rgba(255, 107, 107, 0.2);
            color: var(--accent-color);
            font-weight: 500;
        }

        .profile-sidebar a i {
            width: 20px;
            text-align: center;
        }

        .profile-content {
            background: var(--primary-color);
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            padding: 2rem;
        }

        .profile-section {
            animation: fadeIn 0.5s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .profile-section h2, .profile-section h3 {
            color: var(--accent-color);
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .profile-section h2 i, .profile-section h3 i {
            color: var(--accent-color);
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: var(--text-color);
        }

        .form-group input,
        .form-group textarea,
        .form-group select {
            width: 100%;
            padding: 0.8rem 1rem;
            background-color: rgba(255, 255, 255, 0.1);
            border: 1px solid #444;
            border-radius: var(--border-radius);
            font-family: inherit;
            font-size: 1rem;
            transition: var(--transition);
            color: var(--text-color);
        }

        .form-group input:focus,
        .form-group textarea:focus,
        .form-group select:focus {
            border-color: var(--accent-color);
            outline: none;
            box-shadow: 0 0 0 3px rgba(255, 107, 107, 0.2);
            background-color: rgba(255, 255, 255, 0.15);
        }

        .form-group textarea {
            min-height: 120px;
            resize: vertical;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background-color: var(--accent-color);
            color: white;
            border: none;
            padding: 0.8rem 1.5rem;
            border-radius: var(--border-radius);
            cursor: pointer;
            font-weight: 500;
            transition: var(--transition);
            text-decoration: none;
        }

        .btn:hover {
            background-color: #ff5252;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(255, 107, 107, 0.3);
        }

        .btn i {
            font-size: 0.9rem;
        }

        .btn-outline {
            background-color: transparent;
            border: 1px solid var(--accent-color);
            color: var(--accent-color);
        }

        .btn-outline:hover {
            background-color: var(--accent-color);
            color: white;
        }

        .btn-danger {
            background-color: var(--error-color);
        }

        .btn-danger:hover {
            background-color: #e60000;
        }

        /* Orders Table */
        .orders-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
        }

        .orders-table th,
        .orders-table td {
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid #444;
        }

        .orders-table th {
            background-color: rgba(255, 107, 107, 0.1);
            color: var(--accent-color);
            font-weight: 600;
        }

        .orders-table tr:hover {
            background-color: rgba(255, 255, 255, 0.05);
        }

        .status-badge {
            display: inline-block;
            padding: 0.3rem 0.6rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
        }

        .status-pending {
            background-color: rgba(255, 204, 0, 0.2);
            color: #ffcc00;
        }

        .status-processing {
            background-color: rgba(0, 123, 255, 0.2);
            color: #4dabf7;
        }

        .status-shipped {
            background-color: rgba(23, 162, 184, 0.2);
            color: #15aabf;
        }

        .status-delivered {
            background-color: rgba(40, 167, 69, 0.2);
            color: #40c057;
        }

        .status-cancelled {
            background-color: rgba(220, 53, 69, 0.2);
            color: #fa5252;
        }

        /* Wishlist Items */
        .wishlist-items {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-top: 1.5rem;
        }

        .wishlist-item {
            background-color: var(--secondary-color);
            border-radius: var(--border-radius);
            overflow: hidden;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
            transition: var(--transition);
            position: relative;
        }

        .wishlist-item:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.3);
        }

        .wishlist-item img {
            width: 100%;
            height: 200px;
            object-fit: cover;
            border-bottom: 1px solid #444;
        }

        .wishlist-item-info {
            padding: 1.2rem;
        }

        .wishlist-item-info h4 {
            margin-bottom: 0.5rem;
            font-size: 1.1rem;
            color: var(--text-color);
        }

        .wishlist-item-info p {
            font-weight: 600;
            color: var(--accent-color);
            margin-bottom: 1rem;
        }

        .wishlist-actions {
            display: flex;
            gap: 0.8rem;
        }

        .wishlist-actions .btn {
            flex: 1;
            justify-content: center;
            padding: 0.6rem;
            font-size: 0.9rem;
        }

        /* Messages */
        .message {
            padding: 1rem;
            margin-bottom: 1.5rem;
            border-radius: var(--border-radius);
            display: flex;
            align-items: center;
            gap: 10px;
            animation: slideIn 0.5s ease;
        }

        @keyframes slideIn {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .message-success {
            background-color: rgba(75, 181, 67, 0.2);
            color: var(--success-color);
            border-left: 4px solid var(--success-color);
        }

        .message-error {
            background-color: rgba(255, 51, 51, 0.2);
            color: var(--error-color);
            border-left: 4px solid var(--error-color);
        }

        /* Footer */
        footer {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            padding: 3rem 2rem;
            margin-top: 3rem;
        }

        .footer-content {
            max-width: 1200px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 2rem;
        }

        .footer-column h3 {
            font-size: 1.2rem;
            margin-bottom: 1.5rem;
            position: relative;
            display: inline-block;
            color: var(--accent-color);
        }

        .footer-column h3::after {
            content: '';
            position: absolute;
            bottom: -8px;
            left: 0;
            width: 50px;
            height: 3px;
            background-color: var(--accent-color);
        }

        .footer-column ul {
            list-style: none;
        }

        .footer-column li {
            margin-bottom: 0.8rem;
        }

        .footer-column a {
            color: rgba(255, 255, 255, 0.8);
            text-decoration: none;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .footer-column a:hover {
            color: white;
            transform: translateX(5px);
        }

        .footer-column a i {
            width: 20px;
        }

        .social-links {
            display: flex;
            gap: 1rem;
            margin-top: 1.5rem;
        }

        .social-links a {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            background-color: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            color: white;
            transition: var(--transition);
        }

        .social-links a:hover {
            background-color: var(--accent-color);
            transform: translateY(-3px);
        }

        .copyright {
            text-align: center;
            margin-top: 3rem;
            padding-top: 1.5rem;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            color: rgba(255, 255, 255, 0.7);
            font-size: 0.9rem;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .profile-container {
                grid-template-columns: 1fr;
            }

            .profile-sidebar {
                margin-bottom: 2rem;
            }

            nav ul {
                gap: 1rem;
            }

            .user-actions {
                gap: 1rem;
            }
        }

        @media (max-width: 576px) {
            header {
                flex-direction: column;
                padding: 1rem;
                gap: 1rem;
            }

            nav ul {
                flex-wrap: wrap;
                justify-content: center;
            }

            .user-actions {
                margin-top: 1rem;
            }

            .wishlist-items {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <header>
        <div class="logo">
            <a href="index.php"><i class="fas fa-tshirt"></i> Urban Trends</a>
        </div>
        <nav>
            <ul>
                <li><a href="index.php"><i class="fas fa-home"></i> Home</a></li>
                <li><a href="products.php"><i class="fas fa-box-open"></i> Products</a></li>
                <li><a href="about.php"><i class="fas fa-info-circle"></i> About</a></li>
                <li><a href="contact.php"><i class="fas fa-envelope"></i> Contact</a></li>
            </ul>
        </nav>
        <div class="user-actions">
            <a href="wishlist.php" title="Wishlist"><i class="fas fa-heart"></i></a>
            <a href="cart.php" class="cart-count" title="Cart">
                <i class="fas fa-shopping-cart"></i>
                <span id="cart-counter"><?php echo count($_SESSION['cart'] ?? []); ?></span>
            </a>
            <a href="profile.php" title="Profile"><i class="fas fa-user"></i></a>
            <a href="?logout=1" title="Logout"><i class="fas fa-sign-out-alt"></i></a>
        </div>
    </header>

    <div class="profile-container">
        <div class="profile-sidebar">
            <h2><i class="fas fa-user-circle"></i> Profile</h2>
            <ul>
                <li><a href="#edit-profile" class="active"><i class="fas fa-user-edit"></i> Edit profile</a></li>
                <li><a href="#change-password"><i class="fas fa-key"></i> Modify Password</a></li>
                <li><a href="#orders"><i class="fas fa-clipboard-list"></i> Orders</a></li>
                <li><a href="#wishlist"><i class="fas fa-heart"></i> Wishlist</a></li>
            </ul>
        </div>
        
        <div class="profile-content">
            <?php 
            if (!empty($message)) {
                echo '<div class="message ' . (strpos($message, 'success') !== false ? 'message-success' : 'message-error') . '">
                    <i class="fas ' . (strpos($message, 'success') !== false ? 'fa-check-circle' : 'fa-exclamation-circle') . '"></i>
                    ' . $message . '
                </div>';
            }
            ?>
            
            <!-- Edit Profile Section -->
            <div id="edit-profile" class="profile-section">
                <h2><i class="fas fa-user-edit"></i> Edit Profile</h2>
                <form method="POST">
                    <div class="form-group">
                        <label for="firstname">Firstname</label>
                        <input type="text" id="firstname" name="firstname" value="<?php echo htmlspecialchars($user['firstname']); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="lastname">Lastname</label>
                        <input type="text" id="lastname" name="lastname" value="<?php echo htmlspecialchars($user['lastname']); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="email">Email</label>
                        <input type="email" id="email" value="<?php echo htmlspecialchars($user['email']); ?>" disabled>
                    </div>
                    
                    <div class="form-group">
                        <label for="address">Address</label>
                        <textarea id="address" name="address" required><?php echo htmlspecialchars($user['address']); ?></textarea>
                    </div>
                    
                    <button type="submit" class="btn"><i class="fas fa-save"></i> Save Changes</button>
                </form>
            </div>
            
            <!-- Change Password Section -->
            <div id="change-password" class="profile-section" style="display: none;">
                <h3><i class="fas fa-key"></i> Change Password</h3>
                <form method="POST">
                    <div class="form-group">
                        <label for="current_password">Current Password</label>
                        <input type="password" id="current_password" name="current_password">
                    </div>
                    
                    <div class="form-group">
                        <label for="new_password">New Password</label>
                        <input type="password" id="new_password" name="new_password">
                    </div>
                    
                    <div class="form-group">
                        <label for="confirm_password">Confirm Password</label>
                        <input type="password" id="confirm_password" name="confirm_password">
                    </div>
                    
                    <button type="submit" class="btn"><i class="fas fa-key"></i> Change Password</button>
                </form>
            </div>
            
            <!-- Orders Section -->
            <div id="orders" class="profile-section" style="display: none;">
                <h3><i class="fas fa-clipboard-list"></i> Order History</h3>
                <?php if (empty($orders)): ?>
                    <p>You haven't placed any orders yet.</p>
                <?php else: ?>
                    <table class="orders-table">
                        <thead>
                            <tr>
                                <th>Order #</th>
                                <th>Date</th>
                                <th>Total</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($orders as $order): ?>
                                <tr>
                                    <td><?php echo $order['id']; ?></td>
                                    <td><?php echo date('M d, Y', strtotime($order['order_date'])); ?></td>
                                    <td>$<?php echo number_format($order['total_amount'], 2); ?></td>
                                    <td>
                                        <span class="status-badge status-<?php echo $order['status']; ?>">
                                            <?php echo ucfirst($order['status']); ?>
                                        </span>
                                    </td>
                                    <td><a href="order_details.php?id=<?php echo $order['id']; ?>" class="btn"><i class="fas fa-eye"></i> View</a></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
            
            <!-- Wishlist Section -->
            <div id="wishlist" class="profile-section" style="display: none;">
                <h3><i class="fas fa-heart"></i> Wishlist</h3>
                <?php if (empty($wishlist)): ?>
                    <p>Your wishlist is empty.</p>
                <?php else: ?>
                    <div class="wishlist-items">
                        <?php foreach ($wishlist as $product): ?>
                            <div class="wishlist-item">
                                <img src="assets/images/products/<?php echo htmlspecialchars($product['image']); ?>" alt="<?php echo htmlspecialchars($product['name']); ?>">
                                <div class="wishlist-item-info">
                                    <h4><?php echo htmlspecialchars($product['name']); ?></h4>
                                    <p>$<?php echo number_format($product['price'], 2); ?></p>
                                    <div class="wishlist-actions">
                                        <a href="product.php?id=<?php echo $product['id']; ?>" class="btn"><i class="fas fa-eye"></i> View</a>
                                        <button class="btn btn-danger remove-wishlist" data-id="<?php echo $product['id']; ?>">
                                            <i class="fas fa-trash-alt"></i> Remove
                                        </button>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <footer>
        <div class="footer-content">
            <div class="footer-column">
                <h3>About Urban Trends</h3>
                <p>Your premier destination for the latest in urban fashion trends.</p>
                <div class="social-links">
                    <a href="#"><i class="fab fa-facebook-f"></i></a>
                    <a href="#"><i class="fab fa-twitter"></i></a>
                    <a href="#"><i class="fab fa-instagram"></i></a>
                    <a href="#"><i class="fab fa-linkedin-in"></i></a>
                </div>
            </div>
            <div class="footer-column">
                <h3>Quick Links</h3>
                <ul>
                    <li><a href="index.php"><i class="fas fa-chevron-right"></i> Home</a></li>
                    <li><a href="products.php"><i class="fas fa-chevron-right"></i> Products</a></li>
                    <li><a href="about.php"><i class="fas fa-chevron-right"></i> About Us</a></li>
                    <li><a href="contact.php"><i class="fas fa-chevron-right"></i> Contact</a></li>
                    <li><a href="faq.php"><i class="fas fa-chevron-right"></i> FAQ</a></li>
                </ul>
            </div>
            <div class="footer-column">
                <h3>Customer Service</h3>
                <ul>
                    <li><a href="profile.php"><i class="fas fa-chevron-right"></i> My Account</a></li>
                    <li><a href="orders.php"><i class="fas fa-chevron-right"></i> Order Tracking</a></li>
                    <li><a href="returns.php"><i class="fas fa-chevron-right"></i> Returns & Refunds</a></li>
                    <li><a href="privacy.php"><i class="fas fa-chevron-right"></i> Privacy Policy</a></li>
                    <li><a href="terms.php"><i class="fas fa-chevron-right"></i> Terms & Conditions</a></li>
                </ul>
            </div>
            <div class="footer-column">
                <h3>Contact Info</h3>
                <ul>
                    <li><i class="fas fa-map-marker-alt"></i> 123 Street, City, Country</li>
                    <li><i class="fas fa-phone"></i> +123 456 7890</li>
                    <li><i class="fas fa-envelope"></i> info@urbantrends.com</li>
                    <li><i class="fas fa-clock"></i> Mon-Fri: 9AM - 6PM</li>
                </ul>
            </div>
        </div>
        <div class="copyright">
            &copy; <?php echo date('Y'); ?> Urban Trends Apparel. All Rights Reserved.
        </div>
    </footer>

    <script>
        // Profile section navigation
        document.querySelectorAll('.profile-sidebar a').forEach(link => {
            link.addEventListener('click', function(e) {
                e.preventDefault();
                
                // Remove active class from all links
                document.querySelectorAll('.profile-sidebar a').forEach(l => {
                    l.classList.remove('active');
                });
                
                // Add active class to clicked link
                this.classList.add('active');
                
                // Hide all sections
                document.querySelectorAll('.profile-section').forEach(section => {
                    section.style.display = 'none';
                });
                
                // Show selected section with animation
                const sectionId = this.getAttribute('href');
                const section = document.querySelector(sectionId);
                section.style.display = 'block';
                
                // Trigger animation
                section.style.animation = 'none';
                setTimeout(() => {
                    section.style.animation = 'fadeIn 0.5s ease';
                }, 10);
            });
        });
        
        // Remove from wishlist with confirmation and animation
        document.querySelectorAll('.remove-wishlist').forEach(button => {
            button.addEventListener('click', function() {
                if (!confirm('Are you sure you want to remove this item from your wishlist?')) {
                    return;
                }
                
                const productId = this.getAttribute('data-id');
                const wishlistItem = this.closest('.wishlist-item');
                
                // Add removal animation
                wishlistItem.style.transform = 'scale(0.9)';
                wishlistItem.style.opacity = '0.5';
                wishlistItem.style.transition = 'all 0.3s ease';
                
                setTimeout(() => {
                    fetch('wishlist_action.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: `product_id=${productId}&action=remove`
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            // Add slide out animation
                            wishlistItem.style.transform = 'translateX(-100%)';
                            wishlistItem.style.opacity = '0';
                            
                            setTimeout(() => {
                                wishlistItem.remove();
                                
                                // If no items left, show empty message
                                if (document.querySelectorAll('.wishlist-item').length === 0) {
                                    document.querySelector('#wishlist').innerHTML = `
                                        <h3><i class="fas fa-heart"></i> Wishlist</h3>
                                        <p>Your wishlist is empty.</p>
                                    `;
                                }
                            }, 300);
                        } else {
                            // Reset animation if error
                            wishlistItem.style.transform = '';
                            wishlistItem.style.opacity = '';
                            alert('Error: ' + data.message);
                        }
                    })
                    .catch(error => {
                        // Reset animation if error
                        wishlistItem.style.transform = '';
                        wishlistItem.style.opacity = '';
                        console.error('Error:', error);
                    });
                }, 300);
            });
        });

        // Update cart counter
        function updateCartCounter() {
            // This is a placeholder - replace with actual AJAX call to get cart count
            fetch('get_cart_count.php')
                .then(response => response.json())
                .then(data => {
                    document.getElementById('cart-counter').textContent = data.count;
                })
                .catch(error => {
                    console.error('Error fetching cart count:', error);
                });
        }

        // Initialize cart counter
        updateCartCounter();
    </script>
</body>
</html>