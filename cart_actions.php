<?php
require_once 'config.php'; // Memuat konfigurasi database dan memulai session

header('Content-Type: application/json'); // Respons selalu JSON

$response = ['success' => false, 'message' => ''];

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    $response['message'] = 'Anda harus login untuk mengakses keranjang.';
    echo json_encode($response);
    exit;
}

// Inisialisasi keranjang jika belum ada di session
if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = []; // Format: [product_id => quantity, ...]
}

$action = isset($_POST['action']) ? $_POST['action'] : '';

switch ($action) {
    case 'add':
        $productId = isset($_POST['product_id']) ? (int)$_POST['product_id'] : 0;
        $quantity = isset($_POST['quantity']) ? (int)$_POST['quantity'] : 1;

        if ($productId > 0 && $quantity > 0) {
            // Cek apakah produk ada di database
            $sql = "SELECT id, name, price FROM products WHERE id = :id";
            if ($stmt = $pdo->prepare($sql)) {
                $stmt->bindParam(":id", $productId, PDO::PARAM_INT);
                if ($stmt->execute() && $stmt->rowCount() == 1) {
                    // Produk ditemukan, tambahkan/update di keranjang
                    if (isset($_SESSION['cart'][$productId])) {
                        $_SESSION['cart'][$productId] += $quantity;
                    } else {
                        $_SESSION['cart'][$productId] = $quantity;
                    }
                    $response['success'] = true;
                    $response['message'] = 'Produk berhasil ditambahkan ke keranjang.';
                } else {
                    $response['message'] = 'Produk tidak ditemukan.';
                }
                unset($stmt);
            } else {
                $response['message'] = 'Gagal memverifikasi produk.';
            }
        } else {
            $response['message'] = 'ID produk atau kuantitas tidak valid.';
        }
        break;

    case 'update_quantity':
        $productId = isset($_POST['product_id']) ? (int)$_POST['product_id'] : 0;
        $newQuantity = isset($_POST['quantity']) ? (int)$_POST['quantity'] : 0;

        if ($productId > 0) {
            if (isset($_SESSION['cart'][$productId])) {
                if ($newQuantity > 0) {
                    $_SESSION['cart'][$productId] = $newQuantity;
                    $response['success'] = true;
                    $response['message'] = 'Kuantitas produk berhasil diperbarui.';
                } else {
                    // Jika kuantitas 0 atau kurang, hapus item dari keranjang
                    unset($_SESSION['cart'][$productId]);
                    $response['success'] = true;
                    $response['message'] = 'Produk dihapus dari keranjang.';
                }
            } else {
                $response['message'] = 'Produk tidak ada di keranjang.';
            }
        } else {
            $response['message'] = 'ID produk tidak valid.';
        }
        break;

    case 'remove':
        $productId = isset($_POST['product_id']) ? (int)$_POST['product_id'] : 0;

        if ($productId > 0) {
            if (isset($_SESSION['cart'][$productId])) {
                unset($_SESSION['cart'][$productId]);
                $response['success'] = true;
                $response['message'] = 'Produk berhasil dihapus dari keranjang.';
            } else {
                $response['message'] = 'Produk tidak ada di keranjang.';
            }
        } else {
            $response['message'] = 'ID produk tidak valid.';
        }
        break;

    default:
        $response['message'] = 'Aksi tidak valid.';
        break;
}

unset($pdo); // Tutup koneksi database
echo json_encode($response);
exit;
?>