/**
 * app.js – Client-side logic
 * Smart Bank Locker Management System
 */

/* ════════════════════════════════════════
   SIDEBAR TOGGLE (mobile)
   ════════════════════════════════════════ */
document.addEventListener('DOMContentLoaded', () => {
  const toggle = document.getElementById('sidebarToggle');
  const sidebar = document.getElementById('sidebar');
  const overlay = document.getElementById('sidebarOverlay');

  if (toggle) {
    toggle.addEventListener('click', () => {
      sidebar.classList.toggle('open');
      overlay && overlay.classList.toggle('active');
    });
  }
  if (overlay) {
    overlay.addEventListener('click', () => {
      sidebar.classList.remove('open');
      overlay.classList.remove('active');
    });
  }

  // Header Interactions
  initHeaderInteractions();

  // Mark active nav link
  const links = document.querySelectorAll('.nav-link');
  links.forEach(l => {
    if (window.location.href.includes(l.getAttribute('href'))) {
      l.classList.add('active');
    }
  });

  // Dismiss alerts after 4 s
  document.querySelectorAll('.alert[data-auto-dismiss]').forEach(el => {
    setTimeout(() => el.style.opacity = '0', 4000);
    setTimeout(() => el.remove(), 4500);
  });
});

/* ════════════════════════════════════════
   HEADER INTERACTIONS (Notifs, Profile, Theme)
   ════════════════════════════════════════ */
function initHeaderInteractions() {
  // 1. Profile Dropdown
  const userBtn = document.getElementById('userBtn');
  const userMenu = document.getElementById('userMenu');
  if (userBtn && userMenu) {
    userBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      userMenu.classList.toggle('show');
    });
  }

  // 2. Notification Dropdown
  const notifBtn = document.getElementById('notifBtn');
  const notifMenu = document.getElementById('notifMenu');
  if (notifBtn && notifMenu) {
    notifBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      notifMenu.classList.toggle('show');
    });

    // Fetch Notifs
    fetchNotifications();
  }

  // 3. Close menus on outside click
  document.addEventListener('click', () => {
    userMenu && userMenu.classList.remove('show');
    notifMenu && notifMenu.classList.remove('show');
  });

  // 4. Mark Read
  const markReadBtn = document.getElementById('markReadBtn');
  if (markReadBtn) {
    markReadBtn.addEventListener('click', async (e) => {
      e.preventDefault();
      const res = await fetch(window.location.origin + '/bank_locker/api/mark_notifications_read.php');
      const data = await res.json();
      if (data.success) {
        document.getElementById('notifBadge').style.display = 'none';
        document.getElementById('notifList').innerHTML = '<div class="notif-empty">No new notifications</div>';
      }
    });
  }

  // 5. Theme Initialization (Forced Dark Theme)
  document.documentElement.setAttribute('data-theme', 'dark');
  localStorage.setItem('theme', 'dark');
}

async function fetchNotifications() {
  const badge = document.getElementById('notifBadge');
  const list = document.getElementById('notifList');
  if (!badge || !list) return;

  try {
    const res = await fetch(window.location.origin + '/bank_locker/api/get_notifications.php');
    const data = await res.json();
    if (data.success && data.count > 0) {
      badge.textContent = data.count;
      badge.style.display = 'flex';
      list.innerHTML = data.notifications.map(n => `
                <div class="notif-item ${n.type}">
                    <div class="notif-icon"><i class="fas ${getNotifIcon(n.type)}"></i></div>
                    <div class="notif-content">
                        <strong>${n.title}</strong>
                        <p>${n.message}</p>
                        <small>${n.created_at}</small>
                    </div>
                </div>
            `).join('');
    }
  } catch (e) { console.error('Notif fetch error', e); }
}

function getNotifIcon(type) {
  if (type === 'success') return 'fa-circle-check';
  if (type === 'danger') return 'fa-circle-xmark';
  if (type === 'warning') return 'fa-triangle-exclamation';
  return 'fa-info-circle';
}

/* ════════════════════════════════════════
   MODAL HELPERS
   ════════════════════════════════════════ */
function openModal(id) {
  const el = document.getElementById(id);
  if (el) { el.classList.add('active'); document.body.style.overflow = 'hidden'; }
}
function closeModal(id) {
  const el = document.getElementById(id);
  if (el) { el.classList.remove('active'); document.body.style.overflow = ''; }
}
// Close modal on overlay click
document.addEventListener('click', e => {
  if (e.target.classList.contains('modal-overlay')) {
    e.target.classList.remove('active');
    document.body.style.overflow = '';
  }
});

/* ════════════════════════════════════════
   GLOBAL ALERT TOAST
   ════════════════════════════════════════ */
function showToast(msg, type = 'success') {
  const id = 'toast-' + Date.now();
  const icons = { success: 'fa-circle-check', error: 'fa-circle-xmark', warning: 'fa-triangle-exclamation' };
  const html = `
    <div id="${id}" class="alert alert-${type}" style="position:fixed;top:20px;right:20px;z-index:9999;min-width:280px;max-width:380px;animation:fadeUp .3s ease" data-auto-dismiss>
      <i class="fas ${icons[type] || icons.success}"></i> ${msg}
    </div>`;
  document.body.insertAdjacentHTML('beforeend', html);
  const el = document.getElementById(id);
  setTimeout(() => { el.style.opacity = '0'; el.style.transition = 'opacity .5s'; }, 3800);
  setTimeout(() => el.remove(), 4400);
}

/* ════════════════════════════════════════
   AJAX HELPER
   ════════════════════════════════════════ */
async function ajaxPost(url, data) {
  const fd = data instanceof FormData ? data : (() => {
    const f = new FormData();
    Object.entries(data).forEach(([k, v]) => f.append(k, v));
    return f;
  })();
  const res = await fetch(url, { method: 'POST', body: fd });
  const json = await res.json();
  return json;
}

/* ════════════════════════════════════════
   SEARCH SUGGESTION DROPDOWN
   ════════════════════════════════════════ */
function initSearchSuggest(inputId, dropId, endpoint, onSelect) {
  const inp = document.getElementById(inputId);
  const drop = document.getElementById(dropId);
  if (!inp || !drop) return;

  let debounce;
  inp.addEventListener('input', () => {
    clearTimeout(debounce);
    const q = inp.value.trim();
    if (q.length < 2) { drop.classList.remove('show'); drop.innerHTML = ''; return; }
    debounce = setTimeout(async () => {
      const data = await ajaxPost(endpoint, { action: 'search_suggest', q });
      if (!data.results || data.results.length === 0) {
        drop.classList.remove('show'); return;
      }
      drop.innerHTML = data.results.map(r =>
        `<div class="search-item" data-id="${r.id}" data-label="${r.label}">
           <i class="fas ${r.icon || 'fa-magnifying-glass'}"></i> ${r.label}
           ${r.sub ? `<small class="ms-auto text-muted">${r.sub}</small>` : ''}
         </div>`
      ).join('');
      drop.classList.add('show');
      drop.querySelectorAll('.search-item').forEach(item => {
        item.addEventListener('click', () => {
          inp.value = item.dataset.label;
          drop.classList.remove('show');
          onSelect && onSelect(item.dataset.id, item.dataset.label);
        });
      });
    }, 280);
  });

  document.addEventListener('click', e => {
    if (!inp.contains(e.target) && !drop.contains(e.target)) drop.classList.remove('show');
  });
}

/* ════════════════════════════════════════
   LOCKER FILTER (client-side table filter)
   ════════════════════════════════════════ */
function filterTable(tableId, col, val) {
  const rows = document.querySelectorAll(`#${tableId} tbody tr`);
  rows.forEach(row => {
    const cell = row.cells[col];
    const match = !val || val === 'all' || (cell && cell.textContent.toLowerCase().includes(val.toLowerCase()));
    row.style.display = match ? '' : 'none';
  });
}

//  Filter pill handler
document.querySelectorAll('[data-filter-table]').forEach(btn => {
  btn.addEventListener('click', () => {
    const tableId = btn.dataset.filterTable;
    const col = parseInt(btn.dataset.filterCol || 0);
    const val = btn.dataset.filterVal || 'all';
    document.querySelectorAll(`[data-filter-table="${tableId}"]`)
      .forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    filterTable(tableId, col, val);
  });
});

/* ════════════════════════════════════════
   OTP SYSTEM UI
   ════════════════════════════════════════ */
let _otpTimer;

async function requestOTP(customerId, lockerNo) {
  const box = document.getElementById('otpBox');
  const disp = document.getElementById('otpDisplay');
  const info = document.getElementById('otpInfo');
  if (!box || !disp) return;

  const res = await ajaxPost('../../ajax/handler.php', {
    action: 'generate_otp', customer_id: customerId, locker_no: lockerNo
  });
  if (res.otp) {
    disp.textContent = res.otp;
    box.style.display = 'block';
    info.textContent = 'OTP valid for 10 minutes. Share with customer at the branch.';
    startOTPCountdown(600);
    showToast('OTP generated successfully', 'success');
  } else {
    showToast(res.error || 'Failed to generate OTP', 'error');
  }
}

function startOTPCountdown(sec) {
  clearInterval(_otpTimer);
  const el = document.getElementById('otpCountdown');
  if (!el) return;
  const tick = () => {
    const m = String(Math.floor(sec / 60)).padStart(2, '0');
    const s = String(sec % 60).padStart(2, '0');
    el.textContent = `Expires in ${m}:${s}`;
    if (--sec < 0) { clearInterval(_otpTimer); el.textContent = 'OTP Expired'; }
  };
  tick();
  _otpTimer = setInterval(tick, 1000);
}

async function verifyOTPUI(customerId) {
  const otpVal = document.getElementById('otpInput').value.trim();
  if (!otpVal) { showToast('Enter OTP', 'warning'); return; }
  const res = await ajaxPost('../../ajax/handler.php', {
    action: 'verify_otp', customer_id: customerId, otp: otpVal
  });
  if (res.verified) {
    showToast('OTP Verified! Access Granted.', 'success');
    document.getElementById('otpVerifyBtn').disabled = true;
    simulateBiometric('bio-ring');
  } else {
    showToast('Invalid or expired OTP', 'error');
  }
}

/* ════════════════════════════════════════
   BIOMETRIC SIMULATION
   ════════════════════════════════════════ */
function simulateBiometric(ringId) {
  const ring = document.getElementById(ringId);
  if (!ring) return;
  ring.className = 'biometric-ring scanning';
  ring.innerHTML = '<i class="fas fa-fingerprint"></i>';
  setTimeout(() => {
    ring.className = 'biometric-ring success';
    ring.innerHTML = '<i class="fas fa-check-circle"></i>';
    showToast('Biometric Verified!', 'success');
  }, 2500);
}

/* ════════════════════════════════════════
   SMART LOCKER SUGGESTION
   ════════════════════════════════════════ */
async function loadLockerSuggestions(size) {
  const box = document.getElementById('suggestionBox');
  if (!box) return;
  box.innerHTML = '<p class="text-muted">Loading…</p>';
  const res = await ajaxPost('../../ajax/handler.php', {
    action: 'suggest_lockers', size: size || 'Small'
  });
  if (res.lockers && res.lockers.length) {
    box.innerHTML = res.lockers.map(l =>
      `<div class="suggestion-item">
         <i class="fas fa-lock-open text-teal"></i>
         <b>${l.locker_no}</b>
         <span class="text-muted">${l.size} · ${l.location}</span>
         <span class="ms-auto">₹${l.rent_amount}/mo</span>
       </div>`
    ).join('');
  } else {
    box.innerHTML = '<p class="text-muted">No available lockers found for this size.</p>';
  }
}

/* ════════════════════════════════════════
   CONFIRM DELETE
   ════════════════════════════════════════ */
function confirmDelete(url, msg) {
  if (confirm(msg || 'Are you sure you want to delete this record?')) {
    window.location.href = url;
  }
}

/* ════════════════════════════════════════
   PRINT INVOICE
   ════════════════════════════════════════ */
function printInvoice(payId) {
  const win = window.open(`../../ajax/handler.php?action=invoice&id=${payId}`, '_blank', 'width=750,height=900');
  win.focus();
}

/* ════════════════════════════════════════
   DASHBOARD CHARTS (Chart.js)
   ════════════════════════════════════════ */
function initDashboardCharts(data) {
  const isLight = document.documentElement.getAttribute('data-theme') === 'light';
  const textColor = isLight ? '#64748b' : '#94a3b8';
  const gridColor = isLight ? 'rgba(0,0,0,.05)' : 'rgba(255,255,255,.05)';

  const chartDefaults = {
    responsive: true, maintainAspectRatio: false,
    plugins: { legend: { labels: { color: textColor, font: { size: 12, family: "'Inter', sans-serif" } } } }
  };

  // Helper to destroy existing chart if it exists
  const getCtx = (id) => {
    const canvas = document.getElementById(id);
    if (!canvas) return null;
    const existingChart = Chart.getChart(canvas);
    if (existingChart) existingChart.destroy();
    return canvas.getContext('2d');
  };

  // -- Locker Status Doughnut --
  const dCtx = getCtx('lockerStatusChart');
  if (dCtx && data.lockerStatus) {
    new Chart(dCtx, {
      type: 'doughnut',
      data: {
        labels: ['Available', 'Occupied', 'Maintenance'],
        datasets: [{
          data: [data.lockerStatus.available, data.lockerStatus.occupied, data.lockerStatus.maintenance],
          backgroundColor: ['#00c9b1', '#ef476f', '#ffd166'],
          borderWidth: 0, hoverOffset: 6,
        }]
      },
      options: {
        ...chartDefaults,
        cutout: '72%',
        plugins: {
          ...chartDefaults.plugins,
          tooltip: { callbacks: { label: ctx => ` ${ctx.label}: ${ctx.raw}` } }
        }
      }
    });
  }

  // -- Monthly Revenue Line --
  const rCtx = getCtx('revenueChart');
  if (rCtx && data.revenue) {
    new Chart(rCtx, {
      type: 'line',
      data: {
        labels: data.revenue.labels,
        datasets: [{
          label: 'Revenue (₹)',
          data: data.revenue.values,
          borderColor: '#00c9b1',
          backgroundColor: isLight ? 'rgba(37, 99, 235, .05)' : 'rgba(0, 201, 177, .1)',
          tension: 0.4, fill: true, pointRadius: 4,
          pointBackgroundColor: '#00c9b1',
        }]
      },
      options: {
        ...chartDefaults,
        scales: {
          x: { ticks: { color: textColor }, grid: { display: false } },
          y: { ticks: { color: textColor }, grid: { color: gridColor } }
        }
      }
    });
  }

  // -- Payment Status Bar --
  const pCtx = getCtx('paymentStatusChart');
  if (pCtx && data.payments) {
    new Chart(pCtx, {
      type: 'bar',
      data: {
        labels: data.payments.labels,
        datasets: [
          { label: 'Paid', data: data.payments.paid, backgroundColor: '#00c9b1', borderRadius: 5 },
          { label: 'Pending', data: data.payments.pending, backgroundColor: '#ffd166', borderRadius: 5 },
          { label: 'Overdue', data: data.payments.overdue, backgroundColor: '#ef476f', borderRadius: 5 },
        ]
      },
      options: {
        ...chartDefaults,
        scales: {
          x: { stacked: true, ticks: { color: textColor }, grid: { display: false } },
          y: { stacked: true, ticks: { color: textColor }, grid: { color: gridColor } }
        }
      }
    });
  }

  // Listen for theme changes to re-render
  if (!window.hasThemeChartListener) {
    window.addEventListener('themeChanged', () => {
      initDashboardCharts(data);
    });
    window.hasThemeChartListener = true;
  }
}

/* ════════════════════════════════════════
   FORM VALIDATION
   ════════════════════════════════════════ */
function validateForm(formId) {
  const form = document.getElementById(formId);
  if (!form) return false;
  let valid = true;
  form.querySelectorAll('[required]').forEach(el => {
    if (!el.value.trim()) {
      el.classList.add('error-field');
      valid = false;
    } else {
      el.classList.remove('error-field');
    }
  });
  if (!valid) showToast('Please fill all required fields.', 'warning');
  return valid;
}

/* ════════════════════════════════════════
   DATA TABLE SEARCH (inline)
   ════════════════════════════════════════ */
function tableSearch(inputId, tableId) {
  const inp = document.getElementById(inputId);
  if (!inp) return;
  inp.addEventListener('input', () => {
    const q = inp.value.toLowerCase();
    document.querySelectorAll(`#${tableId} tbody tr`).forEach(row => {
      row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
  });
}
