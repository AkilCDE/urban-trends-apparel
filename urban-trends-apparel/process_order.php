<?php
require_once 'Database/datab.php';
require_once 'includes/price_helper.php';

session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

try {
    $db->beginTransaction();
    
    // Get cart items
    $stmt = $db->prepare("
        SELECT c.*, p.name, p.price, pv.price_adjustment 
        FROM cart c 
        JOIN products p ON c.product_id = p.product_id 
        LEFT JOIN product_variations pv ON c.variation_id = pv.variation_id
        WHERE c.user_id = ?
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $cart_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get promo code if applied
    $promo_code = isset($_SESSION['promo_code']) ? $_SESSION['promo_code'] : null;
    $promo_id = null;
    $discount_percentage = 0;

    if ($promo_code) {
        // Validate promo code and get discount percentage
        $stmt = $db->prepare("SELECT promo_id, discount_percentage FROM promo_codes 
            WHERE code = ? AND is_active = TRUE 
            AND valid_from <= NOW() 
            AND (valid_until IS NULL OR valid_until >= NOW())");
        $stmt->execute([$promo_code]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result) {
            $promo_id = $result['promo_id'];
            $discount_percentage = $result['discount_percentage'];
        }
    }
    
    // Calculate totals
    $subtotal = 0;
    $total_discount = 0;
    $shipping_fee = 100;
    
    // Prepare statement for order items
    $stmt = $db->prepare("INSERT INTO order_items (order_id, product_id, variation_id, quantity, original_price, discounted_price) 
        VALUES (?, ?, ?, ?, ?, ?)");
    
    foreach ($cart_items as $item) {
        $item_base_price = $item['price'] + ($item['price_adjustment'] ?? 0);
        
        if (isset($_SESSION['active_promo'])) {
            $discounted_price = calculateDiscountedPrice($item_base_price);
            $item_total = $discounted_price * $item['quantity'];
            $item_discount = ($item_base_price - $discounted_price) * $item['quantity'];
            $total_discount += $item_discount;
        } else {
            $item_total = $item_base_price * $item['quantity'];
        }
        
        $subtotal += $item_total;
        
        $stmt->execute([
            $order_id,
            $item['product_id'],
            $item['variation_id'],
            $item['quantity'],
            $item_base_price,
            $item_total
        ]);
    }
    
    $stmt->closeCursor();
    
    // If promo code was applied, store the promotion
    if ($promo_id) {
        $stmt = $db->prepare("INSERT INTO order_promotions (order_id, promo_id, discount_amount) VALUES (?, ?, ?)");
        $stmt->execute([$order_id, $promo_id, $total_discount]);
        $stmt->closeCursor();
    }
    
    // Calculate final total and update order
    $total = $subtotal + $shipping_fee;
    $total_amount = $subtotal - $total_discount + $shipping_fee;
    
    // Create order
    $stmt = $db->prepare("
        INSERT INTO orders (
            user_id, total_amount, subtotal, shipping_fee, discount_amount,
            status, payment_method, order_date
        ) VALUES (?, ?, ?, ?, ?, 'pending', ?, NOW())
    ");
    $stmt->execute([
        $_SESSION['user_id'],
        $total_amount,
        $subtotal,
        $shipping_fee,
        $total_discount,
        $_POST['payment_method']
    ]);
    
    $order_id = $db->lastInsertId();
    
    // Clear cart
    $stmt = $db->prepare("DELETE FROM cart WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    
    $db->commit();
    
    // Redirect to order confirmation
    header("Location: order_confirmation.php?order_id=" . $order_id);
    exit;
    
} catch (Exception $e) {
    $db->rollBack();
    $_SESSION['error_message'] = "Error processing order: " . $e->getMessage();
    header("Location: checkout.php");
    exit;
} 