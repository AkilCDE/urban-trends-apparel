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
            return [
                'id' => $_SESSION['user_id'],
                'email' => $_SESSION['user_email'],
                'firstname' => $_SESSION['user_firstname'],
                'lastname' => $_SESSION['user_lastname'],
                'address' => $_SESSION['user_address']
            ];
        }
        return null;
    }
}

$auth = new Auth($db);

// Handle logout
if (isset($_GET['logout'])) {
    $auth->logout();
    header("Location: login.php");
    exit;
}

// Handle Add to Cart and Buy Now actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Require login for cart and wishlist actions
    if (!$auth->isLoggedIn()) {
        $_SESSION['error_message'] = "Please login to add items to cart or wishlist";
        header("Location: login.php");
        exit;
    }

    if (isset($_POST['add_to_cart']) || isset($_POST['buy_now'])) {
        $product_id = $_POST['product_id'];
        $quantity = isset($_POST['quantity']) ? max(1, (int)$_POST['quantity']) : 0;
        $variation_id = isset($_POST['variation_id']) ? (int)$_POST['variation_id'] : null;
        
        if ($quantity <= 0) {
            $_SESSION['error_message'] = "Please select a valid quantity";
            header("Location: shop.php" . (isset($_GET['category']) ? '?category='.$_GET['category'] : ''));
            exit;
        }

        try {
            // Check if product has variations
            $stmt = $db->prepare("SELECT COUNT(*) FROM product_variations WHERE product_id = ?");
            $stmt->execute([$product_id]);
            $has_variations = $stmt->fetchColumn() > 0;

            // Get product category
            $stmt = $db->prepare("SELECT category FROM products WHERE product_id = ?");
            $stmt->execute([$product_id]);
            $product_category = $stmt->fetchColumn();

            // For accessories, we don't require size selection
            if ($has_variations && !$variation_id && strpos($product_category, 'access_') !== 0) {
                $_SESSION['error_message'] = "Please select a size";
                header("Location: shop.php" . (isset($_GET['category']) ? '?category='.$_GET['category'] : ''));
                exit;
            }

            if ($variation_id) {
                // Check variation stock
                $stmt = $db->prepare("SELECT stock FROM product_variations WHERE variation_id = ?");
                $stmt->execute([$variation_id]);
                $variation = $stmt->fetch();
                
                if (!$variation || $variation['stock'] <= 0) {
                    $_SESSION['error_message'] = "This product variation is out of stock";
                    header("Location: shop.php" . (isset($_GET['category']) ? '?category='.$_GET['category'] : ''));
                    exit;
                }
                
                if ($quantity > $variation['stock']) {
                    $_SESSION['error_message'] = "Only {$variation['stock']} items available in stock for this size";
                    header("Location: shop.php" . (isset($_GET['category']) ? '?category='.$_GET['category'] : ''));
                    exit;
                }
            } else {
                // Check product stock (if no variations)
                $stmt = $db->prepare("SELECT SUM(stock) as total_stock FROM product_variations WHERE product_id = ?");
                $stmt->execute([$product_id]);
                $stock = $stmt->fetch();
                
                if (!$stock || $stock['total_stock'] <= 0) {
                    $_SESSION['error_message'] = "This product is out of stock";
                    header("Location: shop.php" . (isset($_GET['category']) ? '?category='.$_GET['category'] : ''));
                    exit;
                }
            }
            
            if (isset($_POST['add_to_cart'])) {
                // Check if product already in cart with same variation
                $stmt = $db->prepare("SELECT * FROM cart WHERE user_id = ? AND product_id = ? AND variation_id " . 
                                    ($variation_id ? "= ?" : "IS NULL"));
                $params = [$_SESSION['user_id'], $product_id];
                if ($variation_id) $params[] = $variation_id;
                $stmt->execute($params);
                $existing_item = $stmt->fetch();
                
                if ($existing_item) {
                    // Update quantity if already in cart
                    $new_quantity = $existing_item['quantity'] + $quantity;
                    $max_stock = $variation_id ? $variation['stock'] : $stock['total_stock'];
                    
                    if ($new_quantity > $max_stock) {
                        $_SESSION['error_message'] = "You can't add more than available stock";
                        header("Location: shop.php" . (isset($_GET['category']) ? '?category='.$_GET['category'] : ''));
                        exit;
                    }
                    
                    $stmt = $db->prepare("UPDATE cart SET quantity = quantity + ? WHERE cart_id = ?");
                    $stmt->execute([$quantity, $existing_item['cart_id']]);
                } else {
                    // Add new item to cart
                    $stmt = $db->prepare("INSERT INTO cart (user_id, product_id, variation_id, quantity) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$_SESSION['user_id'], $product_id, $variation_id, $quantity]);
                }
                
                $_SESSION['success_message'] = "Product added to cart successfully!";
            }
            
            if (isset($_POST['buy_now'])) {
                // Clear current cart (optional, depends on your business logic)
                $stmt = $db->prepare("DELETE FROM cart WHERE user_id = ?");
                $stmt->execute([$_SESSION['user_id']]);
                
                // Add the selected product to cart
                $stmt = $db->prepare("INSERT INTO cart (user_id, product_id, variation_id, quantity) VALUES (?, ?, ?, ?)");
                $stmt->execute([$_SESSION['user_id'], $product_id, $variation_id, $quantity]);
                
                header("Location: checkout.php");
                exit;
            }
            
            header("Location: shop.php" . (isset($_GET['category']) ? '?category='.$_GET['category'] : ''));
            exit;
        } catch (PDOException $e) {
            $_SESSION['error_message'] = "Error processing your request: " . $e->getMessage();
            header("Location: shop.php" . (isset($_GET['category']) ? '?category='.$_GET['category'] : ''));
            exit;
        }
    }

    // Handle Wishlist Actions
    if (isset($_POST['wishlist_action'])) {
        $product_id = $_POST['product_id'];
        $action = $_POST['wishlist_action'];
        
        if ($action === 'add') {
            try {
                // Check if product is already in wishlist
                $stmt = $db->prepare("SELECT COUNT(*) FROM wishlist WHERE user_id = ? AND product_id = ?");
                $stmt->execute([$_SESSION['user_id'], $product_id]);
                $exists = $stmt->fetchColumn();
                
                if (!$exists) {
                    $stmt = $db->prepare("INSERT INTO wishlist (user_id, product_id) VALUES (?, ?)");
                    $stmt->execute([$_SESSION['user_id'], $product_id]);
                    $_SESSION['success_message'] = "Product added to wishlist!";
                } else {
                    $_SESSION['info_message'] = "Product is already in your wishlist";
                }
            } catch (PDOException $e) {
                $_SESSION['error_message'] = "Error adding to wishlist: " . $e->getMessage();
            }
        } elseif ($action === 'remove') {
            try {
                $stmt = $db->prepare("DELETE FROM wishlist WHERE user_id = ? AND product_id = ?");
                $stmt->execute([$_SESSION['user_id'], $product_id]);
                $_SESSION['success_message'] = "Product removed from wishlist!";
            } catch (PDOException $e) {
                $_SESSION['error_message'] = "Error removing from wishlist: " . $e->getMessage();
            }
        }
        
        // Redirect back to the same page with category parameter if exists
        $redirect_url = 'shop.php';
        if (isset($_GET['category'])) {
            $redirect_url .= '?category=' . $_GET['category'];
        }
        header("Location: $redirect_url");
        exit;
    }
}

// Get products with category filtering and stock check
$category = isset($_GET['category']) ? $_GET['category'] : null;

$category_mapping = [
    'men' => 'men',
    'women' => 'women', 
    'shoes' => 'shoes',
    'accessories' => 'access' 
];

$db_category = isset($category_mapping[$category]) ? $category_mapping[$category] : null;

// Get products with available stock (either in variations or base product)
$query = "SELECT p.*, 
          (SELECT COUNT(*) FROM product_variations pv WHERE pv.product_id = p.product_id) as has_variations,
          (SELECT SUM(stock) FROM product_variations pv WHERE pv.product_id = p.product_id) as total_stock
          FROM products p";

$params = [];

if ($db_category) {
    if ($db_category === 'men') {
        $query .= " WHERE p.category LIKE 'men%'";
    } elseif ($db_category === 'women') {
        $query .= " WHERE p.category LIKE 'women%'";
    } elseif ($db_category === 'access') {
        $query .= " WHERE p.category LIKE 'access%'";
    }elseif ($db_category === 'shoes') {
        $query .= " WHERE p.category LIKE 'shoes%'";
    }
     else {
        $query .= " WHERE p.category = ?";
        $params[] = $db_category;
    }
} else {
    $query .= " WHERE 1=1";
}

// Only show products with available stock
$query .= " AND ((SELECT SUM(stock) FROM product_variations pv WHERE pv.product_id = p.product_id) > 0 
          OR (SELECT COUNT(*) FROM product_variations pv WHERE pv.product_id = p.product_id) = 0)";

$query .= " ORDER BY p.created_at DESC";

$stmt = $db->prepare($query);
$stmt->execute($params);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get variations for each product
foreach ($products as &$product) {
    if ($db_category === 'shoes') {
        // For shoes, order sizes numerically
        $stmt = $db->prepare("SELECT * FROM product_variations WHERE product_id = ? ORDER BY CAST(size AS UNSIGNED)");
    } else {
        // For other products, order sizes alphabetically
    $stmt = $db->prepare("SELECT * FROM product_variations WHERE product_id = ? ORDER BY size");
    }
    $stmt->execute([$product['product_id']]);
    $product['variations'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
unset($product);

// Get cart count
$cart_count = 0;
if ($auth->isLoggedIn()) {
    $stmt = $db->prepare("SELECT SUM(quantity) FROM cart WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $cart_count = $stmt->fetchColumn() ?: 0;
}

// Check if product is in wishlist
function isInWishlist($db, $user_id, $product_id) {
    $stmt = $db->prepare("SELECT COUNT(*) FROM wishlist WHERE user_id = ? AND product_id = ?");
    $stmt->execute([$user_id, $product_id]);
    return $stmt->fetchColumn() > 0;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Urban Trends Apparel - Shop</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #181818;
            --secondary-color: #232323;
            --accent-gold:rgb(236, 235, 233);
            --accent-silver: #C0C0C0;
            --light-color: #f8f9fa;
            --dark-color: #101010;
            --text-color: #e0e0e0;
            --text-muted: #b0b0b0;
            --success-color: #4bb543;
            --error-color: #ff3333;
            --warning-color:rgb(251, 251, 249);
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
            color: var(--accent-silver);
            text-decoration: none;
            padding: 0.5rem 1rem;
            transition: var(--transition);
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

        .cart-count {
            position: relative;
        }

        .cart-count span {
            position: absolute;
            top: -8px;
            right: -8px;
            background: var(--accent-gold);
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

        /* Shop Content */
        .shop-header {
            text-align: center;
            padding: 4rem 0 2rem;
            position: relative;
        }

        .shop-header h1 {
            font-size: 3rem;
            margin-bottom: 1rem;
            color: var(--accent-gold);
            text-shadow: 0 2px 8px #000, 0 0 2px var(--accent-gold);
            position: relative;
            display: inline-block;
            border-bottom: none;
        }

        .shop-header h1::after {
            display: none;
        }

        .search-filter-container {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 1rem;
            margin: 0 auto 2rem;
            max-width: 600px;
            padding: 0 1rem;
        }

        .search-bar {
            flex: 1;
            max-width: 400px;
            position: relative;
        }

        .search-bar input {
            width: 100%;
            padding: 0.6rem 1rem 0.6rem 2.5rem;
            background-color: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 6px;
            font-size: 0.9rem;
            color: var(--text-color);
            transition: all 0.3s ease;
        }

        .search-bar input:focus {
            outline: none;
            border-color: var(--accent-gold);
            background-color: rgba(255, 255, 255, 0.08);
        }

        .search-bar i {
            position: absolute;
            left: 0.8rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            font-size: 0.9rem;
            pointer-events: none;
        }

        .filter-container {
            position: relative;
        }

        .filter-btn {
            padding: 0.6rem 1.2rem;
            background-color: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 6px;
            color: var(--text-color);
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .filter-btn:hover {
            background-color: var(--accent-gold);
            border-color: var(--accent-gold);
            color: white;
        }

        .filter-btn i {
            font-size: 0.8rem;
        }

        .filter-menu {
            display: none;
            position: absolute;
            top: 100%;
            right: 1rem;
            background-color: var(--primary-color);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 6px;
            padding: 1rem;
            min-width: 200px;
            z-index: 100;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
            margin-top: 0.5rem;
        }

        .filter-menu.active {
            display: block;
            animation: slideDown 0.3s ease;
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

        .filter-section {
            margin-bottom: 1rem;
        }

        .filter-section:last-child {
            margin-bottom: 0;
        }

        .filter-title {
            font-size: 0.9rem;
            color: var(--text-color);
            margin-bottom: 0.5rem;
            font-weight: 500;
        }

        .filter-options {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .filter-option {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--text-muted);
            font-size: 0.85rem;
            cursor: pointer;
            transition: color 0.2s ease;
        }

        .filter-option:hover {
            color: var(--text-color);
        }

        .filter-option input[type="checkbox"] {
            width: 16px;
            height: 16px;
            border-radius: 3px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            background-color: transparent;
            cursor: pointer;
        }

        .filter-actions {
            display: flex;
            justify-content: space-between;
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
        }

        .filter-actions button {
            padding: 0.4rem 0.8rem;
            border-radius: 4px;
            font-size: 0.85rem;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .apply-filters {
            background: linear-gradient(90deg, var(--accent-gold) 60%, var(--accent-silver) 100%);
            color: #181818;
            font-weight: 700;
            box-shadow: 0 4px 24px 0 rgba(255,215,0,0.18);
            position: relative;
            overflow: hidden;
            animation: shineBtn 2.5s infinite linear;
            border: none;
        }

        .apply-filters:hover {
            background: linear-gradient(90deg, var(--accent-gold) 60%, var(--accent-silver) 100%);
            color: #181818;
            font-weight: 700;
            box-shadow: 0 4px 24px 0 rgba(255,215,0,0.18);
            position: relative;
            overflow: hidden;
            animation: shineBtn 2.5s infinite linear;
        }

        .apply-filters::before {
            content: '';
            position: absolute;
            top: 0; left: -75%;
            width: 50%; height: 100%;
            background: linear-gradient(120deg, rgba(255,255,255,0.18) 0%, rgba(255,255,255,0.01) 100%);
            transform: skewX(-20deg);
            animation: btnShine 2.5s infinite linear;
        }

        .reset-filters {
            background-color: transparent;
            color: var(--text-muted);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .reset-filters:hover {
            color: var(--text-color);
            border-color: rgba(255, 255, 255, 0.2);
        }

        @media (max-width: 768px) {
            .search-filter-container {
                flex-direction: column;
                gap: 0.75rem;
            }

            .search-bar {
                width: 100%;
                max-width: none;
            }

            .filter-btn {
                width: 100%;
                justify-content: center;
            }

            .filter-menu {
                left: 1rem;
                right: 1rem;
                width: auto;
            }
        }

        .categories {
            display: flex;
            justify-content: center;
            margin-bottom: 2rem;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .category-btn {
            padding: 0.6rem 1.2rem;
            background-color: rgba(255,255,255,0.08);
            color: var(--accent-silver);
            border: 1px solid transparent;
            border-radius: 20px;
            cursor: pointer;
            transition: var(--transition);
            font-size: 0.9rem;
        }

        .category-btn:hover, .category-btn.active {
            background: linear-gradient(90deg, var(--accent-gold), var(--accent-silver));
            color: #181818;
            border: 1px solid var(--accent-gold);
            box-shadow: 0 2px 12px 0 rgba(255,215,0,0.12);
        }

        .size-guide-link {
            text-align: center;
            margin: 1rem 0 2rem;
        }

        .size-guide-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 0.8rem 1.5rem;
            background: linear-gradient(90deg, var(--accent-gold) 60%, var(--accent-silver) 100%);
            color: #181818;
            border-radius: var(--border-radius);
            text-decoration: none;
            font-weight: 700;
            transition: var(--transition);
            box-shadow: 0 4px 24px 0 rgba(255,215,0,0.18);
            position: relative;
            overflow: hidden;
            animation: shineBtn 2.5s infinite linear;
        }

        .size-guide-btn::before {
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

        .size-guide-btn:hover {
            box-shadow: 0 12px 48px 0 rgba(255,215,0,0.18), 0 2.5px 8px 0 rgba(192,192,192,0.18);
            border-color: var(--accent-gold);
            transform: translateY(-10px) scale(1.03);
        }

        .size-guide-btn i {
            font-size: 1.2rem;
        }

        .products-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
            gap: 1.5rem;
            padding: 1.5rem;
            max-width: 1200px;
            margin: 0 auto;
            background: linear-gradient(120deg, rgba(255,255,255,0.01) 0%, rgba(255,215,0,0.03) 100%);
            border-radius: var(--border-radius);
            box-shadow: 0 6px 32px 0 rgba(255,215,0,0.10), 0 1.5px 4px 0 rgba(192,192,192,0.10);
        }

        .product-card {
            background: linear-gradient(120deg, rgba(255,255,255,0.01) 0%, rgba(255,215,0,0.03) 100%);
            border: 1.5px solid rgba(255,215,0,0.18);
            border-radius: var(--border-radius);
            overflow: hidden;
            box-shadow: 0 6px 32px 0 rgba(255,215,0,0.10), 0 1.5px 4px 0 rgba(192,192,192,0.10);
            transition: var(--transition), box-shadow 0.5s cubic-bezier(.4,0,.2,1);
            display: flex;
            flex-direction: column;
            height: 100%;
            position: relative;
        }

        .product-card:hover {
            box-shadow: 0 12px 48px 0 rgba(255,215,0,0.18), 0 2.5px 8px 0 rgba(192,192,192,0.18);
            border-color: var(--accent-gold);
            transform: translateY(-10px) scale(1.03);
        }

        .product-image-container {
            position: relative;
            padding-top: 120%;
            overflow: hidden;
            background-color: rgba(0, 0, 0, 0.05);
        }

        .product-image {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.5s ease;
        }

        .product-card:hover .product-image {
            transform: scale(1.08);
        }

        .product-info {
            padding: 1rem;
            flex-grow: 1;
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            background: linear-gradient(
                to bottom,
                rgba(26, 26, 26, 0.95),
                rgba(18, 18, 18, 0.98)
            );
        }

        .product-name {
            font-size: 1rem;
            font-weight: 500;
            color: var(--accent-gold);
            text-shadow: 0 2px 8px #000, 0 0 2px var(--accent-gold);
            margin-bottom: 0.25rem;
            line-height: 1.4;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .product-price {
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--accent-silver);
            margin-bottom: 0.5rem;
        }

        .size-container {
            margin: 0.5rem 0;
            padding: 0.5rem;
            background-color: rgba(255, 255, 255, 0.03);
            border-radius: 4px;
        }

        .sizes-title {
            font-size: 0.8rem;
            margin-bottom: 0.5rem;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .size-selector {
            display: flex;
            flex-wrap: wrap;
            gap: 0.25rem;
        }

        .size-button {
            min-width: 35px;
            height: 35px;
            padding: 0 0.5rem;
            font-size: 0.8rem;
            display: flex;
            align-items: center;
            justify-content: center;
            background-color: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            color: var(--text-color);
            border-radius: 4px;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .size-button:hover:not(:disabled) {
            background-color: rgba(255, 107, 107, 0.1);
            border-color: var(--accent-gold);
            color: var(--accent-gold);
        }

        .size-button.selected {
            background-color: var(--accent-gold);
            border-color: var(--accent-gold);
            color: white;
        }

        .quantity-selector {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin: 0.5rem 0;
            background-color: rgba(255, 255, 255, 0.03);
            padding: 0.25rem;
            border-radius: 4px;
        }

        .quantity-btn {
            width: 28px;
            height: 28px;
            display: flex;
            align-items: center;
            justify-content: center;
            background-color: rgba(255, 255, 255, 0.05);
            border: none;
            border-radius: 4px;
            color: var(--text-color);
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .quantity-btn:hover {
            background-color: var(--accent-gold);
            color: white;
        }

        .quantity-input {
            width: 40px;
            text-align: center;
            background-color: transparent;
            border: none;
            color: var(--text-color);
            font-size: 0.9rem;
            padding: 0;
        }

        .product-actions {
            display: grid;
            grid-template-columns: 1fr 1fr auto;
            gap: 0.5rem;
            margin-top: 0.5rem;
        }

        .action-btn {
            padding: 0.5rem;
            font-size: 0.85rem;
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.25rem;
            transition: all 0.2s ease;
        }

        .action-btn i {
            font-size: 0.9rem;
        }

        .buy-now, .add-to-cart {
            background: linear-gradient(90deg, var(--accent-gold) 60%, var(--accent-silver) 100%);
            color: #181818;
            font-weight: 700;
            box-shadow: 0 4px 24px 0 rgba(255,215,0,0.18);
            position: relative;
            overflow: hidden;
            animation: shineBtn 2.5s infinite linear;
            border: none;
        }

        .buy-now:hover, .add-to-cart:hover {
            background: linear-gradient(90deg, var(--accent-gold) 60%, var(--accent-silver) 100%);
            color: #181818;
            font-weight: 700;
            box-shadow: 0 4px 24px 0 rgba(255,215,0,0.18);
            position: relative;
            overflow: hidden;
            animation: shineBtn 2.5s infinite linear;
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

        .wishlist-btn {
            width: 32px;
            height: 32px;
            border-radius: 4px;
            background: rgba(255,255,255,0.08);
            color: var(--accent-silver);
            transition: all 0.2s ease;
        }

        .wishlist-btn:hover, .wishlist-btn.active {
            background: linear-gradient(90deg, var(--accent-gold), var(--accent-silver));
            color: #181818;
            border: 1px solid var(--accent-gold);
        }

        .product-badge {
            position: absolute;
            top: 0.5rem;
            right: 0.5rem;
            background: var(--accent-gold);
            color: #181818;
            padding: 0.25rem 0.5rem;
            border-radius: 3px;
            font-size: 0.75rem;
            font-weight: 500;
            z-index: 2;
        }

        .out-of-stock-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.7);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1;
        }

        .out-of-stock-overlay span {
            background-color: var(--error-color);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 4px;
            font-size: 0.9rem;
            font-weight: 500;
            letter-spacing: 0.5px;
        }

        @media (max-width: 768px) {
            .products-grid {
                grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
                padding: 1rem;
                gap: 1rem;
            }

            .product-name {
                font-size: 0.9rem;
            }

            .product-price {
                font-size: 1.1rem;
            }

            .action-btn {
                padding: 0.4rem;
                font-size: 0.8rem;
            }
        }

        @media (max-width: 480px) {
            .products-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 0.75rem;
                padding: 0.75rem;
            }

            .product-info {
                padding: 0.75rem;
            }

            .size-button {
                min-width: 30px;
                height: 30px;
                font-size: 0.75rem;
            }

            .product-actions {
                grid-template-columns: 1fr;
            }

            .wishlist-btn {
                display: none;
            }
        }

        /* Footer */
        footer {
            background: linear-gradient(120deg, #232323 60%, #181818 100%);
            color: var(--accent-silver);
            padding: 4rem 0 2rem;
            margin-top: 4rem;
            border-top: 1px solid rgba(255,255,255,0.1);
        }

        .footer-content {
            max-width: 1400px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 3rem;
            padding: 0 2rem;
        }

        .footer-column h3 {
            color: var(--accent-gold);
            font-size: 1.3rem;
            margin-bottom: 1.5rem;
            position: relative;
            padding-bottom: 0.8rem;
        }

        .footer-column h3::after {
            content: '';
            position: absolute;
            left: 0;
            bottom: 0;
            width: 50px;
            height: 3px;
            background: linear-gradient(90deg, var(--accent-gold), var(--accent-silver));
            border-radius: 2px;
        }

        .footer-column p {
            color: var(--text-muted);
            line-height: 1.6;
            margin-bottom: 1.5rem;
        }

        .footer-column ul {
            list-style: none;
            padding: 0;
        }

        .footer-column ul li {
            margin-bottom: 1rem;
        }

        .footer-column ul li a {
            color: var(--accent-silver);
            text-decoration: none;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .footer-column ul li a:hover {
            color: var(--accent-gold);
            text-shadow: 0 2px 8px #000, 0 0 2px var(--accent-gold);
        }

        .social-links {
            display: flex;
            gap: 1rem;
            margin-top: 1.5rem;
        }

        .social-links a {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(120deg, #232323 60%, #181818 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--accent-silver);
            text-decoration: none;
            transition: var(--transition);
        }

        .social-links a:hover {
            background: linear-gradient(90deg, var(--accent-gold), var(--accent-silver));
            color: #181818;
            border-color: var(--accent-gold);
            box-shadow: 0 2px 12px 0 rgba(255,215,0,0.12);
        }

        .copyright {
            text-align: center;
            padding-top: 2rem;
            margin-top: 3rem;
            border-top: 1px solid rgba(255,255,255,0.1);
            color: var(--text-muted);
            font-size: 0.9rem;
        }

        /* Alert messages */
        .alert {
            padding: 1rem;
            margin: 1rem 0;
            border-radius: var(--border-radius);
            font-weight: 500;
        }

        .alert-success {
            background-color: rgba(75, 181, 67, 0.2);
            color: var(--success-color);
            border: 1px solid var(--success-color);
        }

        .alert-error {
            background-color: rgba(255, 51, 51, 0.2);
            color: var(--error-color);
            border: 1px solid var(--error-color);
        }

        .alert-info {
            background-color: rgba(0, 123, 255, 0.2);
            color: var(--info-color);
            border: 1px solid var(--info-color);
        }

        /* Responsive */
        @media (max-width: 768px) {
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

            .shop-header h1 {
                font-size: 2.5rem;
            }

            .products-grid {
                grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
                padding: 1rem;
                gap: 1.5rem;
            }

            .footer-content {
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 2rem;
                padding: 0 1rem;
            }
        }

        @media (max-width: 480px) {
            .products-grid {
                grid-template-columns: 1fr;
            }

            .product-info {
                padding: 1rem;
            }

            .footer-column {
                text-align: center;
            }

            .footer-column h3::after {
                left: 50%;
                transform: translateX(-50%);
            }

            .social-links {
                justify-content: center;
            }
        }

        /* Size Guide Modal Styles */
        .size-guide-modal-btn {
            position: absolute;
            bottom: 10px;
            left: 50%;
            transform: translateX(-50%);
            background-color: rgba(0, 0, 0, 0.7);
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: background-color 0.3s;
        }

        .size-guide-modal-btn:hover {
            background-color: rgba(0, 0, 0, 0.9);
        }

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            justify-content: center;
            align-items: center;
        }

        .modal-content {
            background-color: white;
            padding: 20px;
            border-radius: 8px;
            width: 90%;
            max-width: 800px;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 1px solid #eee;
        }

        .modal-header h2 {
            margin: 0;
            color: #333;
        }

        .close-modal {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: #666;
            padding: 0;
        }

        .close-modal:hover {
            color: #333;
        }

        .size-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }

        .size-tab {
            padding: 8px 16px;
            border: 1px solid #ddd;
            background: none;
            cursor: pointer;
            border-radius: 4px;
            transition: all 0.3s;
        }

        .size-tab.active {
            background-color: #333;
            color: white;
            border-color: #333;
        }

        .size-content {
            display: none;
        }

        .size-content.active {
            display: block;
        }

        .size-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }

        .size-table th,
        .size-table td {
            padding: 8px;
            text-align: center;
            border: 1px solid #ddd;
        }

        .size-table th {
            background-color: #f5f5f5;
            font-weight: bold;
        }

        .size-note {
            background-color: #f9f9f9;
            padding: 15px;
            border-radius: 4px;
            margin-top: 20px;
        }

        .size-note h3 {
            margin-top: 0;
            color: #333;
        }

        .size-note p {
            margin: 5px 0;
            color: #666;
        }

        /* Product Card Size Section */
        .product-sizes {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin: 10px 0;
        }

        .buy-now:disabled,
        .add-to-cart:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        /* Size Container Styles - Uniform for all products */
        .size-container {
            margin: 15px 0;
            padding: 10px;
            background-color: rgba(255, 255, 255, 0.05);
            border-radius: var(--border-radius);
        }

        .sizes-title {
            font-size: 0.9rem;
            margin-bottom: 10px;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .size-selector {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .size-button {
            position: relative;
            min-width: 50px;
            height: 50px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            background-color: rgba(255, 255, 255, 0.1);
            border: 1px solid #444;
            border-radius: 4px;
            cursor: pointer;
            color: var(--text-color);
            font-size: 0.9rem;
            transition: var(--transition);
            padding: 0 10px;
        }

        .size-button:hover, .size-button.selected {
            background-color: var(--accent-gold);
            color: white;
            border-color: var(--accent-gold);
        }

        .size-button:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .stock-indicator {
            font-size: 0.7rem;
            color: var(--text-muted);
            margin-top: 2px;
        }

        .size-button.selected .stock-indicator {
            color: rgba(255, 255, 255, 0.8);
        }
        
        /* Stock info for accessories */
        .stock-info {
            margin: 10px 0;
            padding: 8px 12px;
            background-color: rgba(255, 255, 255, 0.05);
            border-radius: 4px;
            text-align: center;
        }
        
        .stock-info .stock-indicator {
            font-size: 0.9rem;
            color: var(--text-muted);
        }

        .action-btn.login-prompt {
            background-color: var(--accent-gold);
            color: white;
            text-decoration: none;
            display: inline-block;
            padding: 10px 20px;
            border-radius: 4px;
            transition: all 0.3s ease;
            text-align: center;
            width: 100%;
            margin-bottom: 10px;
        }

        .action-btn.login-prompt:hover {
            background-color: #ff5252;
            transform: translateY(-2px);
        }

        .action-btn.login-prompt i {
            margin-right: 8px;
        }

        /* New styles for the shimmer effect */
        .shimmer {
            background: linear-gradient(90deg, rgba(255,255,255,0) 0%, rgba(255,255,255,0.3) 50%, rgba(255,255,255,0) 100%);
            background-size: 200% 100%;
            animation: shimmer-animation 1.5s infinite;
        }

        @keyframes shimmer-animation {
            0% { background-position: 200% 0; }
            100% { background-position: -200% 0; }
        }

        .size-dropdown {
            width: 100%;
            padding: 10px;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--accent-gold);
            border-radius: var(--border-radius);
            color: var(--text-color);
            font-size: 0.95rem;
            cursor: pointer;
            transition: var(--transition);
            margin-top: 5px;
        }

        .size-dropdown:focus {
            outline: none;
            border-color: var(--accent-gold);
            box-shadow: 0 0 0 3px rgba(251, 252, 253, 0.1);
        }

        .size-dropdown option {
            background-color: var(--primary-color);
            color: var(--text-color);
            padding: 10px;
        }

        .size-dropdown option:first-child {
            color: var(--text-muted);
        }

        .size-dropdown:hover {
            background: rgba(255, 255, 255, 0.08);
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
                    <a href="profile.php#cart" class="cart-link" title="Cart">
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
        <!-- Display success/error messages -->
        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="alert alert-success">
                <?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?>
            </div>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="alert alert-error">
                <?php echo $_SESSION['error_message']; unset($_SESSION['error_message']); ?>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['info_message'])): ?>
            <div class="alert alert-info">
                <?php echo $_SESSION['info_message']; unset($_SESSION['info_message']); ?>
            </div>
        <?php endif; ?>

        <section class="shop-section">
            <div class="shop-header">
                <h1>
                    <?php 
                    if ($db_category) {
                        switch($db_category) {
                            case 'men': echo "MEN'S FASHION"; break;
                            case 'women': echo "WOMEN'S FASHION"; break;
                            case 'shoes': echo "FOOTWEAR"; break;
                            case 'access': echo "ACCESSORIES"; break;
                            default: echo "SHOP COLLECTION";
                        }
                    } else {
                        echo "SHOP COLLECTION";
                    }
                    ?>
                </h1>
            </div>
            
            <div class="search-filter-container">
                <div class="search-bar">
                    <i class="fas fa-search"></i>
                    <input type="text" placeholder="Search products..." id="searchInput">
                </div>
                <div class="filter-container">
                    <button class="filter-btn" id="filterBtn">
                        <i class="fas fa-filter"></i> Filters
                    </button>
                    <div class="filter-menu" id="filterMenu">
                        <div class="filter-section">
                            <h3 class="filter-title">Price Range</h3>
                            <div class="filter-options">
                                <label class="filter-option">
                                    <input type="checkbox" name="price" value="0-1000"> ₱0 - ₱1,000
                                </label>
                                <label class="filter-option">
                                    <input type="checkbox" name="price" value="1000-3000"> ₱1,000 - ₱3,000
                                </label>
                                <label class="filter-option">
                                    <input type="checkbox" name="price" value="3000-5000"> ₱3,000 - ₱5,000
                                </label>
                                <label class="filter-option">
                                    <input type="checkbox" name="price" value="5000+"> ₱5,000+
                                </label>
                            </div>
                        </div>
                        <div class="filter-section">
                            <h3 class="filter-title">Availability</h3>
                            <div class="filter-options">
                                <label class="filter-option">
                                    <input type="checkbox" name="availability" value="in-stock"> In Stock
                                </label>
                                <label class="filter-option">
                                    <input type="checkbox" name="availability" value="sale"> On Sale
                                </label>
                            </div>
                        </div>
                        <div class="filter-actions">
                            <button class="reset-filters" onclick="resetFilters()">Reset</button>
                            <button class="apply-filters" onclick="applyFilters()">Apply Filters</button>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="categories">
    <button class="category-btn <?php echo !$db_category ? 'active' : ''; ?>" data-category="all">All Products</button>
    <button class="category-btn <?php echo $db_category === 'men' ? 'active' : ''; ?>" data-category="men">Men's Fashion</button>
    <button class="category-btn <?php echo $db_category === 'women' ? 'active' : ''; ?>" data-category="women">Women's Fashion</button>
    <button class="category-btn <?php echo $db_category === 'shoes' ? 'active' : ''; ?>" data-category="shoes">Footwear</button>
    <button class="category-btn <?php echo $db_category === 'access' ? 'active' : ''; ?>" data-category="accessories">Accessories</button>
</div>


            
            <div class="products-grid" id="productsContainer">
                <?php foreach ($products as $product): 
                    $has_variations = $product['has_variations'] > 0;
                    $total_stock = $product['total_stock'] ?? 0;
                    // Modified image path to ensure correct resolution
                    $primary_image = file_exists("assets/images/products/" . $product['image']) 
                        ? $product['image'] 
                        : 'default-product.jpg';
                ?>
                    <div class="product-card" data-id="<?php echo $product['product_id']; ?>">
                        <?php if($total_stock <= 0): ?>
                            <div class="out-of-stock-overlay">
                                <span>OUT OF STOCK</span>
                            </div>
                        <?php elseif($total_stock < 10): ?>
                            <span class="product-badge">Only <?php echo $total_stock; ?> left</span>
                        <?php endif; ?>
                        
                        <div class="product-image-container">
                            <img src="assets/images/products/<?php echo htmlspecialchars($primary_image); ?>" 
                                 alt="<?php echo htmlspecialchars($product['name']); ?>" 
                                 class="product-image"
                                 onerror="this.src='assets/images/products/default-product.jpg'">
                            
                            <?php if($db_category === 'shoes'): ?>
                            <!-- Size guide button removed -->
                            <?php endif; ?>
                        </div>
                        <div class="product-info">
                            <h3 class="product-name"><?php echo htmlspecialchars($product['name']); ?></h3>
                            <p class="product-price" data-base-price="<?php echo $product['price']; ?>">
                                ₱<?php echo number_format($product['price'], 2); ?>
                            </p>
                            
                            <form method="POST" class="product-form" data-id="<?php echo $product['product_id']; ?>">
                                <input type="hidden" name="product_id" value="<?php echo $product['product_id']; ?>">
                                
                                <?php if($has_variations && $total_stock > 0): ?>
                                    <?php if(strpos($product['category'], 'access_') === 0): ?>
                                        <!-- For accessories, automatically select the first variation and show only stock -->
                                        <?php if (!empty($product['variations'])): ?>
                                            <input type="hidden" name="variation_id" value="<?php echo $product['variations'][0]['variation_id']; ?>">
                                           
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <div class="size-container">
                                            <h4 class="sizes-title">Select Size:</h4>
                                            <select name="variation_id" class="size-dropdown" required>
                                                <option value="">Choose a size</option>
                                        <?php foreach ($product['variations'] as $variation): ?>
                                            <?php if ($variation['stock'] > 0): ?>
                                                        <option value="<?php echo $variation['variation_id']; ?>"
                                                        data-stock="<?php echo $variation['stock']; ?>"
                                                        data-price-adjustment="<?php echo $variation['price_adjustment']; ?>">
                                                    <?php echo $variation['size']; ?>
                                                            (<?php echo $variation['stock']; ?> available)
                                                        </option>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                            </select>
                                    </div>
                                    <?php endif; ?>
                                <?php endif; ?>
                                
                                <?php if($total_stock > 0): ?>
                                    <div class="quantity-selector">
                                        <button type="button" class="quantity-btn minus">-</button>
                                        <input type="number" 
                                               name="quantity" 
                                               class="quantity-input" 
                                               value="1" 
                                               min="1" 
                                               max="<?php echo $total_stock; ?>" 
                                               readonly>
                                        <button type="button" class="quantity-btn plus">+</button>
                                    </div>
                                
                                    <div class="product-actions">
                                        <?php if ($auth->isLoggedIn()): ?>
                                            <button type="submit" 
                                                    name="buy_now" 
                                                    class="action-btn buy-now" 
                                                    <?php echo $has_variations ? 'disabled' : ''; ?> 
                                                    data-action="buy">
                                                <i class="fas fa-bolt"></i> Buy Now
                                            </button>
                                            <button type="submit" 
                                                    name="add_to_cart" 
                                                    class="action-btn add-to-cart" 
                                                    <?php echo $has_variations ? 'disabled' : ''; ?> 
                                                    data-action="cart">
                                                <i class="fas fa-cart-plus"></i> Add to Cart
                                            </button>
                                            <button type="button" 
                                                    class="wishlist-btn <?php echo isInWishlist($db, $_SESSION['user_id'], $product['product_id']) ? 'active' : ''; ?>" 
                                                    onclick="toggleWishlist(<?php echo $product['product_id']; ?>, this)">
                                                <i class="fas fa-heart"></i>
                                            </button>
                                        <?php else: ?>
                                            <a href="login.php" class="action-btn login-prompt">
                                                <i class="fas fa-sign-in-alt"></i> Login to Purchase
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="product-actions">
                                        <button class="action-btn buy-now" disabled>
                                            <i class="fas fa-ban"></i> Out of Stock
                                        </button>
                                    </div>
                                <?php endif; ?>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
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
                        <li><a href="about.php"><i class="fas fa-chevron-right"></i> About</a></li>
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

    <!-- Size Guide Modal -->
   

    <script>
        // Update cart counter
        function updateCartCounter() {
            fetch('get_cart_count.php')
                .then(response => response.json())
                .then(data => {
                    document.getElementById('cart-counter').textContent = data.count;
                })
                .catch(error => {
                    console.error('Error fetching cart count:', error);
                });
        }

        // Category filter
        document.querySelectorAll('.category-btn').forEach(button => {
            button.addEventListener('click', function() {
                const category = this.getAttribute('data-category');
                window.location.href = category === 'all' ? 'shop.php' : `shop.php?category=${category}`;
            });
        });

        // Size Guide Modal Functions
        function openSizeGuide(productId) {
            const modal = document.getElementById('sizeGuideModal');
            modal.style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }

        function closeSizeGuide() {
            const modal = document.getElementById('sizeGuideModal');
            modal.style.display = 'none';
            document.body.style.overflow = 'auto';
        }

        function switchSizeTab(tab) {
            // Remove active class from all tabs and contents
            document.querySelectorAll('.size-tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.size-content').forEach(c => c.classList.remove('active'));
            
            // Add active class to selected tab and content
            document.getElementById(tab + 'Tab').classList.add('active');
            document.getElementById(tab + 'Content').classList.add('active');
        }

        // Size Selection Functions
        function selectSize(productId, size) {
            // Remove selected class from all size buttons for this product
            const sizeButtons = document.querySelectorAll(`[data-product-id="${productId}"] .size-button`);
            sizeButtons.forEach(btn => btn.classList.remove('selected'));
            
            // Add selected class to clicked button
            const selectedButton = document.querySelector(`[data-product-id="${productId}"] .size-button[data-size="${size}"]`);
            selectedButton.classList.add('selected');
            
            // Enable buy now and add to cart buttons
            const buyNowBtn = document.querySelector(`[data-product-id="${productId}"] .buy-now`);
            const addToCartBtn = document.querySelector(`[data-product-id="${productId}"] .add-to-cart`);
            buyNowBtn.disabled = false;
            addToCartBtn.disabled = false;
            
            // Store selected size in data attribute
            buyNowBtn.setAttribute('data-size', size);
            addToCartBtn.setAttribute('data-size', size);
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('sizeGuideModal');
            if (event.target === modal) {
                closeSizeGuide();
            }
        }

        // Initialize size buttons for all products
        document.addEventListener('DOMContentLoaded', function() {
            // Disable buy now and add to cart buttons initially
            document.querySelectorAll('.buy-now, .add-to-cart').forEach(btn => {
                btn.disabled = true;
            });
        });

        // Search functionality
        document.getElementById('searchInput').addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase();
            const category = new URLSearchParams(window.location.search).get('category');
            
            fetch('search_products.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `search_term=${searchTerm}&category=${category || ''}`
            })
            .then(response => response.text())
            .then(html => {
                document.getElementById('productsContainer').innerHTML = html;
                initializeProductControls();
            });
        });

        // Initialize product controls
        function initializeProductControls() {
            document.querySelectorAll('.product-card').forEach(card => {
                const form = card.querySelector('.product-form');
                const sizeButtons = card.querySelectorAll('.size-button');
                const quantityInput = card.querySelector('.quantity-input');
                const minusBtn = card.querySelector('.quantity-btn.minus');
                const plusBtn = card.querySelector('.quantity-btn.plus');
                const buyBtn = card.querySelector('.buy-now');
                const cartBtn = card.querySelector('.add-to-cart');
                const variationInput = card.querySelector('.selected-variation');
                
                // Check if this is an accessory product
                const isAccessory = card.querySelector('input[name="variation_id"]') && 
                                   !card.querySelector('.size-container');
                
                // For accessories, enable buttons by default
                if (isAccessory && buyBtn && cartBtn) {
                    buyBtn.disabled = false;
                    cartBtn.disabled = false;
                    
                    // Set quantity max based on stock
                    if (quantityInput) {
                        const stockInfo = card.querySelector('.stock-info .stock-indicator');
                        if (stockInfo) {
                            const stockMatch = stockInfo.textContent.match(/(\d+)/);
                            if (stockMatch && stockMatch[1]) {
                                const stock = parseInt(stockMatch[1]);
                                quantityInput.max = stock;
                                if (parseInt(quantityInput.value) > stock) {
                                    quantityInput.value = stock;
                                }
                            }
                        }
                    }
                }

                // Size selection
                sizeButtons.forEach(button => {
                    button.addEventListener('click', function() {
                        // Remove selected class from all size buttons in this product card
                        const allSizeButtons = card.querySelectorAll('.size-button');
                        allSizeButtons.forEach(btn => btn.classList.remove('selected'));
                        
                        // Add selected class to clicked button
                        this.classList.add('selected');
                        
                        // Update variation ID
                        if (variationInput) {
                            variationInput.value = this.dataset.variationId;
                        }
                        
                        // Update quantity max
                        if (quantityInput) {
                            const maxStock = parseInt(this.dataset.stock);
                            quantityInput.max = maxStock;
                            if (parseInt(quantityInput.value) > maxStock) {
                                quantityInput.value = maxStock;
                            }
                        }
                        
                        // Update price
                        const priceAdjustment = parseFloat(this.dataset.priceAdjustment) || 0;
                        const basePrice = parseFloat(card.querySelector('.product-price').dataset.basePrice);
                        const newPrice = basePrice + priceAdjustment;
                        card.querySelector('.product-price').textContent = '₱' + newPrice.toFixed(2);
                        
                        // Enable buttons
                        if (buyBtn) buyBtn.disabled = false;
                        if (cartBtn) cartBtn.disabled = false;
                    });
                });
                
                // Quantity controls
                if (minusBtn && quantityInput) {
                    minusBtn.addEventListener('click', function() {
                        const currentValue = parseInt(quantityInput.value);
                        if (currentValue > 1) {
                            quantityInput.value = currentValue - 1;
                        }
                    });
                }
                
                if (plusBtn && quantityInput) {
                    plusBtn.addEventListener('click', function() {
                        const currentValue = parseInt(quantityInput.value);
                        const maxValue = parseInt(quantityInput.max);
                        if (currentValue < maxValue) {
                            quantityInput.value = currentValue + 1;
                        }
                    });
                }
            });
        }

        // Wishlist toggle
        function toggleWishlist(productId, button) {
            const action = button.classList.contains('active') ? 'remove' : 'add';
            const category = new URLSearchParams(window.location.search).get('category');
            
            fetch('shop.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `product_id=${productId}&wishlist_action=${action}&category=${category || ''}`
            })
            .then(response => {
                if (response.redirected) {
                    window.location.href = response.url;
                }
            });
        }

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', initializeProductControls);

        document.addEventListener('DOMContentLoaded', function() {
            const menuToggle = document.getElementById('menuToggle');
            const mainNav = document.getElementById('mainNav');
            const authActions = document.getElementById('authActions');

            menuToggle.addEventListener('click', function() {
                mainNav.classList.toggle('active');
                authActions.classList.toggle('active');
                menuToggle.querySelector('i').classList.toggle('fa-bars');
                menuToggle.querySelector('i').classList.toggle('fa-times');
            });
        });

        // Filter functionality
        const filterBtn = document.querySelector('.filter-btn');
        const filterMenu = document.getElementById('filterMenu');
        let isFilterMenuOpen = false;

        // Toggle filter menu
        filterBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            isFilterMenuOpen = !isFilterMenuOpen;
            filterMenu.classList.toggle('active');
            
            // Position the menu below the filter button
            if (isFilterMenuOpen) {
                const btnRect = filterBtn.getBoundingClientRect();
                filterMenu.style.top = btnRect.bottom + window.scrollY + 'px';
                filterMenu.style.right = (window.innerWidth - btnRect.right) + 'px';
            }
        });

        // Close filter menu when clicking outside
        document.addEventListener('click', (e) => {
            if (isFilterMenuOpen && !filterMenu.contains(e.target)) {
                filterMenu.classList.remove('active');
                isFilterMenuOpen = false;
            }
        });

        // Prevent menu from closing when clicking inside
        filterMenu.addEventListener('click', (e) => {
            e.stopPropagation();
        });

        // Apply filters
        function applyFilters() {
            const selectedPrices = Array.from(document.querySelectorAll('input[name="price"]:checked'))
                .map(cb => cb.value);
            const selectedAvailability = Array.from(document.querySelectorAll('input[name="availability"]:checked'))
                .map(cb => cb.value);
            
            // Get current URL parameters
            const urlParams = new URLSearchParams(window.location.search);
            
            // Update or add filter parameters
            if (selectedPrices.length > 0) {
                urlParams.set('price', selectedPrices.join(','));
            } else {
                urlParams.delete('price');
            }
            
            if (selectedAvailability.length > 0) {
                urlParams.set('availability', selectedAvailability.join(','));
            } else {
                urlParams.delete('availability');
            }
            
            // Preserve category if it exists
            const category = urlParams.get('category');
            
            // Build new URL
            let newUrl = 'shop.php';
            const params = urlParams.toString();
            if (params) {
                newUrl += '?' + params;
            }
            
            // Navigate to filtered results
            window.location.href = newUrl;
        }

        // Reset filters
        function resetFilters() {
            // Uncheck all checkboxes
            document.querySelectorAll('.filter-option input[type="checkbox"]')
                .forEach(cb => cb.checked = false);
            
            // Keep only category parameter if it exists
            const urlParams = new URLSearchParams(window.location.search);
            const category = urlParams.get('category');
            
            // Navigate to reset URL
            window.location.href = category ? `shop.php?category=${category}` : 'shop.php';
        }

        // Initialize filters from URL parameters
        function initializeFilters() {
            const urlParams = new URLSearchParams(window.location.search);
            
            // Set price filters
            const prices = urlParams.get('price')?.split(',') || [];
            prices.forEach(price => {
                const checkbox = document.querySelector(`input[name="price"][value="${price}"]`);
                if (checkbox) checkbox.checked = true;
            });
            
            // Set availability filters
            const availability = urlParams.get('availability')?.split(',') || [];
            availability.forEach(avail => {
                const checkbox = document.querySelector(`input[name="availability"][value="${avail}"]`);
                if (checkbox) checkbox.checked = true;
            });
        }

        // Initialize filters when page loads
        document.addEventListener('DOMContentLoaded', initializeFilters);

        // Update size selection handling
        document.querySelectorAll('.size-dropdown').forEach(dropdown => {
            dropdown.addEventListener('change', function() {
                const form = this.closest('form');
                const selectedOption = this.options[this.selectedIndex];
                const priceElement = form.closest('.product-card').querySelector('.product-price');
                const basePrice = parseFloat(priceElement.getAttribute('data-base-price'));
                const priceAdjustment = parseFloat(selectedOption.getAttribute('data-price-adjustment') || 0);
                const stock = parseInt(selectedOption.getAttribute('data-stock') || 0);
                
                // Update price display
                const finalPrice = basePrice + priceAdjustment;
                priceElement.textContent = '₱' + finalPrice.toFixed(2);
                
                // Update quantity max
                const quantityInput = form.querySelector('.quantity-input');
                if (quantityInput) {
                    quantityInput.max = stock;
                    if (parseInt(quantityInput.value) > stock) {
                        quantityInput.value = stock;
                    }
                }
                
                // Enable/disable action buttons
                const actionButtons = form.querySelectorAll('button[data-action]');
                actionButtons.forEach(button => {
                    button.disabled = !this.value;
                });
            });
        });
    </script>
</body>
</html>