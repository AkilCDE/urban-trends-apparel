<?php
require_once '../Database/datab.php';

session_start();

if (!isset($_SESSION['user_id']) || !isset($_SESSION['is_admin']) || $_SESSION['is_admin'] != 1) {
    header("Location: ../login.php");
    exit;
}

class ProductManager {
    private $db;
    
    public function __construct($db) {
        $this->db = $db;
    }
    
    public function getAllProducts($category = null, $search = null) {
        try {
            $query = "
                SELECT p.*, 
                       GROUP_CONCAT(CONCAT(pv.size, ' (', pv.stock, ')') ORDER BY 
                       FIELD(pv.size, 'XS','S','M','L','XL','XXL') SEPARATOR ', ') as size_info
                FROM products p
                LEFT JOIN product_variations pv ON p.product_id = pv.product_id
            ";
            $conditions = [];
            $params = [];
            
            if ($category) {
                $conditions[] = "p.category = ?";
                $params[] = $category;
            }
            
            if ($search) {
                $conditions[] = "(p.name LIKE ? OR p.description LIKE ?)";
                $params[] = "%$search%";
                $params[] = "%$search%";
            }
            
            if (!empty($conditions)) {
                $query .= " WHERE " . implode(" AND ", $conditions);
            }
            
            $query .= " GROUP BY p.product_id ORDER BY p.created_at DESC";
            
            $stmt = $this->db->prepare($query);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch(PDOException $e) {
            error_log("Error getting products: " . $e->getMessage());
            return [];
        }
    }
    
    public function getProductById($product_id) {
        try {
            $stmt = $this->db->prepare("
                SELECT p.*, 
                       GROUP_CONCAT(CONCAT(pv.size, '|', pv.stock, '|', pv.price_adjustment, '|', pv.variation_id) 
                           ORDER BY FIELD(pv.size, 'XS','S','M','L','XL','XXL') SEPARATOR ';') as variations
                FROM products p
                LEFT JOIN product_variations pv ON p.product_id = pv.product_id
                WHERE p.product_id = ?
                GROUP BY p.product_id
            ");
            $stmt->execute([$product_id]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch(PDOException $e) {
            error_log("Error getting product: " . $e->getMessage());
            return null;
        }
    }
    
    public function addProduct($product_data, $variations) {
        try {
            $this->db->beginTransaction();
            
            $stmt = $this->db->prepare("
                INSERT INTO products (name, description, price, category, image) 
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $product_data['name'],
                $product_data['description'],
                $product_data['price'],
                $product_data['category'],
                $product_data['image']
            ]);
            $product_id = $this->db->lastInsertId();
            
            foreach ($variations as $variation) {
                $stmt = $this->db->prepare("
                    INSERT INTO product_variations (product_id, size, stock, price_adjustment, is_default) 
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $product_id,
                    $variation['size'],
                    $variation['stock'],
                    $variation['price_adjustment'],
                    $variation['size'] == 'M' ? 1 : 0
                ]);
            }
            
            $this->db->commit();
            return $product_id;
        } catch(PDOException $e) {
            $this->db->rollBack();
            error_log("Error adding product: " . $e->getMessage());
            return false;
        }
    }
    
    public function updateProduct($product_id, $product_data, $variations) {
        try {
            $this->db->beginTransaction();
            
            $stmt = $this->db->prepare("
                UPDATE products SET 
                    name = ?, 
                    description = ?, 
                    price = ?, 
                    category = ?, 
                    image = ? 
                WHERE product_id = ?
            ");
            $stmt->execute([
                $product_data['name'],
                $product_data['description'],
                $product_data['price'],
                $product_data['category'],
                $product_data['image'],
                $product_id
            ]);
            
            // Update existing variations and add new ones
            if (isset($variations['existing'])) {
                foreach ($variations['existing'] as $variation) {
                    $stmt = $this->db->prepare("
                        UPDATE product_variations SET 
                            stock = ?, 
                            price_adjustment = ? 
                        WHERE variation_id = ? AND product_id = ?
                    ");
                    $stmt->execute([
                        $variation['stock'],
                        $variation['price_adjustment'],
                        $variation['variation_id'],
                        $product_id
                    ]);
                }
            }
            
            if (isset($variations['new'])) {
                foreach ($variations['new'] as $variation) {
                    $stmt = $this->db->prepare("
                        INSERT INTO product_variations (product_id, size, stock, price_adjustment, is_default) 
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $product_id,
                        $variation['size'],
                        $variation['stock'],
                        $variation['price_adjustment'],
                        $variation['size'] == 'M' ? 1 : 0
                    ]);
                }
            }
            
            $this->db->commit();
            return true;
        } catch(PDOException $e) {
            $this->db->rollBack();
            error_log("Error updating product: " . $e->getMessage());
            return false;
        }
    }
    
    public function deleteProduct($product_id) {
        try {
            $this->db->beginTransaction();
            
            // First check if product exists
            $stmt = $this->db->prepare("SELECT * FROM products WHERE product_id = ?");
            $stmt->execute([$product_id]);
            $product = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$product) {
                throw new Exception("Product not found");
            }
            
            // Delete from cart first
            $stmt = $this->db->prepare("DELETE FROM cart WHERE product_id = ?");
            $stmt->execute([$product_id]);
            
            // Delete from wishlist
            $stmt = $this->db->prepare("DELETE FROM wishlist WHERE product_id = ?");
            $stmt->execute([$product_id]);
            
            // Delete from order_items
            $stmt = $this->db->prepare("DELETE FROM order_items WHERE product_id = ?");
            $stmt->execute([$product_id]);
            
            // Delete product variations
            $stmt = $this->db->prepare("DELETE FROM product_variations WHERE product_id = ?");
            $stmt->execute([$product_id]);
            
            // Finally delete the product
            $stmt = $this->db->prepare("DELETE FROM products WHERE product_id = ?");
            $stmt->execute([$product_id]);
            
            $this->db->commit();
            
            // Delete the image file if it exists and is not the default image
            if ($product['image'] && $product['image'] != 'default.jpg') {
                $image_path = '../assets/images/products/' . $product['image'];
                if (file_exists($image_path)) {
                    @unlink($image_path);
                }
            }
            
            return true;
        } catch(PDOException $e) {
            $this->db->rollBack();
            error_log("Error deleting product: " . $e->getMessage());
            return false;
        } catch(Exception $e) {
            $this->db->rollBack();
            error_log("Error deleting product: " . $e->getMessage());
            return false;
        }
    }
    
    public function getCategories() {
        try {
            $stmt = $this->db->query("SELECT DISTINCT category FROM products ORDER BY category");
            return $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch(PDOException $e) {
            error_log("Error getting categories: " . $e->getMessage());
            return [];
        }
    }
    
    public function updateStock($variation_id, $stock_change) {
        try {
            $this->db->beginTransaction();
            
            // First check current stock
            $stmt = $this->db->prepare("
                SELECT pv.stock, p.name, pv.size 
                FROM product_variations pv
                JOIN products p ON p.product_id = pv.product_id
                WHERE pv.variation_id = ?
            ");
            $stmt->execute([$variation_id]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$result) {
                throw new Exception("Variation not found");
            }
            
            $current_stock = $result['stock'];
            $new_stock = $current_stock + $stock_change;
            
            // Prevent negative stock
            if ($new_stock < 0) {
                throw new Exception("Cannot reduce stock below 0. Current stock: {$current_stock}");
            }
            
            // Update the stock
            $stmt = $this->db->prepare("
                UPDATE product_variations 
                SET stock = ?, 
                    last_updated = NOW() 
                WHERE variation_id = ?
            ");
            $stmt->execute([$new_stock, $variation_id]);
            
            // Log the stock change
            $stmt = $this->db->prepare("
                INSERT INTO inventory_log 
                (variation_id, previous_stock, change_amount, new_stock, changed_by, change_type) 
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $variation_id,
                $current_stock,
                $stock_change,
                $new_stock,
                $_SESSION['user_id'],
                $stock_change > 0 ? 'addition' : 'reduction'
            ]);
            
            $this->db->commit();
            
            return [
                'success' => true,
                'message' => "Stock updated successfully for {$result['name']} (Size: {$result['size']})",
                'new_stock' => $new_stock
            ];
            
        } catch (Exception $e) {
            $this->db->rollBack();
            error_log("Stock update error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    public function getVariations($product_id) {
        try {
            // First get the product category
            $stmt = $this->db->prepare("SELECT category FROM products WHERE product_id = ?");
            $stmt->execute([$product_id]);
            $category = $stmt->fetchColumn();
            
            // Determine if this is a shoe product
            $is_shoe = strpos($category, 'shoes') === 0;
            
            if ($is_shoe) {
                // For shoes, order sizes numerically
                $stmt = $this->db->prepare("
                    SELECT variation_id, size, stock, price_adjustment 
                    FROM product_variations 
                    WHERE product_id = ?
                    ORDER BY CAST(size AS UNSIGNED)
                ");
            } else {
                // For other products, order sizes alphabetically
                $stmt = $this->db->prepare("
                    SELECT variation_id, size, stock, price_adjustment 
                    FROM product_variations 
                    WHERE product_id = ?
                    ORDER BY FIELD(size, 'XS','S','M','L','XL','XXL')
                ");
            }
            $stmt->execute([$product_id]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch(PDOException $e) {
            error_log("Error getting variations: " . $e->getMessage());
            return [];
        }
    }
}

$productManager = new ProductManager($db);

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Add new product
    if (isset($_POST['add_product'])) {
        $product_data = [
            'name' => htmlspecialchars($_POST['name']),
            'description' => htmlspecialchars($_POST['description']),
            'price' => floatval($_POST['price']),
            'category' => htmlspecialchars($_POST['category']),
            'image' => 'default.jpg'
        ];
        
        $variations = [];
        if (isset($_POST['size']) && is_array($_POST['size'])) {
            for ($i = 0; $i < count($_POST['size']); $i++) {
                $variations[] = [
                    'size' => $_POST['size'][$i],
                    'stock' => intval($_POST['stock'][$i]),
                    'price_adjustment' => floatval($_POST['price_adjustment'][$i] ?? 0)
                ];
            }
        }
        
        // Handle image upload
        if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $image = $_FILES['image'];
            $image_name = time() . '_' . $image['name'];
            move_uploaded_file($image['tmp_name'], "../assets/images/products/" . $image_name);
            $product_data['image'] = $image_name;
        }
        
        if ($productManager->addProduct($product_data, $variations)) {
            $_SESSION['success_message'] = "Product added successfully!";
            header("Location: products.php");
            exit;
        } else {
            $_SESSION['error_message'] = "Failed to add product.";
        }
    }
    
    // Update product
    if (isset($_POST['update_product'])) {
        $product_id = intval($_POST['product_id']);
        $product_data = [
            'name' => htmlspecialchars($_POST['name']),
            'description' => htmlspecialchars($_POST['description']),
            'price' => floatval($_POST['price']),
            'category' => htmlspecialchars($_POST['category']),
            'image' => $_POST['current_image']
        ];
        
        $variations = ['existing' => [], 'new' => []];
        if (isset($_POST['variation_id']) && is_array($_POST['variation_id'])) {
            for ($i = 0; $i < count($_POST['variation_id']); $i++) {
                $variations['existing'][] = [
                    'variation_id' => intval($_POST['variation_id'][$i]),
                    'stock' => intval($_POST['existing_stock'][$i]),
                    'price_adjustment' => floatval($_POST['existing_price_adjustment'][$i] ?? 0)
                ];
            }
        }
        
        if (isset($_POST['new_size']) && is_array($_POST['new_size'])) {
            for ($i = 0; $i < count($_POST['new_size']); $i++) {
                $variations['new'][] = [
                    'size' => $_POST['new_size'][$i],
                    'stock' => intval($_POST['new_stock'][$i]),
                    'price_adjustment' => floatval($_POST['new_price_adjustment'][$i] ?? 0)
                ];
            }
        }
        
        // Handle image upload
        if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $image = $_FILES['image'];
            $image_name = time() . '_' . $image['name'];
            move_uploaded_file($image['tmp_name'], "../assets/images/products/" . $image_name);
            $product_data['image'] = $image_name;
        }
        
        if ($productManager->updateProduct($product_id, $product_data, $variations)) {
            $_SESSION['success_message'] = "Product updated successfully!";
            header("Location: products.php");
            exit;
        } else {
            $_SESSION['error_message'] = "Failed to update product.";
        }
    }
    
    // Update stock
    if (isset($_POST['update_stock'])) {
        $variation_id = intval($_POST['variation_id']);
        $stock_change = intval($_POST['stock_change']);
        
        $result = $productManager->updateStock($variation_id, $stock_change);
        header('Content-Type: application/json');
        echo json_encode($result);
        exit;
    }
}

// Handle product deletion
if (isset($_GET['delete_product'])) {
    $product_id = intval($_GET['delete_product']);
    $product = $productManager->getProductById($product_id);
    
    if ($product && $productManager->deleteProduct($product_id)) {
        if ($product['image'] != 'default.jpg') {
            @unlink('../assets/images/products/' . $product['image']);
        }
        $_SESSION['success_message'] = "Product deleted successfully!";
    } else {
        $_SESSION['error_message'] = "Failed to delete product.";
    }
    header("Location: products.php");
    exit;
}

// Get filters
$category_filter = isset($_GET['category']) ? $_GET['category'] : null;
$search_filter = isset($_GET['search']) ? $_GET['search'] : null;

// Get all products with filters
$products = $productManager->getAllProducts($category_filter, $search_filter);

// Get all categories
$categories = $productManager->getCategories();

// Get product details if editing
$product = null;
if (isset($_GET['edit'])) {
    $product = $productManager->getProductById($_GET['edit']);
    if (!$product) {
        $_SESSION['error_message'] = "Product not found.";
        header("Location: products.php");
        exit;
    }
}

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
    <title>Urban Trends Apparel - Product Management</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
        
        .status.low-stock {
            background-color: #FFE3E3;
            color: #C92A2A;
        }
        
        .status.warning {
            background-color: #FFF3CD;
            color: #856404;
        }
        
        .status.success {
            background-color: #D4EDDA;
            color: #155724;
        }
        
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
        
        .btn-sm {
            padding: 4px 8px;
            font-size: 0.75rem;
        }
        
        .btn-secondary {
            background-color: #6c757d;
            color: white;
        }
        
        .btn-secondary:hover {
            background-color: #5a6268;
        }
        
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
        
        .product-details {
            background-color: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .product-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid #eee;
        }
        
        .product-header h3 {
            color: var(--dark-color);
        }
        
        .product-image-container {
            margin-top: 20px;
            text-align: center;
        }
        
        .product-image {
            max-width: 300px;
            max-height: 300px;
            object-fit: contain;
            border-radius: 4px;
        }
        
        .filters {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
            background-color: white;
            padding: 15px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: rgba(0,0,0,0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
        }

        .modal-overlay.active {
            opacity: 1;
            visibility: visible;
        }

        .modal-dialog {
            background-color: white;
            border-radius: 8px;
            width: 90%;
            max-width: 600px;
            margin: 20px auto;
            position: relative;
            animation: modalSlideIn 0.3s ease;
        }

        @keyframes modalSlideIn {
            from {
                transform: translateY(-20px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .modal-header {
            position: sticky;
            top: 0;
            background: white;
            z-index: 1;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 20px;
            border-bottom: 1px solid #dee2e6;
            border-radius: 8px 8px 0 0;
        }

        .modal-body {
            padding: 20px;
            max-height: calc(100vh - 180px);
            overflow-y: auto;
        }

        .modal-footer {
            position: sticky;
            bottom: 0;
            background: white;
            padding: 15px 20px;
            border-top: 1px solid #dee2e6;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            border-radius: 0 0 8px 8px;
        }

        .size-variation {
            display: flex;
            gap: 10px;
            margin-bottom: 10px;
            align-items: center;
        }

        .size-variation select,
        .size-variation input {
            flex: 1;
        }

        .size-variation .btn-danger {
            flex: 0 0 auto;
        }

        .close {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            padding: 0;
            color: #666;
            transition: color 0.3s;
        }

        .close:hover {
            color: #000;
        }

        .mt-2 {
            margin-top: 0.5rem;
        }

        /* Scrollbar Styling */
        .modal-body::-webkit-scrollbar {
            width: 8px;
        }

        .modal-body::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 4px;
        }

        .modal-body::-webkit-scrollbar-thumb {
            background: #888;
            border-radius: 4px;
        }

        .modal-body::-webkit-scrollbar-thumb:hover {
            background: #555;
        }

        /* Mobile Responsiveness */
        @media (max-width: 768px) {
            .modal-overlay {
                padding: 10px;
            }

            .modal-dialog {
                width: 100%;
                margin: 10px 0;
            }

            .modal-body {
                max-height: calc(100vh - 140px);
            }

            .size-variation {
                flex-direction: column;
            }

            .size-variation select,
            .size-variation input,
            .size-variation button {
                width: 100%;
                margin-bottom: 5px;
            }
        }
    </style>
</head>
<body>
    <div class="admin-sidebar">
        <div class="admin-sidebar-header">
            <h2><i class="fas fa-crown"></i> <span>Admin Panel</span></h2>
        </div>
        <ul>
            <li><a href="dashboard.php"><i class="fas fa-tachometer-alt"></i> <span>Dashboard</span></a></li>
            <li><a href="products.php" class="active"><i class="fas fa-tshirt"></i> <span>Products</span></a></li>
            <li><a href="orders.php"><i class="fas fa-shopping-bag"></i> <span>Orders</span></a></li>
            <li><a href="customers.php"><i class="fas fa-users"></i> <span>Customers</span></a></li>
            <li><a href="manage_promo_codes.php"><i class="fas fa-percent"></i> <span>Promo Codes</span></a></li>
            <li><a href="reports.php"><i class="fas fa-chart-bar"></i> <span>Reports</span></a></li>
        </ul>
    </div>

    <div class="admin-main">
        <div class="admin-header">
            <h2><i class="fas fa-tshirt"></i> <?php echo isset($product) ? "Edit Product" : "Product Management"; ?></h2>
            <div class="admin-actions">
                <button class="btn btn-primary" onclick="showAddModal()">
                    <i class="fas fa-plus"></i> Add Product
                </button>
                <a href="../index.php"><i class="fas fa-home"></i> View Site</a>
                <a href="?logout=1"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </div>
        </div>

        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="alert alert-success">
                <?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?>
            </div>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="alert alert-danger">
                <?php echo $_SESSION['error_message']; unset($_SESSION['error_message']); ?>
            </div>
        <?php endif; ?>

        <!-- Add Product Modal -->
        <div id="addProductModal" class="modal-overlay">
            <div class="modal-dialog">
                <div class="modal-header">
                    <h3><i class="fas fa-plus"></i> Add New Product</h3>
                    <button type="button" class="close" onclick="closeModal('addProductModal')">&times;</button>
                </div>
                <div class="modal-body">
                    <form id="addProductForm" method="POST" enctype="multipart/form-data">
                        <div class="form-group">
                            <label for="name">Product Name</label>
                            <input type="text" id="name" name="name" class="form-control" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="description">Description</label>
                            <textarea id="description" name="description" class="form-control" rows="3" required></textarea>
                        </div>
                        
                        <div class="form-group">
                            <label for="category">Category</label>
                            <select id="category" name="category" class="form-control" required onchange="updateSizeOptions()">
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
                            <label for="price">Base Price (₱)</label>
                            <input type="number" id="price" name="price" class="form-control" step="0.01" min="0" required>
                        </div>
                        
                        <div class="form-group">
                            <label>Size Variations</label>
                            <div id="sizeVariationsContainer">
                                <div class="size-variation">
                                    <select name="size[]" class="form-control size-select" required>
                                        <option value="XS">XS</option>
                                        <option value="S">S</option>
                                        <option value="M" selected>M</option>
                                        <option value="L">L</option>
                                        <option value="XL">XL</option>
                                        <option value="XXL">XXL</option>
                                    </select>
                                    <input type="number" name="stock[]" class="form-control" placeholder="Stock" required min="0">
                                    <input type="number" name="price_adjustment[]" class="form-control" placeholder="Price Adjustment" step="0.01" value="0">
                                    <button type="button" class="btn btn-danger remove-size"><i class="fas fa-times"></i></button>
                                </div>
                            </div>
                            <button type="button" class="btn btn-secondary mt-2" onclick="addSizeVariation()">
                                <i class="fas fa-plus"></i> Add Size
                            </button>
                        </div>
                        
                        <div class="form-group">
                            <label for="image">Product Image</label>
                            <input type="file" id="image" name="image" class="form-control" accept="image/*" required>
                            <small class="text-muted">Recommended size: 800x800 pixels</small>
                        </div>
                        
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" onclick="closeModal('addProductModal')">
                                <i class="fas fa-times"></i> Cancel
                            </button>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Save Product
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <script>
        function updateSizeOptions() {
            const category = document.getElementById('category').value;
            const sizeContainer = document.getElementById('sizeVariationsContainer');
            const addSizeBtn = document.querySelector('.btn-secondary.mt-2');
            
            // Clear existing variations
            while (sizeContainer.firstChild) {
                sizeContainer.removeChild(sizeContainer.firstChild);
            }
            
            if (category.startsWith('access_')) {
                // For accessories, only show stock input
                const variation = document.createElement('div');
                variation.className = 'size-variation';
                variation.innerHTML = `
                    <input type="hidden" name="size[]" value="One Size">
                    <input type="number" name="stock[]" class="form-control" placeholder="Stock" required min="0" style="flex: 1;">
                    <input type="number" name="price_adjustment[]" class="form-control" placeholder="Price Adjustment" step="0.01" value="0">
                `;
                sizeContainer.appendChild(variation);
                // Hide the "Add Size" button for accessories
                addSizeBtn.style.display = 'none';
            } else {
                // For other categories, show size variations
                addSizeBtn.style.display = 'block';
                const variation = document.createElement('div');
                variation.className = 'size-variation';
                
                if (category === 'shoes') {
                    // Shoe sizes
                    variation.innerHTML = `
                        <select name="size[]" class="form-control size-select" required>
                            ${Array.from({length: 16}, (_, i) => i + 35)
                                .map(size => `<option value="${size}">${size}</option>`)
                                .join('')}
                        </select>
                        <input type="number" name="stock[]" class="form-control" placeholder="Stock" required min="0">
                        <input type="number" name="price_adjustment[]" class="form-control" placeholder="Price Adjustment" step="0.01" value="0">
                        <button type="button" class="btn btn-danger remove-size"><i class="fas fa-times"></i></button>
                    `;
                } else {
                    // Clothing sizes
                    variation.innerHTML = `
                        <select name="size[]" class="form-control size-select" required>
                            <option value="XS">XS</option>
                            <option value="S">S</option>
                            <option value="M" selected>M</option>
                            <option value="L">L</option>
                            <option value="XL">XL</option>
                            <option value="XXL">XXL</option>
                        </select>
                        <input type="number" name="stock[]" class="form-control" placeholder="Stock" required min="0">
                        <input type="number" name="price_adjustment[]" class="form-control" placeholder="Price Adjustment" step="0.01" value="0">
                        <button type="button" class="btn btn-danger remove-size"><i class="fas fa-times"></i></button>
                    `;
                }
                sizeContainer.appendChild(variation);
            }
        }

        function showAddModal() {
            document.getElementById('addProductModal').classList.add('active');
            document.body.style.overflow = 'hidden';
            // Initialize size options based on default category
            updateSizeOptions();
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('active');
            document.body.style.overflow = '';
            // Reset form
            document.getElementById('addProductForm').reset();
            // Reset size variations
            updateSizeOptions();
        }

        function addSizeVariation() {
            const category = document.getElementById('category').value;
            if (category.startsWith('access_')) {
                return; // Don't add size variations for accessories
            }
            
            const container = document.getElementById('sizeVariationsContainer');
            const newVariation = document.createElement('div');
            newVariation.className = 'size-variation';
            
            if (category === 'shoes') {
                // Shoe sizes
                newVariation.innerHTML = `
                    <select name="size[]" class="form-control size-select" required>
                        ${Array.from({length: 16}, (_, i) => i + 35)
                            .map(size => `<option value="${size}">${size}</option>`)
                            .join('')}
                    </select>
                    <input type="number" name="stock[]" class="form-control" placeholder="Stock" required min="0">
                    <input type="number" name="price_adjustment[]" class="form-control" placeholder="Price Adjustment" step="0.01" value="0">
                    <button type="button" class="btn btn-danger remove-size"><i class="fas fa-times"></i></button>
                `;
            } else {
                // Clothing sizes
                newVariation.innerHTML = `
                    <select name="size[]" class="form-control size-select" required>
                        <option value="XS">XS</option>
                        <option value="S">S</option>
                        <option value="M" selected>M</option>
                        <option value="L">L</option>
                        <option value="XL">XL</option>
                        <option value="XXL">XXL</option>
                    </select>
                    <input type="number" name="stock[]" class="form-control" placeholder="Stock" required min="0">
                    <input type="number" name="price_adjustment[]" class="form-control" placeholder="Price Adjustment" step="0.01" value="0">
                    <button type="button" class="btn btn-danger remove-size"><i class="fas fa-times"></i></button>
                `;
            }
            
            container.appendChild(newVariation);
        }

        // Remove size variation
        document.addEventListener('click', function(e) {
            if (e.target.classList.contains('remove-size') || e.target.parentElement.classList.contains('remove-size')) {
                const btn = e.target.classList.contains('remove-size') ? e.target : e.target.parentElement;
                const container = document.getElementById('sizeVariationsContainer');
                const category = document.getElementById('category').value;
                
                if (category.startsWith('access_')) {
                    return; // Don't remove the only stock input for accessories
                }
                
                if (container.children.length > 1) {
                    btn.closest('.size-variation').remove();
                } else {
                    alert('At least one size variation is required.');
                }
            }
        });

        // Handle form submission
        document.getElementById('addProductForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            // Validate form
            const category = document.getElementById('category').value;
            if (!category) {
                alert('Please select a category');
                return;
            }
            
            const formData = new FormData(this);
            formData.append('add_product', '1');

            // Show loading state
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
            submitBtn.disabled = true;

            fetch('products.php', {
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (response.redirected) {
                    window.location = response.url;
                } else {
                    return response.text().then(text => {
                        try {
                            return JSON.parse(text);
                        } catch (e) {
                            // If response is not JSON, reload the page
                            window.location.reload();
                        }
                    });
                }
            })
            .then(data => {
                if (data && data.success) {
                    alert(data.message || 'Product added successfully!');
                    window.location = 'products.php';
                } else if (data && !data.success) {
                    alert(data.message || 'Error adding product');
                    submitBtn.innerHTML = originalText;
                    submitBtn.disabled = false;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error adding product. Please try again.');
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            });
        });
        </script>

        <?php if (isset($_GET['add_new']) || isset($product)): ?>
            <div class="product-details">
                <div class="product-header">
                    <h3><?php echo isset($product) ? "Edit Product" : "Add New Product"; ?></h3>
                </div>
                
                <form method="POST" enctype="multipart/form-data">
                    <?php if (isset($product)): ?>
                        <input type="hidden" name="product_id" value="<?php echo $product['product_id']; ?>">
                        <input type="hidden" name="current_image" value="<?php echo $product['image']; ?>">
                    <?php endif; ?>
                    
                    <div class="form-group">
                        <label for="name">Product Name</label>
                        <input type="text" id="name" name="name" class="form-control" required 
                               value="<?php echo isset($product) ? htmlspecialchars($product['name']) : ''; ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="description">Description</label>
                        <textarea id="description" name="description" class="form-control" rows="3" required><?php 
                            echo isset($product) ? htmlspecialchars($product['description']) : ''; 
                        ?></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label for="price">Base Price (₱)</label>
                        <input type="number" id="price" name="price" step="0.01" min="0" class="form-control" required 
                               value="<?php echo isset($product) ? $product['price'] : ''; ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="category">Category</label>
                        <select id="category" name="category" class="form-control" required>
                            <option value="">Select Category</option>
                            <?php foreach ($categories as $cat): 
                                $displayName = ucfirst(str_replace('_', ' ', $cat));
                            ?>
                                <option value="<?php echo $cat; ?>" <?php 
                                    echo (isset($product) && $product['category'] == $cat) ? 'selected' : ''; 
                                ?>>
                                    <?php echo $displayName; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <?php if (isset($product)): ?>
                        <div class="form-group">
                            <label>Existing Size Variations</label>
                            <div class="existing-variations-container">
                                <?php
                                if ($product && $product['variations']) {
                                    $variations = explode(';', $product['variations']);
                                    foreach ($variations as $variation) {
                                        list($size, $stock, $price_adjustment, $variation_id) = explode('|', $variation);
                                ?>
                                    <div class="size-variation">
                                        <input type="hidden" name="variation_id[]" value="<?php echo $variation_id; ?>">
                                        <input type="text" value="<?php echo $size; ?>" class="form-control" disabled>
                                        <input type="number" name="existing_price_adjustment[]" step="0.01" 
                                               value="<?php echo $price_adjustment; ?>" placeholder="Price Adjustment" class="form-control">
                                        <input type="number" name="existing_stock[]" min="0" 
                                               value="<?php echo $stock; ?>" placeholder="Stock" class="form-control" required>
                                    </div>
                                <?php
                                    }
                                } else {
                                    echo '<p>No sizes available. Add new sizes below.</p>';
                                }
                                ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <div class="form-group">
                        <label><?php echo isset($product) ? 'Add New Size Variations' : 'Size Variations'; ?></label>
                        <div class="size-variations-container">
                            <div class="size-variation">
                                <?php 
                                $is_shoe = isset($product) && strpos($product['category'], 'shoes') === 0;
                                if ($is_shoe): 
                                ?>
                                <select name="<?php echo isset($product) ? 'new_size[]' : 'size[]'; ?>" class="form-control">
                                    <option value="35">35</option>
                                    <option value="36">36</option>
                                    <option value="37">37</option>
                                    <option value="38">38</option>
                                    <option value="39">39</option>
                                    <option value="40">40</option>
                                    <option value="41">41</option>
                                    <option value="42">42</option>
                                    <option value="43">43</option>
                                    <option value="44">44</option>
                                    <option value="45">45</option>
                                    <option value="46">46</option>
                                    <option value="47">47</option>
                                    <option value="48">48</option>
                                    <option value="49">49</option>
                                    <option value="50">50</option>
                                </select>
                                <?php else: ?>
                                <select name="<?php echo isset($product) ? 'new_size[]' : 'size[]'; ?>" class="form-control">
                                    <option value="XS">XS</option>
                                    <option value="S">S</option>
                                    <option value="M" selected>M</option>
                                    <option value="L">L</option>
                                    <option value="XL">XL</option>
                                    <option value="XXL">XXL</option>
                                </select>
                                <?php endif; ?>
                                <input type="number" name="<?php echo isset($product) ? 'new_price_adjustment[]' : 'price_adjustment[]'; ?>" 
                                       step="0.01" placeholder="Price Adjustment" class="form-control">
                                <input type="number" name="<?php echo isset($product) ? 'new_stock[]' : 'stock[]'; ?>" 
                                       min="0" placeholder="Stock" class="form-control" required>
                                <button type="button" class="btn btn-danger remove-size"><i class="fas fa-times"></i></button>
                            </div>
                        </div>
                        <button type="button" class="btn btn-primary add-size-variation" style="margin-top: 10px;">
                            <i class="fas fa-plus"></i> Add Another Size
                        </button>
                    </div>
                    
                    <div class="form-group">
                        <label for="image">Product Image</label>
                        <input type="file" id="image" name="image" class="form-control" accept="image/*">
                        <?php if (isset($product) && $product['image'] != 'default.jpg'): ?>
                            <div class="product-image-container">
                                <img src="../assets/images/products/<?php echo $product['image']; ?>" alt="Current Image" class="product-image">
                                <p><small>Current image: <?php echo $product['image']; ?></small></p>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="form-actions" style="margin-top: 20px;">
                        <a href="products.php" class="btn btn-secondary">
                            <i class="fas fa-arrow-left"></i> Cancel
                        </a>
                        <button type="submit" name="<?php echo isset($product) ? 'update_product' : 'add_product'; ?>" class="btn btn-success">
                            <i class="fas fa-save"></i> <?php echo isset($product) ? 'Update' : 'Save'; ?> Product
                        </button>
                    </div>
                </form>
            </div>
        <?php else: ?>
            <div class="filters">
                <div class="form-group">
                    <label for="category">Category</label>
                    <select id="category" name="category" class="form-control" onchange="applyFilters()">
                        <option value="">All Categories</option>
                        <?php foreach ($categories as $cat): 
                            $displayName = ucfirst(str_replace('_', ' ', $cat));
                        ?>
                            <option value="<?php echo $cat; ?>" <?php echo $category_filter == $cat ? 'selected' : ''; ?>>
                                <?php echo $displayName; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="search">Search</label>
                    <input type="text" id="search" name="search" value="<?php echo htmlspecialchars($search_filter); ?>" 
                           class="form-control" placeholder="Search products..." oninput="applyFilters()">
                </div>
                
                <div class="form-group" style="display: flex; align-items: flex-end;">
                    <button type="button" class="btn btn-secondary" onclick="resetFilters()" style="height: 38px;">
                        <i class="fas fa-sync-alt"></i> Reset
                    </button>
                </div>
            </div>
            
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Image</th>
                            <th>Name</th>
                            <th>Category</th>
                            <th>Price</th>
                            <th>Sizes</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($products as $product): ?>
                            <tr>
                                <td>
                                    <img src="../assets/images/products/<?php echo $product['image']; ?>" alt="<?php echo htmlspecialchars($product['name']); ?>" style="width: 50px; height: 50px; object-fit: cover;">
                                </td>
                                <td><?php echo htmlspecialchars($product['name']); ?></td>
                                <td><?php echo ucfirst(str_replace('_', ' ', $product['category'])); ?></td>
                                <td>₱<?php echo number_format($product['price'], 2); ?></td>
                                <td>
                                    <?php
                                    $sizes = explode(', ', $product['size_info'] ?? '');
                                    $hasLowStock = false;
                                    foreach ($sizes as $size) {
                                        preg_match('/(\w+)\s*\((\d+)\)/', $size, $matches);
                                        if ($matches && intval($matches[2]) < 5) {
                                            $hasLowStock = true;
                                            break;
                                        }
                                    }
                                    ?>
                                    <span class="status <?php echo $hasLowStock ? 'low-stock' : 'success'; ?>">
                                        <?php echo $product['size_info'] ?? 'No sizes'; ?>
                                    </span>
                                </td>
                                <td>
                                    <a href="products.php?edit=<?php echo $product['product_id']; ?>" class="btn btn-primary btn-sm">
                                        <i class="fas fa-edit"></i> Edit
                                    </a>
                                    <a href="products.php?delete_product=<?php echo $product['product_id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('Are you sure you want to delete this product?');">
                                        <i class="fas fa-trash"></i> Delete
                                    </a>
                                    <button class="btn btn-success btn-sm" onclick="openStockModal(<?php echo $product['product_id']; ?>, '<?php echo htmlspecialchars($product['name'], ENT_QUOTES); ?>')">
                                        <i class="fas fa-boxes"></i> Stock
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <div id="stockModal" class="modal-overlay">
        <div class="modal-dialog">
            <div class="modal-header">
                <h3><i class="fas fa-boxes"></i> Update Stock</h3>
            </div>
            <div class="modal-body">
                <form id="stockForm" method="POST">
                    <input type="hidden" name="product_id" id="modal_product_id">
                    <div class="form-group">
                        <label for="variation_id">Size</label>
                        <select id="variation_id" name="variation_id" class="form-control" required>
                            <!-- Options will be populated by JavaScript -->
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="stock_change">Stock Adjustment</label>
                        <div style="display: flex; align-items: center;">
                            <button type="button" class="btn btn-primary" onclick="adjustStock(-1)">-</button>
                            <input type="number" id="stock_change" name="stock_change" value="0" min="-1000" max="1000" class="form-control" style="margin: 0 10px; text-align: center;">
                            <button type="button" class="btn btn-primary" onclick="adjustStock(1)">+</button>
                        </div>
                        <small>Positive numbers add stock, negative numbers remove stock</small>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeStockModal()">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button type="button" class="btn btn-success" onclick="submitStockForm()">
                    <i class="fas fa-save"></i> Update
                </button>
            </div>
        </div>
    </div>

    <script>
        function applyFilters() {
            const category = document.getElementById('category').value;
            const search = document.getElementById('search').value;
            
            let url = 'products.php?';
            
            if (category) url += `category=${category}&`;
            if (search) url += `search=${encodeURIComponent(search)}&`;
            
            window.location.href = url.slice(0, -1);
        }
        
        function resetFilters() {
            window.location.href = 'products.php';
        }
        
        async function openStockModal(productId, productName) {
            try {
                const response = await fetch(`get_product_variations.php?product_id=${productId}`);
                const variations = await response.json();
                
                const variationSelect = document.getElementById('variation_id');
                variationSelect.innerHTML = '';
                
                if (variations.length === 0) {
                    variationSelect.innerHTML = '<option value="">No sizes available</option>';
                } else {
                    variations.forEach(variation => {
                        const option = document.createElement('option');
                        option.value = variation.variation_id;
                        option.textContent = `${variation.size} (Current stock: ${variation.stock})`;
                        variationSelect.appendChild(option);
                    });
                }
                
                document.getElementById('modal_product_id').value = productId;
                document.getElementById('stock_change').value = 0;
                
                const modalTitle = document.querySelector('#stockModal .modal-header h3');
                modalTitle.innerHTML = `<i class="fas fa-boxes"></i> Update Stock: ${productName}`;
                
                document.getElementById('stockModal').classList.add('active');
                document.body.style.overflow = 'hidden';
            } catch (error) {
                console.error('Error fetching variations:', error);
                alert('Error loading size variations');
            }
        }
        
        function closeStockModal() {
            document.getElementById('stockModal').classList.remove('active');
            document.body.style.overflow = '';
        }
        
        function adjustStock(change) {
            const input = document.getElementById('stock_change');
            let value = parseInt(input.value) + change;
            if (value < -1000) value = -1000;
            if (value > 1000) value = 1000;
            input.value = value;
        }
        
        function submitStockForm() {
            const form = document.getElementById('stockForm');
            const formData = new FormData(form);
            formData.append('update_stock', '1');

            fetch('products.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert(data.message);
                    location.reload(); // Reload to show updated stock
                } else {
                    alert(data.message || 'Error updating stock');
                }
                closeStockModal();
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error updating stock. Please try again.');
                closeStockModal();
            });
        }
        
        document.getElementById('stockModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeStockModal();
            }
        });
        
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeStockModal();
            }
        });
        
        document.addEventListener('DOMContentLoaded', function() {
            // Handle product form submission
            document.querySelectorAll('form').forEach(form => {
                form.addEventListener('submit', function(e) {
                    if (form.querySelector('[name="add_product"]') || form.querySelector('[name="update_product"]')) {
                        e.preventDefault();
                        
                        const formData = new FormData(form);
                        
                        fetch('products.php', {
                            method: 'POST',
                            body: formData
                        })
                        .then(response => {
                            if (response.redirected) {
                                window.location = response.url;
                            } else {
                                return response.text().then(text => {
                                    try {
                                        return JSON.parse(text);
                                    } catch (e) {
                                        // If response is not JSON, reload the page
                                        window.location.reload();
                                    }
                                });
                            }
                        })
                        .then(data => {
                            if (data && data.success) {
                                alert(data.message || 'Product saved successfully!');
                                window.location = 'products.php';
                            } else if (data && !data.success) {
                                alert(data.message || 'Error saving product');
                            }
                        })
                        .catch(error => {
                            console.error('Error:', error);
                            alert('Error saving product. Please try again.');
                        });
                    }
                });
            });

            // Existing event listeners for add-size-variation buttons
            document.querySelectorAll('.add-size-variation').forEach(button => {
                button.addEventListener('click', function() {
                    const container = this.previousElementSibling;
                    const isEditMode = container.querySelector('select[name="new_size[]"]') !== null;
                    const namePrefix = isEditMode ? 'new_' : '';
                    
                    // Check if this is a shoe product
                    const isShoe = container.querySelector('select').querySelector('option[value="35"]') !== null;
                    
                    const newRow = document.createElement('div');
                    newRow.className = 'size-variation';
                    
                    if (isShoe) {
                        newRow.innerHTML = `
                            <select name="${namePrefix}size[]" class="form-control">
                                <option value="35">35</option>
                                <option value="36">36</option>
                                <option value="37">37</option>
                                <option value="38">38</option>
                                <option value="39">39</option>
                                <option value="40">40</option>
                                <option value="41">41</option>
                                <option value="42">42</option>
                                <option value="43">43</option>
                                <option value="44">44</option>
                                <option value="45">45</option>
                                <option value="46">46</option>
                                <option value="47">47</option>
                                <option value="48">48</option>
                                <option value="49">49</option>
                                <option value="50">50</option>
                            </select>
                            <input type="number" name="${namePrefix}price_adjustment[]" step="0.01" placeholder="Price Adjustment" class="form-control">
                            <input type="number" name="${namePrefix}stock[]" min="0" placeholder="Stock" class="form-control" required>
                            <button type="button" class="btn btn-danger remove-size"><i class="fas fa-times"></i></button>
                        `;
                    } else {
                        newRow.innerHTML = `
                            <select name="${namePrefix}size[]" class="form-control">
                                <option value="XS">XS</option>
                                <option value="S">S</option>
                                <option value="M" selected>M</option>
                                <option value="L">L</option>
                                <option value="XL">XL</option>
                                <option value="XXL">XXL</option>
                            </select>
                            <input type="number" name="${namePrefix}price_adjustment[]" step="0.01" placeholder="Price Adjustment" class="form-control">
                            <input type="number" name="${namePrefix}stock[]" min="0" placeholder="Stock" class="form-control" required>
                            <button type="button" class="btn btn-danger remove-size"><i class="fas fa-times"></i></button>
                        `;
                    }
                    container.appendChild(newRow);
                });
            });
            
            document.addEventListener('click', function(e) {
                if (e.target.classList.contains('remove-size') || e.target.parentElement.classList.contains('remove-size')) {
                    const btn = e.target.classList.contains('remove-size') ? e.target : e.target.parentElement;
                    const container = btn.closest('.size-variations-container');
                    if (container.querySelectorAll('.size-variation').length > 1) {
                        btn.closest('.size-variation').remove();
                    } else {
                        alert('At least one size variation is required.');
                    }
                }
            });
        });
    </script>
</body>
</html>