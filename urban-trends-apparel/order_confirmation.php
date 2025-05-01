<?php

require_once 'Database/datab.php';


session_start();

class Auth {
    private $db;
    
    public function __construct($db) {
        $this->db = $db;
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

// Redirect if not logged in
if (!$auth->isLoggedIn()) {
    header("Location: login.php");
    exit;
}

// Check if order ID is provided
if (!isset($_GET['order_id'])) {
    header("Location: shop.php");
    exit;
}

$order_id = intval($_GET['order_id']);
$user_id = $_SESSION['user_id'];

// Get order details
$stmt = $db->prepare("
    SELECT o.*, p.payment_method, p.transaction_id, p.status as payment_status,
           d.preferred_date, d.preferred_time_slot, d.pickup_option, d.pickup_location,
           pr.code as promo_code, pr.discount_value
    FROM orders o
    LEFT JOIN payments p ON o.order_id = p.order_id
    LEFT JOIN delivery_schedules d ON o.order_id = d.order_id
    LEFT JOIN promotions pr ON o.promo_id = pr.promotion_id
    WHERE o.order_id = ? AND o.user_id = ?
");
$stmt->execute([$order_id, $user_id]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    header("Location: shop.php");
    exit;
}

// Get order items with variation details
$stmt = $db->prepare("
    SELECT oi.*, p.name, p.image, pv.size
    FROM order_items oi 
    JOIN products p ON oi.product_id = p.product_id 
    LEFT JOIN product_variations pv ON oi.variation_id = pv.variation_id 
    WHERE oi.order_id = ?
");
$stmt->execute([$order_id]);
$order_items = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate totals
$subtotal = 0;
foreach ($order_items as $item) {
    $subtotal += $item['price'] * $item['quantity'];
}
$shipping = 50.00;
$discount_amount = $order['discount_amount'] ?? 0;
$total = $subtotal + $shipping - $discount_amount;

// Get cart count
$cart_count = 0;
if ($auth->isLoggedIn()) {
    $stmt = $db->prepare("SELECT COUNT(*) FROM cart WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $cart_count = $stmt->fetchColumn();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Urban Trends Apparel - Order Confirmation</title>
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
        }

        body {
            background: linear-gradient(135deg, #181818 0%, #232323 100%);
            color: var(--text-color);
            font-family: 'Poppins', sans-serif;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* Header Styles */
        header {
            background: linear-gradient(120deg, #232323 60%, #181818 100%);
            color: var(--accent-silver);
            box-shadow: var(--box-shadow);
            padding: 0;
            position: sticky;
            top: 0;
            z-index: 1000;
            height: var(--header-height);
        }

        .header-container {
            width: 100%;
            height: 100%;
            max-width: 1200px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 15px;
        }

        .logo-nav-container {
            display: flex;
            align-items: center;
            gap: 2rem;
            height: 100%;
        }

        .logo {
            font-size: 1.5rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
            white-space: nowrap;
        }

        .logo a {
            color: var(--accent-gold) !important;
            text-shadow: 0 2px 8px #000, 0 0 2px var(--accent-gold);
            text-decoration: none;
        }

        .logo i {
            color: var(--accent-gold) !important;
            text-shadow: 0 2px 8px #000, 0 0 2px var(--accent-gold);
            animation: shimmer 2.5s infinite linear;
        }

        @keyframes shimmer {
            0% { filter: brightness(1.1) drop-shadow(0 0 2px var(--accent-gold)); }
            50% { filter: brightness(2) drop-shadow(0 0 12px var(--accent-gold)); }
            100% { filter: brightness(1.1) drop-shadow(0 0 2px var(--accent-gold)); }
        }

        nav {
            height: 100%;
        }

        nav ul {
            display: flex;
            list-style: none;
            gap: 1rem;
            height: 100%;
            margin: 0;
        }

        nav li {
            height: 100%;
            display: flex;
            align-items: center;
        }

        nav a {
            color: var(--accent-silver);
            text-decoration: none;
            font-weight: 500;
            padding: 0.75rem 1.2rem;
            border-radius: var(--border-radius);
            transition: var(--transition);
            position: relative;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        nav a:hover {
            color: var(--accent-gold);
            background: linear-gradient(90deg, rgba(255,215,0,0.08) 0%, rgba(192,192,192,0.08) 100%);
            border: 1px solid var(--accent-gold);
            box-shadow: 0 2px 12px 0 rgba(255,215,0,0.12);
        }

        nav a i {
            font-size: 1.1em;
            transition: var(--transition);
        }

        nav a:hover i {
            transform: translateY(-2px);
            color: var(--accent-gold);
        }

        .user-actions {
            display: flex;
            align-items: center;
            gap: 1.2rem;
        }

        .user-actions a {
            color: var(--accent-silver);
            text-decoration: none;
            font-size: 1rem;
            transition: var(--transition);
            position: relative;
            padding: 0.5rem 1rem;
            border-radius: var(--border-radius);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .user-actions a:hover {
            color: var(--accent-gold);
            background: linear-gradient(90deg, rgba(255,215,0,0.08) 0%, rgba(192,192,192,0.08) 100%);
            border: 1px solid var(--accent-gold);
            box-shadow: 0 2px 12px 0 rgba(255,215,0,0.12);
        }

        .user-actions a i {
            font-size: 1.2em;
            transition: var(--transition);
        }

        .user-actions a:hover i {
            transform: translateY(-2px);
            color: var(--accent-gold);
        }

        .cart-link {
            position: relative;
            padding: 0.75rem 1.2rem !important;
            background: linear-gradient(90deg, rgba(255,215,0,0.12) 0%, rgba(192,192,192,0.12) 100%);
            border: 1px solid var(--accent-gold);
            border-radius: var(--border-radius);
            overflow: hidden;
            transition: var(--transition);
        }

        .cart-link:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 32px 0 rgba(255,215,0,0.18);
            background: linear-gradient(90deg, rgba(255,215,0,0.18) 0%, rgba(192,192,192,0.18) 100%);
        }

        .cart-count {
            position: absolute;
            top: -8px;
            right: -8px;
            background: linear-gradient(90deg, var(--accent-gold) 60%, var(--accent-silver) 100%);
            color: #181818;
            font-size: 0.75rem;
            width: 22px;
            height: 22px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            box-shadow: 0 4px 12px rgba(255,215,0,0.3);
            border: 2px solid var(--primary-color);
            animation: pulseCart 2s infinite;
        }

        @keyframes pulseCart {
            0% {
                box-shadow: 0 4px 12px rgba(255,215,0,0.3);
                transform: scale(1);
            }
            50% {
                box-shadow: 0 4px 24px rgba(255,215,0,0.5);
                transform: scale(1.05);
            }
            100% {
                box-shadow: 0 4px 12px rgba(255,215,0,0.3);
                transform: scale(1);
            }
        }

        /* Alert Styles */
        .alert {
            padding: 1rem;
            margin-bottom: 1.5rem;
            border-radius: var(--border-radius);
        }
        
        .alert-success {
            background-color: rgba(75, 181, 67, 0.2);
            border: 1px solid var(--success-color);
            color: var(--success-color);
        }

        /* Order Confirmation Styles */
        .confirmation-container {
            max-width: 800px;
            margin: 2rem auto;
            background: linear-gradient(135deg, rgba(35,35,35,0.95) 0%, rgba(24,24,24,0.95) 100%);
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            padding: 2rem;
            border: 1px solid rgba(255,215,0,0.1);
            position: relative;
            overflow: hidden;
        }
        
        .confirmation-container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, var(--accent-gold) 0%, var(--accent-silver) 100%);
            opacity: 0.8;
        }
        
        .confirmation-header {
            text-align: center;
            margin-bottom: 2rem;
            position: relative;
        }
        
        .confirmation-header h1 {
            font-size: 2rem;
            color: var(--accent-gold);
            margin-bottom: 1rem;
            text-transform: uppercase;
            letter-spacing: 2px;
            font-weight: 700;
            text-shadow: 0 2px 4px rgba(0,0,0,0.2);
        }
        
        .confirmation-icon {
            font-size: 4rem;
            color: var(--success-color);
            margin-bottom: 1rem;
            animation: bounce 2s infinite;
            filter: drop-shadow(0 4px 12px rgba(75,181,67,0.3));
        }
        
        @keyframes bounce {
            0%, 20%, 50%, 80%, 100% {transform: translateY(0);}
            40% {transform: translateY(-20px);}
            60% {transform: translateY(-10px);}
        }
        
        .order-details {
            background: linear-gradient(135deg, rgba(255,215,0,0.05) 0%, rgba(192,192,192,0.05) 100%);
            border-radius: var(--border-radius);
            padding: 1.5rem;
            margin-bottom: 2rem;
            border: 1px solid rgba(255,215,0,0.1);
        }
        
        .order-details-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }
        
        .order-detail-group {
            background: linear-gradient(135deg, rgba(35,35,35,0.6) 0%, rgba(24,24,24,0.6) 100%);
            padding: 1.5rem;
            border-radius: var(--border-radius);
            border: 1px solid rgba(255,215,0,0.05);
            transition: var(--transition);
        }
        
        .order-detail-group:hover {
            transform: translateY(-2px);
            border-color: rgba(255,215,0,0.1);
            box-shadow: 0 8px 32px rgba(255,215,0,0.1);
        }
        
        .order-detail-group h4 {
            margin-bottom: 0.8rem;
            color: var(--accent-gold);
            font-size: 1.1rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-weight: 600;
        }
        
        .order-items {
            margin-top: 2rem;
        }
        
        .order-item {
            display: flex;
            align-items: center;
            padding: 1.2rem;
            border-bottom: 1px solid rgba(255,215,0,0.1);
            gap: 1.5rem;
            transition: var(--transition);
        }
        
        .order-item:hover {
            background: linear-gradient(135deg, rgba(255,215,0,0.05) 0%, rgba(192,192,192,0.05) 100%);
            transform: translateX(5px);
        }
        
        .order-item:last-child {
            border-bottom: none;
        }
        
        .order-item-image {
            width: 80px;
            height: 80px;
            object-fit: cover;
            border-radius: var(--border-radius);
            border: 2px solid rgba(255,215,0,0.1);
            transition: var(--transition);
        }
        
        .order-item-image:hover {
            transform: scale(1.05);
            border-color: var(--accent-gold);
            box-shadow: 0 4px 16px rgba(255,215,0,0.2);
        }
        
        .order-item-details {
            flex: 1;
        }
        
        .order-item-details h4 {
            color: var(--accent-gold);
            margin-bottom: 0.5rem;
            font-weight: 600;
        }
        
        .order-item-price {
            font-weight: 700;
            color: var(--accent-gold);
            font-size: 1.1rem;
            margin-top: 0.5rem;
        }
        
        .order-item-size {
            color: var(--text-muted);
            font-size: 0.9rem;
            background: rgba(255,255,255,0.05);
            padding: 0.2rem 0.5rem;
            border-radius: 4px;
            display: inline-block;
        }
        
        .status-badge {
            display: inline-block;
            padding: 0.4rem 1rem;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .status-pending {
            background: linear-gradient(135deg, rgba(255,204,0,0.1) 0%, rgba(255,204,0,0.05) 100%);
            color: #ffcc00;
            border: 1px solid rgba(255,204,0,0.2);
        }
        
        .status-completed {
            background: linear-gradient(135deg, rgba(75,181,67,0.1) 0%, rgba(75,181,67,0.05) 100%);
            color: #4bb543;
            border: 1px solid rgba(75,181,67,0.2);
        }
        
        .action-buttons {
            display: flex;
            gap: 1.5rem;
            margin-top: 2rem;
            justify-content: center;
        }
        
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.8rem;
            padding: 1rem 2rem;
            border-radius: var(--border-radius);
            cursor: pointer;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            transition: var(--transition);
            text-decoration: none;
            position: relative;
            overflow: hidden;
        }
        
        .btn-primary {
            background: linear-gradient(90deg, var(--accent-gold) 60%, var(--accent-silver) 100%);
            color: #181818;
            border: none;
            box-shadow: 0 4px 24px rgba(255,215,0,0.18);
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 32px rgba(255,215,0,0.24);
            filter: brightness(1.2);
        }
        
        .btn-secondary {
            background: linear-gradient(90deg, rgba(255,215,0,0.1) 0%, rgba(192,192,192,0.1) 100%);
            color: var(--accent-gold);
            border: 1px solid var(--accent-gold);
        }
        
        .btn-secondary:hover {
            transform: translateY(-2px);
            background: linear-gradient(90deg, rgba(255,215,0,0.15) 0%, rgba(192,192,192,0.15) 100%);
            box-shadow: 0 8px 32px rgba(255,215,0,0.15);
        }
        
        .btn i {
            font-size: 1.2em;
            transition: var(--transition);
        }
        
        .btn:hover i {
            transform: translateY(-2px);
        }

        /* Footer */
        footer {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            padding: 3rem 0;
            margin-top: 3rem;
        }

        .footer-content {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 2rem;
            margin-bottom: 2rem;
        }

        .footer-column h3 {
            font-size: 1.5rem;
            margin-bottom: 1.5rem;
            position: relative;
            color: var(--accent-color);
        }

        .footer-column h3::after {
            content: '';
            position: absolute;
            bottom: -10px;
            left: 0;
            width: 50px;
            height: 3px;
            background-color: var(--accent-color);
        }

        .footer-column p {
            margin-bottom: 1rem;
            color: var(--text-muted);
        }

        .footer-column ul {
            list-style: none;
        }

        .footer-column li {
            margin-bottom: 0.8rem;
        }

        .footer-column a {
            color: var(--text-muted);
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
            padding-top: 2rem;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            color: var(--text-muted);
            font-size: 0.9rem;
        }

        @media (max-width: 768px) {
            .header-right {
                flex-direction: column;
                gap: 1rem;
            }
            
            nav ul {
                gap: 1rem;
            }
            
            .order-details-grid {
                grid-template-columns: 1fr;
            }
            
            .order-item {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>
<body>
<header>
    <div class="header-container">
        <div class="logo-nav-container">
        <div class="logo">
            <a href="index.php"><i class="fas fa-tshirt"></i> Urban Trends</a>
        </div>
            <nav>
                <ul>
                    <li><a href="index.php"><i class="fas fa-home"></i> Home</a></li>
                    <li><a href="shop.php"><i class="fas fa-store"></i> Shop</a></li>
                    <li><a href="about.php"><i class="fas fa-info-circle"></i> About</a></li>
                    <li><a href="contact.php"><i class="fas fa-envelope"></i> Contact</a></li>
                </ul>
            </nav>
        </div>
           
            <div class="user-actions">
                <?php if ($auth->isLoggedIn()): ?>
                <a href="profile.php" title="Profile">
                    <i class="fas fa-user"></i>
                    <span>Profile</span>
                </a>
                    <?php if ($auth->isAdmin()): ?>
                    <a href="admin/dashboard.php" title="Admin">
                        <i class="fas fa-cog"></i>
                        <span>Admin</span>
                    </a>
                <?php endif; ?>
                <a href="cart.php" class="cart-link" title="Cart">
                    <i class="fas fa-shopping-cart"></i>
                    <span>Cart</span>
                    <span class="cart-count"><?php echo $cart_count; ?></span>
                </a>
                <a href="?logout=1" title="Logout">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Logout</span>
                </a>
            <?php else: ?>
                <a href="login.php" title="Login">
                    <i class="fas fa-sign-in-alt"></i>
                    <span>Login</span>
                </a>
                <a href="register.php" title="Register">
                    <i class="fas fa-user-plus"></i>
                    <span>Register</span>
                </a>
            <?php endif; ?>
        </div>
    </div>
</header>
    
<main class="container">
    <div class="confirmation-container">
        <div class="confirmation-header">
            <div class="confirmation-icon">
                <i class="fas fa-check-circle"></i>
            </div>
            <h1>Order Confirmation</h1>
            <p>Thank you for your order! Your order number is #<?php echo $order_id; ?></p>
        </div>
        
        <div class="order-details">
            <h2>Order Summary</h2>
            
            <div class="order-details-grid">
                <div class="order-detail-group">
                    <h4><i class="fas fa-info-circle"></i> Order Information</h4>
                    <p><strong>Order #:</strong> <?php echo $order_id; ?></p>
                    <p><strong>Date:</strong> <?php echo date('M d, Y h:i A', strtotime($order['order_date'])); ?></p>
                    <p><strong>Status:</strong> <span class="status-badge status-<?php echo htmlspecialchars($order['status']); ?>">
                        <?php echo ucfirst(htmlspecialchars($order['status'])); ?>
                    </span></p>
                </div>
                
                <div class="order-detail-group">
                    <h4><i class="fas fa-truck"></i> Delivery Information</h4>
                    <?php if ($order['pickup_option']): ?>
                        <p><strong>Pickup Location:</strong> <?php echo htmlspecialchars($order['pickup_location'] ?: 'N/A'); ?></p>
                        <p><strong>Ready for pickup on:</strong> <?php echo date('M d, Y', strtotime($order['preferred_date'] ?: date('Y-m-d'))); ?></p>
                    <?php else: ?>
                        <p><strong>Delivery Address:</strong> <?php echo htmlspecialchars($order['shipping_address']); ?></p>
                        <p><strong>Scheduled Delivery:</strong> 
                            <?php echo date('M d, Y', strtotime($order['preferred_date'] ?: date('Y-m-d'))); ?> 
                            (<?php echo ucfirst(htmlspecialchars($order['preferred_time_slot'] ?: 'N/A')); ?>)
                        </p>
                    <?php endif; ?>
                </div>
                
                <div class="order-detail-group">
                    <h4><i class="fas fa-credit-card"></i> Payment Information</h4>
                    <p><strong>Method:</strong> <?php echo ucfirst(str_replace('_', ' ', htmlspecialchars($order['payment_method']))); ?></p>
                    <p><strong>Status:</strong> <span class="status-badge status-<?php echo $order['payment_status'] === 'completed' ? 'completed' : 'pending'; ?>">
                        <?php echo ucfirst(htmlspecialchars($order['payment_status'])); ?>
                    </span></p>
                    <?php if (!empty($order['transaction_id'])): ?>
                        <p><strong>Transaction ID:</strong> <?php echo htmlspecialchars($order['transaction_id']); ?></p>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="order-items">
                <h4><i class="fas fa-box-open"></i> Order Items</h4>
                <?php foreach ($order_items as $item): ?>
                    <div class="order-item">
                        <img src="assets/images/products/<?php echo htmlspecialchars($item['image'] ?: 'default-product.jpg'); ?>" 
                             alt="<?php echo htmlspecialchars($item['name']); ?>" 
                             class="order-item-image"
                             onerror="this.src='assets/images/products/default-product.jpg'">
                        <div class="order-item-details">
                            <h5><?php echo htmlspecialchars($item['name']); ?></h5>
                            <?php if ($item['size']): ?>
                                <p class="order-item-size">Size: <?php echo $item['size'] === 'N/A' ? 'Default' : htmlspecialchars($item['size']); ?></p>
                            <?php endif; ?>
                        </div>
                        <div style="text-align: right;">
                            <p>₱<?php echo number_format($item['price'], 2); ?></p>
                            <p>x<?php echo $item['quantity']; ?></p>
                            <p class="order-item-price">₱<?php echo number_format($item['price'] * $item['quantity'], 2); ?></p>
                        </div>
                    </div>
                <?php endforeach; ?>
                
                <div style="text-align: right; margin-top: 1rem;">
                    <p><strong>Subtotal:</strong> ₱<?php echo number_format($subtotal, 2); ?></p>
                    <?php if ($discount_amount > 0): ?>
                        <p class="discount"><strong>Promo Code Discount (<?php echo $order['promo_code']; ?>):</strong> -₱<?php echo number_format($discount_amount, 2); ?></p>
                    <?php endif; ?>
                    <p><strong>Shipping:</strong> ₱<?php echo number_format($shipping, 2); ?></p>
                    <p class="total"><strong>Total:</strong> ₱<?php echo number_format($total, 2); ?></p>
                </div>
            </div>
        </div>
        
        <div class="action-buttons">
            <a href="shop.php" class="btn btn-primary">
                <i class="fas fa-shopping-bag"></i>
                Continue Shopping
            </a>
            <a href="orders.php" class="btn btn-secondary">
                <i class="fas fa-list"></i>
                View Orders
            </a>
        </div>
    </div>
</main>

<footer>
    <div class="container">
        <div class="footer-content">
            <div class="footer-column">
                <h3>About Urban Trends</h3>
                <p>Your premier destination for the latest in urban fashion trends. We offer high-quality apparel and accessories for the modern urban lifestyle.</p>
                <div class="social-links">
                    <a href="#"><i class="fab fa-facebook-f"></i></a>
                    <a href="#"><i class="fab fa-twitter"></i></a>
                    <a href="#"><i class="fab fa-instagram"></i></a>
                    <a href="#"><i class="fab fa-pinterest"></i></a>
                </div>
            </div>
            
            <div class="footer-column">
                <h3>Quick Links</h3>
                <ul>
                    <li><a href="index.php"><i class="fas fa-chevron-right"></i> Home</a></li>
                    <li><a href="shop.php"><i class="fas fa-chevron-right"></i> Shop</a></li>
                    <li><a href="about.php"><i class="fas fa-chevron-right"></i> About Us</a></li>
                    <li><a href="contact.php"><i class="fas fa-chevron-right"></i> Contact Us</a></li>
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
                    <li><i class="fas fa-map-marker-alt"></i> 123 Urban Street, Fashion District, City</li>
                    <li><i class="fas fa-phone"></i> +1 (123) 456-7890</li>
                    <li><i class="fas fa-envelope"></i> info@urbantrends.com</li>
                    <li><i class="fas fa-clock"></i> Mon-Fri: 9AM - 6PM</li>
                </ul>
            </div>
        </div>
        
        <div class="copyright">
            © <?php echo date('Y'); ?> Urban Trends Apparel. All rights reserved.
        </div>
    </div>
</footer>
</body>
</html>