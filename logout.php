<?php
// Mulai session (diperlukan untuk mengakses $_SESSION)
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Hapus semua variabel session
$_SESSION = array();

// Hancurkan session
session_destroy();

// Redirect ke halaman login
header("location: index.php");
exit;
?>