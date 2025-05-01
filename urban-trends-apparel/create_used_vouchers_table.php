<?php
require_once 'Database/datab.php';

try {
    // Create used_vouchers table
    $sql = "CREATE TABLE IF NOT EXISTS used_vouchers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        voucher_code VARCHAR(50) NOT NULL,
        user_id INT NOT NULL,
        product_id INT NOT NULL,
        used_at DATETIME NOT NULL,
        FOREIGN KEY (user_id) REFERENCES users(user_id),
        FOREIGN KEY (product_id) REFERENCES products(product_id)
    )";
    
    $db->exec($sql);
    echo "Table 'used_vouchers' created successfully!";
    
} catch(PDOException $e) {
    echo "Error creating table: " . $e->getMessage();
}
?> 