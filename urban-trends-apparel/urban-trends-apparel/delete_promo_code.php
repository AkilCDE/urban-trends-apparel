<?php
ob_start(); // Start output buffering
require_once '../includes/config.php';
require_once '../includes/auth.php';

// Check if user is admin
if (!isset($_SESSION['user_id']) || !$auth->isAdmin()) {
    header('Location: ../login.php');
    exit();
}

// Check if promotion_id is provided
if (!isset($_POST['promotion_id'])) {
    $_SESSION['error'] = "Promo code ID is required";
    header('Location: manage_promo_codes.php');
    exit();
}

$promotion_id = intval($_POST['promotion_id']);

try {
    // Delete the promo code
    $stmt = $db->prepare("DELETE FROM promotions WHERE promotion_id = ?");
    if ($stmt->execute([$promotion_id])) {
        $_SESSION['success'] = "Promo code deleted successfully";
    } else {
        $_SESSION['error'] = "Failed to delete promo code";
    }
} catch (PDOException $e) {
    $_SESSION['error'] = "Database error: " . $e->getMessage();
}

// Clear the output buffer
ob_end_clean();

// Redirect back to manage promo codes page
header('Location: manage_promo_codes.php');
exit(); 