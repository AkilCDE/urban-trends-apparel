<?php
// Test email functionality
$to = "your-email@example.com"; // Replace with your email
$subject = "Test Email from Urban Trends";
$message = "This is a test email to verify the mail system is working.";
$headers = "From: Urban Trends <noreply@urbantrends.com>\r\n";

if(mail($to, $subject, $message, $headers)) {
    echo "Test email sent successfully!";
} else {
    echo "Failed to send test email.";
    print_r(error_get_last());
}
?> 