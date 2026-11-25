<?php
declare(strict_types=1);

/**
 * BrewStock AI - Database Configuration & Auto-Installer
 * Auto-creates `db_brewstock` and seeds realistic cafe data on first run.
 */

const DB_HOST = '127.0.0.1';
const DB_PORT = '3306';
const DB_NAME = 'db_brewstock';
const DB_USER = 'root';
const DB_PASS = '';

function get_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsnNoDb = sprintf('mysql:host=%s;port=%s;charset=utf8mb4', DB_HOST, DB_PORT);
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    $bootPdo = new PDO($dsnNoDb, DB_USER, DB_PASS, $options);
    $bootPdo->exec(sprintf(
        'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
        DB_NAME
    ));

    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME);
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);

    ensure_schema($pdo);
    seed_initial_data($pdo);

    return $pdo;
}

function ensure_schema(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            full_name VARCHAR(100) NOT NULL,
            role ENUM('admin', 'user') NOT NULL DEFAULT 'user',
            job_title VARCHAR(80) NOT NULL DEFAULT 'Barista',
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS suppliers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL,
            category VARCHAR(80) NOT NULL,
            phone VARCHAR(40) NOT NULL,
            lead_time_days INT NOT NULL DEFAULT 1,
            address VARCHAR(255) DEFAULT '',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS ingredients (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL,
            category ENUM('Dairy', 'Kopi', 'Pastry', 'Sirup & Pemanis', 'Lainnya') NOT NULL DEFAULT 'Lainnya',
            unit VARCHAR(20) NOT NULL DEFAULT 'L',
            current_stock DECIMAL(10,2) NOT NULL DEFAULT 0,
            daily_baseline DECIMAL(10,2) NOT NULL DEFAULT 1.00,
            min_threshold DECIMAL(10,2) NOT NULL DEFAULT 1.00,
            safe_threshold DECIMAL(10,2) NOT NULL DEFAULT 3.00,
            shelf_life_days INT NOT NULL DEFAULT 7,
            is_perishable TINYINT(1) NOT NULL DEFAULT 0,
            weather_sensitivity ENUM('panas_boost', 'hujan_boost', 'netral') NOT NULL DEFAULT 'netral',
            unit_price INT NOT NULL DEFAULT 0,
            supplier_id INT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_ing_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS menus (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL,
            category ENUM('Kopi Dingin', 'Kopi Panas', 'Non-Kopi', 'Pastry') NOT NULL DEFAULT 'Kopi Dingin',
            price INT NOT NULL DEFAULT 25000,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS menu_recipes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            menu_id INT NOT NULL,
            ingredient_id INT NOT NULL,
            qty_per_portion DECIMAL(10,3) NOT NULL,
            UNIQUE KEY uniq_menu_ing (menu_id, ingredient_id),
            CONSTRAINT fk_rec_menu FOREIGN KEY (menu_id) REFERENCES menus(id) ON DELETE CASCADE,
            CONSTRAINT fk_rec_ing FOREIGN KEY (ingredient_id) REFERENCES ingredients(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS weather_settings (
            id INT PRIMARY KEY,
            tomorrow_weather ENUM('panas', 'normal', 'hujan') NOT NULL DEFAULT 'panas',
            is_weekend TINYINT(1) NOT NULL DEFAULT 0,
            event_note VARCHAR(160) DEFAULT 'Operasional Normal',
            temperature_c INT NOT NULL DEFAULT 33,
            updated_by VARCHAR(100) DEFAULT 'System',
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS pos_sales (
            id INT AUTO_INCREMENT PRIMARY KEY,
            menu_id INT NOT NULL,
            qty INT NOT NULL DEFAULT 1,
            total_price INT NOT NULL DEFAULT 0,
            weather_at_sale ENUM('panas', 'normal', 'hujan') NOT NULL DEFAULT 'panas',
            cashier_name VARCHAR(100) NOT NULL,
            sold_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_pos_menu FOREIGN KEY (menu_id) REFERENCES menus(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS closing_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ingredient_id INT NOT NULL,
            previous_stock DECIMAL(10,2) NOT NULL,
            closing_stock DECIMAL(10,2) NOT NULL,
            weather_forecast ENUM('panas', 'normal', 'hujan') NOT NULL,
            predicted_need DECIMAL(10,2) NOT NULL,
            recommended_buy DECIMAL(10,2) NOT NULL,
            status_color ENUM('merah', 'kuning', 'hijau') NOT NULL,
            notes VARCHAR(255) DEFAULT '',
            recorded_by VARCHAR(100) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_cls_ing FOREIGN KEY (ingredient_id) REFERENCES ingredients(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS purchase_orders (
            id INT AUTO_INCREMENT PRIMARY KEY,
            po_number VARCHAR(40) NOT NULL UNIQUE,
            ingredient_id INT NOT NULL,
            supplier_id INT NULL,
            qty DECIMAL(10,2) NOT NULL,
            estimated_cost INT NOT NULL DEFAULT 0,
            status ENUM('draft', 'ordered', 'received', 'cancelled') NOT NULL DEFAULT 'draft',
            requested_by VARCHAR(100) NOT NULL,
            approved_by VARCHAR(100) DEFAULT NULL,
            notes VARCHAR(255) DEFAULT '',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_po_ing FOREIGN KEY (ingredient_id) REFERENCES ingredients(id) ON DELETE CASCADE,
            CONSTRAINT fk_po_sup FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS waste_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ingredient_id INT NOT NULL,
            qty_wasted DECIMAL(10,2) NOT NULL,
            reason VARCHAR(160) NOT NULL,
            loss_amount INT NOT NULL DEFAULT 0,
            recorded_by VARCHAR(100) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_wst_ing FOREIGN KEY (ingredient_id) REFERENCES ingredients(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
}

function seed_initial_data(PDO $pdo): void
{
    $userCount = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    if ($userCount > 0) {
        return;
    }

    // Users: Admin (Owner) & Users (Head Barista, Staf Kasir)
    $stmtUser = $pdo->prepare(
        'INSERT INTO users (username, password_hash, full_name, role, job_title) VALUES (?, ?, ?, ?, ?)'
    );
    $stmtUser->execute(['admin', password_hash('admin123', PASSWORD_DEFAULT), 'Raka Pratama (Owner)', 'admin', 'Owner Kafe']);
    $stmtUser->execute(['barista', password_hash('barista123', PASSWORD_DEFAULT), 'Nadya Putri', 'user', 'Head Barista']);
    $stmtUser->execute(['kasir', password_hash('kasir123', PASSWORD_DEFAULT), 'Dimas Saputra', 'user', 'Staf Kasir']);

    // Suppliers
    $stmtSup = $pdo->prepare(
        'INSERT INTO suppliers (name, category, phone, lead_time_days, address) VALUES (?, ?, ?, ?, ?)'
    );
    $stmtSup->execute(['PT Susu Segar Nusantara', 'Dairy & Cold Chain', '0812-3456-7890', 1, 'Jl. Raya Bogor KM 24']);
    $stmtSup->execute(['Roastery Kopi Pilihan', 'Biji Kopi & Teh', '0813-9876-5432', 2, 'Jl. Kemang Selatan No. 18']);
    $stmtSup->execute(['Artisan Bakehouse', 'Pastry & Bakery', '0856-1122-3344', 1, 'Jl. Senopati No. 45']);
    $stmtSup->execute(['Gula & Sirup Prima', 'Sirup & Pemanis', '0878-5566-7788', 2, 'Kawasan Industri Jababeka']);

    // Ingredients (including exact Design Thinking Canvas simulation: Susu Fresh Milk sisa 2.0 L, baseline 2.5 L -> +40% = 3.5 L -> beli 1.5 L -> Status Merah!)
    $stmtIng = $pdo->prepare("
        INSERT INTO ingredients
        (name, category, unit, current_stock, daily_baseline, min_threshold, safe_threshold, shelf_life_days, is_perishable, weather_sensitivity, unit_price, supplier_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $ingredients = [
        ['Susu Fresh Milk', 'Dairy', 'L', 2.00, 2.50, 2.20, 4.00, 3, 1, 'panas_boost', 24000, 1],
        ['Biji Kopi Arabika House Blend', 'Kopi', 'kg', 1.80, 1.20, 1.00, 2.50, 30, 0, 'panas_boost', 185000, 2],
        ['Croissant Butter Pastry', 'Pastry', 'pcs', 6.00, 12.00, 8.00, 18.00, 2, 1, 'hujan_boost', 14000, 3],
        ['Sirup Gula Aren Organik', 'Sirup & Pemanis', 'L', 2.40, 0.80, 0.70, 2.00, 45, 0, 'panas_boost', 48000, 4],
        ['Bubuk Matcha Uji Grade', 'Kopi', 'kg', 0.90, 0.35, 0.40, 1.00, 60, 0, 'panas_boost', 320000, 2],
        ['Susu Oat Milk Barista', 'Dairy', 'L', 3.50, 1.10, 1.00, 2.50, 5, 1, 'panas_boost', 42000, 1],
        ['Cinnamon Roll Glazed', 'Pastry', 'pcs', 14.00, 8.00, 5.00, 12.00, 2, 1, 'hujan_boost', 13000, 3],
        ['Es Batu Kristal Food Grade', 'Lainnya', 'kg', 9.00, 12.00, 10.00, 20.00, 1, 1, 'panas_boost', 3500, 1],
    ];
    foreach ($ingredients as $ing) {
        $stmtIng->execute($ing);
    }

    // Menus
    $stmtMenu = $pdo->prepare('INSERT INTO menus (name, category, price) VALUES (?, ?, ?)');
    $menus = [
        ['Iced Kopi Susu Aren', 'Kopi Dingin', 26000],
        ['Iced Caffe Latte', 'Kopi Dingin', 28000],
        ['Hot Americano', 'Kopi Panas', 22000],
        ['Iced Matcha Latte', 'Non-Kopi', 30000],
        ['Oat Milk Latte', 'Kopi Dingin', 34000],
        ['Butter Croissant', 'Pastry', 24000],
        ['Warm Cinnamon Roll', 'Pastry', 25000],
    ];
    foreach ($menus as $m) {
        $stmtMenu->execute($m);
    }

    // Menu Recipes (menu_id, ingredient_id, qty_per_portion)
    $stmtRec = $pdo->prepare('INSERT INTO menu_recipes (menu_id, ingredient_id, qty_per_portion) VALUES (?, ?, ?)');
    $recipes = [
        [1, 1, 0.160], // Iced Kopi Susu Aren -> Susu Fresh Milk 160ml
        [1, 2, 0.018], // Biji Kopi 18g
        [1, 4, 0.025], // Gula Aren 25ml
        [1, 8, 0.150], // Es batu 150g
        [2, 1, 0.200], // Iced Caffe Latte -> Susu Fresh Milk 200ml
        [2, 2, 0.018],
        [2, 8, 0.150],
        [3, 2, 0.018], // Hot Americano -> Biji Kopi 18g
        [4, 1, 0.180], // Iced Matcha Latte -> Susu 180ml
        [4, 5, 0.015], // Matcha 15g
        [4, 8, 0.140],
        [5, 6, 0.200], // Oat Milk Latte -> Oat Milk 200ml
        [5, 2, 0.018],
        [5, 8, 0.140],
        [6, 3, 1.000], // Butter Croissant -> 1 pcs
        [7, 7, 1.000], // Cinnamon Roll -> 1 pcs
    ];
    foreach ($recipes as $r) {
        $stmtRec->execute($r);
    }

    // Weather Settings (default: Panas, 33C - matching simulation in 2.png!)
    $pdo->prepare("
        INSERT INTO weather_settings (id, tomorrow_weather, is_weekend, event_note, temperature_c, updated_by)
        VALUES (1, 'panas', 0, 'Prediksi Besok Cerah Terik (+40% Minuman Dingin)', 33, 'BrewStock AI')
    ")->execute();

    // Sample POS Sales (Today & Yesterday)
    $stmtPos = $pdo->prepare(
        'INSERT INTO pos_sales (menu_id, qty, total_price, weather_at_sale, cashier_name, sold_at) VALUES (?, ?, ?, ?, ?, ?)'
    );
    $today = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    $sampleSales = [
        [1, 14, 14 * 26000, 'panas', 'Dimas Saputra', "$today 11:20:00"],
        [2, 9, 9 * 28000, 'panas', 'Dimas Saputra', "$today 14:05:00"],
        [4, 6, 6 * 30000, 'panas', 'Dimas Saputra', "$today 15:40:00"],
        [6, 8, 8 * 24000, 'panas', 'Dimas Saputra', "$today 16:10:00"],
        [3, 5, 5 * 22000, 'normal', 'Dimas Saputra', "$yesterday 09:30:00"],
        [1, 11, 11 * 26000, 'normal', 'Dimas Saputra', "$yesterday 13:15:00"],
        [7, 7, 7 * 25000, 'hujan', 'Dimas Saputra', "$yesterday 17:45:00"],
    ];
    foreach ($sampleSales as $s) {
        $stmtPos->execute($s);
    }

    // Sample Closing Log
    $pdo->prepare("
        INSERT INTO closing_logs (ingredient_id, previous_stock, closing_stock, weather_forecast, predicted_need, recommended_buy, status_color, notes, recorded_by)
        VALUES
        (1, 4.80, 2.00, 'panas', 3.50, 1.50, 'merah', 'Sisa susu menipis menjelang tutup, besok cuaca panas.', 'Nadya Putri'),
        (3, 14.00, 6.00, 'panas', 10.20, 4.20, 'kuning', 'Stok croissant sisa 6 pcs untuk besok pagi.', 'Nadya Putri')
    ")->execute();

    // Sample Purchase Order
    $pdo->prepare("
        INSERT INTO purchase_orders (po_number, ingredient_id, supplier_id, qty, estimated_cost, status, requested_by, notes)
        VALUES
        ('PO-" . date('Ymd') . "-001', 1, 1, 1.50, 36000, 'draft', 'Nadya Putri', 'Rekomendasi otomatis BrewStock AI untuk antisipasi cuaca panas (+40%)')
    ")->execute();

    // Sample Waste Log (historical reference)
    $pdo->prepare("
        INSERT INTO waste_logs (ingredient_id, qty_wasted, reason, loss_amount, recorded_by)
        VALUES
        (3, 2.00, 'Sisa pastry tidak terjual saat hujan lebat (pra-AI)', 28000, 'Nadya Putri')
    ")->execute();
}
