<?php
require_once 'config.php'; // Memuat konfigurasi database dan memulai session

// Cek apakah user sudah login
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("location: index.php");
    exit;
}

// Cek apakah ada transaksi yang perlu dikonfirmasi dari session
if (!isset($_SESSION['current_transaction_id'])) {
    // Jika tidak ada transaksi yang tertunda, arahkan kembali ke dashboard atau riwayat transaksi
    $_SESSION['order_message_type'] = 'danger';
    $_SESSION['order_message'] = 'Tidak ada pesanan yang perlu dikonfirmasi pembayarannya.';
    header("location: dashboard.php"); // Atau ke transaction_history.php
    exit;
}

$transaction_id = $_SESSION['current_transaction_id'];
$transaction_code = $_SESSION['current_transaction_code']; // Ambil juga kode transaksi

$payment_message = '';
$payment_message_type = '';
$transaction_details = null;
$transaction_items = [];
$total_order_price = 0;

// ===============================================
// LOGIKA UNTUK CEK KELENGKAPAN PROFIL PENGGUNA
// ===============================================
$profile_incomplete = false;
$user_id = $_SESSION['id'];
try {
    if (isset($pdo)) {
        $sql_user_profile = "SELECT full_name, email, phone_number, address FROM users WHERE id = :id";
        $stmt_user_profile = $pdo->prepare($sql_user_profile);
        $stmt_user_profile->bindParam(':id', $user_id, PDO::PARAM_INT);
        $stmt_user_profile->execute();
        $user_profile = $stmt_user_profile->fetch(PDO::FETCH_ASSOC);

        if (empty($user_profile['full_name']) || empty($user_profile['email']) || empty($user_profile['phone_number']) || empty($user_profile['address'])) {
            $profile_incomplete = true;
        }
    }
} catch (PDOException $e) {
    error_log("Error fetching user profile data: " . $e->getMessage());
}
// ===============================================
// END LOGIKA CEK KELENGKAPAN PROFIL PENGGUNA
// ===============================================

// Ambil detail transaksi dari database
try {
    $sql_trans = "SELECT id, user_id, transaction_code, total_amount, status, order_date, shipping_courier FROM transactions WHERE id = :id AND user_id = :user_id";
    $stmt_trans = $pdo->prepare($sql_trans);
    $stmt_trans->bindParam(":id", $transaction_id, PDO::PARAM_INT);
    $stmt_trans->bindParam(":user_id", $_SESSION['id'], PDO::PARAM_INT);
    $stmt_trans->execute();
    $transaction_details = $stmt_trans->fetch(PDO::FETCH_ASSOC);

    if (!$transaction_details || $transaction_details['status'] != 'Waiting Payment') {
        // Jika transaksi tidak ditemukan atau statusnya sudah bukan 'Waiting Payment'
        unset($_SESSION['current_transaction_id']); // Hapus dari session agar tidak bisa diakses lagi
        unset($_SESSION['current_transaction_code']);
        $_SESSION['order_message_type'] = 'danger';
        $_SESSION['order_message'] = 'Pesanan tidak valid atau sudah diproses.';
        header("location: transaction_history.php");
        exit;
    }

    $transaction_code = $transaction_details['transaction_code']; // Pastikan kode transaksi yang tampil sesuai
    $total_order_price = $transaction_details['total_amount'];

    // Ambil detail item transaksi
    $sql_items = "SELECT ti.quantity, ti.price_at_purchase, p.name, p.image_path FROM transaction_items ti JOIN products p ON ti.product_id = p.id WHERE ti.transaction_id = :transaction_id";
    $stmt_items = $pdo->prepare($sql_items);
    $stmt_items->bindParam(":transaction_id", $transaction_id, PDO::PARAM_INT);
    $stmt_items->execute();
    $transaction_items = $stmt_items->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $payment_message = "Terjadi kesalahan saat memuat detail pesanan: " . $e->getMessage();
    $payment_message_type = 'danger';
    error_log("Error loading transaction details for payment confirmation: " . $e->getMessage());
}

// --- Proses Konfirmasi Pembayaran (Submit Form) ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['confirm_payment'])) {
    $sender_name = trim($_POST['sender_name']);
    $account_number = trim($_POST['account_number']);

    $errors = [];

    if (empty($sender_name)) {
        $errors[] = "Nama Pengirim tidak boleh kosong.";
    }
    if (empty($account_number)) {
        $errors[] = "Nomor Rekening tidak boleh kosong.";
    }

    // Penanganan upload bukti pembayaran
    $target_dir = "uploads/payment_proofs/";
    $uploaded_file_path = null;

    // Pastikan direktori uploads/payment_proofs ada
    if (!is_dir($target_dir)) {
        mkdir($target_dir, 0777, true);
    }

    if (isset($_FILES["payment_proof"]) && $_FILES["payment_proof"]["error"] == 0) {
        $file_name = basename($_FILES["payment_proof"]["name"]);
        $file_type = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        $unique_file_name = uniqid() . "." . $file_type;
        $target_file = $target_dir . $unique_file_name;
        $uploadOk = 1;

        // Cek apakah file gambar asli atau palsu
        $check = getimagesize($_FILES["payment_proof"]["tmp_name"]);
        if ($check !== false) {
            // File adalah gambar
        } else {
            $errors[] = "File yang diupload bukan gambar.";
            $uploadOk = 0;
        }

        // Cek ukuran file (maksimal 5MB)
        if ($_FILES["payment_proof"]["size"] > 5 * 1024 * 1024) { // 5 MB
            $errors[] = "Ukuran file terlalu besar. Maksimal 5MB.";
            $uploadOk = 0;
        }

        // Izinkan format file tertentu
        $allowed_types = array("jpg", "png", "jpeg", "gif");
        if (!in_array($file_type, $allowed_types)) {
            $errors[] = "Hanya file JPG, JPEG, PNG, & GIF yang diizinkan.";
            $uploadOk = 0;
        }

        // Jika tidak ada error upload, coba pindahkan file
        if ($uploadOk == 1) {
            if (!move_uploaded_file($_FILES["payment_proof"]["tmp_name"], $target_file)) {
                $errors[] = "Terjadi kesalahan saat mengupload bukti pembayaran.";
            } else {
                $uploaded_file_path = $target_file;
            }
        }
    } else {
        $errors[] = "Bukti pembayaran harus diupload.";
    }

    if (empty($errors)) {
        try {
            // Update transaksi di database
            $sql_update_trans = "UPDATE transactions SET
                                 payment_sender_name = :sender_name,
                                 payment_account_number = :account_number,
                                 payment_proof_image = :payment_proof_image,
                                 payment_date = NOW(),
                                 status = 'Payment Confirmed'
                                 WHERE id = :transaction_id AND user_id = :user_id AND status = 'Waiting Payment'";

            $stmt_update = $pdo->prepare($sql_update_trans);
            $stmt_update->bindParam(":sender_name", $sender_name, PDO::PARAM_STR);
            $stmt_update->bindParam(":account_number", $account_number, PDO::PARAM_STR);
            $stmt_update->bindParam(":payment_proof_image", $uploaded_file_path, PDO::PARAM_STR);
            $stmt_update->bindParam(":transaction_id", $transaction_id, PDO::PARAM_INT);
            $stmt_update->bindParam(":user_id", $_SESSION['id'], PDO::PARAM_INT);

            if ($stmt_update->execute()) {
                if ($stmt_update->rowCount() > 0) {
                    // Berhasil update, kosongkan session transaksi
                    unset($_SESSION['current_transaction_id']);
                    unset($_SESSION['current_transaction_code']);
                    $_SESSION['order_success_message'] = "Konfirmasi pembayaran Anda berhasil dikirim! Menunggu verifikasi admin.";
                    header("location: transaction_history.php");
                    exit;
                } else {
                    $payment_message = "Gagal memperbarui status pembayaran. Mungkin transaksi sudah diproses atau tidak valid.";
                    $payment_message_type = 'danger';
                }
            } else {
                $payment_message = "Terjadi kesalahan database saat mengkonfirmasi pembayaran.";
                $payment_message_type = 'danger';
            }
        } catch (PDOException $e) {
            $payment_message = "Gagal mengkonfirmasi pembayaran: " . $e->getMessage();
            $payment_message_type = 'danger';
            error_log("Payment confirmation failed: " . $e->getMessage());
        }
    } else {
        $payment_message = implode("<br>", $errors);
        $payment_message_type = 'danger';
    }
}

unset($pdo); // Tutup koneksi database
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Konfirmasi Pembayaran | WEARNITY</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Outfit:wght@700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #0f172a;
            --secondary-color: #6366f1;
            --accent-color: #f59e0b;
            --background-color: #f8fafc;
            --surface-color: #ffffff;
            --text-primary: #1e293b;
            --text-secondary: #64748b;
            --border-color: #e2e8f0;
            --radius-xl: 24px;
            --radius-lg: 16px;
            --radius-md: 12px;
            --shadow-sm: 0 1px 2px rgba(0, 0, 0, 0.05);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            --shadow-xl: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
            --font-display: 'Outfit', sans-serif;
            --font-body: 'Inter', sans-serif;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: var(--font-body);
            background-color: var(--background-color);
            color: var(--text-primary);
            line-height: 1.6;
            padding-top: 120px;
            -webkit-font-smoothing: antialiased;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }

        h1, h2, h3, .logo-text, .logo strong {
            font-family: var(--font-display);
        }

        a {
            text-decoration: none;
            color: inherit;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        /* Header */
        header {
            position: fixed;
            top: 15px;
            left: 50%;
            transform: translateX(-50%);
            width: 90%;
            max-width: 1400px;
            height: 70px;
            background: rgba(255, 255, 255, 0.85);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 30px;
            z-index: 1000;
            border-radius: 40px;
            border: 1px solid rgba(255, 255, 255, 0.3);
            box-shadow: var(--shadow-lg);
        }

        .logo strong {
            font-size: 20px;
            font-weight: 800;
            letter-spacing: -1px;
            color: var(--primary-color);
            text-transform: uppercase;
        }

        .nav-links {
            display: flex;
            gap: 20px;
            list-style: none;
            margin: 0;
            padding: 0;
        }

        .nav-links a {
            font-weight: 600;
            color: var(--text-secondary);
            font-size: 13.5px;
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            border-radius: 100px;
        }

        .nav-links a:hover,
        .nav-links a.active {
            color: var(--secondary-color);
            background: rgba(99, 102, 241, 0.08);
        }

        .nav-icons {
            display: flex;
            gap: 12px;
            align-items: center;
        }

        .nav-icons .icon-btn {
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            background: var(--background-color);
            font-size: 16px;
            color: var(--text-primary);
            border: 1px solid var(--border-color);
            position: relative;
        }

        .nav-icons .icon-btn:hover {
            background: var(--primary-color);
            color: white;
            transform: scale(1.05);
        }

        .notification-badge-dot {
            position: absolute;
            top: 2px;
            right: 2px;
            width: 12px;
            height: 12px;
            background: #ef4444;
            border: 2px solid white;
            border-radius: 50%;
            z-index: 10;
        }

        .profile-icon-container {
            position: relative;
            padding-bottom: 10px;
            margin-bottom: -10px;
        }

        .profile-dropdown {
            position: absolute;
            top: 50px;
            right: 0;
            width: 220px;
            background: var(--surface-color);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-xl);
            border: 1px solid var(--border-color);
            display: none;
            animation: slideDown 0.3s cubic-bezier(0.16, 1, 0.3, 1);
            overflow: hidden;
            padding: 8px;
            z-index: 1001;
        }

        .dropdown-warning {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 14px;
            margin: 4px 8px 8px;
            background: #fff7ed;
            color: #c2410c;
            font-size: 11px;
            font-weight: 700;
            border-radius: 8px;
            border: 1px solid #ffedd5;
        }

        .profile-dropdown ul li a {
            padding: 10px 14px;
            color: var(--text-primary);
            display: flex;
            gap: 12px;
            align-items: center;
            font-size: 14px;
            font-weight: 500;
            border-radius: var(--radius-md);
        }

        .profile-dropdown ul li a:hover {
            background: var(--background-color);
            color: var(--secondary-color);
        }

        .profile-icon-container:hover .profile-dropdown {
            display: block;
        }

        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Payment Container Layout */
        .payment-container {
            max-width: 1100px;
            margin: 0 auto 80px;
            padding: 0 20px;
        }

        .page-header {
            text-align: center;
            margin-bottom: 50px;
        }

        .page-header h1 {
            font-size: 3rem;
            color: var(--primary-color);
            letter-spacing: -2px;
            margin-bottom: 10px;
        }

        .page-header p {
            color: var(--text-secondary);
            font-size: 1.1rem;
            font-weight: 500;
        }

        .payment-grid {
            display: grid;
            grid-template-columns: 1.5fr 1fr;
            gap: 30px;
        }

        .card {
            background: white;
            border-radius: var(--radius-xl);
            padding: 40px;
            box-shadow: var(--shadow-lg);
            border: 1px solid rgba(0, 0, 0, 0.05);
            margin-bottom: 30px;
        }

        .card-title {
            font-size: 1.5rem;
            font-weight: 800;
            margin-bottom: 30px;
            color: var(--primary-color);
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .card-title i {
            color: var(--secondary-color);
            background: rgba(99, 102, 241, 0.1);
            width: 45px;
            height: 45px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 12px;
            font-size: 1.2rem;
        }

        /* Bank Info */
        .bank-list {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .bank-item {
            display: flex;
            align-items: center;
            gap: 25px;
            padding: 25px;
            background: #f8fafc;
            border-radius: var(--radius-lg);
            border: 2px solid var(--border-color);
            transition: all 0.3s;
        }

        .bank-item:hover {
            border-color: var(--secondary-color);
            transform: translateY(-2px);
            background: white;
            box-shadow: var(--shadow-md);
        }

        .bank-icon {
            width: 60px;
            height: 60px;
            background: white;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 1.2rem;
            color: var(--primary-color);
            box-shadow: var(--shadow-sm);
        }

        .bank-details h4 {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--primary-color);
            margin-bottom: 4px;
        }

        .bank-details p {
            color: var(--text-secondary);
            font-weight: 500;
        }

        .bank-details .acc-number {
            font-family: 'Inter', monospace;
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--secondary-color);
            letter-spacing: 1px;
        }

        /* QR Code Section */
        .qr-section {
            text-align: center;
            padding: 30px;
            background: #f1f5f9;
            border-radius: var(--radius-lg);
            margin-top: 30px;
        }

        .qr-code-img {
            width: 200px;
            height: 200px;
            background: white;
            padding: 15px;
            border-radius: 20px;
            box-shadow: var(--shadow-sm);
            margin-bottom: 20px;
        }

        /* Form Styling */
        .form-group {
            margin-bottom: 25px;
        }

        .form-group label {
            display: block;
            margin-bottom: 10px;
            font-weight: 700;
            font-size: 0.9rem;
            color: var(--text-primary);
        }

        .form-group input, .form-group textarea, .form-group select {
            width: 100%;
            padding: 16px 20px;
            border: 2px solid var(--border-color);
            border-radius: 14px;
            background: #f8fafc;
            font-family: var(--font-body);
            font-size: 0.95rem;
            transition: all 0.3s;
            color: var(--text-primary);
        }

        .form-group input:focus {
            border-color: var(--secondary-color);
            outline: none;
            background: white;
            box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.1);
        }

        .file-upload-wrapper {
            position: relative;
            width: 100%;
            height: 180px;
            border: 2px dashed var(--border-color);
            border-radius: 14px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            background: #f8fafc;
            transition: all 0.3s;
            cursor: pointer;
            overflow: hidden;
        }

        .file-upload-wrapper:hover {
            border-color: var(--secondary-color);
            background: rgba(99, 102, 241, 0.02);
        }

        .file-upload-wrapper i {
            font-size: 2rem;
            color: var(--text-secondary);
            margin-bottom: 15px;
        }

        .file-upload-wrapper p {
            font-weight: 600;
            color: var(--text-secondary);
        }

        .file-upload-wrapper input[type="file"] {
            position: absolute;
            top: 0; left: 0; width: 100%; height: 100%;
            opacity: 0;
            cursor: pointer;
        }

        .submit-btn {
            width: 100%;
            background: var(--primary-color);
            color: white;
            padding: 20px;
            border-radius: 16px;
            border: none;
            font-family: var(--font-display);
            font-size: 1.1rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            cursor: pointer;
            transition: all 0.3s;
            box-shadow: var(--shadow-lg);
        }

        .submit-btn:hover {
            transform: translateY(-2px);
            background: var(--secondary-color);
            box-shadow: 0 10px 25px rgba(99, 102, 241, 0.3);
        }

        /* Summary Sidebar */
        .summary-card {
            background: var(--primary-color);
            color: white;
            padding: 40px;
            border-radius: var(--radius-xl);
            height: fit-content;
            position: sticky;
            top: 110px;
            box-shadow: var(--shadow-xl);
        }

        .summary-card h3 {
            font-size: 1.5rem;
            margin-bottom: 30px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        /* Updated Summary Items */
        .summary-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 15px;
            color: rgba(255, 255, 255, 0.7);
            font-weight: 500;
        }

        /* Order Items Table */
        .order-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            margin-bottom: 30px;
        }

        .order-table th {
            text-align: left;
            padding: 15px;
            color: var(--text-secondary);
            font-size: 0.8rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            border-bottom: 1px solid var(--border-color);
        }

        .order-table td {
            padding: 20px 15px;
            vertical-align: middle;
            border-bottom: 1px solid #f1f5f9;
        }

        .product-cell {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .product-cell img {
            width: 50px;
            height: 60px;
            object-fit: cover;
            border-radius: 8px;
            box-shadow: var(--shadow-sm);
        }

        .product-cell .p-name {
            font-weight: 700;
            color: var(--primary-color);
            display: block;
        }

        .product-cell .p-qty {
            font-size: 0.85rem;
            color: var(--text-secondary);
        }

        /* Profile Popup */
        .profile-popup {
            position: fixed;
            bottom: 40px;
            right: 40px;
            background: rgba(255, 255, 255, 0.85);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            padding: 18px 35px;
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.12);
            border-radius: 35px;
            z-index: 2100;
            display: none;
            align-items: center;
            gap: 20px;
            border: 1px solid rgba(255, 255, 255, 0.4);
            border-left: 8px solid #f59e0b;
            min-width: 450px;
            max-width: 90vw;
            animation: slideUpPopup 0.8s cubic-bezier(0.16, 1, 0.3, 1);
        }

        /* Footer Updated to Match Beranda */
        .simple-footer {
            background: #111111;
            color: #6b7280;
            padding: 25px 7%;
            text-align: center;
            border-top: 1px solid rgba(255, 255, 255, 0.05);
        }

        .simple-footer p {
            font-size: 0.85rem;
            margin: 0;
            letter-spacing: 0.5px;
        }

        @keyframes slideUpPopup {
            from { opacity: 0; transform: translateY(40px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .popup-icon { color: #f59e0b; font-size: 28px; }

        /* Toast Notification */
        #toast-container {
            position: fixed;
            bottom: 30px;
            left: 30px;
            z-index: 9999;
        }

        .toast {
            background: var(--primary-color);
            color: white;
            padding: 16px 28px;
            border-radius: 16px;
            box-shadow: var(--shadow-xl);
            display: flex;
            align-items: center;
            gap: 12px;
            margin-top: 10px;
            animation: slideInLeft 0.5s cubic-bezier(0.16, 1, 0.3, 1);
            font-weight: 600;
            font-size: 0.95rem;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        /* Shopping Cart Sidebar Structure (Matched to Beranda) */
        .cart-overlay {
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0, 0, 0, 0.4);
            backdrop-filter: blur(8px);
            z-index: 3000;
            display: none;
        }

        .cart-sidebar {
            position: fixed;
            top: 0; right: -450px; width: 450px; height: 100%;
            background: rgba(255, 255, 255, 0.98);
            backdrop-filter: blur(20px);
            box-shadow: -15px 0 50px rgba(0, 0, 0, 0.15);
            z-index: 3001;
            transition: all 0.6s cubic-bezier(0.16, 1, 0.3, 1);
            display: flex;
            flex-direction: column;
        }

        .cart-sidebar.open {
            right: 0;
        }

        /* Cart Sidebar Internal Styles (Matched to Screenshot) */
        .cart-header { 
            padding: 35px 40px; 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            border-bottom: 1px solid #f1f5f9;
        }

        .cart-header h3 { 
            font-size: 1.8rem; 
            font-weight: 800; 
            color: #0f172a; 
            font-family: var(--font-display);
            letter-spacing: -0.5px;
            margin: 0;
        }

        .close-cart-btn { 
            background: #f1f5f9; 
            border: none; 
            width: 38px; 
            height: 38px; 
            border-radius: 50%; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            font-size: 18px; 
            cursor: pointer; 
            color: #64748b; 
            transition: all 0.3s; 
        }

        .close-cart-btn:hover { background: #fee2e2; color: #ef4444; }

        .cart-items-list { 
            flex: 1; 
            overflow-y: auto; 
            padding: 40px; 
            display: flex;
            flex-direction: column;
            gap: 25px;
        }

        .empty-cart-message { 
            text-align: center; 
            color: #94a3b8; 
            padding: 120px 20px; 
            font-size: 1.1rem; 
            font-weight: 500;
        }

        .cart-item { 
            display: flex; 
            gap: 20px; 
            padding-bottom: 25px; 
            border-bottom: 1px solid #f1f5f9; 
            align-items: center; 
        }

        .cart-item img { 
            width: 85px; 
            height: 110px; 
            object-fit: cover; 
            border-radius: 14px; 
            box-shadow: 0 4px 12px rgba(0,0,0,0.05); 
        }

        .item-details { flex: 1; display: flex; flex-direction: column; gap: 10px; }
        .item-name { font-weight: 700; font-size: 1.05rem; color: #0f172a; }
        
        .quantity-controls { 
            display: flex; 
            align-items: center; 
            gap: 12px; 
            background: #f8fafc; 
            padding: 5px 12px; 
            border-radius: 100px; 
            width: fit-content; 
        }

        .qty-btn { 
            background: white; 
            border: 1px solid #e2e8f0; 
            width: 26px; 
            height: 26px; 
            border-radius: 50%; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            font-size: 10px; 
            cursor: pointer; 
            transition: all 0.2s; 
        }

        .qty-btn:hover { background: #0f172a; color: white; border-color: #0f172a; }
        .item-quantity { font-weight: 700; font-size: 0.9rem; min-width: 22px; text-align: center; }

        .item-actions { display: flex; flex-direction: column; align-items: flex-end; gap: 12px; }
        .item-price { font-weight: 800; color: #0f172a; font-size: 1rem; }
        .remove-item-btn { color: #cbd5e1; background: none; border: none; cursor: pointer; font-size: 1.1rem; transition: all 0.3s; }
        .remove-item-btn:hover { color: #ef4444; }

        .cart-summary { 
            padding: 40px; 
            background: #ffffff; 
            border-top: 1px solid #f1f5f9;
        }

        .total-price { 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            margin-bottom: 30px; 
        }

        .total-price span:first-child { 
            color: #64748b; 
            font-weight: 600; 
            font-size: 1.05rem; 
        }

        .total-price span:last-child { 
            color: #0f172a; 
            font-weight: 800; 
            font-size: 1.8rem; 
            letter-spacing: -0.5px;
        }

        .checkout-btn { 
            width: 100%; 
            background: #0f172a; 
            color: white; 
            padding: 22px; 
            border-radius: 20px; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            text-decoration: none; 
            transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1);
            box-shadow: 0 10px 30px rgba(15, 23, 42, 0.2);
        }

        .checkout-btn i {
            font-size: 1.5rem;
        }

        .checkout-btn:hover { 
            background: #000;
            transform: translateY(-4px);
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.3);
        }

        @media (max-width: 768px) {
            .page-header h1 { font-size: 2.2rem; }
            .nav-links { display: none; }
            .card { padding: 25px; }
            .cart-sidebar { width: 100%; right: -100%; }
        }
    </style>
</head>

<body>
    <header>
        <div class="logo">
            <a href="dashboard.php"><strong>WEARNITY.</strong></a>
        </div>
        <nav>
            <ul class="nav-links">
                <li><a href="dashboard.php"><i class="fas fa-home"></i> Beranda</a></li>
                <li><a href="dashboard.php#katalog"><i class="fas fa-shirt"></i> Katalog</a></li>
                <li><a href="dashboard.php#cerita-kami"><i class="fas fa-info-circle"></i> Cerita Kami</a></li>
                <li><a href="dashboard.php#hubungi-kami"><i class="fas fa-phone"></i> Hubungi Kami</a></li>
                <li><a href="transaction_history.php"><i class="fas fa-receipt"></i> Riwayat Transaksi</a></li>
            </ul>
        </nav>
        <div class="nav-icons" style="position: relative;">
            <a href="#" class="icon-btn" id="cartIcon">
                <i class="fas fa-shopping-cart"></i>
                <span class="notification-badge-dot"></span>
            </a>
            <div class="profile-icon-container">
                <a href="profile.php" class="icon-btn" id="profileIcon">
                    <i class="fas fa-user"></i>
                    <?php if ($profile_incomplete): ?>
                        <span class="notification-badge-dot"></span>
                    <?php endif; ?>
                </a>
                <div class="profile-dropdown" id="profileDropdown">
                    <?php if ($profile_incomplete): ?>
                        <div class="dropdown-warning">
                            <i class="fas fa-exclamation-triangle"></i> Profil Belum Lengkap
                        </div>
                    <?php endif; ?>
                    <ul>
                        <li><a href="profile.php"><i class="fas fa-user-circle"></i> Profil Saya</a></li>
                        <li><a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </header>

    <main>
        <div class="payment-container">
            <div class="page-header">
                <h1>Konfirmasi Pembayaran</h1>
                <p>Selesaikan pembayaran lo biar barang langsung meluncur!</p>
            </div>

            <?php if (!empty($payment_message)): ?>
                <div class="alert alert-<?php echo $payment_message_type; ?>">
                    <i class="fas <?php echo $payment_message_type == 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?>"></i>
                    <span><?php echo $payment_message; ?></span>
                </div>
            <?php endif; ?>

            <?php if ($transaction_details): ?>
                <div class="payment-grid">
                    <div class="main-column">
                        <div class="card">
                            <div class="card-title">
                                <i class="fas fa-shopping-bag"></i>
                                <span>Detail Pesanan</span>
                            </div>
                            <div class="order-id" style="font-size: 0.95rem; color: var(--text-secondary); margin-bottom: 25px; font-weight: 600;">
                                ORDER CODE: <span style="color: var(--secondary-color);">#<?php echo htmlspecialchars($transaction_details['transaction_code']); ?></span>
                            </div>
                            
                            <table class="order-table">
                                <thead>
                                    <tr>
                                        <th>Produk</th>
                                        <th style="text-align: right;">Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($transaction_items as $item): ?>
                                        <tr>
                                            <td>
                                                <div class="product-cell">
                                                    <img src="<?php echo htmlspecialchars($item['image_path']); ?>" alt="<?php echo htmlspecialchars($item['name']); ?>">
                                                    <div>
                                                        <span class="p-name"><?php echo htmlspecialchars($item['name']); ?></span>
                                                        <span class="p-qty">x<?php echo $item['quantity']; ?> @ Rp <?php echo number_format($item['price_at_purchase'], 0, ',', '.'); ?></span>
                                                    </div>
                                                </div>
                                            </td>
                                            <td style="text-align: right; font-weight: 700; color: var(--primary-color);">
                                                Rp <?php echo number_format($item['price_at_purchase'] * $item['quantity'], 0, ',', '.'); ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="card">
                            <div class="card-title">
                                <i class="fas fa-university"></i>
                                <span>Pilih Bank Transfer</span>
                            </div>
                            <div class="bank-list">
                                <div class="bank-item">
                                    <div class="bank-icon">BCA</div>
                                    <div class="bank-details">
                                        <h4>Bank Central Asia (BCA)</h4>
                                        <p>No. Rekening: <span class="acc-number">1234567890</span></p>
                                        <p>A/N: PT WEARNITY INDONESIA</p>
                                    </div>
                                </div>
                                <div class="bank-item">
                                    <div class="bank-icon">MND</div>
                                    <div class="bank-details">
                                        <h4>Bank Mandiri</h4>
                                        <p>No. Rekening: <span class="acc-number">0987654321</span></p>
                                        <p>A/N: PT WEARNITY INDONESIA</p>
                                    </div>
                                </div>
                            </div>

                            <div class="qr-section">
                                <p style="margin-bottom: 15px; font-weight: 600;">Atau scan QRIS di bawah ini:</p>
                                <img src="Asset/QR.jpg" alt="QRIS Wearnity" class="qr-code-img">
                                <p style="font-size: 0.85rem; color: var(--text-secondary);">Mendukung semua e-wallet & mobile banking</p>
                            </div>
                        </div>

                        <div class="card">
                            <div class="card-title">
                                <i class="fas fa-upload"></i>
                                <span>Upload Bukti Bayar</span>
                            </div>
                            <form class="payment-form" action="payment_confirmation.php" method="POST" enctype="multipart/form-data">
                                <div class="form-group">
                                    <label>Nama Pemilik Rekening / Pengirim</label>
                                    <input type="text" name="sender_name" placeholder="Contoh: Budi Santoso" required value="<?php echo htmlspecialchars($_POST['sender_name'] ?? ''); ?>">
                                </div>
                                <div class="form-group">
                                    <label>Nomor Rekening Pengirim</label>
                                    <input type="text" name="account_number" placeholder="Contoh: 123xxxxxxx" required value="<?php echo htmlspecialchars($_POST['account_number'] ?? ''); ?>">
                                </div>
                                <div class="form-group">
                                    <label>Bukti Pembayaran (JPG/PNG)</label>
                                    <div class="file-upload-wrapper" id="uploadArea">
                                        <i class="fas fa-cloud-upload-alt"></i>
                                        <p id="fileNameDisplay">Klik atau seret foto bukti pembayaran ke sini</p>
                                        <input type="file" id="payment_proof" name="payment_proof" accept="image/*" required>
                                    </div>
                                </div>
                                <button type="submit" name="confirm_payment" class="submit-btn" id="submitBtn">
                                    <i class="fas fa-check-circle"></i>
                                    Konfirmasi & Bayar Sekarang
                                </button>
                            </form>
                        </div>
                    </div>

                    <div class="sidebar-column">
                        <div class="summary-card">
                            <h3>
                                <span>Ringkasan Tagihan</span>
                                <i class="fas fa-receipt"></i>
                            </h3>
                            
                            <div class="summary-item">
                                <span>Subtotal Produk</span>
                                <span>Rp <?php echo number_format($total_order_price, 0, ',', '.'); ?></span>
                            </div>
                            <div class="summary-item">
                                <span>Biaya Pengiriman</span>
                                <span style="color: #4ade80; font-weight: 700;">GRATIS</span>
                            </div>

                            <div class="summary-total">
                                <span class="label">Total Bayar</span>
                                <span class="amount">Rp <?php echo number_format($total_order_price, 0, ',', '.'); ?></span>
                            </div>
                            
                            <div style="margin-top: 30px; font-size: 0.85rem; color: rgba(255,255,255,0.6); line-height: 1.6;">
                                <i class="fas fa-info-circle"></i> Pastikan nominal transfer pas sampai ke digit terakhir ya biar otomatis terverifikasi!
                            </div>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div class="card" style="text-align: center;">
                    <i class="fas fa-search" style="font-size: 3rem; color: var(--text-secondary); margin-bottom: 20px;"></i>
                    <h3>Pesanan Tidak Ditemukan</h3>
                    <p>Mungkin pesanan ini sudah diproses atau dihapus. Cek riwayat transaksi lo ya!</p>
                    <a href="transaction_history.php" class="submit-btn" style="margin-top: 30px; display: inline-flex; width: auto; padding: 15px 40px;">Buka Riwayat Transaksi</a>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <footer class="simple-footer">
        <p>&copy; 2025 Wearnity by THANKSINSOMNIA. All Rights Reserved.</p>
    </footer>

    <?php if ($profile_incomplete): ?>
        <div id="profileCompletionPopup" class="profile-popup">
            <i class="fas fa-exclamation-circle popup-icon"></i>
            <p>Profil lo belum lengkap nih! <a href="profile.php">Lengkapi sekarang</a> biar belanja makin asik.</p>
            <button class="close-popup-btn">&times;</button>
        </div>
    <?php endif; ?>

    <div id="toast-container"></div>
    <div class="cart-overlay" id="cartOverlay"></div>
    <div class="cart-sidebar" id="cartSidebar"></div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Script untuk menandai link navigasi yang sedang aktif
            const currentPath = window.location.pathname.split('/').pop();
            const navLinks = document.querySelectorAll('.nav-links li a');

            navLinks.forEach(link => {
                const linkHref = link.getAttribute('href');
                const linkPath = linkHref ? linkHref.split('/').pop().split('#')[0] : '';
                
                // For payment_confirmation, it might not be a direct link in nav, 
                // but we check anyway.
                if (linkPath === currentPath) {
                    link.classList.add('active');
                }
            });

            // Profile Popup Logic
            const profilePopup = document.getElementById('profileCompletionPopup');
            if (profilePopup) {
                setTimeout(() => profilePopup.classList.add('show'), 1500);
                const closePopupBtn = profilePopup.querySelector('.close-popup-btn');
                if (closePopupBtn) {
                    closePopupBtn.onclick = () => profilePopup.style.display = 'none';
                }
            }

            // Toast Notification
            function showToast(msg, type = 'success') {
                const container = document.getElementById('toast-container');
                if (!container) return;
                const toast = document.createElement('div');
                toast.className = `toast ${type}`;
                toast.innerHTML = `<i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'}"></i><span>${msg}</span>`;
                container.appendChild(toast);
                setTimeout(() => {
                    toast.classList.add('fade-out');
                    setTimeout(() => toast.remove(), 500);
                }, 3000);
            }

            // File Upload Preview
            const fileInput = document.getElementById('payment_proof');
            const fileNameDisplay = document.getElementById('fileNameDisplay');
            const uploadArea = document.getElementById('uploadArea');

            if (fileInput) {
                fileInput.addEventListener('change', function(e) {
                    if (this.files && this.files[0]) {
                        fileNameDisplay.textContent = this.files[0].name;
                        fileNameDisplay.style.color = 'var(--secondary-color)';
                        uploadArea.style.borderColor = 'var(--secondary-color)';
                        uploadArea.style.background = 'rgba(99, 102, 241, 0.05)';
                        showToast('Berhasil memilih file!', 'success');
                    }
                });
            }

            // Shopping Cart Logic (Slider from Right)
            const cartSidebar = document.getElementById('cartSidebar');
            const cartOverlay = document.getElementById('cartOverlay');
            const cartIcon = document.getElementById('cartIcon');

            function toggleCartSidebar() {
                if (cartSidebar && cartOverlay) {
                    cartSidebar.classList.toggle('open');
                    cartOverlay.style.display = cartSidebar.classList.contains('open') ? 'block' : 'none';
                    document.body.style.overflow = cartSidebar.classList.contains('open') ? 'hidden' : '';
                    if (cartSidebar.classList.contains('open')) {
                        loadCartContent();
                    }
                }
            }

            if (cartIcon) {
                cartIcon.addEventListener('click', (e) => {
                    e.preventDefault();
                    toggleCartSidebar();
                });
            }

            if (cartOverlay) {
                cartOverlay.addEventListener('click', toggleCartSidebar);
            }

            function loadCartContent() {
                if (!cartSidebar) return;
                fetch('_cart_content.php')
                    .then(r => {
                        if (!r.ok) throw new Error('Network response failure');
                        return r.text();
                    })
                    .then(html => {
                        cartSidebar.innerHTML = html;
                        attachCartItemListeners();
                    })
                    .catch(err => {
                        console.error('Error loading cart:', err);
                        showToast('Gagal memuat keranjang', 'danger');
                    });
            }

            function attachCartItemListeners() {
                const closeCartBtn = document.querySelector('#cartSidebar .close-cart-btn');
                if (closeCartBtn) {
                    closeCartBtn.addEventListener('click', function() {
                        toggleCartSidebar();
                    });
                }

                document.querySelectorAll('#cartSidebar .qty-btn').forEach(button => {
                    button.onclick = function() {
                        const productId = this.closest('.cart-item').dataset.productId;
                        let currentQty = parseInt(this.closest('.quantity-controls').querySelector('.item-quantity').textContent);
                        let newQty = this.dataset.action === 'plus' ? currentQty + 1 : currentQty - 1;
                        
                        if (newQty <= 0) {
                            if (confirm('Hapus produk dari keranjang?')) {
                                updateCartItemQuantity(productId, 0);
                            }
                        } else {
                            updateCartItemQuantity(productId, newQty);
                        }
                    };
                });

                document.querySelectorAll('#cartSidebar .remove-item-btn').forEach(button => {
                    button.onclick = function() {
                        if (confirm('Hapus produk dari keranjang?')) {
                            const productId = this.closest('.cart-item').dataset.productId;
                            updateCartItemQuantity(productId, 0);
                        }
                    };
                });

                const checkoutBtn = document.getElementById('checkoutBtn');
                if (checkoutBtn) {
                    checkoutBtn.addEventListener('click', function(event) {
                        event.preventDefault();
                        window.location.href = 'order_details.php';
                    });
                }
            }

            function updateCartItemQuantity(pid, qty) {
                fetch('cart_actions.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `action=update_quantity&product_id=${pid}&quantity=${qty}`
                }).then(r => r.json()).then(data => {
                    if (data.success) {
                        loadCartContent();
                        showToast(qty === 0 ? 'Item dihapus' : 'Keranjang diperbarui', 'success');
                    } else {
                        showToast(data.message, 'danger');
                    }
                }).catch(err => console.error('Error updating cart:', err));
            }

            attachCartItemListeners();
        });
    </script>
</body>
</html>
