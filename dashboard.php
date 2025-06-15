<?php
require_once 'config.php'; // Memuat konfigurasi database dan memulai session

// Cek apakah user sudah login. Jika belum, arahkan kembali ke halaman login.
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("location: index.php");
    exit;
}

// ===============================================
// LOGIKA UNTUK CEK KELENGKAPAN PROFIL PENGGUNA
// ===============================================
$profile_incomplete = false;
$user_profile = [];

try {
    // Pastikan $pdo didefinisikan di config.php
    if (isset($pdo)) {
        $sql_user_profile = "SELECT full_name, email, phone_number, address FROM users WHERE id = :id";
        $stmt_user_profile = $pdo->prepare($sql_user_profile);
        $stmt_user_profile->bindParam(':id', $_SESSION['id'], PDO::PARAM_INT);
        $stmt_user_profile->execute();
        $user_profile = $stmt_user_profile->fetch(PDO::FETCH_ASSOC);

        // Asumsikan profil tidak lengkap jika salah satu dari field berikut kosong/null
        if (
            empty($user_profile['full_name']) ||
            empty($user_profile['email']) ||
            empty($user_profile['phone_number']) ||
            empty($user_profile['address'])
        ) {
            $profile_incomplete = true;
        }
    } else {
        error_log("PDO object not available for profile check in dashboard.php.");
    }
} catch (PDOException $e) {
    error_log("Error fetching user profile data: " . $e->getMessage());
    // Anda bisa tambahkan $error_message di sini jika perlu tampilkan ke user
}
// ===============================================
// END LOGIKA CEK KELENGKAPAN PROFIL PENGGUNA
// ===============================================


// Ambil semua produk dari database
$products = [];
try {
    // Pastikan $pdo didefinisikan di config.php
    if (isset($pdo)) {
        $sql_select_products = "SELECT id, name, price, image_path, brand, tags, description, is_available FROM products ORDER BY created_at DESC";
        $stmt_products = $pdo->prepare($sql_select_products);
        $stmt_products->execute();
        $products = $stmt_products->fetchAll(PDO::FETCH_ASSOC);
    } else {
        error_log("PDO object not available for product fetch in dashboard.php.");
    }
} catch (PDOException $e) {
    error_log("Gagal mengambil data produk: " . $e->getMessage());
}

// Hanya unset $pdo jika sudah yakin tidak ada query lagi yang akan dijalankan
// Jika config.php mengatur koneksi secara global dan halaman lain juga menggunakannya,
// ini mungkin tidak perlu atau bisa dipindahkan ke akhir file.
// Untuk halaman ini, karena semua data sudah diambil, bisa di-unset.
if (isset($pdo)) {
    unset($pdo);
}
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Selamat datang di WEARNITY, pusat fashion kekinian untuk gaya tanpa batas. Temukan koleksi terbaru kami dan lengkapi profil Anda untuk pengalaman belanja terbaik.">
    <title>Dashboard | WEARNITY</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* General Styles & Resets */
        body {
            font-family: 'Montserrat', sans-serif;
            /* Menggunakan Montserrat */
            margin: 0;
            padding: 0;
            background-color: #f0f2f5;
            /* Background yang lebih soft */
            color: #333;
            line-height: 1.6;
            overflow-x: hidden;
            padding-top: 70px;
            /* Add padding-top to body equal to header height */
        }

        a {
            text-decoration: none;
            color: inherit;
        }

        /* Header / Navigation Bar (Consistent with transaction_history.php) */
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

        /* Logo Text Styling (Consistent with transaction_history.php) */
        .logo strong {
            font-size: 28px;
            color: #2c3e50;
            /* Darker color for logo text */
            letter-spacing: 1px;
            font-weight: 700;
            font-family: 'Montserrat', sans-serif;
            /* Pastikan font Montserrat juga di sini */
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

        /* Profile Dropdown (Consistent with transaction_history.php) */
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

        /* Shopping Cart Sidebar Styles (UPDATED & Consistent with latest design) */
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
            /* Lebar sidebar */
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

        /* --- PERBAIKAN PENTING DI SINI --- */
        .cart-item {
            display: flex;
            align-items: center;
            margin-bottom: 15px;
            /* Kurangi sedikit margin-bottom */
            padding: 12px;
            /* Sesuaikan padding */
            border: 1px solid #e9e9e9;
            /* Border lebih soft */
            border-radius: 8px;
            /* Lebih halus */
            background-color: #fff;
            box-shadow: 0 1px 6px rgba(0, 0, 0, 0.05);
            /* Shadow lebih lembut */
            transition: box-shadow 0.2s ease;
        }

        .cart-item:hover {
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            /* Sedikit lebih gelap saat hover */
        }

        .cart-item img {
            width: 60px;
            /* Ukuran gambar sedikit lebih kecil */
            height: 60px;
            /* Ukuran gambar sedikit lebih kecil */
            object-fit: cover;
            border-radius: 6px;
            /* Konsisten dengan gambar */
            margin-right: 12px;
            /* Sesuaikan margin */
            flex-shrink: 0;
        }

        .cart-item .item-details {
            flex-grow: 1;
            display: flex;
            flex-direction: column;
            justify-content: center;
            /* Aligns price and quantity below name nicely */
            gap: 4px;
            /* Kurangi jarak antara nama dan kontrol kuantitas */
        }

        .cart-item .item-name {
            font-weight: 600;
            font-size: 0.95em;
            /* Font nama sedikit lebih kecil */
            color: #333;
            margin-bottom: 0px;
            /* Hapus margin bawah karena ada gap di parent */
            line-height: 1.3;
            /* Atur line-height agar tidak terlalu rapat */
        }

        .cart-item .quantity-controls {
            display: flex;
            align-items: center;
            gap: 5px;
            /* Kurangi jarak antara tombol +/- dan angka */
            font-size: 0.9em;
            /* Font kontrol kuantitas lebih kecil */
            color: #555;
            margin-top: 5px;
            /* Beri sedikit jarak dari nama produk */
        }

        .cart-item .qty-btn {
            background-color: #f5f5f5;
            /* Background tombol sedikit lebih gelap */
            border: 1px solid #ccc;
            /* Border lebih jelas */
            border-radius: 50%;
            width: 26px;
            /* Ukuran tombol lebih kecil */
            height: 26px;
            /* Ukuran tombol lebih kecil */
            display: flex;
            justify-content: center;
            align-items: center;
            cursor: pointer;
            transition: background-color 0.2s ease, color 0.2s ease, border-color 0.2s ease;
            font-size: 0.9em;
            /* Ukuran ikon di tombol lebih kecil */
            color: #555;
        }

        .cart-item .qty-btn:hover {
            background-color: #007bff;
            color: white;
            border-color: #007bff;
        }

        .cart-item .qty-btn i {
            font-size: 0.8em;
            /* Sesuaikan ukuran ikon di dalam tombol */
        }

        .cart-item .item-quantity {
            font-weight: 500;
            color: #333;
            min-width: 15px;
            /* Pastikan ada ruang untuk angka kuantitas */
            text-align: center;
        }

        .cart-item .item-actions {
            display: flex;
            flex-direction: column;
            /* Tetap kolom */
            align-items: flex-end;
            /* Harga dan tombol hapus rata kanan */
            margin-left: 10px;
            /* Kurangi margin kiri */
            flex-shrink: 0;
            gap: 5px;
            /* Jarak antara harga dan tombol hapus */
        }

        .cart-item .item-price {
            font-weight: 700;
            color: #007bff;
            font-size: 1.1em;
            /* Font harga sedikit lebih kecil */
            margin-bottom: 0px;
            /* Hapus margin bawah */
            white-space: nowrap;
        }

        .cart-item .remove-item-btn {
            background: none;
            border: none;
            color: #dc3545;
            cursor: pointer;
            font-size: 0.9em;
            /* Ukuran ikon hapus lebih kecil */
            transition: color 0.2s ease;
            padding: 3px;
            /* Kurangi padding agar tidak terlalu besar */
        }

        .cart-item .remove-item-btn:hover {
            color: #c82333;
        }

        .cart-summary {
            border-top: 1px solid #e0e0e0;
            padding: 20px 25px;
            /* Sesuaikan padding */
            background-color: #fcfcfc;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .cart-summary .total-price {
            font-size: 1.2em;
            /* Total harga sedikit lebih kecil */
            font-weight: 700;
            color: #333;
            display: flex;
            flex-direction: column;
            line-height: 1.2;
        }

        .cart-summary .total-price span:first-child {
            font-size: 0.75em;
            /* "Total:" text lebih kecil */
            color: #777;
            font-weight: 500;
        }

        .cart-summary .total-price span:last-child {
            color: #007bff;
        }

        .checkout-btn {
            background-color: #007bff;
            color: white;
            padding: 15px;
            /* Sesuaikan padding untuk ukuran tombol yang lebih proporsional */
            border: none;
            border-radius: 50%;
            cursor: pointer;
            font-size: 20px;
            /* Sesuaikan ukuran ikon panah */
            display: flex;
            align-items: center;
            justify-content: center;
            width: 50px;
            /* Ukuran tombol lingkaran */
            height: 50px;
            /* Ukuran tombol lingkaran */
            transition: background-color 0.3s ease, transform 0.2s ease;
            box-shadow: 0 3px 8px rgba(0, 123, 255, 0.2);
            /* Shadow lebih lembut */
        }

        .checkout-btn:hover {
            background-color: #0056b3;
            transform: translateY(-2px);
            box-shadow: 0 5px 10px rgba(0, 123, 255, 0.3);
        }

        /* --- AKHIR PERBAIKAN DI SINI --- */

        /* ... (Sisa CSS lainnya tidak diubah) ... */

        @media (max-width: 768px) {

            /* ... (CSS responsive lainnya, pastikan ini juga disesuaikan untuk mobile cart) ... */
            .cart-sidebar {
                width: 100%;
                /* Full width on mobile */
                right: -100%;
            }

            .cart-sidebar.open {
                right: 0;
            }

            .cart-items-list {
                padding: 15px;
                /* Kurangi padding di mobile */
            }

            .cart-item {
                flex-wrap: nowrap;
                /* Jangan wrap item di mobile, cukup scroll horizontal jika perlu */
                justify-content: space-between;
                /* Rata kiri dan kanan */
                align-items: center;
                padding: 10px;
                /* Kurangi padding item di mobile */
            }

            .cart-item img {
                width: 50px;
                /* Ukuran gambar lebih kecil di mobile */
                height: 50px;
                margin-right: 10px;
            }

            .cart-item .item-details {
                flex-grow: 1;
                text-align: left;
                /* Biarkan teks rata kiri */
            }

            .cart-item .item-name {
                font-size: 0.9em;
                /* Nama produk lebih kecil */
            }

            .cart-item .quantity-controls {
                font-size: 0.85em;
                /* Kontrol kuantitas lebih kecil */
                gap: 4px;
            }

            .cart-item .qty-btn {
                width: 24px;
                /* Tombol lebih kecil */
                height: 24px;
                font-size: 0.8em;
            }

            .cart-item .item-actions {
                margin-left: 10px;
                align-items: flex-end;
                /* Tetap rata kanan */
            }

            .cart-item .item-price {
                font-size: 1em;
                /* Harga lebih kecil */
            }

            .cart-item .remove-item-btn {
                font-size: 0.8em;
                /* Tombol hapus lebih kecil */
            }

            .cart-summary {
                padding: 15px;
                /* Kurangi padding summary di mobile */
                flex-direction: row;
                /* Kembali ke row di mobile agar lebih kompak */
                flex-wrap: wrap;
                align-items: center;
                justify-content: space-between;
            }

            .cart-summary .total-price {
                font-size: 1.1em;
                /* Total harga lebih kecil */
                text-align: left;
                flex-direction: row;
                gap: 5px;
            }

            .cart-summary .total-price span:first-child {
                font-size: 1em;
                /* "Total:" normal size */
            }

            .checkout-btn {
                width: 45px;
                /* Tombol checkout lebih kecil */
                height: 45px;
                font-size: 18px;
                margin-top: 0;
                /* Hapus margin atas */
            }
        }

        /* ... (sisa media queries lainnya) ... */

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
            /* Add horizontal padding */
        }


        /* Hero Section Styling (Consistent with latest design) */
        .hero-section {
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 40px 30px;
            max-width: 1200px;
            margin: 0 auto;
            gap: 30px;
            min-height: calc(100vh - 70px);
            /* Adjust to fill remaining viewport height */
        }

        .hero-content-left {
            flex: 1;
            text-align: left;
            padding-right: 0;
        }

        .trending-badge {
            display: inline-flex;
            align-items: center;
            background-color: #007bff;
            color: white;
            padding: 8px 15px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 600;
            /* Bolder */
            margin-bottom: 15px;
            gap: 8px;
        }

        .trending-badge i {
            font-size: 1em;
        }

        .hero-content-left h1 {
            font-size: 3.5em;
            margin: 0 0 10px 0;
            color: #212529;
            line-height: 1.1;
            font-weight: 700;
            /* Bolder */
            text-align: left;
        }

        .hero-content-left p {
            font-size: 1.1em;
            color: #666;
            margin-bottom: 15px;
        }

        .hero-content-left .highlighted-text {
            color: #007bff;
            font-weight: 600;
            /* Bolder */
            margin-bottom: 20px;
            display: block;
        }

        .hero-buttons {
            display: flex;
            gap: 15px;
            margin-bottom: 30px;
        }

        .hero-buttons .btn-primary {
            background-color: #007bff;
            color: white;
            padding: 15px 30px;
            border: none;
            border-radius: 8px;
            font-size: 18px;
            cursor: pointer;
            transition: background-color 0.3s ease, transform 0.2s ease;
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 600;
            /* Bolder */
        }

        .hero-buttons .btn-primary:hover {
            background-color: #0056b3;
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
        }

        .hero-buttons .btn-outline {
            background: none;
            color: #007bff;
            padding: 15px 30px;
            border: 2px solid #007bff;
            border-radius: 8px;
            font-size: 18px;
            cursor: pointer;
            transition: background-color 0.3s ease, color 0.3s ease, transform 0.2s ease;
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 600;
            /* Bolder */
        }

        .hero-buttons .btn-outline:hover {
            background-color: #007bff;
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
        }

        .hero-stats {
            display: flex;
            gap: 15px;
            justify-content: space-between;
        }

        .stat-card {
            background-color: #fff;
            padding: 20px 25px;
            border-radius: 10px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
            text-align: center;
            flex: 1;
        }

        .stat-card i {
            font-size: 2.2em;
            color: #007bff;
            margin-bottom: 10px;
        }

        .stat-card .icon-star {
            color: #ffc107;
        }

        .stat-card .value {
            font-size: 1.8em;
            font-weight: 700;
            color: #212529;
            margin-bottom: 5px;
        }

        .stat-card .label {
            font-size: 0.9em;
            color: #666;
        }

        /* Hero Image - Static Banner */
        .hero-image-right {
            flex: 1;
            position: relative;
            background-color: #fff;
            border-radius: 15px;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.15);
            overflow: hidden;
            display: flex;
            justify-content: center;
            align-items: center;
            height: auto;
            max-width: 500px;
            min-width: 300px;
        }

        .hero-image-right img {
            width: 100%;
            height: auto;
            object-fit: cover;
            display: block;
            border-radius: 15px;
        }

        /* Product Catalog Section (Consistent with latest design) */
        .catalog-section {
            padding: 80px 50px;
            text-align: center;
            max-width: 1200px;
            margin: 0 auto;
        }

        .catalog-section h2 {
            font-size: 3em;
            margin-bottom: 10px;
            color: #333;
            font-weight: 700;
        }

        .catalog-section .subtitle {
            font-size: 1.2em;
            color: #777;
            margin-bottom: 40px;
        }

        .product-cards-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 30px;
            max-width: 1200px;
            margin: 0 auto;
        }

        .product-card {
            background-color: #fff;
            border-radius: 12px;
            box-shadow: 0 4px 18px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            text-align: left;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            position: relative;
            height: auto;
            display: flex;
            flex-direction: column;
        }

        .product-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        }

        .product-card-image-wrapper {
            position: relative;
            width: 100%;
            padding-bottom: 100%;
            height: 0;
            overflow: hidden;
            background-color: #f8f8f8;
            border-top-left-radius: 12px;
            border-top-right-radius: 12px;
        }

        .product-card-image-wrapper img {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
            border-radius: 15px;
        }

        /* Product Badges (Consistent with latest design) */
        .product-badge {
            position: absolute;
            top: 15px;
            color: white;
            padding: 6px 12px;
            border-radius: 5px;
            font-size: 0.85em;
            font-weight: 600;
            z-index: 10;
            white-space: nowrap;
        }

        .product-badge.main {
            left: 15px;
        }

        .product-badge.status {
            right: 15px;
            background-color: #28a745;
        }

        /* Specific badge colors */
        .product-badge.bestseller {
            background-color: #e57373;
        }

        .product-badge.discount {
            background-color: #ff5722;
        }

        .product-badge.shipping-free {
            background-color: #66bb6a;
        }

        .product-badge.new-limited {
            background-color: #880e4f;
        }

        .product-badge.status.out-of-stock {
            background-color: #dc3545;
        }

        .product-badge.status.available {
            background-color: #28a745;
        }

        /* Product Info Area (Consistent with latest design) */
        .product-card-info {
            padding: 15px 20px;
            flex-grow: 1;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        .product-card-info .brand-name {
            font-size: 0.9em;
            color: #888;
            margin-bottom: 5px;
            text-transform: capitalize;
            font-weight: 500;
        }

        .product-card-info h3 {
            font-size: 1.5em;
            margin: 5px 0 10px 0;
            color: #333;
            line-height: 1.3;
            font-weight: 600;
        }

        .product-card-info .product-description {
            font-size: 0.95em;
            color: #666;
            margin-bottom: 15px;
            line-height: 1.4;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .product-card-info .product-tags {
            margin-bottom: 10px;
        }

        .product-card-info .tag {
            display: inline-block;
            background-color: #e9ecef;
            color: #555;
            padding: 4px 10px;
            border-radius: 5px;
            font-size: 0.8em;
            margin-right: 5px;
            margin-bottom: 5px;
            text-transform: lowercase;
        }


        .product-card-bottom {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            margin-top: 15px;
            padding: 15px 20px 0 20px;
            border-top: 1px dashed #eee;
        }

        .product-card-bottom .price {
            font-size: 1.6em;
            font-weight: 700;
            color: #007bff;
            margin-bottom: 15px;
            align-self: flex-end;
        }

        .product-card-bottom .add-to-cart-btn {
            background-color: #007bff;
            color: white;
            border: none;
            border-radius: 8px;
            padding: 12px 20px;
            cursor: pointer;
            font-size: 1em;
            transition: background-color 0.3s ease, transform 0.2s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            width: 100%;
            font-weight: 600;
        }

        .product-card-bottom .add-to-cart-btn:disabled {
            background-color: #ccc;
            cursor: not-allowed;
            transform: translateY(0);
            box-shadow: none;
        }

        .product-card-bottom .add-to-cart-btn:hover:not(:disabled) {
            background-color: #0056b3;
            transform: translateY(-2px);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        /* SECTION: Cerita Kami (Consistent with latest design) */
        .about-section {
            padding: 80px 50px;
            text-align: center;
            max-width: 1200px;
            margin: 0 auto;
        }

        .about-section h2 {
            font-size: 3em;
            margin-bottom: 10px;
            color: #333;
            font-weight: 700;
        }

        .about-section .tagline {
            font-size: 1.2em;
            color: #777;
            margin-bottom: 50px;
        }

        .about-content {
            display: flex;
            gap: 50px;
            align-items: center;
            margin-bottom: 50px;
        }

        .about-image-card {
            flex: 1;
            position: relative;
            background-color: #fff;
            border-radius: 15px;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.15);
            overflow: hidden;
            max-width: 550px;
            min-height: 400px;
            height: auto;
        }

        .about-image-card img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
            border-radius: 15px;
        }

        .quote-overlay {
            position: absolute;
            bottom: 20px;
            left: 20px;
            right: 20px;
            background-color: #007bff;
            color: white;
            padding: 15px 20px;
            border-radius: 10px;
            font-size: 1.1em;
            line-height: 1.4;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.2);
            text-align: left;
        }

        .quote-overlay::before {
            content: "\f10d";
            font-family: "Font Awesome 6 Free";
            font-weight: 900;
            position: absolute;
            top: -15px;
            left: 20px;
            font-size: 2em;
            color: rgba(255, 255, 255, 0.3);
            z-index: 1;
        }

        .quote-overlay span {
            position: relative;
            z-index: 2;
        }


        .about-text-content {
            flex: 1;
            text-align: left;
            font-size: 1.1em;
            color: #555;
        }

        .about-text-content p:last-child {
            margin-bottom: 0;
        }

        .about-text-content strong {
            font-size: 1.2em;
            color: #212529;
            display: block;
            margin-bottom: 10px;
            font-weight: 600;
        }

        .vision-mission-cards {
            display: flex;
            gap: 20px;
            justify-content: center;
        }

        .vm-card {
            flex: 1;
            background-color: #fff;
            padding: 25px;
            /* Padding lebih besar */
            border-radius: 15px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            text-align: left;
            max-width: 48%;
        }

        .vm-card .icon-wrapper {
            width: 50px;
            /* Ukuran ikon lebih besar */
            height: 50px;
            border-radius: 50%;
            display: flex;
            justify-content: center;
            align-items: center;
            margin-bottom: 15px;
            font-size: 1.8em;
            /* Ukuran ikon lebih besar */
        }

        .vm-card.vision .icon-wrapper {
            background-color: #007bff;
            color: white;
        }

        .vm-card.mission .icon-wrapper {
            background-color: #28a745;
            color: white;
        }

        .vm-card h3 {
            font-size: 1.6em;
            /* Ukuran judul lebih besar */
            margin: 0 0 10px 0;
            color: #333;
            font-weight: 600;
        }

        .vm-card p,
        .vm-card ul {
            font-size: 1em;
            /* Ukuran font sedikit lebih besar */
            color: #666;
            margin-bottom: 0;
        }

        .vm-card ul {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .vm-card ul li {
            display: flex;
            align-items: flex-start;
            gap: 8px;
            margin-bottom: 10px;
        }

        .vm-card ul li:last-child {
            margin-bottom: 0;
        }

        .vm-card ul li i {
            color: #28a745;
            font-size: 1em;
            margin-top: 4px;
        }


        /* SECTION: Hubungi Kami (Consistent with latest design) */
        .contact-section {
            padding: 80px 50px;
            text-align: center;
            max-width: 1200px;
            margin: 0 auto;
        }

        .contact-section h2 {
            font-size: 3em;
            margin-bottom: 10px;
            color: #333;
            font-weight: 700;
        }

        .contact-section .tagline {
            font-size: 1.2em;
            color: #777;
            margin-bottom: 50px;
        }

        .contact-buttons {
            display: flex;
            justify-content: center;
            gap: 15px;
            margin-bottom: 40px;
        }

        .contact-buttons .btn {
            background-color: #f0f0f0;
            color: #555;
            border: 1px solid #ddd;
            border-radius: 25px;
            padding: 12px 25px;
            font-size: 17px;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 500;
        }

        .contact-buttons .btn:hover {
            background-color: #e0e0e0;
            color: #333;
            border-color: #c0c0c0;
        }

        .contact-buttons .btn.active {
            background-color: #007bff;
            color: white;
            border-color: #007bff;
            box-shadow: 0 4px 10px rgba(0, 123, 255, 0.2);
        }

        .contact-content-area {
            background-color: #fff;
            padding: 40px;
            /* Padding lebih besar */
            border-radius: 15px;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.1);
            text-align: left;
        }

        /* Specific styles for "Kirim Pesan" layout */
        .contact-card-container {
            display: flex;
            gap: 40px;
            flex-wrap: wrap;
        }

        .contact-card-left {
            flex: 1;
            min-width: 300px;
        }

        .contact-card-left h3 {
            font-size: 2em;
            /* Lebih besar */
            margin: 0 0 15px 0;
            color: #333;
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 600;
        }

        .contact-card-left h3 i {
            font-size: 1.5em;
            color: #007bff;
        }

        .contact-card-left p {
            font-size: 1.05em;
            /* Sedikit lebih besar */
            color: #666;
            margin-bottom: 25px;
        }

        .contact-card-left .contact-quick-info {
            border-left: 4px solid #007bff;
            /* Border lebih tebal */
            padding-left: 20px;
            /* Padding lebih besar */
            margin-top: 30px;
        }

        .contact-card-left .contact-quick-info div {
            display: flex;
            align-items: center;
            gap: 12px;
            /* Jarak lebih besar */
            margin-bottom: 12px;
            /* Jarak antar item */
            color: #007bff;
            font-weight: 600;
            /* Lebih tebal */
            font-size: 1.05em;
            /* Lebih besar */
        }

        .contact-card-left .contact-quick-info div i {
            font-size: 1.2em;
        }

        .contact-card-left .contact-quick-info div:last-child {
            margin-bottom: 0;
        }

        .contact-card-right {
            flex: 1;
            min-width: 300px;
        }

        .contact-card-right .form-group {
            margin-bottom: 25px;
        }

        .contact-card-right label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #444;
            font-size: 15px;
        }

        .contact-card-right input[type="text"],
        .contact-card-right input[type="email"],
        .contact-card-right textarea {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #ccc;
            border-radius: 8px;
            font-size: 16px;
            box-sizing: border-box;
            transition: border-color 0.3s ease, box-shadow 0.3s ease;
        }

        .contact-card-right input[type="text"]:focus,
        .contact-card-right input[type="email"]:focus,
        .contact-card-right textarea:focus {
            border-color: #007bff;
            box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.25);
            outline: none;
        }

        .contact-card-right textarea {
            min-height: 140px;
            resize: vertical;
        }

        .contact-card-right .btn-submit {
            background-color: #007bff;
            color: white;
            padding: 12px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 18px;
            width: 100%;
            transition: background-color 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .contact-card-right .btn-submit:hover {
            background-color: #0056b3;
        }

        /* START OF UPDATED CSS FOR "INFORMASI KONTAK" SECTION */

        /* Styles for "Informasi Kontak" tab content container */
        .contact-card-container#info-kontak {
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        /* Style for the "Informasi Kontak" title within its tab */
        .contact-card-container .info-kontak-title {
            font-size: 1.8em;
            margin: 0 0 40px 0;
            color: #333;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            font-weight: bold;
            width: 100%;
            text-align: center;
        }

        .contact-card-container .info-kontak-title i {
            font-size: 1.5em;
            color: #007bff;
        }

        /* Styles for the grid of contact information cards */
        .info-kontak-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 25px;
            width: 100%;
            max-width: 900px;
        }

        .info-kontak-card {
            background: #f9fafb;
            border-radius: 14px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.06);
            display: flex;
            align-items: center;
            padding: 22px 24px;
            gap: 18px;
            font-size: 1.08em;
        }

        .info-kontak-card .icon {
            background: #1976d2;
            color: #fff;
            border-radius: 50%;
            width: 48px;
            height: 48px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.7em;
            flex-shrink: 0;
        }

        .info-kontak-card .label {
            font-weight: bold;
            color: #222;
            margin-bottom: 2px;
        }

        .info-kontak-card .value {
            color: #222;
            font-size: 1em;
            word-break: break-all;
        }

        /* Styling khusus untuk kartu "Jam Kerja" */
        .info-kontak-card.jam-kerja {
            grid-column: 1 / -1;
        }

        .info-kontak-card.jam-kerja .value {
            line-height: 1.5;
        }

        /* Styling untuk teks "Minggu & Libur Nasional tutup" di kartu Jam Kerja */
        .info-kontak-card.jam-kerja .value span {
            display: flex;
            align-items: center;
            gap: 5px;
            margin-top: 5px;
        }

        /* END OF UPDATED CSS FOR "INFORMASI KONTAK" SECTION */


        /* Profile Completion Popup (Consistent with latest design) */
        .profile-popup {
            position: fixed;
            top: 80px;
            right: 20px;
            background-color: #ffe0b2;
            color: #e65100;
            padding: 15px 25px;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
            z-index: 1050;
            display: flex;
            align-items: center;
            gap: 15px;
            animation: fadeInOut 0.5s ease-out forwards;
            max-width: 350px;
            opacity: 0;
            transform: translateY(-20px);
        }

        .profile-popup.show {
            opacity: 1;
            transform: translateY(0);
        }

        @keyframes fadeInOut {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .profile-popup p {
            margin: 0;
            font-size: 1em;
            line-height: 1.4;
        }

        .profile-popup p a {
            color: #007bff;
            font-weight: bold;
            text-decoration: underline;
        }

        .profile-popup p a:hover {
            color: #0056b3;
        }

        .profile-popup .close-popup-btn {
            background: none;
            border: none;
            font-size: 20px;
            color: #e65100;
            cursor: pointer;
            padding: 0;
            line-height: 1;
            margin-left: auto;
        }

        .profile-popup .close-popup-btn:hover {
            color: #a13a00;
        }

        /* UPDATED FOOTER STYLES (Copied from profile.php) */
        footer {
            background-color: #2c3e50;
            /* Dark color consistent with your design */
            color: #ecf0f1;
            /* Light text for contrast */
            padding: 20px 20px;
            /* Reduced padding for a smaller look */
            text-align: center;
            font-size: 0.9em;
            /* Slightly smaller font size for copyright */
            box-shadow: 0 -4px 15px rgba(0, 0, 0, 0.1);
            /* Subtle top shadow */
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: 80px;
            /* Reduced minimum height */
            margin-top: 50px;
            /* Keep margin from main content */
        }

        footer p {
            margin: 0;
            font-weight: 500;
            letter-spacing: 0.5px;
        }

        .footer-social {
            margin-top: 15px;
            /* Reduced margin top for social icons */
            display: flex;
            gap: 15px;
            /* Reduced gap between icons */
        }

        .footer-social a {
            color: #ecf0f1;
            font-size: 20px;
            /* Slightly smaller social icons */
            transition: color 0.3s ease, transform 0.2s ease;
        }

        .footer-social a:hover {
            color: #007bff;
            transform: translateY(-2px);
            /* Subtle hover effect */
        }

        /* Responsive Adjustments (Combined and refined) */
        @media (max-width: 992px) {
            header {
                padding: 15px 30px;
            }

            .nav-links {
                gap: 25px;
            }

            .hero-section,
            .about-content {
                flex-direction: column;
                padding: 40px 30px;
                gap: 30px;
            }

            .hero-content-left,
            .about-text-content {
                padding-right: 0;
                text-align: center;
            }

            .hero-content-left h1 {
                font-size: 2.8em;
                text-align: center;
            }

            .hero-buttons {
                justify-content: center;
            }

            .hero-stats {
                flex-wrap: wrap;
                justify-content: center;
                gap: 15px;
            }

            .stat-card {
                flex: none;
                width: calc(50% - 10px);
            }

            .hero-image-right,
            .about-image-card {
                width: 100%;
                max-width: 500px;
            }

            .trending-badge {
                margin: 0 auto 20px auto;
            }

            .product-card {
                height: auto;
            }

            .catalog-section,
            .about-section,
            .contact-section {
                padding: 60px 30px;
            }

            .contact-card-container {
                flex-direction: column;
                gap: 30px;
            }

            .contact-card-left,
            .contact-card-right {
                min-width: unset;
                width: 100%;
            }

            .contact-buttons {
                flex-wrap: wrap;
                gap: 10px;
            }

            .contact-buttons .btn {
                flex-grow: 1;
                max-width: calc(50% - 5px);
            }

            .info-kontak-grid {
                grid-template-columns: 1fr;
            }

            .info-kontak-card.full-width-card {
                grid-column: auto;
            }

            footer {
                padding: 15px 15px;
                /* Further reduce padding on medium screens */
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

            .hero-content-left h1 {
                font-size: 2.2em;
            }

            .hero-buttons {
                flex-direction: column;
                align-items: center;
            }

            .hero-buttons .btn-primary,
            .hero-buttons .btn-outline {
                width: 100%;
                max-width: 300px;
            }

            .stat-card {
                width: 100%;
            }

            .catalog-section h2,
            .about-section h2,
            .contact-section h2 {
                font-size: 2em;
            }

            .cart-sidebar {
                width: 100%;
                right: -100%;
            }

            /* Cart sidebar on mobile */
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


            .profile-popup {
                top: auto;
                bottom: 20px;
                left: 20px;
                right: 20px;
                max-width: calc(100% - 40px);
                flex-direction: column;
                text-align: center;
            }

            .profile-popup .close-popup-btn {
                position: absolute;
                top: 5px;
                right: 5px;
                margin-left: 0;
            }

            .profile-popup p {
                width: 100%;
            }

            footer {
                padding: 15px 15px;
                min-height: 70px;
                /* Even smaller min-height on mobile */
            }

            footer p {
                font-size: 0.8em;
                /* Even smaller font on mobile */
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

            .product-cards-container {
                grid-template-columns: 1fr;
            }

            .product-card img {
                height: 250px;
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
                <li><a href="dashboard.php" class="active"><i class="fas fa-home"></i> Beranda</a></li>
                <li><a href="#katalog"><i class="fas fa-shirt"></i> Katalog</a></li>
                <li><a href="#cerita-kami"><i class="fas fa-info-circle"></i> Cerita Kami</a></li>
                <li><a href="#hubungi-kami"><i class="fas fa-phone"></i> Hubungi Kami</a></li>
                <li><a href="transaction_history.php"><i class="fas fa-receipt"></i> Riwayat Transaksi</a></li>
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
        <?php if ($profile_incomplete): ?>
            <div id="profileCompletionPopup" class="profile-popup">
                <p>⚠️ Profil Anda belum lengkap! <a href="profile.php">Lengkapi sekarang</a> untuk pengalaman belanja terbaik.</p>
                <button class="close-popup-btn">×</button>
            </div>
        <?php endif; ?>
        <section class="hero-section">
            <div class="hero-content-left">
                <div class="trending-badge">
                    <i class="fas fa-chart-line"></i> Trending Fashion
                </div>
                <h1>Desain Kekinian Kualitas Impian</h1>
                <p>Pusat Fashion Kekinian untuk Gaya Tanpa Batas.</p>
                <span class="highlighted-text">Lihat Apa yang Sedang Tren Pada Katalog Keren Ini!</span>
                <div class="hero-buttons">
                    <a href="#katalog" class="btn-primary"><i class="fas fa-bag-shopping"></i> Lihat Koleksi</a>
                    <a href="#cerita-kami" class="btn-outline"><i class="fas fa-info-circle"></i> Kenalan dulu sama brand-nya</a>
                </div>
                <div class="hero-stats">
                    <div class="stat-card">
                        <i class="fas fa-cube"></i>
                        <div class="value">1.000+</div>
                        <div class="label">Produk Terjual</div>
                    </div>
                    <div class="stat-card">
                        <i class="fas fa-user-check"></i>
                        <div class="value">800+</div>
                        <div class="label">Pelanggan Puas</div>
                    </div>
                    <div class="stat-card">
                        <i class="fas fa-star icon-star"></i>
                        <div class="value">4.9</div>
                        <div class="label">Rating</div>
                    </div>
                </div>
            </div>
            <div class="hero-image-right">
                <img src="Asset/Bannerr.png" alt="Fashion Banner">
            </div>
        </section>
        <section id="katalog" class="catalog-section">
            <h2>Koleksi Katalog Terbaru</h2>
            <p class="subtitle">Temukan style kaos yang cocok denganmu.</p>

            <div class="product-cards-container">
                <?php if (!empty($products)): ?>
                    <?php foreach ($products as $product): ?>
                        <div class="product-card">
                            <?php
                            $status_badge_text = '';
                            $status_badge_class = '';

                            if ($product['is_available']) {
                                $status_badge_text = 'Tersedia';
                                $status_badge_class = 'available'; // Green
                            } else {
                                $status_badge_text = 'Stok Habis';
                                $status_badge_class = 'out-of-stock'; // Red
                            }
                            ?>
                            <div class="product-badge status <?php echo htmlspecialchars($status_badge_class); ?>">
                                <?php echo htmlspecialchars($status_badge_text); ?>
                            </div>

                            <div class="product-card-image-wrapper">
                                <img src="<?php echo htmlspecialchars($product['image_path']); ?>" alt="<?php echo htmlspecialchars($product['name']); ?>">
                            </div>
                            <div class="product-card-info">
                                <div>
                                    <span class="brand-name"><?php echo !empty($product['brand']) ? htmlspecialchars($product['brand']) : 'Brand Tidak Diketahui'; ?></span>
                                    <h3><?php echo htmlspecialchars($product['name']); ?></h3>
                                    <p class="product-description"><?php echo !empty($product['description']) ? htmlspecialchars($product['description']) : 'Deskripsi tidak tersedia.'; ?></p>
                                    <div class="product-tags">
                                        <?php
                                        if (!empty($product['tags'])) {
                                            $tags_array = explode(',', $product['tags']);
                                            foreach ($tags_array as $tag) {
                                                echo '<span class="tag">' . htmlspecialchars(trim($tag)) . '</span>';
                                            }
                                        }
                                        ?>
                                    </div>
                                </div>
                                <div class="product-card-bottom">
                                    <span class="price">Rp <?php echo number_format($product['price'], 0, ',', '.'); ?></span>
                                    <button class="add-to-cart-btn" data-product-id="<?php echo $product['id']; ?>"
                                        <?php echo ($product['is_available'] ? '' : 'disabled'); /* Disable button if not available */ ?>>
                                        <i class="fas fa-shopping-bag"></i> Tambah ke Keranjang
                                    </button>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p style="text-align: center; width: 100%;">Belum ada produk yang tersedia saat ini.</p>
                <?php endif; ?>
            </div>
        </section>
        <section id="cerita-kami" class="about-section">
            <h2>Cerita Kami</h2>
            <p class="tagline">Gaya itu bukan tren — tapi sikap.</p>

            <div class="about-content">
                <div class="about-image-card">
                    <img src="Asset/Kaos.jpeg" alt="Fashion Model">
                    <div class="quote-overlay">
                        <span>Gak harus ngikutin tren. Jadi diri sendiri aja, itu udah cukup keren.</span>
                    </div>
                </div>
                <div class="about-text-content">
                    <p><strong style="font-size: 1.2em; color: #212529;">THANKSINSOMNIA</strong></p>
                    <p>Lupakan batasan, rengkuh keunikanmu. THANKSINSOMNIA hadir sebagai destinasi utama bagi mereka yang mencari lebih dari sekadar pakaian. Kami percaya, fashion adalah cara terkuatmu berbicara tanpa suara. Setiap koleksi kami adalah perayaan gaya yang autentik, didesain dengan hati, dan diproduksi dengan bangga melalui kolaborasi dengan talenta-talenta terbaik di industri lokal. Inilah saatnya untuk tidak hanya mengenakan busana, tetapi juga mengenakan sebuah keyakinan. Jadikan setiap penampilanmu sebuah cerita, karena kami ada untuk memastikan Anda tampil jujur, bukan cuma ikut-ikutan.</p>
                </div>
            </div>

            <div class="vision-mission-cards">
                <div class="vm-card vision">
                    <div class="icon-wrapper"><i class="fas fa-lightbulb"></i></div>
                    <h3>Visi Kami</h3>
                    <p>Bantu lo nemuin versi paling jujur dari gaya lo.</p>
                </div>
                <div class="vm-card mission">
                    <div class="icon-wrapper"><i class="fas fa-bullseye"></i></div>
                    <h3>Misi Kami</h3>
                    <ul>
                        <li><i class="fas fa-check-circle"></i> Desain real, bukan pasaran.</li>
                        <li><i class="fas fa-check-circle"></i> Kolaborasi dengan kreator lokal.</li>
                        <li><i class="fas fa-check-circle"></i> Bikin lo nyaman jadi diri sendiri.</li>
                    </ul>
                </div>
            </div>
        </section>
        <section id="hubungi-kami" class="contact-section">
            <h2>Hubungi Kami</h2>
            <p class="tagline">Punya pertanyaan? Mau kolaborasi? Atau sekadar say hi? Kami selalu buka DM dan email.</p>

            <div class="contact-buttons">
                <button class="btn active" data-target="kirim-pesan"><i class="fas fa-paper-plane"></i> Kirim Pesan</button>
                <button class="btn" data-target="info-kontak"><i class="fas fa-info-circle"></i> Info Kontak</button>
            </div>

            <div class="contact-content-area" id="contactContentArea">
                <div class="contact-card-container active-content" id="kirim-pesan">
                    <div class="contact-card-left">
                        <h3><i class="fas fa-comment-dots"></i> Kirim Pesan</h3>
                        <p>Atau kirim pesan langsung via form di bawah. Kami akan balas secepat mungkin (kecuali pas lagi ngopi).</p>
                        <div class="contact-quick-info">
                            <div>
                                <i class="fas fa-envelope"></i>
                                <a href="mailto:wearnity@gmail.com">wearnity@gmail.com</a>
                            </div>
                            <div>
                                <i class="fas fa-phone"></i>
                                <a href="tel:+6281234567890">+62 812-3456-7890</a>
                            </div>
                        </div>
                    </div>
                    <div class="contact-card-right">
                        <form action="#" method="POST">
                            <div class="form-group">
                                <label for="nama_lengkap">Nama Lengkap<span style="color: red;">*</span></label>
                                <input type="text" id="nama_lengkap" name="nama_lengkap" placeholder="Masukkan nama lo" required>
                            </div>
                            <div class="form-group">
                                <label for="email_kontak">Email<span style="color: red;">*</span></label>
                                <input type="email" id="email_kontak" name="email_kontak" placeholder="Alamat email aktif" required>
                            </div>
                            <div class="form-group">
                                <label for="subjek">Subjek<span style="color: red;">*</span></label>
                                <input type="text" id="subjek" name="subjek" placeholder="Judul pesan" required>
                            </div>
                            <div class="form-group">
                                <label for="pesan">Pesan<span style="color: red;">*</span></label>
                                <textarea id="pesan" name="pesan" placeholder="Tulis apa aja di sini..." required></textarea>
                            </div>
                            <button type="submit" class="btn-submit"><i class="fas fa-paper-plane"></i> Kirim Pesan</button>
                        </form>
                    </div>
                </div>

                <div class="contact-card-container" id="info-kontak" style="display: none;">
                    <h3 class="info-kontak-title"><i class="fas fa-info-circle"></i> Informasi Kontak</h3>
                    <div class="info-kontak-grid">
                        <div class="info-kontak-card">
                            <div class="icon"><i class="fas fa-envelope"></i></div>
                            <div>
                                <div class="label">Email</div>
                                <div class="value">wearnity@gmail.com</div>
                            </div>
                        </div>
                        <div class="info-kontak-card">
                            <div class="icon"><i class="fab fa-whatsapp"></i></div>
                            <div>
                                <div class="label">WhatsApp</div>
                                <div class="value">+62 812-3456-7890</div>
                            </div>
                        </div>
                        <div class="info-kontak-card">
                            <div class="icon"><i class="fab fa-instagram"></i></div>
                            <div>
                                <div class="label">Instagram</div>
                                <div class="value">@wearnitybyTHNKS</div>
                            </div>
                        </div>
                        <div class="info-kontak-card">
                            <div class="icon"><i class="fas fa-map-marker-alt"></i></div>
                            <div>
                                <div class="label">Alamat</div>
                                <div class="value">Jl. Gaya Lokal No. 123, Medan, Sumatera Utara</div>
                            </div>
                        </div>
                        <div class="info-kontak-card jam-kerja">
                            <div class="icon"><i class="fas fa-clock"></i></div>
                            <div>
                                <div class="label">Jam Kerja</div>
                                <div class="value">Senin - Sabtu: 09.00 - 18.00 WIB<br>
                                    <span style="font-size:0.95em;color:#888;"><i class="far fa-calendar-times"></i> Minggu & Libur Nasional tutup</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>
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

        // Script untuk menandai link navigasi yang sedang aktif
        document.addEventListener('DOMContentLoaded', function() {
            const currentPath = window.location.pathname.split('/').pop();
            const navLinks = document.querySelectorAll('.nav-links li a');

            navLinks.forEach(link => {
                const linkHref = link.getAttribute('href');
                const linkPath = linkHref ? linkHref.split('/').pop().split('#')[0] : '';

                if (currentPath === '' || currentPath === 'dashboard.php') {
                    if (linkHref === 'dashboard.php' || linkHref === '#katalog' || linkHref === '#cerita-kami' || linkHref === '#hubungi-kami') { // Adjusted for internal links on dashboard
                        if (linkPath === currentPath || linkHref.startsWith('#')) {
                            link.classList.add('active');
                        }
                    }
                } else if (linkPath === currentPath) {
                    link.classList.add('active');
                }
            });


            // --- Shopping Cart Logic ---
            const cartSidebar = document.getElementById('cartSidebar');
            const cartOverlay = document.getElementById('cartOverlay');
            const cartIcon = document.getElementById('cartIcon');
            const cartContentContainer = document.getElementById('cartSidebar'); // Sama dengan cartSidebar, ini OK

            if (cartIcon && cartSidebar && cartOverlay) {
                cartIcon.addEventListener('click', function(event) {
                    event.preventDefault();
                    toggleCartSidebar();
                });
            }

            // Fungsi utama untuk membuka/menutup sidebar keranjang dan memuat konten
            function toggleCartSidebar() {
                if (cartSidebar && cartOverlay) {
                    cartSidebar.classList.toggle('open');
                    cartOverlay.style.display = cartSidebar.classList.contains('open') ? 'block' : 'none';
                    // Mencegah scroll body saat sidebar terbuka
                    document.body.style.overflow = cartSidebar.classList.contains('open') ? 'hidden' : '';
                    if (cartSidebar.classList.contains('open')) {
                        loadCartContent(); // Muat ulang konten keranjang saat dibuka
                    }
                } else {
                    console.warn("Elemen keranjang (cartSidebar/cartOverlay) tidak ditemukan. Redirect ke halaman keranjang.");
                    window.location.href = 'cart.php'; // Fallback ke halaman keranjang khusus
                }
            }

            // Fungsi untuk memuat konten keranjang via AJAX (mengembalikan HTML)
            function loadCartContent() {
                if (!cartContentContainer) return;
                fetch('_cart_content.php')
                    .then(response => {
                        if (!response.ok) {
                            // Jika respons HTTP bukan 2xx, baca sebagai teks dan lempar error
                            return response.text().then(text => {
                                throw new Error('Network response for cart content was not ok. Status: ' + response.status + ', Response: ' + text);
                            });
                        }
                        return response.text(); // MENGHARAPKAN HTML, BUKAN JSON
                    })
                    .then(html => {
                        cartContentContainer.innerHTML = html; // Langsung masukkan HTML ke container
                        attachCartItemListeners(); // Pasang kembali event listener pada elemen baru setelah konten dimuat
                    })
                    .catch(error => {
                        console.error('Error loading cart content:', error);
                        cartContentContainer.innerHTML = '<p class="empty-cart-message" style="color: red;">Gagal memuat keranjang. Silakan coba lagi. Detail: ' + error.message + '</p>';
                    });
            }

            // Fungsi untuk menambah produk ke keranjang (dipanggil dari tombol "Tambah ke Keranjang")
            function addToCart(productId, quantity = 1) {
                fetch('cart_actions.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded'
                        },
                        body: `action=add&product_id=${productId}&quantity=${quantity}`
                    })
                    .then(response => {
                        if (!response.ok) {
                            // Jika respons HTTP bukan 2xx, baca sebagai teks dan lempar error
                            return response.text().then(text => {
                                throw new Error('Network response for add to cart was not ok. Status: ' + response.status + ', Response: ' + text);
                            });
                        }
                        return response.json(); // cart_actions.php diharapkan mengembalikan JSON
                    })
                    .then(data => {
                        if (data.success) {
                            alert(data.message); // Notifikasi sukses ke user
                            toggleCartSidebar(); // Buka keranjang setelah menambah, ini akan memicu loadCartContent()
                        } else {
                            alert('Gagal menambah produk: ' + data.message); // Notifikasi gagal dari server
                            console.error('Server reported failure (add to cart):', data.message);
                        }
                    })
                    .catch(error => {
                        console.error('Error adding to cart:', error); // Log error (network issues, JSON parsing error)
                        alert('Terjadi kesalahan saat menambah produk. Silakan cek konsol browser untuk detail.');
                    });
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
                    .then(response => {
                        if (!response.ok) {
                            return response.text().then(text => {
                                throw new Error('Network response for update quantity was not ok. Status: ' + response.status + ', Response: ' + text);
                            });
                        }
                        return response.json();
                    })
                    .then(data => {
                        if (data.success) {
                            loadCartContent(); // Panggil loadCartContent() untuk memperbarui sidebar
                        } else {
                            alert('Gagal memperbarui kuantitas: ' + data.message);
                            console.error('Server reported failure (update quantity):', data.message);
                        }
                    })
                    .catch(error => {
                        console.error('Error updating quantity:', error);
                        alert('Terjadi kesalahan saat memperbarui kuantitas. Silakan cek konsol browser.');
                    });
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
                    .then(response => {
                        if (!response.ok) {
                            return response.text().then(text => {
                                throw new Error('Network response for remove item was not ok. Status: ' + response.status + ', Response: ' + text);
                            });
                        }
                        return response.json();
                    })
                    .then(data => {
                        if (data.success) {
                            loadCartContent(); // Panggil loadCartContent() untuk memperbarui sidebar
                        } else {
                            alert('Gagal menghapus produk: ' + data.message);
                            console.error('Server reported failure (remove item):', data.message);
                        }
                    })
                    .catch(error => {
                        console.error('Error removing from cart:', error);
                        alert('Terjadi kesalahan saat menghapus produk. Silakan cek konsol browser.');
                    });
            }

            // Event listener untuk tombol 'add-to-cart-btn' di katalog (dipasang saat DOMContentLoaded)
            document.querySelectorAll('.product-card-bottom .add-to-cart-btn').forEach(button => {
                button.addEventListener('click', function() {
                    const productId = this.dataset.productId;
                    addToCart(productId);
                });
            });

            // Fungsi ini akan dipanggil setelah konten keranjang dimuat ulang oleh AJAX
            // Fungsi ini memasang event listener pada elemen-elemen di dalam sidebar keranjang
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

                // Pasang event listener untuk tombol kuantitas (+/-)
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

                // Pasang event listener untuk tombol hapus item
                document.querySelectorAll('#cartSidebar .remove-item-btn').forEach(button => {
                    button.onclick = function() {
                        if (confirm('Apakah Anda yakin ingin menghapus produk ini dari keranjang?')) {
                            const productId = this.closest('.cart-item').dataset.productId;
                            removeFromCart(productId);
                        }
                    };
                });

                // Event listener untuk tombol Checkout di dalam sidebar keranjang
                const checkoutBtn = document.getElementById('checkoutBtn');
                if (checkoutBtn) { // Pastikan elemen ditemukan sebelum menambahkan event listener
                    checkoutBtn.addEventListener('click', function(event) {
                        event.preventDefault(); // Penting: Mencegah perilaku default dari tag <a> (navigasi langsung)
                        window.location.href = 'order_details.php'; // Arahkan ke halaman order_details.php
                    });
                }
            }

            // Logic for Contact Section tabs (if applicable on dashboard)
            const contactButtons = document.querySelectorAll('.contact-buttons .btn');
            const contactContentContainers = document.querySelectorAll('.contact-card-container');

            if (contactButtons.length > 0 && contactContentContainers.length > 0) {
                function showContactTab(targetId) {
                    contactButtons.forEach(btn => btn.classList.remove('active'));
                    contactContentContainers.forEach(container => container.style.display = 'none');

                    const targetElement = document.getElementById(targetId);
                    const activeBtn = document.querySelector(`.contact-buttons .btn[data-target="${targetId}"]`);
                    if (activeBtn) {
                        activeBtn.classList.add('active');
                    }

                    if (targetElement) {
                        if (targetId === 'kirim-pesan') {
                            targetElement.style.display = 'flex';
                            targetElement.style.flexDirection = 'row';
                        } else if (targetId === 'info-kontak' || targetId === 'lokasi') {
                            targetElement.style.display = 'flex';
                            targetElement.style.flexDirection = 'column';
                            targetElement.style.alignItems = 'center';
                        }
                    }
                }

                contactButtons.forEach(button => {
                    button.addEventListener('click', () => {
                        const targetId = button.dataset.target;
                        showContactTab(targetId);
                    });
                });

                showContactTab('kirim-pesan'); // Set default tab saat halaman dimuat
            }


            // Logic for Profile Completion Popup
            const profileCompletionPopup = document.getElementById('profileCompletionPopup');
            if (profileCompletionPopup) {
                setTimeout(() => {
                    profileCompletionPopup.classList.add('show');
                }, 500);

                const closePopupBtn = profileCompletionPopup.querySelector('.close-popup-btn');
                closePopupBtn.addEventListener('click', () => {
                    profileCompletionPopup.style.display = 'none';
                });
            }
        });
    </script>
</body>

</html>