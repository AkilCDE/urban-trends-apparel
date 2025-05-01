<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
//require_once '../Database/datab.php';

if (!isset($_SESSION['user_id']) || !$auth->isAdmin()) {
    header('Location: ../login.php');
    exit();
}

$error = '';
$success = '';


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = trim($_POST['code'] ?? '');
    $user_id = $_POST['user_id'] ?? '';
    $discount_percentage = intval($_POST['discount_percentage'] ?? 0);
    $start_date = $_POST['start_date'] ?? '';
    $end_date = $_POST['end_date'] ?? '';

    // Validate input
    if (empty($code) || empty($user_id) || empty($discount_percentage) || empty($start_date) || empty($end_date)) {
        $error = "All fields are required";
    } elseif ($discount_percentage < 1 || $discount_percentage > 100) {
        $error = "Discount percentage must be between 1 and 100";
    } elseif (strtotime($start_date) >= strtotime($end_date)) {
        $error = "End date must be after start date";
    } else {
        try {
            $stmt = $db->prepare("
                INSERT INTO promotions (
                    code, user_id, discount_type, discount_value, 
                    start_date, end_date, max_uses, is_active
                ) VALUES (?, ?, 'percentage', ?, ?, ?, 1, 1)
            ");
            
            if ($stmt->execute([
                $code, $user_id, $discount_percentage,
                $start_date, $end_date
            ])) {
                $success = "Promo code created successfully and assigned to selected user";
            } else {
                $error = "Failed to create promo code";
            }
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) { // Duplicate entry
                $error = "Promo code already exists";
            } else {
                $error = "Database error: " . $e->getMessage();
            }
        }
    }
}

if (isset($_GET['logout'])) {
    session_unset();
    session_destroy();
    header("Location: ../login.php");
    exit;
}

// Get all users for the dropdown
$stmt = $db->query("SELECT user_id, email, firstname, lastname FROM users WHERE is_admin = 0 ORDER BY firstname");
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get all promo codes
$stmt = $db->prepare("
    SELECT p.*, u.email, u.firstname, u.lastname 
    FROM promotions p
    LEFT JOIN users u ON p.user_id = u.user_id
    ORDER BY p.end_date DESC
");
$stmt->execute();
$promo_codes = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Urban Trends Apparel - Manage Promo Codes</title>
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
        
        /* Form Styles */
        .form-container {
            background-color: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .form-container h3 {
            font-size: 1.3rem;
            margin-bottom: 20px;
            color: var(--dark-color);
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
        
        /* Buttons */
        .btn {
            padding: 8px 16px;
            border-radius: 4px;
            font-size: 0.9rem;
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
        
        /* Messages */
        .error-message {
            background-color: rgba(247, 37, 133, 0.1);
            border: 1px solid var(--danger-color);
            color: var(--danger-color);
            padding: 10px;
            border-radius: 4px;
            margin-bottom: 15px;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
        }
        
        .error-message i {
            margin-right: 8px;
        }
        
        .success-message {
            background-color: rgba(76, 201, 240, 0.1);
            border: 1px solid var(--success-color);
            color: var(--success-color);
            padding: 10px;
            border-radius: 4px;
            margin-bottom: 15px;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
        }
        
        .success-message i {
            margin-right: 8px;
        }
        
        /* Admin Table Styles */
        .admin-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        .admin-table th, 
        .admin-table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }

        .admin-table th {
            background-color: var(--primary-color);
            color: white;
            font-weight: 500;
        }

        .admin-table tr:hover {
            background-color: rgba(67, 97, 238, 0.05);
        }

        .status-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.8rem;
            font-weight: 500;
        }

        .status-active {
            background-color: rgba(76, 201, 240, 0.1);
            color: var(--success-color);
        }

        .status-used {
            background-color: rgba(247, 37, 133, 0.1);
            color: var(--danger-color);
        }

        .status-expired {
            background-color: rgba(248, 150, 30, 0.1);
            color: var(--warning-color);
        }

        .btn-danger {
            background-color: var(--danger-color);
            color: white;
        }

        .btn-sm {
            padding: 5px 10px;
            font-size: 0.8rem;
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

            .admin-table {
                display: block;
                overflow-x: auto;
            }
        }
        
        @media (max-width: 576px) {
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
            <li><a href="dashboard.php"><i class="fas fa-tachometer-alt"></i> <span>Dashboard</span></a></li>
            <li><a href="products.php"><i class="fas fa-tshirt"></i> <span>Products</span></a></li>
            <li><a href="orders.php"><i class="fas fa-shopping-bag"></i> <span>Orders</span></a></li>
            <li><a href="customers.php"><i class="fas fa-users"></i> <span>Customers</span></a></li>
            <li><a href="manage_promo_codes.php" class="active"><i class="fas fa-percent"></i> <span>Promo Codes</span></a></li>
            <li><a href="reports.php"><i class="fas fa-chart-bar"></i> <span>Reports</span></a></li>
        </ul>
    </div>

    <!-- Main Content -->
    <div class="admin-main">
        <div class="admin-header">
            <h2><i class="fas fa-percent"></i> Manage Promo Codes</h2>
            <div class="admin-actions">
                <a href="../index.php"><i class="fas fa-home"></i> View Site</a>
                <a href="../login.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="error-message">
                <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="success-message">
                <i class="fas fa-check-circle"></i> <?php echo $success; ?>
            </div>
        <?php endif; ?>

        <div class="form-container">
            <h3>Create New Promo Code</h3>
            <form method="POST">
                <div class="form-group">
                    <label for="code">Promo Code</label>
                    <input type="text" id="code" name="code" class="form-control" required>
                </div>

                <div class="form-group">
                    <label for="user_id">Assign to User</label>
                    <select id="user_id" name="user_id" class="form-control" required>
                        <option value="">Select User</option>
                        <?php foreach ($users as $user): ?>
                            <option value="<?php echo $user['user_id']; ?>">
                                <?php echo htmlspecialchars($user['firstname'] . ' ' . $user['lastname'] . ' (' . $user['email'] . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="discount_percentage">Discount Percentage</label>
                    <input type="number" id="discount_percentage" name="discount_percentage" class="form-control" min="1" max="100" step="1" required>
                    <small class="text-muted">Enter a value between 1 and 100</small>
                </div>

                <div class="form-group">
                    <label for="start_date">Start Date</label>
                    <input type="datetime-local" id="start_date" name="start_date" class="form-control" required>
                </div>

                <div class="form-group">
                    <label for="end_date">End Date</label>
                    <input type="datetime-local" id="end_date" name="end_date" class="form-control" required>
                </div>

                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-plus"></i> Create Promo Code
                </button>
            </form>
        </div>

        <div class="form-container">
            <h3>Existing Promo Codes</h3>
            
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Assigned User</th>
                        <th>Discount</th>
                        <th>Start Date</th>
                        <th>End Date</th>
                        <th>Status</th>
                        <th>Uses</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($promo_codes as $promo): 
                        $is_expired = strtotime($promo['end_date']) < time();
                        $is_used = $promo['current_uses'] >= $promo['max_uses'];
                        $status = $is_used ? 'Used' : 
                                 ($is_expired ? 'Expired' : 'Active');
                        $status_class = $is_used ? 'status-used' : 
                                       ($is_expired ? 'status-expired' : 'status-active');
                    ?>
                        <tr>
                            <td><?php echo htmlspecialchars($promo['code']); ?></td>
                            <td><?php echo $promo['user_id'] ? htmlspecialchars($promo['firstname'] . ' ' . $promo['lastname'] . ' (' . $promo['email'] . ')') : 'Not assigned'; ?></td>
                            <td><?php echo $promo['discount_value']; ?>%</td>
                            <td><?php echo date('M j, Y H:i', strtotime($promo['start_date'])); ?></td>
                            <td><?php echo date('M j, Y H:i', strtotime($promo['end_date'])); ?></td>
                            <td><span class="status-badge <?php echo $status_class; ?>"><?php echo $status; ?></span></td>
                            <td><?php echo $promo['current_uses'] . '/' . $promo['max_uses']; ?></td>
                            <td>
                                <form method="POST" action="delete_promo_code.php" style="display: inline;">
                                    <input type="hidden" name="promotion_id" value="<?php echo $promo['promotion_id']; ?>">
                                    <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Are you sure you want to delete this promo code?');">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <script>
        // Set minimum datetime for start_date and end_date
        document.addEventListener('DOMContentLoaded', function() {
            const now = new Date();
            const startDate = document.getElementById('start_date');
            const endDate = document.getElementById('end_date');

            // Format the current datetime for the input
            const year = now.getFullYear();
            const month = String(now.getMonth() + 1).padStart(2, '0');
            const day = String(now.getDate()).padStart(2, '0');
            const hours = String(now.getHours()).padStart(2, '0');
            const minutes = String(now.getMinutes()).padStart(2, '0');

            const currentDateTime = `${year}-${month}-${day}T${hours}:${minutes}`;
            
            startDate.min = currentDateTime;
            endDate.min = currentDateTime;

            // Update end_date min when start_date changes
            startDate.addEventListener('change', function() {
                endDate.min = this.value;
                if (endDate.value && endDate.value < this.value) {
                    endDate.value = this.value;
                }
            });

            // Auto-generate promo code if field is empty
            const codeField = document.getElementById('code');
            codeField.addEventListener('focus', function() {
                if (!this.value) {
                    const randomChars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
                    let result = '';
                    for (let i = 0; i < 8; i++) {
                        result += randomChars.charAt(Math.floor(Math.random() * randomChars.length));
                    }
                    this.value = result;
                }
            });
        });
    </script>
</body>
</html>