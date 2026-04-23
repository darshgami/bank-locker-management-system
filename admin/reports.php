<?php
/**
 * admin/reports.php — Revenue & Activity Reports
 */
require_once '../config/config.php';
requireRole('admin');

$db         = getDB();
$pageTitle  = 'Reports';
$activePage = 'reports';

$monthRevenue = $db->query(
  'SELECT DATE_FORMAT(payment_date,"%b %Y") AS mon,
          COUNT(*) AS count, SUM(amount) AS total
   FROM payments WHERE status="Paid"
   GROUP BY DATE_FORMAT(payment_date,"%Y-%m")
   ORDER BY payment_date DESC LIMIT 12'
)->fetchAll();

$sizeStats = $db->query(
  'SELECT size, COUNT(*) as total,
          SUM(status="Occupied") as occupied,
          SUM(status="Available") as available,
          AVG(rent_amount) as avg_rent
   FROM lockers GROUP BY size'
)->fetchAll();

$topCustomers = $db->query(
  'SELECT u.full_name, u.phone, c.risk_level,
          COUNT(p.id) AS payments,
          COALESCE(SUM(p.amount),0) AS total_paid
   FROM users u
   JOIN customers c ON c.user_id=u.id
   LEFT JOIN locker_assignments la ON la.customer_id=c.id
   LEFT JOIN payments p ON p.assignment_id=la.id AND p.status="Paid"
   GROUP BY u.id ORDER BY total_paid DESC LIMIT 10'
)->fetchAll();

include '../includes/header.php';
include '../includes/sidebar.php';
?>
<div class="main-content">
  <div class="page-content">
    <div class="page-header">
      <div>
        <div class="breadcrumb-nav"><a href="dashboard.php">Dashboard</a> / Reports</div>
        <h1>Reports & Analytics</h1>
        <p>Revenue breakdowns, locker utilization, and customer leaderboard.</p>
      </div>
      <div class="page-header-actions">
        <button class="btn btn-ghost btn-sm no-print" onclick="window.print()">
          <i class="fas fa-print"></i> Print Report
        </button>
      </div>
    </div>

    <!-- Analytics Overview -->
    <div class="grid-2 mb-4">
        <!-- Revenue Trend -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-chart-line text-primary"></i> Revenue Trend (Last 12 Months)</h3>
            </div>
            <div class="card-body">
                <div class="chart-wrap" style="height: 300px;">
                    <canvas id="revenueTrendChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Locker Status -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-chart-pie text-primary"></i> Locker Distribution</h3>
            </div>
            <div class="card-body d-flex align-items-center justify-content-center">
                <div class="chart-wrap" style="height: 300px; width: 300px;">
                    <canvas id="lockerPieChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Monthly Revenue Table -->
    <div class="card mb-4">
      <div class="card-header">
        <h3><i class="fas fa-indian-rupee-sign text-primary"></i> Monthly Revenue Summary</h3>
      </div>
      <div class="table-container">
        <table class="tbl">
          <thead><tr><th>Month</th><th>Payments</th><th>Revenue Collected</th></tr></thead>
          <tbody>
          <?php foreach(array_reverse($monthRevenue) as $r): ?>
            <tr>
              <td><b><?= h($r['mon']) ?></b></td>
              <td><?= (int)$r['count'] ?></td>
              <td><b style="color:var(--primary)">₹<?= number_format($r['total'],2) ?></b></td>
            </tr>
          <?php endforeach; ?>
          <?php if(!$monthRevenue): ?><tr><td colspan="3" style="text-align:center;color:var(--text-muted);padding:20px">No data</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Locker Size Stats -->
    <div class="card mb-3" style="margin-bottom:20px">
      <div class="card-header"><h3><i class="fas fa-lock text-primary"></i> Locker Utilization by Size</h3></div>
      <div class="table-container">
        <table class="tbl">
          <thead><tr><th>Size</th><th>Total</th><th>Occupied</th><th>Available</th><th>Avg Rent/Mo</th></tr></thead>
          <tbody>
          <?php foreach($sizeStats as $s): ?>
            <tr>
              <td><b><?= h($s['size']) ?></b></td>
              <td><?= (int)$s['total'] ?></td>
              <td><span class="badge badge-occupied"><?= (int)$s['occupied'] ?></span></td>
              <td><span class="badge badge-available"><?= (int)$s['available'] ?></span></td>
              <td>₹<?= number_format($s['avg_rent'],0) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Top Customers -->
    <div class="card">
      <div class="card-header"><h3><i class="fas fa-trophy text-gold"></i> Top Customers by Payment</h3></div>
      <div class="table-container">
        <table class="tbl">
          <thead><tr><th>#</th><th>Customer</th><th>Phone</th><th>Risk</th><th>Payments</th><th>Total Paid</th></tr></thead>
          <tbody>
          <?php foreach($topCustomers as $i => $c): ?>
            <tr>
              <td><?= $i+1 ?></td>
              <td><b><?= h($c['full_name']) ?></b></td>
              <td style="color:var(--text-muted)"><?= h($c['phone']) ?></td>
              <td><span class="badge badge-<?= $c['risk_level'] ?>"><?= ucfirst($c['risk_level']) ?></span></td>
              <td><?= (int)$c['payments'] ?></td>
              <td><b style="color:var(--primary)">₹<?= number_format($c['total_paid'],0) ?></b></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php
// Prepare Chart Data
$revenueLabels = []; $revenueValues = [];
foreach(array_reverse($monthRevenue) as $r) {
    $revenueLabels[] = $r['mon'];
    $revenueValues[] = (float)$r['total'];
}

$lockerTotal = $db->query("SELECT status, COUNT(*) as count FROM lockers GROUP BY status")->fetchAll();
$lockerLabels = []; $lockerValues = []; $lockerColors = [];
foreach($lockerTotal as $lt) {
    $lockerLabels[] = ucfirst($lt['status']);
    $lockerValues[] = (int)$lt['count'];
    $lockerColors[] = ($lt['status'] === 'Available' ? '#0d9488' : ($lt['status'] === 'Occupied' ? '#ef476f' : '#ffd166'));
}

$extraJS = '
<script>
document.addEventListener("DOMContentLoaded", () => {
    // Revenue Chart
    new Chart(document.getElementById("revenueTrendChart"), {
        type: "line",
        data: {
            labels: '.json_encode($revenueLabels).',
            datasets: [{
                label: "Revenue (₹)",
                data: '.json_encode($revenueValues).',
                borderColor: "#0d9488",
                backgroundColor: "rgba(13, 148, 136, 0.1)",
                borderWidth: 3,
                tension: 0.4,
                fill: true,
                pointBackgroundColor: "#0d9488",
                pointBorderColor: "#fff",
                pointHoverRadius: 6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                y: { beginAtZero: true, grid: { color: "var(--border)" }, ticks: { color: "var(--text-muted)" } },
                x: { grid: { display: false }, ticks: { color: "var(--text-muted)" } }
            }
        }
    });

    // Locker Pie Chart
    new Chart(document.getElementById("lockerPieChart"), {
        type: "doughnut",
        data: {
            labels: '.json_encode($lockerLabels).',
            datasets: [{
                data: '.json_encode($lockerValues).',
                backgroundColor: '.json_encode($lockerColors).',
                borderWidth: 0,
                hoverOffset: 12
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: "70%",
            plugins: {
                legend: { position: "bottom", labels: { color: "var(--text-muted)", padding: 20, usePointStyle: true } }
            }
        }
    });
});
</script>
';
include '../includes/footer.php'; ?>

