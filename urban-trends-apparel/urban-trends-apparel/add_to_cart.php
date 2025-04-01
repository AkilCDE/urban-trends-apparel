<?php
session_start();

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
    die(json_encode(['success' => false, 'message' => 'Database connection failed']));
}

if (!isset($_SESSION['user_id'])) {
    die(json_encode(['success' => false, 'message' => 'Please login first']));
}

$productId = $_POST['product_id'] ?? 0;
$quantity = $_POST['quantity'] ?? 1;
$size = $_POST['size'] ?? null;

// Get product details
$stmt = $db->prepare("SELECT * FROM products WHERE id = ?");
$stmt->execute([$productId]);
$product = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$product) {
    die(json_encode(['success' => false, 'message' => 'Product not found']));
}

if ($product['stock'] < $quantity) {
    die(json_encode(['success' => false, 'message' => 'Not enough stock available']));
}

// Initialize cart if not exists
if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

// Add item to cart
$cartItem = [
    'product_id' => $productId,
    'quantity' => $quantity,
    'price' => $product['price'],
    'name' => $product['name'],
    'image' => $product['image'],
    'size' => $size
];

// Check if product already in cart
$found = false;
foreach ($_SESSION['cart'] as &$item) {
    if ($item['product_id'] == $productId && $item['size'] == $size) {
        $item['quantity'] += $quantity;
        $found = true;
        break;
    }
}

if (!$found) {
    $_SESSION['cart'][] = $cartItem;
}

// Return success response
echo json_encode([
    'success' => true,
    'message' => 'Product added to cart',
    'cart_count' => count($_SESSION['cart'])
]);