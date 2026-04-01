<?php
// config/session.php
session_start();

// Regenerate ID secara berkala untuk keamanan
if (!isset($_SESSION['created'])) {
    $_SESSION['created'] = time();
} else if (time() - $_SESSION['created'] > 1800) {
    // Session berlaku 30 menit, lalu refresh ID
    session_regenerate_id(true);
    $_SESSION['created'] = time();
}
?>