<?php
require_once 'Database/datab.php';
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}

// Check if file was uploaded
if (!isset($_FILES['profile_picture'])) {
    echo json_encode(['success' => false, 'message' => 'No file uploaded']);
    exit;
}

$file = $_FILES['profile_picture'];
$user_id = $_SESSION['user_id'];

// Validate file type
$allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
if (!in_array($file['type'], $allowed_types)) {
    echo json_encode(['success' => false, 'message' => 'Invalid file type. Only JPG, PNG and GIF are allowed']);
    exit;
}

// Validate file size (max 5MB)
if ($file['size'] > 5 * 1024 * 1024) {
    echo json_encode(['success' => false, 'message' => 'File too large. Maximum size is 5MB']);
    exit;
}

// Create upload directory if it doesn't exist
$upload_dir = 'uploads/profile_pictures';
if (!file_exists($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

// Generate unique filename
$extension = pathinfo($file['name'], PATHINFO_EXTENSION);
$filename = $user_id . '_' . time() . '.' . $extension;
$filepath = $upload_dir . '/' . $filename;

// Move uploaded file
if (move_uploaded_file($file['tmp_name'], $filepath)) {
    // Get old profile picture
    $stmt = $db->prepare("SELECT profile_picture FROM users WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $old_picture = $stmt->fetchColumn();

    // Update database
    $stmt = $db->prepare("UPDATE users SET profile_picture = ? WHERE user_id = ?");
    if ($stmt->execute([$filepath, $user_id])) {
        // Delete old profile picture if it exists
        if ($old_picture && file_exists($old_picture)) {
            unlink($old_picture);
        }

        echo json_encode([
            'success' => true,
            'url' => $filepath,
            'message' => 'Profile picture updated successfully'
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to update database']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to upload file']);
} 