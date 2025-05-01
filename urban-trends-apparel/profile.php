<?php
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
    
    public function getCurrentUser() {
        if (!$this->isLoggedIn()) return null;
        $stmt = $this->db->prepare("SELECT * FROM users WHERE user_id = ?");
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
    
    public function getWalletBalance($user_id) {
        $stmt = $this->db->prepare("SELECT balance FROM user_wallet WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? $result['balance'] : 0;
    }
}

$auth = new Auth($db);

// Redirect if not logged in
if (!$auth->isLoggedIn()) {
    header("Location: login.php");
    exit;
}

$user = $auth->getCurrentUser();
$user['wallet_balance'] = $auth->getWalletBalance($user['user_id']);
$page_title = 'Profile';
$message = '';

if (isset($_GET['logout'])) {
    $auth->logout();
    header("Location: login.php");
    exit;
}

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
    $stmt = $db->prepare("SELECT p.*, 
                         (SELECT SUM(stock) FROM product_variations WHERE product_id = p.product_id) as total_variation_stock
                         FROM wishlist w 
                         JOIN products p ON w.product_id = p.product_id 
                         WHERE w.user_id = ?");
    $stmt->execute([$user_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getCartItems($db, $user_id) {
    $stmt = $db->prepare("SELECT c.*, p.name, p.price, p.image, pv.size, pv.price_adjustment 
                         FROM cart c 
                         JOIN products p ON c.product_id = p.product_id 
                         LEFT JOIN product_variations pv ON c.variation_id = pv.variation_id
                         WHERE c.user_id = ?");
    $stmt->execute([$user_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getOrderItems($db, $order_id) {
    $stmt = $db->prepare("SELECT oi.*, p.name, p.image, p.description, pv.size, pv.price_adjustment 
                         FROM order_items oi 
                         JOIN products p ON oi.product_id = p.product_id 
                         LEFT JOIN product_variations pv ON oi.variation_id = pv.variation_id
                         WHERE oi.order_id = ?");
    $stmt->execute([$order_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function isInWishlist($db, $user_id, $product_id) {
    $stmt = $db->prepare("SELECT COUNT(*) FROM wishlist WHERE user_id = ? AND product_id = ?");
    $stmt->execute([$user_id, $product_id]);
    return $stmt->fetchColumn() > 0;
}

function canReturnOrder($order) {
    // Only delivered orders can be returned
    if ($order['status'] !== 'delivered') {
        return false;
    }
    
    // Check if order was delivered within the last 30 days
    $delivered_date = strtotime($order['delivery_date'] ?? $order['order_date']);
    $thirty_days_ago = strtotime('-30 days');
    
    return $delivered_date >= $thirty_days_ago;
}

function processReturn($db, $order_id, $user_id) {
    try {
        // Begin transaction
        $db->beginTransaction();
        
        // 1. Check if the order exists and belongs to the user
        $stmt = $db->prepare("SELECT * FROM orders WHERE order_id = ? AND user_id = ?");
        $stmt->execute([$order_id, $user_id]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$order) {
            throw new Exception("Order not found or doesn't belong to you.");
        }
        
        // 2. Check if order is eligible for return (must be delivered)
        if ($order['status'] !== 'delivered') {
            throw new Exception("Only delivered orders can be returned.");
        }
        
        // 3. Update order status to 'return_requested'
        $stmt = $db->prepare("UPDATE orders SET status = 'return_requested' WHERE order_id = ?");
        $stmt->execute([$order_id]);
        
        // 4. Add to order status history
        $stmt = $db->prepare("INSERT INTO order_status_history (order_id, status, notes) VALUES (?, ?, ?)");
        $stmt->execute([$order_id, 'return_requested', 'Customer initiated return']);
        
        // 5. Update shipping status if shipping record exists
        $stmt = $db->prepare("UPDATE shipping SET status = 'return_requested' WHERE order_id = ?");
        $stmt->execute([$order_id]);
        
        // 6. Create a support ticket for the return request
        $stmt = $db->prepare("INSERT INTO support_tickets (user_id, order_id, subject, message, status) VALUES (?, ?, ?, ?, ?)");
        $subject = "Return Request for Order #" . $order_id;
        $message = "Customer has requested to return order #" . $order_id . ". Please review and process the return.";
        $stmt->execute([$user_id, $order_id, $subject, $message, 'open']);
        
        // Commit transaction
        $db->commit();
        
        return true;
    } catch (Exception $e) {
        $db->rollBack();
        return $e->getMessage();
    }
}

function processRefund($db, $order_id, $user_id) {
    try {
        // Begin transaction
        $db->beginTransaction();
        
        // 1. Check if the order exists, belongs to the user, and is eligible for refund
        $stmt = $db->prepare("SELECT o.*, p.payment_method, p.transaction_id 
                             FROM orders o 
                             JOIN payments p ON o.order_id = p.order_id 
                             WHERE o.order_id = ? AND o.user_id = ? AND o.status = 'returned'");
        $stmt->execute([$order_id, $user_id]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$order) {
            throw new Exception("Order not found, doesn't belong to you, or not eligible for refund.");
        }
        
        // 2. Calculate refund amount (total minus any discounts)
        $stmt = $db->prepare("SELECT SUM(discount_amount) as total_discount FROM order_promotions WHERE order_id = ?");
        $stmt->execute([$order_id]);
        $discount = $stmt->fetch(PDO::FETCH_ASSOC);
        $total_discount = $discount['total_discount'] ?? 0;
        $refund_amount = $order['total_amount'] - $total_discount;
        
        // 3. Process refund based on payment method
        if ($order['payment_method'] === 'wallet') {
            // Refund to wallet
            $stmt = $db->prepare("UPDATE user_wallet SET balance = balance + ? WHERE user_id = ?");
            $stmt->execute([$refund_amount, $user_id]);
        } elseif (in_array($order['payment_method'], ['credit_card', 'debit_card', 'paypal', 'gcash'])) {
            // For real payment methods, we would integrate with payment gateway API here
            // This is a simulation - in a real app, you'd call the payment provider's API
            $transaction_id = "RFND-" . uniqid();
        } else {
            // For COD, we can't refund automatically
            throw new Exception("Please contact customer service for refund as you paid via cash on delivery.");
        }
        
        // 4. Update order status to 'refunded'
        $stmt = $db->prepare("UPDATE orders SET status = 'refunded' WHERE order_id = ?");
        $stmt->execute([$order_id]);
        
        // 5. Update payment status
        $stmt = $db->prepare("UPDATE payments SET status = 'refunded', transaction_id = ? WHERE order_id = ?");
        $stmt->execute([$transaction_id ?? null, $order_id]);
        
        // 6. Add to order status history
        $stmt = $db->prepare("INSERT INTO order_status_history (order_id, status, notes) VALUES (?, ?, ?)");
        $notes = "Refund processed for ₱" . number_format($refund_amount, 2) . " via " . strtoupper($order['payment_method']);
        $stmt->execute([$order_id, 'refunded', $notes]);
        
        // 7. Update shipping status
        $stmt = $db->prepare("UPDATE shipping SET status = 'returned' WHERE order_id = ?");
        $stmt->execute([$order_id]);
        
        // 8. Update support ticket
        $stmt = $db->prepare("UPDATE support_tickets SET status = 'resolved' WHERE order_id = ?");
        $stmt->execute([$order_id]);
        
        // Commit transaction
        $db->commit();
        
        return true;
    } catch (Exception $e) {
        $db->rollBack();
        return $e->getMessage();
    }
}

function getOrderHistory($db, $user_id, $order_id = null) {
    $orders = [];
    
    // Base query to get orders
    $sql = "SELECT o.*, 
                   p.transaction_id, p.payment_method, p.status as payment_status,
                   s.tracking_number, s.carrier, s.shipping_method, s.status as shipping_status,
                   s.estimated_delivery, s.actual_delivery
            FROM orders o
            LEFT JOIN payments p ON o.order_id = p.order_id
            LEFT JOIN shipping s ON o.order_id = s.order_id
            WHERE o.user_id = ?";
    
    $params = [$user_id];
    
    if ($order_id) {
        $sql .= " AND o.order_id = ?";
        $params[] = $order_id;
    }
    
    $sql .= " ORDER BY o.order_date DESC";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get order items and status history for each order
    foreach ($orders as &$order) {
        // Get order items
        $stmt = $db->prepare("SELECT oi.*, p.name, p.image, p.description, pv.size, pv.price_adjustment 
                             FROM order_items oi 
                             JOIN products p ON oi.product_id = p.product_id 
                             LEFT JOIN product_variations pv ON oi.variation_id = pv.variation_id
                             WHERE oi.order_id = ?");
        $stmt->execute([$order['order_id']]);
        $order['items'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get status history
        $stmt = $db->prepare("SELECT * FROM order_status_history 
                             WHERE order_id = ? 
                             ORDER BY changed_at DESC");
        $stmt->execute([$order['order_id']]);
        $order['status_history'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get promotions if any
    
        
        // Get return information if applicable
        if (in_array($order['status'], ['return_requested', 'returned', 'refunded'])) {
            $stmt = $db->prepare("SELECT * FROM support_tickets WHERE order_id = ?");
            $stmt->execute([$order['order_id']]);
            $order['return_ticket'] = $stmt->fetch(PDO::FETCH_ASSOC);
        }
    }
    
    return $order_id ? ($orders[0] ?? null) : $orders;
}

// Get support tickets for the user
function getSupportTickets($db, $user_id) {
    $stmt = $db->prepare("SELECT t.*, 
                         COUNT(r.response_id) as response_count,
                         MAX(r.created_at) as last_response_date
                         FROM support_tickets t
                         LEFT JOIN ticket_responses r ON t.ticket_id = r.ticket_id
                         WHERE t.user_id = ?
                         GROUP BY t.ticket_id
                         ORDER BY t.created_at DESC");
    $stmt->execute([$user_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get ticket details with responses
function getTicketDetails($db, $ticket_id, $user_id) {
    // Get ticket info
    $stmt = $db->prepare("SELECT * FROM support_tickets WHERE ticket_id = ? AND user_id = ?");
    $stmt->execute([$ticket_id, $user_id]);
    $ticket = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$ticket) {
        return null;
    }
    
    // Get responses
    $stmt = $db->prepare("SELECT r.*, u.firstname, u.lastname, u.is_admin 
                         FROM ticket_responses r
                         JOIN users u ON r.user_id = u.user_id
                         WHERE r.ticket_id = ?
                         ORDER BY r.created_at ASC");
    $stmt->execute([$ticket_id]);
    $ticket['responses'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    return $ticket;
}

// Get product reviews for the user
function getUserReviews($db, $user_id) {
    $stmt = $db->prepare("SELECT r.*, p.name as product_name, p.image as product_image
                         FROM product_reviews r
                         JOIN products p ON r.product_id = p.product_id
                         WHERE r.user_id = ?
                         ORDER BY r.created_at DESC");
    $stmt->execute([$user_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get product details for review
function getProductForReview($db, $product_id, $user_id) {
    $stmt = $db->prepare("SELECT p.*, 
                         (SELECT o.order_id FROM orders o 
                          JOIN order_items oi ON o.order_id = oi.order_id 
                          WHERE o.user_id = ? AND oi.product_id = p.product_id 
                          AND o.status = 'delivered' LIMIT 1) as order_id
                         FROM products p
                         WHERE p.product_id = ?");
    $stmt->execute([$user_id, $product_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle wallet funding
    if (isset($_POST['add_funds'])) {
        $amount = floatval($_POST['fund_amount']);
        if ($amount >= 100) {
            try {
                $stmt = $db->prepare("
                    INSERT INTO user_wallet (user_id, balance) 
                    VALUES (?, ?)
                    ON DUPLICATE KEY UPDATE balance = balance + ?
                ");
                $stmt->execute([$user['user_id'], $amount, $amount]);
                
                $_SESSION['success_message'] = "Successfully added ₱" . number_format($amount, 2) . " to your wallet!";
                header("Location: profile.php#wallet");
                exit;
            } catch (Exception $e) {
                $message = display_error("Failed to add funds: " . $e->getMessage());
            }
        } else {
            $message = display_error("Minimum amount to add is ₱100");
        }
    }
    
    // Handle return requests
    if (isset($_POST['order_action']) && $_POST['order_action'] === 'return') {
        $order_id = intval($_POST['order_id']);
        $result = processReturn($db, $order_id, $user['user_id']);
        
        if ($result === true) {
            $_SESSION['success_message'] = "Return request submitted successfully. Our team will contact you soon.";
            header("Location: profile.php#returns");
            exit;
        } else {
            $message = display_error($result);
        }
    }
    
    // Handle refund requests (admin would typically handle this, but adding for completeness)
    if (isset($_POST['refund_action']) && $_POST['refund_action'] === 'process_refund') {
        $order_id = intval($_POST['order_id']);
        $result = processRefund($db, $order_id, $user['user_id']);
        
        if ($result === true) {
            $_SESSION['success_message'] = "Refund processed successfully.";
            header("Location: profile.php#returns");
            exit;
        } else {
            $message = display_error($result);
        }
    }
    
    // Handle profile updates
    if (isset($_POST['update_profile'])) {
        $firstname = sanitize($_POST['firstname']);
        $lastname = sanitize($_POST['lastname']);
        $phone = sanitize($_POST['phone']);
        $address = sanitize($_POST['address']);
        
        try {
            $stmt = $db->prepare("UPDATE users SET firstname = ?, lastname = ?, phone = ?, address = ? WHERE user_id = ?");
            $stmt->execute([$firstname, $lastname, $phone, $address, $user['user_id']]);
            
            $_SESSION['success_message'] = "Profile updated successfully!";
            header("Location: profile.php#edit-profile");
            exit;
        } catch (Exception $e) {
            $message = display_error("Failed to update profile: " . $e->getMessage());
        }
    }
    
    // Handle password changes
    if (isset($_POST['change_password'])) {
        $current_password = $_POST['current_password'];
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];
        
        // Verify current password
        $stmt = $db->prepare("SELECT password FROM users WHERE user_id = ?");
        $stmt->execute([$user['user_id']]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!password_verify($current_password, $result['password'])) {
            $message = display_error("Current password is incorrect.");
        } elseif ($new_password !== $confirm_password) {
            $message = display_error("New passwords do not match.");
        } elseif (strlen($new_password) < 8) {
            $message = display_error("Password must be at least 8 characters long.");
        } else {
            try {
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt = $db->prepare("UPDATE users SET password = ? WHERE user_id = ?");
                $stmt->execute([$hashed_password, $user['user_id']]);
                
                $_SESSION['success_message'] = "Password changed successfully!";
                header("Location: profile.php#change-password");
                exit;
            } catch (Exception $e) {
                $message = display_error("Failed to change password: " . $e->getMessage());
            }
        }
    }
    
    // Handle cart actions
    if (isset($_POST['cart_action'])) {
        $product_id = intval($_POST['product_id']);
        $variation_id = isset($_POST['variation_id']) ? intval($_POST['variation_id']) : null;
        
        try {
            if ($_POST['cart_action'] === 'remove') {
                $stmt = $db->prepare("DELETE FROM cart WHERE user_id = ? AND product_id = ? AND (variation_id = ? OR variation_id IS NULL)");
                $stmt->execute([$user['user_id'], $product_id, $variation_id]);
                
                $_SESSION['success_message'] = "Item removed from cart.";
                header("Location: profile.php#cart");
                exit;
            } elseif ($_POST['cart_action'] === 'update') {
                $quantity = intval($_POST['quantity']);
                if ($quantity > 0) {
                    $stmt = $db->prepare("UPDATE cart SET quantity = ? WHERE user_id = ? AND product_id = ? AND (variation_id = ? OR variation_id IS NULL)");
                    $stmt->execute([$quantity, $user['user_id'], $product_id, $variation_id]);
                    
                    $_SESSION['success_message'] = "Cart updated successfully!";
                    header("Location: profile.php#cart");
                    exit;
                } else {
                    $message = display_error("Quantity must be at least 1.");
                }
            }
        } catch (Exception $e) {
            $message = display_error("Failed to update cart: " . $e->getMessage());
        }
    }
    
    // Handle wishlist actions
    if (isset($_POST['wishlist_action'])) {
        $product_id = intval($_POST['product_id']);
        
        try {
            if ($_POST['wishlist_action'] === 'remove') {
                $stmt = $db->prepare("DELETE FROM wishlist WHERE user_id = ? AND product_id = ?");
                $stmt->execute([$user['user_id'], $product_id]);
                
                $_SESSION['success_message'] = "Item removed from wishlist.";
                header("Location: profile.php#wishlist");
                exit;
            } elseif ($_POST['wishlist_action'] === 'add') {
                if (!isInWishlist($db, $user['user_id'], $product_id)) {
                    $stmt = $db->prepare("INSERT INTO wishlist (user_id, product_id) VALUES (?, ?)");
                    $stmt->execute([$user['user_id'], $product_id]);
                    
                    $_SESSION['success_message'] = "Item added to wishlist!";
                    header("Location: profile.php#wishlist");
                    exit;
                } else {
                    $message = display_error("Item is already in your wishlist.");
                }
            }
        } catch (Exception $e) {
            $message = display_error("Failed to update wishlist: " . $e->getMessage());
        }
    }
    
    // Handle order cancellation
    if (isset($_POST['order_action']) && $_POST['order_action'] === 'cancel') {
        $order_id = intval($_POST['order_id']);
        
        try {
            // Begin transaction
            $db->beginTransaction();
            
            // Check if order belongs to user and can be cancelled
            $stmt = $db->prepare("SELECT * FROM orders WHERE order_id = ? AND user_id = ? AND status IN ('pending', 'processing')");
            $stmt->execute([$order_id, $user['user_id']]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$order) {
                throw new Exception("Order cannot be cancelled or doesn't belong to you.");
            }
            
            // Update order status
            $stmt = $db->prepare("UPDATE orders SET status = 'cancelled' WHERE order_id = ?");
            $stmt->execute([$order_id]);
            
            // Add to status history
            $stmt = $db->prepare("INSERT INTO order_status_history (order_id, status, notes) VALUES (?, ?, ?)");
            $stmt->execute([$order_id, 'cancelled', 'Customer cancelled order']);
            
            // Update payment status if payment exists
            $stmt = $db->prepare("UPDATE payments SET status = 'refunded' WHERE order_id = ?");
            $stmt->execute([$order_id]);
            
            // Refund to wallet if payment was from wallet
            if ($order['payment_method'] === 'wallet') {
                $stmt = $db->prepare("UPDATE user_wallet SET balance = balance + ? WHERE user_id = ?");
                $stmt->execute([$order['total_amount'], $user['user_id']]);
            }
            
            // Commit transaction
            $db->commit();
            
            $_SESSION['success_message'] = "Order #$order_id has been cancelled successfully.";
            header("Location: profile.php#orders");
            exit;
        } catch (Exception $e) {
            $db->rollBack();
            $message = display_error($e->getMessage());
        }
    }
    
    // Handle support ticket submission
    if (isset($_POST['submit_ticket'])) {
        $subject = sanitize($_POST['subject']);
        $message = sanitize($_POST['message']);
        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : null;
        
        try {
            $stmt = $db->prepare("INSERT INTO support_tickets (user_id, order_id, subject, message, status) VALUES (?, ?, ?, ?, 'open')");
            $stmt->execute([$user['user_id'], $order_id, $subject, $message]);
            
            $_SESSION['success_message'] = "Support ticket submitted successfully! We'll get back to you soon.";
            header("Location: profile.php#support");
            exit;
        } catch (Exception $e) {
            $message = display_error("Failed to submit support ticket: " . $e->getMessage());
        }
    }
    
    // Handle ticket response submission
    if (isset($_POST['submit_response'])) {
        $ticket_id = intval($_POST['ticket_id']);
        $response_message = sanitize($_POST['message']);
        
        try {
            // Verify ticket belongs to user
            $stmt = $db->prepare("SELECT ticket_id FROM support_tickets WHERE ticket_id = ? AND user_id = ?");
            $stmt->execute([$ticket_id, $user['user_id']]);
            if (!$stmt->fetch()) {
                throw new Exception("Ticket not found or doesn't belong to you.");
            }
            
            $stmt = $db->prepare("INSERT INTO ticket_responses (ticket_id, user_id, message, is_admin_response) VALUES (?, ?, ?, 0)");
            $stmt->execute([$ticket_id, $user['user_id'], $response_message]);
            
            // Update ticket status to in_progress if it was closed
            $stmt = $db->prepare("UPDATE support_tickets SET status = 'in_progress', updated_at = NOW() WHERE ticket_id = ?");
            $stmt->execute([$ticket_id]);
            
            $_SESSION['success_message'] = "Your response has been submitted.";
            header("Location: profile.php?ticket_id=$ticket_id#support");
            exit;
        } catch (Exception $e) {
            $message = display_error("Failed to submit response: " . $e->getMessage());
        }
    }
    
    // Handle product review submission
    if (isset($_POST['submit_review'])) {
        $product_id = intval($_POST['product_id']);
        $order_id = intval($_POST['order_id']);
        $rating = intval($_POST['rating']);
        $title = sanitize($_POST['title']);
        $review = sanitize($_POST['review']);
        
        try {
            // Verify user has purchased the product
            $stmt = $db->prepare("SELECT oi.order_item_id 
                                 FROM order_items oi
                                 JOIN orders o ON oi.order_id = o.order_id
                                 WHERE oi.order_id = ? AND oi.product_id = ? AND o.user_id = ? AND o.status = 'delivered'");
            $stmt->execute([$order_id, $product_id, $user['user_id']]);
            if (!$stmt->fetch()) {
                throw new Exception("You can only review products you've purchased.");
            }
            
            // Check if review already exists
            $stmt = $db->prepare("SELECT review_id FROM product_reviews WHERE order_id = ? AND product_id = ? AND user_id = ?");
            $stmt->execute([$order_id, $product_id, $user['user_id']]);
            if ($stmt->fetch()) {
                throw new Exception("You've already reviewed this product from this order.");
            }
            
            $stmt = $db->prepare("INSERT INTO product_reviews (product_id, user_id, order_id, rating, title, review, is_approved) 
                                 VALUES (?, ?, ?, ?, ?, ?, 0)");
            $stmt->execute([$product_id, $user['user_id'], $order_id, $rating, $title, $review]);
            
            $_SESSION['success_message'] = "Thank you for your review! It will be visible after approval.";
            header("Location: profile.php#reviews");
            exit;
        } catch (Exception $e) {
            $message = display_error("Failed to submit review: " . $e->getMessage());
        }
    }
}

// Get user's order history
$order_history = getOrderHistory($db, $user['user_id']);

// Get wishlist items
$wishlist = getWishlistItems($db, $user['user_id']);

// Get cart items
$cart = getCartItems($db, $user['user_id']);

// Get cart count
$cart_count = 0;
if ($auth->isLoggedIn()) {
    $stmt = $db->prepare("SELECT COUNT(*) FROM cart WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $cart_count = $stmt->fetchColumn();
}

// Get support tickets
$support_tickets = getSupportTickets($db, $user['user_id']);

// Get specific ticket if requested
$ticket_id = isset($_GET['ticket_id']) ? intval($_GET['ticket_id']) : null;
$current_ticket = $ticket_id ? getTicketDetails($db, $ticket_id, $user['user_id']) : null;

// Get user reviews
$user_reviews = getUserReviews($db, $user['user_id']);

// Get product for review if requested
$review_product_id = isset($_GET['review_product']) ? intval($_GET['review_product']) : null;
$review_product = $review_product_id ? getProductForReview($db, $review_product_id, $user['user_id']) : null;
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
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, #181818 0%, #232323 100%);
            color: var(--text-color);
            line-height: 1.6;
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
            color: white;
            font-size: 1.5rem;
            cursor: pointer;
            padding: 10px;
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

        nav a, .user-actions a {
            color: var(--accent-silver);
            background: rgba(255,255,255,0.01);
            border: 1px solid transparent;
            transition: var(--transition);
            text-decoration: none;
            font-weight: 500;
            padding: 0.5rem 1rem;
            border-radius: var(--border-radius);
            display: flex;
            align-items: center;
            gap: 8px;
            position: relative;
        }

        nav a:hover, .user-actions a:hover {
            color: var(--accent-gold);
            background: linear-gradient(90deg, rgba(255,215,0,0.08) 0%, rgba(192,192,192,0.08) 100%);
            border: 1px solid var(--accent-gold);
            box-shadow: 0 2px 12px 0 rgba(255,215,0,0.12);
        }

        nav a::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 50%;
            transform: translateX(-50%);
            width: 0;
            height: 2px;
            background: linear-gradient(90deg, var(--accent-gold), var(--accent-silver));
            transition: var(--transition);
        }

        nav a:hover::after {
            width: 70%;
        }

        .user-actions {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .user-actions a {
            color: white;
            text-decoration: none;
            padding: 0.5rem 1rem;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .user-actions a:hover {
            color: var(--accent-color);
        }

        .cart-count {
            position: relative;
        }

        .cart-count span {
            position: absolute;
            top: -8px;
            right: -8px;
            background: var(--accent-color);
            color: white;
            font-size: 0.75rem;
            padding: 2px 6px;
            border-radius: 50%;
        }

        @media (max-width: 768px) {
            .menu-toggle {
                display: block;
            }
            
            nav {
                position: fixed;
                top: var(--header-height);
                left: -100%;
                width: 100%;
                height: calc(100vh - var(--header-height));
                background: var(--primary-color);
                transition: var(--transition);
            }
            
            nav.active {
                left: 0;
            }
            
            nav ul {
                flex-direction: column;
                padding: 1rem;
            }
            
            nav li {
                height: auto;
            }
            
            .user-actions {
                margin-left: auto;
            }
        }

        /* Profile Content */
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
            background-color: rgba(146, 149, 238, 0.2);
            color: var(--accent-color);
            font-weight: 500;
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

        /* Wallet Section Styles */
        .wallet-balance {
            background: var(--secondary-color);
            padding: 1.5rem;
            border-radius: var(--border-radius);
            margin-bottom: 1.5rem;
            text-align: center;
            font-size: 1.2rem;
        }

        .wallet-balance strong {
            font-size: 1.5rem;
            color: var(--accent-color);
        }

        .wallet-transactions {
            background: var(--secondary-color);
            padding: 1.5rem;
            border-radius: var(--border-radius);
        }

        .wallet-transactions h4 {
            margin-bottom: 1rem;
            color: var(--accent-color);
        }

        /* Form Styles */
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

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: linear-gradient(90deg, var(--accent-gold) 60%, var(--accent-silver) 100%);
            color: #181818;
            border: none;
            padding: 0.8rem 1.5rem;
            border-radius: var(--border-radius);
            font-size: 1rem;
            font-weight: 700;
            cursor: pointer;
            transition: var(--transition);
            text-decoration: none;
            position: relative;
            overflow: hidden;
            box-shadow: 0 4px 24px 0 rgba(255,215,0,0.18);
            animation: shineBtn 2.5s infinite linear;
        }

        .btn::before {
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

        .btn:hover {
            transform: translateY(-2px);
        }

        .btn-outline {
            background: transparent;
            border: 1px solid var(--accent-gold);
            color: var(--accent-gold);
            box-shadow: none;
            animation: none;
        }

        .btn-outline:hover {
            background: linear-gradient(90deg, rgba(255,215,0,0.08) 0%, rgba(192,192,192,0.08) 100%);
            border: 1px solid var(--accent-gold);
            box-shadow: 0 2px 12px 0 rgba(255,215,0,0.12);
        }

        .btn-danger {
            background: linear-gradient(90deg, var(--error-color) 60%, #ff6666 100%);
            color: white;
            box-shadow: 0 4px 24px 0 rgba(255,51,51,0.18);
        }

        .btn-danger:hover {
            background: linear-gradient(90deg, #ff3333 60%, #ff4d4d 100%);
        }

        .btn-success {
            background: var(--success-color);
            color: white;
            border: none;
        }

        .btn-success:hover {
            background: #3aa8d1;
            transform: translateY(-2px);
        }

        .btn i {
            font-size: 1.1em;
        }

        .action-btn {
            padding: 0.5rem;
            font-size: 0.85rem;
            border-radius: var(--border-radius);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.25rem;
            transition: var(--transition);
            background: linear-gradient(90deg, var(--accent-gold) 60%, var(--accent-silver) 100%);
            color: #181818;
            font-weight: 700;
            box-shadow: 0 4px 24px 0 rgba(255,215,0,0.18);
            position: relative;
            overflow: hidden;
            animation: shineBtn 2.5s infinite linear;
        }

        .action-btn::before {
            content: '';
            position: absolute;
            top: 0; left: -75%;
            width: 50%; height: 100%;
            background: linear-gradient(120deg, rgba(255,255,255,0.18) 0%, rgba(255,255,255,0.01) 100%);
            transform: skewX(-20deg);
            animation: btnShine 2.5s infinite linear;
        }

        .action-btn:hover {
            transform: translateY(-2px);
        }

        .action-btn i {
            font-size: 0.9rem;
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
            background: linear-gradient(90deg, rgba(251, 252, 253, 0.2) 0%, rgba(192, 192, 192, 0.2) 100%);
            border: 1px solid var(--accent-gold);
            color: var(--accent-gold);
            border-radius: var(--border-radius);
            font-size: 1rem;
            font-weight: 500;
            cursor: pointer;
            transition: var(--transition);
            text-decoration: none;
        }

        .browse-products-btn:hover {
            background: linear-gradient(90deg, rgba(251, 252, 253, 0.3) 0%, rgba(192, 192, 192, 0.3) 100%);
            box-shadow: 0 2px 12px 0 rgba(251, 252, 253, 0.12);
            transform: translateY(-2px);
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
            background-color: rgba(107, 144, 255, 0.1);
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

        .status-return_requested {
            background-color: rgba(255, 193, 7, 0.2);
            color: #ffc107;
        }

        .status-returned {
            background-color: rgba(13, 110, 253, 0.2);
            color: #0d6efd;
        }

        .status-refunded {
            background-color: rgba(25, 135, 84, 0.2);
            color: #198754;
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

        /* Product Card in Wishlist */
        .product-card {
            background-color: var(--secondary-color);
            border-radius: var(--border-radius);
            overflow: hidden;
            box-shadow: var(--box-shadow);
            transition: var(--transition);
            position: relative;
        }

        .product-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 15px 30px rgba(0, 0, 0, 0.4);
        }

        .product-badge {
            position: absolute;
            top: 1rem;
            right: 1rem;
            background: var(--accent-gold);
            color: #181818;
            padding: 0.3rem 0.6rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
            z-index: 2;
        }

        .product-image-container {
            height: 200px;
            position: relative;
            overflow: hidden;
        }

        .product-image {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.5s ease;
        }

        .product-card:hover .product-image {
            transform: scale(1.05);
        }

        .product-info {
            padding: 1.5rem;
        }

        .product-name {
            font-size: 1.1rem;
            margin-bottom: 0.5rem;
            color: var(--text-color);
            font-weight: 500;
        }

        .product-price {
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--accent-gold);
            margin-bottom: 1rem;
        }

        .product-actions {
            display: grid;
            grid-template-columns: 1fr 1fr auto;
            gap: 0.5rem;
            margin-top: 0.5rem;
        }

        .buy-now, .add-to-cart {
            background: linear-gradient(90deg, var(--accent-gold) 60%, var(--accent-silver) 100%);
            color: #181818 !important;
            font-weight: 700;
            box-shadow: 0 4px 24px 0 rgba(255,215,0,0.18);
            position: relative;
            overflow: hidden;
            animation: shineBtn 2.5s infinite linear;
            border: none;
            padding: 0.5rem 1rem;
            font-size: 0.85rem;
            border-radius: var(--border-radius);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.25rem;
            transition: var(--transition);
            cursor: pointer;
            text-decoration: none;
            width: 100%;
        }

        .buy-now i, .add-to-cart i {
            font-size: 0.9rem;
            margin-right: 4px;
            color: #181818;
        }

        .buy-now span, .add-to-cart span {
            color: #181818;
            font-weight: 700;
            white-space: nowrap;
        }

        .buy-now::before, .add-to-cart::before {
            content: '';
            position: absolute;
            top: 0; left: -75%;
            width: 50%; height: 100%;
            background: linear-gradient(120deg, rgba(255,255,255,0.18) 0%, rgba(255,255,255,0.01) 100%);
            transform: skewX(-20deg);
            animation: btnShine 2.5s infinite linear;
        }

        .buy-now:hover, .add-to-cart:hover {
            transform: translateY(-2px);
        }

        .wishlist-btn {
            width: 32px;
            height: 32px;
            border-radius: var(--border-radius);
            background: rgba(255,255,255,0.08);
            color: #ff3333;
            transition: var(--transition);
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .wishlist-btn:hover, .wishlist-btn.active {
            background: linear-gradient(90deg, #ff3333, #ff6666);
            color: white;
            border: 1px solid #ff3333;
            box-shadow: 0 4px 24px 0 rgba(255,51,51,0.18);
        }

        .wishlist-btn i {
            font-size: 0.9rem;
            color: inherit;
        }

        @keyframes btnShine {
            0% { left: -75%; }
            100% { left: 120%; }
        }

        @keyframes shineBtn {
            0%, 100% { filter: brightness(1.1); }
            50% { filter: brightness(1.3); }
        }

        .buy-now {
            background-color: var(--accent-color);
            color: white;
        }

        .buy-now:hover {
            background-color: #ff5252;
        }

        .add-to-cart {
            background-color: rgba(255, 255, 255, 0.1);
            color: var(--text-color);
        }

        .add-to-cart:hover {
            background-color: rgba(255, 255, 255, 0.2);
        }

        .wishlist-btn {
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            background-color: rgba(255, 255, 255, 0.1);
            border: none;
            border-radius: 50%;
            cursor: pointer;
            color: var(--text-muted);
            transition: var(--transition);
            font-size: 1.1rem;
        }

        .wishlist-btn:hover, .wishlist-btn.active {
            color: var(--accent-color);
            background-color: rgba(255, 107, 107, 0.1);
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

        /* Return History Styles */
        .history-details {
            background-color: rgba(0, 0, 0, 0.1);
            border-radius: var(--border-radius);
            padding: 1rem;
            margin-top: 1rem;
        }

        .history-details ul {
            list-style-type: none;
            padding-left: 0;
        }

        .history-details li {
            padding: 0.5rem 0;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .history-details li:last-child {
            border-bottom: none;
        }

        /* Order Details Styles */
        .order-details {
            background-color: var(--secondary-color);
            border-radius: var(--border-radius);
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: var(--box-shadow);
        }
        
        .order-details-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }
        
        .order-detail-group {
            background-color: rgba(0, 0, 0, 0.2);
            padding: 1rem;
            border-radius: var(--border-radius);
        }
        
        .order-detail-group h4 {
            margin-bottom: 0.5rem;
            color: var(--accent-color);
            font-size: 1rem;
        }
        
        .order-items {
            margin-top: 2rem;
        }
        
        .order-item {
            display: flex;
            align-items: center;
            padding: 1rem;
            border-bottom: 1px solid #444;
            gap: 1.5rem;
        }
        
        .order-item:last-child {
            border-bottom: none;
        }
        
        .order-item-image {
            width: 80px;
            height: 80px;
            object-fit: cover;
            border-radius: var(--border-radius);
        }
        
        .order-item-details {
            flex: 1;
        }
        
        .order-item-price {
            font-weight: 600;
            color: var(--accent-color);
        }
        
        .timeline {
            position: relative;
            padding-left: 1.5rem;
            margin-top: 2rem;
        }
        
        .timeline::before {
            content: '';
            position: absolute;
            left: 7px;
            top: 0;
            bottom: 0;
            width: 2px;
            background-color: var(--accent-color);
        }
        
        .timeline-item {
            position: relative;
            padding-bottom: 1.5rem;
        }
        
        .timeline-item::before {
            content: '';
            position: absolute;
            left: -1.5rem;
            top: 5px;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background-color: var(--accent-color);
        }
        
        .timeline-date {
            font-size: 0.9rem;
            color: var(--text-muted);
        }
        
        .timeline-content {
            background-color: rgba(0, 0, 0, 0.2);
            padding: 1rem;
            border-radius: var(--border-radius);
            margin-top: 0.5rem;
        }
        
        .promo-badge {
            display: inline-block;
            padding: 0.3rem 0.6rem;
            background-color: rgba(75, 181, 67, 0.2);
            color: var(--success-color);
            border-radius: 20px;
            font-size: 0.8rem;
            margin-right: 0.5rem;
            margin-bottom: 0.5rem;
        }

        /* Return specific styles */
        .status-return_requested {
            background-color: rgba(255, 193, 7, 0.2);
            color: #ffc107;
        }

        .status-returned {
            background-color: rgba(13, 110, 253, 0.2);
            color: #0d6efd;
        }

        .status-refunded {
            background-color: rgba(25, 135, 84, 0.2);
            color: #198754;
        }

        .return-instructions {
            background-color: rgba(255, 255, 255, 0.05);
            border-left: 4px solid var(--accent-color);
            padding: 1rem;
            margin: 1rem 0;
            border-radius: var(--border-radius);
        }

        .return-instructions h5 {
            margin-top: 0;
            color: var(--accent-color);
        }

        .return-instructions ol {
            padding-left: 1.5rem;
        }

        .return-instructions li {
            margin-bottom: 0.5rem;
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
            
            .order-details-grid {
                grid-template-columns: 1fr;
            }
            
            .order-item {
                flex-direction: column;
                align-items: flex-start;
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
        /* Support Ticket Styles */
        .ticket-list {
            margin-top: 1.5rem;
        }
        
        .ticket-item {
            background-color: var(--secondary-color);
            border-radius: var(--border-radius);
            padding: 1.5rem;
            margin-bottom: 1rem;
            box-shadow: var(--box-shadow);
            transition: var(--transition);
        }
        
        .ticket-item:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.3);
        }
        
        .ticket-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }
        
        .ticket-subject {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--accent-color);
        }
        
        .ticket-meta {
            display: flex;
            gap: 1rem;
            color: var(--text-muted);
            font-size: 0.9rem;
        }
        
        .ticket-status {
            display: inline-block;
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
        }
        
        .status-open {
            background-color: rgba(255, 107, 107, 0.2);
            color: var(--accent-color);
        }
        
        .status-in_progress {
            background-color: rgba(0, 123, 255, 0.2);
            color: #4dabf7;
        }
        
        .status-resolved {
            background-color: rgba(40, 167, 69, 0.2);
            color: #40c057;
        }
        
        .status-closed {
            background-color: rgba(108, 117, 125, 0.2);
            color: #adb5bd;
        }
        
        .ticket-message {
            margin-bottom: 1rem;
            line-height: 1.6;
        }
        
        .ticket-order {
            font-size: 0.9rem;
            color: var(--text-muted);
            margin-bottom: 1rem;
        }
        
        .ticket-order a {
            color: var(--accent-color);
            text-decoration: none;
        }
        
        .ticket-order a:hover {
            text-decoration: underline;
        }
        
        .ticket-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.9rem;
            color: var(--text-muted);
        }
        
        .ticket-response-count {
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        /* Ticket Conversation Styles */
        .ticket-conversation {
            margin-top: 2rem;
        }
        
        .ticket-response {
            background-color: var(--secondary-color);
            border-radius: var(--border-radius);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            position: relative;
        }
        
        .response-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }
        
        .response-user {
            font-weight: 600;
            color: var(--accent-color);
        }
        
        .response-date {
            color: var(--text-muted);
            font-size: 0.9rem;
        }
        
        .response-message {
            line-height: 1.6;
        }
        
        .response-admin {
            border-left: 4px solid var(--accent-color);
        }
        
        .response-customer {
            border-left: 4px solid #4dabf7;
        }
        
        /* Review Styles */
        .review-list {
            margin-top: 1.5rem;
        }
        
        .review-item {
            background-color: var(--secondary-color);
            border-radius: var(--border-radius);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: var(--box-shadow);
        }
        
        .review-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }
        
        .review-product {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1rem;
        }
        
        .review-product-image {
            width: 60px;
            height: 60px;
            object-fit: cover;
            border-radius: var(--border-radius);
        }
        
        .review-product-name {
            font-weight: 600;
        }
        
        .review-rating {
            display: flex;
            align-items: center;
            gap: 5px;
            margin-bottom: 0.5rem;
        }
        
        .review-rating .stars {
            color: #ffc107;
        }
        
        .review-title {
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }
        
        .review-content {
            line-height: 1.6;
            margin-bottom: 1rem;
        }
        
        .review-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: var(--text-muted);
            font-size: 0.9rem;
        }
        
        .review-status {
            display: inline-block;
            padding: 0.3rem 0.6rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
        }
        
        .status-pending {
            background-color: rgba(255, 193, 7, 0.2);
            color: #ffc107;
        }
        
        .status-approved {
            background-color: rgba(40, 167, 69, 0.2);
            color: #40c057;
        }
        
        /* Review Form Styles */
        .review-form-container {
            background-color: var(--secondary-color);
            border-radius: var(--border-radius);
            padding: 2rem;
            margin-top: 2rem;
            box-shadow: var(--box-shadow);
        }
        
        .review-product-card {
            display: flex;
            align-items: center;
            gap: 1.5rem;
            margin-bottom: 1.5rem;
            padding-bottom: 1.5rem;
            border-bottom: 1px solid #444;
        }
        
        .review-product-image-large {
            width: 100px;
            height: 100px;
            object-fit: cover;
            border-radius: var(--border-radius);
        }
        
        .rating-input {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 1.5rem;
        }
        
        .rating-stars {
            display: flex;
            gap: 5px;
        }
        
        .rating-stars input {
            display: none;
        }
        
        .rating-stars label {
            font-size: 1.5rem;
            color: #ccc;
            cursor: pointer;
            transition: var(--transition);
        }
        
        .rating-stars input:checked ~ label {
            color: #ffc107;
        }
        
        .rating-stars label:hover,
        .rating-stars label:hover ~ label {
            color: #ffc107;
        }
        
        /* New section links in sidebar */
        .profile-sidebar li a i.fa-headset {
            color: #4dabf7;
        }
        
        .profile-sidebar li a i.fa-star {
            color: #ffc107;
        }

        /* Map View Styles */
        .order-map-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.8);
            z-index: 1000;
            justify-content: center;
            align-items: center;
        }

        .map-container {
            background: var(--primary-color);
            padding: 2rem;
            border-radius: var(--border-radius);
            width: 90%;
            max-width: 800px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: var(--box-shadow);
            border: 1px solid rgba(255, 255, 255, 0.1);
            color: var(--text-color);
        }

        .map-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .map-header h2 {
            color: var(--accent-color);
            font-size: 1.5rem;
            margin: 0;
        }

        .map-close {
            cursor: pointer;
            font-size: 24px;
            color: var(--text-muted);
            transition: var(--transition);
        }

        .map-close:hover {
            color: var(--accent-color);
        }

        #orderMap {
            width: 100%;
            height: 400px;
            border-radius: var(--border-radius);
            margin-bottom: 20px;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .tracking-info {
            background: rgba(255, 255, 255, 0.05);
            padding: 1.5rem;
            border-radius: var(--border-radius);
            margin-top: 1.5rem;
        }

        .tracking-info p {
            margin-bottom: 0.8rem;
            color: var(--text-muted);
        }

        .tracking-info strong {
            color: var(--text-color);
        }

        .view-tracking-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: var(--accent-color);
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: var(--border-radius);
            font-size: 0.9rem;
            cursor: pointer;
            transition: var(--transition);
            margin-top: 1rem;
        }

        .view-tracking-btn:hover {
            background:rgb(49, 48, 48);
            transform: translateY(-2px);
        }

        .view-tracking-btn i {
            font-size: 1.1em;
        }

        /* Responsive Map Styles */
        @media (max-width: 768px) {
            .map-container {
                width: 95%;
                padding: 1.5rem;
            }

            #orderMap {
                height: 300px;
            }
        }

        /* Add these styles */
        .profile-picture-section {
            text-align: center;
            margin-bottom: 30px;
        }

        .profile-picture-container {
            position: relative;
            width: 150px;
            height: 150px;
            margin: 0 auto 15px;
            border-radius: 50%;
            overflow: hidden;
            background: var(--secondary-color);
            border: 3px solid var(--accent-color);
        }

        .profile-picture {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .profile-picture-placeholder {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--secondary-color);
            color: var(--text-muted);
            font-size: 3rem;
        }

        .profile-picture-actions {
            display: flex;
            gap: 10px;
            justify-content: center;
            margin-top: 15px;
        }

        .profile-picture-btn {
            padding: 8px 15px;
            border: none;
            border-radius: var(--border-radius);
            background: var(--accent-color);
            color: white;
            cursor: pointer;
            transition: var(--transition);
            font-size: 0.9rem;
        }

        .profile-picture-btn:hover {
            background: #ff5252;
        }

        .profile-picture-btn i {
            margin-right: 5px;
        }

        #camera-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.8);
            z-index: 1000;
        }

        .camera-container {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: var(--primary-color);
            padding: 20px;
            border-radius: var(--border-radius);
            width: 90%;
            max-width: 500px;
        }

        #camera-preview {
            width: 100%;
            border-radius: var(--border-radius);
            margin-bottom: 15px;
        }

        .camera-actions {
            display: flex;
            gap: 10px;
            justify-content: center;
        }

        .camera-btn {
            padding: 10px 20px;
            border: none;
            border-radius: var(--border-radius);
            cursor: pointer;
            transition: var(--transition);
        }

        .camera-btn.capture {
            background: var(--accent-color);
            color: white;
        }

        .camera-btn.cancel {
            background: var(--secondary-color);
            color: var(--text-color);
        }

        .camera-btn:hover {
            opacity: 0.9;
        }

        #file-input {
            display: none;
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
                <a href="profile.php" title="Profile"><i class="fas fa-user"></i></a>
                    <?php if ($auth->isAdmin()): ?>
                    <a href="admin/dashboard.php" title="Admin"><i class="fas fa-cog"></i></a>
                    <?php endif; ?>
                <a href="?logout=1" title="Logout"><i class="fas fa-sign-out-alt"></i> logout</a>
                <?php else: ?>
                <a href="login.php" title="Login"><i class="fas fa-sign-in-alt"></i></a>
                <a href="register.php" title="Register"><i class="fas fa-user-plus"></i></a>
                <?php endif; ?>
            </div>
            <button class="menu-toggle" onclick="toggleMenu()">☰</button>
        </div>
    </header>

    <!-- Add this section after the profile header -->
    <div class="profile-picture-section">
        <div class="profile-picture-container">
            <?php if ($user['profile_picture']): ?>
                <img src="<?php echo htmlspecialchars($user['profile_picture']); ?>" alt="Profile Picture" class="profile-picture">
            <?php else: ?>
                <div class="profile-picture-placeholder">
                    <i class="fas fa-user"></i>
                </div>
            <?php endif; ?>
        </div>
        <div class="profile-picture-actions">
            <button class="profile-picture-btn" onclick="openCamera()">
                <i class="fas fa-camera"></i> Take Photo
            </button>
            <button class="profile-picture-btn" onclick="document.getElementById('file-input').click()">
                <i class="fas fa-upload"></i> Upload Photo
            </button>
        </div>
        <input type="file" id="file-input" accept="image/*" onchange="handleFileSelect(event)">
    </div>

    <!-- Add the camera modal -->
    <div id="camera-modal">
        <div class="camera-container">
            <video id="camera-preview" autoplay playsinline></video>
            <div class="camera-actions">
                <button class="camera-btn capture" onclick="capturePhoto()">
                    <i class="fas fa-camera"></i> Capture
                </button>
                <button class="camera-btn cancel" onclick="closeCamera()">
                    <i class="fas fa-times"></i> Cancel
                </button>
            </div>
        </div>
    </div>

    <div class="profile-container">
        <div class="profile-sidebar">
            <h2><i class="fas fa-user-circle"></i> Profile</h2>
            <ul>
                <li><a href="#edit-profile" class="active"><i class="fas fa-user-edit"></i> Edit profile</a></li>
                <li><a href="#change-password"><i class="fas fa-key"></i> Change Password</a></li>
                <li><a href="#wallet"><i class="fas fa-wallet"></i> My Wallet (₱<?php echo number_format($user['wallet_balance'], 2); ?>)</a></li>
                <li><a href="#cart"><i class="fas fa-shopping-cart"></i> My Cart (<?php echo $cart_count; ?>)</a></li>
                <li><a href="#orders"><i class="fas fa-clipboard-list"></i> My Orders</a></li>
                <li><a href="#wishlist"><i class="fas fa-heart"></i> Wishlist (<?php echo count($wishlist); ?>)</a></li>
                <li><a href="#returns"><i class="fas fa-exchange-alt"></i> Returns</a></li>
                <li><a href="#support"><i class="fas fa-headset"></i> Support Tickets (<?php echo count($support_tickets); ?>)</a></li>
                <li><a href="#reviews"><i class="fas fa-star"></i> My Reviews (<?php echo count($user_reviews); ?>)</a></li>
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
            
            if (isset($_SESSION['success_message'])) {
                echo '<div class="message message-success">
                    <i class="fas fa-check-circle"></i> ' . $_SESSION['success_message'] . '
                </div>';
                unset($_SESSION['success_message']);
            }
            
            if (isset($_SESSION['error_message'])) {
                echo '<div class="message message-error">
                    <i class="fas fa-exclamation-circle"></i> ' . $_SESSION['error_message'] . '
                </div>';
                unset($_SESSION['error_message']);
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
                        <label for="phone">Phone Number</label>
                        <input type="tel" id="phone" name="phone" value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="address">Address</label>
                        <textarea id="address" name="address" required><?php echo htmlspecialchars($user['address']); ?></textarea>
                    </div>
                    
                    <button type="submit" name="update_profile" class="btn"><i class="fas fa-save"></i> Save Changes</button>
                </form>
            </div>
            
            <!-- Change Password Section -->
            <div id="change-password" class="profile-section" style="display: none;">
                <h3><i class="fas fa-key"></i> Change Password</h3>
                <form method="POST">
                    <div class="form-group">
                        <label for="current_password">Current Password</label>
                        <input type="password" id="current_password" name="current_password" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="new_password">New Password</label>
                        <input type="password" id="new_password" name="new_password" required minlength="8">
                    </div>
                    
                    <div class="form-group">
                        <label for="confirm_password">Confirm Password</label>
                        <input type="password" id="confirm_password" name="confirm_password" required minlength="8">
                    </div>
                    
                    <button type="submit" name="change_password" class="btn"><i class="fas fa-key"></i> Change Password</button>
                </form>
            </div>
            
            <!-- Wallet Section -->
            <div id="wallet" class="profile-section" style="display: none;">
                <h3><i class="fas fa-wallet"></i> My Wallet</h3>
                <div class="wallet-balance">
                    <p>Current Balance: <strong>₱<?php echo number_format($user['wallet_balance'], 2); ?></strong></p>
                </div>
                
                <form method="POST">
                    <div class="form-group">
                        <label for="fund_amount">Add Funds to Wallet</label>
                        <input type="number" id="fund_amount" name="fund_amount" min="100" step="100" value="500" required>
                    </div>
                    
                    <button type="submit" name="add_funds" class="btn"><i class="fas fa-plus-circle"></i> Add Funds</button>
                </form>
                
                <div class="wallet-transactions" style="margin-top: 2rem;">
                    <h4>Transaction History</h4>
                    <p>Wallet transactions will appear here.</p>
                </div>
            </div>
            
            <!-- Cart Section -->
            <div id="cart" class="profile-section" style="display: none;">
                <h3><i class="fas fa-shopping-cart"></i> My Cart</h3>
                <?php if (empty($cart)): ?>
                    <p>Your cart is empty. <a href="shop.php">Continue shopping</a></p>
                <?php else: ?>
                    <table class="orders-table">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>Price</th>
                                <th>Quantity</th>
                                <th>Total</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $cart_total = 0;
                            foreach ($cart as $item): 
                                $price = $item['price'] + ($item['price_adjustment'] ?? 0);
                                $product_total = $price * $item['quantity'];
                                $cart_total += $product_total;
                            ?>
                                <tr>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 10px;">
                                            <img src="assets/images/products/<?php echo htmlspecialchars($item['image']); ?>" alt="<?php echo htmlspecialchars($item['name']); ?>" style="width: 50px; height: 50px; object-fit: cover;">
                                            <?php echo htmlspecialchars($item['name']); ?>
                                            <?php if (!empty($item['size'])): ?>
                                                <span style="font-size: 0.8rem; color: var(--text-muted);">(Size: <?php echo $item['size']; ?>)</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>₱<?php echo number_format($price, 2); ?></td>
                                    <td>
                                        <form method="POST" style="display: flex; align-items: center; gap: 5px;">
                                            <input type="hidden" name="product_id" value="<?php echo $item['product_id']; ?>">
                                            <input type="hidden" name="variation_id" value="<?php echo $item['variation_id'] ?? null; ?>">
                                            <input type="hidden" name="cart_action" value="update">
                                            <input type="number" name="quantity" value="<?php echo $item['quantity']; ?>" min="1" style="width: 60px; padding: 5px;">
                                            <button type="submit" class="btn" style="padding: 5px 10px;"><i class="fas fa-sync-alt"></i></button>
                                        </form>
                                    </td>
                                    <td>₱<?php echo number_format($product_total, 2); ?></td>
                                    <td>
                                        <form method="POST">
                                            <input type="hidden" name="product_id" value="<?php echo $item['product_id']; ?>">
                                            <input type="hidden" name="variation_id" value="<?php echo $item['variation_id'] ?? null; ?>">
                                            <input type="hidden" name="cart_action" value="remove">
                                            <button type="submit" class="btn btn-danger" style="padding: 5px 10px;"><i class="fas fa-trash-alt"></i></button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <tr>
                                <td colspan="3" style="text-align: right; font-weight: bold;">Total:</td>
                                <td style="font-weight: bold;">₱<?php echo number_format($cart_total, 2); ?></td>
                                <td></td>
                            </tr>
                        </tbody>
                    </table>
                    <div style="margin-top: 20px; text-align: right;">
                        <a href="shop.php" class="btn btn-outline"><i class="fas fa-arrow-left"></i> Continue Shopping</a>
                        <a href="checkout.php" class="btn"><i class="fas fa-credit-card"></i> Proceed to Checkout</a>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Orders Section -->
            <div id="orders" class="profile-section" style="display: none;">
                <h3><i class="fas fa-clipboard-list"></i> Order History</h3>
                <?php if (empty($order_history)): ?>
                    <p>You haven't placed any orders yet. <a href="shop.php">Start shopping</a></p>
                <?php else: ?>
                    <?php foreach ($order_history as $order): ?>
                        <div class="order-details">
                            <div class="order-details-grid">
                                <div class="order-detail-group">
                                    <h4><i class="fas fa-info-circle"></i> Order Information</h4>
                                    <p><strong>Order #:</strong> <?php echo $order['order_id']; ?></p>
                                    <p><strong>Date:</strong> <?php echo date('M d, Y h:i A', strtotime($order['order_date'])); ?></p>
                                    <p><strong>Status:</strong> 
                                        <span class="status-badge status-<?php echo $order['status']; ?>">
                                            <?php echo ucfirst($order['status']); ?>
                                        </span>
                                    </p>
                                    <?php if ($order['delivery_date']): ?>
                                        <p><strong>Delivered:</strong> <?php echo date('M d, Y', strtotime($order['delivery_date'])); ?></p>
                                    <?php elseif ($order['estimated_delivery']): ?>
                                        <p><strong>Estimated Delivery:</strong> <?php echo date('M d, Y', strtotime($order['estimated_delivery'])); ?></p>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="order-detail-group">
                                    <h4><i class="fas fa-truck"></i> Shipping Information</h4>
                                    <?php if (!empty($order['tracking_number'])): ?>
                                        <p><strong>Tracking #:</strong> <?php echo $order['tracking_number']; ?></p>
                                        <p><strong>Carrier:</strong> <?php echo $order['carrier'] ?? 'N/A'; ?></p>
                                        <p><strong>Status:</strong> 
                                            <span class="status-badge status-<?php echo $order['shipping_status'] ?? 'processing'; ?>">
                                                <?php echo ucfirst($order['shipping_status'] ?? 'Processing'); ?>
                                            </span>
                                        </p>
                                    <?php else: ?>
                                        <p>Shipping information will be available once your order is processed.</p>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="order-detail-group">
                                    <h4><i class="fas fa-credit-card"></i> Payment Information</h4>
                                    <p><strong>Method:</strong> <?php echo ucfirst(str_replace('_', ' ', $order['payment_method'] ?? 'N/A')); ?></p>
                                    <p><strong>Status:</strong> 
                                        <span class="status-badge status-<?php echo $order['payment_status'] ?? 'pending'; ?>">
                                            <?php echo ucfirst($order['payment_status'] ?? 'Pending'); ?>
                                        </span>
                                    </p>
                                    <?php if (!empty($order['transaction_id'])): ?>
                                        <p><strong>Transaction ID:</strong> <?php echo $order['transaction_id']; ?></p>
                                    <?php endif; ?>
                                </div>
                                 <?php if (isset($order['latitude']) && isset($order['longitude'])): ?>
        <button class="view-tracking-btn" onclick="showOrderMap(<?php 
            echo htmlspecialchars(json_encode([
                'orderId' => $order['order_id'],
                'lat' => $order['latitude'],
                'lng' => $order['longitude']
            ])); 
        ?>)">
            <i class="fas fa-map-marker-alt"></i> Track Order
        </button>
    <?php endif; ?>
                            </div>
                            
                           
                            
                            <div class="order-items">
                                <h4><i class="fas fa-box-open"></i> Order Items</h4>
                                <?php foreach ($order['items'] as $item): ?>
                                    <div class="order-item">
                                        <div class="order-item-image-wrapper">
                                            <img src="assets/images/products/<?php echo htmlspecialchars($item['image']); ?>" alt="<?php echo htmlspecialchars($item['name']); ?>" class="order-item-image">
                                        </div>
                                        <div class="order-item-details">
                                            <h5><?php echo htmlspecialchars($item['name']); ?></h5>
                                            <?php if (!empty($item['size'])): ?>
                                                <p style="color: var(--text-muted); font-size: 0.9rem;">Size: <?php echo $item['size']; ?></p>
                                            <?php endif; ?>
                                            <?php if (!empty($item['description'])): ?>
                                                <p style="color: var(--text-muted); font-size: 0.9rem;"><?php echo htmlspecialchars($item['description']); ?></p>
                                            <?php endif; ?>
                                        </div>
                                        <div style="text-align: right;">
                                            <?php 
                                            $price = $item['price'] + ($item['price_adjustment'] ?? 0);
                                            ?>
                                            <p>₱<?php echo number_format($price, 2); ?></p>
                                            <p>x<?php echo $item['quantity']; ?></p>
                                            <p class="order-item-price">₱<?php echo number_format($price * $item['quantity'], 2); ?></p>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                                
                                <div style="text-align: right; margin-top: 1rem;">
                                    <p><strong>Subtotal: ₱<?php echo number_format($order['total_amount'], 2); ?></strong></p>
                                    <?php if (!empty($order['promotions'])): ?>
                                        <?php 
                                        $total_discount = 0;
                                        foreach ($order['promotions'] as $promo) {
                                            $total_discount += $promo['discount_amount'];
                                        }
                                        ?>
                                        <p>Discount: -₱<?php echo number_format($total_discount, 2); ?></p>
                                        <p><strong>Total Paid: ₱<?php echo number_format($order['total_amount'] - $total_discount, 2); ?></strong></p>
                                    <?php else: ?>
                                        <p><strong>Total Paid: ₱<?php echo number_format($order['total_amount'], 2); ?></strong></p>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div class="timeline">
                                <h4><i class="fas fa-history"></i> Order Timeline</h4>
                                <?php foreach ($order['status_history'] as $history): ?>
                                    <div class="timeline-item">
                                        <div class="timeline-date">
                                            <?php echo date('M d, Y h:i A', strtotime($history['changed_at'])); ?>
                                        </div>
                                        <div class="timeline-content">
                                            <strong><?php echo ucfirst($history['status']); ?></strong>
                                            <?php if (!empty($history['notes'])): ?>
                                                <p><?php echo htmlspecialchars($history['notes']); ?></p>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            
                            <div style="margin-top: 1.5rem;">
                                <?php if ($order['status'] === 'pending' || $order['status'] === 'processing'): ?>
                                    <form method="POST" style="display: inline-block;">
                                        <input type="hidden" name="order_id" value="<?php echo $order['order_id']; ?>">
                                        <input type="hidden" name="order_action" value="cancel">
                                        <button type="submit" class="btn btn-danger"><i class="fas fa-times"></i> Cancel Order</button>
                                    </form>
                                <?php elseif (canReturnOrder($order)): ?>
                                    <form method="POST" style="display: inline-block; margin-left: 0.5rem;">
                                        <input type="hidden" name="order_id" value="<?php echo $order['order_id']; ?>">
                                        <input type="hidden" name="order_action" value="return">
                                        <button type="submit" class="btn btn-outline"><i class="fas fa-exchange-alt"></i> Request Return</button>
                                    </form>
                                <?php endif; ?>
                                
                                <!-- Add review button for delivered orders -->
                                <?php if ($order['status'] === 'delivered'): ?>
                                    <?php foreach ($order['items'] as $item): ?>
                                        <?php 
                                        // Check if review already exists for this product in this order
                                        $stmt = $db->prepare("SELECT review_id FROM product_reviews WHERE order_id = ? AND product_id = ? AND user_id = ?");
                                        $stmt->execute([$order['order_id'], $item['product_id'], $user['user_id']]);
                                        $has_review = $stmt->fetch();
                                        ?>
                                        <?php if (!$has_review): ?>
                                            <a href="profile.php?review_product=<?php echo $item['product_id']; ?>#reviews" class="btn" style="margin-left: 0.5rem;">
                                                <i class="fas fa-star"></i> Review <?php echo htmlspecialchars($item['name']); ?>
                                            </a>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            
            <!-- Wishlist Section -->
            <div id="wishlist" class="profile-section" style="display: none;">
                <h3><i class="fas fa-heart"></i> Wishlist</h3>
                <?php if (empty($wishlist)): ?>
                    <p>Your wishlist is empty. <a href="shop.php">Browse our products</a></p>
                <?php else: ?>
                    <div class="wishlist-items">
                        <?php foreach ($wishlist as $product): ?>
                            <div class="product-card">
                            <?php 
                            // Calculate available stock
                            $available_stock = $product['total_variation_stock'] ?? 0;
                            if ($available_stock > 0 && $available_stock < 10): ?>
                                <span class="product-badge">Only <?php echo $available_stock; ?> left</span>
                            <?php endif; ?>
                                <div class="product-image-container">
                                    <img src="assets/images/products/<?php echo htmlspecialchars($product['image']); ?>" alt="<?php echo htmlspecialchars($product['name']); ?>" class="product-image">
                                </div>
                                <div class="product-info">
                                    <h3 class="product-name"><?php echo htmlspecialchars($product['name']); ?></h3>
                                    <p class="product-price">₱<?php echo number_format($product['price'], 2); ?></p>
                                    <div class="product-actions">
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="product_id" value="<?php echo $product['product_id']; ?>">
                                            <button type="submit" name="buy_now" class="action-btn buy-now">
                                                <i class="fas fa-bolt"></i> Buy Now
                                            </button>
                                        </form>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="product_id" value="<?php echo $product['product_id']; ?>">
                                            <button type="submit" name="add_to_cart" class="action-btn add-to-cart">
                                                <i class="fas fa-cart-plus"></i> Add to Cart
                                            </button>
                                        </form>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="product_id" value="<?php echo $product['product_id']; ?>">
                                            <input type="hidden" name="wishlist_action" value="remove">
                                            <button type="submit" class="wishlist-btn active">
                                                <i class="fas fa-heart"></i>
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Returns Section -->
            <div id="returns" class="profile-section" style="display: none;">
                <h3><i class="fas fa-exchange-alt"></i> Returns & Refunds</h3>
                <?php 
                $return_orders = array_filter($order_history, function($order) {
                    return in_array($order['status'], ['return_requested', 'returned', 'refunded']);
                });
                
                if (empty($return_orders)): ?>
                    <p>You don't have any return requests.</p>
                <?php else: ?>
                    <?php foreach ($return_orders as $order): ?>
                        <div class="order-details">
                            <div class="order-details-grid">
                                <div class="order-detail-group">
                                    <h4><i class="fas fa-info-circle"></i> Return Information</h4>
                                    <p><strong>Order #:</strong> <?php echo $order['order_id']; ?></p>
                                    <p><strong>Date:</strong> <?php echo date('M d, Y', strtotime($order['order_date'])); ?></p>
                                    <p><strong>Status:</strong> 
                                        <span class="status-badge status-<?php echo $order['status']; ?>">
                                            <?php 
                                            $status_map = [
                                                'return_requested' => 'Return Requested',
                                                'returned' => 'Returned - Pending Refund',
                                                'refunded' => 'Refund Processed'
                                            ];
                                            echo $status_map[$order['status']] ?? ucfirst($order['status']); 
                                            ?>
                                        </span>
                                    </p>
                                    <?php if (!empty($order['return_ticket'])): ?>
                                        <p><strong>Ticket #:</strong> <?php echo $order['return_ticket']['ticket_id']; ?></p>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="order-detail-group">
                                    <h4><i class="fas fa-truck"></i> Return Shipping</h4>
                                    <?php if (!empty($order['tracking_number'])): ?>
                                        <p><strong>Tracking #:</strong> <?php echo $order['tracking_number']; ?></p>
                                        <p><strong>Carrier:</strong> <?php echo $order['carrier'] ?? 'N/A'; ?></p>
                                        <?php if ($order['status'] === 'return_requested'): ?>
                                            <p><em>Please ship your return within 7 days using this tracking number.</em></p>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <?php if ($order['status'] === 'return_requested'): ?>
                                            <p>Our team is preparing your return shipping label. You'll receive an email with instructions soon.</p>
                                        <?php else: ?>
                                            <p>Return shipping information will be provided after your return is approved.</p>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="order-detail-group">
                                    <h4><i class="fas fa-money-bill-wave"></i> Refund</h4>
                                    <?php if ($order['status'] === 'refunded'): ?>
                                        <p><strong>Status:</strong> <span class="status-badge status-refunded">Refund Completed</span></p>
                                        <p><strong>Amount:</strong> ₱<?php echo number_format($order['total_amount'], 2); ?></p>
                                        <p><strong>Method:</strong> <?php echo ucfirst(str_replace('_', ' ', $order['payment_method'])); ?></p>
                                        <?php if (!empty($order['transaction_id'])): ?>
                                            <p><strong>Reference:</strong> <?php echo $order['transaction_id']; ?></p>
                                        <?php endif; ?>
                                    <?php elseif ($order['status'] === 'returned'): ?>
                                        <p><strong>Estimated Refund:</strong> ₱<?php echo number_format($order['total_amount'], 2); ?></p>
                                        <p>Your refund will be processed within 3-5 business days after we receive your return.</p>
                                    <?php else: ?>
                                        <p><strong>Estimated Refund:</strong> ₱<?php echo number_format($order['total_amount'], 2); ?></p>
                                        <p>Refund will be processed after we receive and inspect your returned items.</p>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div class="order-items">
                                <h4><i class="fas fa-box-open"></i> Returned Items</h4>
                                <?php foreach ($order['items'] as $item): ?>
                                    <div class="order-item">
                                        <img src="assets/images/products/<?php echo htmlspecialchars($item['image']); ?>" alt="<?php echo htmlspecialchars($item['name']); ?>" class="order-item-image">
                                        <div class="order-item-details">
                                            <h5><?php echo htmlspecialchars($item['name']); ?></h5>
                                            <?php if (!empty($item['size'])): ?>
                                                <p style="color: var(--text-muted); font-size: 0.9rem;">Size: <?php echo $item['size']; ?></p>
                                            <?php endif; ?>
                                            <?php if (!empty($item['description'])): ?>
                                                <p style="color: var(--text-muted); font-size: 0.9rem;"><?php echo htmlspecialchars($item['description']); ?></p>
                                            <?php endif; ?>
                                        </div>
                                        <div style="text-align: right;">
                                            <?php 
                                            $price = $item['price'] + ($item['price_adjustment'] ?? 0);
                                            ?>
                                            <p>₱<?php echo number_format($price, 2); ?></p>
                                            <p>x<?php echo $item['quantity']; ?></p>
                                            <p class="order-item-price">₱<?php echo number_format($price * $item['quantity'], 2); ?></p>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            
                            <div class="timeline">
                                <h4><i class="fas fa-history"></i> Return Timeline</h4>
                                <?php foreach ($order['status_history'] as $history): ?>
                                    <?php if (in_array($history['status'], ['return_requested', 'returned', 'refunded'])): ?>
                                        <div class="timeline-item">
                                            <div class="timeline-date">
                                                <?php echo date('M d, Y h:i A', strtotime($history['changed_at'])); ?>
                                            </div>
                                            <div class="timeline-content">
                                                <strong>
                                                    <?php 
                                                    echo $status_map[$history['status']] ?? ucfirst($history['status']); 
                                                    ?>
                                                </strong>
                                                <?php if (!empty($history['notes'])): ?>
                                                    <p><?php echo htmlspecialchars($history['notes']); ?></p>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                            
                            <?php if ($order['status'] === 'returned' && $order['payment_method'] === 'wallet'): ?>
                                <div style="margin-top: 1.5rem;">
                                    <form method="POST">
                                        <input type="hidden" name="order_id" value="<?php echo $order['order_id']; ?>">
                                        <input type="hidden" name="refund_action" value="process_refund">
                                        <button type="submit" class="btn"><i class="fas fa-money-bill-wave"></i> Process Refund to Wallet</button>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
                
                <div class="return-instructions">
                    <h4><i class="fas fa-info-circle"></i> Return Policy</h4>
                    <p>Our return policy allows you to return items within 30 days of delivery for a full refund. Items must be in their original condition with all tags attached. Please contact our support team if you have any questions about returns.</p>
                    
                    <h5>How to Return an Item:</h5>
                    <ol>
                        <li>Click the "Request Return" button on your delivered order</li>
                        <li>Wait for our team to approve your return request (1-2 business days)</li>
                        <li>You'll receive a return shipping label via email</li>
                        <li>Pack the items securely in their original packaging</li>
                        <li>Attach the return label and drop off the package at any courier location</li>
                        <li>Once received and inspected, we'll process your refund within 3-5 business days</li>
                    </ol>
                    
                    <h5>Refund Methods:</h5>
                    <ul>
                        <li><strong>Credit/Debit Card:</strong> Refunded to original payment method (5-10 business days)</li>
                        <li><strong>E-wallets (GCash, Paypal):</strong> Refunded within 3 business days</li>
                        <li><strong>Wallet Balance:</strong> Refunded immediately to your account wallet</li>
                        <li><strong>Cash on Delivery:</strong> Bank transfer refund (provide details via support ticket)</li>
                    </ul>
                </div>
            </div>
            
            <!-- Support Tickets Section -->
            <div id="support" class="profile-section" style="display: none;">
                <h3><i class="fas fa-headset"></i> Support Tickets</h3>
                
                <?php if ($current_ticket): ?>
                    <!-- Ticket Conversation View -->
                    <div class="ticket-details">
                        <div class="ticket-header">
                            <h4 class="ticket-subject"><?php echo htmlspecialchars($current_ticket['subject']); ?></h4>
                            <span class="ticket-status status-<?php echo str_replace(' ', '_', strtolower($current_ticket['status'])); ?>">
                                <?php echo ucfirst(str_replace('_', ' ', $current_ticket['status'])); ?>
                            </span>
                        </div>
                        
                        <?php if ($current_ticket['order_id']): ?>
                            <div class="ticket-order">
                                Related to Order #<?php echo $current_ticket['order_id']; ?>
                                <a href="profile.php#orders">View Order</a>
                            </div>
                        <?php endif; ?>
                        
                        <div class="ticket-message">
                            <p><?php echo nl2br(htmlspecialchars($current_ticket['message'])); ?></p>
                        </div>
                        
                        <div class="ticket-footer">
                            <div>
                                Created: <?php echo date('M d, Y h:i A', strtotime($current_ticket['created_at'])); ?>
                            </div>
                            <div>
                                Last updated: <?php echo date('M d, Y h:i A', strtotime($current_ticket['updated_at'] ?? $current_ticket['created_at'])); ?>
                            </div>
                        </div>
                        
                        <div class="ticket-conversation">
                            <h4>Conversation</h4>
                            
                            <?php if (empty($current_ticket['responses'])): ?>
                                <p>No responses yet.</p>
                            <?php else: ?>
                                <?php foreach ($current_ticket['responses'] as $response): ?>
                                    <div class="ticket-response <?php echo $response['is_admin_response'] ? 'response-admin' : 'response-customer'; ?>">
                                        <div class="response-header">
                                            <div class="response-user">
                                                <?php echo htmlspecialchars($response['firstname'] . ' ' . htmlspecialchars($response['lastname'])); ?>
                                                <?php if ($response['is_admin_response']): ?>
                                                    <span style="color: var(--accent-color);">(Admin)</span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="response-date">
                                                <?php echo date('M d, Y h:i A', strtotime($response['created_at'])); ?>
                                            </div>
                                        </div>
                                        <div class="response-message">
                                            <?php echo nl2br(htmlspecialchars($response['message'])); ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            
                            <?php if ($current_ticket['status'] !== 'closed'): ?>
                                <form method="POST" class="response-form">
                                    <input type="hidden" name="ticket_id" value="<?php echo $current_ticket['ticket_id']; ?>">
                                    <div class="form-group">
                                        <label for="response_message">Your Response</label>
                                        <textarea id="response_message" name="message" rows="5" required></textarea>
                                    </div>
                                    <button type="submit" name="submit_response" class="btn">
                                        <i class="fas fa-paper-plane"></i> Send Response
                                    </button>
                                </form>
                            <?php else: ?>
                                <div class="message message-error">
                                    <i class="fas fa-info-circle"></i> This ticket is closed and cannot receive new responses.
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <a href="profile.php#support" class="btn btn-outline" style="margin-top: 1.5rem;">
                        <i class="fas fa-arrow-left"></i> Back to Tickets
                    </a>
                <?php else: ?>
                    <!-- Ticket List View -->
                    <a href="#new-ticket" class="btn" style="margin-bottom: 1.5rem;">
                        <i class="fas fa-plus"></i> Create New Ticket
                    </a>
                    
                    <?php if (empty($support_tickets)): ?>
                        <p>You don't have any support tickets yet.</p>
                    <?php else: ?>
                        <div class="ticket-list">
                            <?php foreach ($support_tickets as $ticket): ?>
                                <div class="ticket-item">
                                    <div class="ticket-header">
                                        <a href="profile.php?ticket_id=<?php echo $ticket['ticket_id']; ?>#support" class="ticket-subject">
                                            <?php echo htmlspecialchars($ticket['subject']); ?>
                                        </a>
                                        <span class="ticket-status status-<?php echo str_replace(' ', '_', strtolower($ticket['status'])); ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $ticket['status'])); ?>
                                        </span>
                                    </div>
                                    
                                    <?php if ($ticket['order_id']): ?>
                                        <div class="ticket-order">
                                            Related to Order #<?php echo $ticket['order_id']; ?>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <div class="ticket-message">
                                        <p><?php echo nl2br(htmlspecialchars(substr($ticket['message'], 0, 200) . (strlen($ticket['message']) > 200 ? '...' : ''))); ?></p>
                                    </div>
                                    
                                    <div class="ticket-footer">
                                        <div>
                                            Created: <?php echo date('M d, Y h:i A', strtotime($ticket['created_at'])); ?>
                                        </div>
                                        <div class="ticket-response-count">
                                            <i class="fas fa-comments"></i>
                                            <?php echo $ticket['response_count']; ?> response<?php echo $ticket['response_count'] != 1 ? 's' : ''; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    
                    <!-- New Ticket Form -->
                    <div id="new-ticket" class="profile-section" style="margin-top: 3rem; padding: 2rem; background-color: var(--secondary-color); border-radius: var(--border-radius);">
                        <h4><i class="fas fa-plus"></i> Create New Support Ticket</h4>
                        <form method="POST">
                            <div class="form-group">
                                <label for="ticket_subject">Subject</label>
                                <input type="text" id="ticket_subject" name="subject" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="ticket_order_id">Related Order (optional)</label>
                                <select id="ticket_order_id" name="order_id" class="form-control">
                                    <option value="">Select an order...</option>
                                    <?php foreach ($order_history as $order): ?>
                                        <option value="<?php echo $order['order_id']; ?>">Order #<?php echo $order['order_id']; ?> - <?php echo date('M d, Y', strtotime($order['order_date'])); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="ticket_message">Message</label>
                                <textarea id="ticket_message" name="message" rows="5" required></textarea>
                            </div>
                            
                            <button type="submit" name="submit_ticket" class="btn">
                                <i class="fas fa-paper-plane"></i> Submit Ticket
                            </button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Product Reviews Section -->
            <div id="reviews" class="profile-section" style="display: none;">
                <h3><i class="fas fa-star"></i> My Product Reviews</h3>
                
                <?php if ($review_product): ?>
                    <!-- Review Form -->
                    <div class="review-form-container">
                        <h4>Write a Review</h4>
                        
                        <div class="review-product-card">
                            <img src="assets/images/products/<?php echo htmlspecialchars($review_product['image']); ?>" alt="<?php echo htmlspecialchars($review_product['name']); ?>" class="review-product-image-large">
                            <div>
                                <h4><?php echo htmlspecialchars($review_product['name']); ?></h4>
                                <p>₱<?php echo number_format($review_product['price'], 2); ?></p>
                                <p>From Order #<?php echo $review_product['order_id']; ?></p>
                            </div>
                        </div>
                        
                        <form method="POST">
                            <input type="hidden" name="product_id" value="<?php echo $review_product['product_id']; ?>">
                            <input type="hidden" name="order_id" value="<?php echo $review_product['order_id']; ?>">
                            
                            <div class="form-group">
                                <label>Rating</label>
                                <div class="rating-input">
                                    <span>Your rating:</span>
                                    <div class="rating-stars">
                                        <input type="radio" id="star5" name="rating" value="5" required>
                                        <label for="star5"><i class="fas fa-star"></i></label>
                                        <input type="radio" id="star4" name="rating" value="4">
                                        <label for="star4"><i class="fas fa-star"></i></label>
                                        <input type="radio" id="star3" name="rating" value="3">
                                        <label for="star3"><i class="fas fa-star"></i></label>
                                        <input type="radio" id="star2" name="rating" value="2">
                                        <label for="star2"><i class="fas fa-star"></i></label>
                                        <input type="radio" id="star1" name="rating" value="1">
                                        <label for="star1"><i class="fas fa-star"></i></label>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label for="review_title">Title</label>
                                <input type="text" id="review_title" name="title" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="review_content">Your Review</label>
                                <textarea id="review_content" name="review" rows="5" required></textarea>
                            </div>
                            
                            <button type="submit" name="submit_review" class="btn">
                                <i class="fas fa-paper-plane"></i> Submit Review
                            </button>
                            
                            <a href="profile.php#reviews" class="btn btn-outline" style="margin-left: 1rem;">
                                <i class="fas fa-times"></i> Cancel
                            </a>
                        </form>
                    </div>
                <?php endif; ?>
                
                <?php if (empty($user_reviews) && !$review_product): ?>
                    <p>You haven't reviewed any products yet.</p>
                    <p>You can review products from your <a href="#orders">order history</a> after they've been delivered.</p>
                <?php else: ?>
                    <div class="review-list">
                        <?php foreach ($user_reviews as $review): ?>
                            <div class="review-item">
                                <div class="review-product">
                                    <img src="assets/images/products/<?php echo htmlspecialchars($review['product_image']); ?>" alt="<?php echo htmlspecialchars($review['product_name']); ?>" class="review-product-image">
                                    <div>
                                        <h4 class="review-product-name"><?php echo htmlspecialchars($review['product_name']); ?></h4>
                                        <p>From Order #<?php echo $review['order_id']; ?></p>
                                    </div>
                                </div>
                                
                                <div class="review-rating">
                                    <span>Rating:</span>
                                    <div class="stars">
                                        <?php for ($i = 1; $i <= 5; $i++): ?>
                                            <i class="fas fa-star<?php echo $i > $review['rating'] ? '-half-alt' : ''; ?>"></i>
                                        <?php endfor; ?>
                                    </div>
                                    <span><?php echo $review['rating']; ?> out of 5</span>
                                </div>
                                
                                <?php if (!empty($review['title'])): ?>
                                    <h5 class="review-title"><?php echo htmlspecialchars($review['title']); ?></h5>
                                <?php endif; ?>
                                
                                <div class="review-content">
                                    <p><?php echo nl2br(htmlspecialchars($review['review'])); ?></p>
                                </div>
                                
                                <div class="review-footer">
                                    <div>
                                        Reviewed on <?php echo date('M d, Y', strtotime($review['created_at'])); ?>
                                    </div>
                                    <div>
                                        <span class="review-status status-<?php echo $review['is_approved'] ? 'approved' : 'pending'; ?>">
                                            <?php echo $review['is_approved'] ? 'Approved' : 'Pending Approval'; ?>
                                        </span>
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
                    <li><a href="#orders"><i class="fas fa-chevron-right"></i> Order Tracking</a></li>
                    <li><a href="#returns"><i class="fas fa-chevron-right"></i> Returns & Refunds</a></li>
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
            &copy; <?php echo date('Y'); ?> Urban Trends Apparel. All rights reserved.
        </div>
    </footer>

    <script>
        // Function to show active section
        function showSection(sectionId) {
            // Hide all sections
            document.querySelectorAll('.profile-section').forEach(section => {
                section.style.display = 'none';
            });
            
            // Remove active class from all sidebar links
            document.querySelectorAll('.profile-sidebar a').forEach(link => {
                link.classList.remove('active');
            });
            
            // Show selected section
            const selectedSection = document.querySelector(sectionId);
            if (selectedSection) {
                selectedSection.style.display = 'block';
                // Add active class to corresponding sidebar link
                const correspondingLink = document.querySelector(`.profile-sidebar a[href="${sectionId}"]`);
                if (correspondingLink) {
                    correspondingLink.classList.add('active');
                }
                // Update URL hash without scrolling
                history.replaceState(null, null, sectionId);
            }
        }

        // Handle sidebar navigation
        document.querySelectorAll('.profile-sidebar a').forEach(link => {
            link.addEventListener('click', function(e) {
                e.preventDefault();
                const sectionId = this.getAttribute('href');
                showSection(sectionId);
            });
        });

        // Check URL hash on page load
        document.addEventListener('DOMContentLoaded', function() {
            // Get hash from URL or session storage
            let hash = window.location.hash;
            if (!hash) {
                hash = sessionStorage.getItem('lastSection') || '#edit-profile';
            }
            showSection(hash);
        });

        // Store current section in session storage when changed
        window.addEventListener('hashchange', function() {
            sessionStorage.setItem('lastSection', window.location.hash);
        });

        // Handle form submissions to maintain section
        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', function() {
                sessionStorage.setItem('lastSection', window.location.hash || '#edit-profile');
            });
        });

        // Mobile menu toggle
        function toggleMenu() {
            document.querySelector('nav').classList.toggle('active');
        }

        document.querySelector('.menu-toggle').addEventListener('click', function() {
            toggleMenu();
        });

        // Close menu when clicking outside
        document.addEventListener('click', function(e) {
            if (!e.target.closest('nav') && !e.target.closest('.menu-toggle')) {
                document.querySelector('nav').classList.remove('active');
            }
        });
    </script>
    <script src="https://maps.googleapis.com/maps/api/js?key=AIzaSyAgLyBmsBfFsoW7zzmIS8Aj-XRk2-mwYd8&callback=initMap" async defer></script>

    <!-- Add this before the closing body tag -->
    <div class="order-map-modal" id="mapModal">
        <div class="map-container">
            <div class="map-header">
                <h2><i class="fas fa-map-marker-alt"></i> Order Tracking</h2>
                <span class="map-close" onclick="closeMap()">&times;</span>
            </div>
            <div id="orderMap"></div>
            <div class="tracking-info" id="trackingInfo"></div>
        </div>
    </div>

    <!-- Add this in your orders section where you display orders -->
   

    <!-- Add this before the closing body tag -->
    <script src="https://maps.googleapis.com/maps/api/js?key=AIzaSyAgLyBmsBfFsoW7zzmIS8Aj-XRk2-mwYd8"></script>
    <script>
        let map;
        let marker;

        function showOrderMap(orderData) {
            const modal = document.getElementById('mapModal');
            modal.style.display = 'flex';

            // Fetch order tracking details
            fetch(`get_order_tracking.php?order_id=${orderData.orderId}`)
                .then(response => response.json())
                .then(data => {
                    const location = {
                        lat: parseFloat(data.lat),
                        lng: parseFloat(data.lng)
                    };

                    // Initialize map if not already initialized
                    if (!map) {
                        map = new google.maps.Map(document.getElementById('orderMap'), {
                            zoom: 15,
                            center: location,
                            styles: [
                                {
                                    featureType: "all",
                                    elementType: "geometry",
                                    stylers: [{ color: "#242f3e" }]
                                },
                                {
                                    featureType: "all",
                                    elementType: "labels.text.stroke",
                                    stylers: [{ color: "#242f3e" }]
                                },
                                {
                                    featureType: "all",
                                    elementType: "labels.text.fill",
                                    stylers: [{ color: "#746855" }]
                                }
                            ]
                        });
                    } else {
                        map.setCenter(location);
                    }

                    // Update or create marker
                    if (marker) {
                        marker.setPosition(location);
                    } else {
                        marker = new google.maps.Marker({
                            position: location,
                            map: map,
                            icon: {
                                path: google.maps.SymbolPath.CIRCLE,
                                scale: 10,
                                fillColor: "#ff6b6b",
                                fillOpacity: 1,
                                strokeWeight: 2,
                                strokeColor: "#ffffff"
                            }
                        });
                    }

                    // Update tracking info
                    const trackingInfo = document.getElementById('trackingInfo');
                    trackingInfo.innerHTML = `
                        <div class="status-badge status-${data.status.toLowerCase()}">
                            ${data.status}
                        </div>
                        <p><strong>Order ID:</strong> #${data.orderId}</p>
                        <p><strong>Tracking Number:</strong> ${data.tracking}</p>
                        <p><strong>Carrier:</strong> ${data.carrier}</p>
                        <p><strong>Shipping Method:</strong> ${data.shippingMethod}</p>
                        <p><strong>Estimated Delivery:</strong> ${data.estimatedDelivery}</p>
                        ${data.actualDelivery ? `<p><strong>Delivered On:</strong> ${data.actualDelivery}</p>` : ''}
                        <p><strong>Shipping Address:</strong> ${data.shippingAddress}</p>
                        ${data.locationNotes ? `<p><strong>Notes:</strong> ${data.locationNotes}</p>` : ''}
                        <p><strong>Order Date:</strong> ${data.orderDate}</p>
                        <p><strong>Total Amount:</strong> $${data.totalAmount}</p>
                    `;
                })
                .catch(error => {
                    console.error('Error:', error);
                    document.getElementById('trackingInfo').innerHTML = `
                        <div class="error-message">
                            Failed to load tracking information. Please try again later.
                        </div>
                    `;
                });
        }

        function closeMap() {
            document.getElementById('mapModal').style.display = 'none';
        }

        // Close modal when clicking outside
        document.getElementById('mapModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeMap();
            }
        });

        // Close modal with Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && document.getElementById('mapModal').style.display === 'flex') {
                closeMap();
            }
        });
    </script>
    <script>
        function trackOrder(trackingNumber, carrier) {
            // Show loading state
            const trackingInfo = document.getElementById('trackingInfo');
            trackingInfo.innerHTML = '<div class="loading">Loading tracking information...</div>';
            
            // Make API call to get tracking details
            fetch(`track_order.php?tracking_number=${trackingNumber}&carrier=${carrier}`)
                .then(response => response.json())
                .then(data => {
                    trackingInfo.innerHTML = `
                        <div class="tracking-details">
                            <p><strong>Tracking Number:</strong> ${data.trackingNumber}</p>
                            <p><strong>Carrier:</strong> ${data.carrier}</p>
                            <p><strong>Status:</strong> ${data.status}</p>
                            <p><strong>Last Update:</strong> ${data.lastUpdate}</p>
                            <p><strong>Location:</strong> ${data.location}</p>
                            ${data.estimatedDelivery ? `<p><strong>Estimated Delivery:</strong> ${data.estimatedDelivery}</p>` : ''}
                        </div>
                    `;
                })
                .catch(error => {
                    trackingInfo.innerHTML = `
                        <div class="error-message">
                            Failed to load tracking information. Please try again later.
                        </div>
                    `;
                });
        }
    </script>
    <script>
        // Add to cart functionality
        function addToCart(productId, variationId) {
            const quantity = document.getElementById('quantity').value;
            const formData = new FormData();
            formData.append('product_id', productId);
            formData.append('variation_id', variationId);
            formData.append('quantity', quantity);

            fetch('add_to_cart.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Product added to cart!');
                    location.reload();
                } else {
                    alert(data.message || 'Error adding product to cart');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error adding product to cart');
            });
        }

        // Add to wishlist functionality
        function addToWishlist(productId, variationId) {
            const formData = new FormData();
            formData.append('product_id', productId);
            formData.append('variation_id', variationId);

            fetch('add_to_wishlist.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Product added to wishlist!');
                    location.reload();
                } else {
                    alert(data.message || 'Error adding product to wishlist');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error adding product to wishlist');
            });
        }

        // Remove from wishlist functionality
        function removeFromWishlist(productId, variationId) {
            if (!confirm('Are you sure you want to remove this item from your wishlist?')) {
                return;
            }

            const formData = new FormData();
            formData.append('product_id', productId);
            formData.append('variation_id', variationId);

            fetch('remove_from_wishlist.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Product removed from wishlist!');
                    location.reload();
                } else {
                    alert(data.message || 'Error removing product from wishlist');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error removing product from wishlist');
            });
        }

        // Cancel order functionality
        function cancelOrder(orderId) {
            if (!confirm('Are you sure you want to cancel this order? This action cannot be undone.')) {
                return;
            }

            const formData = new FormData();
            formData.append('order_id', orderId);

            fetch('cancel_order.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Order cancelled successfully!');
                    location.reload();
                } else {
                    alert(data.message || 'Error cancelling order');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error cancelling order');
            });
        }

        // Submit support ticket functionality
        function submitSupportTicket() {
            const subject = document.getElementById('ticket_subject').value;
            const message = document.getElementById('ticket_message').value;

            if (!subject || !message) {
                alert('Please fill in all fields');
                return;
            }

            const formData = new FormData();
            formData.append('subject', subject);
            formData.append('message', message);

            fetch('submit_support_ticket.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Support ticket submitted successfully!');
                    location.reload();
                } else {
                    alert(data.message || 'Error submitting support ticket');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error submitting support ticket');
            });
        }

        // Submit support response functionality
        function submitSupportResponse(ticketId) {
            const message = document.getElementById('response_message_' + ticketId).value;

            if (!message) {
                alert('Please enter your response');
                return;
            }

            const formData = new FormData();
            formData.append('ticket_id', ticketId);
            formData.append('message', message);

            fetch('submit_support_response.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Response submitted successfully!');
                    location.reload();
                } else {
                    alert(data.message || 'Error submitting response');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error submitting response');
            });
        }

        // Submit review functionality
        function submitReview(productId, variationId) {
            const rating = document.getElementById('rating_' + productId + '_' + variationId).value;
            const comment = document.getElementById('comment_' + productId + '_' + variationId).value;

            if (!rating) {
                alert('Please select a rating');
                return;
            }

            const formData = new FormData();
            formData.append('product_id', productId);
            formData.append('variation_id', variationId);
            formData.append('rating', rating);
            formData.append('comment', comment);

            fetch('submit_review.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Review submitted successfully!');
                    location.reload();
                } else {
                    alert(data.message || 'Error submitting review');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error submitting review');
            });
        }

        // Fund wallet functionality
        function fundWallet() {
            const amount = document.getElementById('fund_amount').value;

            if (!amount || amount <= 0) {
                alert('Please enter a valid amount');
                return;
            }

            const formData = new FormData();
            formData.append('amount', amount);

            fetch('fund_wallet.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Wallet funded successfully!');
                    location.reload();
                } else {
                    alert(data.message || 'Error funding wallet');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error funding wallet');
            });
        }

        // Update profile functionality
        function updateProfile() {
            const formData = new FormData(document.getElementById('profile_form'));

            fetch('update_profile.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Profile updated successfully!');
                    location.reload();
                } else {
                    alert(data.message || 'Error updating profile');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error updating profile');
            });
        }

        // Change password functionality
        function changePassword() {
            const currentPassword = document.getElementById('current_password').value;
            const newPassword = document.getElementById('new_password').value;
            const confirmPassword = document.getElementById('confirm_password').value;

            if (!currentPassword || !newPassword || !confirmPassword) {
                alert('Please fill in all fields');
                return;
            }

            if (newPassword !== confirmPassword) {
                alert('New passwords do not match');
                return;
            }

            const formData = new FormData();
            formData.append('current_password', currentPassword);
            formData.append('new_password', newPassword);
            formData.append('confirm_password', confirmPassword);

            fetch('change_password.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Password changed successfully!');
                    location.reload();
                } else {
                    alert(data.message || 'Error changing password');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error changing password');
            });
        }

        // Update cart quantity functionality
        function updateCartQuantity(cartId) {
            const quantity = document.getElementById('cart_quantity_' + cartId).value;

            if (!quantity || quantity < 1) {
                alert('Please enter a valid quantity');
                return;
            }

            const formData = new FormData();
            formData.append('cart_id', cartId);
            formData.append('quantity', quantity);

            fetch('update_cart.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert(data.message || 'Error updating cart');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error updating cart');
            });
        }

        // Remove from cart functionality
        function removeFromCart(cartId) {
            if (!confirm('Are you sure you want to remove this item from your cart?')) {
                return;
            }

            const formData = new FormData();
            formData.append('cart_id', cartId);

            fetch('remove_from_cart.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert(data.message || 'Error removing item from cart');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error removing item from cart');
            });
        }

        // Apply promotion code functionality
        function applyPromotion() {
            const code = document.getElementById('promotion_code').value;

            if (!code) {
                alert('Please enter a promotion code');
                return;
            }

            const formData = new FormData();
            formData.append('code', code);

            fetch('apply_promotion.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert(data.message || 'Error applying promotion code');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error applying promotion code');
            });
        }

        // Place order functionality
        function placeOrder() {
            if (!confirm('Are you sure you want to place this order?')) {
                return;
            }

            fetch('place_order.php', {
                method: 'POST'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Order placed successfully!');
                    window.location.href = 'profile.php';
                } else {
                    alert(data.message || 'Error placing order');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error placing order');
            });
        }
    </script>
    <script>
    // Add this JavaScript code
    let stream = null;

    async function openCamera() {
        const modal = document.getElementById('camera-modal');
        const video = document.getElementById('camera-preview');
        
        try {
            stream = await navigator.mediaDevices.getUserMedia({ 
                video: { 
                    facingMode: 'user',
                    width: { ideal: 1280 },
                    height: { ideal: 720 }
                } 
            });
            video.srcObject = stream;
            modal.style.display = 'block';
        } catch (err) {
            console.error('Error accessing camera:', err);
            alert('Unable to access camera. Please make sure you have granted camera permissions.');
        }
    }

    function closeCamera() {
        const modal = document.getElementById('camera-modal');
        const video = document.getElementById('camera-preview');
        
        if (stream) {
            stream.getTracks().forEach(track => track.stop());
        }
        video.srcObject = null;
        modal.style.display = 'none';
    }

    function capturePhoto() {
        const video = document.getElementById('camera-preview');
        const canvas = document.createElement('canvas');
        canvas.width = video.videoWidth;
        canvas.height = video.videoHeight;
        
        const context = canvas.getContext('2d');
        context.drawImage(video, 0, 0, canvas.width, canvas.height);
        
        // Convert to blob and upload
        canvas.toBlob(blob => {
            const formData = new FormData();
            formData.append('profile_picture', blob, 'profile.jpg');
            uploadProfilePicture(formData);
        }, 'image/jpeg', 0.95);
        
        closeCamera();
    }

    async function handleFileSelect(event) {
        const file = event.target.files[0];
        if (!file) return;
        
        const formData = new FormData();
        formData.append('profile_picture', file);
        uploadProfilePicture(formData);
    }

    async function uploadProfilePicture(formData) {
        try {
            const response = await fetch('upload_profile_picture.php', {
                method: 'POST',
                body: formData
            });
            
            const result = await response.json();
            
            if (result.success) {
                // Update profile picture display
                const container = document.querySelector('.profile-picture-container');
                container.innerHTML = `<img src="${result.url}" alt="Profile Picture" class="profile-picture">`;
                
                // Show success message
                alert('Profile picture updated successfully!');
            } else {
                throw new Error(result.message || 'Failed to upload profile picture');
            }
        } catch (error) {
            console.error('Error uploading profile picture:', error);
            alert('Failed to upload profile picture. Please try again.');
        }
    }
    </script>
    <script>
        // Mobile menu toggle
        function toggleMenu() {
            document.querySelector('nav').classList.toggle('active');
        }

        document.querySelector('.menu-toggle').addEventListener('click', function() {
            toggleMenu();
        });

        // Close menu when clicking outside
        document.addEventListener('click', function(e) {
            if (!e.target.closest('nav') && !e.target.closest('.menu-toggle')) {
                document.querySelector('nav').classList.remove('active');
            }
        });
        
        // ... rest of existing JavaScript ...
    </script>
</body>
</html>