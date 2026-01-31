<?php
require_once 'config.php'; // Memuat konfigurasi database dan memulai session

// Cek apakah user sudah login. Jika belum, arahkan kembali ke halaman login.
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("location: index.php");
    exit;
}

$user_id = $_SESSION['id'];
$username = $_SESSION['username'];
$transaction_id = ''; 
$cart_session_data = isset($_SESSION['cart']) ? $_SESSION['cart'] : [];
$detailed_cart_items = [];
$total_order_price = 0;
$order_message = $order_message ?? '';
$order_message_type = $order_message_type ?? '';
$shipping_courier_err = ''; 
$selected_courier = ''; 
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
        
        // Pre-fill variables for the form
        $full_name = htmlspecialchars($user_profile['full_name'] ?? '');
        $email = htmlspecialchars($user_profile['email'] ?? '');
        $phone_number = htmlspecialchars($user_profile['phone_number'] ?? '');
        $address = htmlspecialchars($user_profile['address'] ?? '');
    }
} catch (PDOException $e) {
    error_log("Error fetching user profile data: " . $e->getMessage());
}
// ===============================================
// END LOGIKA CEK KELENGKAPAN PROFIL PENGGUNA
// ===============================================

// Flag for allowing order submission
$profile_complete = !$profile_incomplete;
if ($profile_incomplete) {
    $order_message = "Untuk melanjutkan transaksi, mohon lengkapi data profil Anda (Nama Lengkap, Email, Nomor Telepon, dan Alamat Pengiriman) di halaman <a href='profile.php'>Profil Saya</a>.";
    $order_message_type = 'warning';
}

// --- Proses Konfirmasi Pesanan (Submit Form) ---
// NEW: Tambahkan kondisi $profile_complete di sini agar transaksi hanya bisa dilakukan jika profil lengkap
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['confirm_order']) && $profile_complete) {
    // NEW: Validasi kurir pengiriman
    if (empty(trim($_POST["shipping_courier"]))) {
        $shipping_courier_err = "Mohon pilih kurir pengiriman.";
        $order_message = "Harap lengkapi semua data yang diperlukan.";
        $order_message_type = 'danger';
    } else {
        $selected_courier = trim($_POST["shipping_courier"]);
    }

    if (empty($cart_session_data)) {
        $order_message = "Keranjang Anda kosong, tidak dapat membuat pesanan.";
        $order_message_type = 'danger';
    } elseif (empty($shipping_courier_err)) { // NEW: Hanya proses jika tidak ada error kurir
        try {
            $pdo->beginTransaction();

            // 1. Generate kode transaksi unik
            $transaction_id = 'TRX-' . strtoupper(uniqid());

            // 2. Ambil detail produk untuk perhitungan total akhir dan penyimpanan transaction_items
            $productIdsInCart = array_keys($cart_session_data);
            $final_total_amount = 0;
            $items_to_insert = [];

            if (!empty($productIdsInCart)) {
                $placeholders = implode(',', array_fill(0, count($productIdsInCart), '?'));
                $sql_products = "SELECT id, name, price, image_path FROM products WHERE id IN ($placeholders)";
                $stmt_products = $pdo->prepare($sql_products);
                foreach ($productIdsInCart as $k => $id) {
                    $stmt_products->bindValue(($k + 1), $id, PDO::PARAM_INT);
                }
                $stmt_products->execute();
                $dbProducts = $stmt_products->fetchAll(PDO::FETCH_ASSOC);

                foreach ($dbProducts as $product) {
                    $qty = $cart_session_data[$product['id']];
                    $item_price_at_purchase = $product['price'];
                    $item_subtotal = $item_price_at_purchase * $qty;
                    $final_total_amount += $item_subtotal;

                    $items_to_insert[] = [
                        'product_id' => $product['id'],
                        'quantity' => $qty,
                        'price_at_purchase' => $item_price_at_purchase
                    ];
                }
            }

            if (!empty($items_to_insert)) {
                // 3. Insert ke tabel `transactions` dengan kurir pengiriman
                // UBAH: Status awal menjadi 'Waiting Payment'
                $sql_insert_transaction = "INSERT INTO transactions (user_id, transaction_code, total_amount, status, order_date, shipping_courier) VALUES (:user_id, :transaction_code, :total_amount, :status, NOW(), :shipping_courier)";
                $stmt_trans = $pdo->prepare($sql_insert_transaction);
                $stmt_trans->bindParam(":user_id", $user_id, PDO::PARAM_INT);
                $stmt_trans->bindParam(":transaction_code", $transaction_id, PDO::PARAM_STR);
                $stmt_trans->bindParam(":total_amount", $final_total_amount, PDO::PARAM_STR);
                $status = 'Waiting Payment'; // UBAH STATUS DI SINI
                $stmt_trans->bindParam(":status", $status, PDO::PARAM_STR);
                $stmt_trans->bindParam(":shipping_courier", $selected_courier, PDO::PARAM_STR); // NEW: Bind kurir
                $stmt_trans->execute();
                $last_transaction_id = $pdo->lastInsertId();

                // 4. Insert ke tabel `transaction_items`
                $sql_insert_item = "INSERT INTO transaction_items (transaction_id, product_id, quantity, price_at_purchase) VALUES (:transaction_id, :product_id, :quantity, :price_at_purchase)";
                $stmt_item = $pdo->prepare($sql_insert_item);

                foreach ($items_to_insert as $item) {
                    $stmt_item->bindParam(":transaction_id", $last_transaction_id, PDO::PARAM_INT);
                    $stmt_item->bindParam(":product_id", $item['product_id'], PDO::PARAM_INT);
                    $stmt_item->bindParam(":quantity", $item['quantity'], PDO::PARAM_INT);
                    $stmt_item->bindParam(":price_at_purchase", $item['price_at_purchase'], PDO::PARAM_STR);
                    $stmt_item->execute();
                }

                $pdo->commit();
                $_SESSION['cart'] = []; // Kosongkan keranjang

                // BARU: Simpan ID transaksi di session dan arahkan ke halaman konfirmasi pembayaran
                $_SESSION['current_transaction_id'] = $last_transaction_id;
                $_SESSION['current_transaction_code'] = $transaction_id; // Simpan juga kode transaksi
                $_SESSION['order_success_message'] = "Pesanan Anda berhasil dibuat! Mohon lakukan pembayaran untuk melanjutkan.";
                header("location: payment_confirmation.php"); // Arahkan ke halaman konfirmasi pembayaran
                exit;
            } else {
                $pdo->rollBack();
                $order_message = "Keranjang Anda kosong, tidak ada produk untuk dipesan.";
                $order_message_type = 'danger';
            }
        } catch (PDOException $e) {
            $pdo->rollBack();
            $order_message = "Gagal membuat pesanan: " . $e->getMessage();
            $order_message_type = 'danger';
            error_log("Order creation failed: " . $e->getMessage());
        }
    }
}


// --- Ambil Detail Produk dari Keranjang untuk Tampilan (Ini akan dieksekusi setiap kali halaman dimuat, baik GET/POST) ---
if (!empty($cart_session_data)) {
    $productIds = array_keys($cart_session_data);
    $placeholders = implode(',', array_fill(0, count($productIds), '?'));

    $sql = "SELECT id, name, price, image_path FROM products WHERE id IN ($placeholders)";

    try {
        $stmt = $pdo->prepare($sql);
        foreach ($productIds as $k => $id) {
            $stmt->bindValue(($k + 1), $id, PDO::PARAM_INT);
        }
        $stmt->execute();
        $dbProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($dbProducts as $product) {
            $qty = $cart_session_data[$product['id']];
            $itemTotal = $product['price'] * $qty;
            $total_order_price += $itemTotal;
            $detailed_cart_items[] = [
                'id' => $product['id'],
                'name' => $product['name'],
                'price' => $product['price'],
                'image_path' => $product['image_path'],
                'quantity' => $qty,
                'item_total' => $itemTotal
            ];
        }
    } catch (PDOException $e) {
        $order_message = "Error memuat detail produk dari keranjang: " . $e->getMessage();
        $order_message_type = 'danger';
        $detailed_cart_items = [];
    }
}

// Generate kode transaksi hanya jika belum dikonfirmasi atau keranjang tidak kosong
// (Ini hanya untuk tampilan sementara, kode transaksi yang sebenarnya digenerate saat POST)
if (empty($transaction_id) && !empty($detailed_cart_items)) {
    $transaction_id = 'TRX-' . strtoupper(uniqid());
} elseif (empty($detailed_cart_items)) {
    $transaction_id = 'N/A';
}

unset($pdo); // Tutup koneksi database setelah semua operasi
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Selesaikan Pesanan | WEARNITY</title>
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

        /* Order Details Specific */
        .page-header {
            max-width: 1100px;
            margin: 0 auto 30px;
            padding: 0 20px;
        }

        .page-header h1 {
            font-size: 2.5rem;
            color: var(--primary-color);
            letter-spacing: -1.5px;
            margin-bottom: 8px;
        }

        .page-header p {
            color: var(--text-secondary);
            font-weight: 500;
        }

        .order-grid {
            max-width: 1100px;
            margin: 0 auto 80px;
            display: grid;
            grid-template-columns: 1.6fr 1fr;
            gap: 30px;
            padding: 0 20px;
        }

        .checkout-card {
            background: white;
            border-radius: var(--radius-xl);
            padding: 40px;
            box-shadow: var(--shadow-lg);
            border: 1px solid rgba(0, 0, 0, 0.05);
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

        .order-items-list {
            margin-bottom: 30px;
        }

        .order-item-mini {
            display: flex;
            gap: 20px;
            padding: 20px 0;
            border-bottom: 1px solid var(--border-color);
        }

        .order-item-mini:last-child {
            border-bottom: none;
        }

        .order-item-mini img {
            width: 80px;
            height: 100px;
            object-fit: cover;
            border-radius: var(--radius-md);
        }

        .item-info-mini h4 {
            font-size: 1rem;
            font-weight: 700;
            margin-bottom: 4px;
            color: var(--primary-color);
        }

        .item-info-mini p {
            font-size: 0.9rem;
            color: var(--text-secondary);
        }

        .item-price-mini {
            margin-left: auto;
            text-align: right;
        }

        .item-price-mini .price {
            font-weight: 700;
            color: var(--primary-color);
            display: block;
        }

        .item-price-mini .qty {
            font-size: 0.85rem;
            color: var(--text-secondary);
            font-weight: 500;
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

        .form-group input:focus, .form-group textarea:focus, .form-group select:focus {
            border-color: var(--secondary-color);
            outline: none;
            background: white;
            box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.1);
        }

        .form-group input[readonly], .form-group textarea[readonly] {
            background: #f1f5f9;
            cursor: not-allowed;
            border-color: #e2e8f0;
        }

        .form-group select {
            cursor: pointer;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke='%2364748b'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M19 9l-7 7-7-7'%3E%3C/path%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 20px center;
            background-size: 20px;
        }

        .summary-card {
            height: fit-content;
            position: sticky;
            top: 110px;
            background: var(--primary-color);
            color: white;
            border-radius: var(--radius-xl);
            padding: 40px;
            box-shadow: var(--shadow-xl);
        }

        .summary-card h3 {
            font-size: 1.5rem;
            margin-bottom: 30px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .summary-line {
            display: flex;
            justify-content: space-between;
            margin-bottom: 15px;
            color: rgba(255, 255, 255, 0.7);
            font-weight: 500;
        }

        .summary-total {
            margin-top: 30px;
            padding-top: 30px;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
        }

        .summary-total .label {
            font-weight: 600;
            font-size: 1rem;
        }

        .summary-total .value {
            font-size: 2rem;
            font-weight: 800;
            letter-spacing: -1px;
            color: var(--accent-color);
        }

        .btn-confirm {
            width: 100%;
            background: var(--secondary-color);
            color: white;
            padding: 20px;
            border-radius: 18px;
            font-size: 1.1rem;
            font-weight: 700;
            border: none;
            cursor: pointer;
            margin-top: 40px;
            transition: all 0.4s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            box-shadow: 0 10px 25px rgba(99, 102, 241, 0.3);
        }

        .btn-confirm:hover:not(:disabled) {
            transform: translateY(-5px);
            box-shadow: 0 15px 35px rgba(99, 102, 241, 0.4);
            filter: brightness(1.1);
        }

        .btn-confirm:disabled {
            background: #475569;
            cursor: not-allowed;
            box-shadow: none;
            opacity: 0.7;
        }

        .alert {
            padding: 20px;
            border-radius: var(--radius-md);
            margin-bottom: 30px;
            display: flex;
            align-items: center;
            gap: 15px;
            font-weight: 600;
        }

        .alert-warning {
            background: #fff7ed;
            color: #c2410c;
            border: 1px solid #ffedd5;
        }

        .alert-danger {
            background: #fef2f2;
            color: #991b1b;
            border: 1px solid #fee2e2;
        }

        .alert a {
            color: var(--secondary-color);
            text-decoration: underline;
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

        /* Cart Sidebar & Mobile Responsive */
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

        .cart-sidebar.open { right: 0; }

        @media (max-width: 1024px) {
            .order-grid { grid-template-columns: 1fr; }
            .summary-card { position: static; margin-top: 20px; }
        }

        @media (max-width: 768px) {
            header { width: 95%; padding: 0 15px; }
            .nav-links { display: none; }
            .page-header h1 { font-size: 2rem; }
            .checkout-card { padding: 25px; }
        }

        /* Popup */
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
            <a href="#" class="icon-btn" id="cartIcon">
                <i class="fas fa-shopping-cart"></i>
                <span class="notification-badge-dot"></span>
            </a>
            <div class="profile-icon-container">
                <a href="profile.php" class="icon-btn" id="profileIcon">
                    <i class="fas fa-user"></i>
                </a>
                <div class="profile-dropdown" id="profileDropdown">
                    <?php if($profile_incomplete): ?>
                        <div class="dropdown-warning">
                            <i class="fas fa-exclamation-triangle"></i> Profil Belum Lengkap!
                        </div>
                    <?php endif; ?>
                    <ul>
                        <li><a href="profile.php"><i class="fas fa-user-circle"></i> Profile Saya</a></li>
                        <li><a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </header>

    <main>
        <div class="page-header">
            <h1>Selesaikan Pesanan</h1>
            <p>Konfirmasi detail pengiriman dan selesaikan transaksi Anda.</p>
        </div>

        <div class="order-grid">
            <div class="left-col">
                <?php if (!empty($order_message)): ?>
                    <div class="alert alert-<?php echo $order_message_type; ?>">
                        <i class="fas <?php echo $order_message_type === 'warning' ? 'fa-exclamation-triangle' : 'fa-times-circle'; ?>"></i>
                        <div><?php echo $order_message; ?></div>
                    </div>
                <?php endif; ?>

                <div class="checkout-card">
                    <h3 class="card-title"><i><i class="fas fa-shopping-bag"></i></i> Detail Produk</h3>
                    <div class="order-items-list">
                        <?php foreach ($detailed_cart_items as $item): ?>
                            <div class="order-item-mini">
                                <img src="<?php echo htmlspecialchars($item['image_path']); ?>" alt="<?php echo htmlspecialchars($item['name']); ?>">
                                <div class="item-info-mini">
                                    <h4><?php echo htmlspecialchars($item['name']); ?></h4>
                                    <p>Produk Pilihan Terlaris</p>
                                </div>
                                <div class="item-price-mini">
                                    <span class="price">Rp <?php echo number_format($item['price'], 0, ',', '.'); ?></span>
                                    <span class="qty">x <?php echo htmlspecialchars($item['quantity']); ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <h3 class="card-title" style="margin-top: 40px;"><i><i class="fas fa-truck"></i></i> Informasi Pengiriman</h3>
                    <form class="receiver-info-form" method="POST" action="">
                        <div class="form-group">
                            <label>Nama Lengkap</label>
                            <input type="text" value="<?php echo $full_name; ?>" readonly>
                        </div>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                            <div class="form-group">
                                <label>Email</label>
                                <input type="email" value="<?php echo $email; ?>" readonly>
                            </div>
                            <div class="form-group">
                                <label>Nomor Telepon</label>
                                <input type="tel" value="<?php echo $phone_number; ?>" readonly>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Alamat Lengkap</label>
                            <textarea readonly><?php echo $address; ?></textarea>
                        </div>
                        <div class="form-group">
                            <label for="shipping_courier">Pilih Kurir Pengiriman <span style="color: #ef4444;">*</span></label>
                            <select id="shipping_courier" name="shipping_courier" required <?php echo ($profile_complete ? '' : 'disabled'); ?>>
                                <option value="" <?php echo (empty($selected_courier) ? 'selected' : ''); ?>>-- Pilih Jasa Pengiriman --</option>
                                <option value="JNE" <?php echo ($selected_courier == 'JNE' ? 'selected' : ''); ?>>JNE - Layanan Kilat</option>
                                <option value="J&T Express" <?php echo ($selected_courier == 'J&T Express' ? 'selected' : ''); ?>>J&T Express - Cepat & Aman</option>
                                <option value="SiCepat" <?php echo ($selected_courier == 'SiCepat' ? 'selected' : ''); ?>>SiCepat - Best Service</option>
                                <option value="AnterAja" <?php echo ($selected_courier == 'AnterAja' ? 'selected' : ''); ?>>AnterAja - Terpercaya</option>
                            </select>
                        </div>
                        <input type="hidden" name="confirm_order" value="1">
                        <button type="submit" class="btn-confirm" <?php echo ($profile_complete ? '' : 'disabled'); ?>>
                            <i class="fas fa-lock"></i> Konfirmasi & Bayar Sekarang
                        </button>
                    </form>
                </div>
            </div>

            <div class="right-col">
                <div class="summary-card">
                    <h3>Ringkasan Pesanan <i class="fas fa-file-invoice"></i></h3>
                    <div class="summary-line">
                        <span>Kode Transaksi</span>
                        <span style="color: white; font-weight: 700;"><?php echo htmlspecialchars($transaction_id); ?></span>
                    </div>
                    <div class="summary-line">
                        <span>Subtotal (<?php echo count($detailed_cart_items); ?> Produk)</span>
                        <span>Rp <?php echo number_format($total_order_price, 0, ',', '.'); ?></span>
                    </div>
                    <div class="summary-line">
                        <span>Biaya Pengiriman</span>
                        <span style="color: #4ade80;">Gratis</span>
                    </div>
                    <div class="summary-line">
                        <span>PPN (11%)</span>
                        <span>Termasuk</span>
                    </div>

                    <div class="summary-total">
                        <div class="label">Total Pembayaran</div>
                        <div class="value">Rp <?php echo number_format($total_order_price, 0, ',', '.'); ?></div>
                    </div>

                    <div style="margin-top: 30px; font-size: 0.85rem; color: rgba(255, 255, 255, 0.5); text-align: center;">
                        <i class="fas fa-shield-alt"></i> Pembayaran Aman & Terenkripsi
                    </div>
                </div>
            </div>
        </div>
    </main>

    <footer>
        <p>&copy; 2025 Wearnity by THANKSINSOMNIA. All Rights Reserved.</p>
    </footer>

    <div class="cart-overlay" id="cartOverlay"></div>
    <div class="cart-sidebar" id="cartSidebar"></div>

    <?php if($profile_incomplete): ?>
    <div class="profile-popup" id="profileCompletionPopup">
        <div class="popup-icon"><i class="fas fa-exclamation-circle"></i></div>
        <p>Ayo lengkapi profil lo dulu biar bisa gas belanja! <a href="profile.php">Klik di sini buat lengkapi.</a></p>
        <button class="close-popup-btn">&times;</button>
    </div>
    <?php endif; ?>

    <div id="toast-container"></div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Profile Dropdown Handling
            const profileIcon = document.getElementById('profileIcon');
            const profileDropdown = document.getElementById('profileDropdown');

            if (profileIcon && profileDropdown) {
                profileIcon.addEventListener('click', function(e) {
                    // Navigate only on double click or context, but here we let it hover on desktop
                    // and handle click for mobile if needed.
                });

                document.addEventListener('click', function(event) {
                    if (!profileIcon.contains(event.target) && !profileDropdown.contains(event.target)) {
                        profileDropdown.classList.remove('show');
                    }
                });
            }

            // Cart Sidebar Logic
            const cartSidebar = document.getElementById('cartSidebar');
            const cartOverlay = document.getElementById('cartOverlay');
            const cartIcon = document.getElementById('cartIcon');

            function toggleCartSidebar() {
                cartSidebar.classList.toggle('open');
                cartOverlay.style.display = cartSidebar.classList.contains('open') ? 'block' : 'none';
                document.body.style.overflow = cartSidebar.classList.contains('open') ? 'hidden' : '';
                if (cartSidebar.classList.contains('open')) loadCartContent();
            }

            if (cartIcon) {
                cartIcon.addEventListener('click', function(e) {
                    e.preventDefault();
                    toggleCartSidebar();
                });
            }

            if (cartOverlay) {
                cartOverlay.addEventListener('click', toggleCartSidebar);
            }

            function loadCartContent() {
                fetch('_cart_content.php')
                    .then(r => r.text())
                    .then(html => {
                        cartSidebar.innerHTML = html;
                        attachCartListeners();
                    });
            }

            function attachCartListeners() {
                const closeBtn = cartSidebar.querySelector('.close-cart-btn');
                if (closeBtn) closeBtn.onclick = toggleCartSidebar;

                cartSidebar.querySelectorAll('.qty-btn').forEach(btn => {
                    btn.onclick = function() {
                        const id = this.closest('.cart-item').dataset.productId;
                        let qty = parseInt(this.closest('.quantity-controls').querySelector('.item-quantity').textContent);
                        updateQty(id, this.dataset.action === 'plus' ? qty + 1 : qty - 1);
                    };
                });

                cartSidebar.querySelectorAll('.remove-item-btn').forEach(btn => {
                    btn.onclick = function() {
                        if (confirm('Hapus item?')) removeCartItem(this.closest('.cart-item').dataset.productId);
                    };
                });
            }

            function updateQty(id, qty) {
                if (qty <= 0) return removeCartItem(id);
                fetch('cart_actions.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: `action=update_quantity&product_id=${id}&quantity=${qty}`
                }).then(() => loadCartContent());
            }

            function removeCartItem(id) {
                fetch('cart_actions.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: `action=remove&product_id=${id}`
                }).then(() => loadCartContent());
            }

            // Profile Popup
            const profilePopup = document.getElementById('profileCompletionPopup');
            if (profilePopup) {
                setTimeout(() => profilePopup.style.display = 'flex', 1000);
                profilePopup.querySelector('.close-popup-btn').onclick = () => profilePopup.style.display = 'none';
            }

            // Toast Logic
            function showToast(msg, type = 'success') {
                const container = document.getElementById('toast-container');
                const toast = document.createElement('div');
                toast.className = `toast ${type}`;
                toast.innerHTML = `<i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'}"></i><span>${msg}</span>`;
                container.appendChild(toast);
                setTimeout(() => {
                    toast.style.opacity = '0';
                    setTimeout(() => toast.remove(), 500);
                }, 3000);
            }
        });
    </script>
</body>
</html>