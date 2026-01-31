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

// ===============================================
// LOGIKA UNTUK CEK KELENGKAPAN PROFIL PENGGUNA
// ===============================================
$profile_incomplete = false;
$user_profile = [];

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
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Lihat riwayat transaksi Anda di WEARNITY. Lacak status pesanan Anda.">
    <title>Riwayat Transaksi | WEARNITY</title>
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

        main {
            flex: 1;
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

        /* Profile Dropdown */
        .profile-icon-container {
            position: relative;
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
        }

        .profile-dropdown.show {
            display: block;
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

        /* Ensuring there's no gap for hover to stay active */
        .profile-icon-container {
            padding-bottom: 10px;
            margin-bottom: -10px;
        }

        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Shopping Cart Sidebar */
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
            background: rgba(255, 255, 255, 0.95);
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

        .cart-header {
            padding: 35px 30px;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .cart-header h3 { 
            font-size: 1.8rem; 
            font-weight: 800; 
            color: var(--primary-color);
            letter-spacing: -1px;
        }

        .close-cart-btn {
            background: #f1f5f9;
            border: none;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            cursor: pointer;
            color: #64748b;
            transition: all 0.3s;
        }

        .close-cart-btn:hover { background: #fee2e2; color: #ef4444; }

        .cart-items-list {
            flex: 1;
            overflow-y: auto;
            padding: 20px 30px;
        }

        .cart-item {
            display: flex;
            gap: 20px;
            padding: 20px 0;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
            align-items: center;
        }

        .cart-item img {
            width: 70px;
            height: 80px;
            object-fit: cover;
            border-radius: 12px;
            box-shadow: var(--shadow-sm);
        }

        .item-details {
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .item-name {
            font-weight: 700;
            font-size: 1rem;
            color: var(--primary-color);
        }

        .item-price {
            font-weight: 700;
            color: var(--secondary-color);
            font-size: 0.9rem;
        }

        .quantity-controls {
            display: flex;
            align-items: center;
            gap: 12px;
            background: #f8fafc;
            padding: 4px 10px;
            border-radius: 100px;
            width: fit-content;
        }

        .qty-btn {
            background: white;
            border: 1px solid var(--border-color);
            width: 24px;
            height: 24px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 10px;
            cursor: pointer;
            color: var(--text-primary);
        }

        .item-quantity { font-weight: 700; font-size: 0.85rem; }

        .cart-summary {
            padding: 30px;
            background: white;
            border-top: 1px solid rgba(0, 0, 0, 0.05);
        }

        .total-price {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .total-price span:last-child { 
            color: var(--primary-color); 
            font-weight: 800; 
            font-size: 1.4rem;
        }

        .checkout-btn {
            width: 100%;
            background: var(--primary-color);
            color: white;
            padding: 16px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            box-shadow: 0 10px 20px rgba(15, 23, 42, 0.1);
        }

        /* End of Navbar/Cart CSS */

        /* Main content for history */
        .history-container {
            max-width: 1000px;
            margin: 40px auto;
            padding: 0 20px;
        }

        .history-container h2 {
            font-size: 2.5rem;
            color: var(--primary-color);
            margin-bottom: 40px;
            letter-spacing: -1.5px;
            text-align: center;
        }

        /* Alert styling */
        .alert {
            padding: 20px 25px;
            border-radius: var(--radius-lg);
            margin-bottom: 30px;
            display: flex;
            align-items: center;
            gap: 15px;
            font-weight: 600;
            border: 1px solid transparent;
        }

        .alert-success { background: #f0fdf4; color: #166534; border-color: #dcfce7; }
        .alert-danger { background: #fef2f2; color: #991b1b; border-color: #fee2e2; }
        .alert-info { background: #eff6ff; color: #1e40af; border-color: #dbeafe; }

        /* Transaction List Styles */
        .transaction-card {
            background: white;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-xl);
            margin-bottom: 30px;
            padding: 30px;
            box-shadow: var(--shadow-md);
            transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .transaction-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-xl);
            border-color: var(--secondary-color);
        }

        .transaction-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 20px;
            border-bottom: 1px solid var(--border-color);
        }

        .transaction-header .code {
            font-weight: 800;
            font-size: 1.2rem;
            color: var(--primary-color);
        }

        .transaction-header .date {
            font-size: 0.9rem;
            color: var(--text-secondary);
            font-weight: 500;
        }

        .status {
            padding: 6px 16px;
            border-radius: 100px;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status.waiting-payment { background: #fffbeb; color: #92400e; }
        .status.payment-confirmed { background: #eff6ff; color: #1e40af; }
        .status.processing { background: #f5f3ff; color: #5b21b6; }
        .status.shipped { background: #ecfeff; color: #155e75; }
        .status.completed { background: #f0fdf4; color: #166534; }
        .status.cancelled { background: #fef2f2; color: #991b1b; }

        .transaction-items-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            margin-bottom: 25px;
        }

        .transaction-items-table th {
            text-align: left;
            padding: 12px 15px;
            color: var(--text-secondary);
            font-size: 0.8rem;
            font-weight: 700;
            text-transform: uppercase;
            border-bottom: 1px solid var(--border-color);
        }

        .transaction-items-table td {
            padding: 15px;
            vertical-align: middle;
            border-bottom: 1px solid #f8fafc;
        }

        .item-product-details {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .item-product-details img {
            width: 50px;
            height: 60px;
            object-fit: cover;
            border-radius: 8px;
        }

        .item-product-details span {
            font-weight: 700;
            color: var(--primary-color);
        }

        .transaction-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-top: 20px;
            border-top: 1px solid var(--border-color);
        }

        .shipping-courier-info {
            color: var(--text-secondary);
            font-size: 0.9rem;
            font-weight: 500;
        }

        .transaction-total {
            font-size: 1.5rem;
            font-weight: 800;
            color: var(--primary-color);
        }

        .transaction-actions {
            margin-top: 25px;
            display: flex;
            justify-content: flex-end;
            gap: 15px;
        }

        .btn-pay-now {
            background: var(--primary-color);
            color: white;
            padding: 12px 24px;
            border-radius: 12px;
            font-weight: 700;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 10px;
            border: none;
            cursor: pointer;
            transition: all 0.3s;
        }

        .btn-pay-now:hover {
            background: var(--secondary-color);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(99, 102, 241, 0.3);
        }

        .payment-proof-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-top: 10px;
            color: var(--secondary-color);
            font-weight: 700;
            font-size: 0.85rem;
            transition: all 0.3s;
        }

        .payment-proof-link:hover {
            opacity: 0.8;
            transform: translateX(3px);
        }

        /* Footer */
        footer {
            background: #111111;
            color: #6b7280;
            padding: 30px 20px;
            text-align: center;
            margin-top: 80px;
            border-top: 1px solid rgba(255, 255, 255, 0.05);
        }

        footer p {
            font-size: 0.85rem;
            margin: 0;
            letter-spacing: 0.5px;
        }


        /* Notifications & Popups */
        .profile-popup {
            position: fixed;
            bottom: 40px;
            right: 40px;
            background: rgba(255, 255, 255, 0.85);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            padding: 18px 35px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.15);
            border-radius: 40px;
            z-index: 2100;
            display: none;
            align-items: center;
            gap: 20px;
            animation: slideUpPopup 0.7s cubic-bezier(0.16, 1, 0.3, 1);
            border: 1px solid rgba(0, 0, 0, 0.05);
            border-left: 8px solid #f59e0b;
            min-width: 450px;
            max-width: 90vw;
        }

        @keyframes slideUpPopup {
            from { transform: translateY(100px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .popup-icon { font-size: 24px; color: #f59e0b; }
        .profile-popup p { font-size: 14px; font-weight: 600; color: #1e293b; }
        .profile-popup a { color: #6366f1; text-decoration: underline; }
        .close-popup-btn { background: none; border: none; font-size: 22px; cursor: pointer; color: #94a3b8; }

        #toast-container {
            position: fixed;
            top: 30px;
            right: 30px;
            z-index: 9999;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .toast {
            background: white;
            padding: 16px 25px;
            border-radius: 16px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 700;
            font-size: 0.9rem;
            animation: toastSlide 0.5s cubic-bezier(0.16, 1, 0.3, 1);
            border-left: 5px solid #10b981;
        }

        .toast.error { border-left-color: #ef4444; }

        @keyframes toastSlide {
            from { transform: translateX(50px); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }

        /* Responsive */
        @media (max-width: 768px) {
            header { padding: 0 20px; width: 95%; top: 10px; }
            .nav-links { display: none; }
            body { padding-top: 100px; }
            .history-container h2 { font-size: 2rem; }
            .transaction-card { padding: 20px; }
            .transaction-header { flex-direction: column; align-items: flex-start; gap: 10px; }
            .transaction-footer { flex-direction: column; align-items: flex-start; gap: 15px; }
            .transaction-items-table { display: block; overflow-x: auto; }
            .transaction-total { width: 100%; text-align: right; }
            .profile-popup { right: 20px; bottom: 20px; min-width: unset; width: calc(100% - 40px); }
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
                <li><a href="dashboard.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : ''; ?>"><i class="fas fa-home"></i> Beranda</a></li>
                <li><a href="dashboard.php#katalog"><i class="fas fa-shirt"></i> Katalog</a></li>
                <li><a href="dashboard.php#cerita-kami"><i class="fas fa-info-circle"></i> Cerita Kami</a></li>
                <li><a href="dashboard.php#hubungi-kami"><i class="fas fa-phone"></i> Hubungi Kami</a></li>
                <li><a href="transaction_history.php" class="active"><i class="fas fa-receipt"></i> Riwayat Transaksi</a></li>
            </ul>
        </nav>
        <div class="nav-icons">
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
        <?php if ($profile_incomplete): ?>
            <div id="profileCompletionPopup" class="profile-popup">
                <i class="fas fa-exclamation-circle popup-icon"></i>
                <p>Profil lo belum lengkap nih! <a href="profile.php">Lengkapi sekarang</a> biar belanja makin asik.</p>
                <button class="close-popup-btn">&times;</button>
            </div>
        <?php endif; ?>

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
        <p>&copy; 2025 Wearnity by THANKSINSOMNIA. All Rights Reserved.</p>
    </footer>

    <div class="cart-overlay" id="cartOverlay"></div>
    <div class="cart-sidebar" id="cartSidebar"></div>
    <div id="toast-container"></div>

    <script>
        // Profile Dropdown
        const profileIcon = document.getElementById('profileIcon');
        const profileDropdown = document.getElementById('profileDropdown');

        if (profileIcon && profileDropdown) {
             // Dropdown is handled by CSS hover on desktop, 
             // but we keep the element for consistency
        }

        document.addEventListener('DOMContentLoaded', function() {
            // --- Toast Logic ---
            function showToast(message, type = 'success') {
                const container = document.getElementById('toast-container');
                if (!container) return;
                const toast = document.createElement('div');
                toast.className = `toast ${type}`;
                toast.innerHTML = `<i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'}"></i><span>${message}</span>`;
                container.appendChild(toast);
                setTimeout(() => {
                    toast.classList.add('fade-out');
                    setTimeout(() => toast.remove(), 500);
                }, 3000);
            }

            // --- Shopping Cart Logic ---
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

            if (cartIcon) cartIcon.onclick = (e) => { e.preventDefault(); toggleCartSidebar(); };
            if (cartOverlay) cartOverlay.onclick = toggleCartSidebar;

            function loadCartContent() {
                fetch('_cart_content.php').then(r => r.text()).then(html => {
                    cartSidebar.innerHTML = html;
                    attachCartItemListeners();
                }).catch(err => console.error('Error loading cart:', err));
            }

            function attachCartItemListeners() {
                const closeBtn = document.getElementById('closeCartBtn');
                if (closeBtn) closeBtn.onclick = toggleCartSidebar;

                document.querySelectorAll('.qty-btn').forEach(btn => {
                    btn.onclick = function() {
                        const pid = this.closest('.cart-item').dataset.productId;
                        const action = this.dataset.action;
                        const qtyEl = this.parentNode.querySelector('.item-quantity');
                        const currentQty = parseInt(qtyEl.textContent);
                        const newQty = action === 'plus' ? currentQty + 1 : currentQty - 1;
                        if (newQty > 0) updateCartItemQuantity(pid, newQty);
                        else if (confirm('Hapus item dari keranjang?')) updateCartItemQuantity(pid, 0);
                    };
                });

                document.querySelectorAll('.remove-item-btn').forEach(btn => {
                    btn.onclick = function() {
                        if (confirm('Hapus item dari keranjang?')) {
                            const pid = this.closest('.cart-item').dataset.productId;
                            updateCartItemQuantity(pid, 0);
                        }
                    };
                });
            }

            function updateCartItemQuantity(pid, qty) {
                fetch('cart_actions.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `action=update_quantity&product_id=${pid}&quantity=${qty}`
                }).then(r => r.json()).then(data => {
                    if (data.success) loadCartContent();
                    else showToast(data.message, 'error');
                }).catch(err => console.error('Error updating qty:', err));
            }

            // --- Payment Confirmation Handler ---
            document.querySelectorAll('.btn-pay-now').forEach(btn => {
                btn.addEventListener('click', function(e) {
                    e.preventDefault();
                    const transactionId = this.dataset.transactionId;
                    if (transactionId) {
                        fetch('set_transaction_session.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: 'transaction_id=' + transactionId
                        })
                        .then(r => r.json())
                        .then(data => {
                            if (data.success) window.location.href = 'payment_confirmation.php';
                            else showToast(data.message, 'error');
                        })
                        .catch(err => showToast('Terjadi kesalahan teknis.', 'error'));
                    }
                });
            });

            // Profile Popup
            const profilePopup = document.getElementById('profileCompletionPopup');
            if (profilePopup) {
                setTimeout(() => profilePopup.style.display = 'flex', 1000);
                profilePopup.querySelector('.close-popup-btn').onclick = () => profilePopup.style.display = 'none';
            }

            // Initial cart listener
            attachCartItemListeners();
        });
    </script>
</body>
</html>