<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/icons.php';

get_db();
require_login();
$user = current_user();
$csrf = csrf_token();
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>BrewStock AI — Tampilan User (Barista &amp; Kasir)</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@500;700&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/glass.css">
</head>
<body data-mode="user" data-csrf="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
  <div class="ambient-canvas">
    <div class="orb orb-1"></div>
    <div class="orb orb-2"></div>
    <div class="orb orb-3"></div>
    <div class="orb orb-4"></div>
  </div>

  <div class="app-shell">
    <!-- SIDEBAR -->
    <aside class="glass-panel sidebar">
      <div class="brand-box">
        <div class="brand-logo"><?= icon('sparkles', 20) ?></div>
        <div>
          <div class="brand-title">BrewStock AI</div>
          <div class="brand-sub">Smart Cafe &bull; Tampilan User</div>
        </div>
      </div>

      <div class="role-pill">
        <?= icon('coffee', 15) ?>
        <span><?= htmlspecialchars($user['job_title'], ENT_QUOTES, 'UTF-8') ?></span>
      </div>

      <nav class="nav-list" id="sidebarNav">
        <button class="nav-btn active" data-tab="user-dashboard">
          <?= icon('sparkles', 17) ?>
          <span>Status &amp; Prediksi AI</span>
          <span class="nav-badge" id="badgeCriticalCount">0</span>
        </button>
        <button class="nav-btn" data-tab="user-closing">
          <?= icon('clipboard', 17) ?>
          <span>Closing Stok (&lt;5m)</span>
        </button>
        <button class="nav-btn" data-tab="user-pos">
          <?= icon('receipt', 17) ?>
          <span>Kasir POS Mini</span>
        </button>
        <button class="nav-btn" data-tab="user-history">
          <?= icon('clock', 17) ?>
          <span>Riwayat &amp; Status PO</span>
        </button>
      </nav>

      <div class="sidebar-footer">
        <?php if (is_admin()): ?>
          <a href="admin.php" class="btn btn-glass btn-sm" style="width:100%;">
            <?= icon('arrow-left', 14) ?> Kembali ke Panel Admin
          </a>
        <?php endif; ?>
        <div class="user-chip">
          <div class="avatar-circle"><?= strtoupper(substr($user['full_name'], 0, 2)) ?></div>
          <div style="min-width:0; flex:1;">
            <div style="font-size:0.84rem; font-weight:700; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
              <?= htmlspecialchars($user['full_name'], ENT_QUOTES, 'UTF-8') ?>
            </div>
            <div style="font-size:0.72rem; color:var(--text-muted);">@<?= htmlspecialchars($user['username'], ENT_QUOTES, 'UTF-8') ?></div>
          </div>
        </div>
        <a href="logout.php" class="btn btn-danger btn-sm" style="width:100%;"><?= icon('log-out', 14) ?> Keluar / Ganti Peran</a>
      </div>
    </aside>

    <!-- MAIN CONTENT -->
    <main class="main-area">
      <header class="glass-panel topbar">
        <div class="page-heading">
          <h1>Operasional Barista &amp; Kasir</h1>
          <p>Pantau stok bahan baku, catat transaksi POS, dan lakukan closing cepat kurang dari 5 menit.</p>
        </div>
        <div class="topbar-actions">
          <div class="weather-toggle-group" id="userWeatherControls" title="Atur prakiraan cuaca besok">
            <button class="weather-pill" data-weather="panas"><?= icon('sun', 15) ?> Panas</button>
            <button class="weather-pill" data-weather="normal"><?= icon('cloud-sun', 15) ?> Normal</button>
            <button class="weather-pill" data-weather="hujan"><?= icon('cloud-rain', 15) ?> Hujan</button>
          </div>
          <button class="btn btn-glass btn-sm" id="btnToggleWeekend" type="button">
            <?= icon('calendar', 14) ?> Akhir Pekan: <strong id="weekendLabel">Tidak</strong>
          </button>
        </div>
      </header>

      <!-- TAB 1: DASHBOARD & REKOMENDASI AI -->
      <section class="tab-view active" id="tab-user-dashboard">
        <div class="kpi-grid" id="userKpiGrid"></div>

        <div class="glass-panel section-card" style="margin-top:18px;">
          <div class="section-header">
            <div>
              <div class="section-title">Bagaimana BrewStock AI Bekerja Hari Ini</div>
              <div class="section-sub" id="userWeatherNote">Memuat analisis cuaca &amp; POS...</div>
            </div>
            <button class="btn btn-primary btn-sm" type="button" onclick="switchTab('user-closing')">
              <?= icon('clipboard', 14) ?> Input Closing Sekarang
            </button>
          </div>
          <div id="userSimulationBanner" class="sim-banner"></div>
        </div>

        <div class="glass-panel section-card" style="margin-top:18px;">
          <div class="section-header">
            <div>
              <div class="section-title">Status Stok &amp; Rekomendasi Belanja Esok Hari</div>
              <div class="section-sub">Merah = Kritis &bull; Kuning = Hampir Habis &bull; Hijau = Aman</div>
            </div>
            <div style="display:flex; gap:8px; flex-wrap:wrap;">
              <input class="glass-input" id="userStockSearch" type="search" placeholder="Cari bahan baku..." style="width:210px; padding:8px 12px;">
              <select class="glass-select" id="userStatusFilter" style="width:160px; padding:8px 12px;">
                <option value="all">Semua Status</option>
                <option value="merah">Merah (Kritis)</option>
                <option value="kuning">Kuning (Hampir Habis)</option>
                <option value="hijau">Hijau (Aman)</option>
              </select>
            </div>
          </div>

          <div class="table-wrap">
            <table class="glass-table">
              <thead>
                <tr>
                  <th>Bahan Baku</th>
                  <th>Sisa Closing</th>
                  <th>Prediksi Besok (AI)</th>
                  <th>Status</th>
                  <th>Rekomendasi Belanja</th>
                  <th>Catatan Zero Waste</th>
                  <th>Aksi</th>
                </tr>
              </thead>
              <tbody id="userPredictionTable"></tbody>
            </table>
          </div>
        </div>
      </section>

      <!-- TAB 2: CLOSING STOK (< 5 MENIT) -->
      <section class="tab-view" id="tab-user-closing">
        <div class="glass-panel section-card">
          <div class="section-header">
            <div>
              <div class="section-title">Form Closing Stok Cepat (&lt; 5 Menit)</div>
              <div class="section-sub">Masukkan sisa fisik bahan baku saat kafe akan tutup. BrewStock AI otomatis menghitung kebutuhan besok.</div>
            </div>
          </div>

          <form id="closingForm">
            <div class="grid-2" id="closingGrid" style="gap:14px;"></div>

            <div class="glass-card" style="padding:16px; margin-top:18px; display:flex; flex-wrap:wrap; gap:12px; align-items:end; justify-content:space-between;">
              <div style="flex:1; min-width:240px;">
                <label class="field-label" for="closingNotes">Catatan Shift Closing</label>
                <input class="glass-input" id="closingNotes" type="text" placeholder="Contoh: Susu habis di jam ramai sore, besok prediksi panas" value="Closing harian selesai tepat waktu">
              </div>
              <button class="btn btn-primary" type="submit" style="padding:12px 22px;">
                <?= icon('sparkles', 16) ?> Simpan Closing &amp; Hitung Prediksi AI
              </button>
            </div>
          </form>
        </div>
      </section>

      <!-- TAB 3: KASIR POS MINI -->
      <section class="tab-view" id="tab-user-pos">
        <div class="pos-grid">
          <div class="glass-panel section-card">
            <div class="section-header">
              <div>
                <div class="section-title">Menu Kafe (Kasir POS)</div>
                <div class="section-sub">Setiap transaksi otomatis mencatat data POS dan mengurangi estimasi stok bahan baku via resep.</div>
              </div>
            </div>
            <div class="menu-cards" id="posMenuGrid"></div>
          </div>

          <div class="glass-panel-strong section-card" style="display:flex; flex-direction:column; justify-content:space-between; gap:14px;">
            <div>
              <div class="section-title">Keranjang Pesanan</div>
              <div class="section-sub">Klik menu di samping untuk menambah pesanan</div>
              <div id="posCartList" style="margin-top:14px; display:flex; flex-direction:column; gap:10px;"></div>
            </div>

            <div style="border-top:1px solid rgba(91,52,35,0.14); padding-top:14px;">
              <div style="display:flex; justify-content:space-between; align-items:center; font-weight:800; font-size:1.1rem; margin-bottom:12px;">
                <span>Total Bayar</span>
                <span class="mono" id="posCartTotal">Rp 0</span>
              </div>
              <div style="display:flex; gap:8px;">
                <button class="btn btn-glass" type="button" id="btnClearCart" style="flex:1;">Kosongkan</button>
                <button class="btn btn-primary" type="button" id="btnCheckoutPos" style="flex:2;">Catat Transaksi</button>
              </div>
            </div>
          </div>
        </div>
      </section>

      <!-- TAB 4: RIWAYAT & STATUS PO -->
      <section class="tab-view" id="tab-user-history">
        <div class="grid-2">
          <div class="glass-panel section-card">
            <div class="section-header">
              <div>
                <div class="section-title">Pengajuan Restock / PO</div>
                <div class="section-sub">Status permintaan pembelian bahan ke Owner &amp; Supplier</div>
              </div>
            </div>
            <div class="table-wrap">
              <table class="glass-table">
                <thead>
                  <tr>
                    <th>No. PO</th>
                    <th>Bahan</th>
                    <th>Jumlah</th>
                    <th>Status</th>
                  </tr>
                </thead>
                <tbody id="userPoTable"></tbody>
              </table>
            </div>
          </div>

          <div class="glass-panel section-card">
            <div class="section-header">
              <div>
                <div class="section-title">Transaksi Kasir (POS) Terbaru</div>
                <div class="section-sub">Data input utama untuk analisis pola BrewStock AI</div>
              </div>
            </div>
            <div class="table-wrap">
              <table class="glass-table">
                <thead>
                  <tr>
                    <th>Waktu</th>
                    <th>Menu</th>
                    <th>Qty</th>
                    <th>Total</th>
                  </tr>
                </thead>
                <tbody id="userPosHistoryTable"></tbody>
              </table>
            </div>
          </div>
        </div>

        <div class="glass-panel section-card" style="margin-top:18px;">
          <div class="section-header">
            <div>
              <div class="section-title">Riwayat Closing Barista</div>
              <div class="section-sub">Rekam jejak pengecekan stok tutup kafe</div>
            </div>
          </div>
          <div class="table-wrap">
            <table class="glass-table">
              <thead>
                <tr>
                  <th>Waktu</th>
                  <th>Bahan</th>
                  <th>Sisa Closing</th>
                  <th>Cuaca</th>
                  <th>Prediksi AI</th>
                  <th>Rekomendasi Beli</th>
                  <th>Status</th>
                  <th>Oleh</th>
                </tr>
              </thead>
              <tbody id="userClosingHistoryTable"></tbody>
            </table>
          </div>
        </div>
      </section>
    </main>
  </div>

  <!-- MOBILE BOTTOM NAV -->
  <nav class="glass-panel-strong mobile-nav-bar" id="mobileNav">
    <button class="nav-btn active" data-tab="user-dashboard"><?= icon('sparkles', 15) ?> AI Stok</button>
    <button class="nav-btn" data-tab="user-closing"><?= icon('clipboard', 15) ?> Closing</button>
    <button class="nav-btn" data-tab="user-pos"><?= icon('receipt', 15) ?> POS</button>
    <button class="nav-btn" data-tab="user-history"><?= icon('clock', 15) ?> Riwayat</button>
  </nav>

  <div class="toast-stack" id="toastStack"></div>
  <script src="assets/js/app.js"></script>
</body>
</html>
