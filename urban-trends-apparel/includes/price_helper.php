<?php

function isPromoCodeValid($db, $code) {
    if (empty($code)) return false;
    
    $stmt = $db->prepare("
        SELECT * FROM promo_codes 
        WHERE code = ? 
        AND is_used = 0 
        AND valid_until >= CURRENT_TIMESTAMP()
    ");
    $stmt->execute([$code]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function calculateDiscountedPrice($original_price) {
    if (isset($_SESSION['active_promo']) && !empty($_SESSION['active_promo']['code'])) {
        // Check if promo is still valid
        global $db;
        $promo = isPromoCodeValid($db, $_SESSION['active_promo']['code']);
        
        if (!$promo) {
            // Promo is no longer valid, remove from session
            unset($_SESSION['active_promo']);
            return $original_price;
        }
        
        $discount_percentage = $_SESSION['active_promo']['discount_percentage'];
        return $original_price * (1 - ($discount_percentage / 100));
    }
    return $original_price;
}

function formatPrice($price) {
    return number_format($price, 2);
}

function getDiscountBadge() {
    if (isset($_SESSION['active_promo']) && !empty($_SESSION['active_promo']['code'])) {
        // Check if promo is still valid
        global $db;
        $promo = isPromoCodeValid($db, $_SESSION['active_promo']['code']);
        
        if (!$promo) {
            // Promo is no longer valid, remove from session
            unset($_SESSION['active_promo']);
            return '';
        }
        
        return '<span class="discount-badge">-' . $_SESSION['active_promo']['discount_percentage'] . '%</span>';
    }
    return '';
}

function displayPrice($original_price) {
    $discounted_price = calculateDiscountedPrice($original_price);
    
    if (isset($_SESSION['active_promo']) && !empty($_SESSION['active_promo']['code'])) {
        // Check if promo is still valid
        global $db;
        $promo = isPromoCodeValid($db, $_SESSION['active_promo']['code']);
        
        if (!$promo) {
            // Promo is no longer valid, remove from session
            unset($_SESSION['active_promo']);
            return '₱' . formatPrice($original_price);
        }
        
        return '<span class="original-price">₱' . formatPrice($original_price) . '</span> ' .
               '<span class="discounted-price">₱' . formatPrice($discounted_price) . '</span> ' .
               getDiscountBadge();
    }
    
    return '₱' . formatPrice($original_price);
}

function validatePromoCode($db, $code, $user_id) {
    try {
        $stmt = $db->prepare("
            SELECT pc.*, 
                   (SELECT COUNT(*) FROM promo_code_usage WHERE promo_id = pc.promo_id AND user_id = ?) as times_used
            FROM promo_codes pc
            WHERE pc.code = ?
            AND pc.is_used = 0
            AND pc.valid_until >= CURRENT_DATE()
            LIMIT 1
        ");
        $stmt->execute([$user_id, $code]);
        $promo = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$promo) {
            return [
                'valid' => false,
                'message' => 'Invalid or expired promo code.'
            ];
        }

        if ($promo['times_used'] > 0) {
            return [
                'valid' => false,
                'message' => 'You have already used this promo code.'
            ];
        }

        return [
            'valid' => true,
            'promo' => $promo,
            'message' => 'Promo code applied successfully!'
        ];
    } catch (Exception $e) {
        error_log("Error validating promo code: " . $e->getMessage());
        return [
            'valid' => false,
            'message' => 'An error occurred while validating the promo code.'
        ];
    }
}

function calculateDiscount($price, $discount_percentage) {
    return round($price * ($discount_percentage / 100), 2);
}

function applyPromoCode($db, $order_id, $promo_id, $user_id) {
    try {
        $db->beginTransaction();

        // Add promo code to order
        $stmt = $db->prepare("
            INSERT INTO order_promotions (order_id, promo_id)
            VALUES (?, ?)
        ");
        $stmt->execute([$order_id, $promo_id]);

        // Mark promo code as used
        $stmt = $db->prepare("
            UPDATE promo_codes 
            SET is_used = 1, 
                used_at = NOW(), 
                used_by = ? 
            WHERE promo_id = ?
        ");
        $stmt->execute([$user_id, $promo_id]);

        // Record usage
        $stmt = $db->prepare("
            INSERT INTO promo_code_usage (promo_id, user_id, order_id, used_at)
            VALUES (?, ?, ?, NOW())
        ");
        $stmt->execute([$promo_id, $user_id, $order_id]);

        // Clear the active promo from session
        if (isset($_SESSION['active_promo']) && $_SESSION['active_promo']['promo_id'] == $promo_id) {
            unset($_SESSION['active_promo']);
        }

        $db->commit();
        return true;
    } catch (Exception $e) {
        $db->rollBack();
        error_log("Error applying promo code: " . $e->getMessage());
        return false;
    }
}

function clearActivePromo() {
    if (isset($_SESSION['active_promo'])) {
        unset($_SESSION['active_promo']);
    }
}

// Function to check if a promo code has been used in an order
function isPromoCodeUsedInOrder($db, $order_id) {
    $stmt = $db->prepare("
        SELECT COUNT(*) 
        FROM order_promotions op 
        JOIN promo_codes pc ON op.promo_id = pc.promo_id 
        WHERE op.order_id = ? AND pc.is_used = 1
    ");
    $stmt->execute([$order_id]);
    return $stmt->fetchColumn() > 0;
} 