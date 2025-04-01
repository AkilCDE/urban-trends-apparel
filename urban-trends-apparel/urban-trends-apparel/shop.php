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

// Redirect if not logged in
if (!$auth->isLoggedIn()) {
    header("Location: login.php");
    exit;
}

// Handle logout
if (isset($_GET['logout'])) {
    $auth->logout();
    header("Location: login.php");
    exit;
}

// Get products
function getProducts($db, $category = null) {
    if ($category) {
        $stmt = $db->prepare("SELECT * FROM products WHERE category = ?");
        $stmt->execute([$category]);
    } else {
        $stmt = $db->query("SELECT * FROM products");
    }
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Check if product is in wishlist
function isInWishlist($db, $user_id, $product_id) {
    $stmt = $db->prepare("SELECT COUNT(*) FROM wishlist WHERE user_id = ? AND product_id = ?");
    $stmt->execute([$user_id, $product_id]);
    return $stmt->fetchColumn() > 0;
}

$category = isset($_GET['category']) ? htmlspecialchars($_GET['category']) : null;
$products = getProducts($db, $category);
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
            --primary-color: #1a1a1a;
            --secondary-color: #121212;
            --accent-color: #ff6b6b;
            --light-color: #f8f9fa;
            --dark-color: #0d0d0d;
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

        .container {
            width: 90%;
            max-width: 1200px;
            margin: 0 auto;
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
            display: flex;
            align-items: center;
            gap: 8px;
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

        /* Shop Content */
        .shop-header {
            text-align: center;
            padding: 4rem 0 2rem;
            position: relative;
        }

        .shop-header h1 {
            font-size: 3rem;
            margin-bottom: 1rem;
            color: var(--text-color);
            position: relative;
            display: inline-block;
        }

        .shop-header h1::after {
            content: '';
            position: absolute;
            bottom: -10px;
            left: 50%;
            transform: translateX(-50%);
            width: 100px;
            height: 3px;
            background-color: var(--accent-color);
        }

        .search-filter-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 3rem;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .search-bar {
            flex: 1;
            min-width: 300px;
            position: relative;
        }

        .search-bar input {
            width: 100%;
            padding: 0.8rem 1rem 0.8rem 3rem;
            background-color: rgba(255, 255, 255, 0.1);
            border: 1px solid #444;
            border-radius: var(--border-radius);
            font-size: 1rem;
            color: var(--text-color);
            transition: var(--transition);
        }

        .search-bar input:focus {
            outline: none;
            border-color: var(--accent-color);
            box-shadow: 0 0 0 3px rgba(255, 107, 107, 0.2);
        }

        .search-bar i {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
        }

        .filter-btn {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 0.8rem 1.5rem;
            background-color: var(--accent-color);
            color: white;
            border: none;
            border-radius: var(--border-radius);
            cursor: pointer;
            font-weight: 500;
            transition: var(--transition);
        }

        .filter-btn:hover {
            background-color: #ff5252;
            transform: translateY(-2px);
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
            background-color: rgba(255, 255, 255, 0.1);
            color: var(--text-color);
            border: none;
            border-radius: 20px;
            cursor: pointer;
            transition: var(--transition);
            font-size: 0.9rem;
        }

        .category-btn:hover, .category-btn.active {
            background-color: var(--accent-color);
            color: white;
        }

        .products-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 2rem;
            padding: 1rem 0 3rem;
        }

        .product-card {
            background-color: var(--primary-color);
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
            background-color: var(--accent-color);
            color: white;
            padding: 0.3rem 0.6rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
            z-index: 2;
        }

        .product-image-container {
            height: 300px;
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
            color: var(--accent-color);
            margin-bottom: 1rem;
        }

        .product-actions {
            display: flex;
            gap: 0.8rem;
        }

        .action-btn {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 0.8rem;
            border: none;
        border-radius: var(--border-radius);
        cursor: pointer;
        font-weight: 500;
        transition: var(--transition);
        font-size: 0.9rem;
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

    /* Quick View Modal */
    .modal {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(0, 0, 0, 0.8);
        z-index: 1000;
        overflow-y: auto;
        padding: 2rem 0;
    }

    .modal-content {
        background-color: var(--primary-color);
        margin: 0 auto;
        max-width: 900px;
        border-radius: var(--border-radius);
        overflow: hidden;
        box-shadow: var(--box-shadow);
        animation: modalFadeIn 0.3s ease;
    }

    @keyframes modalFadeIn {
        from { opacity: 0; transform: translateY(-50px); }
        to { opacity: 1; transform: translateY(0); }
    }

    .close-modal {
        position: absolute;
        top: 1rem;
        right: 1rem;
        font-size: 2rem;
        color: white;
        cursor: pointer;
        transition: var(--transition);
    }

    .close-modal:hover {
        color: var(--accent-color);
    }

    .modal-product {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 2rem;
    }

    .modal-product-image {
        width: 100%;
        height: 500px;
        object-fit: cover;
    }

    .modal-product-info {
        padding: 2rem;
    }

    .modal-product-name {
        font-size: 2rem;
        margin-bottom: 1rem;
        color: var(--text-color);
    }

    .modal-product-price {
        font-size: 1.8rem;
        font-weight: 700;
        color: var(--accent-color);
        margin-bottom: 1.5rem;
    }

    .modal-product-description {
        margin-bottom: 2rem;
        color: var(--text-muted);
        line-height: 1.7;
    }

    .modal-product-options {
        margin-bottom: 2rem;
    }

    .option-group {
        margin-bottom: 1.5rem;
    }

    .option-group label {
        display: block;
        margin-bottom: 0.5rem;
        font-weight: 500;
        color: var(--text-color);
    }

    .size-options {
        display: flex;
        gap: 0.8rem;
    }

    .size-option {
        padding: 0.6rem 1rem;
        background-color: rgba(255, 255, 255, 0.1);
        border: 1px solid #444;
        border-radius: var(--border-radius);
        cursor: pointer;
        transition: var(--transition);
    }

    .size-option:hover, .size-option.selected {
        background-color: var(--accent-color);
        color: white;
        border-color: var(--accent-color);
    }

    .quantity-selector {
        display: flex;
        align-items: center;
        gap: 1rem;
    }

    .quantity-btn {
        width: 40px;
        height: 40px;
        display: flex;
        align-items: center;
        justify-content: center;
        background-color: rgba(255, 255, 255, 0.1);
        border: none;
        border-radius: var(--border-radius);
        cursor: pointer;
        font-size: 1.2rem;
        color: var(--text-color);
        transition: var(--transition);
    }

    .quantity-btn:hover {
        background-color: var(--accent-color);
        color: white;
    }

    .quantity-input {
        width: 60px;
        text-align: center;
        padding: 0.8rem;
        background-color: rgba(255, 255, 255, 0.1);
        border: 1px solid #444;
        border-radius: var(--border-radius);
        color: var(--text-color);
        font-size: 1.1rem;
    }

    .modal-actions {
        display: flex;
        gap: 1rem;
        margin-top: 2rem;
    }

    .modal-action-btn {
        flex: 1;
        padding: 1rem;
        border: none;
        border-radius: var(--border-radius);
        cursor: pointer;
        font-weight: 600;
        transition: var(--transition);
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
    }

    .add-to-cart-modal {
        background-color: rgba(255, 255, 255, 0.1);
        color: var(--text-color);
    }

    .add-to-cart-modal:hover {
        background-color: rgba(255, 255, 255, 0.2);
    }

    .buy-now-modal {
        background-color: var(--accent-color);
        color: white;
    }

    .buy-now-modal:hover {
        background-color: #ff5252;
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
    @media (max-width: 992px) {
        .modal-product {
            grid-template-columns: 1fr;
        }
        
        .modal-product-image {
            height: 400px;
        }
    }

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
        }
    }

    @media (max-width: 576px) {
        .shop-header h1 {
            font-size: 2rem;
        }

        .modal-product-info {
            padding: 1.5rem;
        }

        .modal-product-name {
            font-size: 1.5rem;
        }

        .modal-product-price {
            font-size: 1.5rem;
        }

        .modal-actions {
            flex-direction: column;
        }
    }
</style>

</head> <body> <header> <div class="container">
     <div class="logo"> <a href="index.php"><i class="fas fa-tshirt"></i> Urban Trends</a> </div>
      <nav> <ul> <li><a href="index.php"><i class="fas fa-home"></i> Home</a></li> <li><a 
      href="shop.php"><i class="fas fa-store"></i> Shop</a></li> <li>
        <a href="about.php"><i class="fas fa-info-circle">
        </i> About</a></li> <li><a href="contact.php">
            <i class="fas fa-envelope"></i> Contact</a></li> </ul> </nav>
             <div class="user-actions"> <?php if ($auth->isLoggedIn()): ?>
                 <a href="profile.php" title="Profile"><i class="fas fa-user"></i></a>
                  <?php if ($auth->isAdmin()): ?> <a href="admin/dashboard.php" title="Admin"><i class="fas fa-cog"></i></a> <?php endif;
                   ?> <a href="wishlist.php" title="Wishlist"><i class="fas fa-heart"></i></a>
                    <a href="cart.php" class="cart-count" title="Cart"> <i class="fas fa-shopping-cart"></i>
                     <span id="cart-counter"><?php echo count($_SESSION['cart'] ?? []); ?></span>
                     </a> <a href="?logout=1" title="Logout"><i class="fas fa-sign-out-alt"></i></a>
                      <?php else: ?> <a href="login.php" title="Login"><i class="fas fa-sign-in-alt"></i></a>
                         <a href="register.php" title="Register"><i class="fas fa-user-plus"></i></a>
                          <a href="cart.php" class="cart-count" title="Cart"> <i class="fas fa-shopping-cart"></i>
                           <span id="cart-counter">0</span> 
                        </a> <?php endif; 
                        ?> 
                        </div> </div>
     </header>
    <main class="container">
    <section class="shop-section">
        <div class="shop-header">
            <h1>SHOP COLLECTION</h1>
        </div>
        
        <div class="search-filter-container">
            <div class="search-bar">
                <i class="fas fa-search"></i>
                <input type="text" placeholder="Search products..." id="searchInput">
            </div>
            <button class="filter-btn">
                <i class="fas fa-filter"></i> Filters
            </button>
        </div>
        
        <div class="categories">
            <button class="category-btn <?php echo !$category ? 'active' : ''; ?>" data-category="all">All Products</button>
            <button class="category-btn <?php echo $category === 'men' ? 'active' : ''; ?>" data-category="men">Men</button>
            <button class="category-btn <?php echo $category === 'women' ? 'active' : ''; ?>" data-category="women">Women</button>
            <button class="category-btn <?php echo $category === 'shoes' ? 'active' : ''; ?>" data-category="shoes">Shoes</button>
            <button class="category-btn <?php echo $category === 'accessories' ? 'active' : ''; ?>" data-category="accessories">Accessories</button>
        </div>
        
        <div class="products-grid" id="productsContainer">
            <?php foreach ($products as $product): ?>
                <div class="product-card" data-id="<?php echo $product['id']; ?>">
                    <?php if($product['stock'] < 10): ?>
                        <span class="product-badge">Only <?php echo $product['stock']; ?> left</span>
                    <?php endif; ?>
                    <div class="product-image-container">
                        <img src="assets/images/products/<?php echo htmlspecialchars($product['image']); ?>" alt="<?php echo htmlspecialchars($product['name']); ?>" class="product-image">
                    </div>
                    <div class="product-info">
                        <h3 class="product-name"><?php echo htmlspecialchars($product['name']); ?></h3>
                        <p class="product-price">$<?php echo number_format($product['price'], 2); ?></p>
                        <div class="product-actions">
                            <button class="action-btn buy-now" data-id="<?php echo $product['id']; ?>">
                                <i class="fas fa-bolt"></i> Buy Now
                            </button>
                            <button class="action-btn add-to-cart" data-id="<?php echo $product['id']; ?>">
                                <i class="fas fa-cart-plus"></i> Add to Cart
                            </button>
                            <button class="wishlist-btn <?php echo isInWishlist($db, $_SESSION['user_id'], $product['id']) ? 'active' : ''; ?>" 
                                    data-id="<?php echo $product['id']; ?>">
                                <i class="fas fa-heart"></i>
                            </button>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
</main>

<!-- Quick View Modal -->
<div class="modal" id="quickViewModal">
    <span class="close-modal" id="closeModal">&times;</span>
    <div class="modal-content">
        <div class="modal-product" id="modalProductContent">
            <!-- Content will be loaded via AJAX -->
        </div>
    </div>
</div>

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
            &copy; <?php echo date('Y'); ?> Urban Trends Apparel. All rights reserved.
        </div>
    </div>
</footer>

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
            if (category === 'all') {
                window.location.href = 'shop.php';
            } else {
                window.location.href = `shop.php?category=${category}`;
            }
        });
    });

    // Search functionality
    document.getElementById('searchInput').addEventListener('input', function() {
        const searchTerm = this.value.toLowerCase();
        
        fetch('search_products.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `search_term=${searchTerm}`
        })
        .then(response => response.text())
        .then(html => {
            document.getElementById('productsContainer').innerHTML = html;
            attachEventListeners();
        });
    });

    // Add to cart functionality
    function addToCart(productId, quantity = 1, size = null) {
        let formData = `product_id=${productId}&quantity=${quantity}`;
        if (size) {
            formData += `&size=${size}`;
        }
        
        return fetch('add_to_cart.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: formData
        })
        .then(response => response.json());
    }

    // Wishlist functionality
    function toggleWishlist(productId) {
        const isActive = document.querySelector(`.wishlist-btn[data-id="${productId}"]`).classList.contains('active');
        
        return fetch('wishlist_action.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `product_id=${productId}&action=${isActive ? 'remove' : 'add'}`
        })
        .then(response => response.json());
    }

    // Quick View Modal
    const modal = document.getElementById('quickViewModal');
    const closeModal = document.getElementById('closeModal');

    function openQuickView(productId) {
        fetch(`get_product.php?id=${productId}`)
            .then(response => response.text())
            .then(html => {
                document.getElementById('modalProductContent').innerHTML = html;
                modal.style.display = 'block';
                document.body.style.overflow = 'hidden';
                
                // Attach event listeners to modal elements
                attachModalListeners(productId);
            });
    }

    function closeQuickView() {
        modal.style.display = 'none';
        document.body.style.overflow = 'auto';
    }

    closeModal.addEventListener('click', closeQuickView);

    window.addEventListener('click', function(event) {
        if (event.target === modal) {
            closeQuickView();
        }
    });

    // Size selection in modal
    function attachModalListeners(productId) {
        const sizeOptions = document.querySelectorAll('.size-option');
        sizeOptions.forEach(option => {
            option.addEventListener('click', function() {
                sizeOptions.forEach(opt => opt.classList.remove('selected'));
                this.classList.add('selected');
            });
        });

        // Quantity controls
        const quantityInput = document.querySelector('.quantity-input');
        const minusBtn = document.querySelector('.quantity-btn.minus');
        const plusBtn = document.querySelector('.quantity-btn.plus');

        minusBtn.addEventListener('click', function() {
            let value = parseInt(quantityInput.value);
            if (value > 1) {
                quantityInput.value = value - 1;
            }
        });

        plusBtn.addEventListener('click', function() {
            let value = parseInt(quantityInput.value);
            quantityInput.value = value + 1;
        });

        // Modal action buttons
        document.querySelector('.add-to-cart-modal').addEventListener('click', function() {
            const quantity = parseInt(quantityInput.value);
            const size = document.querySelector('.size-option.selected')?.textContent;
            
            addToCart(productId, quantity, size)
                .then(data => {
                    if (data.success) {
                        alert('Product added to cart!');
                        updateCartCounter();
                        closeQuickView();
                    } else {
                        alert('Error: ' + data.message);
                    }
                });
        });

        document.querySelector('.buy-now-modal').addEventListener('click', function() {
            const quantity = parseInt(quantityInput.value);
            const size = document.querySelector('.size-option.selected')?.textContent;
            
            addToCart(productId, quantity, size)
                .then(data => {
                    if (data.success) {
                        window.location.href = 'checkout.php';
                    } else {
                        alert('Error: ' + data.message);
                    }
                });
        });
    }

    // Attach event listeners to product cards
    function attachEventListeners() {
        // Buy Now buttons
        document.querySelectorAll('.buy-now').forEach(button => {
            button.addEventListener('click', function() {
                const productId = this.getAttribute('data-id');
                openQuickView(productId);
            });
        });

        // Add to Cart buttons
        document.querySelectorAll('.add-to-cart').forEach(button => {
            button.addEventListener('click', function() {
                const productId = this.getAttribute('data-id');
                
                addToCart(productId)
                    .then(data => {
                        if (data.success) {
                            alert('Product added to cart!');
                            updateCartCounter();
                        } else {
                            alert('Error: ' + data.message);
                        }
                    });
            });
        });

        // Wishlist buttons
        document.querySelectorAll('.wishlist-btn').forEach(button => {
            button.addEventListener('click', function() {
                const productId = this.getAttribute('data-id');
                
                toggleWishlist(productId)
                    .then(data => {
                        if (data.success) {
                            this.classList.toggle('active');
                        } else {
                            alert('Error: ' + data.message);
                        }
                    });
            });
        });

        // Product card click (for quick view)
        document.querySelectorAll('.product-card').forEach(card => {
            card.addEventListener('click', function(e) {
                // Only trigger if not clicking on a button
                if (!e.target.closest('button')) {
                    const productId = this.getAttribute('data-id');
                    openQuickView(productId);
                }
            });
        });
    }

    // Initialize event listeners
    attachEventListeners();
</script>