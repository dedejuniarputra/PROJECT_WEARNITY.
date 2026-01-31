<?php
require_once 'config.php'; // Memuat konfigurasi database dan memulai session

// Cek apakah user sudah login. Jika belum, arahkan kembali ke halaman login.
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("location: index.php");
    exit;
}

$user_id = $_SESSION['id'];
$username = $_SESSION['username']; // Username dari session
$full_name = $email = $phone_number = $address = ""; // Inisialisasi awal
$full_name_err = $email_err = $phone_number_err = $address_err = "";
$success_message = "";
$error_message = "";

// Ambil data user yang sedang login dari database
$sql_select_user = "SELECT username, full_name, email, phone_number, address FROM users WHERE id = :id";
if ($stmt = $pdo->prepare($sql_select_user)) {
    $stmt->bindParam(":id", $user_id, PDO::PARAM_INT);
    if ($stmt->execute()) {
        if ($stmt->rowCount() == 1) {
            $user_data = $stmt->fetch(PDO::FETCH_ASSOC);
            $full_name = $user_data['full_name'] ?? '';
            $email = $user_data['email'] ?? '';
            $phone_number = $user_data['phone_number'] ?? '';
            $address = $user_data['address'] ?? '';
        } else {
            $error_message = "Data pengguna tidak ditemukan.";
        }
    } else {
        $error_message = "Terjadi kesalahan saat mengambil data pengguna.";
    }
    unset($stmt);
}

// Cek profile completeness untuk UI header
$profile_incomplete = false;
if (empty($full_name) || empty($email) || empty($phone_number) || empty($address)) {
    $profile_incomplete = true;
}

// Proses update profile jika form disubmit
if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // Validasi Full Name
    if (empty(trim($_POST["full_name"]))) {
        $full_name_err = "Mohon masukkan nama lengkap.";
    } else {
        $full_name = trim($_POST["full_name"]);
    }

    // Validasi Email
    if (empty(trim($_POST["email"]))) {
        $email_err = "Mohon masukkan alamat email.";
    } elseif (!filter_var(trim($_POST["email"]), FILTER_VALIDATE_EMAIL)) {
        $email_err = "Format email tidak valid.";
    } else {
        $email = trim($_POST["email"]);
        $sql_check_email = "SELECT id FROM users WHERE email = :email AND id != :id";
        if ($stmt_check = $pdo->prepare($sql_check_email)) {
            $stmt_check->bindParam(":email", $param_email, PDO::PARAM_STR);
            $stmt_check->bindParam(":id", $user_id, PDO::PARAM_INT);
            $param_email = $email;
            if ($stmt_check->execute()) {
                if ($stmt_check->rowCount() > 0) {
                    $email_err = "Email ini sudah terdaftar oleh pengguna lain.";
                }
            } else {
                $error_message .= " Terjadi kesalahan saat memeriksa email.";
            }
            unset($stmt_check);
        }
    }

    // Validasi Phone Number
    if (empty(trim($_POST["phone_number"]))) {
        $phone_number_err = "Mohon masukkan nomor telepon.";
    } elseif (!preg_match("/^[0-9\s\-\(\)\+]*$/", trim($_POST["phone_number"]))) {
        $phone_number_err = "Nomor telepon tidak valid.";
    } else {
        $phone_number = trim($_POST["phone_number"]);
    }

    // Validasi Address
    if (empty(trim($_POST["address"]))) {
        $address_err = "Mohon masukkan alamat lengkap.";
    } else {
        $address = trim($_POST["address"]);
    }

    // Jika tidak ada error validasi, update data di database
    if (empty($full_name_err) && empty($email_err) && empty($phone_number_err) && empty($address_err)) {
        $sql_update = "UPDATE users SET full_name = :full_name, email = :email, phone_number = :phone_number, address = :address WHERE id = :id";
        if ($stmt_update = $pdo->prepare($sql_update)) {
            $stmt_update->bindParam(":full_name", $full_name, PDO::PARAM_STR);
            $stmt_update->bindParam(":email", $email, PDO::PARAM_STR);
            $stmt_update->bindParam(":phone_number", $phone_number, PDO::PARAM_STR);
            $stmt_update->bindParam(":address", $address, PDO::PARAM_STR);
            $stmt_update->bindParam(":id", $user_id, PDO::PARAM_INT);

            if ($stmt_update->execute()) {
                $success_message = "Profil berhasil diperbarui!";
                $_SESSION['full_name'] = $full_name;
                // Re-check completeness
                $profile_incomplete = false; 
            } else {
                $error_message = "Terjadi kesalahan saat memperbarui profil Anda.";
            }
            unset($stmt_update);
        }
    }
}
unset($pdo);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile Saya | WEARNITY</title>
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

        /* Profile Container */
        .profile-container {
            max-width: 800px;
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

        .profile-card {
            background: white;
            border-radius: var(--radius-xl);
            padding: 50px;
            box-shadow: var(--shadow-lg);
            border: 1px solid rgba(0, 0, 0, 0.05);
        }

        .profile-title {
            font-size: 1.6rem;
            font-weight: 800;
            margin-bottom: 35px;
            color: var(--primary-color);
            display: flex;
            align-items: center;
            gap: 15px;
            border-bottom: 1px solid #f1f5f9;
            padding-bottom: 20px;
        }

        .profile-title i {
            color: var(--secondary-color);
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 25px;
        }

        .form-group {
            margin-bottom: 25px;
        }

        .form-group.full-width {
            grid-column: span 2;
        }

        .form-group label {
            display: block;
            margin-bottom: 10px;
            font-weight: 700;
            font-size: 0.9rem;
            color: var(--text-primary);
        }

        .form-group input, .form-group textarea {
            width: 100%;
            padding: 16px 22px;
            border: 2px solid var(--border-color);
            border-radius: 16px;
            background: #f8fafc;
            font-family: var(--font-body);
            font-size: 0.95rem;
            transition: all 0.3s;
            color: var(--text-primary);
        }

        .form-group input:focus, .form-group textarea:focus {
            border-color: var(--secondary-color);
            outline: none;
            background: white;
            box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.1);
        }

        .form-group input.readonly {
            background: #f1f5f9;
            cursor: not-allowed;
            color: #94a3b8;
        }

        .invalid-feedback {
            color: #ef4444;
            font-size: 0.85rem;
            font-weight: 600;
            margin-top: 8px;
            display: block;
        }

        .submit-btn {
            width: 100%;
            background: var(--primary-color);
            color: white;
            padding: 20px;
            border-radius: 18px;
            border: none;
            font-family: var(--font-display);
            font-size: 1.1rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            box-shadow: var(--shadow-lg);
            margin-top: 20px;
        }

        .submit-btn:hover {
            background: var(--secondary-color);
            transform: translateY(-3px);
            box-shadow: 0 15px 35px rgba(99, 102, 241, 0.25);
        }

        .simple-footer {
            background: #111111;
            color: #6b7280;
            padding: 25px 7%;
            text-align: center;
            border-top: 1px solid rgba(255, 255, 255, 0.05);
            margin-top: auto;
        }

        .simple-footer p {
            font-size: 0.85rem;
            margin: 0;
            letter-spacing: 0.5px;
        }

        /* Toast & Sidebars Structure */
        #toast-container { position: fixed; bottom: 30px; left: 30px; z-index: 9999; }
        .toast { background: var(--primary-color); color: white; padding: 16px 28px; border-radius: 16px; display: flex; align-items: center; gap: 12px; margin-top: 10px; animation: slideInLeft 0.5s cubic-bezier(0.16, 1, 0.3, 1); font-weight: 600; border: 1px solid rgba(255,255,255,0.1); box-shadow: var(--shadow-xl); }
        .toast.fade-out { opacity: 0; transform: translateY(10px); transition: 0.5s; }
        @keyframes slideInLeft { from { transform: translateX(-100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }

        .cart-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.4); backdrop-filter: blur(8px); z-index: 3000; display: none; }
        .cart-sidebar { position: fixed; top: 0; right: -450px; width: 450px; height: 100%; background: #ffffff; backdrop-filter: blur(20px); box-shadow: -15px 0 50px rgba(0,0,0,0.15); z-index: 3001; transition: all 0.6s cubic-bezier(0.16, 1, 0.3, 1); display: flex; flex-direction: column; }
        .cart-sidebar.open { right: 0; }

        /* Cart UI Internal (Matched to Screenshot) */
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
            .form-grid { grid-template-columns: 1fr; }
            .form-group.full-width { grid-column: span 1; }
            .page-header h1 { font-size: 2.5rem; }
            .nav-links { display: none; }
            .profile-card { padding: 30px; }
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
                        <li><a href="profile.php" class="active"><i class="fas fa-user-circle"></i> Profil Saya</a></li>
                        <li><a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </header>

    <main>
        <div class="profile-container">
            <div class="page-header">
                <h1>Profil Gue</h1>
                <p>Kelola data diri lo biar belanja makin sat-set!</p>
            </div>

            <div class="profile-card">
                <div class="profile-title">
                    <i class="fas fa-user-edit"></i>
                    <span>Informasi Akun</span>
                </div>

                <?php if ($success_message): ?>
                    <div class="toast success" style="position: static; margin-bottom: 25px; animation: none;">
                        <i class="fas fa-check-circle"></i>
                        <span><?php echo $success_message; ?></span>
                    </div>
                <?php endif; ?>

                <?php if ($error_message): ?>
                    <div class="toast danger" style="position: static; margin-bottom: 25px; animation: none; background: #ef4444;">
                        <i class="fas fa-exclamation-circle"></i>
                        <span><?php echo $error_message; ?></span>
                    </div>
                <?php endif; ?>

                <form action="profile.php" method="POST">
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Username</label>
                            <input type="text" value="<?php echo htmlspecialchars($username); ?>" class="readonly" readonly>
                            <small style="color: #94a3b8; font-size: 0.8rem; margin-top: 5px; display: block;">Username bersifat permanen ya!</small>
                        </div>
                        <div class="form-group">
                            <label>Nama Lengkap</label>
                            <input type="text" name="full_name" value="<?php echo htmlspecialchars($full_name); ?>" placeholder="Masukkan nama keren lo" required>
                            <span class="invalid-feedback"><?php echo $full_name_err; ?></span>
                        </div>
                        <div class="form-group">
                            <label>Email Aktif</label>
                            <input type="email" name="email" value="<?php echo htmlspecialchars($email); ?>" placeholder="nama@email.com" required>
                            <span class="invalid-feedback"><?php echo $email_err; ?></span>
                        </div>
                        <div class="form-group">
                            <label>Nomor Telepon (WhatsApp)</label>
                            <input type="tel" name="phone_number" value="<?php echo htmlspecialchars($phone_number); ?>" placeholder="08xxxxxxxxxx" required>
                            <span class="invalid-feedback"><?php echo $phone_number_err; ?></span>
                        </div>
                        <div class="form-group full-width">
                            <label>Alamat Pengiriman Lengkap</label>
                            <textarea name="address" rows="4" placeholder="Jl. Kenangan No. 123, Blok A, Jakarta Selatan..." required><?php echo htmlspecialchars($address); ?></textarea>
                            <span class="invalid-feedback"><?php echo $address_err; ?></span>
                        </div>
                    </div>

                    <button type="submit" class="submit-btn">
                        <i class="fas fa-save"></i>
                        Simpan Perubahan
                    </button>
                </form>
            </div>
        </div>
    </main>

    <footer class="simple-footer">
        <p>&copy; 2025 Wearnity by THANKSINSOMNIA. All Rights Reserved.</p>
    </footer>

    <div id="toast-container"></div>
    <div class="cart-overlay" id="cartOverlay"></div>
    <div class="cart-sidebar" id="cartSidebar"></div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Navigation Active State
            const currentPath = window.location.pathname.split('/').pop();
            document.querySelectorAll('.nav-links li a').forEach(link => {
                const linkPath = link.getAttribute('href').split('/').pop().split('#')[0];
                if (linkPath === currentPath) link.classList.add('active');
            });

            // Toast Logic
            function showToast(msg, type = 'success') {
                const container = document.getElementById('toast-container');
                const toast = document.createElement('div');
                toast.className = `toast ${type}`;
                toast.innerHTML = `<i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'}"></i><span>${msg}</span>`;
                container.appendChild(toast);
                setTimeout(() => {
                    toast.classList.add('fade-out');
                    setTimeout(() => toast.remove(), 500);
                }, 3000);
            }

            // Shopping Cart Logic
            const cartSidebar = document.getElementById('cartSidebar');
            const cartOverlay = document.getElementById('cartOverlay');
            const cartIcon = document.getElementById('cartIcon');

            function toggleCartSidebar() {
                if (cartSidebar && cartOverlay) {
                    cartSidebar.classList.toggle('open');
                    cartOverlay.style.display = cartSidebar.classList.contains('open') ? 'block' : 'none';
                    document.body.style.overflow = cartSidebar.classList.contains('open') ? 'hidden' : '';
                    if (cartSidebar.classList.contains('open')) loadCartContent();
                }
            }

            if (cartIcon) cartIcon.addEventListener('click', (e) => { e.preventDefault(); toggleCartSidebar(); });
            if (cartOverlay) cartOverlay.addEventListener('click', toggleCartSidebar);

            function loadCartContent() {
                fetch('_cart_content.php').then(r => r.text()).then(html => {
                    cartSidebar.innerHTML = html;
                    attachCartItemListeners();
                });
            }

            function attachCartItemListeners() {
                const closeBtn = document.querySelector('#cartSidebar .close-cart-btn');
                if (closeBtn) closeBtn.onclick = toggleCartSidebar;

                document.querySelectorAll('#cartSidebar .qty-btn').forEach(btn => {
                    btn.onclick = function() {
                        const pid = this.closest('.cart-item').dataset.productId;
                        const qtyEl = this.parentNode.querySelector('.item-quantity');
                        let newQty = this.dataset.action === 'plus' ? parseInt(qtyEl.textContent) + 1 : parseInt(qtyEl.textContent) - 1;
                        if (newQty <= 0) { if (confirm('Hapus item?')) updateCartItem(pid, 0); }
                        else updateCartItem(pid, newQty);
                    };
                });

                document.querySelectorAll('#cartSidebar .remove-item-btn').forEach(btn => {
                    btn.onclick = function() {
                        if (confirm('Hapus item?')) updateCartItem(this.closest('.cart-item').dataset.productId, 0);
                    };
                });

                const checkoutBtn = document.getElementById('checkoutBtn');
                if (checkoutBtn) checkoutBtn.onclick = (e) => { e.preventDefault(); window.location.href = 'order_details.php'; };
            }

            function updateCartItem(pid, qty) {
                fetch('cart_actions.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `action=update_quantity&product_id=${pid}&quantity=${qty}`
                }).then(r => r.json()).then(data => {
                    if (data.success) {
                        loadCartContent();
                        showToast(qty === 0 ? 'Item dihapus' : 'Keranjang diperbarui');
                    }
                });
            }
        });
    </script>
</body>
</html>