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
            // Pastikan nilai yang diambil dari database tidak NULL sebelum diassign
            $full_name = $user_data['full_name'] ?? ''; // Jika NULL, gunakan string kosong
            $email = $user_data['email'] ?? '';
            $phone_number = $user_data['phone_number'] ?? '';
            $address = $user_data['address'] ?? '';
            // Username dari session sudah string, jadi tidak perlu ?? '' di sini
            // Tapi jika Anda ambil dari $user_data['username'], pastikan juga pakai ?? ''
            // $username = $user_data['username'] ?? ''; // Opsional jika username bisa NULL
        } else {
            $error_message = "Data pengguna tidak ditemukan.";
        }
    } else {
        $error_message = "Terjadi kesalahan saat mengambil data pengguna.";
    }
    unset($stmt);
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
        // Cek apakah email sudah terdaftar pada user lain (kecuali diri sendiri)
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
                // Update session jika full_name digunakan di header (optional)
                $_SESSION['full_name'] = $full_name;
            } else {
                $error_message = "Terjadi kesalahan saat memperbarui profil Anda.";
            }
            unset($stmt_update);
        }
    }
}
unset($pdo); // Tutup koneksi database
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile Saya | WEARNITY</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* General Styles & Resets (Updated for Montserrat and consistent background) */
        body {
            font-family: 'Montserrat', sans-serif;
            margin: 0;
            padding: 0;
            background-color: #f0f2f5; /* Consistent soft background */
            color: #333;
            line-height: 1.6;
            overflow-x: hidden;
            padding-top: 70px; /* Consistent padding-top for fixed header */
        }

        a {
            text-decoration: none;
            color: inherit;
        }

        /* Header / Navigation Bar (Copied from dashboard.php for consistency) */
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

        /* Logo Text Styling (Copied from dashboard.php) */
        .logo strong {
            font-size: 28px;
            color: #2c3e50; /* Darker color for logo text */
            letter-spacing: 1px;
            font-weight: 700;
            font-family: 'Montserrat', sans-serif; /* Ensure Montserrat font here */
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

        /* Profile Dropdown (Copied from dashboard.php) */
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

        /* Shopping Cart Sidebar Styles (Copied from dashboard.php) */
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

        /* Main Content (Profile Specific Styles) */
        .container {
            max-width: 700px;
            margin: 40px auto;
            background-color: #fff;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 0 15px rgba(0, 0, 0, 0.1);
        }

        h2 {
            text-align: center;
            margin-bottom: 30px;
            color: #333;
            font-weight: 700; /* Consistent with other page titles */
        }

        .form-group {
            margin-bottom: 20px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600; /* Consistent with other forms */
            color: #444; /* Consistent with other forms */
            font-size: 15px; /* Consistent with other forms */
        }

        input[type="text"],
        input[type="email"],
        input[type="tel"],
        textarea {
            width: calc(100% - 22px); /* Adjusted for 10px padding + 1px border on each side */
            padding: 12px 15px; /* Consistent with other forms */
            border: 1px solid #ddd;
            border-radius: 8px; /* Consistent with other forms */
            font-size: 16px;
            box-sizing: border-box; /* Crucial for consistent width */
            transition: border-color 0.3s ease, box-shadow 0.3s ease; /* Consistent */
        }
        input[type="text"]:focus,
        input[type="email"]:focus,
        input[type="tel"]:focus,
        textarea:focus {
            border-color: #007bff;
            box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.25); /* Consistent */
            outline: none;
        }


        textarea {
            resize: vertical;
            min-height: 80px;
        }

        .btn-submit {
            background-color: #007bff; /* Consistent primary button color */
            color: white;
            padding: 15px 25px; /* Consistent padding */
            border: none;
            border-radius: 8px; /* Consistent border-radius */
            cursor: pointer;
            font-size: 18px; /* Consistent font size */
            width: 100%;
            transition: background-color 0.3s ease, transform 0.2s ease; /* Consistent animation */
            font-weight: 600; /* Consistent font-weight */
        }

        .btn-submit:hover {
            background-color: #0056b3; /* Consistent hover color */
            transform: translateY(-2px); /* Consistent hover effect */
            box-shadow: 0 2px 8px rgba(0,0,0,0.1); /* Consistent hover shadow */
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

        .username-field {
            background-color: #e9ecef;
            cursor: not-allowed;
        }

        /* Footer (Copied from dashboard.php for consistency) */
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


        /* Responsive Adjustments (Copied from dashboard.php for consistency) */
        @media (max-width: 992px) {
            header {
                padding: 15px 30px;
            }
            .nav-links {
                gap: 25px;
            }
            .container {
                margin: 30px auto;
                padding: 25px;
            }
            footer {
                padding: 15px 15px;
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
                padding-top: 130px; /* Adjust if header layout changes significantly */
            }
            header {
                flex-direction: column;
                align-items: flex-start;
                padding: 15px 20px;
            }
            .logo { /* Add margin to separate from nav-links */
                margin-bottom: 15px; 
            }
            .nav-links {
                margin-top: 0; /* Reset margin from header flex-direction */
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
                bottom: -5px; /* Drop up from bottom on mobile */
                left: 50%;
                transform: translateX(-50%) translateY(-100%);
            }
            /* Cart sidebar responsive styles - if this page has one */
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

            .container {
                margin: 20px;
                padding: 20px;
            }
            .btn-submit {
                font-size: 16px;
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
                font-size: 24px;
            }
            .nav-links li a {
                font-size: 15px;
                gap: 5px;
            }
            .nav-icons .icon-btn {
                font-size: 20px;
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
                        <li><a href="profile.php" class="active"><i class="fas fa-user-circle"></i> Profil Saya</a></li>
                        <li><a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </header>

    <main>
        <div class="container">
            <h2>Profil Pengguna</h2>
            <?php
            if (!empty($success_message)) {
                echo '<div class="alert alert-success">' . htmlspecialchars($success_message) . '</div>';
            }
            if (!empty($error_message)) {
                echo '<div class="alert alert-danger">' . htmlspecialchars($error_message) . '</div>';
            }
            ?>
            <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username" value="<?php echo htmlspecialchars($username); ?>" class="username-field" readonly>
                    <small style="color: #777;">Username tidak dapat diubah.</small>
                </div>
                <div class="form-group">
                    <label for="full_name">Nama Lengkap</label>
                    <input type="text" id="full_name" name="full_name" value="<?php echo htmlspecialchars($full_name); ?>">
                    <span class="invalid-feedback"><?php echo htmlspecialchars($full_name_err); ?></span>
                </div>
                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($email); ?>">
                    <span class="invalid-feedback"><?php echo htmlspecialchars($email_err); ?></span>
                </div>
                <div class="form-group">
                    <label for="phone_number">Nomor Telepon</label>
                    <input type="tel" id="phone_number" name="phone_number" value="<?php echo htmlspecialchars($phone_number); ?>">
                    <span class="invalid-feedback"><?php echo htmlspecialchars($phone_number_err); ?></span>
                </div>
                <div class="form-group">
                    <label for="address">Alamat</label>
                    <textarea id="address" name="address"><?php echo htmlspecialchars($address); ?></textarea>
                    <span class="invalid-feedback"><?php echo htmlspecialchars($address_err); ?></span>
                </div>
                <div class="form-group">
                    <button type="submit" class="btn-submit">Update Profil</button>
                </div>
            </form>
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
    // JavaScript untuk Profile Dropdown
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
            const linkHref = link.getAttribute('href');
            // Check if linkHref is not null or empty before splitting
            const linkPath = linkHref ? linkHref.split('/').pop().split('#')[0] : ''; // Handle links with # and empty href

            if (currentPath === 'profile.php' && linkPath === 'profile.php') {
                link.classList.add('active');
            } else if (currentPath === '' || currentPath === 'dashboard.php') {
                // Special handling for dashboard link which might be just "dashboard.php" or "#"
                if (linkHref === 'dashboard.php' || linkHref === '#') {
                    link.classList.add('active');
                }
            } else if (linkPath === currentPath) {
                link.classList.add('active');
            }
        });


        // --- Logika Keranjang Belanja (Shopping Cart Logic) ---
        // Ensure these elements exist in your HTML, especially for cart functionality
        const cartSidebar = document.getElementById('cartSidebar');
        const cartOverlay = document.getElementById('cartOverlay');
        const cartIcon = document.getElementById('cartIcon');
        // cartContentContainer merujuk pada elemen sidebar itu sendiri di mana konten akan dimuat
        const cartContentContainer = document.getElementById('cartSidebar');

        // Fungsi untuk membuka/menutup sidebar keranjang
        function toggleCartSidebar() {
            if (cartSidebar && cartOverlay) { // Defensive check
                cartSidebar.classList.toggle('open');
                cartOverlay.style.display = cartSidebar.classList.contains('open') ? 'block' : 'none';
                // Mencegah scroll body saat sidebar terbuka
                document.body.style.overflow = cartSidebar.classList.contains('open') ? 'hidden' : '';
                if (cartSidebar.classList.contains('open')) {
                    loadCartContent(); // Muat ulang konten keranjang saat dibuka
                }
            } else {
                console.warn("Cart elements not found on profile.php. Cart functionality might be limited.");
                // Optionally, redirect to a dedicated cart page if sidebar isn't setup
                // window.location.href = 'cart.php'; 
            }
        }

        // Event listener untuk ikon keranjang di header
        if (cartIcon) { // Pastikan ikon keranjang ada
            cartIcon.addEventListener('click', function(event) {
                event.preventDefault();
                toggleCartSidebar();
            });
        }

        // Fungsi untuk memuat konten keranjang via AJAX
        function loadCartContent() {
            if (!cartContentContainer) return; // Defensive check
            fetch('_cart_content.php')
                .then(response => {
                    if (!response.ok) {
                        // Attempt to read text for more detailed error
                        return response.text().then(text => {
                            throw new Error('Network response for cart content was not ok. Status: ' + response.status + ', Response: ' + text);
                        });
                    }
                    return response.text();
                })
                .then(html => {
                    cartContentContainer.innerHTML = html;
                    // Sangat penting: pasang kembali event listener pada elemen baru setelah konten dimuat
                    attachCartItemListeners();
                })
                .catch(error => {
                    console.error('Error loading cart content:', error);
                    cartContentContainer.innerHTML = '<p class="empty-cart-message" style="color: red;">Gagal memuat keranjang. Silakan coba lagi. Detail: ' + error.message + '</p>';
                });
        }

        // Fungsi untuk menambah produk ke keranjang (mungkin tidak dipanggil langsung di halaman ini, tapi diperlukan oleh sidebar)
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
                        return response.text().then(text => { throw new Error(text); });
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        alert(data.message);
                        loadCartContent(); // Refresh cart after adding
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
                .then(response => {
                    if (!response.ok) {
                        return response.text().then(text => { throw new Error(text); });
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        loadCartContent(); // Muat ulang keranjang setelah update
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
                .then(response => {
                    if (!response.ok) {
                        return response.text().then(text => { throw new Error(text); });
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        loadCartContent(); // Muat ulang keranjang setelah hapus
                    } else {
                        alert('Gagal menghapus produk: ' + data.message);
                    }
                })
                .catch(error => console.error('Error removing from cart:', error));
        }


        // Fungsi ini akan dipanggil setelah konten keranjang dimuat ulang oleh AJAX
        // Fungsi ini memasang event listener pada elemen-elemen di dalam sidebar keranjang
        function attachCartItemListeners() {
            // Event listener for close button in cart sidebar
            const closeCartBtn = document.querySelector('#cartSidebar .close-cart-btn'); // Target tombol di dalam sidebar
            if (closeCartBtn) {
                closeCartBtn.addEventListener('click', function() {
                    toggleCartSidebar(); // Panggil fungsi toggle untuk menutup sidebar
                });
            } else {
                console.warn("Close cart button not found in sidebar after AJAX load.");
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
    });
    </script>
</body>

</html>