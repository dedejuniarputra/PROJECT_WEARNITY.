<?php
require_once 'config.php'; // Memuat konfigurasi database dan memulai session

// Cek apakah user sudah login dan memiliki role admin
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || $_SESSION['role'] !== 'admin') {
    header("location: index.php");
    exit;
}

// Tentukan halaman yang akan ditampilkan berdasarkan parameter GET 'page'
$currentPage = isset($_GET['page']) ? $_GET['page'] : 'dashboard';
$pageTitle = "Dashboard Admin"; // Default title for the top bar

// Variabel untuk pesan sukses/error
$success_message = "";
$error_message = "";

// ===============================================
// Logika untuk Halaman 'Dashboard'
// Ambil data statistik dari database untuk dashboard
// ===============================================
$total_products = 0;
$total_users = 0;
$total_revenue = 0;
$total_pending_orders = 0;
$completed_transactions_data = []; // Untuk tabel transaksi terbaru di dashboard

if ($currentPage == 'dashboard') {
    try {
        // Hitung Total Produk (hanya yang belum di-soft delete)
        $sql = "SELECT COUNT(id) FROM products WHERE is_deleted = 0"; //
        $stmt = $pdo->query($sql);
        $total_products = $stmt->fetchColumn();

        // Hitung Total Pengguna (hanya user biasa, bukan admin)
        $sql = "SELECT COUNT(id) FROM users WHERE role = 'user'";
        $stmt = $pdo->query($sql);
        $total_users = $stmt->fetchColumn();

        // Hitung Total Penjualan (dari transaksi berstatus 'Completed')
        $sql = "SELECT SUM(total_amount) FROM transactions WHERE status = 'Completed'";
        $stmt = $pdo->query($sql);
        $total_revenue = $stmt->fetchColumn();
        // Pastikan total_revenue bukan NULL jika belum ada penjualan
        $total_revenue = $total_revenue ?: 0;

        // Hitung Total Pesanan Tertunda (dari transaksi berstatus 'Pending')
        $sql = "SELECT COUNT(id) FROM transactions WHERE status = 'Pending'";
        $stmt = $pdo->query($sql);
        $total_pending_orders = $stmt->fetchColumn();

        // Query untuk mengambil transaksi yang statusnya 'Completed' untuk tabel di dashboard
        $sql_completed_transactions = "
            SELECT
                t.id AS transaction_id,
                t.transaction_code,
                t.total_amount,
                t.order_date,
                u.full_name AS username, -- Mengambil nama lengkap pengguna
                ti.quantity,
                ti.price_at_purchase,
                p.name AS product_name,
                p.image_path
            FROM
                transactions t
            JOIN
                users u ON t.user_id = u.id
            JOIN
                transaction_items ti ON t.id = ti.transaction_id
            JOIN
                products p ON ti.product_id = p.id
            WHERE
                t.status = 'Completed'
            ORDER BY
                t.order_date DESC, t.id DESC
            LIMIT 5 -- Batasi hanya 5 data terbaru di dashboard
        ";
        $stmt_completed = $pdo->prepare($sql_completed_transactions);
        $stmt_completed->execute();
        $raw_completed_transactions = $stmt_completed->fetchAll(PDO::FETCH_ASSOC);

        // Kelompokkan item-item ke dalam transaksi yang sama
        foreach ($raw_completed_transactions as $row) {
            $trans_id = $row['transaction_id'];
            if (!isset($completed_transactions_data[$trans_id])) {
                $completed_transactions_data[$trans_id] = [
                    'transaction_code' => $row['transaction_code'],
                    'total_amount' => $row['total_amount'],
                    'order_date' => $row['order_date'],
                    'username' => $row['username'],
                    'items' => []
                ];
            }
            $completed_transactions_data[$trans_id]['items'][] = [
                'product_name' => $row['product_name'],
                'image_path' => $row['image_path'],
                'quantity' => $row['quantity'],
                'price_at_purchase' => $row['price_at_purchase']
            ];
        }
    } catch (PDOException $e) {
        $error_message .= " Gagal memuat data statistik dashboard atau transaksi selesai: " . $e->getMessage();
        error_log("Dashboard stats/completed transactions error: " . $e->getMessage());
    }
}
// ===============================================
// END Logika untuk Halaman 'Dashboard'
// ===============================================


// --- Logika untuk Halaman 'Manajemen Produk' ---
$product_name = $product_price = "";
$product_brand = $product_tags = $product_description = "";
$is_available = true; // Default value for the checkbox
$name_err = $price_err = $image_err = "";
$brand_err = $tags_err = $description_err = "";
$target_dir = "uploads/";

$editingProductId = null; // ID produk yang sedang diedit
$productToEdit = null;    // Data produk yang sedang diedit

if ($currentPage == 'manajemen_produk') {
    $pageTitle = "Manajemen Produk"; // Update page title for this section

    // Aksi Edit Produk (mengisi form)
    if (isset($_GET['action']) && $_GET['action'] == 'edit_product' && isset($_GET['id'])) {
        $editingProductId = (int)$_GET['id'];
        $sql_select_product = "SELECT id, name, price, image_path, brand, tags, description, is_available FROM products WHERE id = :id";
        if ($stmt = $pdo->prepare($sql_select_product)) {
            $stmt->bindParam(":id", $editingProductId, PDO::PARAM_INT);
            if ($stmt->execute()) {
                if ($stmt->rowCount() == 1) {
                    $productToEdit = $stmt->fetch(PDO::FETCH_ASSOC);
                    $product_name = $productToEdit['name'];
                    $product_price = $productToEdit['price'];
                    $product_brand = $productToEdit['brand'];
                    $product_tags = $productToEdit['tags'];
                    $product_description = $productToEdit['description'];
                    $is_available = $productToEdit['is_available'];
                } else {
                    $error_message = "Produk tidak ditemukan.";
                    $editingProductId = null;
                }
            } else {
                $error_message = "Terjadi kesalahan saat mengambil data produk.";
                $editingProductId = null;
            }
            unset($stmt);
        }
    }

    // Aksi Hapus Produk (menggunakan soft delete)
    if (isset($_GET['action']) && $_GET['action'] == 'delete_product' && isset($_GET['id'])) {
        $deleteId = (int)$_GET['id'];
        try {
            // Update status is_deleted menjadi 1 (true)
            $sql_soft_delete = "UPDATE products SET is_deleted = 1 WHERE id = :id";
            if ($stmt_delete = $pdo->prepare($sql_soft_delete)) {
                $stmt_delete->bindParam(":id", $deleteId, PDO::PARAM_INT);
                if ($stmt_delete->execute()) {
                    $success_message = "Produk berhasil di-nonaktifkan (soft deleted).";
                } else {
                    $error_message = "Gagal menonaktifkan produk.";
                }
                unset($stmt_delete);
            }
        } catch (PDOException $e) {
            $error_message = "Terjadi kesalahan saat menonaktifkan produk: " . $e->getMessage();
            error_log("Soft delete error: " . $e->getMessage());
        }
        header("Location: admin.php?page=manajemen_produk&msg=" . urlencode($success_message ?: $error_message));
        exit();
    }

    // Proses ketika form tambah/update produk disubmit
    if ($_SERVER["REQUEST_METHOD"] == "POST" && $currentPage == 'manajemen_produk') {
        $is_update = isset($_POST['product_id']) && !empty($_POST['product_id']);
        $current_product_id = $is_update ? (int)$_POST['product_id'] : null;
        $old_image_path = '';

        if ($is_update) {
            $sql_get_old_image = "SELECT image_path FROM products WHERE id = :id";
            if ($stmt = $pdo->prepare($sql_get_old_image)) {
                $stmt->bindParam(":id", $current_product_id, PDO::PARAM_INT);
                if ($stmt->execute()) {
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);
                    $old_image_path = $row['image_path'];
                }
                unset($stmt);
            }
        }

        // Validasi dan ambil data
        if (empty(trim($_POST["product_name"]))) {
            $name_err = "Mohon masukkan nama produk.";
        } else {
            $product_name = trim($_POST["product_name"]);
        }
        if (empty(trim($_POST["product_price"]))) {
            $price_err = "Mohon masukkan harga produk.";
        } elseif (!is_numeric(trim($_POST["product_price"]))) {
            $price_err = "Harga harus berupa angka.";
        } else {
            $product_price = (int)trim($_POST["product_price"]);
        }
        if (empty(trim($_POST["product_brand"]))) {
            $brand_err = "Mohon masukkan nama brand.";
        } else {
            $product_brand = trim($_POST["product_brand"]);
        }
        $product_tags = trim($_POST["product_tags"]);
        $product_description = trim($_POST["product_description"]);
        $is_available = isset($_POST['is_available']) ? 1 : 0;

        $new_image_uploaded = !empty($_FILES["product_image"]["name"]);
        $image_path_to_save = $old_image_path;

        if ($new_image_uploaded) {
            $target_file = $target_dir . uniqid() . "-" . basename($_FILES["product_image"]["name"]);
            $imageFileType = strtolower(pathinfo($target_file, PATHINFO_EXTENSION));

            $check = getimagesize($_FILES["product_image"]["tmp_name"]);
            if ($check === false) {
                $image_err = "File bukan gambar.";
            }
            if ($_FILES["product_image"]["size"] > 5000000) {
                $image_err = "Ukuran gambar terlalu besar (maks 5MB).";
            }
            if ($imageFileType != "jpg" && $imageFileType != "png" && $imageFileType != "jpeg" && $imageFileType != "gif") {
                $image_err = "Hanya file JPG, JPEG, PNG & GIF yang diizinkan.";
            }

            if (empty($image_err)) {
                if (move_uploaded_file($_FILES["product_image"]["tmp_name"], $target_file)) {
                    $image_path_to_save = $target_file;
                    if ($is_update && !empty($old_image_path) && file_exists($old_image_path) && $old_image_path != $image_path_to_save) {
                        unlink($old_image_path);
                    }
                } else {
                    $image_err = "Maaf, terjadi kesalahan saat mengupload gambar Anda.";
                }
            }
        } elseif (!$is_update) { // Only require image if it's a new product
            $image_err = "Mohon pilih gambar produk.";
        }

        if (empty($name_err) && empty($price_err) && empty($brand_err) && empty($tags_err) && empty($description_err) && empty($image_err)) {
            if ($is_update) {
                $sql = "UPDATE products SET name = :name, price = :price, image_path = :image_path, brand = :brand, tags = :tags, description = :description, is_available = :is_available WHERE id = :id";
                if ($stmt = $pdo->prepare($sql)) {
                    $stmt->bindParam(":name", $product_name, PDO::PARAM_STR);
                    $stmt->bindParam(":price", $product_price, PDO::PARAM_INT);
                    $stmt->bindParam(":image_path", $image_path_to_save, PDO::PARAM_STR);
                    $stmt->bindParam(":brand", $product_brand, PDO::PARAM_STR);
                    $stmt->bindParam(":tags", $product_tags, PDO::PARAM_STR);
                    $stmt->bindParam(":description", $product_description, PDO::PARAM_STR);
                    $stmt->bindParam(":is_available", $is_available, PDO::PARAM_BOOL);
                    $stmt->bindParam(":id", $current_product_id, PDO::PARAM_INT);

                    if ($stmt->execute()) {
                        $success_message = "Produk berhasil diperbarui.";
                        $editingProductId = null;
                        header("Location: admin.php?page=manajemen_produk&msg=" . urlencode($success_message));
                        exit();
                    } else {
                        $error_message = "Terjadi kesalahan saat memperbarui data produk.";
                    }
                    unset($stmt);
                }
            } else {
                $sql = "INSERT INTO products (name, price, image_path, brand, tags, description, is_available) VALUES (:name, :price, :image_path, :brand, :tags, :description, :is_available)";
                if ($stmt = $pdo->prepare($sql)) {
                    $stmt->bindParam(":name", $product_name, PDO::PARAM_STR);
                    $stmt->bindParam(":price", $product_price, PDO::PARAM_INT);
                    $stmt->bindParam(":image_path", $image_path_to_save, PDO::PARAM_STR);
                    $stmt->bindParam(":brand", $product_brand, PDO::PARAM_STR);
                    $stmt->bindParam(":tags", $product_tags, PDO::PARAM_STR);
                    $stmt->bindParam(":description", $product_description, PDO::PARAM_STR);
                    $stmt->bindParam(":is_available", $is_available, PDO::PARAM_BOOL);

                    if ($stmt->execute()) {
                        $success_message = "Produk berhasil ditambahkan.";
                        $product_name = $product_price = "";
                        $product_brand = $product_tags = $product_description = "";
                        $is_available = true;
                    } else {
                        $error_message = "Terjadi kesalahan saat menyimpan data produk ke database.";
                    }
                    unset($stmt);
                }
            }
        }
    }

    // Ambil semua produk dari database (hanya yang belum di-soft delete)
    $products = [];
    $sql_select_products = "SELECT id, name, price, image_path, brand, tags, description, is_available FROM products WHERE is_deleted = 0 ORDER BY created_at DESC"; //
    if ($stmt_products = $pdo->prepare($sql_select_products)) {
        if ($stmt_products->execute()) {
            $products = $stmt_products->fetchAll(PDO::FETCH_ASSOC);
        }
        unset($stmt_products);
    }
}

// --- Logika untuk Halaman 'Manajemen Pesanan' ---
$all_transactions = [];
if ($currentPage == 'manajemen_pesanan') {
    $pageTitle = "Manajemen Pesanan";

    // Aksi update status pesanan
    if (isset($_GET['action']) && $_GET['action'] == 'update_status' && isset($_GET['id']) && isset($_GET['status'])) {
        $transactionId = (int)$_GET['id'];
        $newStatus = htmlspecialchars($_GET['status']);

        $sql_update_status = "UPDATE transactions SET status = :status WHERE id = :id";
        if ($stmt = $pdo->prepare($sql_update_status)) {
            $stmt->bindParam(":status", $newStatus, PDO::PARAM_STR);
            $stmt->bindParam(":id", $transactionId, PDO::PARAM_INT);
            if ($stmt->execute()) {
                if ($newStatus == 'Completed') {
                    $success_message = "Status pesanan berhasil diubah menjadi 'Completed'.";
                    // IDEALNYA: Kirim email notifikasi ke pengguna bahwa pesanan telah berhasil dikirim/diproses
                    // Contoh: sendEmailToUser($user_email, "Pesanan Anda #{$transactionCode} Telah Selesai!", "Halo, pesanan Anda dengan kode {$transactionCode} telah selesai dan dalam perjalanan. Terima kasih!");
                } elseif ($newStatus == 'Cancelled') {
                    $success_message = "Status pesanan berhasil diubah menjadi 'Cancelled'.";
                    // IDEALNYA: Kirim email notifikasi ke pengguna bahwa pesanan dibatalkan (mungkin disertai alasan jika ada fitur alasan pembatalan)
                    // Contoh: sendEmailToUser($user_email, "Pesanan Anda #{$transactionCode} Dibatalkan", "Maaf, pesanan Anda dengan kode {$transactionCode} telah dibatalkan karena bukti pembayaran tidak sesuai. Mohon hubungi CS jika ada pertanyaan.");
                } else { // Pending atau status lainnya
                    $success_message = "Status pesanan berhasil diperbarui!";
                }
            } else {
                $error_message = "Gagal memperbarui status pesanan.";
            }
            unset($stmt);
        }
        // Redirect kembali ke halaman manajemen pesanan dengan pesan
        header("Location: admin.php?page=manajemen_pesanan&msg=" . urlencode($success_message ?: $error_message));
        exit();
    }

    try {
        // Query untuk mengambil semua detail transaksi (transaksi + item)
        // Gabungkan dengan tabel users untuk mendapatkan username/nama pengguna
        // DAN tambahkan proof_image_path
        $sql_all_transactions = "
            SELECT
                t.id AS transaction_id,
                t.transaction_code,
                t.total_amount,
                t.status,
                t.order_date,
                t.shipping_courier,
                t.payment_proof_image AS proof_image_path,
                u.username,
                u.full_name,
                ti.quantity,
                ti.price_at_purchase,
                p.name AS product_name,
                p.image_path
            FROM
                transactions t
            JOIN
                users u ON t.user_id = u.id
            JOIN
                transaction_items ti ON t.id = ti.transaction_id
            JOIN
                products p ON ti.product_id = p.id
            ORDER BY
                t.order_date DESC, t.id DESC
        ";
        $stmt = $pdo->prepare($sql_all_transactions);
        $stmt->execute();
        $raw_all_transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Kelompokkan item-item ke dalam transaksi yang sama
        foreach ($raw_all_transactions as $row) {
            $trans_id = $row['transaction_id'];
            if (!isset($all_transactions[$trans_id])) {
                $all_transactions[$trans_id] = [
                    'transaction_code' => $row['transaction_code'],
                    'total_amount' => $row['total_amount'],
                    'status' => $row['status'],
                    'order_date' => $row['order_date'],
                    'username' => $row['username'],
                    'full_name' => $row['full_name'],
                    'shipping_courier' => $row['shipping_courier'],
                    'proof_image_path' => $row['proof_image_path'], // Ambil bukti pembayaran
                    'items' => []
                ];
            }
            $all_transactions[$trans_id]['items'][] = [
                'product_name' => $row['product_name'],
                'image_path' => $row['image_path'],
                'quantity' => $row['quantity'],
                'price_at_purchase' => $row['price_at_purchase'],
                'item_subtotal' => $row['quantity'] * $row['price_at_purchase']
            ];
        }
    } catch (PDOException $e) {
        $error_message .= " Gagal memuat data pesanan: " . $e->getMessage();
        error_log("Admin transactions load error: " . $e->getMessage());
    }
}


// --- Logika untuk Halaman 'Data Admin' ---
$admin_users = [];
if ($currentPage == 'data_admin') {
    $pageTitle = "Data Admin";
    try {
        $sql_admins = "SELECT id, username, full_name, email, phone_number, address FROM users WHERE role = 'admin' ORDER BY id ASC";
        $stmt_admins = $pdo->prepare($sql_admins);
        $stmt_admins->execute();
        $admin_users = $stmt_admins->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $error_message .= " Gagal mengambil data admin: " . $e->getMessage();
    }
}

// --- Logika untuk Halaman 'Data Pengguna' ---
$regular_users = [];
if ($currentPage == 'data_pengguna') {
    $pageTitle = "Data Pengguna";
    if (isset($_GET['action']) && $_GET['action'] == 'delete_user' && isset($_GET['id'])) {
        $deleteUserId = (int)$_GET['id'];
        $sql_check_role = "SELECT role FROM users WHERE id = :id";
        if ($stmt_check_role = $pdo->prepare($sql_check_role)) {
            $stmt_check_role->bindParam(":id", $deleteUserId, PDO::PARAM_INT);
            if ($stmt_check_role->execute() && $stmt_check_role->rowCount() == 1) {
                $user_role_to_delete = $stmt_check_role->fetchColumn();
                if ($user_role_to_delete === 'user') {
                    $sql_delete_user = "DELETE FROM users WHERE id = :id";
                    if ($stmt_delete_user = $pdo->prepare($sql_delete_user)) {
                        $stmt_delete_user->bindParam(":id", $deleteUserId, PDO::PARAM_INT);
                        if ($stmt_delete_user->execute()) {
                            $success_message = "Pengguna berhasil dihapus.";
                        } else {
                            $error_message = "Gagal menghapus pengguna dari database.";
                        }
                        unset($stmt_delete_user);
                    }
                } else {
                    $error_message = "Tidak diizinkan menghapus akun admin dari sini.";
                }
            } else {
                $error_message = "Pengguna tidak ditemukan.";
            }
            unset($stmt_check_role);
        }
        header("Location: admin.php?page=data_pengguna&msg=" . urlencode($success_message ?: $error_message));
        exit();
    }
    try {
        $sql_users = "SELECT id, username, full_name, email, phone_number, address FROM users WHERE role = 'user' ORDER BY id ASC";
        $stmt_users = $pdo->prepare($sql_users);
        $stmt_users->execute();
        $regular_users = $stmt_users->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $error_message .= " Gagal mengambil data pengguna: " . $e->getMessage();
    }
}

// Menampilkan pesan dari redirect
if (isset($_GET['msg']) && !empty($_GET['msg'])) {
    if (strpos($_GET['msg'], 'berhasil') !== false || strpos($_GET['msg'], 'Berhasil') !== false) {
        $success_message = $_GET['msg'];
    } else {
        $error_message = $_GET['msg'];
    }
}

// Mengatur pageTitle untuk Dashboard dan halaman lain yang tidak ada di sidebar
if ($currentPage == 'dashboard') {
    $pageTitle = "Dashboard Admin";
} elseif ($currentPage == 'data_admin') {
    $pageTitle = "Data Admin";
} elseif ($currentPage == 'verifikasi_akun') {
    $pageTitle = "Verifikasi Akun Pengguna";
} elseif ($currentPage == 'data_pengaduan') {
    $pageTitle = "Data Pengaduan";
}

unset($pdo); // Tutup koneksi database SETELAH semua data dipakai
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel | <?php echo htmlspecialchars($pageTitle); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="css/style_admin.css">
    <style>
        /* ===================================== */
        /* NEW CSS START: Dashboard Card Styles  */
        /* ===================================== */
        .dashboard-summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            /* Responsive columns */
            gap: 20px;
            /* Space between cards */
            margin-top: 20px;
            /* Space from section header */
            margin-bottom: 30px;
            /* Space above footer */
        }

        .dashboard-card {
            background-color: #ffffff;
            /* White background */
            border-radius: 10px;
            /* Rounded corners */
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.05);
            /* Subtle shadow */
            padding: 25px;
            /* Internal spacing */
            display: flex;
            /* For icon and content layout */
            align-items: center;
            /* Vertically align items */
            gap: 15px;
            /* Space between icon and text */
            transition: transform 0.2s ease-in-out;
            /* Smooth hover effect */
        }

        .dashboard-card:hover {
            transform: translateY(-5px);
            /* Lift effect on hover */
        }

        .card-icon {
            /* Default background - these will be overridden by specific colors */
            background-color: #6a6aed;
            /* Blue-ish purple, similar to current design */
            color: white;
            /* White icon */
            border-radius: 8px;
            /* Slightly rounded corners for icon background */
            width: 60px;
            /* Fixed width */
            height: 60px;
            /* Fixed height to make it square */
            display: flex;
            justify-content: center;
            align-items: center;
            font-size: 2em;
            /* Large icon size */
            flex-shrink: 0;
            /* Prevent icon from shrinking */
        }

        /* Specific icon background colors for each card to match sample image 2 */
        /* You can adjust these colors to match your brand or the exact sample */
        .dashboard-card.products .card-icon {
            background-color: #5b9bd5;
            /* Example: Blue */
        }

        .dashboard-card.users .card-icon {
            background-color: #5cb85c;
            /* Example: Green */
        }

        .dashboard-card.revenue .card-icon {
            background-color: #f0ad4e;
            /* Example: Orange/Amber */
        }

        .dashboard-card.pending-orders .card-icon {
            background-color: #d9534f;
            /* Example: Red */
        }


        .card-content {
            flex-grow: 1;
            /* Allow content to take available space */
            text-align: right;
            /* Align text to the right */
        }

        .card-label {
            display: block;
            /* Ensure label is on its own line */
            font-size: 0.9em;
            color: #888;
            /* Lighter color for the label */
            margin-bottom: 5px;
            /* Space below label */
            font-weight: 600;
            /* Slightly bolder */
        }

        .card-value {
            display: block;
            /* Ensure value is on its own line */
            font-size: 1.6em;
            /* Mengurangi ukuran font dari 2.2em ke 1.6em */
            font-weight: bold;
            color: #333;
            /* Darker color for the value */
            line-height: 1;
            /* Adjust line height if numbers are tall */
        }

        /* Responsive adjustments for dashboard cards */
        @media (max-width: 992px) {
            .dashboard-summary-grid {
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            }
        }

        @media (max-width: 600px) {
            .dashboard-summary-grid {
                grid-template-columns: 1fr;
                /* Stack cards vertically on very small screens */
            }

            .dashboard-card {
                flex-direction: row;
                /* Keep icon and text side-by-side or adjust if needed */
                justify-content: flex-start;
                /* Align content to left */
                text-align: left;
                /* Align text within card content to left */
            }

            .card-content {
                text-align: left;
                /* Override previous right-alignment for card content */
            }
        }

        /* ===================================== */
        /* NEW CSS END: Dashboard Card Styles    */
        /* ===================================== */


        /* CSS Tambahan untuk Manajemen Pesanan (Existing Styles) */
        .transaction-admin-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        .transaction-admin-table th,
        .transaction-admin-table td {
            border: 1px solid #ddd;
            padding: 10px;
            text-align: left;
            vertical-align: top;
        }

        .transaction-admin-table th {
            background-color: #f2f2f2;
            color: #555;
            font-weight: bold;
        }

        .transaction-admin-table tbody tr:nth-child(even) {
            background-color: #fdfdfd;
        }

        .transaction-admin-table .product-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .transaction-admin-table .product-item {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 5px;
            padding-bottom: 5px;
            border-bottom: 1px dashed #eee;
        }

        .transaction-admin-table .product-item:last-child {
            margin-bottom: 0;
            padding-bottom: 0;
            border-bottom: none;
        }

        .transaction-admin-table .product-item img {
            width: 40px;
            height: 40px;
            object-fit: cover;
            border-radius: 3px;
        }

        .transaction-admin-table .product-info {
            font-size: 0.9em;
        }

        .transaction-admin-table .product-info strong {
            display: block;
            font-size: 1em;
            color: #333;
        }

        .transaction-admin-table .product-info span {
            color: #777;
        }

        .status-dropdown {
            padding: 5px;
            border-radius: 4px;
            border: 1px solid #ccc;
            font-size: 0.9em;
            cursor: pointer;
            background-color: white;
            min-width: 100px;
        }

        .status-dropdown.Pending {
            background-color: #ffc107;
            color: #856404;
            border-color: #ffeeba;
        }

        .status-dropdown.Completed {
            background-color: #28a745;
            color: white;
            border-color: #28a745;
        }

        .status-dropdown.Cancelled {
            background-color: #dc3545;
            color: white;
            border-color: #dc3545;
        }

        .status-dropdown option {
            color: black;
            background-color: white;
        }

        /* Modal Style for Proof Image */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0, 0, 0, 0.7);
            justify-content: center;
            align-items: center;
            padding: 20px;
            box-sizing: border-box;
        }

        .modal-content {
            background-color: #fefefe;
            margin: auto;
            padding: 20px;
            border: 1px solid #888;
            border-radius: 8px;
            width: 90%;
            max-width: 600px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
            position: relative;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
            margin-bottom: 15px;
        }

        .modal-header h3 {
            margin: 0;
            font-size: 1.2em;
            color: #333;
        }

        .close-button {
            color: #aaa;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            line-height: 1;
        }

        .close-button:hover,
        .close-button:focus {
            color: black;
            text-decoration: none;
            cursor: pointer;
        }

        .modal-body {
            text-align: center;
        }

        .modal-body img {
            max-width: 100%;
            height: auto;
            display: block;
            margin: 0 auto;
            border: 1px solid #ddd;
            border-radius: 4px;
        }

        .modal-body p.no-image {
            color: #888;
            font-style: italic;
            margin-top: 15px;
        }


        /* Responsive table */
        @media (max-width: 768px) {
            .transaction-admin-table thead {
                display: none;
            }

            .transaction-admin-table tbody,
            .transaction-admin-table tr {
                display: block;
                margin-bottom: 20px;
                border: 1px solid #ddd;
                border-radius: 8px;
            }

            .transaction-admin-table td {
                display: block;
                width: 100%;
                box-sizing: border-box;
                text-align: right;
                padding-left: 50%;
                position: relative;
                border: none;
                border-bottom: 1px solid #eee;
            }

            .transaction-admin-table td:last-child {
                border-bottom: none;
            }

            .transaction-admin-table td::before {
                content: attr(data-label);
                position: absolute;
                left: 10px;
                width: 45%;
                white-space: nowrap;
                text-align: left;
                font-weight: bold;
                color: #555;
            }

            .transaction-admin-table .product-item {
                flex-direction: column;
                align-items: flex-start;
            }

            .transaction-admin-table .product-item img {
                margin-bottom: 5px;
            }
        }

        /* General styles for data tables (Manajemen Produk, Data Pengguna, Data Admin) */
        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
            border-radius: 8px;
            overflow: hidden;
            /* Ensures rounded corners apply to content */
        }

        .data-table th,
        .data-table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }

        .data-table thead th {
            background-color: #f8f8f8;
            color: #666;
            font-weight: bold;
            text-transform: uppercase;
            font-size: 0.9em;
        }

        .data-table tbody tr:nth-child(even) {
            background-color: #fdfdfd;
        }

        .data-table tbody tr:hover {
            background-color: #f2f2f2;
        }

        .data-table td img {
            width: 50px;
            height: 50px;
            object-fit: cover;
            border-radius: 5px;
            border: 1px solid #ddd;
        }

        .data-table .action-buttons a {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 5px;
            text-decoration: none;
            font-size: 0.85em;
            margin-right: 5px;
            transition: background-color 0.2s ease;
        }

        .data-table .action-buttons .edit-btn {
            background-color: #007bff;
            color: white;
        }

        .data-table .action-buttons .edit-btn:hover {
            background-color: #0056b3;
        }

        .data-table .action-buttons .delete-btn {
            background-color: #dc3545;
            color: white;
        }

        .data-table .action-buttons .delete-btn:hover {
            background-color: #c82333;
        }

        .status-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.8em;
            font-weight: 600;
            color: white;
            text-align: center;
        }

        .status-badge.tersedia {
            background-color: #28a745;
            /* Green */
        }

        .status-badge.habis {
            background-color: #6c757d;
            /* Gray */
        }

        .product-tags-admin span {
            display: inline-block;
            background-color: #e9ecef;
            color: #495057;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 0.75em;
            margin-right: 5px;
            margin-bottom: 5px;
        }

        /* Form styles (Manajemen Produk) */
        form {
            background-color: #ffffff;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.05);
            margin-bottom: 30px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #555;
        }

        .form-group input[type="text"],
        .form-group input[type="number"],
        .form-group input[type="file"],
        .form-group textarea {
            width: calc(100% - 22px);
            /* Account for padding and border */
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 1em;
            box-sizing: border-box;
            /* Include padding and border in width */
        }

        .form-group textarea {
            min-height: 100px;
            resize: vertical;
        }

        .form-group input[type="file"] {
            padding: 5px;
            background-color: #f8f8f8;
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            margin-top: 15px;
        }

        .checkbox-group input[type="checkbox"] {
            margin-right: 10px;
            width: auto;
            /* Override default input width */
        }

        .invalid-feedback {
            color: #dc3545;
            font-size: 0.85em;
            margin-top: 5px;
            display: block;
        }

        .btn-submit {
            background-color: #28a745;
            color: white;
            padding: 12px 25px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 1em;
            transition: background-color 0.2s ease;
        }

        .btn-submit:hover {
            background-color: #218838;
        }

        /* Alerts */
        .alert {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 8px;
            font-size: 0.95em;
            display: flex;
            align-items: center;
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

        .add-button {
            background-color: #007bff;
            /* Blue for add button */
            color: white;
            padding: 8px 15px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 0.9em;
            transition: background-color 0.2s ease;
        }

        .add-button:hover {
            background-color: #0056b3;
        }

        /* Top bar and sidebar already seem to have good base styles. */
        /* Ensure sidebar-header strong and sidebar-nav li a are styled correctly in style_admin.css */

        /* General layout adjustments for better spacing */
        .main-content-panel {
            padding: 20px;
            background-color: #f4f7f6;
            /* Light gray background for the main content area */
            min-height: calc(100vh - 70px);
            /* Adjust based on top-bar height */
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 1px solid #eee;
        }

        .section-header h2,
        .section-header h3 {
            margin: 0;
            color: #333;
            font-size: 1.8em;
            font-weight: 600;
        }

        .section-header h3 {
            font-size: 1.5em;
        }

        /* Styles for the new table on the Dashboard */
        .dashboard-transactions-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
            border-radius: 8px;
            overflow: hidden;
        }

        .dashboard-transactions-table th,
        .dashboard-transactions-table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #eee;
            vertical-align: middle;
        }

        .dashboard-transactions-table thead th {
            background-color: #f8f8f8;
            color: #666;
            font-weight: bold;
            text-transform: uppercase;
            font-size: 0.9em;
        }

        .dashboard-transactions-table tbody tr:nth-child(even) {
            background-color: #fdfdfd;
        }

        .dashboard-transactions-table tbody tr:hover {
            background-color: #f2f2f2;
        }

        /* Styling for product list inside the dashboard table (restored) */
        .dashboard-transactions-table .product-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .product-item-dashboard {
            display: flex;
            align-items: center;
            gap: 10px;
            /* Jarak antara gambar dan teks */
            margin-bottom: 5px;
            padding-bottom: 5px;
            border-bottom: 1px dashed #f0f0f0;
        }

        .product-item-dashboard:last-child {
            margin-bottom: 0;
            padding-bottom: 0;
            border-bottom: none;
        }

        .product-item-dashboard img {
            width: 40px;
            /* Ukuran gambar produk seperti di contoh */
            height: 40px;
            /* Ukuran gambar produk seperti di contoh */
            object-fit: cover;
            border-radius: 5px;
            border: 1px solid #ddd;
            display: block;
            /* Pastikan gambar ditampilkan kembali */
        }

        .product-info-dashboard {
            flex-grow: 1;
            font-size: 0.85em;
            line-height: 1.4;
        }

        .product-info-dashboard strong {
            display: block;
            /* Nama produk di baris baru */
            color: #333;
            font-size: 1em;
        }

        .product-info-dashboard span {
            color: #777;
            /* Warna teks untuk kuantitas dan harga satuan */
            font-size: 0.9em;
            /* Ukuran font untuk kuantitas dan harga */
        }


        .status-badge.completed-badge {
            background-color: #28a745;
            /* Green color for completed status */
            color: white;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.8em;
            font-weight: 600;
        }

        .view-all-button {
            background-color: #007bff;
            color: white;
            padding: 8px 15px;
            border-radius: 5px;
            text-decoration: none;
            font-size: 0.9em;
            transition: background-color 0.2s ease;
        }

        .view-all-button:hover {
            background-color: #0056b3;
        }

        /* Responsive table for dashboard */
        @media (max-width: 768px) {
            .dashboard-transactions-table thead {
                display: none;
            }

            .dashboard-transactions-table tbody,
            .dashboard-transactions-table tr {
                display: block;
                margin-bottom: 20px;
                border: 1px solid #ddd;
                border-radius: 8px;
            }

            .dashboard-transactions-table td {
                display: block;
                width: 100%;
                box-sizing: border-box;
                text-align: right;
                padding-left: 50%;
                position: relative;
                border: none;
                border-bottom: 1px solid #eee;
            }

            .dashboard-transactions-table td:last-child {
                border-bottom: none;
            }

            .dashboard-transactions-table td::before {
                content: attr(data-label);
                position: absolute;
                left: 10px;
                width: 45%;
                white-space: nowrap;
                text-align: left;
                font-weight: bold;
                color: #555;
            }

            .product-item-dashboard {
                flex-direction: row;
                /* Tetap row untuk mobile agar lebih kompak */
                align-items: center;
                justify-content: flex-end;
                /* Pindahkan konten ke kanan */
                text-align: right;
                /* Pastikan teks di kanan */
            }

            .product-item-dashboard strong,
            .product-item-dashboard span {
                display: inline;
                /* Biarkan nama produk dan kuantitas sebaris */
            }

            .product-info-dashboard {
                text-align: right;
                /* Pastikan teks tetap di kanan */
            }

            .product-item-dashboard img {
                /* Aktifkan kembali gambar untuk tampilan mobile juga */
                display: block;
                /* Pastikan gambar ditampilkan kembali */
                margin-right: 10px;
                /* Beri sedikit jarak dari teks */
                margin-bottom: 0;
                /* Hapus margin bawah yang mungkin ada sebelumnya */
            }
        }
    </style>
</head>

<body>
    <aside class="sidebar">
        <div class="sidebar-header">
            <strong style="font-size: 28px; color: #fff;">WEARNITY.</strong>
        </div>
        <nav class="sidebar-nav">
            <ul>
                <li class="<?php echo ($currentPage == 'dashboard' ? 'active' : ''); ?>">
                    <a href="admin.php?page=dashboard"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                </li>
                <li class="<?php echo ($currentPage == 'manajemen_produk' ? 'active' : ''); ?>">
                    <a href="admin.php?page=manajemen_produk"><i class="fas fa-box-open"></i> Manajemen Produk</a>
                </li>
                <li class="<?php echo ($currentPage == 'manajemen_pesanan' ? 'active' : ''); ?>">
                    <a href="admin.php?page=manajemen_pesanan"><i class="fas fa-receipt"></i> Manajemen Pesanan</a>
                </li>
                <li class="<?php echo ($currentPage == 'data_pengguna' ? 'active' : ''); ?>">
                    <a href="admin.php?page=data_pengguna"><i class="fas fa-users"></i> Data Pengguna</a>
                </li>
            </ul>
        </nav>
    </aside>

    <div class="content-area">
        <nav class="top-bar">
            <div class="page-title"><?php echo htmlspecialchars($pageTitle); ?></div>
            <div class="admin-profile-dropdown">
                <button class="admin-btn" id="adminProfileBtn">
                    <?php echo htmlspecialchars($_SESSION["username"]); ?>
                    <i class="fas fa-caret-down"></i>
                </button>
                <div class="admin-profile-content" id="adminProfileDropdown">
                    <a href="logout.php">Logout</a>
                </div>
            </div>
        </nav>

        <main class="main-content-panel">
            <?php
            if (!empty($success_message)) {
                echo '<div class="alert alert-success">' . htmlspecialchars($success_message) . '</div>';
            }
            if (!empty($error_message)) {
                echo '<div class="alert alert-danger">' . htmlspecialchars($error_message) . '</div>';
            }
            ?>

            <?php if ($currentPage == 'dashboard'): ?>
                <div class="section-header">
                    <h2>Dashboard Admin</h2>
                </div>

                <div class="dashboard-summary-grid">
                    <div class="dashboard-card products">
                        <div class="card-icon"><i class="fas fa-box-open"></i></div>
                        <div class="card-content">
                            <span class="card-label">Total Produk</span>
                            <span class="card-value"><?php echo number_format($total_products, 0, ',', '.'); ?></span>
                        </div>
                    </div>
                    <div class="dashboard-card users">
                        <div class="card-icon"><i class="fas fa-users"></i></div>
                        <div class="card-content">
                            <span class="card-label">Total Pengguna</span>
                            <span class="card-value"><?php echo number_format($total_users, 0, ',', '.'); ?></span>
                        </div>
                    </div>
                    <div class="dashboard-card revenue">
                        <div class="card-icon"><i class="fas fa-dollar-sign"></i></div>
                        <div class="card-content">
                            <span class="card-label">Total Penjualan (Selesai)</span>
                            <span class="card-value">Rp <?php echo number_format($total_revenue, 0, ',', '.'); ?></span>
                        </div>
                    </div>
                    <div class="dashboard-card pending-orders">
                        <div class="card-icon"><i class="fas fa-hourglass-half"></i></div>
                        <div class="card-content">
                            <span class="card-label">Pesanan Tertunda</span>
                            <span class="card-value"><?php echo number_format($total_pending_orders, 0, ',', '.'); ?></span>
                        </div>
                    </div>
                </div>

                <div class="section-header" style="margin-top: 40px;">
                    <h3>Transaksi Terbaru (Completed)</h3>
                    <a href="admin.php?page=manajemen_pesanan" class="view-all-button">Lihat Semua Transaksi</a>
                </div>

                <?php if (!empty($completed_transactions_data)): ?>
                    <div class="table-container">
                        <table class="data-table dashboard-transactions-table">
                            <thead>
                                <tr>
                                    <th>No</th>
                                    <th>Nama Pengguna</th>
                                    <th>Produk & Kuantitas</th>
                                    <th>Status</th>
                                    <th>Total Harga</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $no = 1; ?>
                                <?php foreach ($completed_transactions_data as $trans_id => $transaction): ?>
                                    <tr>
                                        <td data-label="No"><?php echo $no++; ?></td>
                                        <td data-label="Nama Pengguna"><?php echo htmlspecialchars($transaction['username']); ?></td>
                                        <td data-label="Produk & Kuantitas">
                                            <ul class="product-list">
                                                <?php foreach ($transaction['items'] as $item): ?>
                                                    <li class="product-item-dashboard">
                                                        <img src="<?php echo htmlspecialchars($item['image_path']); ?>" alt="<?php echo htmlspecialchars($item['product_name']); ?>">
                                                        <div class="product-info-dashboard">
                                                            <strong><?php echo htmlspecialchars($item['product_name']); ?></strong>
                                                            <span>x<?php echo htmlspecialchars($item['quantity']); ?> (Rp <?php echo number_format($item['price_at_purchase'], 0, ',', '.'); ?>)</span>
                                                        </div>
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>
                                        </td>
                                        <td data-label="Status"><span class="status-badge completed-badge">Completed</span></td>
                                        <td data-label="Total Harga">Rp <?php echo number_format($transaction['total_amount'], 0, ',', '.'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="alert alert-info" style="text-align: center;">Tidak ada transaksi completed terbaru.</p>
                <?php endif; ?>

                <p style="text-align: center; margin-top: 50px;">Copyright © 2025 - Wearnity by THANKSINSOMNIA</p>

            <?php elseif ($currentPage == 'manajemen_produk'): ?>
                <div class="section-header">
                    <h2>Manajemen Produk</h2>
                </div>

                <h3><?php echo ($editingProductId ? 'Edit Produk' : 'Tambah Produk Baru'); ?></h3>
                <form action="admin.php?page=manajemen_produk<?php echo ($editingProductId ? '&action=edit_product&id=' . $editingProductId : ''); ?>" method="post" enctype="multipart/form-data">
                    <?php if ($editingProductId): ?>
                        <input type="hidden" name="product_id" value="<?php echo htmlspecialchars($editingProductId); ?>">
                    <?php endif; ?>
                    <div class="form-group">
                        <label for="product_name">Nama Produk</label>
                        <input type="text" id="product_name" name="product_name" value="<?php echo htmlspecialchars($product_name); ?>">
                        <span class="invalid-feedback"><?php echo $name_err; ?></span>
                    </div>
                    <div class="form-group">
                        <label for="product_brand">Brand</label>
                        <input type="text" id="product_brand" name="product_brand" value="<?php echo htmlspecialchars($product_brand); ?>">
                        <span class="invalid-feedback"><?php echo $brand_err; ?></span>
                    </div>
                    <div class="form-group">
                        <label for="product_price">Harga Produk (Rp)</label>
                        <input type="number" id="product_price" name="product_price" value="<?php echo htmlspecialchars($product_price); ?>">
                        <span class="invalid-feedback"><?php echo $price_err; ?></span>
                    </div>
                    <div class="form-group">
                        <label for="product_tags">Tags (Pisahkan dengan koma, cth: #unisex, #hitam)</label>
                        <input type="text" id="product_tags" name="product_tags" value="<?php echo htmlspecialchars($product_tags); ?>">
                        <span class="invalid-feedback"><?php echo $tags_err; ?></span>
                    </div>
                    <div class="form-group">
                        <label for="product_description">Deskripsi Produk</label>
                        <textarea id="product_description" name="product_description"><?php echo htmlspecialchars($product_description); ?></textarea>
                        <span class="invalid-feedback"><?php echo $description_err; ?></span>
                    </div>
                    <div class="form-group checkbox-group">
                        <input type="checkbox" id="is_available" name="is_available" <?php echo ($is_available ? 'checked' : ''); ?>>
                        <label for="is_available">Produk Tersedia</label>
                    </div>
                    <div class="form-group">
                        <label for="product_image">Gambar Produk <?php echo ($editingProductId ? '(Kosongkan jika tidak ingin mengubah)' : ''); ?></label>
                        <input type="file" id="product_image" name="product_image" accept="image/*">
                        <span class="invalid-feedback"><?php echo $image_err; ?></span>
                        <?php if ($editingProductId && $productToEdit && $productToEdit['image_path']): ?>
                            <p style="margin-top: 10px;">Gambar saat ini: <img src="<?php echo htmlspecialchars($productToEdit['image_path']); ?>" alt="Current Product Image" style="width: 100px; height: 100px; object-fit: cover; vertical-align: middle; border: 1px solid #ddd;"></p>
                        <?php endif; ?>
                    </div>
                    <div class="form-group">
                        <button type="submit" name="add_product" class="btn-submit"><?php echo ($editingProductId ? 'Update Produk' : 'Tambah Produk'); ?></button>
                    </div>
                </form>

                <hr style="margin: 50px 0; border: 0; border-top: 1px solid #eee;">

                <h3>Daftar Produk</h3>
                <?php
                $no_prod = 1;
                if (!empty($products)): ?>
                    <table class="data-table product-table">
                        <thead>
                            <tr>
                                <th>No</th>
                                <th>Gambar</th>
                                <th>Nama Produk</th>
                                <th>Brand</th>
                                <th>Harga</th>
                                <th>Tags</th>
                                <th>Deskripsi</th>
                                <th>Status</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($products as $product): ?>
                                <tr>
                                    <td data-label="No"><?php echo $no_prod++; ?></td>
                                    <td data-label="Gambar"><img src="<?php echo htmlspecialchars($product['image_path']); ?>" alt="<?php echo htmlspecialchars($product['name']); ?>"></td>
                                    <td data-label="Nama Produk"><?php echo htmlspecialchars($product['name']); ?></td>
                                    <td data-label="Brand"><?php echo !empty($product['brand']) ? htmlspecialchars($product['brand']) : '-'; ?></td>
                                    <td data-label="Harga">Rp <?php echo number_format($product['price'], 0, ',', '.'); ?></td>
                                    <td data-label="Tags" class="product-tags-admin">
                                        <?php
                                        if (!empty($product['tags'])) {
                                            $tags_array = explode(',', $product['tags']);
                                            foreach ($tags_array as $tag) {
                                                echo '<span>' . htmlspecialchars(trim($tag)) . '</span>';
                                            }
                                        } else {
                                            echo '-';
                                        }
                                        ?>
                                    </td>
                                    <td data-label="Deskripsi"><?php echo !empty($product['description']) ? htmlspecialchars(substr($product['description'], 0, 100)) . (strlen($product['description']) > 100 ? '...' : '') : '-'; ?></td>
                                    <td data-label="Status">
                                        <?php
                                        if ($product['is_available']) {
                                            echo '<span class="status-badge tersedia">Tersedia</span>';
                                        } else {
                                            echo '<span class="status-badge habis">Stok Habis</span>';
                                        }
                                        ?>
                                    </td>
                                    <td data-label="Aksi" class="action-buttons">
                                        <a href="admin.php?page=manajemen_produk&action=edit_product&id=<?php echo $product['id']; ?>" class="edit-btn">Edit</a>
                                        <a href="admin.php?page=manajemen_produk&action=delete_product&id=<?php echo $product['id']; ?>"
                                            class="delete-btn"
                                            onclick="return confirm('Apakah Anda yakin ingin menonaktifkan produk ini? Ini akan menyembunyikannya dari tampilan pelanggan, namun riwayat transaksi tetap ada.');">Nonaktifkan</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="9" style="text-align: center;">Tidak ada produk yang ditambahkan.</td>
                            </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>

                <?php elseif ($currentPage == 'manajemen_pesanan'): ?>
                    <div class="section-header">
                        <h2>Manajemen Pesanan</h2>
                    </div>
                    <?php if (empty($all_transactions)): ?>
                        <p class="alert alert-info" style="text-align: center;">Tidak ada data pesanan yang tersedia.</p>
                    <?php else: ?>
                        <table class="transaction-admin-table">
                            <thead>
                                <tr>
                                    <th>No</th>
                                    <th>Kode Transaksi</th>
                                    <th>Pelanggan</th>
                                    <th>Tanggal Pesanan</th>
                                    <th>Kurir</th>
                                    <th>Produk & Kuantitas</th>
                                    <th>Total</th>
                                    <th>Status</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $no_trans = 1; ?>
                                <?php foreach ($all_transactions as $trans_id => $transaction): ?>
                                    <tr>
                                        <td data-label="No"><?php echo $no_trans++; ?></td>
                                        <td data-label="Kode Transaksi">#<?php echo htmlspecialchars($transaction['transaction_code']); ?></td>
                                        <td data-label="Pelanggan">
                                            <strong><?php echo htmlspecialchars($transaction['full_name']); ?></strong><br> <small><?php echo htmlspecialchars($transaction['username']); ?></small>
                                        </td>
                                        <td data-label="Tanggal Pesanan"><?php echo date('d M Y, H:i', strtotime($transaction['order_date'])); ?></td>
                                        <td data-label="Kurir"><?php echo !empty($transaction['shipping_courier']) ? htmlspecialchars($transaction['shipping_courier']) : '-'; ?></td>
                                        <td data-label="Produk & Kuantitas">
                                            <ul class="product-list">
                                                <?php foreach ($transaction['items'] as $item): ?>
                                                    <li class="product-item">
                                                        <img src="<?php echo htmlspecialchars($item['image_path']); ?>" alt="<?php echo htmlspecialchars($item['product_name']); ?>">
                                                        <div class="product-info">
                                                            <strong><?php echo htmlspecialchars($item['product_name']); ?></strong>
                                                            <span>(x<?php echo htmlspecialchars($item['quantity']); ?>)</span>
                                                            <span>Rp <?php echo number_format($item['price_at_purchase'], 0, ',', '.'); ?></span>
                                                        </div>
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>
                                        </td>
                                        <td data-label="Total">Rp <?php echo number_format($transaction['total_amount'], 0, ',', '.'); ?></td>
                                        <td data-label="Status">
                                            <select class="status-dropdown <?php echo htmlspecialchars($transaction['status']); ?>"
                                                onchange="location = 'admin.php?page=manajemen_pesanan&action=update_status&id=<?php echo $trans_id; ?>&status=' + this.value;">
                                                <option value="Pending" <?php echo ($transaction['status'] == 'Pending' ? 'selected' : ''); ?>>Pending</option>
                                                <option value="Completed" <?php echo ($transaction['status'] == 'Completed' ? 'selected' : ''); ?>>Completed</option>
                                                <option value="Cancelled" <?php echo ($transaction['status'] == 'Cancelled' ? 'selected' : ''); ?>>Cancelled</option>
                                            </select>
                                        </td>
                                        <td data-label="Aksi">
                                            <button class="view-details-btn"
                                                data-transaction-code="#<?php echo htmlspecialchars($transaction['transaction_code']); ?>"
                                                data-proof-image="<?php echo htmlspecialchars($transaction['proof_image_path']); ?>">
                                                Detail
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>

                <?php elseif ($currentPage == 'data_pengguna'): ?>
                    <div class="section-header">
                        <h2>Data Pengguna</h2>
                        <button class="add-button" onclick="alert('Penambahan pengguna baru dilakukan melalui halaman registrasi.');">TAMBAH</button>
                    </div>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>No</th>
                                <th>Username</th>
                                <th>Nama Lengkap</th>
                                <th>Email</th>
                                <th>Nomor Telepon</th>
                                <th>Alamat</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $no_user = 1;
                            if (!empty($regular_users)): ?>
                                <?php foreach ($regular_users as $user): ?>
                                    <tr>
                                        <td data-label="No"><?php echo $no_user++; ?></td>
                                        <td data-label="Username"><?php echo htmlspecialchars($user['username']); ?></td>
                                        <td data-label="Nama Lengkap"><?php echo !empty($user['full_name']) ? htmlspecialchars($user['full_name']) : '-'; ?></td>
                                        <td data-label="Email"><?php echo !empty($user['email']) ? htmlspecialchars($user['email']) : '-'; ?></td>
                                        <td data-label="Nomor Telepon"><?php echo !empty($user['phone_number']) ? htmlspecialchars($user['phone_number']) : '-'; ?></td>
                                        <td data-label="Alamat"><?php echo !empty($user['address']) ? htmlspecialchars($user['address']) : '-'; ?></td>
                                        <td data-label="Action" class="action-buttons">
                                            <!-- <a href="#" class="edit-btn" onclick="alert('Fitur edit pengguna belum diimplementasikan.'); return false;">Edit</a> -->
                                            <a href="admin.php?page=data_pengguna&action=delete_user&id=<?php echo $user['id']; ?>"
                                                class="delete-btn"
                                                onclick="return confirm('Apakah Anda yakin ingin menghapus pengguna <?php echo htmlspecialchars($user['username']); ?>?');">Hapus</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" style="text-align: center;">Tidak ada data pengguna terdaftar.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>

                <?php elseif ($currentPage == 'data_admin'): ?>
                    <div class="section-header">
                        <h2>Data Admin</h2>
                        <button class="add-button" onclick="alert('Penambahan admin baru dilakukan manual melalui database.');">TAMBAH</button>
                    </div>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>No</th>
                                <th>Nama Admin</th>
                                <th>Username</th>
                                <th>Email</th>
                                <th>Password</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $no_admin = 1;
                            if (!empty($admin_users)): ?>
                                <?php foreach ($admin_users as $admin): ?>
                                    <tr>
                                        <td data-label="No"><?php echo $no_admin++; ?></td>
                                        <td data-label="Nama Admin"><?php echo !empty($admin['full_name']) ? htmlspecialchars($admin['full_name']) : htmlspecialchars($admin['username']); ?></td>
                                        <td data-label="Username"><?php echo htmlspecialchars($admin['username']); ?></td>
                                        <td data-label="Email"><?php echo !empty($admin['email']) ? htmlspecialchars($admin['email']) : '-'; ?></td>
                                        <td data-label="Password">********</td>
                                        <td data-label="Action" class="action-buttons">
                                            <a href="#" class="edit-btn" onclick="alert('Fitur edit admin belum diimplementasikan.'); return false;">Edit</a>
                                            <a href="#" class="delete-btn" onclick="alert('Fitur hapus admin belum diimplementasikan.'); return false;">Hapus</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" style="text-align: center;">Tidak ada data admin.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>


                <?php elseif ($currentPage == 'verifikasi_akun'): ?>
                    <div class="section-header">
                        <h2>Verifikasi Akun Pengguna</h2>
                    </div>
                    <p>Halaman untuk verifikasi akun pengguna baru.</p>

                <?php elseif ($currentPage == 'data_pengaduan'): ?>
                    <div class="section-header">
                        <h2>Data Pengaduan</h2>
                    </div>
                    <p>Halaman untuk melihat dan mengelola pengaduan.</p>

                <?php else: ?>
                    <div class="section-header">
                        <h2>Halaman Tidak Ditemukan</h2>
                    </div>
                    <p>Halaman yang Anda cari tidak tersedia.</p>
                <?php endif; ?>

        </main>
    </div>

    <div id="proofImageModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="modalTransactionCode">Detail Bukti Pembayaran</h3>
                <span class="close-button" id="closeModalButton">×</span>
            </div>
            <div class="modal-body">
                <img id="modalProofImage" src="" alt="Bukti Pembayaran">
                <p id="modalNoImageMessage" class="no-image" style="display: none;">Tidak ada bukti pembayaran yang diunggah untuk pesanan ini.</p>
            </div>
        </div>
    </div>

    <script>
        // JavaScript untuk Profile Dropdown di Top Bar
        const adminProfileBtn = document.getElementById('adminProfileBtn');
        const adminProfileDropdown = document.getElementById('adminProfileDropdown');

        adminProfileBtn.addEventListener('click', function(event) {
            event.preventDefault();
            adminProfileDropdown.classList.toggle('show');
        });

        document.addEventListener('click', function(event) {
            if (!adminProfileBtn.contains(event.target) && !adminProfileDropdown.contains(event.target)) {
                adminProfileDropdown.classList.remove('show');
            }
        });

        // JavaScript untuk Modal Bukti Pembayaran
        const proofImageModal = document.getElementById('proofImageModal');
        const closeModalButton = document.getElementById('closeModalButton');
        const modalProofImage = document.getElementById('modalProofImage');
        const modalTransactionCode = document.getElementById('modalTransactionCode');
        const modalNoImageMessage = document.getElementById('modalNoImageMessage');

        document.querySelectorAll('.view-details-btn').forEach(button => {
            button.addEventListener('click', function() {
                const transactionCode = this.dataset.transactionCode;
                const proofImagePath = this.dataset.proofImage;

                modalTransactionCode.textContent = `Bukti Pembayaran Pesanan ${transactionCode}`;

                if (proofImagePath && proofImagePath !== 'null' && proofImagePath !== '') { // Check if path exists and not null/empty string
                    modalProofImage.src = proofImagePath;
                    modalProofImage.style.display = 'block';
                    modalNoImageMessage.style.display = 'none';
                } else {
                    modalProofImage.src = '';
                    modalProofImage.style.display = 'none';
                    modalNoImageMessage.style.display = 'block';
                }

                proofImageModal.style.display = 'flex'; // Use flex to center
            });
        });

        closeModalButton.addEventListener('click', function() {
            proofImageModal.style.display = 'none';
        });

        // Close the modal when clicking outside of the modal content
        window.addEventListener('click', function(event) {
            if (event.target == proofImageModal) {
                proofImageModal.style.display = 'none';
            }
        });
    </script>
</body>

</html>