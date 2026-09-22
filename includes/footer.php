    </div> <!-- /.app-content -->
    <footer class="text-center py-3 border-top bg-white text-muted small no-print mt-auto">
        <div class="container-fluid">
            &copy; <?= date('Y') ?> <strong><?= htmlspecialchars(getSetting('school_name', 'KETAN M/A B COMPLEX')) ?></strong>. 
            <?= htmlspecialchars(getSetting('school_tagline', 'School-Based Assessment & Performance Management System')) ?>.
        </div>
    </footer>
</main> <!-- /.app-main -->
</div> <!-- /.app-wrapper -->

<!-- Bootstrap 5 Bundle JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<!-- Main App JS -->
<script src="<?= asset('js/main.js') ?>"></script>
</body>
</html>
