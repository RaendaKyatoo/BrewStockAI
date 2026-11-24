<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/ai_engine.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Sesi berakhir. Silakan login kembali.']);
    exit;
}

$pdo = get_db();
$user = current_user();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

$input = [];
if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    $decoded = json_decode($raw ?: '{}', true);
    $input = is_array($decoded) ? $decoded : $_POST;

    $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['csrf_token'] ?? '');
    if (!verify_csrf($csrf)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Token keamanan (CSRF) tidak valid. Muat ulang halaman.']);
        exit;
    }
}

$action = $_GET['action'] ?? ($input['action'] ?? 'get_state');

function build_full_state(PDO $pdo, array $user): array
{
    $ai = run_brewstock_predictions($pdo);

    $menus = $pdo->query('SELECT * FROM menus WHERE is_active = 1 ORDER BY category, name')->fetchAll();
    $recipesRaw = $pdo->query("
        SELECT mr.*, i.name AS ingredient_name, i.unit
        FROM menu_recipes mr
        JOIN ingredients i ON i.id = mr.ingredient_id
        ORDER BY mr.menu_id, i.name
    ")->fetchAll();
    $recipeByMenu = [];
    foreach ($recipesRaw as $r) {
        $recipeByMenu[(int)$r['menu_id']][] = [
            'ingredient_id' => (int)$r['ingredient_id'],
            'ingredient_name' => $r['ingredient_name'],
            'unit' => $r['unit'],
            'qty_per_portion' => (float)$r['qty_per_portion'],
        ];
    }
    foreach ($menus as &$m) {
        $m['id'] = (int)$m['id'];
        $m['price'] = (int)$m['price'];
        $m['recipes'] = $recipeByMenu[$m['id']] ?? [];
    }
    unset($m);

    $posSales = $pdo->query("
        SELECT ps.*, m.name AS menu_name, m.category AS menu_category
        FROM pos_sales ps
        JOIN menus m ON m.id = ps.menu_id
        ORDER BY ps.sold_at DESC, ps.id DESC
        LIMIT 30
    ")->fetchAll();

    $todayRevenue = (int)$pdo->query("SELECT COALESCE(SUM(total_price), 0) FROM pos_sales WHERE DATE(sold_at) = CURDATE()")->fetchColumn();
    $todayCups = (int)$pdo->query("SELECT COALESCE(SUM(qty), 0) FROM pos_sales WHERE DATE(sold_at) = CURDATE()")->fetchColumn();

    $closingLogs = $pdo->query("
        SELECT cl.*, i.name AS ingredient_name, i.unit
        FROM closing_logs cl
        JOIN ingredients i ON i.id = cl.ingredient_id
        ORDER BY cl.created_at DESC, cl.id DESC
        LIMIT 25
    ")->fetchAll();

    $purchaseOrders = $pdo->query("
        SELECT po.*, i.name AS ingredient_name, i.unit, s.name AS supplier_name, s.phone AS supplier_phone
        FROM purchase_orders po
        JOIN ingredients i ON i.id = po.ingredient_id
        LEFT JOIN suppliers s ON s.id = po.supplier_id
        ORDER BY FIELD(po.status, 'draft', 'ordered', 'received', 'cancelled'), po.created_at DESC
        LIMIT 30
    ")->fetchAll();

    $suppliers = $pdo->query('SELECT * FROM suppliers ORDER BY name ASC')->fetchAll();

    $wasteLogs = $pdo->query("
        SELECT wl.*, i.name AS ingredient_name, i.unit
        FROM waste_logs wl
        JOIN ingredients i ON i.id = wl.ingredient_id
        ORDER BY wl.created_at DESC
        LIMIT 20
    ")->fetchAll();

    $totalWasteLoss = (int)$pdo->query('SELECT COALESCE(SUM(loss_amount), 0) FROM waste_logs')->fetchColumn();

    $state = [
        'user' => $user,
        'ai' => $ai,
        'menus' => $menus,
        'pos_sales' => $posSales,
        'today_stats' => [
            'revenue' => $todayRevenue,
            'cups' => $todayCups,
            'waste_loss_total' => $totalWasteLoss,
        ],
        'closing_logs' => $closingLogs,
        'purchase_orders' => $purchaseOrders,
        'suppliers' => $suppliers,
        'waste_logs' => $wasteLogs,
    ];

    if ($user['role'] === 'admin') {
        $state['users'] = $pdo->query('SELECT id, username, full_name, role, job_title, is_active, created_at FROM users ORDER BY id ASC')->fetchAll();
    }

    return $state;
}

try {
    switch ($action) {
        case 'get_state':
            echo json_encode(['ok' => true, 'data' => build_full_state($pdo, $user)]);
            break;

        case 'simulate_ai':
            $weather = $input['weather'] ?? ($_GET['weather'] ?? 'panas');
            if (!in_array($weather, ['panas', 'normal', 'hujan'], true)) {
                $weather = 'panas';
            }
            $isWeekend = filter_var($input['is_weekend'] ?? ($_GET['is_weekend'] ?? false), FILTER_VALIDATE_BOOLEAN);
            $sim = run_brewstock_predictions($pdo, $weather, $isWeekend);
            echo json_encode(['ok' => true, 'simulation' => $sim]);
            break;

        case 'update_weather':
            $weather = (string)($input['tomorrow_weather'] ?? 'panas');
            if (!in_array($weather, ['panas', 'normal', 'hujan'], true)) {
                throw new RuntimeException('Kondisi cuaca tidak valid.');
            }
            $isWeekend = !empty($input['is_weekend']) ? 1 : 0;
            $tempMap = ['panas' => 33, 'normal' => 27, 'hujan' => 23];
            $temp = (int)($input['temperature_c'] ?? $tempMap[$weather]);
            $note = trim((string)($input['event_note'] ?? ''));
            if ($note === '') {
                $note = match ($weather) {
                    'panas' => 'Prediksi Besok Cerah Terik (+40% Minuman Dingin)',
                    'hujan' => 'Prediksi Besok Hujan (-25% Es, +25% Pastry Hangat)',
                    default => 'Cuaca Normal & Operasional Standar',
                };
            }

            $stmt = $pdo->prepare("
                UPDATE weather_settings
                SET tomorrow_weather = ?, is_weekend = ?, temperature_c = ?, event_note = ?, updated_by = ?
                WHERE id = 1
            ");
            $stmt->execute([$weather, $isWeekend, $temp, $note, $user['full_name']]);

            echo json_encode([
                'ok' => true,
                'message' => 'Prakiraan cuaca & faktor keramaian diperbarui. AI telah menghitung ulang rekomendasi stok.',
                'data' => build_full_state($pdo, $user),
            ]);
            break;

        case 'submit_closing':
            $entries = $input['entries'] ?? [];
            if (!is_array($entries) || count($entries) === 0) {
                throw new RuntimeException('Tidak ada data stok closing yang dikirim.');
            }
            $notes = trim((string)($input['notes'] ?? 'Closing shift harian'));
            $weatherRow = get_weather_settings($pdo);
            $weather = (string)$weatherRow['tomorrow_weather'];
            $isWeekend = (int)$weatherRow['is_weekend'] === 1;

            $pdo->beginTransaction();
            $getIng = $pdo->prepare('SELECT * FROM ingredients WHERE id = ?');
            $updIng = $pdo->prepare('UPDATE ingredients SET current_stock = ? WHERE id = ?');
            $insLog = $pdo->prepare("
                INSERT INTO closing_logs
                (ingredient_id, previous_stock, closing_stock, weather_forecast, predicted_need, recommended_buy, status_color, notes, recorded_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $updatedCount = 0;
            foreach ($entries as $entry) {
                $ingId = (int)($entry['ingredient_id'] ?? 0);
                $newStock = max(0.0, round((float)($entry['closing_stock'] ?? 0), 2));
                $getIng->execute([$ingId]);
                $ing = $getIng->fetch();
                if (!$ing) {
                    continue;
                }
                $prevStock = (float)$ing['current_stock'];
                $updIng->execute([$newStock, $ingId]);

                $mult = calculate_weather_multiplier((string)$ing['weather_sensitivity'], $weather, $isWeekend);
                $predNeed = round((float)$ing['daily_baseline'] * $mult['factor'], 2);
                $buffer = ((int)$ing['is_perishable'] === 1 && (int)$ing['shelf_life_days'] <= 3) ? 1.00 : 1.10;
                $target = round($predNeed * $buffer, 2);
                $recBuy = $target > $newStock ? round($target - $newStock, 2) : 0.0;

                if ($newStock < ($predNeed * 0.72) || ($newStock <= (float)$ing['min_threshold'] && $recBuy > 0)) {
                    $status = 'merah';
                } elseif ($recBuy > 0 || $newStock < ($predNeed * 1.20) || $newStock <= (float)$ing['min_threshold']) {
                    $status = 'kuning';
                } else {
                    $status = 'hijau';
                }

                $insLog->execute([
                    $ingId,
                    $prevStock,
                    $newStock,
                    $weather,
                    $predNeed,
                    $recBuy,
                    $status,
                    $notes,
                    $user['full_name'],
                ]);
                $updatedCount++;
            }
            $pdo->commit();

            echo json_encode([
                'ok' => true,
                'message' => "Closing {$updatedCount} bahan baku tersimpan & langsung dianalisis BrewStock AI!",
                'data' => build_full_state($pdo, $user),
            ]);
            break;

        case 'submit_pos_sale':
            $cart = $input['items'] ?? [];
            if (!is_array($cart) || count($cart) === 0) {
                throw new RuntimeException('Keranjang kasir masih kosong.');
            }
            $weatherRow = get_weather_settings($pdo);
            $weather = (string)$weatherRow['tomorrow_weather'];

            $pdo->beginTransaction();
            $getMenu = $pdo->prepare('SELECT * FROM menus WHERE id = ? AND is_active = 1');
            $getRec = $pdo->prepare('SELECT ingredient_id, qty_per_portion FROM menu_recipes WHERE menu_id = ?');
            $insSale = $pdo->prepare("
                INSERT INTO pos_sales (menu_id, qty, total_price, weather_at_sale, cashier_name, sold_at)
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            $deductStock = $pdo->prepare('UPDATE ingredients SET current_stock = GREATEST(0, ROUND(current_stock - ?, 2)) WHERE id = ?');

            $totalCups = 0;
            $totalAmount = 0;
            foreach ($cart as $line) {
                $menuId = (int)($line['menu_id'] ?? 0);
                $qty = max(1, (int)($line['qty'] ?? 1));
                $getMenu->execute([$menuId]);
                $menu = $getMenu->fetch();
                if (!$menu) {
                    continue;
                }
                $lineTotal = (int)$menu['price'] * $qty;
                $insSale->execute([$menuId, $qty, $lineTotal, $weather, $user['full_name']]);

                $getRec->execute([$menuId]);
                foreach ($getRec->fetchAll() as $r) {
                    $usedQty = round((float)$r['qty_per_portion'] * $qty, 3);
                    $deductStock->execute([$usedQty, (int)$r['ingredient_id']]);
                }

                $totalCups += $qty;
                $totalAmount += $lineTotal;
            }
            $pdo->commit();

            echo json_encode([
                'ok' => true,
                'message' => "Transaksi POS berhasil ({$totalCups} item, Rp " . number_format($totalAmount, 0, ',', '.') . '). Stok bahan otomatis terpotong via resep!',
                'data' => build_full_state($pdo, $user),
            ]);
            break;

        case 'request_po':
            $ingId = (int)($input['ingredient_id'] ?? 0);
            $qty = max(0.1, round((float)($input['qty'] ?? 0), 2));
            $notes = trim((string)($input['notes'] ?? 'Pengajuan restock dari rekomendasi BrewStock AI'));

            $stmt = $pdo->prepare('SELECT * FROM ingredients WHERE id = ?');
            $stmt->execute([$ingId]);
            $ing = $stmt->fetch();
            if (!$ing) {
                throw new RuntimeException('Bahan baku tidak ditemukan.');
            }

            $poNumber = 'PO-' . date('Ymd-His') . '-' . random_int(10, 99);
            $cost = (int)round($qty * (int)$ing['unit_price']);
            $status = ($user['role'] === 'admin') ? 'ordered' : 'draft';
            $approvedBy = ($user['role'] === 'admin') ? $user['full_name'] : null;

            $pdo->prepare("
                INSERT INTO purchase_orders (po_number, ingredient_id, supplier_id, qty, estimated_cost, status, requested_by, approved_by, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                $poNumber,
                $ingId,
                $ing['supplier_id'],
                $qty,
                $cost,
                $status,
                $user['full_name'],
                $approvedBy,
                $notes,
            ]);

            echo json_encode([
                'ok' => true,
                'message' => "PO {$poNumber} untuk {$ing['name']} ({$qty} {$ing['unit']}) berhasil dibuat.",
                'data' => build_full_state($pdo, $user),
            ]);
            break;

        // ==================== ADMIN ONLY ACTIONS ====================
        case 'save_ingredient':
            if ($user['role'] !== 'admin') {
                throw new RuntimeException('Akses khusus Admin/Owner.');
            }
            $id = (int)($input['id'] ?? 0);
            $name = trim((string)($input['name'] ?? ''));
            if ($name === '') {
                throw new RuntimeException('Nama bahan baku wajib diisi.');
            }
            $category = (string)($input['category'] ?? 'Lainnya');
            $unit = trim((string)($input['unit'] ?? 'L')) ?: 'L';
            $currentStock = max(0.0, (float)($input['current_stock'] ?? 0));
            $baseline = max(0.1, (float)($input['daily_baseline'] ?? 1));
            $minThresh = max(0.1, (float)($input['min_threshold'] ?? 1));
            $safeThresh = max($minThresh, (float)($input['safe_threshold'] ?? 3));
            $shelfLife = max(1, (int)($input['shelf_life_days'] ?? 7));
            $isPerishable = !empty($input['is_perishable']) ? 1 : 0;
            $sensitivity = (string)($input['weather_sensitivity'] ?? 'netral');
            $unitPrice = max(0, (int)($input['unit_price'] ?? 0));
            $supplierId = !empty($input['supplier_id']) ? (int)$input['supplier_id'] : null;

            if ($id > 0) {
                $pdo->prepare("
                    UPDATE ingredients
                    SET name=?, category=?, unit=?, current_stock=?, daily_baseline=?, min_threshold=?, safe_threshold=?,
                        shelf_life_days=?, is_perishable=?, weather_sensitivity=?, unit_price=?, supplier_id=?
                    WHERE id=?
                ")->execute([
                    $name, $category, $unit, $currentStock, $baseline, $minThresh, $safeThresh,
                    $shelfLife, $isPerishable, $sensitivity, $unitPrice, $supplierId, $id
                ]);
                $msg = "Bahan baku \"{$name}\" diperbarui.";
            } else {
                $pdo->prepare("
                    INSERT INTO ingredients
                    (name, category, unit, current_stock, daily_baseline, min_threshold, safe_threshold, shelf_life_days, is_perishable, weather_sensitivity, unit_price, supplier_id)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ")->execute([
                    $name, $category, $unit, $currentStock, $baseline, $minThresh, $safeThresh,
                    $shelfLife, $isPerishable, $sensitivity, $unitPrice, $supplierId
                ]);
                $msg = "Bahan baku \"{$name}\" ditambahkan.";
            }

            echo json_encode(['ok' => true, 'message' => $msg, 'data' => build_full_state($pdo, $user)]);
            break;

        case 'delete_ingredient':
            if ($user['role'] !== 'admin') {
                throw new RuntimeException('Akses khusus Admin/Owner.');
            }
            $id = (int)($input['id'] ?? 0);
            $pdo->prepare('DELETE FROM ingredients WHERE id = ?')->execute([$id]);
            echo json_encode(['ok' => true, 'message' => 'Bahan baku dihapus.', 'data' => build_full_state($pdo, $user)]);
            break;

        case 'save_menu':
            if ($user['role'] !== 'admin') {
                throw new RuntimeException('Akses khusus Admin/Owner.');
            }
            $id = (int)($input['id'] ?? 0);
            $name = trim((string)($input['name'] ?? ''));
            if ($name === '') {
                throw new RuntimeException('Nama menu wajib diisi.');
            }
            $category = (string)($input['category'] ?? 'Kopi Dingin');
            $price = max(1000, (int)($input['price'] ?? 25000));
            $recipes = $input['recipes'] ?? [];

            $pdo->beginTransaction();
            if ($id > 0) {
                $pdo->prepare('UPDATE menus SET name=?, category=?, price=? WHERE id=?')->execute([$name, $category, $price, $id]);
                $menuId = $id;
            } else {
                $pdo->prepare('INSERT INTO menus (name, category, price) VALUES (?, ?, ?)')->execute([$name, $category, $price]);
                $menuId = (int)$pdo->lastInsertId();
            }

            $pdo->prepare('DELETE FROM menu_recipes WHERE menu_id = ?')->execute([$menuId]);
            $insRec = $pdo->prepare('INSERT INTO menu_recipes (menu_id, ingredient_id, qty_per_portion) VALUES (?, ?, ?)');
            if (is_array($recipes)) {
                foreach ($recipes as $rec) {
                    $ingId = (int)($rec['ingredient_id'] ?? 0);
                    $qty = (float)($rec['qty_per_portion'] ?? 0);
                    if ($ingId > 0 && $qty > 0) {
                        $insRec->execute([$menuId, $ingId, $qty]);
                    }
                }
            }
            $pdo->commit();

            echo json_encode(['ok' => true, 'message' => "Menu & resep \"{$name}\" tersimpan.", 'data' => build_full_state($pdo, $user)]);
            break;

        case 'delete_menu':
            if ($user['role'] !== 'admin') {
                throw new RuntimeException('Akses khusus Admin/Owner.');
            }
            $id = (int)($input['id'] ?? 0);
            $pdo->prepare('UPDATE menus SET is_active = 0 WHERE id = ?')->execute([$id]);
            echo json_encode(['ok' => true, 'message' => 'Menu dinonaktifkan.', 'data' => build_full_state($pdo, $user)]);
            break;

        case 'save_supplier':
            if ($user['role'] !== 'admin') {
                throw new RuntimeException('Akses khusus Admin/Owner.');
            }
            $id = (int)($input['id'] ?? 0);
            $name = trim((string)($input['name'] ?? ''));
            $category = trim((string)($input['category'] ?? 'Umum'));
            $phone = trim((string)($input['phone'] ?? ''));
            $lead = max(1, (int)($input['lead_time_days'] ?? 1));
            $address = trim((string)($input['address'] ?? ''));
            if ($name === '' || $phone === '') {
                throw new RuntimeException('Nama dan telepon supplier wajib diisi.');
            }

            if ($id > 0) {
                $pdo->prepare('UPDATE suppliers SET name=?, category=?, phone=?, lead_time_days=?, address=? WHERE id=?')
                    ->execute([$name, $category, $phone, $lead, $address, $id]);
            } else {
                $pdo->prepare('INSERT INTO suppliers (name, category, phone, lead_time_days, address) VALUES (?, ?, ?, ?, ?)')
                    ->execute([$name, $category, $phone, $lead, $address]);
            }

            echo json_encode(['ok' => true, 'message' => "Data supplier \"{$name}\" tersimpan.", 'data' => build_full_state($pdo, $user)]);
            break;

        case 'generate_auto_pos':
            if ($user['role'] !== 'admin') {
                throw new RuntimeException('Akses khusus Admin/Owner.');
            }
            $ai = run_brewstock_predictions($pdo);
            $created = 0;
            $checkOpen = $pdo->prepare("SELECT COUNT(*) FROM purchase_orders WHERE ingredient_id = ? AND status IN ('draft', 'ordered')");
            $insPo = $pdo->prepare("
                INSERT INTO purchase_orders (po_number, ingredient_id, supplier_id, qty, estimated_cost, status, requested_by, approved_by, notes)
                VALUES (?, ?, ?, ?, ?, 'ordered', ?, ?, ?)
            ");
            foreach ($ai['items'] as $item) {
                if ($item['recommended_buy'] <= 0 || $item['status'] === 'hijau') {
                    continue;
                }
                $checkOpen->execute([$item['id']]);
                if ((int)$checkOpen->fetchColumn() > 0) {
                    continue;
                }
                $poNumber = 'PO-' . date('Ymd') . '-' . str_pad((string)random_int(100, 999), 3, '0', STR_PAD_LEFT);
                $insPo->execute([
                    $poNumber,
                    $item['id'],
                    $item['supplier_id'],
                    $item['recommended_buy'],
                    $item['estimated_cost'],
                    $user['full_name'],
                    $user['full_name'],
                    "Auto-PO BrewStock AI ({$item['status_label']}): {$item['ai_insight']}",
                ]);
                $created++;
            }

            echo json_encode([
                'ok' => true,
                'message' => $created > 0
                    ? "{$created} Purchase Order otomatis dibuat & dikirim ke supplier."
                    : 'Semua item kritis sudah memiliki PO aktif atau stok aman.',
                'data' => build_full_state($pdo, $user),
            ]);
            break;

        case 'update_po_status':
            if ($user['role'] !== 'admin') {
                throw new RuntimeException('Akses khusus Admin/Owner.');
            }
            $poId = (int)($input['id'] ?? 0);
            $newStatus = (string)($input['status'] ?? '');
            if (!in_array($newStatus, ['ordered', 'received', 'cancelled'], true)) {
                throw new RuntimeException('Status PO tidak valid.');
            }

            $pdo->beginTransaction();
            $stmt = $pdo->prepare('SELECT * FROM purchase_orders WHERE id = ? FOR UPDATE');
            $stmt->execute([$poId]);
            $po = $stmt->fetch();
            if (!$po) {
                throw new RuntimeException('PO tidak ditemukan.');
            }
            $oldStatus = $po['status'];
            $pdo->prepare('UPDATE purchase_orders SET status = ?, approved_by = ? WHERE id = ?')
                ->execute([$newStatus, $user['full_name'], $poId]);

            if ($newStatus === 'received' && $oldStatus !== 'received') {
                $pdo->prepare('UPDATE ingredients SET current_stock = ROUND(current_stock + ?, 2) WHERE id = ?')
                    ->execute([(float)$po['qty'], (int)$po['ingredient_id']]);
            }
            $pdo->commit();

            $msg = $newStatus === 'received'
                ? "Barang PO {$po['po_number']} diterima & stok otomatis bertambah!"
                : "Status PO {$po['po_number']} diubah menjadi {$newStatus}.";

            echo json_encode(['ok' => true, 'message' => $msg, 'data' => build_full_state($pdo, $user)]);
            break;

        case 'log_waste':
            if ($user['role'] !== 'admin') {
                throw new RuntimeException('Akses khusus Admin/Owner.');
            }
            $ingId = (int)($input['ingredient_id'] ?? 0);
            $qty = max(0.01, round((float)($input['qty_wasted'] ?? 0), 2));
            $reason = trim((string)($input['reason'] ?? 'Bahan kedaluwarsa / basi'));

            $stmt = $pdo->prepare('SELECT * FROM ingredients WHERE id = ?');
            $stmt->execute([$ingId]);
            $ing = $stmt->fetch();
            if (!$ing) {
                throw new RuntimeException('Bahan baku tidak ditemukan.');
            }
            $loss = (int)round($qty * (int)$ing['unit_price']);

            $pdo->beginTransaction();
            $pdo->prepare('INSERT INTO waste_logs (ingredient_id, qty_wasted, reason, loss_amount, recorded_by) VALUES (?, ?, ?, ?, ?)')
                ->execute([$ingId, $qty, $reason, $loss, $user['full_name']]);
            $pdo->prepare('UPDATE ingredients SET current_stock = GREATEST(0, ROUND(current_stock - ?, 2)) WHERE id = ?')
                ->execute([$qty, $ingId]);
            $pdo->commit();

            echo json_encode([
                'ok' => true,
                'message' => "Log waste {$ing['name']} ({$qty} {$ing['unit']}) dicatat.",
                'data' => build_full_state($pdo, $user),
            ]);
            break;

        case 'save_user':
            if ($user['role'] !== 'admin') {
                throw new RuntimeException('Akses khusus Admin/Owner.');
            }
            $username = trim((string)($input['username'] ?? ''));
            $fullName = trim((string)($input['full_name'] ?? ''));
            $role = ($input['role'] ?? 'user') === 'admin' ? 'admin' : 'user';
            $jobTitle = trim((string)($input['job_title'] ?? 'Barista'));
            $password = (string)($input['password'] ?? '123456');

            if ($username === '' || $fullName === '') {
                throw new RuntimeException('Username dan nama lengkap wajib diisi.');
            }
            $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role, job_title) VALUES (?, ?, ?, ?, ?)')
                ->execute([$username, password_hash($password, PASSWORD_DEFAULT), $fullName, $role, $jobTitle]);

            echo json_encode([
                'ok' => true,
                'message' => "Akun pengguna \"{$fullName}\" berhasil dibuat.",
                'data' => build_full_state($pdo, $user),
            ]);
            break;

        case 'toggle_user':
            if ($user['role'] !== 'admin') {
                throw new RuntimeException('Akses khusus Admin/Owner.');
            }
            $targetId = (int)($input['id'] ?? 0);
            if ($targetId === (int)$user['id']) {
                throw new RuntimeException('Tidak bisa menonaktifkan akun yang sedang digunakan.');
            }
            $pdo->prepare('UPDATE users SET is_active = 1 - is_active WHERE id = ?')->execute([$targetId]);
            echo json_encode([
                'ok' => true,
                'message' => 'Status akun pengguna diperbarui.',
                'data' => build_full_state($pdo, $user),
            ]);
            break;

        default:
            throw new RuntimeException("Action '{$action}' tidak dikenali.");
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
