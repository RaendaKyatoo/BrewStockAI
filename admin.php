<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/icons.php';

get_db();
require_admin();
$user = current_user();
$csrf = csrf_token();
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>BrewStock AI — Panel Admin (Owner Kafe)</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@500;700&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/glass.css">
</head>
<body data-mode="admin" data-csrf="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
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
          <div class="brand-sub">Smart Cafe &bull; Panel Admin</div>
        </div>
      </div>

      <div class="role-pill">
        <?= icon('crown', 15) ?>
        <span><?= htmlspecialchars($user['job_title'], ENT_QUOTES, 'UTF-8') ?></span>
      </div>

      <nav class="nav-list" id="sidebarNav">
        <button class="nav-btn active" data-tab="admin-overview">
          <?= icon('bar-chart', 17) ?>
          <span>Overview &amp; Zero Waste</span>
          <span class="nav-badge" id="badgeCriticalCount">0</span>
        </button>
        <button class="nav-btn" data-tab="admin-ai-sim">
          <?= icon('sparkles', 17) ?>
          <span>Engine &amp; Simulasi AI</span>
        </button>
        <button class="nav-btn" data-tab="admin-ingredients">
          <?= icon('droplet', 17) ?>
          <span>Bahan Baku</span>
        </button>
        <button class="nav-btn" data-tab="admin-recipes">
          <?= icon('coffee', 17) ?>
          <span>Menu &amp; Resep</span>
        </button>
        <button class="nav-btn" data-tab="admin-po">
          <?= icon('package', 17) ?>
          <span>PO &amp; Supplier</span>
        </button>
        <button class="nav-btn" data-tab="admin-users">
          <?= icon('users', 17) ?>
          <span>User &amp; Log Waste</span>
        </button>
      </nav>

      <div class="sidebar-footer">
        <a href="user.php" class="btn btn-glass btn-sm" style="width:100%;">
          <?= icon('eye', 14) ?> Lihat Tampilan User
        </a>
        <div class="user-chip">
          <div class="avatar-circle"><?= strtoupper(substr($user['full_name'], 0, 2)) ?></div>
          <div style="min-width:0; flex:1;">
            <div style="font-size:0.84rem; font-weight:700; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
              <?= htmlspecialchars($user['full_name'], ENT_QUOTES, 'UTF-8') ?>
            </div>
            <div style="font-size:0.72rem; color:var(--text-muted);">Admin / Owner</div>
          </div>
        </div>
        <a href="logout.php" class="btn btn-danger btn-sm" style="width:100%;"><?= icon('log-out', 14) ?> Keluar</a>
      </div>
    </aside>

    <!-- MAIN CONTENT -->
    <main class="main-area">
      <header class="glass-panel topbar">
        <div class="page-heading">
          <h1>Dashboard Owner &amp; Manajemen BrewStock AI</h1>
          <p>Kontrol penuh persediaan bahan baku, resep konversi POS, simulasi cuaca AI, PO supplier, dan pencegahan food waste.</p>
        </div>
        <div class="topbar-actions">
          <div class="weather-toggle-group" id="adminWeatherControls" title="Set prakiraan cuaca live sistem">
            <button class="weather-pill" data-weather="panas"><?= icon('sun', 15) ?> Panas</button>
            <button class="weather-pill" data-weather="normal"><?= icon('cloud-sun', 15) ?> Normal</button>
            <button class="weather-pill" data-weather="hujan"><?= icon('cloud-rain', 15) ?> Hujan</button>
          </div>
          <button class="btn btn-glass btn-sm" id="btnToggleWeekend" type="button">
            <?= icon('calendar', 14) ?> Akhir Pekan: <strong id="weekendLabel">Tidak</strong>
          </button>
        </div>
      </header>

      <!-- TAB 1: OVERVIEW & ZERO WASTE -->
      <section class="tab-view active" id="tab-admin-overview">
        <div class="kpi-grid" id="adminKpiGrid"></div>

        <div class="grid-2" style="margin-top:18px;">
          <div class="glass-panel section-card">
            <div class="section-header">
              <div>
                <div class="section-title">Stok Saat Ini vs Prediksi Kebutuhan Besok</div>
                <div class="section-sub">Perbandingan kapasitas stok closing terhadap kebutuhan hasil kalkulasi AI</div>
              </div>
            </div>
            <div id="adminStockChart" style="display:flex; flex-direction:column; gap:12px;"></div>
          </div>

          <div class="glass-panel section-card">
            <div class="section-header">
              <div>
                <div class="section-title">Radar Zero Waste &amp; Prioritas Belanja</div>
                <div class="section-sub">Deteksi dini bahan mudah basi &amp; item kritis yang perlu PO segera</div>
              </div>
              <button class="btn btn-primary btn-sm" type="button" id="btnAutoGeneratePo">
                <?= icon('zap', 14) ?> Auto-PO Item Kritis
              </button>
            </div>
            <div id="adminPriorityFeed" style="display:flex; flex-direction:column; gap:10px;"></div>
          </div>
        </div>

        <div class="glass-panel section-card" style="margin-top:18px;">
          <div class="section-header">
            <div>
              <div class="section-title">Ringkasan Keputusan Belanja AI Semua Bahan Baku</div>
              <div class="section-sub">Berdasarkan data POS, resep takaran, sisa closing, dan prakiraan cuaca aktif</div>
            </div>
          </div>
          <div class="table-wrap">
            <table class="glass-table">
              <thead>
                <tr>
                  <th>Bahan Baku</th>
                  <th>Stok Closing</th>
                  <th>Baseline / POS Hari Ini</th>
                  <th>Prediksi Besok</th>
                  <th>Status</th>
                  <th>Rekomendasi Beli</th>
                  <th>Estimasi Biaya</th>
                </tr>
              </thead>
              <tbody id="adminOverviewTable"></tbody>
            </table>
          </div>
        </div>
      </section>

      <!-- TAB 2: ENGINE & SIMULASI AI (SESUAI 2.PNG) -->
      <section class="tab-view" id="tab-admin-ai-sim">
        <div class="glass-panel section-card">
          <div class="section-header">
            <div>
              <div class="section-title">Bagaimana BrewStock AI Bekerja?</div>
              <div class="section-sub">Mengubah data transaksi kasir dan prakiraan cuaca menjadi keputusan stok yang lebih cerdas.</div>
            </div>
            <span class="badge badge-neutral">Pipeline Prediksi Real-Time</span>
          </div>

          <div class="pipeline-grid">
            <div class="glass-card pipeline-stage">
              <div class="pipeline-head">
                <div class="pipeline-icon"><?= icon('database', 19) ?></div>
                <div>
                  <div style="font-weight:800; font-size:0.98rem;">INPUT DATA</div>
                  <div style="font-size:0.78rem; color:var(--text-muted);">Sumber Data Kafe</div>
                </div>
              </div>
              <div class="pipeline-item">
                <strong>Data Kasir (POS)</strong>
                <span>Riwayat transaksi harian menu</span>
              </div>
              <div class="pipeline-item">
                <strong>Stock &amp; Resep</strong>
                <span>Sisa bahan saat closing &amp; takaran</span>
              </div>
              <div class="pipeline-item">
                <strong>Prakiraan Cuaca</strong>
                <span>Kondisi panas, normal, atau hujan</span>
              </div>
            </div>

            <div class="pipeline-arrow"><?= icon('arrow-right', 20) ?></div>

            <div class="glass-card pipeline-stage">
              <div class="pipeline-head">
                <div class="pipeline-icon"><?= icon('sparkles', 19) ?></div>
                <div>
                  <div style="font-weight:800; font-size:0.98rem;">PROSES AI</div>
                  <div style="font-size:0.78rem; color:var(--text-muted);">Prediksi &amp; Kalkulasi</div>
                </div>
              </div>
              <div class="pipeline-item">
                <strong>Analisis Pola</strong>
                <span>Korelasi cuaca, akhir pekan &amp; penjualan</span>
              </div>
              <div class="pipeline-item">
                <strong>Konversi Resep</strong>
                <span>Hitung takaran bahan bersih per porsi</span>
              </div>
              <div class="pipeline-item">
                <strong>Deteksi Risiko</strong>
                <span>Cegah overstock basi &amp; stockout jam ramai</span>
              </div>
            </div>

            <div class="pipeline-arrow"><?= icon('arrow-right', 20) ?></div>

            <div class="glass-card pipeline-stage">
              <div class="pipeline-head">
                <div class="pipeline-icon"><?= icon('zap', 19) ?></div>
                <div>
                  <div style="font-weight:800; font-size:0.98rem;">OUTPUT SISTEM</div>
                  <div style="font-size:0.78rem; color:var(--text-muted);">Keputusan Belanja</div>
                </div>
              </div>
              <div class="pipeline-item">
                <strong>Takaran Belanja</strong>
                <span>Rekomendasi beli otomatis per satuan</span>
              </div>
              <div class="pipeline-item">
                <strong>Status Stok</strong>
                <span>Merah (Kritis), Kuning, dan Hijau (Aman)</span>
              </div>
              <div class="pipeline-item">
                <strong>Zero Waste</strong>
                <span>Minimalkan bahan baku mudah basi</span>
              </div>
            </div>
          </div>

          <!-- Interactive Scenario Simulator -->
          <div class="glass-card" style="margin-top:18px; padding:18px;">
            <div class="section-header" style="margin-bottom:10px;">
              <div>
                <div class="section-title">Simulator Skenario AI (Uji Tanpa Mengubah Data Live)</div>
                <div class="section-sub">Coba kombinasi cuaca &amp; akhir pekan untuk melihat perubahan status dan rekomendasi belanja.</div>
              </div>
              <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
                <select class="glass-select" id="simWeatherSelect" style="width:160px;">
                  <option value="panas">Cuaca Panas</option>
                  <option value="normal">Cuaca Normal</option>
                  <option value="hujan">Cuaca Hujan</option>
                </select>
                <label style="display:inline-flex; align-items:center; gap:6px; font-size:0.84rem; font-weight:700; cursor:pointer;">
                  <input type="checkbox" id="simWeekendCheck"> Malam Minggu / Akhir Pekan
                </label>
                <button class="btn btn-primary btn-sm" type="button" id="btnRunSim">Jalankan Simulasi</button>
                <button class="btn btn-glass btn-sm" type="button" id="btnApplySimLive">Terapkan ke Live</button>
              </div>
            </div>

            <div class="sim-banner" id="simHighlightBanner"></div>

            <div class="table-wrap" style="margin-top:14px;">
              <table class="glass-table">
                <thead>
                  <tr>
                    <th>Bahan Baku</th>
                    <th>Stok Saat Ini</th>
                    <th>Faktor AI</th>
                    <th>Proyeksi Kebutuhan</th>
                    <th>Status Simulasi</th>
                    <th>Rekomendasi Beli</th>
                    <th>Analisis Zero Waste</th>
                  </tr>
                </thead>
                <tbody id="simResultTable"></tbody>
              </table>
            </div>
          </div>
        </div>
      </section>

      <!-- TAB 3: INVENTORI BAHAN BAKU -->
      <section class="tab-view" id="tab-admin-ingredients">
        <div class="glass-panel section-card">
          <div class="section-header">
            <div>
              <div class="section-title">Manajemen Bahan Baku &amp; Parameter AI</div>
              <div class="section-sub">Atur stok, baseline pemakaian harian, umur simpan (shelf life), dan sensitivitas cuaca.</div>
            </div>
            <button class="btn btn-primary btn-sm" type="button" onclick="openIngredientModal()">+ Tambah Bahan Baku</button>
          </div>
          <div class="table-wrap">
            <table class="glass-table">
              <thead>
                <tr>
                  <th>Nama Bahan</th>
                  <th>Kategori</th>
                  <th>Stok</th>
                  <th>Baseline/Hari</th>
                  <th>Sensitivitas Cuaca</th>
                  <th>Umur Simpan</th>
                  <th>Harga/Unit</th>
                  <th>Supplier</th>
                  <th>Aksi</th>
                </tr>
              </thead>
              <tbody id="adminIngredientsTable"></tbody>
            </table>
          </div>
        </div>
      </section>

      <!-- TAB 4: MENU & RESEP TAKARAN -->
      <section class="tab-view" id="tab-admin-recipes">
        <div class="glass-panel section-card">
          <div class="section-header">
            <div>
              <div class="section-title">Menu Kafe &amp; Resep Konversi Takaran</div>
              <div class="section-sub">Setiap penjualan di Kasir POS otomatis memotong bahan baku sesuai takaran resep di bawah ini.</div>
            </div>
            <button class="btn btn-primary btn-sm" type="button" onclick="openMenuModal()">+ Tambah Menu &amp; Resep</button>
          </div>
          <div class="grid-3" id="adminRecipesGrid"></div>
        </div>
      </section>

      <!-- TAB 5: PURCHASE ORDER & SUPPLIER -->
      <section class="tab-view" id="tab-admin-po">
        <div class="glass-panel section-card">
          <div class="section-header">
            <div>
              <div class="section-title">Daftar Purchase Order (PO)</div>
              <div class="section-sub">Setujui pengajuan dari barista, kirim ke supplier, dan klik "Terima" untuk menambah stok otomatis.</div>
            </div>
            <button class="btn btn-primary btn-sm" type="button" onclick="triggerAutoPo()"><?= icon('zap', 14) ?> Buat PO Otomatis (Kritis)</button>
          </div>
          <div class="table-wrap">
            <table class="glass-table">
              <thead>
                <tr>
                  <th>No. PO</th>
                  <th>Bahan</th>
                  <th>Supplier</th>
                  <th>Jumlah</th>
                  <th>Est. Biaya</th>
                  <th>Pemohon</th>
                  <th>Status</th>
                  <th>Aksi</th>
                </tr>
              </thead>
              <tbody id="adminPoTable"></tbody>
            </table>
          </div>
        </div>

        <div class="glass-panel section-card" style="margin-top:18px;">
          <div class="section-header">
            <div>
              <div class="section-title">Direktori Supplier Kafe</div>
              <div class="section-sub">Mitra pemasok bahan baku harian &amp; lead time pengiriman</div>
            </div>
            <button class="btn btn-glass btn-sm" type="button" onclick="openSupplierModal()">+ Tambah Supplier</button>
          </div>
          <div class="table-wrap">
            <table class="glass-table">
              <thead>
                <tr>
                  <th>Nama Supplier</th>
                  <th>Kategori</th>
                  <th>Telepon / WA</th>
                  <th>Lead Time</th>
                  <th>Alamat</th>
                  <th>Aksi</th>
                </tr>
              </thead>
              <tbody id="adminSuppliersTable"></tbody>
            </table>
          </div>
        </div>
      </section>

      <!-- TAB 6: MANAJEMEN USER & LOG WASTE -->
      <section class="tab-view" id="tab-admin-users">
        <div class="grid-2">
          <div class="glass-panel section-card">
            <div class="section-header">
              <div>
                <div class="section-title">Manajemen Akun Pengguna</div>
                <div class="section-sub">Kelola akses User (Barista/Kasir) dan Admin (Owner)</div>
              </div>
              <button class="btn btn-primary btn-sm" type="button" onclick="openUserModal()">+ Tambah Pengguna</button>
            </div>
            <div class="table-wrap">
              <table class="glass-table">
                <thead>
                  <tr>
                    <th>Nama &amp; Jabatan</th>
                    <th>Username</th>
                    <th>Peran</th>
                    <th>Status</th>
                    <th>Aksi</th>
                  </tr>
                </thead>
                <tbody id="adminUsersTable"></tbody>
              </table>
            </div>
          </div>

          <div class="glass-panel section-card">
            <div class="section-header">
              <div>
                <div class="section-title">Catatan Bahan Basi / Food Waste</div>
                <div class="section-sub">Pantau bahan kedaluwarsa untuk mengukur efektivitas Zero Waste AI</div>
              </div>
              <button class="btn btn-danger btn-sm" type="button" onclick="openWasteModal()">+ Catat Waste</button>
            </div>
            <div class="table-wrap">
              <table class="glass-table">
                <thead>
                  <tr>
                    <th>Tanggal</th>
                    <th>Bahan</th>
                    <th>Terbuang</th>
                    <th>Kerugian</th>
                    <th>Alasan</th>
                  </tr>
                </thead>
                <tbody id="adminWasteTable"></tbody>
              </table>
            </div>
          </div>
        </div>
      </section>
    </main>
  </div>

  <!-- MOBILE BOTTOM NAV -->
  <nav class="glass-panel-strong mobile-nav-bar" id="mobileNav">
    <button class="nav-btn active" data-tab="admin-overview"><?= icon('bar-chart', 15) ?> Overview</button>
    <button class="nav-btn" data-tab="admin-ai-sim"><?= icon('sparkles', 15) ?> AI Sim</button>
    <button class="nav-btn" data-tab="admin-ingredients"><?= icon('droplet', 15) ?> Bahan</button>
    <button class="nav-btn" data-tab="admin-recipes"><?= icon('coffee', 15) ?> Resep</button>
    <button class="nav-btn" data-tab="admin-po"><?= icon('package', 15) ?> PO</button>
    <button class="nav-btn" data-tab="admin-users"><?= icon('users', 15) ?> User</button>
  </nav>

  <!-- REUSABLE GLASS MODAL -->
  <div class="modal-backdrop" id="glassModal">
    <div class="glass-panel-strong modal-box">
      <div class="section-header" style="margin-bottom:14px;">
        <div class="section-title" id="modalTitle">Form</div>
        <button class="btn btn-glass btn-sm" type="button" onclick="closeModal()"><?= icon('x', 15) ?></button>
      </div>
      <div id="modalBody"></div>
    </div>
  </div>

  <div class="toast-stack" id="toastStack"></div>
  <script src="assets/js/app.js"></script>
</body>
</html>
