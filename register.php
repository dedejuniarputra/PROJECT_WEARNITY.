<?php
require_once 'config.php';

// Jika user sudah login, tidak perlu register lagi, arahkan ke dashboard
if (isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true) {
    if ($_SESSION['role'] === 'admin') {
        header("location: admin.php");
    } else {
        header("location: dashboard.php");
    }
    exit;
}

$username = $password = $confirm_password = "";
$username_err = $password_err = $confirm_password_err = "";
$registration_success = false;

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // Validasi username
    if (empty(trim($_POST["username"]))) {
        $username_err = "Mohon masukkan username.";
    } else {
        // Cek apakah username sudah ada di database
        $sql = "SELECT id FROM users WHERE username = :username";
        if ($stmt = $pdo->prepare($sql)) {
            $stmt->bindParam(":username", $param_username, PDO::PARAM_STR);
            $param_username = trim($_POST["username"]);
            if ($stmt->execute()) {
                if ($stmt->rowCount() == 1) {
                    $username_err = "Username ini sudah terdaftar.";
                } else {
                    $username = trim($_POST["username"]);
                }
            } else {
                echo "Terjadi kesalahan. Mohon coba lagi nanti.";
            }
            unset($stmt);
        }
    }

    // Validasi password
    if (empty(trim($_POST["password"]))) {
        $password_err = "Mohon masukkan password.";
    } elseif (strlen(trim($_POST["password"])) < 6) {
        $password_err = "Password minimal harus 6 karakter.";
    } else {
        $password = trim($_POST["password"]);
    }

    // Validasi konfirmasi password
    if (empty(trim($_POST["confirm_password"]))) {
        $confirm_password_err = "Mohon konfirmasi password.";
    } else {
        $confirm_password = trim($_POST["confirm_password"]);
        if (empty($password_err) && ($password != $confirm_password)) {
            $confirm_password_err = "Password tidak cocok.";
        }
    }

    // Jika tidak ada error, masukkan user baru ke database
    if (empty($username_err) && empty($password_err) && empty($confirm_password_err)) {
        $sql = "INSERT INTO users (username, password, role) VALUES (:username, :password, 'user')";

        if ($stmt = $pdo->prepare($sql)) {
            $stmt->bindParam(":username", $param_username, PDO::PARAM_STR);
            $stmt->bindParam(":password", $param_password, PDO::PARAM_STR);

            $param_username = $username;
            $param_password = password_hash($password, PASSWORD_DEFAULT); // Hash password

            if ($stmt->execute()) {
                $registration_success = true;
            } else {
                echo "Terjadi kesalahan. Mohon coba lagi nanti.";
            }
            unset($stmt);
        }
    }

    unset($pdo);
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registrasi | WEARNITY</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Outfit:wght@700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-navy: #0f172a;
            --accent-indigo: #6366f1;
            --background-dark: #020617;
            --text-slate: #64748b;
            --radius-form: 16px;
            --shadow-premium: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
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
            background-color: var(--background-dark);
            margin: 0;
            padding: 0;
            min-height: 100vh;
            width: 100vw;
            display: grid;
            place-items: center;
            overflow: hidden;
            position: relative;
        }

        /* Ambient Background */
        .ambient-glow {
            position: absolute;
            width: 600px;
            height: 600px;
            background: radial-gradient(circle, rgba(99, 102, 241, 0.15) 0%, transparent 70%);
            border-radius: 50%;
            z-index: 1;
            filter: blur(60px);
        }
        .glow-1 { top: -10%; left: -5%; }
        .glow-2 { bottom: -10%; right: -5%; }

        .register-card {
            width: 100%;
            max-width: 420px;
            background: rgba(15, 23, 42, 0.6);
            backdrop-filter: blur(25px);
            -webkit-backdrop-filter: blur(25px);
            border-radius: 32px;
            padding: 50px 40px;
            border: 1px solid rgba(255, 255, 255, 0.08);
            box-shadow: var(--shadow-premium);
            text-align: center;
            position: relative;
            z-index: 10;
            animation: cardFadeUp 0.8s cubic-bezier(0.16, 1, 0.3, 1);
        }

        @keyframes cardFadeUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        h2 {
            font-family: var(--font-display);
            font-size: 2.1rem;
            color: #ffffff;
            margin-bottom: 6px;
            letter-spacing: -1px;
        }

        .subtitle {
            color: var(--text-slate);
            font-size: 0.9rem;
            margin-bottom: 30px;
            font-weight: 500;
        }

        .form-group {
            margin-bottom: 18px;
            text-align: left;
        }

        .form-group label {
            display: block;
            font-size: 0.7rem;
            font-weight: 700;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 8px;
            margin-left: 5px;
        }

        .input-box {
            position: relative;
        }

        .input-box i {
            position: absolute;
            left: 20px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-slate);
            font-size: 1rem;
            transition: 0.3s;
        }

        input {
            width: 100%;
            padding: 14px 20px 14px 52px;
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: var(--radius-form);
            color: #ffffff;
            font-family: var(--font-body);
            font-size: 0.95rem;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        input:focus {
            outline: none;
            background: rgba(255, 255, 255, 0.06);
            border-color: var(--accent-indigo);
            box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.15);
        }

        input:focus + i {
            color: var(--accent-indigo);
        }

        .invalid-feedback {
            color: #fb7185;
            font-size: 0.8rem;
            margin-top: 8px;
            margin-left: 5px;
            display: block;
            font-weight: 500;
        }

        .register-btn {
            width: 100%;
            background: #ffffff;
            color: var(--primary-navy);
            padding: 16px;
            border-radius: var(--radius-form);
            border: none;
            font-family: var(--font-display);
            font-size: 1.05rem;
            font-weight: 800;
            cursor: pointer;
            transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1);
            margin-top: 10px;
        }

        .register-btn:hover {
            background: var(--accent-indigo);
            color: #ffffff;
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(99, 102, 241, 0.4);
        }

        .register-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        .footer-text {
            margin-top: 25px;
            color: var(--text-slate);
            font-size: 0.85rem;
            font-weight: 500;
        }

        .footer-text a {
            color: #ffffff;
            text-decoration: none;
            font-weight: 700;
            transition: 0.3s;
        }

        .footer-text a:hover {
            color: var(--accent-indigo);
        }

        .alert-success {
            background: rgba(16, 185, 129, 0.1);
            border: 1px solid rgba(16, 185, 129, 0.2);
            color: #6ee7b7;
            padding: 16px;
            border-radius: 16px;
            margin-bottom: 25px;
            font-size: 0.9rem;
            font-weight: 600;
            text-align: center;
        }

        .alert-success a {
            color: #ffffff;
            text-decoration: underline;
        }

        @media (max-width: 440px) {
            .register-card {
                width: 90%;
                padding: 40px 25px;
            }
        }
    </style>
</head>

<body>
    <div class="ambient-glow glow-1"></div>
    <div class="ambient-glow glow-2"></div>

    <div class="register-card">
        <h2>Join Us</h2>
        <p class="subtitle">Daftar biar bisa borong koleksi kita!</p>

        <?php if ($registration_success): ?>
            <div class="alert-success">
                <i class="fas fa-check-circle"></i> Akun berhasil dibuat! <br> Silakan <a href="index.php">login di sini</a>.
            </div>
        <?php endif; ?>

        <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
            <div class="form-group">
                <label>Username</label>
                <div class="input-box">
                    <input type="text" name="username" value="<?php echo $username; ?>" placeholder="Username unik lo">
                    <i class="fas fa-user"></i>
                </div>
                <?php if (!empty($username_err)): ?>
                    <span class="invalid-feedback"><?php echo $username_err; ?></span>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label>Password</label>
                <div class="input-box">
                    <input type="password" name="password" placeholder="Minimal 6 karakter">
                    <i class="fas fa-lock"></i>
                </div>
                <?php if (!empty($password_err)): ?>
                    <span class="invalid-feedback"><?php echo $password_err; ?></span>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label>Konfirmasi Password</label>
                <div class="input-box">
                    <input type="password" name="confirm_password" placeholder="Ulangi password lo">
                    <i class="fas fa-check-double"></i>
                </div>
                <?php if (!empty($confirm_password_err)): ?>
                    <span class="invalid-feedback"><?php echo $confirm_password_err; ?></span>
                <?php endif; ?>
            </div>

            <button type="submit" class="register-btn" <?php echo $registration_success ? 'disabled' : ''; ?>>
                Daftar Sekarang
            </button>

            <p class="footer-text">
                Sudah punya akun? <a href="index.php">Login di sini</a>
            </p>
        </form>
    </div>
</body>

</html>