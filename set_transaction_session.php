<?php
session_start(); // Pastikan session dimulai di sini juga!

header('Content-Type: application/json'); // Beri tahu browser bahwa responsnya adalah JSON

$response = ['success' => false, 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['transaction_id'])) {
        $transaction_id = filter_input(INPUT_POST, 'transaction_id', FILTER_VALIDATE_INT);

        if ($transaction_id !== false && $transaction_id !== null) {
            $_SESSION['transaction_id'] = $transaction_id;
            $response['success'] = true;
            $response['message'] = 'Transaction ID berhasil disimpan ke sesi.';
        } else {
            $response['message'] = 'ID transaksi tidak valid.';
        }
    } else {
        $response['message'] = 'Parameter transaction_id tidak ditemukan.';
    }
} else {
    $response['message'] = 'Metode request tidak diizinkan.';
}

echo json_encode($response);
exit(); // Penting untuk menghentikan eksekusi setelah mengirim JSON
?>