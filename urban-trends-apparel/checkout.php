<?php
// Database configuration
require_once 'Database/datab.php';

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
    
    public function isAdmin() {
        return isset($_SESSION['is_admin']) && $_SESSION['is_admin'] == 1;
    }
    
    public function logout() {
        session_unset();
        session_destroy();
    }
    
    public function getCurrentUser() {
        if ($this->isLoggedIn()) {
            $stmt = $this->db->prepare("SELECT * FROM users WHERE user_id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        }
        return null;
    }
    
    public function getWalletBalance($user_id) {
        $stmt = $this->db->prepare("SELECT balance FROM user_wallet WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? $result['balance'] : 0;
    }
    
    public function addToWallet($user_id, $amount) {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO user_wallet (user_id, balance) 
                VALUES (?, ?)
                ON DUPLICATE KEY UPDATE balance = balance + ?
            ");
            $stmt->execute([$user_id, $amount, $amount]);
            return true;
        } catch(PDOException $e) {
            error_log("Wallet error: " . $e->getMessage());
            throw new Exception("Failed to update wallet: " . $e->getMessage());
        }
    }
}

$auth = new Auth($db);

// Redirect if not logged in
if (!$auth->isLoggedIn()) {
    header("Location: login.php");
    exit;
}

$user = $auth->getCurrentUser();

// Handle logout
if (isset($_GET['logout'])) {
    $auth->logout();
    header("Location: login.php");
    exit;
}

// Get cart items with variation details
$user_id = $_SESSION['user_id'];
$stmt = $db->prepare("
    SELECT c.*, p.name, p.price AS base_price, p.image, pv.size, pv.price_adjustment, pv.stock
    FROM cart c 
    JOIN products p ON c.product_id = p.product_id 
    LEFT JOIN product_variations pv ON c.variation_id = pv.variation_id 
    WHERE c.user_id = ?
");
$stmt->execute([$user_id]);
$cart_items = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Initialize variables
$subtotal = 0;
$shipping_cost = 0;
$total = 0;
$discount_amount = 0;
$applied_promo = null;

// Get user's active promo code if exists
if (isset($_SESSION['user_id'])) {
    $stmt = $db->prepare("
        SELECT p.* 
        FROM promotions p
        WHERE p.user_id = ? 
        AND p.is_active = 1 
        AND p.current_uses < p.max_uses
        AND p.is_used = 0
        AND NOW() BETWEEN p.start_date AND p.end_date
        LIMIT 1
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $applied_promo = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Calculate cart total
if (!empty($cart_items)) {
    foreach ($cart_items as $item) {
        $item_price = $item['base_price'] + ($item['price_adjustment'] ?? 0);
        $subtotal += $item_price * $item['quantity'];
    }
    
    // Apply promo code discount if active
    if ($applied_promo) {
        $discount_amount = $subtotal * ($applied_promo['discount_value'] / 100);
    }
    
    // Calculate shipping cost (fixed 50 pesos)
    $shipping_cost = 50;
    
    // Calculate final total
    $total = $subtotal + $shipping_cost - $discount_amount;
}

// Debug information (you can remove this after testing)
error_log("Cart Items: " . print_r($cart_items, true));
error_log("Subtotal: " . $subtotal);
error_log("Discount Amount: " . $discount_amount);
error_log("Shipping Cost: " . $shipping_cost);
error_log("Total: " . $total);

// Get user's wallet balance
$wallet_balance = $auth->getWalletBalance($user_id);

// Handle add funds to wallet
if (isset($_POST['add_funds'])) {
    $amount = floatval($_POST['fund_amount']);
    if ($amount > 0) {
        if ($auth->addToWallet($user_id, $amount)) {
            $_SESSION['success_message'] = "Successfully added ₱" . number_format($amount, 2) . " to your wallet!";
            header("Location: checkout.php");
            exit;
        } else {
            $error = "Failed to add funds to wallet. Please try again.";
        }
    } else {
        $error = "Please enter a valid amount to add to your wallet.";
    }
}

// Handle checkout form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['checkout'])) {
    try {
        // Validate all required fields
        $required_fields = [
            'fullname' => 'Full Name',
            'email' => 'Email',
            'shipping_address' => 'Shipping Address',
            'phone' => 'Phone Number',
            'payment_method' => 'Payment Method'
        ];
        
        $missing_fields = [];
        foreach ($required_fields as $field => $name) {
            if (empty($_POST[$field])) {
                $missing_fields[] = $name;
            }
        }
        
        if (!empty($missing_fields)) {
            throw new Exception("Please fill in all required fields: " . implode(', ', $missing_fields));
        }
        
        $payment_method = $_POST['payment_method'];
        $transaction_id = null;
        $payment_status = 'pending';
        
        // Validate payment method specific fields
        switch ($payment_method) {
            case 'gcash':
                if (empty($_POST['gcash_number'])) {
                    throw new Exception("Please provide your GCash number.");
                }
                $transaction_id = 'GC' . time() . rand(100, 999);
                break;
                
            case 'paypal':
                $transaction_id = 'PP' . time() . rand(100, 999);
                break;
                
            case 'credit_card':
                if (empty($_POST['card_number']) || empty($_POST['card_name']) || 
                    empty($_POST['card_expiry']) || empty($_POST['card_cvv'])) {
                    throw new Exception("Please provide complete credit card information.");
                }
                $transaction_id = 'CC' . time() . rand(100, 999);
                break;
                
            case 'wallet':
                if ($wallet_balance < $total) {
                    throw new Exception("Insufficient funds in wallet. Please add more funds or choose another payment method.");
                }
                $payment_status = 'completed';
                $transaction_id = 'WL' . time() . rand(100, 999);
                break;
                
            case 'cod':
                $payment_status = 'pending';
                break;
                
            default:
                throw new Exception("Invalid payment method selected.");
        }
        
        // Validate stock for each item
        foreach ($cart_items as $item) {
            if ($item['variation_id']) {
                $stmt = $db->prepare("SELECT stock FROM product_variations WHERE variation_id = ?");
                $stmt->execute([$item['variation_id']]);
                $stock = $stmt->fetchColumn();
                
                if ($stock === false || $stock < $item['quantity']) {
                    throw new Exception("Insufficient stock for {$item['name']} (Size: {$item['size']}). Available: " . ($stock ?: 0));
                }
            }
        }
        
        // Start transaction
        $db->beginTransaction();
        
        try {
            // 1. Create order
            $stmt = $db->prepare("
                INSERT INTO orders (
                    user_id, promo_id, discount_amount, total_amount, 
                    shipping_address, status
                ) VALUES (?, ?, ?, ?, ?, 'pending')
            ");
            $promo_id = $applied_promo ? $applied_promo['promotion_id'] : null;
            $stmt->execute([
                $user_id,
                $promo_id,
                $discount_amount,
                $total,
                $_POST['shipping_address']
            ]);
            $order_id = $db->lastInsertId();
            
            // 2. Add order items
            foreach ($cart_items as $item) {
                $item_price = $item['base_price'] + ($item['price_adjustment'] ?? 0);
                $discounted_price = $applied_promo ? ($item_price * (1 - $discount_amount / 100)) : $item_price;
                $stmt = $db->prepare("
                    INSERT INTO order_items (order_id, product_id, variation_id, quantity, price, discounted_price) 
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $order_id,
                    $item['product_id'],
                    $item['variation_id'] ?: null,
                    $item['quantity'],
                    $item_price,
                    $discounted_price
                ]);
                
                // 3. Update variation stock
                if ($item['variation_id']) {
                    $stmt = $db->prepare("
                        UPDATE product_variations SET stock = stock - ? 
                        WHERE variation_id = ?
                    ");
                    $stmt->execute([$item['quantity'], $item['variation_id']]);
                }
            }
            
            // 4. Create payment record
            $stmt = $db->prepare("
                INSERT INTO payments (order_id, amount, payment_method, transaction_id, status) 
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $order_id,
                $total,
                $payment_method,
                $transaction_id,
                $payment_status
            ]);
            
            // 5. Add delivery schedule if provided
            if (!empty($_POST['delivery_date'])) {
                $stmt = $db->prepare("
                    INSERT INTO delivery_schedules 
                    (order_id, preferred_date, preferred_time_slot, pickup_option, pickup_location) 
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $order_id,
                    $_POST['delivery_date'],
                    $_POST['time_slot'],
                    isset($_POST['pickup_option']) ? 1 : 0,
                    $_POST['pickup_location'] ?? null
                ]);
            }
            
            // 6. If payment is wallet, deduct from wallet
            if ($payment_method === 'wallet') {
                $stmt = $db->prepare("
                    UPDATE user_wallet SET balance = balance - ? 
                    WHERE user_id = ? AND balance >= ?
                ");
                $stmt->execute([$total, $user_id, $total]);
                
                if ($stmt->rowCount() === 0) {
                    throw new Exception("Insufficient wallet balance.");
                }
            }
            
            // 7. Mark promo as used if applied
            if ($applied_promo) {
                $stmt = $db->prepare("
                    UPDATE promotions 
                    SET is_used = 1, 
                        current_uses = current_uses + 1,
                        last_used_date = NOW()
                    WHERE promotion_id = ?
                ");
                $stmt->execute([$applied_promo['promotion_id']]);
            }
            
            // 8. Clear cart
            $stmt = $db->prepare("DELETE FROM cart WHERE user_id = ?");
            $stmt->execute([$user_id]);
            
            // 9. Add initial order status
            $stmt = $db->prepare("
                INSERT INTO order_status_history (order_id, status, notes) 
                VALUES (?, ?, ?)
            ");
            $stmt->execute([
                $order_id,
                'pending',
                'Order created successfully'
            ]);
            
            // Commit transaction
            $db->commit();
            
            // Redirect to confirmation
            header("Location: order_confirmation.php?order_id=" . $order_id);
            exit;
            
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
        
    } catch (Exception $e) {
        $error = "Checkout failed: " . $e->getMessage();
    }
}

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
    <title>Urban Trends Apparel - Checkout</title>
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

        html {
            scroll-behavior: smooth;
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
            padding: 0;
            box-shadow: var(--box-shadow);
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

        /* Mobile menu toggle */
        .menu-toggle {
            display: none;
            background: none;
            border: none;
            color: var(--accent-silver);
            font-size: 1.5rem;
            cursor: pointer;
            padding: 0.5rem;
            border-radius: var(--border-radius);
            transition: var(--transition);
        }

        .menu-toggle:hover {
            color: var(--accent-gold);
            background: linear-gradient(135deg, rgba(251,252,253,0.1) 0%, rgba(192,192,192,0.1) 100%);
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

        nav a i {
            font-size: 1.1em;
            transition: var(--transition);
            color: var(--accent-silver);
        }

        nav a:hover {
            color: var(--accent-gold);
            background: linear-gradient(90deg, rgba(255,215,0,0.08) 0%, rgba(192,192,192,0.08) 100%);
            border: 1px solid var(--accent-gold);
            box-shadow: 0 2px 12px 0 rgba(255,215,0,0.12);
        }

        nav a:hover i {
            transform: translateY(-2px);
            color: var(--accent-gold);
        }

        nav a::after {
            content: '';
            position: absolute;
            bottom: 0.5rem;
            left: 50%;
            width: 0;
            height: 2px;
            background: linear-gradient(135deg, var(--accent-gold) 0%, var(--accent-silver) 100%);
            transition: var(--transition);
            transform: translateX(-50%);
        }

        nav a:hover::after {
            width: 60%;
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

        .user-actions a i {
            font-size: 1.2em;
            transition: var(--transition);
            color: var(--accent-silver);
        }

        .user-actions a:hover {
            color: var(--accent-gold);
            background: linear-gradient(90deg, rgba(255,215,0,0.08) 0%, rgba(192,192,192,0.08) 100%);
            border: 1px solid var(--accent-gold);
            box-shadow: 0 2px 12px 0 rgba(255,215,0,0.12);
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

        .cart-link i {
            font-size: 1.3em;
            margin-right: 0.3rem;
            transition: var(--transition);
        }

        .cart-link:hover i {
            transform: translateY(-2px) scale(1.1);
            color: var(--accent-gold);
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

        .cart-text {
            font-weight: 600;
            letter-spacing: 0.5px;
        }

        /* Alert Styles */
        .alert {
            padding: 1rem;
            margin-bottom: 1.5rem;
            border-radius: var(--border-radius);
        }
        
        .alert-danger {
            background-color: rgba(255, 51, 51, 0.2);
            border: 1px solid var(--error-color);
            color: var(--error-color);
        }
        
        .alert-success {
            background-color: rgba(75, 181, 67, 0.2);
            border: 1px solid var(--success-color);
            color: var(--success-color);
        }
        
        .alert-info {
            background-color: rgba(0, 123, 255, 0.2);
            border: 1px solid #007bff;
            color: #007bff;
        }

        /* Checkout specific styles */
        .checkout-container {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
            margin: 2rem 0;
        }
        
        .checkout-section {
            background-color: var(--primary-color);
            padding: 2rem;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
        }
        
        .checkout-section h2 {
            margin-bottom: 1.5rem;
            color: var(--accent-gold);
            border-bottom: 1px solid #444;
            padding-bottom: 0.5rem;
        }
        
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
        }
        
        .form-control {
            width: 100%;
            padding: 0.8rem;
            background-color: rgba(255, 255, 255, 0.1);
            border: 1px solid #444;
            border-radius: var(--border-radius);
            color: var(--text-color);
            font-size: 1rem;
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--accent-gold);
            box-shadow: 0 0 0 3px rgba(255, 107, 107, 0.2);
        }
        
        /* Payment Methods */
        .payment-methods {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        
        .payment-method {
            display: flex;
            align-items: center;
            gap: 0.8rem;
            padding: 1rem;
            background-color: rgba(255, 255, 255, 0.1);
            border-radius: var(--border-radius);
            cursor: pointer;
            transition: var(--transition);
        }
        
        .payment-method:hover {
            background-color: rgba(255, 255, 255, 0.2);
        }
        
        .payment-method.selected {
            background-color: rgba(255, 107, 107, 0.2);
            border: 1px solid var(--accent-gold);
        }
        
        .payment-method input {
            display: none;
        }
        
        .payment-method i {
            font-size: 1.5rem;
        }
        
        /* Wallet Section */
        .wallet-section {
            background-color: rgba(0, 0, 0, 0.2);
            padding: 1.5rem;
            border-radius: var(--border-radius);
            margin-bottom: 1.5rem;
        }
        
        .wallet-balance {
            font-size: 1.2rem;
            margin-bottom: 1rem;
        }
        
        .wallet-balance span {
            color: var(--accent-gold);
            font-weight: bold;
        }
        
        .wallet-form {
            display: flex;
            gap: 1rem;
        }
        
        .wallet-form input {
            flex: 1;
        }
        
        /* Order Summary */
        .order-summary {
            background: linear-gradient(135deg, rgba(35,35,35,0.95) 0%, rgba(24,24,24,0.95) 100%);
            padding: 2rem;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255,215,0,0.1);
            position: relative;
            overflow: hidden;
        }

        .order-summary::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, var(--accent-gold) 0%, var(--accent-silver) 100%);
            opacity: 0.8;
        }

        .order-summary h3 {
            font-size: 1.4rem;
            font-weight: 600;
            margin-bottom: 1.5rem;
            color: var(--accent-gold);
            position: relative;
            padding-bottom: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .order-summary h3::after {
            content: '';
            position: absolute;
            left: 0;
            bottom: 0;
            width: 50px;
            height: 2px;
            background: linear-gradient(90deg, var(--accent-gold) 60%, var(--accent-silver) 100%);
        }

        .order-summary-item {
            display: flex;
            justify-content: space-between;
            padding: 1.2rem;
            margin-bottom: 1rem;
            border-radius: var(--border-radius);
            background: linear-gradient(135deg, rgba(255,215,0,0.05) 0%, rgba(192,192,192,0.05) 100%);
            border: 1px solid rgba(255,215,0,0.1);
            transition: var(--transition);
        }

        .order-summary-item:hover {
            transform: translateY(-2px);
            border-color: rgba(255,215,0,0.2);
            box-shadow: 0 8px 32px rgba(255,215,0,0.1);
        }

        .item-details {
            display: flex;
            gap: 1.2rem;
            align-items: center;
            flex: 1;
        }

        .item-image {
            width: 80px;
            height: 80px;
            object-fit: cover;
            border-radius: var(--border-radius);
            border: 2px solid rgba(255,215,0,0.1);
            transition: var(--transition);
        }

        .item-image:hover {
            transform: scale(1.05);
            border-color: var(--accent-gold);
            box-shadow: 0 4px 16px rgba(255,215,0,0.2);
        }

        .item-details h4 {
            font-size: 1.1rem;
            margin-bottom: 0.5rem;
            color: var(--accent-gold);
            font-weight: 600;
        }

        .item-details p {
            color: var(--text-muted);
            font-size: 0.9rem;
            margin-bottom: 0.3rem;
        }

        .item-price {
            text-align: right;
            min-width: 120px;
        }

        .original-price {
            color: var(--text-muted);
            text-decoration: line-through;
            font-size: 0.9rem;
            margin-bottom: 0.3rem;
        }

        .item-discount {
            color: var(--success-color);
            font-weight: 600;
            font-size: 0.9rem;
            margin-bottom: 0.3rem;
            background: linear-gradient(90deg, rgba(75,181,67,0.1) 0%, rgba(75,181,67,0.05) 100%);
            padding: 0.2rem 0.5rem;
            border-radius: 4px;
            display: inline-block;
        }

        .discounted-price {
            color: var(--accent-silver);
            font-weight: 500;
            font-size: 0.95rem;
        }

        .item-total-amount {
            font-weight: 600;
            color: var(--accent-gold);
            margin-top: 0.5rem;
            font-size: 1.1rem;
            text-shadow: 0 2px 4px rgba(0,0,0,0.2);
        }

        .order-totals {
            margin-top: 2rem;
            padding: 1.5rem;
            border-radius: var(--border-radius);
            background: linear-gradient(135deg, rgba(255,215,0,0.08) 0%, rgba(192,192,192,0.08) 100%);
            border: 1px solid rgba(255,215,0,0.1);
            position: relative;
            overflow: hidden;
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            padding: 0.8rem 0;
            font-size: 1rem;
            position: relative;
        }

        .summary-label {
            color: var(--text-muted);
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-size: 0.9rem;
        }

        .summary-value {
            color: var(--accent-silver);
            font-weight: 600;
        }

        .summary-row.discount {
            margin: 0.8rem -1.5rem;
            padding: 1rem 1.5rem;
            background: linear-gradient(90deg, rgba(75,181,67,0.1) 0%, rgba(75,181,67,0.05) 100%);
            border-top: 1px solid rgba(75,181,67,0.1);
            border-bottom: 1px solid rgba(75,181,67,0.1);
        }

        .summary-row.discount .summary-label {
            color: var(--success-color);
        }

        .summary-row.discount .summary-value {
            color: var(--success-color);
            font-weight: 700;
        }

        .summary-row.total {
            margin-top: 1rem;
            padding-top: 1.2rem;
            border-top: 2px solid rgba(255,215,0,0.1);
        }

        .summary-row.total .summary-label {
            font-size: 1.1rem;
            color: var(--accent-gold);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .summary-row.total .summary-value {
            font-size: 1.4rem;
            font-weight: 700;
            color: var(--accent-gold);
            text-shadow: 0 2px 4px rgba(0,0,0,0.2);
            background: linear-gradient(90deg, var(--accent-gold) 0%, var(--accent-silver) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .back-to-cart {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1.5rem;
            background: linear-gradient(90deg, rgba(255,215,0,0.1) 0%, rgba(192,192,192,0.1) 100%);
            color: var(--accent-gold);
            border: 1px solid var(--accent-gold);
            border-radius: var(--border-radius);
            font-weight: 600;
            text-decoration: none;
            transition: var(--transition);
            margin-top: 1rem;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .back-to-cart:hover {
            transform: translateX(-5px);
            background: linear-gradient(90deg, rgba(255,215,0,0.15) 0%, rgba(192,192,192,0.15) 100%);
            box-shadow: 0 4px 16px rgba(255,215,0,0.15);
        }

        .back-to-cart i {
            transition: var(--transition);
        }

        .back-to-cart:hover i {
            transform: translateX(-5px);
        }

        .checkout-btn {
            width: 100%;
            padding: 1rem 2rem;
            margin-top: 1.5rem;
            background: linear-gradient(90deg, var(--accent-gold) 60%, var(--accent-silver) 100%);
            color: #181818;
            border: none;
            border-radius: var(--border-radius);
            font-weight: 700;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            font-size: 1.1rem;
            text-transform: uppercase;
            letter-spacing: 2px;
            box-shadow: 0 4px 24px 0 rgba(255,215,0,0.18);
            position: relative;
            overflow: hidden;
            animation: shineBtn 2.5s infinite linear;
        }

        .checkout-btn::before {
            content: '';
            position: absolute;
            top: 0; left: -75%;
            width: 50%; height: 100%;
            background: linear-gradient(120deg, rgba(255,255,255,0.18) 0%, rgba(255,255,255,0.01) 100%);
            transform: skewX(-20deg);
            animation: btnShine 2.5s infinite linear;
        }

        @keyframes btnShine {
            0% { left: -75%; }
            100% { left: 120%; }
        }

        @keyframes shineBtn {
            0%, 100% { filter: brightness(1.1); }
            50% { filter: brightness(1.3); }
        }

        .checkout-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 32px 0 rgba(255,215,0,0.24);
            filter: brightness(1.2);
        }

        .checkout-btn:active {
            transform: translateY(0);
            box-shadow: 0 4px 16px 0 rgba(255,215,0,0.18);
            filter: brightness(0.95);
        }

        .checkout-btn i {
            font-size: 1.2em;
            color: #181818;
        }

        .checkout-btn:disabled {
            background-color: #666;
            cursor: not-allowed;
            transform: none;
        }
        
        .cart-item {
            background: linear-gradient(120deg, var(--secondary-color) 0%, var(--primary-color) 100%);
            border: 1px solid var(--accent-silver);
            border-radius: var(--border-radius);
            padding: 1rem;
            margin-bottom: 1rem;
            display: flex;
            gap: 1rem;
            transition: var(--transition);
        }
        
        .cart-item:hover {
            border-color: var(--accent-gold);
            transform: translateY(-2px);
            box-shadow: var(--box-shadow);
        }
        
        .cart-item-image {
            width: 100px;
            height: 100px;
            object-fit: cover;
            border-radius: var(--border-radius);
            border: 1px solid var(--accent-silver);
        }
        
        .cart-item-details {
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }
        
        .cart-item-name {
            color: var(--accent-gold);
            font-weight: 600;
            font-size: 1.1rem;
        }
        
        .cart-item-size {
            color: var(--text-muted);
            font-size: 0.9rem;
        }
        
        .cart-item-price {
            color: var(--accent-silver);
            font-weight: 500;
        }
        
        .cart-item-quantity {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .cart-item-quantity button {
            background: var(--secondary-color);
            color: var(--accent-silver);
            border: 1px solid var(--accent-silver);
            border-radius: var(--border-radius);
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: var(--transition);
        }
        
        .cart-item-quantity button:hover {
            background: var(--primary-color);
            color: var(--accent-gold);
            border-color: var(--accent-gold);
        }
        
        .cart-summary {
            background: linear-gradient(120deg, var(--secondary-color) 0%, var(--primary-color) 100%);
            border: 1px solid var(--accent-silver);
            border-radius: var(--border-radius);
            padding: 1.5rem;
            margin-top: 2rem;
        }
        
        .cart-summary h2 {
            color: var(--accent-gold);
            margin-bottom: 1rem;
            font-size: 1.5rem;
            font-weight: 600;
        }
        
        .cart-summary-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.5rem;
            color: var(--text-color);
        }
        
        .cart-summary-total {
            border-top: 1px solid var(--accent-silver);
            margin-top: 1rem;
            padding-top: 1rem;
            font-weight: 600;
            color: var(--accent-gold);
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
            color: var(--accent-gold);
        }

        .footer-column h3::after {
            content: '';
            position: absolute;
            bottom: -10px;
            left: 0;
            width: 50px;
            height: 3px;
            background-color: var(--accent-gold);
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
            background-color: var(--accent-gold);
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
            .checkout-container {
                grid-template-columns: 1fr;
            }
            
            .payment-methods {
                grid-template-columns: 1fr;
            }
            
            .wallet-form {
                flex-direction: column;
            }

            .menu-toggle {
                display: block;
            }

            nav {
                position: fixed;
                top: var(--header-height);
                left: -100%;
                width: 100%;
                height: calc(100vh - var(--header-height));
                background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
                transition: var(--transition);
            }

            nav.active {
                left: 0;
            }

            nav ul {
                flex-direction: column;
                padding: 2rem;
                gap: 2rem;
            }

            nav li {
                width: 100%;
            }

            nav a {
                width: 100%;
                display: block;
                text-align: center;
                font-size: 1.2rem;
            }
        }

        .btn {
            background: linear-gradient(120deg, var(--secondary-color) 0%, var(--primary-color) 100%);
            color: var(--accent-gold);
            border: 1px solid var(--accent-silver);
            padding: 10px 20px;
            border-radius: var(--border-radius);
            cursor: pointer;
            font-weight: 500;
            transition: var(--transition);
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .btn:hover {
            background: linear-gradient(120deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            border-color: var(--accent-gold);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.3);
        }

        .btn:active {
            transform: translateY(0);
            box-shadow: 0 5px 15px rgba(0,0,0,0.4);
            filter: brightness(0.9);
        }

        .btn i {
            font-size: 1.1em;
        }

        .btn-primary {
            background: linear-gradient(90deg, var(--accent-gold) 60%, var(--accent-silver) 100%);
            color: #181818;
            border: none;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            box-shadow: 0 4px 24px 0 rgba(255,215,0,0.18);
            position: relative;
            overflow: hidden;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 32px 0 rgba(255,215,0,0.24);
            filter: brightness(1.2);
        }

        .btn-primary:active {
            transform: translateY(0);
            box-shadow: 0 4px 16px 0 rgba(255,215,0,0.18);
            filter: brightness(0.95);
        }

        .btn-secondary {
            background: linear-gradient(135deg, var(--secondary-color) 0%, var(--primary-color) 100%);
            color: var(--accent-gold);
            border: 1px solid var(--accent-gold);
            font-weight: 500;
        }

        .input-group {
            position: relative;
            margin-bottom: 1.5rem;
        }

        .input-group input,
        .input-group select,
        .input-group textarea {
            width: 100%;
            padding: 1rem;
            background: linear-gradient(135deg, rgba(35,35,35,0.8) 0%, rgba(24,24,24,0.8) 100%);
            border: 1px solid var(--secondary-color);
            border-radius: var(--border-radius);
            color: var(--text-color);
            font-size: 1rem;
            transition: var(--transition);
        }

        .input-group input:focus,
        .input-group select:focus,
        .input-group textarea:focus {
            outline: none;
            border-color: var(--accent-gold);
            box-shadow: 0 0 0 3px rgba(251,252,253,0.2);
        }

        .input-group label {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            pointer-events: none;
            transition: var(--transition);
        }

        .input-group input:focus ~ label,
        .input-group input:not(:placeholder-shown) ~ label {
            top: 0;
            font-size: 0.8rem;
            background: var(--primary-color);
            padding: 0 0.5rem;
            color: var(--accent-gold);
        }

        .section-title {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 1.5rem;
            color: var(--accent-gold);
            position: relative;
            padding-bottom: 0.5rem;
        }

        .section-title::after {
            content: '';
            position: absolute;
            left: 0;
            bottom: 0;
            width: 50px;
            height: 3px;
            background: linear-gradient(135deg, var(--accent-gold) 0%, var(--accent-silver) 100%);
        }

        .checkout-container {
            max-width: 1200px;
            margin: 2rem auto;
            padding: 0 1rem;
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 2rem;
        }

        .checkout-form,
        .order-summary {
            background: linear-gradient(135deg, rgba(35,35,35,0.8) 0%, rgba(24,24,24,0.8) 100%);
            padding: 2rem;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            backdrop-filter: blur(10px);
        }

        .payment-methods {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .payment-method {
            padding: 1rem;
            border: 1px solid var(--secondary-color);
            border-radius: var(--border-radius);
            cursor: pointer;
            transition: var(--transition);
            background: linear-gradient(135deg, rgba(35,35,35,0.8) 0%, rgba(24,24,24,0.8) 100%);
        }

        .payment-method:hover {
            border-color: var(--accent-gold);
            transform: translateY(-2px);
        }

        .payment-method.selected {
            border-color: var(--accent-gold);
            background: linear-gradient(135deg, rgba(251,252,253,0.1) 0%, rgba(192,192,192,0.1) 100%);
        }
    </style>
</head>
<body>
<header>
    <div class="header-container">
        <div class="logo-nav-container">
            <button class="menu-toggle" aria-label="Toggle navigation menu">
                <i class="fas fa-bars"></i>
            </button>
            <div class="logo">
                <a href="index.php"><i class="fas fa-tshirt"></i> <span>Urban Trends</span></a>
            </div>
            <nav id="main-nav">
                <ul>
                    <li><a href="index.php"><i class="fas fa-home"></i> <span>Home</span></a></li>
                    <li><a href="shop.php"><i class="fas fa-store"></i> <span>Shop</span></a></li>
                    <li><a href="about.php"><i class="fas fa-info-circle"></i> <span>About</span></a></li>
                    <li><a href="contact.php"><i class="fas fa-envelope"></i> <span>Contact</span></a></li>
                </ul>
            </nav>
        </div>
        
        <div class="user-actions">
            <?php if ($auth->isLoggedIn()): ?>
                <a href="profile.php" title="Profile" aria-label="Profile">
                    <i class="fas fa-user"></i>
                    <span>Profile</span>
                </a>
                <?php if ($auth->isAdmin()): ?>
                    <a href="admin/dashboard.php" title="Admin" aria-label="Admin Dashboard">
                        <i class="fas fa-cog"></i>
                        <span>Admin</span>
                    </a>
                <?php endif; ?>
               
                <a href="?logout=1" title="Logout" aria-label="Logout">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Logout</span>
                </a>
            <?php else: ?>
                <a href="login.php" title="Login" aria-label="Login">
                    <i class="fas fa-sign-in-alt"></i>
                    <span>Login</span>
                </a>
                <a href="register.php" title="Register" aria-label="Register">
                    <i class="fas fa-user-plus"></i>
                    <span>Register</span>
                </a>
            <?php endif; ?>
        </div>
    </div>
</header>
    
<main class="container">
    <h1 style="margin: 2rem 0 1rem;">Checkout</h1>
    
    <?php if (!empty($error)): ?>
        <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo $error; ?></div>
    <?php endif; ?>
    
    <?php if (!empty($voucher_error)): ?>
        <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo $voucher_error; ?></div>
    <?php endif; ?>
    
    <?php if (!empty($voucher_success)): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $voucher_success; ?></div>
    <?php endif; ?>
    
    <?php if (isset($_SESSION['success_message'])): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?></div>
    <?php endif; ?>
    
    <?php if (empty($cart_items)): ?>
        <div class="alert alert-info">
            <i class="fas fa-info-circle"></i> Your cart is empty. <a href="shop.php">Continue shopping</a>
        </div>
    <?php else: ?>
        <form method="POST" class="checkout-container" id="checkoutForm">
            <div class="checkout-section">
                <h2>Shipping Information</h2>
                
                <div class="form-group">
                    <label for="fullname">Full Name</label>
                    <input type="text" id="fullname" name="fullname" class="form-control" required 
                           value="<?php echo htmlspecialchars($user['firstname'] . ' ' . $user['lastname']); ?>">
                </div>
                
                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" class="form-control" required 
                           value="<?php echo htmlspecialchars($user['email']); ?>">
                </div>
                
                <div class="form-group">
                    <label for="shipping_address">Shipping Address</label>
                    <textarea id="shipping_address" name="shipping_address" class="form-control" rows="4" required><?php 
                        echo htmlspecialchars($user['address']); 
                    ?></textarea>
                </div>
                
                <div class="form-group">
                    <label for="phone">Phone Number</label>
                    <input type="tel" id="phone" name="phone" class="form-control" required
                           value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>">
                </div>
                
                <h2>Delivery Options</h2>
                
                <div class="form-group">
                    <label for="delivery_date">Preferred Delivery Date</label>
                    <input type="date" id="delivery_date" name="delivery_date" class="form-control" 
                           min="<?php echo date('Y-m-d', strtotime('+2 days')); ?>">
                </div>
                
                <div class="form-group">
                    <label for="time_slot">Preferred Time Slot</label>
                    <select id="time_slot" name="time_slot" class="form-control">
                        <option value="morning">Morning (9AM - 12PM)</option>
                        <option value="afternoon">Afternoon (1PM - 5PM)</option>
                        <option value="evening">Evening (6PM - 9PM)</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>
                        <input type="checkbox" name="pickup_option" id="pickup_option"> 
                        I prefer to pick up in-store
                    </label>
                </div>
                
                <div class="form-group" id="pickup_location_group" style="display: none;">
                    <label for="pickup_location">Pickup Location</label>
                    <select id="pickup_location" name="pickup_location" class="form-control">
                        <option value="Main Store">Main Store - 123 Urban Street</option>
                        <option value="Mall Branch">Mall Branch - Fashion District</option>
                        <option value="Downtown Branch">Downtown Branch - City Center</option>
                    </select>
                </div>
                
                <h2>Payment Method</h2>
                
                <div class="payment-methods">
                    <label class="payment-method">
                        <input type="radio" name="payment_method" value="cod" required>
                        <i class="fas fa-money-bill-wave"></i>
                        <span>Cash on Delivery</span>
                    </label>
                    
                    <label class="payment-method">
                        <input type="radio" name="payment_method" value="gcash">
                        <i class="fas fa-mobile-alt"></i>
                        <span>GCash</span>
                    </label>
                    
                    <label class="payment-method">
                        <input type="radio" name="payment_method" value="paypal">
                        <i class="fab fa-paypal"></i>
                        <span>PayPal</span>
                    </label>
                    
                    <label class="payment-method">
                        <input type="radio" name="payment_method" value="credit_card">
                        <i class="far fa-credit-card"></i>
                        <span>Credit Card</span>
                    </label>
                    
                    <?php if ($wallet_balance > 0): ?>
                    <label class="payment-method">
                        <input type="radio" name="payment_method" value="wallet">
                        <i class="fas fa-wallet"></i>
                        <span>Wallet (₱<?php echo number_format($wallet_balance, 2); ?>)</span>
                    </label>
                    <?php endif; ?>
                </div>
                
                <!-- Payment details (shown based on selection) -->
                <div id="payment-details"></div>
            </div>
            
            <div class="checkout-section">
                <h2>Order Summary</h2>
                
                <div class="order-summary">
                    <h3>Order Summary</h3>
                    
                    <!-- Cart Items -->
                    <?php foreach ($cart_items as $item): 
                        $item_price = $item['base_price'] + ($item['price_adjustment'] ?? 0);
                        $discounted_price = $applied_promo ? ($item_price * (1 - $applied_promo['discount_value'] / 100)) : $item_price;
                        $item_total = $discounted_price * $item['quantity'];
                    ?>
                        <div class="order-summary-item">
                            <div class="item-details">
                                <img src="assets/images/products/<?php echo htmlspecialchars($item['image']); ?>" 
                                     alt="<?php echo htmlspecialchars($item['name']); ?>" 
                                     class="item-image"
                                     onerror="this.src='assets/images/products/default-product.jpg'">
                                <div>
                                    <h4><?php echo htmlspecialchars($item['name']); ?></h4>
                                    <?php if ($item['size']): ?>
                                        <p>Size: <?php echo $item['size'] === 'N/A' ? 'Default' : htmlspecialchars($item['size']); ?></p>
                                    <?php endif; ?>
                                    <p>Quantity: <?php echo $item['quantity']; ?></p>
                                </div>
                            </div>
                            <div class="item-price">
                                <?php if ($applied_promo): ?>
                                    <p class="original-price">₱<?php echo number_format($item_price, 2); ?> × <?php echo $item['quantity']; ?></p>
                                    <p class="item-discount">-<?php echo $applied_promo['discount_value']; ?>%</p>
                                    <p class="discounted-price">₱<?php echo number_format($discounted_price, 2); ?> × <?php echo $item['quantity']; ?></p>
                                <?php else: ?>
                                    <p class="item-total">₱<?php echo number_format($item_price, 2); ?> × <?php echo $item['quantity']; ?></p>
                                <?php endif; ?>
                                <div class="item-total-amount">₱<?php echo number_format($item_total, 2); ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    
                    <!-- Order Totals -->
                    <div class="order-totals">
                        <div class="summary-row">
                            <span class="summary-label">Subtotal:</span>
                            <span class="summary-value">₱<?php echo number_format($subtotal, 2); ?></span>
                        </div>
                        
                        <?php if ($applied_promo): ?>
                            <div class="summary-row discount">
                                <span class="summary-label">Promo Code Discount (<?php echo $applied_promo['discount_value']; ?>%):</span>
                                <span class="summary-value">-₱<?php echo number_format($discount_amount, 2); ?></span>
                            </div>
                        <?php endif; ?>
                        
                        <div class="summary-row">
                            <span class="summary-label">Shipping Cost:</span>
                            <span class="summary-value">₱<?php echo number_format($shipping_cost, 2); ?></span>
                        </div>
                        
                        <div class="summary-row total">
                            <span class="summary-label">Total Amount:</span>
                            <span class="summary-value">₱<?php echo number_format($total, 2); ?></span>
                        </div>
                    </div>
                </div>
                
                <button type="submit" name="checkout" class="checkout-btn" id="completeOrderBtn">
                    <i class="fas fa-credit-card"></i> Complete Order
                </button>
                
                <a href="profile.php#cart" class="back-to-cart">
                    <i class="fas fa-arrow-left"></i> Back to Cart
                </a>
            </div>
        </form>
    <?php endif; ?>
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

<script>
    // Show/hide pickup location based on checkbox
    document.getElementById('pickup_option').addEventListener('change', function() {
        const pickupLocationGroup = document.getElementById('pickup_location_group');
        pickupLocationGroup.style.display = this.checked ? 'block' : 'none';
    });

    // Set minimum delivery date (2 days from now)
    document.getElementById('delivery_date').min = new Date(Date.now() + 2 * 24 * 60 * 60 * 1000).toISOString().split('T')[0];

    // Show payment details based on selection
    document.querySelectorAll('input[name="payment_method"]').forEach(radio => {
        radio.addEventListener('change', function() {
            const paymentDetails = document.getElementById('payment-details');
            let html = '';
            
            switch(this.value) {
                case 'gcash':
                    html = `
                        <div class="form-group">
                            <label for="gcash_number">GCash Number</label>
                            <input type="text" id="gcash_number" name="gcash_number" class="form-control" placeholder="09XXXXXXXXX" required>
                        </div>
                        <div class="form-group">
                            <label for="gcash_name">Account Name</label>
                            <input type="text" id="gcash_name" name="gcash_name" class="form-control" required>
                        </div>
                    `;
                    break;
                    
                case 'paypal':
                    html = `
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> You will be redirected to PayPal to complete your payment.
                        </div>
                    `;
                    break;
                    
                case 'credit_card':
                    html = `
                        <div class="form-group">
                            <label for="card_number">Card Number</label>
                            <input type="text" id="card_number" name="card_number" class="form-control" placeholder="1234 5678 9012 3456" required>
                        </div>
                        <div class="form-group">
                            <label for="card_name">Name on Card</label>
                            <input type="text" id="card_name" name="card_name" class="form-control" required>
                        </div>
                        <div class="form-group" style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                            <div>
                                <label for="card_expiry">Expiry Date</label>
                                <input type="text" id="card_expiry" name="card_expiry" class="form-control" placeholder="MM/YY" required>
                            </div>
                            <div>
                                <label for="card_cvv">CVV</label>
                                <input type="text" id="card_cvv" name="card_cvv" class="form-control" placeholder="123" required>
                            </div>
                        </div>
                    `;
                    break;
                    
                default:
                    html = '';
            }
            
            paymentDetails.innerHTML = html;
        });
    });

    // Form validation before submission
    document.getElementById('checkoutForm').addEventListener('submit', function(e) {
        if (e.submitter && e.submitter.name === 'checkout') {
            // Validate required fields
            const requiredFields = [
                'fullname', 'email', 'shipping_address', 'phone', 'payment_method'
            ];
            
            let isValid = true;
            let missingFields = [];
            
            requiredFields.forEach(field => {
                const element = document.querySelector(`[name="${field}"]`);
                if (!element || !element.value.trim()) {
                    isValid = false;
                    missingFields.push(field.replace('_', ' '));
                    element.classList.add('error');
                } else {
                    element.classList.remove('error');
                }
            });
            
            // Validate payment method specific fields
            const paymentMethod = document.querySelector('input[name="payment_method"]:checked');
            if (!paymentMethod) {
                isValid = false;
                alert('Please select a payment method.');
                return false;
            }
            
            switch(paymentMethod.value) {
                case 'gcash':
                    if (!document.getElementById('gcash_number') || !document.getElementById('gcash_number').value.trim() ||
                        !document.getElementById('gcash_name') || !document.getElementById('gcash_name').value.trim()) {
                        isValid = false;
                        alert('Please provide your GCash number and account name.');
                    }
                    break;
                    
                case 'credit_card':
                    if (!document.getElementById('card_number') || !document.getElementById('card_number').value.trim() ||
                        !document.getElementById('card_name') || !document.getElementById('card_name').value.trim() ||
                        !document.getElementById('card_expiry') || !document.getElementById('card_expiry').value.trim() ||
                        !document.getElementById('card_cvv') || !document.getElementById('card_cvv').value.trim()) {
                        isValid = false;
                        alert('Please provide complete credit card information.');
                    }
                    break;
            }
            
            if (!isValid) {
                e.preventDefault();
                if (missingFields.length > 0) {
                    alert('Please fill in all required fields: ' + missingFields.join(', '));
                }
                return false;
            }
        }
    });

    // Highlight selected payment method
    document.querySelectorAll('.payment-method').forEach(method => {
        method.addEventListener('click', function() {
            document.querySelectorAll('.payment-method').forEach(m => {
                m.classList.remove('selected');
            });
            this.classList.add('selected');
        });
    });
</script>
</body>
</html>