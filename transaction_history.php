<?php
require_once 'config.php'; // Memuat konfigurasi database dan memulai session (pastikan session_start() ada di config.php)

// Cek apakah user sudah login. Jika belum, arahkan kembali ke halaman login.
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("location: index.php");
    exit;
}

$user_id = $_SESSION['id'];
$transactions_history = [];
$message = '';
$message_type = '';

// Ambil pesan sukses/error dari session jika ada
// Setelah konfirmasi pesanan (dari order_details.php) atau konfirmasi pembayaran (dari payment_confirmation.php)
if (isset($_SESSION['order_success_message'])) {
    $message = $_SESSION['order_success_message'];
    $message_type = $_SESSION['order_message_type'] ?? 'success'; // Ambil tipe jika ada, default 'success'
    unset($_SESSION['order_success_message']); // Hapus setelah ditampilkan
    unset($_SESSION['order_message_type']); // Hapus juga tipenya
}

// Fungsi untuk mengubah status string menjadi format kelas CSS (misal "Waiting Payment" -> "waiting-payment")
function getStatusCssClass($status) {
    return strtolower(str_replace(' ', '-', $status));
}

try {
    // Query untuk mengambil semua detail transaksi (transaksi + item) untuk user ini
    $sql_history = "
        SELECT
            t.id AS transaction_id,
            t.transaction_code,
            t.total_amount,
            t.status,
            t.order_date,
            t.shipping_courier,
            t.payment_proof_image,
            ti.quantity,
            ti.price_at_purchase,
            p.name AS product_name,
            p.image_path
        FROM
            transactions t
        JOIN
            transaction_items ti ON t.id = ti.transaction_id
        JOIN
            products p ON ti.product_id = p.id
        WHERE
            t.user_id = :user_id
        ORDER BY
            t.order_date DESC, t.id DESC
    ";
    $stmt = $pdo->prepare($sql_history);
    $stmt->bindParam(":user_id", $user_id, PDO::PARAM_INT);
    $stmt->execute();
    $raw_transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Kelompokkan item-item ke dalam transaksi yang sama
    foreach ($raw_transactions as $row) {
        $trans_id = $row['transaction_id'];
        if (!isset($transactions_history[$trans_id])) {
            $transactions_history[$trans_id] = [
                'transaction_id' => $row['transaction_id'], // Tambahkan transaction_id ke array utama
                'transaction_code' => $row['transaction_code'],
                'total_amount' => $row['total_amount'],
                'status' => $row['status'],
                'order_date' => $row['order_date'],
                'shipping_courier' => $row['shipping_courier'],
                'payment_proof_image' => $row['payment_proof_image'],
                'items' => []
            ];
        }
        $transactions_history[$trans_id]['items'][] = [
            'product_name' => $row['product_name'],
            'image_path' => $row['image_path'],
            'quantity' => $row['quantity'],
            'price_at_purchase' => $row['price_at_purchase'],
            'item_subtotal' => $row['quantity'] * $row['price_at_purchase']
        ];
    }

} catch (PDOException $e) {
    $message = "Gagal memuat riwayat transaksi: " . $e->getMessage();
    $message_type = 'danger';
    error_log("Transaction history load error: " . $e->getMessage());
}
unset($pdo); // Tutup koneksi database
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Lihat riwayat transaksi Anda di WEARNITY. Lacak status pesanan Anda.">
    <title>Riwayat Transaksi | WEARNITY</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* General Styles & Resets */
        body {
            font-family: 'Montserrat', sans-serif;
            margin: 0;
            padding: 0;
            background-color: #f0f2f5;
            color: #333;
            line-height: 1.6;
            overflow-x: hidden;
            padding-top: 70px; /* Add padding-top to body equal to header height */
        }

        a {
            text-decoration: none;
            color: inherit;
        }

        /* Header / Navigation Bar */
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

        .logo strong {
            font-size: 28px;
            color: #2c3e50;
            letter-spacing: 1px;
            font-weight: 700;
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

        /* Profile Dropdown */
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

        /* Shopping Cart Sidebar Styles */
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
            position: fixed;
            top: 0;
            right: -400px;
            width: 380px;
            height: 100%;
            background-color: #fff;
            box-shadow: -5px 0 15px rgba(0, 0, 0, 0.2);
            z-index: 1002;
            transition: right 0.3s ease-in-out;
            display: flex;
            flex-direction: column;
        }

        .cart-sidebar.open {
            right: 0;
        }

        .cart-header {
            padding: 20px;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background-color: #f8f8f8;
        }

        .cart-header h3 {
            margin: 0;
            font-size: 24px;
            color: #333;
        }

        .close-cart-btn {
            background: none;
            border: none;
            font-size: 30px;
            cursor: pointer;
            color: #777;
            padding: 0;
            line-height: 1;
        }

        .cart-items-list {
            flex-grow: 1;
            overflow-y: auto;
            padding: 20px;
        }

        .cart-item {
            display: flex;
            align-items: center;
            margin-bottom: 20px;
            padding: 10px;
            border: 1px solid #eee;
            border-radius: 8px;
            background-color: #fff;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
        }

        .cart-item img {
            width: 80px;
            height: 80px;
            object-fit: cover;
            border-radius: 5px;
            margin-right: 15px;
        }

        .cart-item .item-details {
            flex-grow: 1;
            display: flex;
            flex-direction: column;
        }

        .cart-item .item-name {
            font-weight: bold;
            font-size: 1.1em;
            margin-bottom: 5px;
        }

        .cart-item .quantity-controls {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 1.1em;
        }

        .cart-item .qty-btn {
            background: none;
            border: 1px solid #ccc;
            border-radius: 50%;
            width: 28px;
            height: 28px;
            display: flex;
            justify-content: center;
            align-items: center;
            cursor: pointer;
            transition: background-color 0.2s ease, color 0.2s ease;
        }

        .cart-item .qty-btn:hover {
            background-color: #007bff;
            color: white;
            border-color: #007bff;
        }

        .cart-item .qty-btn i {
            font-size: 0.8em;
        }

        .cart-item .item-actions {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 10px;
            margin-left: 15px;
        }

        .cart-item .item-price {
            font-weight: bold;
            color: #007bff;
            font-size: 1.1em;
        }

        .cart-item .remove-item-btn {
            background: none;
            border: none;
            color: #dc3545;
            cursor: pointer;
            font-size: 1.2em;
            transition: color 0.2s ease;
        }

        .cart-item .remove-item-btn:hover {
            color: #c82333;
        }

        .cart-summary {
            border-top: 1px solid #eee;
            padding: 20px;
            background-color: #f8f8f8;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .cart-summary .total-price {
            font-size: 1.5em;
            font-weight: bold;
            color: #333;
            display: flex;
            flex-direction: column;
        }

        .cart-summary .total-price span:last-child {
            color: #007bff;
        }

        .checkout-btn {
            background-color: #007bff;
            color: white;
            padding: 15px 25px;
            border: none;
            border-radius: 50%;
            cursor: pointer;
            font-size: 1.5em;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 60px;
            height: 60px;
            transition: background-color 0.3s ease;
        }

        .checkout-btn:hover {
            background-color: #0056b3;
        }

        .empty-cart-message {
            text-align: center;
            color: #777;
            margin-top: 50px;
            font-size: 1.1em;
        }
        /* End of Navbar/Cart CSS */

        /* Main content for history */
        .history-container {
            max-width: 900px;
            margin: 60px auto 40px auto; /* Increased top margin for consistency */
            background-color: #fff;
            padding: 40px; /* Increased padding */
            border-radius: 12px; /* Smoother corners */
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.12); /* Deeper shadow */
            border: 1px solid #e0e0e0; /* Subtle border */
        }

        .history-container h2 {
            text-align: center;
            margin-bottom: 35px; /* Increased margin bottom */
            color: #2c3e50; /* Darker title color */
            font-size: 28px; /* Larger font size */
            font-weight: 600; /* Bolder font */
        }

        /* Alert styling for success/danger/info messages */
        .alert {
            padding: 18px 25px; /* Larger padding */
            border-radius: 8px; /* Smoother corners */
            margin-bottom: 25px; /* More space below */
            font-size: 1.05em; /* Slightly larger font */
            display: flex;
            align-items: center;
            gap: 12px; /* Gap between icon and text */
            font-weight: 500;
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
        .alert-info {
            background-color: #d1ecf1;
            color: #0c5460;
            border: 1px solid #bee5eb;
        }
        .alert i {
            font-size: 1.2em; /* Icon size */
        }

        /* Transaction List Styles */
        .transaction-card {
            background-color: #fdfdfd;
            border: 1px solid #e5e5e5; /* Lighter border */
            border-radius: 10px; /* Consistent border radius */
            margin-bottom: 30px; /* More space between cards */
            padding: 25px; /* More padding inside card */
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08); /* Better shadow */
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .transaction-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 6px 16px rgba(0, 0, 0, 0.1);
        }

        .transaction-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px; /* More space below header */
            padding-bottom: 15px;
            border-bottom: 1px dashed #e0e0e0; /* Lighter dash border */
            flex-wrap: wrap; /* Allow wrapping on small screens */
            gap: 10px; /* Gap for wrapped items */
        }

        .transaction-header .code {
            font-weight: 700; /* Bolder */
            font-size: 1.3em; /* Larger font */
            color: #007bff;
            flex-grow: 1; /* Allow code to take space */
        }

        .transaction-header .date {
            font-size: 0.95em; /* Slightly larger font */
            color: #666;
            text-align: right;
            white-space: nowrap; /* Prevent wrapping for date */
        }

        .transaction-header .status {
            font-weight: 600; /* Bolder */
            padding: 6px 12px; /* More padding */
            border-radius: 20px; /* Pill-shaped */
            font-size: 0.9em;
            text-align: center;
            min-width: 120px; /* Adjust minimum width for consistency */
            box-sizing: border-box;
            line-height: 1.2; /* Ensure vertical alignment */
        }

        /* Status colors */
        .transaction-header .status.waiting-payment {
            background-color: #ffc107;
            color: #856404;
        }
        .transaction-header .status.payment-confirmed {
            background-color: #007bff;
            color: white;
        }
        .transaction-header .status.processing {
            background-color: #6f42c1;
            color: white;
        }
        .transaction-header .status.shipped {
            background-color: #17a2b8;
            color: white;
        }
        .transaction-header .status.completed {
            background-color: #28a745;
            color: white;
        }
        .transaction-header .status.cancelled {
            background-color: #dc3545;
            color: white;
        }
        .transaction-header .status.pending { /* Fallback for generic Pending */
            background-color: #d6d8d9;
            color: #383d41;
        }

        .transaction-items-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px; /* More space above table */
        }

        .transaction-items-table th,
        .transaction-items-table td {
            border: 1px solid #eee;
            padding: 12px 15px; /* More padding */
            text-align: left;
            vertical-align: middle; /* Align content vertically */
        }

        .transaction-items-table th {
            background-color: #f8f8f8;
            color: #555;
            font-weight: 600; /* Bolder header text */
            font-size: 0.95em;
        }

        .transaction-items-table .item-product-details {
            display: flex;
            align-items: center;
            gap: 15px; /* More gap */
        }

        .transaction-items-table .item-product-details img {
            width: 60px; /* Larger image */
            height: 60px; /* Larger image */
            object-fit: cover;
            border-radius: 6px; /* Slightly smoother border */
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
        }
        .transaction-items-table td {
            font-size: 0.9em; /* Consistent font size for table data */
            color: #444;
        }
        .transaction-items-table td:nth-child(2), /* Kuantitas */
        .transaction-items-table td:nth-child(3), /* Harga Satuan */
        .transaction-items-table td:nth-child(4) /* Subtotal */ {
            white-space: nowrap; /* Prevent wrapping for numbers */
        }


        /* New styling for "Konfirmasi Pembayaran" button */
        .transaction-actions {
            text-align: right;
            margin-top: 20px; /* More space above actions */
        }
        .btn-pay-now {
            background-color: #28a745; /* Green */
            color: white;
            padding: 10px 20px; /* More padding */
            border: none;
            border-radius: 8px; /* Smoother corners */
            cursor: pointer;
            font-size: 1em; /* Standard font size */
            font-weight: 600; /* Bolder text */
            transition: background-color 0.3s ease, transform 0.2s ease;
            display: inline-flex; /* For icon alignment */
            align-items: center;
            gap: 8px;
        }
        .btn-pay-now:hover {
            background-color: #218838;
            transform: translateY(-2px);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }
        .payment-proof-link {
            display: inline-block; /* Changed to inline-block for better spacing with button */
            margin-top: 10px;
            font-size: 0.95em; /* Slightly larger */
            color: #007bff;
            text-decoration: none; /* Remove underline by default */
            font-weight: 500;
        }
        .payment-proof-link:hover {
            color: #0056b3;
            text-decoration: underline; /* Add underline on hover */
        }

        .transaction-footer {
            display: flex;
            justify-content: flex-end;
            margin-top: 20px; /* More space above footer */
            align-items: center;
            gap: 20px; /* Increased gap */
            padding-top: 15px;
            border-top: 1px dashed #e0e0e0;
        }
        .shipping-courier-info {
            font-size: 0.95em; /* Slightly larger */
            color: #777;
            text-align: left;
            flex-grow: 1;
        }

        .transaction-total {
            font-size: 1.4em; /* Larger font */
            font-weight: 700; /* Bolder */
            color: #007bff;
            background-color: #e6f7ff;
            padding: 12px 20px; /* More padding */
            border-radius: 8px; /* Smoother corners */
            text-align: right;
            min-width: 200px;
        }

        /* UPDATED FOOTER STYLES (Copied from dashboard) */
        footer {
            background-color: #2c3e50; /* Dark color consistent with your design */
            color: #ecf0f1; /* Light text for contrast */
            padding: 20px 20px; /* Reduced padding for a smaller look */
            text-align: center;
            font-size: 0.9em; /* Slightly smaller font size for copyright */
            box-shadow: 0 -4px 15px rgba(0, 0, 0, 0.1); /* Subtle top shadow */
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: 80px; /* Reduced minimum height */
            margin-top: 50px; /* Keep margin from main content */
        }

        footer p {
            margin: 0;
            font-weight: 500;
            letter-spacing: 0.5px;
        }

        .footer-social {
            margin-top: 15px; /* Reduced margin top for social icons */
            display: flex;
            gap: 15px; /* Reduced gap between icons */
        }

        .footer-social a {
            color: #ecf0f1;
            font-size: 20px; /* Slightly smaller social icons */
            transition: color 0.3s ease, transform 0.2s ease;
        }

        .footer-social a:hover {
            color: #007bff;
            transform: translateY(-2px); /* Subtle hover effect */
        }

        /* Responsive adjustments */
        @media (max-width: 992px) {
            header {
                padding: 15px 30px;
            }
            .nav-links {
                gap: 25px;
            }
            .history-container {
                margin: 40px auto 30px auto;
                padding: 30px;
                max-width: 90%;
            }
            footer {
                padding: 15px 15px; /* Further reduce padding on medium screens */
            }
            .footer-social {
                margin-top: 10px;
                gap: 10px;
            }
            .footer-social a {
                font-size: 18px;
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
            .logo {
                margin-bottom: 15px;
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

            .history-container {
                margin: 20px;
                padding: 20px;
            }
            .history-container h2 {
                font-size: 24px;
                margin-bottom: 25px;
            }
            .alert {
                padding: 15px 20px;
                font-size: 0.95em;
                flex-direction: column;
                align-items: flex-start;
                gap: 8px;
            }
            .alert p {
                text-align: left;
            }
            .alert i {
                align-self: flex-start;
            }

            .transaction-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 5px;
                margin-bottom: 15px;
            }
            .transaction-header .code,
            .transaction-header .date,
            .transaction-header .status {
                width: auto;
                text-align: left;
            }
            .transaction-header .status {
                min-width: 100px;
            }

            .transaction-items-table thead {
                display: none;
            }
            .transaction-items-table tr {
                display: block;
                margin-bottom: 15px;
                border: 1px solid #eee;
                border-radius: 8px;
                overflow: hidden;
            }
            .transaction-items-table td {
                display: block;
                width: 100%;
                box-sizing: border-box;
                text-align: right;
                padding-left: 50%;
                position: relative;
                border-bottom: none;
            }
            .transaction-items-table td:last-child {
                border-bottom: 1px solid #eee;
            }
            .transaction-items-table td::before {
                content: attr(data-label);
                position: absolute;
                left: 15px;
                width: 45%;
                white-space: nowrap;
                text-align: left;
                font-weight: 600;
                color: #555;
            }
            .transaction-items-table .item-product-details {
                justify-content: flex-end;
                text-align: right;
            }
            .transaction-items-table .item-product-details img {
                order: 2;
                margin-right: 0;
                margin-left: 10px;
            }
            .transaction-items-table .item-product-details span {
                order: 1;
            }

            .transaction-footer {
                flex-direction: column;
                align-items: flex-end;
                gap: 15px;
            }
            .shipping-courier-info {
                text-align: right;
                width: 100%;
            }
            .transaction-total {
                width: 100%;
                text-align: right;
            }
            .transaction-actions {
                width: 100%;
                text-align: right;
            }
            .btn-pay-now {
                width: 100%;
                max-width: 250px;
            }
            footer {
                padding: 15px 15px;
                min-height: 70px; /* Even smaller min-height on mobile */
            }
            footer p {
                font-size: 0.8em; /* Even smaller font on mobile */
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
                font-size: 24px;
            }
            .nav-links li a {
                font-size: 15px;
                gap: 5px;
            }
            .nav-icons .icon-btn {
                font-size: 20px;
            }
            .history-container {
                padding: 15px;
                margin: 20px auto;
            }
            .transaction-total {
                font-size: 1.2em;
                padding: 10px 15px;
            }
            .btn-pay-now {
                padding: 10px 15px;
                font-size: 0.9em;
            }
            .payment-proof-link {
                font-size: 0.85em;
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
                <li><a href="transaction_history.php" class="active"><i class="fas fa-receipt"></i> Riwayat Transaksi</a></li>
            </ul>
        </nav>
        <div class="nav-icons">
            <a href="#" class="icon-btn" id="cartIcon"><i class="fas fa-shopping-cart"></i></a>
            <div class="profile-icon-container">
                <a href="#" class="icon-btn" id="profileIcon"><i class="fas fa-user"></i></a>
                <div class="profile-dropdown" id="profileDropdown">
                    <ul>
                        <li><a href="profile.php"><i class="fas fa-user-circle"></i> Profil Saya</a></li>
                        <li><a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </header>

    <main>
        <div class="history-container">
            <h2>Riwayat Transaksi Anda</h2>
            <?php if (!empty($message)): ?>
                <div class="alert alert-<?php echo $message_type; ?>">
                    <?php if ($message_type == 'success'): ?>
                        <i class="fas fa-check-circle"></i>
                    <?php elseif ($message_type == 'danger'): ?>
                        <i class="fas fa-times-circle"></i>
                    <?php else: /* info */ ?>
                        <i class="fas fa-info-circle"></i>
                    <?php endif; ?>
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <?php if (empty($transactions_history)): ?>
                <p class="alert alert-info"><i class="fas fa-info-circle"></i> Anda belum memiliki riwayat transaksi.</p>
            <?php else: ?>
                <?php foreach ($transactions_history as $transaction): ?>
                    <div class="transaction-card">
                        <div class="transaction-header">
                            <div class="code">#<?php echo htmlspecialchars($transaction['transaction_code']); ?></div>
                            <div class="date"><?php echo date('d M Y, H:i', strtotime($transaction['order_date'])); ?></div>
                            <?php
                            $displayStatusText = htmlspecialchars($transaction['status']);
                            if ($transaction['status'] == 'Completed') {
                                $displayStatusText = 'Success';
                            }
                            ?>
                            <div class="status <?php echo getStatusCssClass($transaction['status']); ?>"><?php echo $displayStatusText; ?></div>
                        </div>
                        <table class="transaction-items-table">
                            <thead>
                                <tr>
                                    <th>Produk</th>
                                    <th>Kuantitas</th>
                                    <th>Harga Satuan</th>
                                    <th>Subtotal</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($transaction['items'] as $item): ?>
                                    <tr>
                                        <td data-label="Produk">
                                            <div class="item-product-details">
                                                <img src="<?php echo htmlspecialchars($item['image_path']); ?>" alt="<?php echo htmlspecialchars($item['product_name']); ?>">
                                                <span><?php echo htmlspecialchars($item['product_name']); ?></span>
                                            </div>
                                        </td>
                                        <td data-label="Kuantitas"><?php echo htmlspecialchars($item['quantity']); ?></td>
                                        <td data-label="Harga Satuan">Rp <?php echo number_format($item['price_at_purchase'], 0, ',', '.'); ?></td>
                                        <td data-label="Subtotal">Rp <?php echo number_format($item['item_subtotal'], 0, ',', '.'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <div class="transaction-footer">
                            <div class="shipping-courier-info">
                                Kurir: <strong><?php echo htmlspecialchars($transaction['shipping_courier'] ?? 'N/A'); ?></strong>
                            </div>
                            <div class="transaction-total">Total: Rp <?php echo number_format($transaction['total_amount'], 0, ',', '.'); ?></div>
                        </div>
                        <div class="transaction-actions">
                            <?php if ($transaction['status'] == 'Waiting Payment'): ?>
                                <button type="button" class="btn-pay-now" data-transaction-id="<?php echo $transaction['transaction_id']; ?>">
                                    <i class="fas fa-hand-holding-dollar"></i> Konfirmasi Pembayaran Sekarang
                                </button>
                            <?php elseif ($transaction['status'] == 'Payment Confirmed' && !empty($transaction['payment_proof_image'])): ?>
                                <p style="font-size: 0.9em; color: #777;">Menunggu verifikasi admin.</p>
                                <a href="<?php echo htmlspecialchars($transaction['payment_proof_image']); ?>" target="_blank" class="payment-proof-link"><i class="fas fa-eye"></i> Lihat Bukti Pembayaran Anda</a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </main>

    <footer>
        <p>Copyright © 2025 - Wearnity by THANKSINSOMNIA</p>
        <div class="footer-social">
            <a href="#" target="_blank" aria-label="Facebook"><i class="fab fa-facebook-f"></i></a>
            <a href="#" target="_blank" aria-label="Twitter"><i class="fab fa-twitter"></i></a>
            <a href="#" target="_blank" aria-label="Instagram"><i class="fab fa-instagram"></i></a>
            <a href="#" target="_blank" aria-label="LinkedIn"><i class="fab fa-linkedin-in"></i></a>
        </div>
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

        // Script untuk menambahkan kelas 'active' pada link navigasi yang sedang aktif
        document.addEventListener('DOMContentLoaded', function() {
            const currentPath = window.location.pathname.split('/').pop();
            const navLinks = document.querySelectorAll('.nav-links li a');

            navLinks.forEach(link => {
                const linkPath = link.getAttribute('href').split('/').pop();
                // Handle dashboard.php as default empty path if user accesses domain root
                if (linkPath === currentPath || (currentPath === 'dashboard.php' && linkPath === '')) {
                    link.classList.add('active');
                }
            });

            // --- Shopping Cart Logic ---
            const cartSidebar = document.getElementById('cartSidebar');
            const cartOverlay = document.getElementById('cartOverlay');
            const cartIcon = document.getElementById('cartIcon');
            const cartContentContainer = document.getElementById('cartSidebar'); // Same element

            if (cartIcon && cartSidebar && cartOverlay) {
                cartIcon.addEventListener('click', function(event) {
                    event.preventDefault();
                    toggleCartSidebar();
                });

                // Close cart sidebar when overlay is clicked
                cartOverlay.addEventListener('click', function() {
                    toggleCartSidebar();
                });
            }

            function toggleCartSidebar() {
                if (cartSidebar && cartOverlay) {
                    cartSidebar.classList.toggle('open');
                    cartOverlay.style.display = cartSidebar.classList.contains('open') ? 'block' : 'none';
                    document.body.style.overflow = cartSidebar.classList.contains('open') ? 'hidden' : '';
                    if (cartSidebar.classList.contains('open')) {
                        loadCartContent();
                    }
                } else {
                    console.warn("Elemen keranjang (cartSidebar/cartOverlay) tidak ditemukan. Redirect ke halaman keranjang.");
                    window.location.href = 'cart.php'; // Fallback if elements not found
                }
            }

            function loadCartContent() {
                if (!cartContentContainer) return;
                fetch('_cart_content.php')
                    .then(response => {
                        if (!response.ok) throw new Error('Network response was not ok: ' + response.statusText);
                        return response.text();
                    })
                    .then(html => {
                        cartContentContainer.innerHTML = html;
                        attachCartItemListeners(); // Re-attach listeners after content is loaded
                    })
                    .catch(error => {
                        console.error('Error loading cart content:', error);
                        cartContentContainer.innerHTML = '<p class="empty-cart-message" style="color: red;">Gagal memuat keranjang. Silakan coba lagi.</p>';
                    });
            }

            // These functions are generally for adding/updating/removing from cart,
            // which usually happens on product pages, not directly on transaction_history.
            // But they are here if needed by attachedCartItemListeners() when cart is open.
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
                            loadCartContent(); // Reload cart content to reflect changes
                        } else {
                            alert('Gagal memperbarui kuantitas: ' + data.message);
                        }
                    })
                    .catch(error => console.error('Error updating quantity:', error));
            }

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
                            loadCartContent(); // Reload cart content to reflect changes
                        } else {
                            alert('Gagal menghapus produk: ' + data.message);
                        }
                    })
                    .catch(error => console.error('Error removing from cart:', error));
            }

            // This function will be called after cart content is reloaded via AJAX
            function attachCartItemListeners() {
                // Event listener for close button in cart sidebar
                const closeCartBtn = document.querySelector('#cartSidebar .close-cart-btn');
                if (closeCartBtn) {
                    closeCartBtn.addEventListener('click', function() {
                        toggleCartSidebar();
                    });
                }

                // Attach listeners for quantity buttons
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

                // Attach listeners for remove buttons
                document.querySelectorAll('#cartSidebar .remove-item-btn').forEach(button => {
                    button.onclick = function() {
                        if (confirm('Apakah Anda yakin ingin menghapus produk ini dari keranjang?')) {
                            const productId = this.closest('.cart-item').dataset.productId;
                            removeFromCart(productId);
                        }
                    };
                });

                // Attach listener for checkout button
                const checkoutBtn = document.getElementById('checkoutBtn');
                if (checkoutBtn) {
                    checkoutBtn.addEventListener('click', function(event) {
                        event.preventDefault();
                        window.location.href = 'order_details.php';
                    });
                }
            }
            
            // JavaScript for handling the "Konfirmasi Pembayaran Sekarang" button
            document.querySelectorAll('.btn-pay-now').forEach(button => {
                // Prevent attaching multiple listeners if the script runs multiple times
                if (!button.dataset.listenerAttached) {
                    button.addEventListener('click', function(event) {
                        event.preventDefault(); // Prevent immediate navigation

                        // Get the transaction_id from the data attribute
                        const transactionId = this.dataset.transactionId;

                        if (transactionId) {
                            // Make an AJAX call to set the transaction ID in session
                            fetch('set_transaction_session.php', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/x-www-form-urlencoded',
                                },
                                body: 'transaction_id=' + transactionId
                            })
                            .then(response => {
                                if (!response.ok) {
                                    // Handle HTTP errors
                                    throw new Error('Network response was not ok ' + response.statusText);
                                }
                                return response.json();
                            })
                            .then(data => {
                                if (data.success) {
                                    // If session is set successfully, then navigate to payment_confirmation.php
                                    window.location.href = 'payment_confirmation.php'; // No need for transaction_id in URL now
                                } else {
                                    alert('Gagal menyiapkan pembayaran. Silakan coba lagi. ' + (data.message || ''));
                                    console.error('Server response error:', data.message);
                                }
                            })
                            .catch(error => {
                                console.error('Error setting session or fetching:', error);
                                alert('Terjadi kesalahan teknis. Silakan coba lagi.');
                            });
                        } else {
                            alert('Transaction ID tidak ditemukan pada tombol.');
                        }
                    });
                    button.dataset.listenerAttached = true; // Mark listener as attached
                }
            });
        });
    </script>
</body>
</html>