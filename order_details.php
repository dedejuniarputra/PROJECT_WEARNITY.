<?php
require_once 'config.php'; // Memuat konfigurasi database dan memulai session

// Cek apakah user sudah login. Jika belum, arahkan kembali ke halaman login.
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("location: index.php");
    exit;
}

$user_id = $_SESSION['id'];
$username = $_SESSION['username'];
$full_name = $email = $phone_number = $address = "";
$transaction_id = ''; // Ini akan digunakan untuk menyimpan kode transaksi yang digenerate
$cart_session_data = isset($_SESSION['cart']) ? $_SESSION['cart'] : [];
$detailed_cart_items = [];
$total_order_price = 0;
$order_message = '';
$order_message_type = '';
$shipping_courier_err = ''; // NEW: Error untuk pemilihan kurir
$selected_courier = ''; // NEW: Variabel untuk menyimpan kurir yang dipilih

// Ambil data user yang sedang login dari database untuk pre-fill form
try {
    $sql_select_user = "SELECT full_name, email, phone_number, address FROM users WHERE id = :id";
    if ($stmt = $pdo->prepare($sql_select_user)) {
        $stmt->bindParam(":id", $user_id, PDO::PARAM_INT);
        if ($stmt->execute()) {
            if ($stmt->rowCount() == 1) {
                $user_data = $stmt->fetch(PDO::FETCH_ASSOC);
                $full_name = htmlspecialchars($user_data['full_name']);
                $email = htmlspecialchars($user_data['email']);
                $phone_number = htmlspecialchars($user_data['phone_number']);
                $address = htmlspecialchars($user_data['address']);
            }
        }
        unset($stmt);
    }
} catch (PDOException $e) {
    $order_message = "Terjadi kesalahan saat mengambil data profil: " . $e->getMessage();
    $order_message_type = 'danger';
}

// --- Proses Konfirmasi Pesanan (Submit Form) ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['confirm_order'])) {
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
    <title>Pesanan Anda | WEARNITY</title>
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

        /* Header / Navigation Bar (Consistent with dashboard/transaction_history) */
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

        /* Logo Text Styling (Consistent with dashboard/transaction_history) */
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

        /* Profile Dropdown (Consistent with dashboard/transaction_history) */
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

        /* Shopping Cart Sidebar Styles (Consistent with dashboard/transaction_history) */
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

        /* Order Details Specific Styles */
        .order-container {
            max-width: 900px;
            margin: 40px auto;
            background-color: #fff;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 0 15px rgba(0, 0, 0, 0.1);
        }

        .order-container h2 {
            text-align: center;
            margin-bottom: 30px;
            color: #333;
            font-weight: 700; /* Konsisten dengan dashboard */
        }

        .order-details-section {
            margin-bottom: 30px;
            border: 1px solid #eee;
            border-radius: 8px;
            padding: 20px;
            background-color: #f9f9f9;
        }

        .order-details-section h3 {
            color: #007bff;
            margin-top: 0;
            margin-bottom: 15px;
            font-size: 1.8em;
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 600; /* Konsisten dengan dashboard */
        }

        .order-details-section h3 i {
            font-size: 1.2em;
        }

        .transaction-code {
            font-size: 1.1em;
            color: #555;
            margin-bottom: 20px;
            text-align: center;
            padding: 10px;
            background-color: #e6f2ff;
            border: 1px dashed #007bff;
            border-radius: 5px;
            font-weight: bold;
        }

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

        /* Receiver Info Form (similar to profile form) */
        .receiver-info-form .form-group {
            margin-bottom: 20px;
        }

        .receiver-info-form label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600; /* Konsisten dengan dashboard */
            color: #444; /* Konsisten dengan dashboard */
            font-size: 15px; /* Konsisten dengan dashboard */
        }

        .receiver-info-form input[type="text"],
        .receiver-info-form input[type="email"],
        .receiver-info-form input[type="tel"],
        .receiver-info-form textarea {
            width: calc(100% - 22px);
            padding: 12px 15px; /* Konsisten dengan dashboard */
            border: 1px solid #ddd;
            border-radius: 8px; /* Konsisten dengan dashboard */
            font-size: 16px;
            background-color: #e9ecef; /* Read-only style */
            cursor: not-allowed;
            box-sizing: border-box; /* Pastikan padding masuk hitungan width */
        }
        /* NEW: Styles for the select element */
        .receiver-info-form select {
            width: calc(100% - 22px); /* Same width as inputs */
            padding: 12px 15px; /* Konsisten dengan input */
            border: 1px solid #ddd;
            border-radius: 8px; /* Konsisten dengan input */
            font-size: 16px;
            background-color: white; /* Make it white, not gray like readonly inputs */
            cursor: pointer;
            -webkit-appearance: none; /* Remove default arrow on Webkit */
            -moz-appearance: none;    /* Remove default arrow on Firefox */
            appearance: none;         /* Remove default arrow */
            /* Custom arrow icon for select */
            background-image: url('data:image/svg+xml;charset=US-ASCII,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%22292.4%22%20height%3D%22292.4%22%3E%3Cpath%20fill%3D%22%23000%22%20d%3D%22M287%2C197.352L146.2%2C56.652L5.4%2C197.352H287z%22%2F%3E%3C%2Fsvg%3E');
            background-repeat: no-repeat;
            background-position: right 0.7em top 50%, 0 0;
            background-size: 0.65em auto, 100%;
            box-sizing: border-box; /* Pastikan padding masuk hitungan width */
        }
        .receiver-info-form select:focus {
            outline: none;
            border-color: #007bff;
            box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.25); /* Konsisten dengan dashboard */
        }
        .receiver-info-form select.is-invalid {
            border-color: #dc3545;
        }

        textarea {
            resize: vertical;
            min-height: 80px;
        }

        .btn-confirm-order {
            background-color: #28a745;
            color: white;
            padding: 15px 25px;
            border: none;
            border-radius: 8px; /* Konsisten dengan dashboard */
            cursor: pointer;
            font-size: 1.1em; /* Sedikit disesuaikan */
            width: 100%;
            transition: background-color 0.3s ease, transform 0.2s ease; /* Konsisten */
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            font-weight: 600; /* Konsisten */
            margin-top: 30px;
        }

        .btn-confirm-order:hover {
            background-color: #218838;
            transform: translateY(-2px); /* Konsisten */
            box-shadow: 0 2px 8px rgba(0,0,0,0.1); /* Konsisten */
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
            /* Other responsive rules from dashboard.php for general elements if applicable */
        }

        @media (max-width: 768px) {
            body {
                padding-top: 130px; /* Adjust if header layout changes */
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

            .order-container {
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

            .btn-confirm-order {
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

            .order-details-section h3 {
                font-size: 1.5em;
            }
            .transaction-code {
                font-size: 0.9em;
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
        <div class="order-container">
            <h2>Pesanan Anda</h2>

            <?php if (!empty($order_message)): ?>
                <div class="alert alert-<?php echo $order_message_type; ?>"><?php echo $order_message; ?></div>
            <?php endif; ?>

            <?php if (empty($detailed_cart_items)): ?>
                <div class="alert alert-danger">Keranjang Anda kosong. Tidak ada pesanan untuk ditampilkan. Silakan belanja terlebih dahulu. <a href="dashboard.php#katalog">Lihat Katalog</a></div>
            <?php else: ?>
                <div class="order-details-section">
                    <h3><i class="fas fa-shopping-bag"></i> Detail Produk</h3>
                    <div class="transaction-code">Kode Transaksi: <strong><?php echo htmlspecialchars($transaction_id); ?></strong></div>

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
                            <?php foreach ($detailed_cart_items as $item): ?>
                                <tr>
                                    <td data-label="Produk">
                                        <div class="product-item-details">
                                            <img src="<?php echo htmlspecialchars($item['image_path']); ?>" alt="<?php echo htmlspecialchars($item['name']); ?>">
                                            <span><?php echo htmlspecialchars($item['name']); ?></span>
                                        </div>
                                    </td>
                                    <td data-label="Harga Satuan">Rp <?php echo number_format($item['price'], 0, ',', '.'); ?></td>
                                    <td data-label="Jumlah"><?php echo htmlspecialchars($item['quantity']); ?></td>
                                    <td data-label="Subtotal">Rp <?php echo number_format($item['item_total'], 0, ',', '.'); ?></td>
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

                <div class="order-details-section">
                    <h3><i class="fas fa-user-check"></i> Detail Penerima</h3>
                    <form class="receiver-info-form" method="POST" action="">
                        <div class="form-group">
                            <label for="receiver_name">Nama Lengkap Penerima</label>
                            <input type="text" id="receiver_name" name="receiver_name" value="<?php echo $full_name; ?>" readonly>
                            <small style="color: #777;">Data diambil dari profil Anda.</small>
                        </div>
                        <div class="form-group">
                            <label for="receiver_email">Email Penerima</label>
                            <input type="email" id="receiver_email" name="receiver_email" value="<?php echo $email; ?>" readonly>
                        </div>
                        <div class="form-group">
                            <label for="receiver_phone">Nomor Telepon Penerima</label>
                            <input type="tel" id="receiver_phone" name="receiver_phone" value="<?php echo $phone_number; ?>" readonly>
                        </div>
                        <div class="form-group">
                            <label for="receiver_address">Alamat Pengiriman</label>
                            <textarea id="receiver_address" name="receiver_address" readonly><?php echo $address; ?></textarea>
                        </div>
                        <div class="form-group">
                            <label for="shipping_courier">Pilih Kurir Pengiriman<span style="color: red;">*</span></label>
                            <select id="shipping_courier" name="shipping_courier" class="<?php echo (!empty($shipping_courier_err) ? 'is-invalid' : ''); ?>" required>
                                <option value="" <?php echo (empty($selected_courier) ? 'selected' : ''); ?>>-- Pilih Kurir --</option>
                                <option value="JNE" <?php echo ($selected_courier == 'JNE' ? 'selected' : ''); ?>>JNE</option>
                                <option value="J&T Express" <?php echo ($selected_courier == 'J&T Express' ? 'selected' : ''); ?>>J&T Express</option>
                                <option value="SiCepat" <?php echo ($selected_courier == 'SiCepat' ? 'selected' : ''); ?>>SiCepat</option>
                                <option value="AnterAja" <?php echo ($selected_courier == 'AnterAja' ? 'selected' : ''); ?>>AnterAja</option>
                            </select>
                            <span class="invalid-feedback" id="shipping_courier_err_msg"><?php echo $shipping_courier_err; ?></span>
                        </div>
                        <button type="submit" name="confirm_order" class="btn-confirm-order">
                            <i class="fas fa-check-circle"></i> Konfirmasi Pesanan
                        </button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <footer>
        <p>Copyright © 2025 - Wearnity by THANKSINSOMNIA</p>
    </footer>

    <div class="cart-overlay" id="cartOverlay"></div> <div class="cart-sidebar" id="cartSidebar">
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
                    window.location.href = 'order_details.php'; // Redirect to order details page
                });
            }
        }
    </script>
</body>

</html>