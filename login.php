<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/icons.php';

$pdo = get_db();

if (is_logged_in()) {
    header('Location: ' . (is_admin() ? 'admin.php' : 'user.php'));
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $demoUsername = $_POST['demo_username'] ?? '';
    if ($demoUsername !== '') {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE username = ? AND is_active = 1 LIMIT 1');
        $stmt->execute([$demoUsername]);
        $row = $stmt->fetch();
        if ($row) {
            login_user_record($row);
            header('Location: ' . ($row['role'] === 'admin' ? 'admin.php' : 'user.php'));
            exit;
        }
        $error = 'Akun demo tidak ditemukan atau nonaktif.';
    } else {
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $stmt = $pdo->prepare('SELECT * FROM users WHERE username = ? AND is_active = 1 LIMIT 1');
        $stmt->execute([$username]);
        $row = $stmt->fetch();
        if ($row && password_verify($password, $row['password_hash'])) {
            login_user_record($row);
            header('Location: ' . ($row['role'] === 'admin' ? 'admin.php' : 'user.php'));
            exit;
        }
        $error = 'Username atau password salah.';
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>BrewStock AI — Smart Cafe Inventory</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@500;700&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/glass.css">
</head>
<body>
  <div class="ambient-canvas">
    <div class="orb orb-1"></div>
    <div class="orb orb-2"></div>
    <div class="orb orb-3"></div>
    <div class="orb orb-4"></div>
  </div>

  <main class="login-wrap">
    <section class="glass-panel-strong login-shell">
      <div class="login-hero">
        <div>
          <span class="role-pill" style="background:rgba(255,255,255,0.16); color:#fff8f0; border-color:rgba(255,255,255,0.28);">
            <?= icon('sparkles', 14) ?> BREWSTOCK AI &bull; SMART CAFE
          </span>
          <h1 style="font-size:2rem; font-weight:800; line-height:1.15; margin-top:16px; letter-spacing:-0.03em;">
            Prediksi Stok Kafe Cerdas &amp; Zero Food Waste
          </h1>
          <p style="margin-top:12px; font-size:0.94rem; color:rgba(255,245,235,0.84); line-height:1.6;">
            Ubah data transaksi kasir (POS), sisa closing, dan prakiraan cuaca menjadi keputusan belanja otomatis dengan indikator status <strong>Merah, Kuning, dan Hijau</strong>.
          </p>
        </div>

        <div style="display:flex; flex-direction:column; gap:12px;">
          <div style="padding:14px 16px; border-radius:16px; background:rgba(255,255,255,0.12); border:1px solid rgba(255,255,255,0.2);">
            <div style="font-size:0.74rem; text-transform:uppercase; letter-spacing:0.06em; opacity:0.8; font-weight:700;">Contoh Simulasi AI</div>
            <div style="font-size:0.88rem; margin-top:4px; font-weight:600;">
              Cuaca Panas + Sisa Susu 2L → Prediksi Kopi +40% → <span style="color:#fecaca;">Status Merah • Beli 1.5L Susu</span>
            </div>
          </div>
          <div style="display:flex; gap:14px; font-size:0.8rem; opacity:0.88; flex-wrap:wrap; align-items:center;">
            <span style="display:inline-flex; align-items:center; gap:5px;"><?= icon('check', 14) ?> Closing &lt; 5 Menit</span>
            <span style="display:inline-flex; align-items:center; gap:5px;"><?= icon('check', 14) ?> Anti Overstock / Basi</span>
            <span style="display:inline-flex; align-items:center; gap:5px;"><?= icon('check', 14) ?> Dual View User &amp; Admin</span>
          </div>
        </div>
      </div>

      <div class="login-form-pane">
        <div>
          <h2 style="font-size:1.35rem; font-weight:800;">Masuk ke Aplikasi</h2>
          <p style="font-size:0.85rem; color:var(--text-muted); margin-top:4px;">
            Pilih akses cepat sesuai peran atau login menggunakan akun Anda.
          </p>
        </div>

        <?php if ($error !== ''): ?>
          <div class="badge badge-merah" style="padding:10px 14px; border-radius:12px; width:100%;">
            <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
          </div>
        <?php endif; ?>

        <form method="post" style="display:flex; flex-direction:column; gap:12px;">
          <div>
            <label class="field-label" for="username">Username</label>
            <input class="glass-input" id="username" name="username" type="text" placeholder="contoh: barista atau admin" required>
          </div>
          <div>
            <label class="field-label" for="password">Password</label>
            <input class="glass-input" id="password" name="password" type="password" placeholder="••••••••" required>
          </div>
          <button class="btn btn-primary" type="submit" style="width:100%; padding:12px;">
            Masuk
          </button>
        </form>

        <div style="border-top:1px solid rgba(91,52,35,0.12); padding-top:14px;">
          <div style="font-size:0.76rem; font-weight:700; color:var(--text-muted); margin-bottom:9px; text-transform:uppercase; letter-spacing:0.04em;">
            Akses Cepat Demo (1 Klik)
          </div>
          <div class="demo-cards">
            <form method="post">
              <input type="hidden" name="demo_username" value="barista">
              <button class="glass-card" type="submit" style="width:100%; padding:12px; text-align:left; cursor:pointer; border-radius:14px;">
                <div style="font-size:0.72rem; font-weight:800; color:var(--caramel-500);">TAMPILAN USER</div>
                <div style="font-weight:800; font-size:0.92rem; margin-top:2px;">Barista / Kasir</div>
                <div style="font-size:0.75rem; color:var(--text-muted); margin-top:2px;">Closing, POS &amp; Cek AI</div>
              </button>
            </form>
            <form method="post">
              <input type="hidden" name="demo_username" value="admin">
              <button class="glass-card" type="submit" style="width:100%; padding:12px; text-align:left; cursor:pointer; border-radius:14px;">
                <div style="font-size:0.72rem; font-weight:800; color:var(--espresso-800);">TAMPILAN ADMIN</div>
                <div style="font-weight:800; font-size:0.92rem; margin-top:2px;">Owner Kafe</div>
                <div style="font-size:0.75rem; color:var(--text-muted); margin-top:2px;">Analitik, Resep, PO &amp; User</div>
              </button>
            </form>
          </div>
          <div style="font-size:0.74rem; color:var(--text-muted); margin-top:10px;">
            Akun default: <code class="mono">barista / barista123</code> • <code class="mono">kasir / kasir123</code> • <code class="mono">admin / admin123</code>
          </div>
        </div>
      </div>
    </section>
  </main>
</body>
</html>
