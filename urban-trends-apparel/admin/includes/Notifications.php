<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class Notifications {
    private $db;
    private $sms_config;
    private $mailer;
    
    public function __construct($db) {
        $this->db = $db;
        // Load SMS configuration
        $this->sms_config = require_once __DIR__ . '/../../config/sms_config.php';
        
        // Initialize PHPMailer
        $this->mailer = new PHPMailer(true);
        
        // Enable debug output
        $this->mailer->SMTPDebug = 2;  // Enable verbose debug output
        $this->mailer->Debugoutput = function($str, $level) {
            error_log("PHPMailer Debug: $str");
        };

        // Configure SMTP
        $this->mailer->isSMTP();
        $this->mailer->Host = 'smtp.gmail.com';
        $this->mailer->SMTPAuth = true;
        $this->mailer->Username = 'trendwears.ubantrends@gmail.com'; // Your Gmail address
        $this->mailer->Password = 'zrcd gfna uadf gvzz'; // Your Gmail App Password
        $this->mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $this->mailer->Port = 587;
        $this->mailer->CharSet = 'UTF-8';
        
        // Set who the message is from
        $this->mailer->setFrom('trendwears.ubantrends@gmail.com', 'Urban Trends Apparel');
        $this->mailer->isHTML(true);
        
        // Try to prevent "from" spoofing
        $this->mailer->XMailer = 'Urban Trends Mailer';
    }
    
    public function sendOrderStatusNotification($email, $phone, $name, $order_number, $status, $notes = null, $product_names = null) {
        // Send both email and SMS notifications
        $this->sendEmailNotification($email, $name, $order_number, $status, $notes, $product_names);
        $this->sendSMSNotification($phone, $order_number, $status, $notes, $product_names);
    }
    
    private function sendEmailNotification($email, $name, $order_number, $status, $notes = null, $product_names = null) {
        try {
            $subject = "Order #$order_number Status Update";
            $status_text = ucwords(str_replace('_', ' ', $status));
            
            // Get status-specific message
            $status_message = $this->getStatusMessage($status);
            
            // Create the actual message content (this will be stored in database)
            $db_message = "Order #$order_number";
            if ($product_names) {
                $db_message .= " ($product_names)";
            }
            $db_message .= " Status: " . $status_text . "\n";
            $db_message .= "Message: " . $status_message . "\n";
            if (!empty($notes)) {
                $db_message .= "Additional Notes: " . $notes;
            }
            
            // Log the parameters for debugging
            error_log("Sending email notification:");
            error_log("Email: " . $email);
            error_log("Name: " . $name);
            error_log("Order: " . $order_number);
            error_log("Status: " . $status);
            error_log("Notes: " . ($notes ?? 'No notes'));
            
            // Create the HTML email template
            $message = "
            <html>
            <head>
                <title>Order Status Update</title>
                <style>
                    body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                    .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                    .header { 
                        background: linear-gradient(135deg, #181818 0%, #232323 100%);
                        color: white; 
                        padding: 20px; 
                        text-align: center;
                        border-radius: 8px 8px 0 0;
                    }
                    .content { 
                        padding: 30px; 
                        background-color: #f9f9f9;
                        border: 1px solid #ddd;
                    }
                    .footer { 
                        padding: 20px; 
                        text-align: center; 
                        font-size: 0.9em; 
                        color: #666;
                        background: #f3f3f3;
                        border-radius: 0 0 8px 8px;
                    }
                    .status { 
                        font-weight: 600;
                        color: #4361ee;
                        font-size: 1.1em;
                    }
                    .notes { 
                        background-color: #fff3cd;
                        padding: 15px;
                        border-radius: 8px;
                        margin: 20px 0;
                        border-left: 4px solid #ffc107;
                    }
                    .button {
                        display: inline-block;
                        padding: 12px 24px;
                        background: linear-gradient(135deg, #4361ee 0%, #3a0ca3 100%);
                        color: white !important;
                        text-decoration: none;
                        border-radius: 6px;
                        font-weight: 600;
                        margin-top: 15px;
                    }
                    .status-message {
                        background: rgba(67, 97, 238, 0.1);
                        padding: 15px;
                        border-radius: 8px;
                        margin: 15px 0;
                        color: #4361ee;
                    }
                    .order-number {
                        font-size: 1.2em;
                        color: #ffc107;
                        font-weight: 600;
                    }
                    .products {
                        background: #f8f9fa;
                        padding: 15px;
                        border-radius: 8px;
                        margin: 15px 0;
                        border-left: 4px solid #4361ee;
                    }
                </style>
            </head>
            <body>
                <div class='container'>
                    <div class='header'>
                        <h2 style='margin:0;'>Order Status Update</h2>
                    </div>
                    <div class='content'>
                        <p>Dear " . htmlspecialchars($name) . ",</p>
                        
                        <p>Your order <span class='order-number'>#" . htmlspecialchars($order_number) . "</span>";
            
            if ($product_names) {
                $message .= " containing: <div class='products'>" . htmlspecialchars($product_names) . "</div>";
            }
            
            $message .= " has been updated to: 
                            <span class='status'>" . htmlspecialchars($status_text) . "</span>
                        </p>

                        <div class='status-message'>
                            " . htmlspecialchars($status_message) . "
                        </div>";
            
            if (!empty($notes)) {
                $message .= "
                        <div class='notes'>
                            <strong>Additional Information from our team:</strong><br>
                            " . nl2br(htmlspecialchars($notes)) . "
                        </div>";
            }
            
            $message .= "
                        <p style='text-align: center;'>
                            <a href='http://localhost/urban-trends-apparel/urban-trends-apparel/profile.php?section=orders&order_id=" . htmlspecialchars($order_number) . "' class='button' style='background: linear-gradient(135deg, #4361ee 0%, #3a0ca3 100%); color: white; text-decoration: none; padding: 12px 24px; border-radius: 6px; font-weight: 600; margin: 15px 0; display: inline-block;'>Track Your Order</a>
                        </p>
                    </div>
                    <div class='footer'>
                        <p>Thank you for shopping with Urban Trends Apparel!</p>
                        <p style='font-size: 0.8em; color: #888;'>If you have any questions, please don't hesitate to contact our support team.</p>
                    </div>
                </div>
            </body>
            </html>";

            // Clear any previous recipients
            $this->mailer->clearAddresses();
            
            // Set the recipient
            $this->mailer->addAddress($email, $name);
            
            // Set email subject and body
            $this->mailer->Subject = $subject;
            $this->mailer->Body = $message;
            $this->mailer->AltBody = strip_tags($db_message);
            
            // Send the email
            $mail_sent = $this->mailer->send();
            
            if ($mail_sent) {
                // Log success and save to database
                error_log("Email sent successfully to: " . $email);
                $stmt = $this->db->prepare("INSERT INTO email_notifications (recipient_email, subject, message, status) VALUES (?, ?, ?, 'sent')");
                $stmt->execute([$email, $subject, $db_message]);
                return true;
            }
            
            return true;
        } catch (Exception $e) {
            // Log failure and save to database
            error_log("Exception in sendEmailNotification: " . $e->getMessage());
            $stmt = $this->db->prepare("INSERT INTO email_notifications (recipient_email, subject, message, status, error_message) VALUES (?, ?, ?, 'failed', ?)");
            $stmt->execute([$email, $subject, $db_message, $e->getMessage()]);
            return false;
        }
    }
    
    private function getStatusMessage($status) {
        switch($status) {
            case 'processing':
                return "We're currently processing your order. Our team is preparing your items for shipment.";
            case 'shipped':
                return "Great news! Your order is on its way to you. You can track your shipment using the tracking information in your order details.";
            case 'delivered':
                return "Your order has been delivered! We hope you're enjoying your purchase.";
            case 'cancelled':
                return "Your order has been cancelled. If you have any questions, please contact our support team.";
            case 'return_requested':
                return "We've received your return request. Our team will review it and get back to you soon.";
            case 'returned':
                return "Your return has been processed. If a refund is due, it will be processed according to our return policy.";
            case 'refunded':
                return "Your refund has been processed. Please allow a few business days for it to appear in your account.";
            default:
                return "Thank you for shopping with Urban Trends Apparel. We'll keep you updated on your order status.";
        }
    }
    
    private function sendSMSNotification($phone, $order_number, $status, $notes = null, $product_names = null) {
        if (empty($phone)) {
            error_log("SMS not sent: Phone number is empty");
            return false;
        }
        
        // Format the phone number for Philippines
        $phone = $this->formatPhoneNumber($phone);
        if (!$phone) {
            error_log("SMS not sent: Invalid phone number format");
            return false;
        }
        
        $status_text = ucwords(str_replace('_', ' ', $status));
        $message = "Your Urban Trends order #$order_number";
        if ($product_names) {
            $message .= " ($product_names)";
        }
        $message .= " has been updated to: $status_text.";
        
        // Add notes if provided
        if (!empty($notes)) {
            $message .= "\n\nNote: " . $notes;
        }
        
        // Also update the SMS tracking URL
        $message .= "\n\nTrack your order at: http://localhost/urban-trends-apparel/urban-trends-apparel/profile.php?section=orders&order_id=" . $order_number;
        
        try {
            // Prepare the request data for Semaphore
            $data = [
                'apikey' => $this->sms_config['api_key'],
                'number' => $phone,
                'message' => $message,
                'sendername' => $this->sms_config['sender_id']
            ];

            error_log("Attempting to send SMS...");
            error_log("Phone Number: " . $phone);
            error_log("Message: " . $message);
            error_log("API URL: " . $this->sms_config['api_url']);
            
            // Initialize cURL
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $this->sms_config['api_url']);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            
            $response = curl_exec($ch);
            $err = curl_error($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            
            error_log("HTTP Code: " . $httpCode);
            error_log("Response: " . $response);
            error_log("Curl Error: " . $err);
            
            curl_close($ch);
            
            if ($err) {
                error_log("Curl Error sending SMS: " . $err);
                // Log the failed attempt
                $stmt = $this->db->prepare("INSERT INTO sms_notifications (recipient_phone, message, status, error_message) VALUES (?, ?, 'failed', ?)");
                $stmt->execute([$phone, $message, $err]);
                return false;
            }
            
            $response_data = json_decode($response, true);
            error_log("Decoded Response: " . print_r($response_data, true));
            
            // Check if message was sent successfully
            if ($response_data && isset($response_data['message_id'])) {
                // Log the successful SMS
                $stmt = $this->db->prepare("INSERT INTO sms_notifications (recipient_phone, message, status) VALUES (?, ?, 'sent')");
                $stmt->execute([$phone, $message]);
                error_log("SMS sent successfully to " . $phone . " with message_id: " . $response_data['message_id']);
                return true;
            } else {
                $error_message = isset($response_data['message']) ? $response_data['message'] : 'Unknown error';
                error_log("SMS sending failed: " . $error_message);
                // Log the failed attempt
                $stmt = $this->db->prepare("INSERT INTO sms_notifications (recipient_phone, message, status, error_message) VALUES (?, ?, 'failed', ?)");
                $stmt->execute([$phone, $message, $error_message]);
                return false;
            }
            
        } catch(Exception $e) {
            error_log("Exception sending SMS notification: " . $e->getMessage());
            return false;
        }
    }
    
    private function formatPhoneNumber($phone) {
        // Remove any non-numeric characters
        $phone = preg_replace('/[^0-9]/', '', $phone);
        
        // If it's a Philippine number starting with 09
        if (strlen($phone) === 11 && substr($phone, 0, 2) === '09') {
            return '63' . substr($phone, 1); // Convert 09xx to 639xx
        }
        
        // If it's a 10-digit number starting with 9
        if (strlen($phone) === 10 && substr($phone, 0, 1) === '9') {
            return '63' . $phone; // Add 63 prefix
        }
        
        // If it already has 63 prefix
        if (strlen($phone) === 12 && substr($phone, 0, 2) === '63') {
            return $phone;
        }
        
        error_log("Invalid phone number format: " . $phone);
        return false;
    }
} 