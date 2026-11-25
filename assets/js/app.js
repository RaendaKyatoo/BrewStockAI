(() => {
  const mode = document.body.dataset.mode || 'user';
  const csrfToken = document.body.dataset.csrf || '';

  let appState = null;
  let posCart = []; // [{ menu_id, name, price, qty }]

  const esc = (v) => {
    const d = document.createElement('div');
    d.textContent = v == null ? '' : String(v);
    return d.innerHTML;
  };

  const fmtRp = (num) => 'Rp ' + Number(num || 0).toLocaleString('id-ID');
  const fmtNum = (num) => {
    const n = Number(num || 0);
    return Number.isInteger(n) ? String(n) : n.toFixed(2).replace(/\.?0+$/, '');
  };

  function showToast(msg, isError = false) {
    const stack = document.getElementById('toastStack');
    if (!stack) return;
    const el = document.createElement('div');
    el.className = 'toast-item' + (isError ? ' error' : '');
    el.textContent = msg;
    stack.appendChild(el);
    setTimeout(() => {
      el.style.opacity = '0';
      el.style.transform = 'translateY(8px)';
      setTimeout(() => el.remove(), 220);
    }, 3400);
  }

  async function apiPost(action, payload = {}) {
    const res = await fetch(`api.php?action=${encodeURIComponent(action)}`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': csrfToken,
      },
      body: JSON.stringify({ action, csrf_token: csrfToken, ...payload }),
    });
    const json = await res.json();
    if (!res.ok || !json.ok) {
      throw new Error(json.error || 'Terjadi kesalahan pada server.');
    }
    if (json.data) {
      appState = json.data;
      renderAll();
    }
    if (json.message) {
      showToast(json.message);
    }
    return json;
  }

  async function loadInitialState() {
    const res = await fetch('api.php?action=get_state');
    const json = await res.json();
    if (!res.ok || !json.ok) {
      showToast(json.error || 'Gagal memuat data.', true);
      return;
    }
    appState = json.data;
    renderAll();
    if (mode === 'admin') {
      runSimulation();
    }
  }

  // ==================== TAB NAVIGATION ====================
  window.switchTab = (tabId) => {
    document.querySelectorAll('.tab-view').forEach((sec) => {
      sec.classList.toggle('active', sec.id === `tab-${tabId}`);
    });
    document.querySelectorAll('.nav-btn[data-tab]').forEach((btn) => {
      btn.classList.toggle('active', btn.dataset.tab === tabId);
    });
  };

  document.querySelectorAll('.nav-btn[data-tab]').forEach((btn) => {
    btn.addEventListener('click', () => window.switchTab(btn.dataset.tab));
  });

  // ==================== MODAL HELPERS ====================
  window.openModal = (title, html) => {
    const modal = document.getElementById('glassModal');
    if (!modal) return;
    document.getElementById('modalTitle').textContent = title;
    document.getElementById('modalBody').innerHTML = html;
    modal.classList.add('open');
  };

  window.closeModal = () => {
    const modal = document.getElementById('glassModal');
    if (modal) modal.classList.remove('open');
  };

  document.getElementById('glassModal')?.addEventListener('click', (e) => {
    if (e.target.id === 'glassModal') window.closeModal();
  });

  // ==================== WEATHER & WEEKEND CONTROLS ====================
  function renderWeatherControls() {
    if (!appState) return;
    const w = appState.ai.weather;
    document.querySelectorAll('.weather-pill[data-weather]').forEach((btn) => {
      btn.classList.toggle('active', btn.dataset.weather === w.condition);
    });
    const wkLabel = document.getElementById('weekendLabel');
    if (wkLabel) wkLabel.textContent = w.is_weekend ? 'Ya (+25%)' : 'Tidak';

    const badge = document.getElementById('badgeCriticalCount');
    if (badge) badge.textContent = String(appState.ai.summary.counts.merah || 0);
  }

  document.querySelectorAll('.weather-pill[data-weather]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      if (!appState) return;
      try {
        await apiPost('update_weather', {
          tomorrow_weather: btn.dataset.weather,
          is_weekend: appState.ai.weather.is_weekend,
        });
      } catch (err) {
        showToast(err.message, true);
      }
    });
  });

  document.getElementById('btnToggleWeekend')?.addEventListener('click', async () => {
    if (!appState) return;
    try {
      await apiPost('update_weather', {
        tomorrow_weather: appState.ai.weather.condition,
        is_weekend: !appState.ai.weather.is_weekend,
      });
    } catch (err) {
      showToast(err.message, true);
    }
  });

  const ICON_PATHS = {
    sun: '<circle cx="12" cy="12" r="4"/><path d="M12 2v2"/><path d="M12 20v2"/><path d="m4.93 4.93 1.41 1.41"/><path d="m17.66 17.66 1.41 1.41"/><path d="M2 12h2"/><path d="M20 12h2"/><path d="m6.34 17.66-1.41 1.41"/><path d="m19.07 4.93-1.41 1.41"/>',
    'cloud-sun': '<path d="M12 2v2"/><path d="m4.93 4.93 1.41 1.41"/><path d="M20 12h2"/><path d="m19.07 4.93-1.41 1.41"/><path d="M15.947 12.65a4 4 0 0 0-5.925-4.128"/><path d="M13 22H7a5 5 0 1 1 4.9-6H13a3 3 0 0 1 0 6Z"/>',
    'cloud-rain': '<path d="M4 14.899A7 7 0 1 1 15.71 8h1.79a4.5 4.5 0 0 1 2.5 8.242"/><path d="M16 14v6"/><path d="M8 14v6"/><path d="M12 16v6"/>',
    'alert-circle': '<circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/>',
    'alert-triangle': '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><line x1="12" x2="12" y1="9" y2="13"/><line x1="12" x2="12.01" y1="17" y2="17"/>',
    'check-circle': '<circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/>',
    leaf: '<path d="M11 20A7 7 0 0 1 9.8 6.1C15.5 5 17 4.48 19 2c1 2 2 4.18 2 8 0 5.5-4.78 10-10 10Z"/><path d="M2 21c0-3 1.85-5.36 5.08-6C9.5 14.52 12 13 13 12"/>',
    wallet: '<path d="M19 7V4a1 1 0 0 0-1-1H5a2 2 0 0 0 0 4h15a1 1 0 0 1 1 1v4h-3a2 2 0 0 0 0 4h3a1 1 0 0 0 1-1v-2a1 1 0 0 0-1-1"/><path d="M3 5v14a2 2 0 0 0 2 2h15a1 1 0 0 0 1-1v-4"/>',
    receipt: '<path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2l-2 1-2-1-2 1-2-1-2 1-2-1-2 1Z"/><path d="M16 8h-6a2 2 0 1 0 0 4h4a2 2 0 1 1 0 4H8"/><path d="M12 17.5v-11"/>',
  };

  function svgIcon(name, size = 15, color = '') {
    const p = ICON_PATHS[name] || ICON_PATHS['alert-circle'];
    const style = color ? ` style="color:${color}"` : '';
    return `<svg class="svg-icon" width="${size}" height="${size}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"${style} aria-hidden="true">${p}</svg>`;
  }

  function statusBadge(status, label) {
    return `<span class="badge badge-${esc(status)}"><span class="badge-dot"></span>${esc(label)}</span>`;
  }

  function weatherLabel(w) {
    if (w === 'panas') return `<span style="display:inline-flex;align-items:center;gap:5px;">${svgIcon('sun', 14)} Panas</span>`;
    if (w === 'hujan') return `<span style="display:inline-flex;align-items:center;gap:5px;">${svgIcon('cloud-rain', 14)} Hujan</span>`;
    return `<span style="display:inline-flex;align-items:center;gap:5px;">${svgIcon('cloud-sun', 14)} Normal</span>`;
  }

  function sensitivityLabel(s) {
    if (s === 'panas_boost') return 'Naik saat Panas (+40%)';
    if (s === 'hujan_boost') return 'Naik saat Hujan (+25%)';
    return 'Stabil / Netral';
  }

  // ==================== RENDER DISPATCHER ====================
  function renderAll() {
    if (!appState) return;
    renderWeatherControls();
    if (mode === 'user') {
      renderUserView();
    } else {
      renderAdminView();
    }
  }

  // ==================== USER VIEW RENDERING ====================
  function renderUserView() {
    const { ai, menus, purchase_orders, pos_sales, closing_logs } = appState;
    const counts = ai.summary.counts;

    // 1. KPI Grid
    const kpiEl = document.getElementById('userKpiGrid');
    if (kpiEl) {
      kpiEl.innerHTML = `
        <div class="glass-card kpi-card">
          <span class="kpi-label">${svgIcon('alert-circle', 15, '#dc2626')} Status Merah (Kritis)</span>
          <span class="kpi-value" style="color:#b91c1c;">${counts.merah} Item</span>
          <span class="kpi-sub">Perlu segera ajukan restock</span>
        </div>
        <div class="glass-card kpi-card">
          <span class="kpi-label">${svgIcon('alert-triangle', 15, '#d97706')} Kuning (Hampir Habis)</span>
          <span class="kpi-value" style="color:#b45309;">${counts.kuning} Item</span>
          <span class="kpi-sub">Siapkan pembelian esok hari</span>
        </div>
        <div class="glass-card kpi-card">
          <span class="kpi-label">${svgIcon('check-circle', 15, '#059669')} Hijau (Aman)</span>
          <span class="kpi-value" style="color:#047857;">${counts.hijau} Item</span>
          <span class="kpi-sub">Cukup untuk operasional besok</span>
        </div>
        <div class="glass-card kpi-card">
          <span class="kpi-label">${svgIcon('leaf', 15, '#7c3aed')} Peringatan Zero Waste</span>
          <span class="kpi-value" style="color:#6b21a8;">${counts.waste_alert} Alert</span>
          <span class="kpi-sub">Cegah bahan mudah basi menumpuk</span>
        </div>
      `;
    }

    // 2. Simulation Highlight Banner (Matches Design 2.png!)
    const noteEl = document.getElementById('userWeatherNote');
    if (noteEl) {
      noteEl.innerHTML = `${weatherLabel(ai.weather.condition)} (${esc(ai.weather.temperature_c)}°C) &bull; ${esc(ai.weather.event_note)}`;
    }

    const simBanner = document.getElementById('userSimulationBanner');
    if (simBanner) {
      const topCritical = ai.items.find((i) => i.status === 'merah') || ai.items[0];
      if (topCritical) {
        const pct = topCritical.total_pct >= 0 ? `+${topCritical.total_pct}%` : `${topCritical.total_pct}%`;
        simBanner.innerHTML = `
          <span class="badge badge-neutral">ANALISIS AI AKTIF</span>
          <span>
            Input: <strong>${weatherLabel(ai.weather.condition)}</strong> &amp; sisa <strong>${esc(topCritical.name)} ${fmtNum(topCritical.current_stock)} ${esc(topCritical.unit)}</strong>
            → AI: Prediksi kebutuhan <strong>${pct} (${fmtNum(topCritical.predicted_need)} ${esc(topCritical.unit)})</strong>
            → Output: <strong>Status ${esc(topCritical.status_label)}</strong>
            ${topCritical.recommended_buy > 0 ? `• Rekomendasi Beli <strong>${fmtNum(topCritical.recommended_buy)} ${esc(topCritical.unit)}</strong>` : '• Stok Cukup'}
          </span>
        `;
      }
    }

    // 3. Prediction Table
    renderUserPredictionTable();

    // 4. Closing Form Grid
    const closingGrid = document.getElementById('closingGrid');
    if (closingGrid) {
      closingGrid.innerHTML = ai.items.map((item) => `
        <div class="glass-card" style="padding:14px 16px; display:flex; align-items:center; justify-content:space-between; gap:12px;">
          <div style="min-width:0; flex:1;">
            <div style="font-weight:800; font-size:0.94rem;">${esc(item.name)}</div>
            <div style="font-size:0.76rem; color:var(--text-muted); margin-top:2px;">
              ${esc(item.category)} • Baseline ${fmtNum(item.daily_baseline)} ${esc(item.unit)}/hari • Terpakai POS hari ini: ${fmtNum(item.used_today_pos)} ${esc(item.unit)}
            </div>
            <div style="margin-top:6px;">${statusBadge(item.status, item.status_label)}</div>
          </div>
          <div style="width:128px;">
            <label class="field-label" style="margin-bottom:3px;">Sisa (${esc(item.unit)})</label>
            <input class="glass-input mono closing-input" data-ing-id="${item.id}" type="number" step="0.05" min="0" value="${item.current_stock}" required>
          </div>
        </div>
      `).join('');
    }

    // 5. POS Menu Grid & Cart
    const posGrid = document.getElementById('posMenuGrid');
    if (posGrid) {
      posGrid.innerHTML = menus.map((m) => `
        <button class="glass-card menu-tile" type="button" onclick="addToCart(${m.id})">
          <span class="badge badge-neutral" style="align-self:flex-start; font-size:0.68rem;">${esc(m.category)}</span>
          <strong style="font-size:0.92rem; margin-top:4px;">${esc(m.name)}</strong>
          <span class="mono" style="font-weight:700; color:var(--caramel-500);">${fmtRp(m.price)}</span>
        </button>
      `).join('');
      renderPosCart();
    }

    // 6. History & PO Tables
    const poBody = document.getElementById('userPoTable');
    if (poBody) {
      poBody.innerHTML = purchase_orders.length
        ? purchase_orders.map((po) => `
            <tr>
              <td class="mono">${esc(po.po_number)}</td>
              <td><strong>${esc(po.ingredient_name)}</strong></td>
              <td class="mono">${fmtNum(po.qty)} ${esc(po.unit)}</td>
              <td>${poStatusBadge(po.status)}</td>
            </tr>
          `).join('')
        : '<tr><td colspan="4" style="text-align:center; color:var(--text-muted);">Belum ada pengajuan PO.</td></tr>';
    }

    const posHistBody = document.getElementById('userPosHistoryTable');
    if (posHistBody) {
      posHistBody.innerHTML = pos_sales.length
        ? pos_sales.map((s) => `
            <tr>
              <td style="font-size:0.78rem; color:var(--text-muted);">${esc(s.sold_at)}</td>
              <td><strong>${esc(s.menu_name)}</strong></td>
              <td class="mono">${s.qty}x</td>
              <td class="mono">${fmtRp(s.total_price)}</td>
            </tr>
          `).join('')
        : '<tr><td colspan="4" style="text-align:center; color:var(--text-muted);">Belum ada transaksi POS.</td></tr>';
    }

    const clsBody = document.getElementById('userClosingHistoryTable');
    if (clsBody) {
      clsBody.innerHTML = closing_logs.length
        ? closing_logs.map((cl) => `
            <tr>
              <td style="font-size:0.78rem; color:var(--text-muted);">${esc(cl.created_at)}</td>
              <td><strong>${esc(cl.ingredient_name)}</strong></td>
              <td class="mono">${fmtNum(cl.closing_stock)} ${esc(cl.unit)}</td>
              <td>${weatherLabel(cl.weather_forecast)}</td>
              <td class="mono">${fmtNum(cl.predicted_need)} ${esc(cl.unit)}</td>
              <td class="mono">${fmtNum(cl.recommended_buy)} ${esc(cl.unit)}</td>
              <td>${statusBadge(cl.status_color, cl.status_color.toUpperCase())}</td>
              <td>${esc(cl.recorded_by)}</td>
            </tr>
          `).join('')
        : '<tr><td colspan="8" style="text-align:center; color:var(--text-muted);">Belum ada riwayat closing.</td></tr>';
    }
  }

  function renderUserPredictionTable() {
    const tbody = document.getElementById('userPredictionTable');
    if (!tbody || !appState) return;
    const q = (document.getElementById('userStockSearch')?.value || '').toLowerCase();
    const st = document.getElementById('userStatusFilter')?.value || 'all';

    const filtered = appState.ai.items.filter((item) => {
      const matchText = item.name.toLowerCase().includes(q) || item.category.toLowerCase().includes(q);
      const matchStatus = st === 'all' || item.status === st;
      return matchText && matchStatus;
    });

    tbody.innerHTML = filtered.length
      ? filtered.map((item) => {
          const pct = item.total_pct >= 0 ? `+${item.total_pct}%` : `${item.total_pct}%`;
          return `
            <tr>
              <td>
                <strong>${esc(item.name)}</strong>
                <div style="font-size:0.75rem; color:var(--text-muted);">${esc(item.category)} • Umur simpan ${item.shelf_life_days} hr</div>
              </td>
              <td class="mono"><strong>${fmtNum(item.current_stock)} ${esc(item.unit)}</strong></td>
              <td>
                <span class="mono">${fmtNum(item.predicted_need)} ${esc(item.unit)}</span>
                <span style="font-size:0.74rem; color:var(--text-muted);"> (${pct})</span>
              </td>
              <td>${statusBadge(item.status, item.status_label)}</td>
              <td>
                ${item.recommended_buy > 0
                  ? `<strong class="mono" style="color:#991b1b;">Beli ${fmtNum(item.recommended_buy)} ${esc(item.unit)}</strong>`
                  : `<span style="color:#065f46; font-weight:700;">Tidak perlu beli</span>`}
              </td>
              <td style="max-width:250px; font-size:0.78rem; color:var(--text-muted);">
                ${item.waste_risk === 'tinggi'
                  ? `<span class="badge badge-waste" style="margin-bottom:4px;">${svgIcon('alert-triangle', 12)} Risiko Overstock</span><br>`
                  : ''}
                ${esc(item.waste_note)}
              </td>
              <td>
                ${item.recommended_buy > 0
                  ? `<button class="btn btn-primary btn-sm" type="button" onclick="quickRequestPo(${item.id}, ${item.recommended_buy})">Ajukan Beli</button>`
                  : `<span style="font-size:0.76rem; color:var(--text-muted);">Stok OK</span>`}
              </td>
            </tr>
          `;
        }).join('')
      : '<tr><td colspan="7" style="text-align:center; color:var(--text-muted);">Tidak ada bahan baku yang cocok.</td></tr>';
  }

  document.getElementById('userStockSearch')?.addEventListener('input', renderUserPredictionTable);
  document.getElementById('userStatusFilter')?.addEventListener('change', renderUserPredictionTable);

  // Closing submit
  document.getElementById('closingForm')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const inputs = Array.from(document.querySelectorAll('.closing-input'));
    const entries = inputs.map((inp) => ({
      ingredient_id: Number(inp.dataset.ingId),
      closing_stock: Number(inp.value || 0),
    }));
    const notes = document.getElementById('closingNotes')?.value || '';
    try {
      await apiPost('submit_closing', { entries, notes });
      window.switchTab('user-dashboard');
    } catch (err) {
      showToast(err.message, true);
    }
  });

  // POS Cart Logic
  window.addToCart = (menuId) => {
    if (!appState) return;
    const menu = appState.menus.find((m) => m.id === menuId);
    if (!menu) return;
    const existing = posCart.find((c) => c.menu_id === menuId);
    if (existing) {
      existing.qty += 1;
    } else {
      posCart.push({ menu_id: menu.id, name: menu.name, price: menu.price, qty: 1 });
    }
    renderPosCart();
  };

  window.updateCartQty = (menuId, delta) => {
    const idx = posCart.findIndex((c) => c.menu_id === menuId);
    if (idx === -1) return;
    posCart[idx].qty += delta;
    if (posCart[idx].qty <= 0) posCart.splice(idx, 1);
    renderPosCart();
  };

  function renderPosCart() {
    const list = document.getElementById('posCartList');
    const totalEl = document.getElementById('posCartTotal');
    if (!list || !totalEl) return;

    if (posCart.length === 0) {
      list.innerHTML = '<div style="padding:20px; text-align:center; color:var(--text-muted); font-size:0.84rem;">Belum ada menu dipilih.</div>';
      totalEl.textContent = 'Rp 0';
      return;
    }

    let total = 0;
    list.innerHTML = posCart.map((c) => {
      total += c.price * c.qty;
      return `
        <div class="glass-card" style="padding:10px 12px; display:flex; align-items:center; justify-content:space-between; gap:10px;">
          <div>
            <div style="font-weight:700; font-size:0.88rem;">${esc(c.name)}</div>
            <div class="mono" style="font-size:0.76rem; color:var(--text-muted);">${fmtRp(c.price)}</div>
          </div>
          <div style="display:flex; align-items:center; gap:6px;">
            <button class="btn btn-glass btn-sm" type="button" onclick="updateCartQty(${c.menu_id}, -1)">−</button>
            <strong class="mono" style="min-width:22px; text-align:center;">${c.qty}</strong>
            <button class="btn btn-glass btn-sm" type="button" onclick="updateCartQty(${c.menu_id}, 1)">+</button>
          </div>
        </div>
      `;
    }).join('');
    totalEl.textContent = fmtRp(total);
  }

  document.getElementById('btnClearCart')?.addEventListener('click', () => {
    posCart = [];
    renderPosCart();
  });

  document.getElementById('btnCheckoutPos')?.addEventListener('click', async () => {
    if (posCart.length === 0) {
      showToast('Pilih minimal 1 menu terlebih dahulu.', true);
      return;
    }
    try {
      await apiPost('submit_pos_sale', { items: posCart });
      posCart = [];
      renderPosCart();
    } catch (err) {
      showToast(err.message, true);
    }
  });

  window.quickRequestPo = async (ingredientId, qty) => {
    try {
      await apiPost('request_po', {
        ingredient_id: ingredientId,
        qty,
        notes: 'Pengajuan restock dari rekomendasi BrewStock AI',
      });
    } catch (err) {
      showToast(err.message, true);
    }
  };

  function poStatusBadge(status) {
    const map = {
      draft: ['badge-kuning', 'Menunggu ACC'],
      ordered: ['badge-neutral', 'Dipesan'],
      received: ['badge-hijau', 'Diterima'],
      cancelled: ['badge-merah', 'Dibatalkan'],
    };
    const [cls, label] = map[status] || ['badge-neutral', status];
    return `<span class="badge ${cls}">${esc(label)}</span>`;
  }

  // ==================== ADMIN VIEW RENDERING ====================
  function renderAdminView() {
    const { ai, today_stats, menus, purchase_orders, suppliers, users, waste_logs } = appState;
    const counts = ai.summary.counts;

    // 1. Admin KPIs
    const kpiEl = document.getElementById('adminKpiGrid');
    if (kpiEl) {
      kpiEl.innerHTML = `
        <div class="glass-card kpi-card">
          <span class="kpi-label">${svgIcon('alert-circle', 15, '#dc2626')} Item Kritis / Belanja Esok</span>
          <span class="kpi-value" style="color:#b91c1c;">${counts.merah} Kritis &bull; ${counts.kuning} Waspada</span>
          <span class="kpi-sub">Est. total belanja: <strong>${fmtRp(ai.summary.total_estimated_cost)}</strong></span>
        </div>
        <div class="glass-card kpi-card">
          <span class="kpi-label">${svgIcon('wallet', 15, '#b86b3a')} Nilai Inventori Aktif</span>
          <span class="kpi-value">${fmtRp(ai.summary.total_inventory_value)}</span>
          <span class="kpi-sub">${ai.summary.total_items} bahan baku terpantau AI</span>
        </div>
        <div class="glass-card kpi-card">
          <span class="kpi-label">${svgIcon('receipt', 15, '#3d2217')} Penjualan POS Hari Ini</span>
          <span class="kpi-value">${fmtRp(today_stats.revenue)}</span>
          <span class="kpi-sub">${today_stats.cups} porsi terjual &bull; ${weatherLabel(ai.weather.condition)}</span>
        </div>
        <div class="glass-card kpi-card">
          <span class="kpi-label">${svgIcon('leaf', 15, '#7c3aed')} Zero Waste Tracker</span>
          <span class="kpi-value" style="color:${counts.waste_alert > 0 ? '#6b21a8' : '#047857'};">${counts.waste_alert} Risiko Overstock</span>
          <span class="kpi-sub">Akumulasi waste tercatat: ${fmtRp(today_stats.waste_loss_total)}</span>
        </div>
      `;
    }

    // 2. Stock vs Predicted Chart
    const chartEl = document.getElementById('adminStockChart');
    if (chartEl) {
      chartEl.innerHTML = ai.items.map((item) => {
        const maxVal = Math.max(item.current_stock, item.predicted_need, item.safe_threshold, 1);
        const curPct = Math.min(100, Math.round((item.current_stock / maxVal) * 100));
        const predPct = Math.min(100, Math.round((item.predicted_need / maxVal) * 100));
        return `
          <div style="padding:10px 12px; border-radius:12px; background:rgba(255,255,255,0.4); border:1px solid rgba(255,255,255,0.6);">
            <div style="display:flex; justify-content:space-between; align-items:center; gap:8px; font-size:0.84rem;">
              <strong>${esc(item.name)}</strong>
              <span class="mono" style="font-size:0.78rem;">
                Stok: <strong>${fmtNum(item.current_stock)}</strong> / Prediksi AI: <strong>${fmtNum(item.predicted_need)} ${esc(item.unit)}</strong>
              </span>
            </div>
            <div class="stock-bar-bg" style="position:relative;">
              <div class="stock-bar-fill fill-${esc(item.status)}" style="width:${curPct}%;"></div>
              <div title="Target Prediksi AI" style="position:absolute; top:0; bottom:0; left:${predPct}%; width:3px; background:#26140e;"></div>
            </div>
          </div>
        `;
      }).join('');
    }

    // 3. Priority Feed
    const feedEl = document.getElementById('adminPriorityFeed');
    if (feedEl) {
      const alerts = ai.items.filter((i) => i.status !== 'hijau' || i.waste_risk === 'tinggi');
      feedEl.innerHTML = alerts.length
        ? alerts.slice(0, 6).map((item) => `
            <div class="glass-card" style="padding:12px 14px; display:flex; justify-content:space-between; align-items:center; gap:10px;">
              <div>
                <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                  <strong>${esc(item.name)}</strong>
                  ${statusBadge(item.status, item.status_label)}
                  ${item.waste_risk === 'tinggi' ? '<span class="badge badge-waste">Overstock / Basi</span>' : ''}
                </div>
                <div style="font-size:0.78rem; color:var(--text-muted); margin-top:4px;">${esc(item.ai_insight)}</div>
              </div>
              ${item.recommended_buy > 0
                ? `<button class="btn btn-glass btn-sm" type="button" onclick="quickRequestPo(${item.id}, ${item.recommended_buy})">+ PO</button>`
                : ''}
            </div>
          `).join('')
        : '<div class="glass-card" style="padding:16px; text-align:center; color:#065f46; font-weight:700;">Semua stok dalam status Aman &amp; Zero Waste!</div>';
    }

    // 4. Overview Table
    const ovTable = document.getElementById('adminOverviewTable');
    if (ovTable) {
      ovTable.innerHTML = ai.items.map((item) => `
        <tr>
          <td><strong>${esc(item.name)}</strong><div style="font-size:0.74rem; color:var(--text-muted);">${esc(item.supplier_name)}</div></td>
          <td class="mono">${fmtNum(item.current_stock)} ${esc(item.unit)}</td>
          <td class="mono" style="font-size:0.8rem;">${fmtNum(item.daily_baseline)} / ${fmtNum(item.used_today_pos)} ${esc(item.unit)}</td>
          <td class="mono"><strong>${fmtNum(item.predicted_need)} ${esc(item.unit)}</strong> (${item.total_pct >= 0 ? '+' : ''}${item.total_pct}%)</td>
          <td>${statusBadge(item.status, item.status_label)}</td>
          <td class="mono">${item.recommended_buy > 0 ? `<strong style="color:#991b1b;">${fmtNum(item.recommended_buy)} ${esc(item.unit)}</strong>` : '—'}</td>
          <td class="mono">${item.estimated_cost > 0 ? fmtRp(item.estimated_cost) : '—'}</td>
        </tr>
      `).join('');
    }

    // 5. Ingredients CRUD Table
    const ingTable = document.getElementById('adminIngredientsTable');
    if (ingTable) {
      ingTable.innerHTML = ai.items.map((item) => `
        <tr>
          <td><strong>${esc(item.name)}</strong></td>
          <td><span class="badge badge-neutral">${esc(item.category)}</span></td>
          <td class="mono">${fmtNum(item.current_stock)} ${esc(item.unit)}</td>
          <td class="mono">${fmtNum(item.daily_baseline)} ${esc(item.unit)}</td>
          <td style="font-size:0.78rem;">${esc(sensitivityLabel(item.weather_sensitivity))}</td>
          <td>${item.shelf_life_days} hari ${item.is_perishable ? '<span class="badge badge-kuning">Mudah Basi</span>' : ''}</td>
          <td class="mono">${fmtRp(item.unit_price)}</td>
          <td style="font-size:0.8rem;">${esc(item.supplier_name)}</td>
          <td style="white-space:nowrap;">
            <button class="btn btn-glass btn-sm" type="button" onclick="openIngredientModal(${item.id})">Edit</button>
            <button class="btn btn-danger btn-sm" type="button" onclick="deleteIngredient(${item.id}, '${esc(item.name)}')">Hapus</button>
          </td>
        </tr>
      `).join('');
    }

    // 6. Recipes Grid
    const recGrid = document.getElementById('adminRecipesGrid');
    if (recGrid) {
      recGrid.innerHTML = menus.map((m) => `
        <div class="glass-card" style="padding:16px; display:flex; flex-direction:column; justify-content:space-between; gap:12px;">
          <div>
            <div style="display:flex; justify-content:space-between; align-items:start; gap:8px;">
              <div>
                <span class="badge badge-neutral" style="font-size:0.7rem;">${esc(m.category)}</span>
                <h3 style="font-size:1rem; font-weight:800; margin-top:6px;">${esc(m.name)}</h3>
              </div>
              <strong class="mono" style="color:var(--caramel-500);">${fmtRp(m.price)}</strong>
            </div>
            <div style="margin-top:12px; display:flex; flex-direction:column; gap:5px; font-size:0.8rem;">
              <div style="font-weight:700; color:var(--text-muted); font-size:0.72rem; text-transform:uppercase;">Takaran Bahan / Porsi:</div>
              ${m.recipes.length
                ? m.recipes.map((r) => `
                    <div style="display:flex; justify-content:space-between; border-bottom:1px dashed rgba(91,52,35,0.12); padding-bottom:3px;">
                      <span>${esc(r.ingredient_name)}</span>
                      <span class="mono"><strong>${fmtNum(r.qty_per_portion)} ${esc(r.unit)}</strong></span>
                    </div>
                  `).join('')
                : '<span style="color:var(--text-muted);">Belum ada resep.</span>'}
            </div>
          </div>
          <div style="display:flex; gap:8px;">
            <button class="btn btn-glass btn-sm" style="flex:1;" type="button" onclick="openMenuModal(${m.id})">Edit Resep</button>
            <button class="btn btn-danger btn-sm" type="button" onclick="deleteMenu(${m.id}, '${esc(m.name)}')">Hapus</button>
          </div>
        </div>
      `).join('');
    }

    // 7. PO & Suppliers
    const adminPoBody = document.getElementById('adminPoTable');
    if (adminPoBody) {
      adminPoBody.innerHTML = purchase_orders.length
        ? purchase_orders.map((po) => `
            <tr>
              <td class="mono">${esc(po.po_number)}</td>
              <td><strong>${esc(po.ingredient_name)}</strong></td>
              <td style="font-size:0.8rem;">${esc(po.supplier_name || '-')}</td>
              <td class="mono">${fmtNum(po.qty)} ${esc(po.unit)}</td>
              <td class="mono">${fmtRp(po.estimated_cost)}</td>
              <td style="font-size:0.8rem;">${esc(po.requested_by)}</td>
              <td>${poStatusBadge(po.status)}</td>
              <td style="white-space:nowrap;">
                ${po.status === 'draft' ? `<button class="btn btn-primary btn-sm" type="button" onclick="setPoStatus(${po.id}, 'ordered')">Kirim ke Supplier</button>` : ''}
                ${po.status === 'ordered' ? `<button class="btn btn-success btn-sm" type="button" onclick="setPoStatus(${po.id}, 'received')">Terima &amp; Tambah Stok</button>` : ''}
                ${(po.status === 'draft' || po.status === 'ordered') ? `<button class="btn btn-danger btn-sm" type="button" onclick="setPoStatus(${po.id}, 'cancelled')">Batal</button>` : ''}
              </td>
            </tr>
          `).join('')
        : '<tr><td colspan="8" style="text-align:center; color:var(--text-muted);">Belum ada PO.</td></tr>';
    }

    const supBody = document.getElementById('adminSuppliersTable');
    if (supBody) {
      supBody.innerHTML = suppliers.map((s) => `
        <tr>
          <td><strong>${esc(s.name)}</strong></td>
          <td>${esc(s.category)}</td>
          <td class="mono">${esc(s.phone)}</td>
          <td>${s.lead_time_days} hari</td>
          <td style="font-size:0.8rem; color:var(--text-muted);">${esc(s.address)}</td>
          <td><button class="btn btn-glass btn-sm" type="button" onclick="openSupplierModal(${s.id})">Edit</button></td>
        </tr>
      `).join('');
    }

    // 8. Users & Waste
    const usrBody = document.getElementById('adminUsersTable');
    if (usrBody && users) {
      usrBody.innerHTML = users.map((u) => `
        <tr>
          <td><strong>${esc(u.full_name)}</strong><div style="font-size:0.74rem; color:var(--text-muted);">${esc(u.job_title)}</div></td>
          <td class="mono">@${esc(u.username)}</td>
          <td><span class="badge ${u.role === 'admin' ? 'badge-neutral' : 'badge-hijau'}">${u.role === 'admin' ? 'Admin' : 'User'}</span></td>
          <td>${Number(u.is_active) === 1 ? '<span class="badge badge-hijau">Aktif</span>' : '<span class="badge badge-merah">Nonaktif</span>'}</td>
          <td>
            ${Number(u.id) !== Number(appState.user.id)
              ? `<button class="btn btn-glass btn-sm" type="button" onclick="toggleUser(${u.id})">${Number(u.is_active) === 1 ? 'Nonaktifkan' : 'Aktifkan'}</button>`
              : '<span style="font-size:0.76rem; color:var(--text-muted);">Sedang Login</span>'}
          </td>
        </tr>
      `).join('');
    }

    const wstBody = document.getElementById('adminWasteTable');
    if (wstBody) {
      wstBody.innerHTML = waste_logs.length
        ? waste_logs.map((w) => `
            <tr>
              <td style="font-size:0.76rem; color:var(--text-muted);">${esc(w.created_at)}</td>
              <td><strong>${esc(w.ingredient_name)}</strong></td>
              <td class="mono">${fmtNum(w.qty_wasted)} ${esc(w.unit)}</td>
              <td class="mono" style="color:#991b1b;">${fmtRp(w.loss_amount)}</td>
              <td style="font-size:0.8rem;">${esc(w.reason)}</td>
            </tr>
          `).join('')
        : '<tr><td colspan="5" style="text-align:center; color:var(--text-muted);">Belum ada log waste. Zero waste tercapai!</td></tr>';
    }
  }

  // ==================== ADMIN AI SIMULATOR ====================
  async function runSimulation() {
    const weather = document.getElementById('simWeatherSelect')?.value || 'panas';
    const isWeekend = document.getElementById('simWeekendCheck')?.checked || false;
    try {
      const res = await fetch(`api.php?action=simulate_ai&weather=${encodeURIComponent(weather)}&is_weekend=${isWeekend ? 1 : 0}`);
      const json = await res.json();
      if (!json.ok) return;
      const sim = json.simulation;

      const banner = document.getElementById('simHighlightBanner');
      if (banner) {
        const focus = sim.items.find((i) => i.name.toLowerCase().includes('susu')) || sim.items[0];
        if (focus) {
          const pct = focus.total_pct >= 0 ? `+${focus.total_pct}%` : `${focus.total_pct}%`;
          banner.innerHTML = `
            <span class="badge badge-neutral">CONTOH SIMULASI</span>
            <span>
              Input: <strong>${weatherLabel(weather)}${isWeekend ? ' + Akhir Pekan' : ''}</strong> &amp; sisa <strong>${esc(focus.name)} ${fmtNum(focus.current_stock)} ${esc(focus.unit)}</strong>
              → AI: Prediksi kebutuhan <strong>${pct}</strong>
              → Output: <strong>Status ${esc(focus.status_label)}</strong>
              ${focus.recommended_buy > 0 ? `• Beli <strong>${fmtNum(focus.recommended_buy)} ${esc(focus.unit)}</strong>` : '• Tidak perlu beli'}
            </span>
          `;
        }
      }

      const tbody = document.getElementById('simResultTable');
      if (tbody) {
        tbody.innerHTML = sim.items.map((item) => {
          const pct = item.total_pct >= 0 ? `+${item.total_pct}%` : `${item.total_pct}%`;
          return `
            <tr>
              <td><strong>${esc(item.name)}</strong></td>
              <td class="mono">${fmtNum(item.current_stock)} ${esc(item.unit)}</td>
              <td class="mono">${pct}</td>
              <td class="mono"><strong>${fmtNum(item.predicted_need)} ${esc(item.unit)}</strong></td>
              <td>${statusBadge(item.status, item.status_label)}</td>
              <td class="mono">${item.recommended_buy > 0 ? `<strong>${fmtNum(item.recommended_buy)} ${esc(item.unit)}</strong>` : '0'}</td>
              <td style="font-size:0.78rem; color:var(--text-muted);">${esc(item.waste_note)}</td>
            </tr>
          `;
        }).join('');
      }
    } catch (err) {
      showToast(err.message, true);
    }
  }

  document.getElementById('btnRunSim')?.addEventListener('click', runSimulation);
  document.getElementById('simWeatherSelect')?.addEventListener('change', runSimulation);
  document.getElementById('simWeekendCheck')?.addEventListener('change', runSimulation);

  document.getElementById('btnApplySimLive')?.addEventListener('click', async () => {
    const weather = document.getElementById('simWeatherSelect')?.value || 'panas';
    const isWeekend = document.getElementById('simWeekendCheck')?.checked || false;
    try {
      await apiPost('update_weather', { tomorrow_weather: weather, is_weekend: isWeekend });
    } catch (err) {
      showToast(err.message, true);
    }
  });

  // ==================== ADMIN MODALS & CRUD ====================
  window.triggerAutoPo = async () => {
    try {
      await apiPost('generate_auto_pos');
    } catch (err) {
      showToast(err.message, true);
    }
  };
  document.getElementById('btnAutoGeneratePo')?.addEventListener('click', window.triggerAutoPo);

  window.setPoStatus = async (id, status) => {
    try {
      await apiPost('update_po_status', { id, status });
    } catch (err) {
      showToast(err.message, true);
    }
  };

  window.openIngredientModal = (id = 0) => {
    const item = id ? appState.ai.items.find((x) => x.id === id) : null;
    const supOptions = appState.suppliers.map((s) =>
      `<option value="${s.id}" ${item && Number(item.supplier_id) === Number(s.id) ? 'selected' : ''}>${esc(s.name)}</option>`
    ).join('');

    window.openModal(item ? `Edit Bahan: ${item.name}` : 'Tambah Bahan Baku Baru', `
      <form id="ingModalForm" style="display:flex; flex-direction:column; gap:12px;">
        <input type="hidden" name="id" value="${item ? item.id : 0}">
        <div class="grid-2" style="gap:10px;">
          <div>
            <label class="field-label">Nama Bahan</label>
            <input class="glass-input" name="name" value="${item ? esc(item.name) : ''}" required>
          </div>
          <div>
            <label class="field-label">Kategori</label>
            <select class="glass-select" name="category">
              ${['Dairy', 'Kopi', 'Pastry', 'Sirup & Pemanis', 'Lainnya'].map((c) =>
                `<option value="${c}" ${item && item.category === c ? 'selected' : ''}>${c}</option>`
              ).join('')}
            </select>
          </div>
        </div>
        <div class="grid-3" style="gap:10px;">
          <div>
            <label class="field-label">Satuan</label>
            <input class="glass-input" name="unit" value="${item ? esc(item.unit) : 'L'}" required>
          </div>
          <div>
            <label class="field-label">Stok Saat Ini</label>
            <input class="glass-input" name="current_stock" type="number" step="0.01" value="${item ? item.current_stock : 0}" required>
          </div>
          <div>
            <label class="field-label">Baseline / Hari</label>
            <input class="glass-input" name="daily_baseline" type="number" step="0.01" value="${item ? item.daily_baseline : 1}" required>
          </div>
        </div>
        <div class="grid-3" style="gap:10px;">
          <div>
            <label class="field-label">Batas Kritis</label>
            <input class="glass-input" name="min_threshold" type="number" step="0.01" value="${item ? item.min_threshold : 1}" required>
          </div>
          <div>
            <label class="field-label">Batas Aman</label>
            <input class="glass-input" name="safe_threshold" type="number" step="0.01" value="${item ? item.safe_threshold : 3}" required>
          </div>
          <div>
            <label class="field-label">Umur Simpan (Hari)</label>
            <input class="glass-input" name="shelf_life_days" type="number" min="1" value="${item ? item.shelf_life_days : 7}" required>
          </div>
        </div>
        <div class="grid-2" style="gap:10px;">
          <div>
            <label class="field-label">Sensitivitas Cuaca AI</label>
            <select class="glass-select" name="weather_sensitivity">
              <option value="panas_boost" ${item && item.weather_sensitivity === 'panas_boost' ? 'selected' : ''}>Naik saat Panas (+40%)</option>
              <option value="hujan_boost" ${item && item.weather_sensitivity === 'hujan_boost' ? 'selected' : ''}>Naik saat Hujan (+25%)</option>
              <option value="netral" ${item && item.weather_sensitivity === 'netral' ? 'selected' : ''}>Stabil / Netral</option>
            </select>
          </div>
          <div>
            <label class="field-label">Harga / Satuan (Rp)</label>
            <input class="glass-input" name="unit_price" type="number" min="0" value="${item ? item.unit_price : 20000}" required>
          </div>
        </div>
        <div class="grid-2" style="gap:10px; align-items:center;">
          <div>
            <label class="field-label">Supplier</label>
            <select class="glass-select" name="supplier_id">
              <option value="">-- Pilih Supplier --</option>
              ${supOptions}
            </select>
          </div>
          <label style="display:flex; align-items:center; gap:8px; font-size:0.84rem; font-weight:700; margin-top:18px; cursor:pointer;">
            <input type="checkbox" name="is_perishable" ${item && item.is_perishable ? 'checked' : ''}> Mudah Basi (Zero Waste Alert)
          </label>
        </div>
        <button class="btn btn-primary" type="submit" style="margin-top:6px;">Simpan Bahan Baku</button>
      </form>
    `);

    document.getElementById('ingModalForm').addEventListener('submit', async (e) => {
      e.preventDefault();
      const fd = new FormData(e.target);
      try {
        await apiPost('save_ingredient', {
          id: Number(fd.get('id')),
          name: fd.get('name'),
          category: fd.get('category'),
          unit: fd.get('unit'),
          current_stock: Number(fd.get('current_stock')),
          daily_baseline: Number(fd.get('daily_baseline')),
          min_threshold: Number(fd.get('min_threshold')),
          safe_threshold: Number(fd.get('safe_threshold')),
          shelf_life_days: Number(fd.get('shelf_life_days')),
          weather_sensitivity: fd.get('weather_sensitivity'),
          unit_price: Number(fd.get('unit_price')),
          supplier_id: fd.get('supplier_id') ? Number(fd.get('supplier_id')) : null,
          is_perishable: fd.get('is_perishable') === 'on',
        });
        window.closeModal();
      } catch (err) {
        showToast(err.message, true);
      }
    });
  };

  window.deleteIngredient = async (id, name) => {
    if (!confirm(`Hapus bahan baku "${name}"?`)) return;
    try {
      await apiPost('delete_ingredient', { id });
    } catch (err) {
      showToast(err.message, true);
    }
  };

  window.openMenuModal = (menuId = 0) => {
    const menu = menuId ? appState.menus.find((m) => m.id === menuId) : null;
    const ingRows = appState.ai.items.map((ing) => {
      const existing = menu ? menu.recipes.find((r) => r.ingredient_id === ing.id) : null;
      return `
        <div style="display:flex; align-items:center; justify-content:space-between; gap:10px; padding:6px 0; border-bottom:1px dashed rgba(91,52,35,0.12);">
          <span style="font-size:0.84rem; font-weight:600;">${esc(ing.name)} (${esc(ing.unit)})</span>
          <input class="glass-input mono recipe-qty-input" data-ing-id="${ing.id}" type="number" step="0.005" min="0" value="${existing ? existing.qty_per_portion : 0}" style="width:110px; padding:6px 10px;">
        </div>
      `;
    }).join('');

    window.openModal(menu ? `Edit Menu & Resep: ${menu.name}` : 'Tambah Menu & Resep Baru', `
      <form id="menuModalForm" style="display:flex; flex-direction:column; gap:12px;">
        <input type="hidden" name="id" value="${menu ? menu.id : 0}">
        <div>
          <label class="field-label">Nama Menu</label>
          <input class="glass-input" name="name" value="${menu ? esc(menu.name) : ''}" required>
        </div>
        <div class="grid-2" style="gap:10px;">
          <div>
            <label class="field-label">Kategori</label>
            <select class="glass-select" name="category">
              ${['Kopi Dingin', 'Kopi Panas', 'Non-Kopi', 'Pastry'].map((c) =>
                `<option value="${c}" ${menu && menu.category === c ? 'selected' : ''}>${c}</option>`
              ).join('')}
            </select>
          </div>
          <div>
            <label class="field-label">Harga Jual (Rp)</label>
            <input class="glass-input" name="price" type="number" min="1000" value="${menu ? menu.price : 25000}" required>
          </div>
        </div>
        <div>
          <label class="field-label">Takaran Bahan per 1 Porsi (isi 0 jika tidak dipakai)</label>
          <div style="max-height:220px; overflow-y:auto; padding:8px 12px; border-radius:12px; background:rgba(255,255,255,0.45);">
            ${ingRows}
          </div>
        </div>
        <button class="btn btn-primary" type="submit">Simpan Menu &amp; Resep</button>
      </form>
    `);

    document.getElementById('menuModalForm').addEventListener('submit', async (e) => {
      e.preventDefault();
      const fd = new FormData(e.target);
      const recipes = Array.from(document.querySelectorAll('.recipe-qty-input'))
        .map((inp) => ({ ingredient_id: Number(inp.dataset.ingId), qty_per_portion: Number(inp.value || 0) }))
        .filter((r) => r.qty_per_portion > 0);

      try {
        await apiPost('save_menu', {
          id: Number(fd.get('id')),
          name: fd.get('name'),
          category: fd.get('category'),
          price: Number(fd.get('price')),
          recipes,
        });
        window.closeModal();
      } catch (err) {
        showToast(err.message, true);
      }
    });
  };

  window.deleteMenu = async (id, name) => {
    if (!confirm(`Nonaktifkan menu "${name}"?`)) return;
    try {
      await apiPost('delete_menu', { id });
    } catch (err) {
      showToast(err.message, true);
    }
  };

  window.openSupplierModal = (supId = 0) => {
    const sup = supId ? appState.suppliers.find((s) => Number(s.id) === supId) : null;
    window.openModal(sup ? `Edit Supplier: ${sup.name}` : 'Tambah Supplier Baru', `
      <form id="supModalForm" style="display:flex; flex-direction:column; gap:12px;">
        <input type="hidden" name="id" value="${sup ? sup.id : 0}">
        <div>
          <label class="field-label">Nama Supplier</label>
          <input class="glass-input" name="name" value="${sup ? esc(sup.name) : ''}" required>
        </div>
        <div class="grid-2" style="gap:10px;">
          <div>
            <label class="field-label">Kategori Pasokan</label>
            <input class="glass-input" name="category" value="${sup ? esc(sup.category) : 'Dairy & Bahan Kafe'}" required>
          </div>
          <div>
            <label class="field-label">Telepon / WhatsApp</label>
            <input class="glass-input" name="phone" value="${sup ? esc(sup.phone) : ''}" required>
          </div>
        </div>
        <div class="grid-2" style="gap:10px;">
          <div>
            <label class="field-label">Lead Time (Hari)</label>
            <input class="glass-input" name="lead_time_days" type="number" min="1" value="${sup ? sup.lead_time_days : 1}" required>
          </div>
          <div>
            <label class="field-label">Alamat</label>
            <input class="glass-input" name="address" value="${sup ? esc(sup.address) : ''}">
          </div>
        </div>
        <button class="btn btn-primary" type="submit">Simpan Supplier</button>
      </form>
    `);

    document.getElementById('supModalForm').addEventListener('submit', async (e) => {
      e.preventDefault();
      const fd = new FormData(e.target);
      try {
        await apiPost('save_supplier', {
          id: Number(fd.get('id')),
          name: fd.get('name'),
          category: fd.get('category'),
          phone: fd.get('phone'),
          lead_time_days: Number(fd.get('lead_time_days')),
          address: fd.get('address'),
        });
        window.closeModal();
      } catch (err) {
        showToast(err.message, true);
      }
    });
  };

  window.openUserModal = () => {
    window.openModal('Tambah Pengguna Baru', `
      <form id="userModalForm" style="display:flex; flex-direction:column; gap:12px;">
        <div>
          <label class="field-label">Nama Lengkap</label>
          <input class="glass-input" name="full_name" required>
        </div>
        <div class="grid-2" style="gap:10px;">
          <div>
            <label class="field-label">Username</label>
            <input class="glass-input" name="username" required>
          </div>
          <div>
            <label class="field-label">Password</label>
            <input class="glass-input" name="password" type="password" value="123456" required>
          </div>
        </div>
        <div class="grid-2" style="gap:10px;">
          <div>
            <label class="field-label">Peran (Role)</label>
            <select class="glass-select" name="role">
              <option value="user">User (Barista / Kasir)</option>
              <option value="admin">Admin (Owner Kafe)</option>
            </select>
          </div>
          <div>
            <label class="field-label">Jabatan</label>
            <input class="glass-input" name="job_title" value="Barista" required>
          </div>
        </div>
        <button class="btn btn-primary" type="submit">Buat Akun</button>
      </form>
    `);

    document.getElementById('userModalForm').addEventListener('submit', async (e) => {
      e.preventDefault();
      const fd = new FormData(e.target);
      try {
        await apiPost('save_user', {
          full_name: fd.get('full_name'),
          username: fd.get('username'),
          password: fd.get('password'),
          role: fd.get('role'),
          job_title: fd.get('job_title'),
        });
        window.closeModal();
      } catch (err) {
        showToast(err.message, true);
      }
    });
  };

  window.toggleUser = async (id) => {
    try {
      await apiPost('toggle_user', { id });
    } catch (err) {
      showToast(err.message, true);
    }
  };

  window.openWasteModal = () => {
    const ingOpts = appState.ai.items.map((i) =>
      `<option value="${i.id}">${esc(i.name)} (Stok: ${fmtNum(i.current_stock)} ${esc(i.unit)})</option>`
    ).join('');
    window.openModal('Catat Bahan Basi / Waste', `
      <form id="wasteModalForm" style="display:flex; flex-direction:column; gap:12px;">
        <div>
          <label class="field-label">Bahan Baku</label>
          <select class="glass-select" name="ingredient_id">${ingOpts}</select>
        </div>
        <div>
          <label class="field-label">Jumlah Terbuang</label>
          <input class="glass-input" name="qty_wasted" type="number" step="0.05" min="0.05" value="0.5" required>
        </div>
        <div>
          <label class="field-label">Alasan / Penyebab</label>
          <input class="glass-input" name="reason" value="Melewati umur simpan / basi" required>
        </div>
        <button class="btn btn-danger" type="submit">Catat Waste &amp; Kurangi Stok</button>
      </form>
    `);

    document.getElementById('wasteModalForm').addEventListener('submit', async (e) => {
      e.preventDefault();
      const fd = new FormData(e.target);
      try {
        await apiPost('log_waste', {
          ingredient_id: Number(fd.get('ingredient_id')),
          qty_wasted: Number(fd.get('qty_wasted')),
          reason: fd.get('reason'),
        });
        window.closeModal();
      } catch (err) {
        showToast(err.message, true);
      }
    });
  };

  loadInitialState();
})();
