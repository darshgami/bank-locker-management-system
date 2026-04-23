<?php
/**
 * includes/footer.php
 * Closes layout divs, includes Bootstrap JS, Chart.js, app.js
 */
?>
        </div><!-- /.page-content -->
      </div><!-- /.main-content -->
    </div><!-- /.app-layout -->

  <!-- Bootstrap 5 JS -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  <!-- Chart.js -->
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
  <!-- App JS -->
  <script src="<?= BASE_URL ?>/assets/js/app.js"></script>
  <?php if (!empty($extraJS)) echo $extraJS; ?>
</body>
</html>
