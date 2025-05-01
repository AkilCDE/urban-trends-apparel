<?php
require_once 'Database/datab.php';
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Check if order_id is provided
if (!isset($_GET['order_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Order ID is required']);
    exit;
}

$order_id = $_GET['order_id'];
$user_id = $_SESSION['user_id'];

try {
    // Get order and shipping information
    $stmt = $db->prepare("
        SELECT 
            o.*,
            s.tracking_number,
            s.carrier,
            s.shipping_method,
            s.status as shipping_status,
            s.estimated_delivery,
            s.actual_delivery
        FROM orders o
        LEFT JOIN shipping s ON o.order_id = s.order_id
        WHERE o.order_id = ? AND o.user_id = ?
    ");
    
    $stmt->execute([$order_id, $user_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        http_response_code(404);
        echo json_encode(['error' => 'Order not found or unauthorized']);
        exit;
    }
    
    // Format the response
    $response = [
        'orderId' => $order_id,
        'status' => $order['status'],
        'tracking' => $order['tracking_number'] ?? 'N/A',
        'carrier' => $order['carrier'] ?? 'N/A',
        'shippingMethod' => $order['shipping_method'] ?? 'Standard',
        'estimatedDelivery' => $order['estimated_delivery'] ? date('M d, Y', strtotime($order['estimated_delivery'])) : 'Not available',
        'actualDelivery' => $order['actual_delivery'] ? date('M d, Y', strtotime($order['actual_delivery'])) : null,
        'shippingAddress' => $order['shipping_address'],
        'locationNotes' => $order['location_notes'] ?? '',
        'lat' => $order['latitude'] ?? null,
        'lng' => $order['longitude'] ?? null,
        'orderDate' => date('M d, Y', strtotime($order['order_date'])),
        'totalAmount' => number_format($order['total_amount'], 2)
    ];
    
    header('Content-Type: application/json');
    echo json_encode($response);
    
} catch (PDOException $e) {
    error_log("Error in get_order_tracking.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
    exit;
} 