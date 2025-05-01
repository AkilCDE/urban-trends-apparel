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

// Get cart count
$cart_count = 0;
if ($auth->isLoggedIn()) {
    $stmt = $db->prepare("SELECT SUM(quantity) FROM cart WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $cart_count = $stmt->fetchColumn() ?: 0;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Urban Trends Apparel - Shoe Size Guide</title>
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
            --info-color: #007bff;
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

        /* Size Guide Content */
        .size-guide-header {
            text-align: center;
            padding: 4rem 0 2rem;
            position: relative;
        }

        .size-guide-header h1 {
            font-size: 3rem;
            margin-bottom: 1rem;
            color: var(--text-color);
            position: relative;
            display: inline-block;
        }

        .size-guide-header h1::after {
            content: '';
            position: absolute;
            bottom: -10px;
            left: 50%;
            transform: translateX(-50%);
            width: 100px;
            height: 3px;
            background-color: var(--accent-color);
        }

        .size-guide-intro {
            max-width: 800px;
            margin: 0 auto 3rem;
            text-align: center;
            color: var(--text-muted);
        }

        .size-guide-section {
            margin-bottom: 4rem;
        }

        .size-guide-section h2 {
            font-size: 2rem;
            margin-bottom: 1.5rem;
            color: var(--accent-color);
            text-align: center;
            position: relative;
            display: inline-block;
            left: 50%;
            transform: translateX(-50%);
        }

        .size-guide-section h2::after {
            content: '';
            position: absolute;
            bottom: -10px;
            left: 0;
            width: 100%;
            height: 3px;
            background-color: var(--accent-color);
        }

        .size-table-container {
            overflow-x: auto;
            margin-bottom: 2rem;
            background-color: var(--primary-color);
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
        }

        .size-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 800px;
        }

        .size-table th {
            background-color: var(--accent-color);
            color: white;
            padding: 1rem;
            text-align: center;
            font-weight: 600;
        }

        .size-table td {
            padding: 0.8rem;
            text-align: center;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .size-table tr:last-child td {
            border-bottom: none;
        }

        .size-table tr:hover {
            background-color: rgba(255, 255, 255, 0.05);
        }

        .size-note {
            background-color: rgba(255, 107, 107, 0.1);
            border-left: 4px solid var(--accent-color);
            padding: 1rem;
            margin: 2rem 0;
            border-radius: 0 var(--border-radius) var(--border-radius) 0;
        }

        .size-note h3 {
            color: var(--accent-color);
            margin-bottom: 0.5rem;
        }

        .size-note p {
            color: var(--text-muted);
        }

        .size-tabs {
            display: flex;
            justify-content: center;
            margin-bottom: 2rem;
            gap: 1rem;
        }

        .size-tab {
            padding: 0.8rem 1.5rem;
            background-color: var(--primary-color);
            color: var(--text-color);
            border: none;
            border-radius: var(--border-radius);
            cursor: pointer;
            font-weight: 500;
            transition: var(--transition);
        }

        .size-tab.active {
            background-color: var(--accent-color);
            color: white;
        }

        .size-tab:hover {
            background-color: var(--accent-color);
            color: white;
        }

        .size-content {
            display: none;
        }

        .size-content.active {
            display: block;
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

            .size-guide-header h1 {
                font-size: 2.5rem;
            }

            .size-table-container {
                margin: 0 -1rem;
                border-radius: 0;
            }
        }

        @media (max-width: 576px) {
            .size-guide-header h1 {
                font-size: 2rem;
            }

            .size-tabs {
                flex-direction: column;
            }

            .size-tab {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <header>
        <div class="container">
            <div class="logo">
                <a href="index.php"><i class="fas fa-tshirt"></i> Urban Trends</a>
            </div>
            
            <div style="display: flex; align-items: center;">
                <nav>
                    <ul>
                        <li><a href="index.php"><i class="fas fa-home"></i> Home</a></li>
                        <li><a href="shop.php"><i class="fas fa-store"></i> Shop</a></li>
                        <li><a href="about.php"><i class="fas fa-info-circle"></i> About</a></li>
                        <li><a href="contact.php"><i class="fas fa-envelope"></i> Contact</a></li>
                    </ul>
                </nav>
                
                <div class="user-actions" style="margin-left: auto;">
                    <?php if ($auth->isLoggedIn()): ?>
                        <?php if ($auth->isAdmin()): ?>
                            <a href="admin/dashboard.php" title="Admin"><i class="fas fa-cog"></i></a>
                        <?php endif; ?>
                        <a href="profile.php" title="Profile"><i class="fas fa-user"></i> Profile</a>
                        <a href="?logout=1" title="Logout"><i class="fas fa-sign-out-alt"></i> logout</a>
                        <a href="cart.php" title="Cart" class="cart-count">
                            <i class="fas fa-shopping-cart"></i>
                            <span id="cart-counter"><?php echo $cart_count; ?></span>
                        </a>
                    <?php else: ?>
                        <a href="login.php" title="Login"><i class="fas fa-sign-in-alt"></i>Login first</a>
                        <a href="register.php" title="Register"><i class="fas fa-user-plus"></i></a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </header>
    
    <main class="container">
        <section class="size-guide-section">
            <div class="size-guide-header">
                <h1>SHOE SIZE GUIDE</h1>
            </div>
            
            <div class="size-guide-intro">
                <p>Finding the perfect fit is essential for comfort and style. Use our comprehensive size guide to determine your ideal shoe size. We offer a wide range of sizes for both adults and children.</p>
            </div>
            
            <div class="size-tabs">
                <button class="size-tab active" data-tab="adult">Adult Sizes</button>
                <button class="size-tab" data-tab="kids">Kids Sizes</button>
            </div>
            
            <div class="size-content active" id="adult-sizes">
                <div class="size-table-container">
                    <table class="size-table">
                        <thead>
                            <tr>
                                <th>US</th>
                                <th>EURO</th>
                                <th>CM</th>
                                <th>UK</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>4</td>
                                <td>36</td>
                                <td>22.5</td>
                                <td>3</td>
                            </tr>
                            <tr>
                                <td>4.5</td>
                                <td>37</td>
                                <td>23</td>
                                <td>3.5</td>
                            </tr>
                            <tr>
                                <td>5</td>
                                <td>37.5</td>
                                <td>23.5</td>
                                <td>4</td>
                            </tr>
                            <tr>
                                <td>5.5</td>
                                <td>38</td>
                                <td>24</td>
                                <td>4.5</td>
                            </tr>
                            <tr>
                                <td>6</td>
                                <td>39</td>
                                <td>24.5</td>
                                <td>5</td>
                            </tr>
                            <tr>
                                <td>6.5</td>
                                <td>39.5</td>
                                <td>25</td>
                                <td>5.5</td>
                            </tr>
                            <tr>
                                <td>7</td>
                                <td>40</td>
                                <td>25.25</td>
                                <td>6</td>
                            </tr>
                            <tr>
                                <td>7H</td>
                                <td>40.5</td>
                                <td>25.5</td>
                                <td>6.5</td>
                            </tr>
                            <tr>
                                <td>8</td>
                                <td>41.5</td>
                                <td>26</td>
                                <td>7</td>
                            </tr>
                            <tr>
                                <td>8H</td>
                                <td>42</td>
                                <td>26.5</td>
                                <td>7.5</td>
                            </tr>
                            <tr>
                                <td>9</td>
                                <td>42.5</td>
                                <td>27</td>
                                <td>8</td>
                            </tr>
                            <tr>
                                <td>9H</td>
                                <td>43.5</td>
                                <td>27.5</td>
                                <td>8.5</td>
                            </tr>
                            <tr>
                                <td>10</td>
                                <td>44</td>
                                <td>28</td>
                                <td>9</td>
                            </tr>
                            <tr>
                                <td>10H</td>
                                <td>44.5</td>
                                <td>28.25</td>
                                <td>9.5</td>
                            </tr>
                            <tr>
                                <td>11</td>
                                <td>45</td>
                                <td>28.5</td>
                                <td>10</td>
                            </tr>
                            <tr>
                                <td>11H</td>
                                <td>46</td>
                                <td>29</td>
                                <td>10.5</td>
                            </tr>
                            <tr>
                                <td>12</td>
                                <td>46.5</td>
                                <td>29.5</td>
                                <td>11</td>
                            </tr>
                            <tr>
                                <td>12H</td>
                                <td>47</td>
                                <td>30</td>
                                <td>11.5</td>
                            </tr>
                            <tr>
                                <td>13</td>
                                <td>48</td>
                                <td>30.5</td>
                                <td>12</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                
                <div class="size-note">
                    <h3>How to Measure Your Foot</h3>
                    <p>1. Place a piece of paper on a hard floor.</p>
                    <p>2. Stand on the paper with your heel against a wall.</p>
                    <p>3. Mark the tip of your longest toe on the paper.</p>
                    <p>4. Measure the length from the heel to the toe mark in centimeters.</p>
                    <p>5. Use the CM column in the size chart to find your size.</p>
                </div>
            </div>
            
            <div class="size-content" id="kids-sizes">
                <div class="size-table-container">
                    <table class="size-table">
                        <thead>
                            <tr>
                                <th>CM</th>
                                <th>US</th>
                                <th>UK</th>
                                <th>EURO</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>12</td>
                                <td>K4</td>
                                <td>K3</td>
                                <td>19.5</td>
                            </tr>
                            <tr>
                                <td>12.5</td>
                                <td>K4H</td>
                                <td>K3.5</td>
                                <td>20.5</td>
                            </tr>
                            <tr>
                                <td>13</td>
                                <td>K5</td>
                                <td>K4</td>
                                <td>21</td>
                            </tr>
                            <tr>
                                <td>13.25</td>
                                <td>K5H</td>
                                <td>K4.5</td>
                                <td>21.5</td>
                            </tr>
                            <tr>
                                <td>13.5</td>
                                <td>K6</td>
                                <td>K5</td>
                                <td>22.5</td>
                            </tr>
                            <tr>
                                <td>14</td>
                                <td>K6H</td>
                                <td>K5.5</td>
                                <td>23</td>
                            </tr>
                            <tr>
                                <td>14.5</td>
                                <td>K7</td>
                                <td>K6</td>
                                <td>23.5</td>
                            </tr>
                            <tr>
                                <td>14.75</td>
                                <td>K7H</td>
                                <td>K6.5</td>
                                <td>24</td>
                            </tr>
                            <tr>
                                <td>15</td>
                                <td>K8</td>
                                <td>K7</td>
                                <td>25</td>
                            </tr>
                            <tr>
                                <td>15.5</td>
                                <td>K8H</td>
                                <td>K7.5</td>
                                <td>25.5</td>
                            </tr>
                            <tr>
                                <td>16</td>
                                <td>K9</td>
                                <td>K8</td>
                                <td>26</td>
                            </tr>
                            <tr>
                                <td>16.5</td>
                                <td>K9H</td>
                                <td>K8.5</td>
                                <td>26.5</td>
                            </tr>
                            <tr>
                                <td>17.0</td>
                                <td>K10</td>
                                <td>K9</td>
                                <td>27</td>
                            </tr>
                            <tr>
                                <td>17.25</td>
                                <td>K10H</td>
                                <td>K9.5</td>
                                <td>28</td>
                            </tr>
                            <tr>
                                <td>17.5</td>
                                <td>K11</td>
                                <td>K10</td>
                                <td>28.5</td>
                            </tr>
                            <tr>
                                <td>18</td>
                                <td>K11H</td>
                                <td>K10.5</td>
                                <td>29.5</td>
                            </tr>
                            <tr>
                                <td>18.5</td>
                                <td>K12</td>
                                <td>K11</td>
                                <td>30</td>
                            </tr>
                            <tr>
                                <td>19</td>
                                <td>K12H</td>
                                <td>K11.5</td>
                                <td>30.5</td>
                            </tr>
                            <tr>
                                <td>19.5</td>
                                <td>K13</td>
                                <td>K12</td>
                                <td>31.5</td>
                            </tr>
                            <tr>
                                <td>19.75</td>
                                <td>K13H</td>
                                <td>K12.5</td>
                                <td>32</td>
                            </tr>
                            <tr>
                                <td>20</td>
                                <td>1</td>
                                <td>K13</td>
                                <td>32.5</td>
                            </tr>
                            <tr>
                                <td>20.5</td>
                                <td>1H</td>
                                <td>K13.5</td>
                                <td>33</td>
                            </tr>
                            <tr>
                                <td>21</td>
                                <td>2</td>
                                <td>1</td>
                                <td>33.5</td>
                            </tr>
                            <tr>
                                <td>21.5</td>
                                <td>2H</td>
                                <td>1.5</td>
                                <td>34.5</td>
                            </tr>
                            <tr>
                                <td>22</td>
                                <td>3</td>
                                <td>2</td>
                                <td>35</td>
                            </tr>
                            <tr>
                                <td>22.25</td>
                                <td>3H</td>
                                <td>2.5</td>
                                <td>35.5</td>
                            </tr>
                            <tr>
                                <td>22.5</td>
                                <td>4</td>
                                <td>3</td>
                                <td>36</td>
                            </tr>
                            <tr>
                                <td>23</td>
                                <td>4H</td>
                                <td>3.5</td>
                                <td>37</td>
                            </tr>
                            <tr>
                                <td>23.5</td>
                                <td>5</td>
                                <td>4</td>
                                <td>37.5</td>
                            </tr>
                            <tr>
                                <td>24</td>
                                <td>5H</td>
                                <td>4.5</td>
                                <td>38</td>
                            </tr>
                            <tr>
                                <td>24.5</td>
                                <td>6</td>
                                <td>5</td>
                                <td>39</td>
                            </tr>
                            <tr>
                                <td>25</td>
                                <td>6H</td>
                                <td>5.5</td>
                                <td>39.5</td>
                            </tr>
                            <tr>
                                <td>25.5</td>
                                <td>7</td>
                                <td>6</td>
                                <td>40</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                
                <div class="size-note">
                    <h3>How to Measure Your Child's Foot</h3>
                    <p>1. Place a piece of paper on a hard floor.</p>
                    <p>2. Have your child stand on the paper with their heel against a wall.</p>
                    <p>3. Mark the tip of their longest toe on the paper.</p>
                    <p>4. Measure the length from the heel to the toe mark in centimeters.</p>
                    <p>5. Use the CM column in the size chart to find their size.</p>
                    <p>6. For growing feet, add 0.5-1 cm to allow for growth.</p>
                </div>
            </div>
            
            <div class="size-note">
                <h3>Tips for Finding the Perfect Fit</h3>
                <p>• Always measure both feet and use the larger measurement.</p>
                <p>• Try on shoes in the afternoon as feet can swell throughout the day.</p>
                <p>• There should be about a thumb's width of space between your longest toe and the end of the shoe.</p>
                <p>• If you're between sizes, we recommend going up a size for comfort.</p>
                <p>• Different brands may fit differently, so check the specific product description for any sizing notes.</p>
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

        // Size guide tabs
        document.querySelectorAll('.size-tab').forEach(tab => {
            tab.addEventListener('click', function() {
                // Remove active class from all tabs and content
                document.querySelectorAll('.size-tab').forEach(t => t.classList.remove('active'));
                document.querySelectorAll('.size-content').forEach(c => c.classList.remove('active'));
                
                // Add active class to clicked tab and corresponding content
                this.classList.add('active');
                document.getElementById(this.dataset.tab + '-sizes').classList.add('active');
            });
        });

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            updateCartCounter();
        });
    </script>
</body>
</html> 