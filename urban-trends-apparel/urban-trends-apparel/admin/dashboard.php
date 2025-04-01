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

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || !isset($_SESSION['is_admin']) || $_SESSION['is_admin'] != 1) {
    header("Location: ../login.php");
    exit;
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Add new product
    if (isset($_POST['add_product'])) {
        $name = htmlspecialchars($_POST['name']);
        $description = htmlspecialchars($_POST['description']);
        $price = floatval($_POST['price']);
        $category = htmlspecialchars($_POST['category']);
        $stock = intval($_POST['stock']);
        
        // Handle image upload
        $image = 'default.jpg';
        if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = '../assets/images/products/';
            $image = basename($_FILES['image']['name']);
            $uploadFile = $uploadDir . $image;
            
            // Check if image file is valid
            $imageFileType = strtolower(pathinfo($uploadFile, PATHINFO_EXTENSION));
            $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif'];
            
            if (in_array($imageFileType, $allowedExtensions)) {
                move_uploaded_file($_FILES['image']['tmp_name'], $uploadFile);
            } else {
                $image = 'default.jpg';
            }
        }
        
        $stmt = $db->prepare("INSERT INTO products (name, description, price, category, stock, image) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$name, $description, $price, $category, $stock, $image]);
    }
    
    // Update stock
    if (isset($_POST['update_stock'])) {
        $product_id = intval($_POST['product_id']);
        $stock_change = intval($_POST['stock_change']);
        
        $stmt = $db->prepare("UPDATE products SET stock = stock + ? WHERE id = ?");
        $stmt->execute([$stock_change, $product_id]);
    }
}

// Get statistics for dashboard
$totalRevenue = $db->query("SELECT SUM(total_amount) FROM orders WHERE status = 'delivered'")->fetchColumn();
$totalOrders = $db->query("SELECT COUNT(*) FROM orders")->fetchColumn();
$totalCustomers = $db->query("SELECT COUNT(*) FROM users WHERE is_admin = 0")->fetchColumn();
$totalProducts = $db->query("SELECT COUNT(*) FROM products")->fetchColumn();

// Get low stock products (less than 10 in stock)
$lowStockProducts = $db->query("SELECT * FROM products WHERE stock < 10 ORDER BY stock ASC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);

// Get recent orders
$recentOrders = $db->query("SELECT o.*, u.email FROM orders o JOIN users u ON o.user_id = u.id ORDER BY order_date DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);

// Get popular products (most ordered)
$popularProducts = $db->query("
    SELECT p.id, p.name, p.image, SUM(oi.quantity) as total_ordered 
    FROM order_items oi 
    JOIN products p ON oi.product_id = p.id 
    GROUP BY p.id 
    ORDER BY total_ordered DESC 
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

// Get frequent customers
$frequentCustomers = $db->query("
    SELECT u.id, u.email, u.firstname, u.lastname, COUNT(o.id) as order_count 
    FROM users u 
    JOIN orders o ON u.id = o.user_id 
    WHERE u.is_admin = 0 
    GROUP BY u.id 
    ORDER BY order_count DESC 
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

// Get order volume by month
$orderVolume = $db->query("
    SELECT 
        DATE_FORMAT(order_date, '%Y-%m') as month, 
        COUNT(*) as order_count,
        SUM(total_amount) as revenue
    FROM orders 
    GROUP BY DATE_FORMAT(order_date, '%Y-%m') 
    ORDER BY month DESC 
    LIMIT 12
")->fetchAll(PDO::FETCH_ASSOC);

// Handle logout
if (isset($_GET['logout'])) {
    session_unset();
    session_destroy();
    header("Location: ../login.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Urban Trends Apparel - Admin Dashboard</title>
    <!-- Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Chart.js for reports -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --primary-color: #4361ee;
            --secondary-color: #3f37c9;
            --accent-color: #4895ef;
            --danger-color: #f72585;
            --warning-color: #f8961e;
            --success-color: #4cc9f0;
            --dark-color: #2b2d42;
            --light-color: #f8f9fa;
            --sidebar-width: 250px;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        body {
            background-color: #f5f7fa;
            color: #333;
            display: flex;
            min-height: 100vh;
        }
        
        /* Sidebar Styles */
        .admin-sidebar {
            width: var(--sidebar-width);
            background-color: var(--dark-color);
            color: white;
            padding: 20px 0;
            height: 100vh;
            position: fixed;
            box-shadow: 2px 0 10px rgba(0,0,0,0.1);
            transition: all 0.3s;
            z-index: 100;
        }
        
        .admin-sidebar-header {
            padding: 0 20px 20px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            margin-bottom: 20px;
        }
        
        .admin-sidebar-header h2 {
            display: flex;
            align-items: center;
            font-size: 1.2rem;
        }
        
        .admin-sidebar-header h2 i {
            margin-right: 10px;
            color: var(--accent-color);
        }
        
        .admin-sidebar ul {
            list-style: none;
        }
        
        .admin-sidebar ul li a {
            display: flex;
            align-items: center;
            padding: 12px 20px;
            color: rgba(255,255,255,0.8);
            text-decoration: none;
            transition: all 0.3s;
            border-left: 3px solid transparent;
        }
        
        .admin-sidebar ul li a:hover, 
        .admin-sidebar ul li a.active {
            background-color: rgba(255,255,255,0.1);
            color: white;
            border-left: 3px solid var(--accent-color);
        }
        
        .admin-sidebar ul li a i {
            margin-right: 10px;
            width: 20px;
            text-align: center;
        }
        
        /* Main Content Styles */
        .admin-main {
            flex: 1;
            margin-left: var(--sidebar-width);
            padding: 20px;
        }
        
        .admin-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 20px;
            background-color: white;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .admin-header h2 {
            color: var(--dark-color);
            font-size: 1.5rem;
            display: flex;
            align-items: center;
        }
        
        .admin-header h2 i {
            margin-right: 10px;
        }
        
        .admin-actions a {
            color: var(--dark-color);
            text-decoration: none;
            margin-left: 15px;
            transition: color 0.3s;
            display: flex;
            align-items: center;
        }
        
        .admin-actions a:hover {
            color: var(--danger-color);
        }
        
        .admin-actions a i {
            margin-right: 5px;
        }
        
        /* Tabs */
        .tabs {
            display: flex;
            border-bottom: 1px solid #ddd;
            margin-bottom: 20px;
        }
        
        .tab {
            padding: 10px 20px;
            cursor: pointer;
            border-bottom: 3px solid transparent;
            transition: all 0.3s;
        }
        
        .tab.active {
            border-bottom: 3px solid var(--primary-color);
            color: var(--primary-color);
            font-weight: 600;
        }
        
        .tab:hover:not(.active) {
            border-bottom: 3px solid #ddd;
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }
        
        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background-color: white;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            transition: transform 0.3s;
            border-top: 4px solid var(--primary-color);
        }
        
        .stat-card.warning {
            border-top-color: var(--warning-color);
        }
        
        .stat-card.danger {
            border-top-color: var(--danger-color);
        }
        
        .stat-card.success {
            border-top-color: var(--success-color);
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .stat-card h3 {
            font-size: 0.9rem;
            color: #666;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
        }
        
        .stat-card h3 i {
            margin-right: 8px;
        }
        
        .stat-card p {
            font-size: 1.8rem;
            font-weight: bold;
            color: var(--dark-color);
        }
        
        .stat-card .stat-change {
            font-size: 0.8rem;
            color: #4CAF50;
            display: flex;
            align-items: center;
            margin-top: 5px;
        }
        
        .stat-card .stat-change.negative {
            color: #F44336;
        }
        
        /* Tables */
        .table-container {
            background-color: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            padding: 20px;
            overflow-x: auto;
            margin-bottom: 20px;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 600px;
        }
        
        th, td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }
        
        th {
            background-color: #f8f9fa;
            color: #555;
            font-weight: 600;
        }
        
        tr:hover {
            background-color: #f8f9fa;
        }
        
        .status {
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
        }
        
        .status.pending {
            background-color: #FFF3CD;
            color: #856404;
        }
        
        .status.processing {
            background-color: #CCE5FF;
            color: #004085;
        }
        
        .status.shipped {
            background-color: #D4EDDA;
            color: #155724;
        }
        
        .status.delivered {
            background-color: #D1ECF1;
            color: #0C5460;
        }
        
        .status.cancelled {
            background-color: #F8D7DA;
            color: #721C24;
        }
        
        .status.low-stock {
            background-color: #FFE3E3;
            color: #C92A2A;
        }
        
        /* Buttons */
        .btn {
            padding: 6px 12px;
            border-radius: 4px;
            font-size: 0.8rem;
            cursor: pointer;
            transition: all 0.3s;
            border: none;
            display: inline-flex;
            align-items: center;
        }
        
        .btn i {
            margin-right: 5px;
        }
        
        .btn-primary {
            background-color: var(--primary-color);
            color: white;
        }
        
        .btn-primary:hover {
            background-color: var(--secondary-color);
        }
        
        .btn-danger {
            background-color: var(--danger-color);
            color: white;
        }
        
        .btn-danger:hover {
            background-color: #d63384;
        }
        
        .btn-success {
            background-color: var(--success-color);
            color: white;
        }
        
        .btn-success:hover {
            background-color: #3aa8d1;
        }
        
        /* Forms */
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
        }
        
        .form-control {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 1rem;
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.2);
        }
        
        /* Charts */
        .chart-container {
            background-color: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            padding: 20px;
            margin-bottom: 20px;
        }
        
        /* Product Cards */
        .product-card {
            display: flex;
            background-color: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            overflow: hidden;
            margin-bottom: 15px;
        }
        
        .product-image {
            width: 100px;
            height: 100px;
            object-fit: cover;
        }
        
        .product-info {
            padding: 15px;
            flex: 1;
        }
        
        .product-name {
            font-weight: 600;
            margin-bottom: 5px;
        }
        
        .product-meta {
            color: #666;
            font-size: 0.9rem;
            margin-bottom: 5px;
        }
        
        /* Responsive Styles */
        @media (max-width: 768px) {
            .admin-sidebar {
                width: 70px;
                overflow: hidden;
            }
            
            .admin-sidebar-header h2 span,
            .admin-sidebar ul li a span {
                display: none;
            }
            
            .admin-sidebar ul li a {
                justify-content: center;
                padding: 12px 0;
            }
            
            .admin-sidebar ul li a i {
                margin-right: 0;
                font-size: 1.2rem;
            }
            
            .admin-main {
                margin-left: 70px;
            }
            
            .stats-grid {
                grid-template-columns: 1fr 1fr;
            }
        }
        
        @media (max-width: 576px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .admin-sidebar {
                width: 100%;
                height: auto;
                position: static;
                display: flex;
                flex-direction: column;
            }
            
            .admin-main {
                margin-left: 0;
            }
            
            .admin-sidebar-header {
                display: none;
            }
            
            .admin-sidebar ul {
                display: flex;
                overflow-x: auto;
            }
            
            .admin-sidebar ul li {
                flex: 0 0 auto;
            }
            
            .admin-sidebar ul li a {
                padding: 10px 15px;
                border-left: none;
                border-bottom: 3px solid transparent;
            }
            
            .admin-sidebar ul li a:hover, 
            .admin-sidebar ul li a.active {
                border-left: none;
                border-bottom: 3px solid var(--accent-color);
            }
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <div class="admin-sidebar">
        <div class="admin-sidebar-header">
            <h2><i class="fas fa-crown"></i> <span>Admin Panel</span></h2>
        </div>
        <ul>
            <li><a href="dashboard.php" class="active"><i class="fas fa-tachometer-alt"></i> <span>Dashboard</span></a></li>
            <li><a href="products.php"><i class="fas fa-tshirt"></i> <span>Products</span></a></li>
            <li><a href="orders.php"><i class="fas fa-shopping-bag"></i> <span>Orders</span></a></li>
            <li><a href="customers.php"><i class="fas fa-users"></i> <span>Customers</span></a></li>
            <li><a href="reports.php"><i class="fas fa-chart-bar"></i> <span>Reports</span></a></li>
        </ul>
    </div>

    <!-- Main Content -->
    <div class="admin-main">
        <div class="admin-header">
            <h2><i class="fas fa-tachometer-alt"></i> Dashboard Overview</h2>
            <div class="admin-actions">
                <a href="../index.php"><i class="fas fa-home"></i> View Site</a>
                <a href="?logout=1"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </div>
        </div>

        <!-- Tabs -->
        <div class="tabs">
            <div class="tab active" onclick="switchTab('dashboard')">Dashboard</div>
            <div class="tab" onclick="switchTab('inventory')">Inventory Management</div>
            <div class="tab" onclick="switchTab('reports')">Reports</div>
            <div class="tab" onclick="switchTab('add-product')">Add Product</div>
        </div>

        <!-- Dashboard Tab -->
        <div id="dashboard" class="tab-content active">
            <!-- Stats Cards -->
            <div class="stats-grid">
                <div class="stat-card">
                    <h3><i class="fas fa-dollar-sign"></i> Total Revenue</h3>
                    <p>$<?php echo number_format($totalRevenue ?: 0, 2); ?></p>
                    <div class="stat-change">
                        <i class="fas fa-arrow-up"></i> 12% from last month
                    </div>
                </div>
                <div class="stat-card">
                    <h3><i class="fas fa-shopping-bag"></i> Total Orders</h3>
                    <p><?php echo $totalOrders; ?></p>
                    <div class="stat-change">
                        <i class="fas fa-arrow-up"></i> 8% from last month
                    </div>
                </div>
                <div class="stat-card">
                    <h3><i class="fas fa-users"></i> Total Customers</h3>
                    <p><?php echo $totalCustomers; ?></p>
                    <div class="stat-change">
                        <i class="fas fa-arrow-up"></i> 5% from last month
                    </div>
                </div>
                <div class="stat-card">
                    <h3><i class="fas fa-tshirt"></i> Total Products</h3>
                    <p><?php echo $totalProducts; ?></p>
                    <div class="stat-change negative">
                        <i class="fas fa-arrow-down"></i> 2% from last month
                    </div>
                </div>
            </div>

            <div class="grid-2-col">
                <div class="table-container">
                    <h3><i class="fas fa-clock"></i> Recent Orders</h3>
                    <table>
                        <thead>
                            <tr>
                                <th>Order #</th>
                                <th>Customer</th>
                                <th>Date</th>
                                <th>Amount</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentOrders as $order): ?>
                                <tr>
                                    <td>#<?php echo $order['id']; ?></td>
                                    <td><?php echo $order['email']; ?></td>
                                    <td><?php echo date('M d, Y', strtotime($order['order_date'])); ?></td>
                                    <td>$<?php echo number_format($order['total_amount'], 2); ?></td>
                                    <td>
                                        <span class="status <?php echo $order['status']; ?>">
                                            <?php echo ucfirst($order['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <a href="order_details.php?id=<?php echo $order['id']; ?>" class="btn btn-primary">
                                            <i class="fas fa-eye"></i> View
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="table-container">
                    <h3><i class="fas fa-exclamation-triangle"></i> Low Stock Products</h3>
                    <table>
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>Category</th>
                                <th>Stock</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($lowStockProducts as $product): ?>
                                <tr>
                                    <td><?php echo $product['name']; ?></td>
                                    <td><?php echo ucfirst(str_replace('_', ' ', $product['category'])); ?></td>
                                    <td>
                                        <span class="status <?php echo $product['stock'] < 5 ? 'low-stock' : 'warning'; ?>">
                                            <?php echo $product['stock']; ?> left
                                        </span>
                                    </td>
                                    <td>
                                        <button class="btn btn-primary" onclick="openStockModal(<?php echo $product['id']; ?>, '<?php echo $product['name']; ?>')">
                                            <i class="fas fa-plus"></i> Restock
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Inventory Management Tab -->
        <div id="inventory" class="tab-content">
            <div class="table-container">
                <h3><i class="fas fa-boxes"></i> Product Inventory</h3>
                <div class="search-bar" style="margin-bottom: 15px;">
                    <input type="text" id="inventorySearch" placeholder="Search products..." class="form-control">
                </div>
                <table id="inventoryTable">
                    <thead>
                        <tr>
                            <th>Image</th>
                            <th>Product</th>
                            <th>Category</th>
                            <th>Price</th>
                            <th>Stock</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $allProducts = $db->query("SELECT * FROM products ORDER BY stock ASC")->fetchAll(PDO::FETCH_ASSOC);
                        foreach ($allProducts as $product): 
                        ?>
                            <tr>
                                <td>
                                    <img src="../assets/images/products/<?php echo $product['image']; ?>" alt="<?php echo $product['name']; ?>" style="width: 50px; height: 50px; object-fit: cover;">
                                </td>
                                <td><?php echo $product['name']; ?></td>
                                <td><?php echo ucfirst(str_replace('_', ' ', $product['category'])); ?></td>
                                <td>$<?php echo number_format($product['price'], 2); ?></td>
                                <td>
                                    <span class="status <?php 
                                        echo $product['stock'] < 5 ? 'low-stock' : 
                                             ($product['stock'] < 10 ? 'warning' : 'success'); 
                                    ?>">
                                        <?php echo $product['stock']; ?>
                                    </span>
                                </td>
                                <td>
                                    <button class="btn btn-primary" onclick="openStockModal(<?php echo $product['id']; ?>, '<?php echo $product['name']; ?>')">
                                        <i class="fas fa-edit"></i> Update
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Reports Tab -->
        <div id="reports" class="tab-content">
            <div class="chart-container">
                <h3><i class="fas fa-chart-line"></i> Sales Trends</h3>
                <canvas id="salesChart" height="300"></canvas>
            </div>

            <div class="grid-2-col">
                <div class="table-container">
                    <h3><i class="fas fa-star"></i> Popular Products</h3>
                    <table>
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>Total Ordered</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($popularProducts as $product): ?>
                                <tr>
                                    <td>
                                        <div style="display: flex; align-items: center;">
                                            <img src="../assets/images/products/<?php echo $product['image']; ?>" alt="<?php echo $product['name']; ?>" style="width: 40px; height: 40px; object-fit: cover; margin-right: 10px;">
                                            <?php echo $product['name']; ?>
                                        </div>
                                    </td>
                                    <td><?php echo $product['total_ordered']; ?></td>
                                    <td>
                                        <a href="products.php?edit=<?php echo $product['id']; ?>" class="btn btn-primary">
                                            <i class="fas fa-edit"></i> Edit
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="table-container">
                    <h3><i class="fas fa-users"></i> Frequent Customers</h3>
                    <table>
                        <thead>
                            <tr>
                                <th>Customer</th>
                                <th>Orders</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($frequentCustomers as $customer): ?>
                                <tr>
                                    <td><?php echo $customer['firstname'] . ' ' . $customer['lastname']; ?><br><small><?php echo $customer['email']; ?></small></td>
                                    <td><?php echo $customer['order_count']; ?></td>
                                    <td>
                                        <a href="customers.php?id=<?php echo $customer['id']; ?>" class="btn btn-primary">
                                            <i class="fas fa-eye"></i> View
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Add Product Tab -->
        <div id="add-product" class="tab-content">
            <div class="table-container">
                <h3><i class="fas fa-plus-circle"></i> Add New Product</h3>
                <form method="POST" enctype="multipart/form-data">
                    <div class="form-group">
                        <label for="name">Product Name</label>
                        <input type="text" id="name" name="name" class="form-control" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="description">Description</label>
                        <textarea id="description" name="description" class="form-control" rows="3" required></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label for="price">Price</label>
                        <input type="number" id="price" name="price" step="0.01" min="0" class="form-control" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="category">Category</label>
                        <select id="category" name="category" class="form-control" required>
                            <option value="">Select Category</option>
                            <option value="men_tshirts">Men's T-Shirts</option>
                            <option value="men_polos">Men's Polo Shirts</option>
                            <option value="men_pants">Men's Pants</option>
                            <option value="men_hoodies">Men's Hoodies</option>
                            <option value="women_dresses">Women's Dresses</option>
                            <option value="women_tops">Women's Tops</option>
                            <option value="women_blouses">Women's Blouses</option>
                            <option value="women_pants">Women's Pants</option>
                            <option value="shoes">Shoes</option>
                            <option value="access_eyewear">Eyewear</option>
                            <option value="access_necklace">Necklace</option>
                            <option value="access_watch">Watch</option>
                            <option value="access_wallet">Wallet</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="stock">Initial Stock</label>
                        <input type="number" id="stock" name="stock" min="0" class="form-control" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="image">Product Image</label>
                        <input type="file" id="image" name="image" class="form-control" accept="image/*">
                    </div>
                    
                    <button type="submit" name="add_product" class="btn btn-success">
                        <i class="fas fa-save"></i> Add Product
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Stock Update Modal -->
    <div id="stockModal" class="modal" style="display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5);">
        <div style="background-color: white; margin: 10% auto; padding: 20px; border-radius: 8px; width: 400px; max-width: 90%;">
            <h3 id="modalTitle" style="margin-bottom: 20px;"></h3>
            <form id="stockForm" method="POST">
                <input type="hidden" id="product_id" name="product_id">
                <div class="form-group">
                    <label for="stock_change">Stock Adjustment</label>
                    <div style="display: flex; align-items: center;">
                        <button type="button" class="btn btn-primary" onclick="adjustStock(-1)">-</button>
                        <input type="number" id="stock_change" name="stock_change" value="0" min="-1000" max="1000" class="form-control" style="margin: 0 10px; text-align: center;">
                        <button type="button" class="btn btn-primary" onclick="adjustStock(1)">+</button>
                    </div>
                    <small>Positive numbers add stock, negative numbers remove stock</small>
                </div>
                <div style="display: flex; justify-content: flex-end; margin-top: 20px;">
                    <button type="button" class="btn btn-danger" style="margin-right: 10px;" onclick="closeStockModal()">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button type="submit" name="update_stock" class="btn btn-success">
                        <i class="fas fa-save"></i> Update Stock
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Switch between tabs
        function switchTab(tabId) {
            document.querySelectorAll('.tab').forEach(tab => {
                tab.classList.remove('active');
            });
            
            document.querySelectorAll('.tab-content').forEach(content => {
                content.classList.remove('active');
            });
            
            document.querySelector(`.tab[onclick="switchTab('${tabId}')"]`).classList.add('active');
            document.getElementById(tabId).classList.add('active');
        }
        
        // Stock modal functions
        function openStockModal(productId, productName) {
            document.getElementById('product_id').value = productId;
            document.getElementById('modalTitle').textContent = `Update Stock: ${productName}`;
            document.getElementById('stock_change').value = 0;
            document.getElementById('stockModal').style.display = 'block';
        }
        
        function closeStockModal() {
            document.getElementById('stockModal').style.display = 'none';
        }
        
        function adjustStock(change) {
            const input = document.getElementById('stock_change');
            let value = parseInt(input.value) + change;
            if (value < -1000) value = -1000;
            if (value > 1000) value = 1000;
            input.value = value;
        }
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            if (event.target == document.getElementById('stockModal')) {
                closeStockModal();
            }
        }
        
        // Inventory search
        document.getElementById('inventorySearch').addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase();
            const rows = document.querySelectorAll('#inventoryTable tbody tr');
            
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(searchTerm) ? '' : 'none';
            });
        });
        
        // Initialize sales chart
        document.addEventListener('DOMContentLoaded', function() {
            const ctx = document.getElementById('salesChart').getContext('2d');
            const salesChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: <?php echo json_encode(array_column(array_reverse($orderVolume), 'month')); ?>,
                    datasets: [
                        {
                            label: 'Order Volume',
                            data: <?php echo json_encode(array_column(array_reverse($orderVolume), 'order_count')); ?>,
                            borderColor: '#4361ee',
                            backgroundColor: 'rgba(67, 97, 238, 0.1)',
                            tension: 0.3,
                            yAxisID: 'y'
                        },
                        {
                            label: 'Revenue ($)',
                            data: <?php echo json_encode(array_column(array_reverse($orderVolume), 'revenue')); ?>,
                            borderColor: '#4cc9f0',
                            backgroundColor: 'rgba(76, 201, 240, 0.1)',
                            tension: 0.3,
                            yAxisID: 'y1'
                        }
                    ]
                },
                options: {
                    responsive: true,
                    interaction: {
                        mode: 'index',
                        intersect: false,
                    },
                    scales: {
                        y: {
                            type: 'linear',
                            display: true,
                            position: 'left',
                            title: {
                                display: true,
                                text: 'Order Volume'
                            }
                        },
                        y1: {
                            type: 'linear',
                            display: true,
                            position: 'right',
                            title: {
                                display: true,
                                text: 'Revenue ($)'
                            },
                            grid: {
                                drawOnChartArea: false
                            }
                        }
                    }
                }
            });
        });
        
        // Active sidebar link highlighting
        document.addEventListener('DOMContentLoaded', function() {
            const currentPage = window.location.pathname.split('/').pop();
            const links = document.querySelectorAll('.admin-sidebar ul li a');
            
            links.forEach(link => {
                const linkPage = link.getAttribute('href');
                if (currentPage === linkPage) {
                    link.classList.add('active');
                }
            });
        });
    </script>
</body>
</html>