<?php
require_once 'config.php';

// Cek apakah user sudah login, jika ya, arahkan ke halaman yang sesuai
if (isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true) {
    if ($_SESSION['role'] === 'admin') {
        header("location: admin.php");
    } else {
        header("location: dashboard.php");
    }
    exit;
}

$username = $password = "";
$username_err = $password_err = $login_err = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // Validasi username
    if (empty(trim($_POST["username"]))) {
        $username_err = "Mohon masukkan username.";
    } else {
        $username = trim($_POST["username"]);
    }

    // Validasi password
    if (empty(trim($_POST["password"]))) {
        $password_err = "Mohon masukkan password.";
    } else {
        $password = trim($_POST["password"]);
    }

    // Cek kredensial
    if (empty($username_err) && empty($password_err)) {
        $sql = "SELECT id, username, password, role FROM users WHERE username = :username";

        if ($stmt = $pdo->prepare($sql)) {
            $stmt->bindParam(":username", $param_username, PDO::PARAM_STR);
            $param_username = $username;

            if ($stmt->execute()) {
                if ($stmt->rowCount() == 1) {
                    if ($row = $stmt->fetch()) {
                        $id = $row["id"];
                        $username = $row["username"];
                        $hashed_password = $row["password"];
                        $role = $row["role"];

                        if (password_verify($password, $hashed_password)) {
                            // Password benar, mulai session
                            $_SESSION["loggedin"] = true;
                            $_SESSION["id"] = $id;
                            $_SESSION["username"] = $username;
                            $_SESSION["role"] = $role;

                            // Arahkan ke halaman yang sesuai berdasarkan role
                            if ($role === 'admin') {
                                header("location: admin.php");
                            } else {
                                header("location: dashboard.php");
                            }
                        } else {
                            $login_err = "Username atau password salah.";
                        }
                    }
                } else {
                    $login_err = "Username atau password salah.";
                }
            } else {
                $login_err = "Terjadi kesalahan. Mohon coba lagi nanti.";
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
    <title>Login | WEARNITY</title>
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

        /* Ambient Background Fixes */
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

        .login-card {
            width: 100%;
            max-width: 400px;
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

        .login-btn {
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

        .login-btn:hover {
            background: var(--accent-indigo);
            color: #ffffff;
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(99, 102, 241, 0.4);
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

        .alert-box {
            background: rgba(225, 29, 72, 0.1);
            border: 1px solid rgba(225, 29, 72, 0.2);
            color: #fda4af;
            padding: 12px 16px;
            border-radius: 12px;
            font-size: 0.85rem;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 10px;
            text-align: left;
        }

        @media (max-width: 420px) {
            .login-card {
                width: 90%;
                padding: 40px 25px;
            }
        }
    </style>
</head>

<body>
    <div class="ambient-glow glow-1"></div>
    <div class="ambient-glow glow-2"></div>

    <div class="login-card">
        <h2>Welcome Back</h2>
        <p class="subtitle">Masuk buat lanjut belanja asik.</p>

        <?php if (!empty($login_err)): ?>
            <div class="alert-box">
                <i class="fas fa-circle-exclamation"></i>
                <span><?php echo $login_err; ?></span>
            </div>
        <?php endif; ?>

        <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
            <div class="form-group">
                <label>Username</label>
                <div class="input-box">
                    <input type="text" name="username" value="<?php echo $username; ?>" placeholder="Username kamu">
                    <i class="fas fa-user"></i>
                </div>
                <?php if (!empty($username_err)): ?>
                    <span class="invalid-feedback"><?php echo $username_err; ?></span>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label>Password</label>
                <div class="input-box">
                    <input type="password" name="password" placeholder="••••••••">
                    <i class="fas fa-lock"></i>
                </div>
                <?php if (!empty($password_err)): ?>
                    <span class="invalid-feedback"><?php echo $password_err; ?></span>
                <?php endif; ?>
            </div>

            <button type="submit" class="login-btn">
                Masuk Sekarang
            </button>

            <p class="footer-text">
                Belum punya akun? <a href="register.php">Daftar dulu</a>
            </p>
        </form>
    </div>
</body>

</html>