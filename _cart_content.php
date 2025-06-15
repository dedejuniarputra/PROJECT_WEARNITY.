<?php
// Ini akan dipanggil via AJAX, jadi perlu memuat config untuk session dan DB
// config.php seharusnya sudah memuat session_start()
require_once 'config.php';

// Pastikan user login
// Meskipun _cart_content.php akan dimuat di halaman yang sudah mengecek login,
// pemeriksaan ini penting jika ada akses langsung atau skenario lain.
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    echo "<p class='empty-cart-message'>Anda harus login untuk melihat keranjang.</p>";
    exit;
}

// Ambil data keranjang dari session
// $_SESSION['cart'] diharapkan memiliki format [product_id => quantity]
$cart = isset($_SESSION['cart']) ? $_SESSION['cart'] : [];
$cartItems = [];
$totalPrice = 0;

if (!empty($cart)) {
    // Ambil ID produk dari keranjang (keys dari array $cart)
    $productIds = array_keys($cart);
    // Buat placeholder untuk query IN clause agar aman dari SQL Injection
    $placeholders = implode(',', array_fill(0, count($productIds), '?'));

    // Query untuk mengambil detail produk dari database
    // Pastikan kolom yang diambil sesuai dengan kebutuhan tampilan (id, name, price, image_path)
    $sql = "SELECT id, name, price, image_path FROM products WHERE id IN ($placeholders)";
    
    try {
        $stmt = $pdo->prepare($sql);
        // Bind parameter secara dinamis
        foreach ($productIds as $k => $id) {
            // Bind value dengan tipe integer untuk ID produk
            $stmt->bindValue(($k + 1), $id, PDO::PARAM_INT);
        }
        $stmt->execute();
        $dbProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Map produk dari database ke item keranjang yang akan ditampilkan
        foreach ($dbProducts as $product) {
            $qty = $cart[$product['id']]; // Ambil kuantitas dari session cart
            $itemTotal = $product['price'] * $qty;
            $totalPrice += $itemTotal; // Tambahkan ke total harga keseluruhan
            
            $cartItems[] = [
                'id' => $product['id'],
                'name' => $product['name'],
                'price' => $product['price'], // Harga satuan
                'image_path' => $product['image_path'],
                'quantity' => $qty, // Kuantitas dari session
                'item_total' => $itemTotal // Subtotal untuk item ini
            ];
        }

    } catch (PDOException $e) {
        // Tampilkan pesan error jika gagal mengambil data produk
        error_log("Error fetching cart products: " . $e->getMessage()); // Log error
        echo "<p class='empty-cart-message' style='color: red;'>Terjadi kesalahan saat memuat produk keranjang. Silakan coba lagi.</p>";
        $cartItems = []; // Pastikan keranjang kosong di tampilan jika ada error
    }
}
// Tidak perlu unset($pdo) di sini, biarkan koneksi tetap terbuka untuk script induk.
?>

<div class="cart-header">
    <h3>Keranjang Belanja</h3>
    <button class="close-cart-btn" id="closeCartBtn">×</button>
</div>

<div class="cart-items-list">
    <?php if (!empty($cartItems)): ?>
        <?php foreach ($cartItems as $item): ?>
            <div class="cart-item" data-product-id="<?php echo $item['id']; ?>">
                <img src="<?php echo htmlspecialchars($item['image_path']); ?>" alt="<?php echo htmlspecialchars($item['name']); ?>">
                <div class="item-details">
                    <span class="item-name"><?php echo htmlspecialchars($item['name']); ?></span>
                    <div class="quantity-controls">
                        <button class="qty-btn" data-action="minus"><i class="fas fa-minus"></i></button>
                        <span class="item-quantity"><?php echo $item['quantity']; ?></span>
                        <button class="qty-btn" data-action="plus"><i class="fas fa-plus"></i></button>
                    </div>
                </div>
                <div class="item-actions">
                    <span class="item-price">Rp <?php echo number_format($item['item_total'], 0, ',', '.'); ?></span>
                    <button class="remove-item-btn"><i class="fas fa-trash-alt"></i></button>
                </div>
            </div>
        <?php endforeach; ?>
    <?php else: ?>
        <p class="empty-cart-message">Keranjang belanja Anda kosong.</p>
    <?php endif; ?>
</div>

<div class="cart-summary">
    <div class="total-price">
        <span>Total:</span>
        <span>Rp <?php echo number_format($totalPrice, 0, ',', '.'); ?></span>
    </div>
    <a href="order_details.php" class="checkout-btn" id="checkoutBtn">
        <i class="fas fa-arrow-right"></i>
    </a>
</div>