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
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Outfit:wght@700;800&display=swap" rel="stylesheet">
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

        html {
            scroll-behavior: smooth;
        }

        body {
            font-family: var(--font-body);
            background-color: var(--background-color);
            color: var(--text-primary);
            line-height: 1.6;
            padding-top: 80px;
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

        ul {
            list-style: none;
        }

        img {
            max-width: 100%;
            display: block;
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

        /* Hero Section */
        .hero-section {
            min-height: 75vh;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 80px 7% 40px;
            gap: 40px;
            background: radial-gradient(circle at 80% 20%, rgba(99, 102, 241, 0.06) 0%, transparent 40%),
                        radial-gradient(circle at 10% 80%, rgba(245, 158, 11, 0.04) 0%, transparent 30%);
        }

        .hero-content-left {
            flex: 1.2;
            max-width: 650px;
        }

        .trending-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(99, 102, 241, 0.1);
            color: var(--secondary-color);
            padding: 8px 16px;
            border-radius: 30px;
            font-size: 12px;
            font-weight: 800;
            margin-bottom: 16px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .hero-content-left h1 {
            font-size: 3.2rem;
            font-weight: 800;
            line-height: 1.1;
            margin-bottom: 12px;
            background: linear-gradient(135deg, var(--primary-color) 0%, #334155 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            letter-spacing: -1.5px;
        }

        .hero-content-left p {
            font-size: 1.1rem;
            color: var(--text-secondary);
            margin-bottom: 20px;
            font-weight: 400;
        }

        .highlighted-text {
            display: block;
            font-weight: 600;
            color: var(--secondary-color);
            margin-bottom: 20px;
            font-size: 1rem;
        }

        .hero-buttons {
            display: flex;
            gap: 20px;
            margin-bottom: 40px;
        }

        .btn-primary, .btn-submit, .checkout-btn {
            background: var(--primary-color);
            color: white;
            padding: 16px 36px;
            border-radius: 100px;
            font-weight: 600;
            border: none;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
            display: inline-flex;
            align-items: center;
            gap: 12px;
            font-size: 15px;
        }

        .btn-primary:hover, .btn-submit:hover {
            background: var(--secondary-color);
            box-shadow: 0 10px 20px rgba(99, 102, 241, 0.25);
            transform: translateY(-4px);
        }

        .btn-outline {
            background: transparent;
            border: 2px solid var(--border-color);
            color: var(--primary-color);
            padding: 16px 36px;
            border-radius: 100px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 12px;
            font-size: 15px;
            transition: all 0.3s;
        }

        .btn-outline:hover {
            border-color: var(--primary-color);
            background: rgba(15, 23, 42, 0.04);
            transform: translateY(-2px);
        }

        .hero-image-right {
            flex: 0.8;
            position: relative;
        }

        .hero-image-right img {
            width: 100%;
            border-radius: 30px;
            box-shadow: var(--shadow-xl);
            transition: all 0.7s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .hero-image-right:hover img {
            transform: scale(1.03) rotate(-1deg);
        }

        /* Stats */
        .hero-stats {
            display: flex;
            gap: 40px;
            padding-top: 30px;
            margin-top: 10px;
        }

        .stat-card {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .stat-icon {
            width: 45px;
            height: 45px;
            background: rgba(15, 23, 42, 0.05);
            color: var(--primary-color);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            transition: all 0.3s;
        }

        .stat-card:hover .stat-icon {
            background: var(--primary-color);
            color: white;
            transform: translateY(-3px);
        }

        .stat-info {
            display: flex;
            flex-direction: column;
        }

        .stat-card .value {
            font-size: 1.5rem;
            font-weight: 800;
            color: var(--primary-color);
            line-height: 1.1;
            letter-spacing: -0.5px;
        }

        .stat-card .label {
            font-size: 0.7rem;
            color: var(--text-secondary);
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Catalog Section */
        .catalog-section {
            padding: 80px 7%;
            background: var(--surface-color);
        }

        .catalog-section h2 {
            font-size: 2.2rem;
            text-align: center;
            margin-bottom: 12px;
            letter-spacing: -1px;
        }

        .subtitle {
            text-align: center;
            color: var(--text-secondary);
            margin-bottom: 50px;
            font-size: 1.2rem;
        }

        .product-cards-container {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 40px;
        }

        .product-card {
            background: var(--surface-color);
            border-radius: var(--radius-xl);
            overflow: hidden;
            transition: all 0.5s cubic-bezier(0.16, 1, 0.3, 1);
            position: relative;
            display: flex;
            flex-direction: column;
            border: 1px solid var(--border-color);
        }

        .product-card:hover {
            transform: translateY(-15px);
            box-shadow: var(--shadow-xl);
            border-color: rgba(99, 102, 241, 0.2);
        }

        .product-card-image-wrapper {
            position: relative;
            padding-top: 130%;
            overflow: hidden;
            background: #f1f5f9;
        }

        .product-card-image-wrapper img {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 1s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .product-card:hover img {
            transform: scale(1.12);
        }

        .product-badge {
            position: absolute;
            top: 20px;
            right: 20px;
            padding: 6px 14px;
            border-radius: 30px;
            font-size: 0.7rem;
            font-weight: 800;
            z-index: 10;
            text-transform: uppercase;
            letter-spacing: 0.8px;
        }

        .product-badge.available { background: #dcfce7; color: #166534; }
        .product-badge.out-of-stock { background: #fee2e2; color: #991b1b; }

        .product-card-info {
            padding: 28px;
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        .brand-name {
            font-size: 0.7rem;
            text-transform: uppercase;
            color: var(--secondary-color);
            font-weight: 800;
            letter-spacing: 1.5px;
            margin-bottom: 10px;
            display: block;
        }

        .product-card-info h3 {
            font-size: 1.5rem;
            margin-bottom: 12px;
            color: var(--primary-color);
            font-weight: 800;
            letter-spacing: -0.5px;
        }

        .product-description {
            font-size: 0.95rem;
            color: var(--text-secondary);
            line-height: 1.6;
            margin-bottom: 24px;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .product-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 24px;
        }

        .tag {
            background: var(--background-color);
            padding: 5px 12px;
            border-radius: 8px;
            font-size: 11px;
            font-weight: 700;
            color: var(--text-secondary);
            border: 1px solid var(--border-color);
        }

        .product-card-bottom {
            margin-top: auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-top: 20px;
            border-top: 1px solid var(--border-color);
        }

        .price {
            font-size: 1.6rem;
            font-weight: 800;
            color: var(--primary-color);
        }

        .add-to-cart-btn {
            width: 48px;
            height: 48px;
            border-radius: 14px;
            background: var(--primary-color);
            color: white;
            border: none;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s;
            font-size: 20px;
        }

        .add-to-cart-btn:hover {
            background: var(--secondary-color);
            transform: rotate(10deg) scale(1.1);
        }

        /* About Section */
        .about-section {
            padding: 80px 7%;
            background: var(--background-color);
        }

        .about-section h2 { 
            font-size: 2.4rem; 
            text-align: center; 
            margin-bottom: 12px; 
            letter-spacing: -1px;
        }

        .about-section .tagline { 
            text-align: center; 
            color: var(--text-secondary); 
            margin-bottom: 50px; 
            font-size: 1.3rem; 
            font-weight: 500;
            max-width: 700px;
            margin-left: auto;
            margin-right: auto;
        }

        .about-content {
            display: flex;
            align-items: center;
            gap: 80px;
            margin-bottom: 80px;
        }

        .about-image-card { flex: 1.1; position: relative; }
        .about-image-card img { 
            border-radius: 40px; 
            box-shadow: var(--shadow-xl);
            width: 100%;
            height: auto;
        }

        .quote-overlay {
            position: absolute;
            bottom: -40px;
            right: -20px;
            background: var(--primary-color);
            color: white;
            padding: 40px;
            border-radius: 30px;
            max-width: 340px;
            font-weight: 600;
            font-size: 1.15rem;
            box-shadow: var(--shadow-xl);
            line-height: 1.5;
            border: 4px solid var(--background-color);
        }

        .about-text-content { flex: 1; }
        .about-text-content .brand-insight {
            color: var(--secondary-color);
            font-weight: 800;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 2px;
            margin-bottom: 15px;
            display: block;
        }
        
        .about-text-content h3 {
            font-size: 2.2rem;
            margin-bottom: 30px;
            line-height: 1.2;
            letter-spacing: -1px;
        }

        .about-text-content p { 
            font-size: 1.1rem; 
            color: var(--text-secondary); 
            margin-bottom: 30px; 
            line-height: 1.8; 
            text-align: justify;
        }

        .vision-mission-cards { display: grid; grid-template-columns: 1fr 1fr; gap: 40px; }
        .vm-card {
            background: var(--surface-color);
            padding: 50px;
            border-radius: 35px;
            border: 1px solid var(--border-color);
            transition: all 0.5s cubic-bezier(0.16, 1, 0.3, 1);
            position: relative;
            overflow: hidden;
        }

        .vm-card::after {
            content: '';
            position: absolute;
            top: 0; left: 0; width: 100%; height: 4px;
            background: var(--secondary-color);
            transform: scaleX(0);
            transition: transform 0.5s;
            transform-origin: left;
        }

        .vm-card:hover::after { transform: scaleX(1); }
        .vm-card:hover { transform: translateY(-10px); box-shadow: var(--shadow-xl); border-color: transparent; }
        
        .vm-card .icon-wrapper {
            width: 70px;
            height: 70px;
            background: rgba(99, 102, 241, 0.08);
            color: var(--secondary-color);
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            margin-bottom: 35px;
        }

        .vm-card h3 { font-size: 2rem; margin-bottom: 20px; letter-spacing: -0.5px; }
        .vm-card ul li { margin-bottom: 10px; display: flex; align-items: center; gap: 10px; color: var(--text-secondary); }
        .vm-card ul li i { color: var(--secondary-color); }

        /* Contact Section */
        .contact-section { padding: 80px 7%; background: var(--surface-color); }
        .contact-section h2 { font-size: 2.2rem; text-align: center; margin-bottom: 12px; letter-spacing: -1px; }
        .contact-section .tagline { text-align: center; color: var(--text-secondary); margin-bottom: 50px; font-size: 1.25rem; max-width: 800px; margin-left: auto; margin-right: auto; }
        
        .contact-content-area {
            background: var(--surface-color);
            border-radius: 30px;
            box-shadow: var(--shadow-xl);
            overflow: hidden;
            border: 1px solid var(--border-color);
            max-width: 1200px;
            margin: 0 auto;
            padding: 40px;
        }

        .contact-buttons {
            display: flex;
            justify-content: center;
            gap: 15px;
            margin-bottom: 30px;
        }

        .contact-buttons .btn {
            background: var(--background-color);
            color: var(--text-secondary);
            padding: 14px 32px;
            border-radius: 100px;
            border: 1px solid var(--border-color);
            cursor: pointer;
            font-weight: 700;
            font-size: 14px;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .contact-buttons .btn.active {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
            box-shadow: var(--shadow-md);
        }

        .contact-card-container {
            display: flex;
            gap: 60px;
            align-items: flex-start;
        }

        .contact-card-left {
            flex: 1;
        }

        .contact-card-left h3 {
            font-size: 2.2rem;
            margin-bottom: 20px;
            color: var(--primary-color);
            letter-spacing: -1px;
        }

        .contact-card-left p {
            font-size: 1.1rem;
            color: var(--text-secondary);
            margin-bottom: 40px;
            line-height: 1.6;
        }

        .contact-quick-info {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .contact-quick-info div {
            display: flex;
            align-items: center;
            gap: 15px;
            font-weight: 600;
            color: var(--text-primary);
        }

        .contact-quick-info i {
            width: 40px;
            height: 40px;
            background: rgba(99, 102, 241, 0.1);
            color: var(--secondary-color);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .contact-card-right {
            flex: 1.5;
            background: var(--background-color);
            padding: 40px;
            border-radius: 24px;
            border: 1px solid var(--border-color);
        }

        .form-group { margin-bottom: 25px; }
        .form-group label {
            display: block;
            margin-bottom: 10px;
            font-weight: 700;
            font-size: 0.9rem;
            color: var(--text-primary);
        }

        .form-group input, .form-group textarea {
            width: 100%;
            padding: 16px 20px;
            border: 2px solid var(--border-color);
            border-radius: 14px;
            background: white;
            font-family: var(--font-body);
            font-size: 0.95rem;
            transition: all 0.3s;
        }

        .form-group input:focus, .form-group textarea:focus {
            border-color: var(--secondary-color);
            outline: none;
            box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.1);
        }

        .info-kontak-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 25px;
            width: 100%;
        }

        .info-kontak-card {
            display: flex;
            align-items: center;
            gap: 20px;
            padding: 25px;
            background: var(--background-color);
            border-radius: 20px;
            border: 1px solid var(--border-color);
            transition: all 0.3s;
        }

        .info-kontak-card:hover {
            background: white;
            border-color: var(--secondary-color);
            transform: translateY(-5px);
            box-shadow: var(--shadow-md);
        }

        .info-kontak-card .icon {
            width: 50px;
            height: 50px;
            background: var(--primary-color);
            color: white;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }

        .info-kontak-card .label { font-size: 0.8rem; font-weight: 700; color: var(--text-secondary); text-transform: uppercase; margin-bottom: 4px; }
        .info-kontak-card .value { font-weight: 700; color: var(--text-primary); }

        /* Footer */
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

        @media (max-width: 1024px) {
            .footer-container {
                grid-template-columns: repeat(2, 1fr);
                gap: 40px;
            }
        }

        @media (max-width: 640px) {
            .footer-container {
                grid-template-columns: 1fr;
            }
            footer {
                padding-top: 50px;
                text-align: center;
            }
            .footer-social-mini, .footer-links li, .footer-contact-info li {
                justify-content: center;
            }
        }

        /* Responsive Improvements */
        @media (max-width: 1200px) {
            .hero-content-left h1 { font-size: 4rem; }
            .about-content { gap: 60px; }
        }

        @media (max-width: 1024px) {
            .hero-section { flex-direction: column; text-align: center; padding-top: 160px; }
            .hero-content-left { max-width: 100%; }
            .hero-buttons, .hero-stats { justify-content: center; }
            .about-content { flex-direction: column; }
            .about-image-card { order: 2; width: 80%; margin: 0 auto; }
            .quote-overlay { right: 0; bottom: -20px; }
            .vision-mission-cards { grid-template-columns: 1fr; }
            .contact-card-container { flex-direction: column; gap: 40px; }
            .contact-content-area { padding: 30px; }
            .info-kontak-grid { grid-template-columns: 1fr; }
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
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.12);
            border-radius: 35px;
            z-index: 2100;
            display: none;
            align-items: center;
            gap: 20px;
            animation: slideUpPopup 0.8s cubic-bezier(0.16, 1, 0.3, 1);
            border: 1px solid rgba(255, 255, 255, 0.4);
            border-left: 8px solid #f59e0b;
            min-width: 450px;
            max-width: 90vw;
        }

        @keyframes slideUpPopup {
            from { opacity: 0; transform: translateY(40px); }
            to { opacity: 1; transform: translateY(0); }
        }

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

        @keyframes slideInLeft {
            from { transform: translateX(-100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }

        .toast.fade-out {
            animation: fadeOut 0.5s forwards;
        }

        @keyframes fadeOut {
            to { opacity: 0; transform: translateY(10px); }
        }

        .popup-icon {
            color: #f59e0b; /* Uniform orange */
            font-size: 28px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .profile-popup.show {
            display: flex;
        }

        .profile-popup p {
            font-size: 14px;
            font-weight: 500;
            color: var(--text-primary);
        }

        .profile-popup a {
            color: var(--secondary-color);
            font-weight: 700;
            text-decoration: underline;
        }

        .close-popup-btn {
            background: #f1f5f9;
            border: none;
            width: 44px;
            height: 44px;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            color: #64748b;
            transition: all 0.3s;
            margin-left: auto;
        }

        .close-popup-btn:hover {
            background: #fee2e2;
            color: #ef4444;
        }

        @keyframes slideUp {
            from { opacity: 0; transform: translateY(20px); }
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
            width: 90px;
            height: 110px;
            object-fit: cover;
            border-radius: 16px;
            box-shadow: var(--shadow-sm);
        }

        .item-details {
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .item-name {
            font-weight: 700;
            font-size: 1.05rem;
            color: var(--primary-color);
        }

        .item-price {
            font-weight: 800;
            color: var(--secondary-color);
            font-size: 0.95rem;
        }

        .quantity-controls {
            display: flex;
            align-items: center;
            gap: 15px;
            background: #f8fafc;
            padding: 6px 12px;
            border-radius: 100px;
            width: fit-content;
            margin-top: 5px;
        }

        .qty-btn {
            background: white;
            border: 1px solid var(--border-color);
            width: 28px;
            height: 28px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            cursor: pointer;
            color: var(--text-primary);
            transition: all 0.2s;
        }

        .qty-btn:hover { background: var(--primary-color); color: white; border-color: var(--primary-color); }

        .item-quantity { font-weight: 700; font-size: 0.9rem; min-width: 20px; text-align: center; }

        .remove-item-btn {
            color: #cbd5e1;
            background: none;
            border: none;
            cursor: pointer;
            font-size: 1.1rem;
            transition: all 0.3s;
            padding: 5px;
        }

        .remove-item-btn:hover { color: #ef4444; }

        .cart-summary {
            padding: 30px;
            background: white;
            border-top: 1px solid rgba(0, 0, 0, 0.05);
            box-shadow: 0 -10px 30px rgba(0, 0, 0, 0.02);
        }

        .total-price {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
        }

        .total-price span:first-child { 
            color: var(--text-secondary); 
            font-weight: 600; 
            font-size: 1.1rem;
        }

        .total-price span:last-child { 
            color: var(--primary-color); 
            font-weight: 900; 
            font-size: 1.6rem;
            letter-spacing: -0.5px;
        }

        .checkout-btn {
            width: 100%;
            background: var(--primary-color);
            color: white;
            padding: 20px;
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            font-weight: 700;
            text-decoration: none;
            transition: all 0.4s;
            box-shadow: 0 10px 25px rgba(15, 23, 42, 0.2);
        }

        .checkout-btn:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 35px rgba(15, 23, 42, 0.3);
            background: #000;
        }

        .empty-cart-message {
            text-align: center;
            color: var(--text-secondary);
            font-weight: 500;
            padding: 50px 0;
            font-size: 1.1rem;
        }

        @media (max-width: 500px) {
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
                <li><a href="dashboard.php" class="active"><i class="fas fa-home"></i> Beranda</a></li>
                <li><a href="#katalog"><i class="fas fa-shirt"></i> Katalog</a></li>
                <li><a href="#cerita-kami"><i class="fas fa-info-circle"></i> Cerita Kami</a></li>
                <li><a href="#hubungi-kami"><i class="fas fa-phone"></i> Hubungi Kami</a></li>
                <li><a href="transaction_history.php"><i class="fas fa-receipt"></i> Riwayat Transaksi</a></li>
            </ul>
        </nav>
        <div class="nav-icons" style="position: relative;">
            <a href="#" class="icon-btn" id="cartIcon"><i class="fas fa-shopping-cart"></i></a>
            <div class="profile-icon-container">
                <a href="profile.php" class="icon-btn" id="profileIcon" style="position: relative;">
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
                <p>Profil Anda belum lengkap. <a href="profile.php">Lengkapi sekarang</a> untuk mendapatkan pengalaman belanja terbaik.</p>
                <button class="close-popup-btn">&times;</button>
            </div>
        <?php endif; ?>
        <section class="hero-section">
            <div class="hero-content-left">
                <div class="trending-badge">
                    <i class="fas fa-chart-line"></i> Trending Fashion
                </div>
                <h1>Elegansi Modern dalam Setiap Jahitan</h1>
                <p>Destinasi utama untuk fashion modern yang dirancang untuk menyempurnakan penampilan Anda setiap hari.</p>
                <span class="highlighted-text">Jelajahi Tren Terbaru Musim Ini</span>
                <div class="hero-buttons">
                    <a href="#katalog" class="btn-primary"><i class="fas fa-bag-shopping"></i> Lihat Koleksi</a>
                    <a href="#cerita-kami" class="btn-outline"><i class="fas fa-info-circle"></i> Tentang Wearnity</a>
                </div>
                <div class="hero-stats">
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-cube"></i>
                        </div>
                        <div class="stat-info">
                            <div class="value">1.000+</div>
                            <div class="label">Produk Terjual</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-user-check"></i>
                        </div>
                        <div class="stat-info">
                            <div class="value">800+</div>
                            <div class="label">Pelanggan Puas</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-star" style="color: var(--accent-color);"></i>
                        </div>
                        <div class="stat-info">
                            <div class="value">4.9</div>
                            <div class="label">Rating</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="hero-image-right">
                <img src="Asset/Bannerr.png" alt="Fashion Banner">
            </div>
        </section>
        <section id="katalog" class="catalog-section">
            <h2>Koleksi Katalog Eksklusif</h2>
            <p class="subtitle">Temukan gaya busana yang mencerminkan jati diri Anda.</p>

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
                                        <?php echo ($product['is_available'] ? '' : 'disabled'); /* Disable button if not available */ ?> title="Tambah ke Keranjang">
                                        <i class="fas fa-shopping-bag"></i>
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
            <p class="tagline">Merayakan setiap jati diri melalui karya fashion yang otentik dan penuh makna.</p>

            <div class="about-content">
                <div class="about-image-card">
                    <img src="Asset/Kaos.jpeg" alt="Fashion Story">
                    <div class="quote-overlay">
                        <span>"Fashion bukan hanya tentang apa yang Anda pakai, tapi tentang bagaimana Anda hidup."</span>
                    </div>
                </div>
                <div class="about-text-content">
                    <span class="brand-insight">Kisah di Balik Layar</span>
                    <h3>WEARNITY BY THANKSINSOMNIA</h3>
                    <p>WEARNITY lahir sebagai wadah ekspresi jati diri melalui fashion. Kami tidak hanya menciptakan pakaian; kami merajut kepercayaan diri ke dalam setiap serat kain melalui kolaborasi erat dengan kreator lokal terbaik.</p>
                    <p>Hadir untuk memastikan Anda tampil elegan, otentik, dan bangga menjadi diri sendiri tanpa harus sekadar mengikuti arus tren musiman.</p>
                </div>
            </div>


        </section>
        <section id="hubungi-kami" class="contact-section">
            <h2>Hubungi Kami</h2>
            <p class="tagline">Ada pertanyaan atau ingin berkolaborasi? Kami siap mendengarkan pesan Anda.</p>

            <div class="contact-buttons">
                <button class="btn active" data-target="kirim-pesan"><i class="fas fa-paper-plane"></i> Kirim Pesan</button>
                <button class="btn" data-target="info-kontak"><i class="fas fa-info-circle"></i> Info Kontak</button>
            </div>

            <div class="contact-content-area" id="contactContentArea">
                <div class="contact-card-container active-content" id="kirim-pesan">
                    <div class="contact-card-left">
                        <h3><i class="fas fa-comment-dots"></i> Kirim Pesan</h3>
                        <p>Kirim pesan langsung melalui formulir di bawah ini. Kami akan merespons pesan Anda sesegera mungkin.</p>
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
                                <input type="text" id="nama_lengkap" name="nama_lengkap" placeholder="Masukkan nama lengkap Anda" required>
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
                                <textarea id="pesan" name="pesan" placeholder="Tuliskan pesan Anda di sini..." required></textarea>
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

    <footer class="simple-footer">
        <p>&copy; 2025 Wearnity by THANKSINSOMNIA. All Rights Reserved.</p>
    </footer>

    <div class="cart-overlay" id="cartOverlay"></div>
    <div class="cart-sidebar" id="cartSidebar">
        <!-- Content will be loaded via AJAX -->
    </div>

    <div id="toast-container"></div>
    <script>
        // Profile Dropdown
        const profileIcon = document.getElementById('profileIcon');
        const profileDropdown = document.getElementById('profileDropdown');

        // Dropdown handled by CSS hover for desktop, 
        // using click logic only for mobile if needed or just letting it navigate
        profileIcon.addEventListener('click', function(event) {
            // No preventDefault here so it navigates to profile.php
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

            // Fungsi untuk menampilkan Toast Notification
            function showToast(message, type = 'success') {
                const container = document.getElementById('toast-container');
                if (!container) return;

                const toast = document.createElement('div');
                toast.className = `toast ${type}`;
                toast.innerHTML = `
                    <i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'}"></i>
                    <span>${message}</span>
                `;
                
                container.appendChild(toast);

                setTimeout(() => {
                    toast.classList.add('fade-out');
                    setTimeout(() => toast.remove(), 500);
                }, 3000);
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
                            return response.text().then(text => {
                                throw new Error('Network response failure. Status: ' + response.status);
                            });
                        }
                        return response.json();
                    })
                    .then(data => {
                        if (data.success) {
                            showToast(data.message); // Gunakan toast, bukan alert
                            toggleCartSidebar(); // Langsung buka keranjang
                        } else {
                            showToast(data.message, 'error');
                        }
                    })
                    .catch(error => {
                        console.error('Error adding to cart:', error);
                        showToast('Gagal menambah produk. Silakan coba lagi.', 'error');
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
                // Show immediately at the start
                profileCompletionPopup.classList.add('show');

                const closePopupBtn = profileCompletionPopup.querySelector('.close-popup-btn');
                closePopupBtn.addEventListener('click', () => {
                    profileCompletionPopup.style.display = 'none';
                });
            }
        });
    </script>
</body>

</html>
