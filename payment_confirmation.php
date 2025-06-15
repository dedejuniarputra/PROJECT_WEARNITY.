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
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* General Styles & Resets */
        body {
            font-family: 'Montserrat', sans-serif; /* Menggunakan Montserrat */
            margin: 0;
            padding: 0;
            background-color: #f0f2f5; /* Background yang lebih soft */
            color: #333;
            line-height: 1.6;
            overflow-x: hidden;
            padding-top: 70px; /* Add padding-top to body equal to header height */
        }

        a {
            text-decoration: none;
            color: inherit;
        }

        /* Header / Navigation Bar (Consistent with dashboard/order_details) */
        header {
            background-color: #ffffff;
            padding: 15px 50px;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.08);
            display: flex;
            justify-content: space-between;
            align-items: center;
            z-index: 100;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            box-sizing: border-box;
        }

        /* Logo Text Styling (Consistent with dashboard/order_details) */
        .logo strong {
            font-size: 28px;
            color: #2c3e50; /* Darker color for logo text */
            letter-spacing: 1px;
            font-weight: 700;
            font-family: 'Montserrat', sans-serif; /* Pastikan font Montserrat juga di sini */
        }

        .nav-links {
            list-style: none;
            margin: 0;
            padding: 0;
            display: flex;
            gap: 40px;
        }

        .nav-links li a {
            font-size: 16px;
            color: #555;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 5px 0;
        }

        .nav-links li a i {
            font-size: 14px;
            color: #888;
            transition: color 0.3s ease;
        }

        .nav-links li a:hover,
        .nav-links li a.active {
            color: #007bff;
        }

        .nav-links li a:hover i,
        .nav-links li a.active i {
            color: #007bff;
        }

        .nav-icons {
            display: flex;
            gap: 25px;
            position: relative;
        }

        .nav-icons .icon-btn {
            font-size: 22px;
            color: #777;
            cursor: pointer;
            transition: color 0.3s ease;
        }

        .nav-icons .icon-btn:hover {
            color: #007bff;
        }

        /* Profile Dropdown (Consistent with dashboard/order_details) */
        .profile-dropdown {
            position: absolute;
            top: 45px;
            right: 0;
            background-color: #fff;
            border: 1px solid #eee;
            border-radius: 8px;
            box-shadow: 0 6px 15px rgba(0, 0, 0, 0.15);
            z-index: 1000;
            display: none;
            width: 180px;
            overflow: hidden;
            animation: fadeIn 0.2s ease-out forwards;
        }

        .profile-dropdown.show {
            display: block;
        }

        .profile-dropdown ul {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .profile-dropdown ul li a {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 15px;
            color: #444;
            transition: background-color 0.3s ease, color 0.3s ease;
            font-size: 15px;
        }

        .profile-dropdown ul li a i {
            color: #777;
            font-size: 16px;
        }

        .profile-dropdown ul li a:hover {
            background-color: #f5f5f5;
            color: #007bff;
        }

        .profile-dropdown ul li a:hover i {
            color: #007bff;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Shopping Cart Sidebar Styles (Consistent with dashboard/order_details) */
        .cart-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 1001;
            display: none;
        }

        .cart-sidebar {
            font-family: 'Montserrat', sans-serif;
            position: fixed;
            top: 0;
            right: -400px;
            width: 350px;
            height: 100%;
            background-color: #fff;
            box-shadow: -5px 0 20px rgba(0, 0, 0, 0.25);
            z-index: 1002;
            transition: right 0.3s ease-in-out;
            display: flex;
            flex-direction: column;
        }

        .cart-sidebar.open {
            right: 0;
        }

        .cart-header {
            padding: 20px 25px;
            border-bottom: 1px solid #e0e0e0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background-color: #fcfcfc;
        }

        .cart-header h3 {
            margin: 0;
            font-size: 24px;
            color: #333;
            font-weight: 600;
        }

        .close-cart-btn {
            background: none;
            border: none;
            font-size: 28px;
            cursor: pointer;
            color: #777;
            padding: 5px;
            line-height: 1;
            transition: color 0.2s ease;
        }

        .close-cart-btn:hover {
            color: #333;
        }

        .cart-items-list {
            flex-grow: 1;
            overflow-y: auto;
            padding: 25px;
            -webkit-overflow-scrolling: touch;
        }

        .cart-item {
            display: flex;
            align-items: center;
            margin-bottom: 20px;
            padding: 15px;
            border: 1px solid #f0f0f0;
            border-radius: 10px;
            background-color: #fff;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            transition: box-shadow 0.2s ease;
        }

        .cart-item:hover {
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        .cart-item img {
            width: 70px;
            height: 70px;
            object-fit: cover;
            border-radius: 8px;
            margin-right: 15px;
            flex-shrink: 0;
        }

        .cart-item .item-details {
            flex-grow: 1;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .cart-item .item-name {
            font-weight: 600;
            font-size: 1.05em;
            color: #333;
            margin-bottom: 8px;
        }

        .cart-item .quantity-controls {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 1em;
            color: #555;
        }

        .cart-item .qty-btn {
            background-color: #f0f2f5;
            border: 1px solid #ddd;
            border-radius: 50%;
            width: 30px;
            height: 30px;
            display: flex;
            justify-content: center;
            align-items: center;
            cursor: pointer;
            transition: background-color 0.2s ease, color 0.2s ease, border-color 0.2s ease;
            font-size: 1.1em;
            color: #555;
        }

        .cart-item .qty-btn:hover {
            background-color: #007bff;
            color: white;
            border-color: #007bff;
        }

        .cart-item .qty-btn i {
            font-size: 0.9em;
        }

        .cart-item .item-quantity {
            font-weight: 500;
            color: #333;
        }

        .cart-item .item-actions {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            margin-left: 15px;
            flex-shrink: 0;
        }

        .cart-item .item-price {
            font-weight: 700;
            color: #007bff;
            font-size: 1.2em;
            margin-bottom: 8px;
            white-space: nowrap;
        }

        .cart-item .remove-item-btn {
            background: none;
            border: none;
            color: #dc3545;
            cursor: pointer;
            font-size: 1em;
            transition: color 0.2s ease;
            padding: 5px;
        }

        .cart-item .remove-item-btn:hover {
            color: #c82333;
        }

        .cart-summary {
            border-top: 1px solid #e0e0e0;
            padding: 25px;
            background-color: #fcfcfc;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .cart-summary .total-price {
            font-size: 1.4em;
            font-weight: 700;
            color: #333;
            display: flex;
            flex-direction: column;
            line-height: 1.2;
        }

        .cart-summary .total-price span:first-child {
            font-size: 0.8em;
            color: #777;
            font-weight: 500;
        }

        .cart-summary .total-price span:last-child {
            color: #007bff;
        }

        .checkout-btn {
            background-color: #007bff;
            color: white;
            padding: 18px;
            border: none;
            border-radius: 50%;
            cursor: pointer;
            font-size: 22px;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 60px;
            height: 60px;
            transition: background-color 0.3s ease, transform 0.2s ease;
            box-shadow: 0 4px 10px rgba(0, 123, 255, 0.3);
        }

        .checkout-btn:hover {
            background-color: #0056b3;
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(0, 123, 255, 0.4);
        }

        .empty-cart-message {
            text-align: center;
            color: #777;
            margin-top: 50px;
            font-size: 1.1em;
            padding: 0 20px;
        }
        /* End of Shopping Cart Sidebar Styles */

        /* Payment Confirmation Specific Styles (Keep as is, or adjust slightly if needed) */
        .payment-container {
            max-width: 800px;
            margin: 40px auto;
            background-color: #fff;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 0 15px rgba(0, 0, 0, 0.1);
        }

        .payment-container h2 {
            text-align: center;
            margin-bottom: 30px;
            color: #333;
            font-weight: 700; /* Consistent with other titles */
        }

        .payment-section {
            margin-bottom: 30px;
            border: 1px solid #eee;
            border-radius: 8px;
            padding: 20px;
            background-color: #f9f9f9;
        }

        .payment-section h3 {
            color: #007bff;
            margin-top: 0;
            margin-bottom: 15px;
            font-size: 1.6em;
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 600; /* Consistent with other subtitles */
        }

        .payment-info {
            text-align: center;
            margin-bottom: 25px;
        }

        .payment-info img.qr-code {
            width: 200px;
            height: 200px;
            border: 1px solid #ddd;
            border-radius: 8px;
            margin-bottom: 15px;
        }

        .payment-info p {
            margin: 5px 0;
            font-size: 1.1em;
            color: #555;
        }

        .payment-info .bank-details {
            background-color: #e6f7ff;
            border: 1px dashed #007bff;
            padding: 15px;
            border-radius: 8px;
            margin-top: 20px;
        }

        .payment-info .bank-details strong {
            display: block;
            margin-bottom: 5px;
            font-size: 1.2em;
            color: #007bff;
        }

        .payment-form .form-group {
            margin-bottom: 20px;
        }

        .payment-form label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600; /* Consistent with order_details */
            color: #444; /* Consistent with order_details */
            font-size: 15px; /* Consistent with order_details */
        }

        .payment-form input[type="text"],
        .payment-form input[type="file"] {
            width: calc(100% - 22px); /* Adjusting for padding */
            padding: 12px 15px; /* Consistent with dashboard/order_details */
            border: 1px solid #ddd;
            border-radius: 8px; /* Consistent with dashboard/order_details */
            font-size: 16px;
            box-sizing: border-box; /* Include padding in width */
            transition: border-color 0.3s ease, box-shadow 0.3s ease; /* Consistent */
        }
        .payment-form input[type="text"]:focus,
        .payment-form input[type="file"]:focus {
            border-color: #007bff;
            box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.25); /* Consistent */
            outline: none;
        }

        .payment-form input[type="file"] {
            padding: 10px 15px; /* Adjust padding for file input visually */
            cursor: pointer;
            background-color: #fff;
        }

        .payment-form input[type="text"].is-invalid,
        .payment-form input[type="file"].is-invalid {
            border-color: #dc3545;
        }

        .payment-form button {
            background-color: #007bff; /* Changed to primary blue */
            color: white;
            padding: 15px 25px;
            border: none;
            border-radius: 8px; /* Consistent with other buttons */
            cursor: pointer;
            font-size: 1.1em;
            width: 100%;
            transition: background-color 0.3s ease, transform 0.2s ease; /* Consistent */
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            font-weight: 600; /* Consistent */
            margin-top: 30px;
        }

        .payment-form button:hover {
            background-color: #0056b3; /* Darker blue on hover */
            transform: translateY(-2px); /* Consistent */
            box-shadow: 0 2px 8px rgba(0,0,0,0.1); /* Consistent */
        }

        .alert {
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            font-size: 1em;
        }

        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-danger {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .invalid-feedback {
            color: red;
            font-size: 0.9em;
            margin-top: 5px;
            display: block;
        }

        /* Re-use from order_details for product display */
        .order-items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }

        .order-items-table th,
        .order-items-table td {
            border: 1px solid #ddd;
            padding: 12px;
            text-align: left;
        }

        .order-items-table th {
            background-color: #f2f2f2;
            color: #555;
            font-weight: bold;
        }

        .order-items-table tr:nth-child(even) {
            background-color: #fdfdfd;
        }

        .product-item-details {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .product-item-details img {
            width: 60px;
            height: 60px;
            object-fit: cover;
            border-radius: 4px;
        }

        .order-summary {
            display: flex;
            justify-content: flex-end;
            margin-top: 20px;
            margin-bottom: 30px;
        }

        .summary-box {
            width: 100%;
            max-width: 350px;
            background-color: #e6f7ff;
            border: 1px solid #cceeff;
            border-radius: 8px;
            padding: 20px;
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
            font-size: 1.1em;
        }

        .summary-row.total {
            font-size: 1.4em;
            font-weight: bold;
            color: #007bff;
            border-top: 1px dashed #007bff;
            padding-top: 15px;
            margin-top: 15px;
        }

        /* UPDATED FOOTER STYLES (Copied from dashboard/transaction_history) */
        footer {
            background-color: #2c3e50;
            color: #ecf0f1;
            padding: 20px 20px;
            text-align: center;
            font-size: 0.9em;
            box-shadow: 0 -4px 15px rgba(0, 0, 0, 0.1);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: 80px;
            margin-top: 50px;
        }

        footer p {
            margin: 0;
            font-weight: 500;
            letter-spacing: 0.5px;
        }

        .footer-social {
            margin-top: 15px;
            display: flex;
            gap: 15px;
        }

        .footer-social a {
            color: #ecf0f1;
            font-size: 20px;
            transition: color 0.3s ease, transform 0.2s ease;
        }

        .footer-social a:hover {
            color: #007bff;
            transform: translateY(-2px);
        }

        /* Responsive */
        @media (max-width: 992px) {
            header {
                padding: 15px 30px;
            }
            .nav-links {
                gap: 25px;
            }
        }

        @media (max-width: 768px) {
            body {
                padding-top: 130px;
            }
            header {
                flex-direction: column;
                align-items: flex-start;
                padding: 15px 20px;
            }
            .logo strong {
                font-size: 24px;
            }
            .nav-links {
                margin-top: 0;
                flex-wrap: wrap;
                justify-content: center;
                gap: 15px;
            }
            .nav-links li {
                width: 100%;
                text-align: center;
            }
            .nav-links li a {
                justify-content: center;
            }
            .nav-icons {
                margin-top: 15px;
                width: 100%;
                justify-content: center;
            }
            .profile-dropdown {
                top: auto;
                bottom: -5px;
                left: 50%;
                transform: translateX(-50%) translateY(-100%);
            }
            .cart-sidebar {
                width: 100%;
                right: -100%;
            }
            .cart-sidebar.open {
                width: 100%;
            }
            .cart-items-list {
                padding: 20px;
            }
            .cart-item {
                flex-wrap: wrap;
                justify-content: center;
                text-align: center;
            }
            .cart-item img {
                margin-right: 0;
                margin-bottom: 10px;
            }
            .cart-item .item-details,
            .cart-item .item-actions {
                width: 100%;
                align-items: center;
                text-align: center;
            }
            .cart-item .quantity-controls {
                justify-content: center;
            }
            .cart-summary {
                flex-direction: column;
                padding: 20px;
            }
            .cart-summary .total-price,
            .checkout-btn {
                width: 100%;
                max-width: 250px;
            }
            .checkout-btn {
                margin-top: 15px;
            }
            .payment-container {
                margin: 20px;
                padding: 20px;
            }
            .order-items-table, .summary-box {
                max-width: 100%;
            }
            .order-items-table th, .order-items-table td {
                padding: 8px;
                font-size: 0.9em;
            }
            .product-item-details {
                flex-direction: column;
                align-items: flex-start;
                gap: 8px;
            }
            .product-item-details img {
                width: 50px;
                height: 50px;
            }
            .summary-row {
                font-size: 1em;
            }
            .summary-row.total {
                font-size: 1.2em;
            }
            .payment-form button {
                font-size: 1em;
                padding: 12px 20px;
            }
            footer {
                padding: 15px 15px;
                min-height: 70px;
            }
            footer p {
                font-size: 0.8em;
            }
            .footer-social {
                margin-top: 10px;
                gap: 10px;
            }
            .footer-social a {
                font-size: 16px;
            }
        }

        @media (max-width: 480px) {
            .logo strong {
                font-size: 22px;
            }
            .nav-links li a {
                font-size: 15px;
                gap: 5px;
            }
            .nav-icons .icon-btn {
                font-size: 20px;
            }
            .payment-section h3 {
                font-size: 1.5em;
            }
            .order-items-table thead {
                display: none; /* Hide header on small screens */
            }
            .order-items-table tr {
                display: block;
                margin-bottom: 15px;
                border: 1px solid #ddd;
                border-radius: 8px;
            }
            .order-items-table td {
                display: block;
                width: 100%;
                box-sizing: border-box;
                text-align: right;
                padding-left: 50%;
                position: relative;
                border: none;
                border-bottom: 1px solid #eee;
            }
            .order-items-table td:last-child {
                border-bottom: none;
            }
            .order-items-table td::before {
                content: attr(data-label);
                position: absolute;
                left: 10px;
                width: 45%;
                white-space: nowrap;
                text-align: left;
                font-weight: bold;
            }
            .product-item-details {
                align-items: center;
                flex-direction: row; /* Keep horizontal on mobile if table converts */
            }
            .product-item-details img {
                margin-right: 10px; /* Add margin to image */
            }
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
        <div class="nav-icons">
            <a href="#" class="icon-btn" id="cartIcon"><i class="fas fa-shopping-cart"></i></a>
            <div class="profile-icon-container">
                <a href="#" class="icon-btn" id="profileIcon"><i class="fas fa-user"></i></a>
                <div class="profile-dropdown" id="profileDropdown">
                    <ul>
                        <li><a href="profile.php"><i class="fas fa-user-circle"></i> Profile Saya</a></li>
                        <li><a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </header>

    <main>
        <div class="payment-container">
            <h2>Konfirmasi Pembayaran Anda</h2>

            <?php if (!empty($payment_message)): ?>
                <div class="alert alert-<?php echo $payment_message_type; ?>"><?php echo $payment_message; ?></div>
            <?php endif; ?>

            <?php if ($transaction_details): ?>
                <div class="payment-section">
                    <h3><i class="fas fa-clipboard-list"></i> Detail Pesanan</h3>
                    <div class="transaction-code">Kode Transaksi: <strong><?php echo htmlspecialchars($transaction_details['transaction_code']); ?></strong></div>

                    <table class="order-items-table">
                        <thead>
                            <tr>
                                <th>Produk</th>
                                <th>Harga Satuan</th>
                                <th>Jumlah</th>
                                <th>Subtotal</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($transaction_items as $item): ?>
                                <tr>
                                    <td data-label="Produk">
                                        <div class="product-item-details">
                                            <img src="<?php echo htmlspecialchars($item['image_path']); ?>" alt="<?php echo htmlspecialchars($item['name']); ?>">
                                            <span><?php echo htmlspecialchars($item['name']); ?></span>
                                        </div>
                                    </td>
                                    <td data-label="Harga Satuan">Rp <?php echo number_format($item['price_at_purchase'], 0, ',', '.'); ?></td>
                                    <td data-label="Jumlah"><?php echo htmlspecialchars($item['quantity']); ?></td>
                                    <td data-label="Subtotal">Rp <?php echo number_format($item['price_at_purchase'] * $item['quantity'], 0, ',', '.'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <div class="order-summary">
                        <div class="summary-box">
                            <div class="summary-row">
                                <span>Subtotal Produk:</span>
                                <span>Rp <?php echo number_format($total_order_price, 0, ',', '.'); ?></span>
                            </div>
                            <div class="summary-row">
                                <span>Biaya Pengiriman:</span>
                                <span>Rp 0 <small>(Gratis)</small></span>
                            </div>
                            <div class="summary-row total">
                                <span>Total Keseluruhan:</span>
                                <span>Rp <?php echo number_format($total_order_price, 0, ',', '.'); ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="payment-section">
                    <h3><i class="fas fa-money-check-alt"></i> Informasi Pembayaran</h3>
                    <div class="payment-info">
                        <p>Silakan lakukan pembayaran sejumlah <strong>Rp <?php echo number_format($total_order_price, 0, ',', '.'); ?></strong> ke salah satu rekening berikut atau scan QR Code:</p>
                        
                        <div class="bank-details">
                            <strong>Bank BCA</strong>
                            <p>Nomor Rekening: 1234567890</p>
                            <p>Atas Nama: PT WEARNITY INDONESIA</p>
                        </div>
                        <br>
                        <div class="bank-details">
                            <strong>Bank Mandiri</strong>
                            <p>Nomor Rekening: 0987654321</p>
                            <p>Atas Nama: PT WEARNITY INDONESIA</p>
                        </div>
                        <br>
                        <p>Atau scan QR Code ini:</p>
                        <img src="Asset/QR.jpg" alt="QR Code Pembayaran" class="qr-code">
                        <p style="font-size: 0.9em; color: #777;">(Ganti `Asset/QR.jpg` dengan path QR code Anda yang sebenarnya)</p>
                    </div>

                    <h3><i class="fas fa-upload"></i> Unggah Bukti Pembayaran</h3>
                    <form class="payment-form" action="payment_confirmation.php" method="POST" enctype="multipart/form-data">
                        <div class="form-group">
                            <label for="sender_name">Nama Pengirim Transfer / Nama Pemilik Rekening<span style="color: red;">*</span></label>
                            <input type="text" id="sender_name" name="sender_name" required value="<?php echo htmlspecialchars($_POST['sender_name'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label for="account_number">Nomor Rekening Pengirim<span style="color: red;">*</span></label>
                            <input type="text" id="account_number" name="account_number" required value="<?php echo htmlspecialchars($_POST['account_number'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label for="payment_proof">Bukti Pembayaran (JPG, JPEG, PNG, GIF - Max 5MB)<span style="color: red;">*</span></label>
                            <input type="file" id="payment_proof" name="payment_proof" accept="image/jpeg,image/png,image/gif" required>
                            <small style="color: #777;">Foto struk/tangkapan layar bukti transfer.</small>
                        </div>
                        <button type="submit" name="confirm_payment">
                            <i class="fas fa-paper-plane"></i> Kirim Konfirmasi Pembayaran
                        </button>
                    </form>
                </div>
            <?php else: ?>
                <div class="alert alert-danger">Detail pesanan tidak ditemukan atau sudah dikonfirmasi.</div>
                <div style="text-align: center; margin-top: 20px;"><a href="transaction_history.php" class="btn btn-primary" style="background-color: #007bff; color: white; padding: 10px 20px; border-radius: 5px;">Lihat Riwayat Transaksi</a></div>
            <?php endif; ?>
        </div>
    </main>

    <footer>
        <p>Copyright © 2025 - Wearnity by THANKSINSOMNIA</p>
    </footer>

    <div class="cart-overlay" id="cartOverlay"></div>
    <div class="cart-sidebar" id="cartSidebar">
        </div>

    <script>
        // Profile Dropdown
        const profileIcon = document.getElementById('profileIcon');
        const profileDropdown = document.getElementById('profileDropdown');

        profileIcon.addEventListener('click', function(event) {
            event.preventDefault();
            profileDropdown.classList.toggle('show');
        });

        document.addEventListener('click', function(event) {
            if (!profileIcon.contains(event.target) && !profileDropdown.contains(event.target)) {
                profileDropdown.classList.remove('show');
            }
        });

        // --- Shopping Cart Logic (Copied from dashboard/profile) ---
        const cartSidebar = document.getElementById('cartSidebar');
        const cartOverlay = document.getElementById('cartOverlay');
        const cartIcon = document.getElementById('cartIcon');
        const cartContentContainer = document.getElementById('cartSidebar');

        function toggleCartSidebar() {
            cartSidebar.classList.toggle('open');
            cartOverlay.style.display = cartSidebar.classList.contains('open') ? 'block' : 'none';
            document.body.style.overflow = cartSidebar.classList.contains('open') ? 'hidden' : ''; // Prevent body scroll when open
            if (cartSidebar.classList.contains('open')) {
                loadCartContent(); // Muat ulang konten keranjang saat dibuka
            }
        }

        // Event listener untuk ikon keranjang di header
        cartIcon.addEventListener('click', function(event) {
            event.preventDefault();
            toggleCartSidebar();
        });

        // Fungsi untuk memuat konten keranjang via AJAX
        function loadCartContent() {
            fetch('_cart_content.php')
                .then(response => {
                    if (!response.ok) throw new Error('Network response was not ok');
                    return response.text();
                })
                .then(html => {
                    cartContentContainer.innerHTML = html;
                    attachCartItemListeners(); // Pasang kembali event listener pada elemen baru
                })
                .catch(error => {
                    console.error('Error loading cart content:', error);
                    cartContentContainer.innerHTML = '<p class="empty-cart-message" style="color: red;">Gagal memuat keranjang. Silakan coba lagi.</p>';
                });
        }

        // Fungsi untuk menambah produk ke keranjang (tidak akan dipanggil di halaman ini)
        function addToCart(productId, quantity = 1) {
            fetch('cart_actions.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: `action=add&product_id=${productId}&quantity=${quantity}`
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert(data.message);
                        loadCartContent();
                    } else {
                        alert('Gagal menambah produk: ' + data.message);
                    }
                })
                .catch(error => console.error('Error adding to cart:', error));
        }

        // Fungsi untuk memperbarui kuantitas item di keranjang
        function updateCartItemQuantity(productId, newQuantity) {
            fetch('cart_actions.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: `action=update_quantity&product_id=${productId}&quantity=${newQuantity}`
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        loadCartContent();
                    } else {
                        alert('Gagal memperbarui kuantitas: ' + data.message);
                    }
                })
                .catch(error => console.error('Error updating quantity:', error));
        }

        // Fungsi untuk menghapus item dari keranjang
        function removeFromCart(productId) {
            fetch('cart_actions.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: `action=remove&product_id=${productId}`
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        loadCartContent();
                    } else {
                        alert('Gagal menghapus produk: ' + data.message);
                    }
                })
                .catch(error => console.error('Error removing from cart:', error));
        }

        // Fungsi ini akan dipanggil setelah konten keranjang dimuat ulang oleh AJAX
        function attachCartItemListeners() {
            // Event listener untuk tombol TUTUP sidebar keranjang
            const closeCartBtn = document.querySelector('#cartSidebar .close-cart-btn'); // Target tombol di dalam sidebar
            if (closeCartBtn) {
                closeCartBtn.addEventListener('click', function() {
                    toggleCartSidebar(); // Panggil fungsi toggle untuk menutup sidebar
                });
            } else {
                console.warn("Tombol penutup keranjang tidak ditemukan di dalam sidebar setelah pemuatan AJAX.");
            }
            
            document.querySelectorAll('#cartSidebar .qty-btn').forEach(button => {
                button.onclick = function() {
                    const productId = this.closest('.cart-item').dataset.productId;
                    let currentQty = parseInt(this.closest('.quantity-controls').querySelector('.item-quantity').textContent);
                    let newQty;
                    if (this.dataset.action === 'plus') {
                        newQty = currentQty + 1;
                    } else { // minus
                        newQty = currentQty - 1;
                    }
                    if (newQty <= 0) {
                        if (confirm('Apakah Anda yakin ingin menghapus produk ini dari keranjang?')) {
                            removeFromCart(productId);
                        }
                    } else {
                        updateCartItemQuantity(productId, newQty);
                    }
                };
            });

            document.querySelectorAll('#cartSidebar .remove-item-btn').forEach(button => {
                button.onclick = function() {
                    if (confirm('Apakah Anda yakin ingin menghapus produk ini dari keranjang?')) {
                        const productId = this.closest('.cart-item').dataset.productId;
                        removeFromCart(productId);
                    }
                };
            });

            const checkoutBtn = document.getElementById('checkoutBtn');
            if (checkoutBtn) {
                checkoutBtn.addEventListener('click', function(event) {
                    event.preventDefault(); // Prevent default link behavior
                    // Di halaman ini, tombol checkout di sidebar akan mengarah ke order_details.php
                    window.location.href = 'order_details.php';
                });
            }
        }
    </script>
</body>

</html>