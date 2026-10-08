<?php include('auth.php'); ?>
<?php
$current = basename($_SERVER['PHP_SELF']);
$adminName = $_SESSION['admin_username'] ?? 'Admin';
$initials = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $adminName), 0, 2));
if ($initials === '') {
    $initials = 'AD';
}

$adminRole = $_SESSION['admin_role'] ?? 'sub_admin';

$menuItems = [
    ['file' => 'dashboard.php', 'label' => 'Dashboard', 'icon' => 'fa-solid fa-house'],
    ['file' => 'register.php', 'label' => 'Registration', 'icon' => 'fa-solid fa-user-plus'],
    ['file' => 'faculty.php', 'label' => 'Faculty', 'icon' => 'fa-solid fa-chalkboard-user'],
    ['file' => 'class_schedules.php', 'label' => 'Class Schedules', 'icon' => 'fa-solid fa-calendar-days'],
    ['file' => 'students.php', 'label' => 'Students', 'icon' => 'fa-solid fa-user-graduate'],
    ['file' => 'logs.php',         'label' => 'Student Attendance Logs', 'icon' => 'fa-solid fa-clipboard-list'],
    ['file' => 'faculty_logs.php', 'label' => 'Faculty Attendance Logs', 'icon' => 'fa-solid fa-chalkboard-user'],
];

if ($adminRole === 'super_admin') {
    $menuItems[] = ['file' => 'manage_admins.php', 'label' => 'User Management', 'icon' => 'fa-solid fa-users-gear'];
    $menuItems[] = ['file' => 'audit_logs.php', 'label' => 'Audit Logs', 'icon' => 'fa-solid fa-clock-rotate-left'];
}

$menuItems[] = ['file' => 'settings.php', 'label' => 'Settings', 'icon' => 'fa-solid fa-gear'];
?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
<link rel="stylesheet" href="admin_assets/admin_sidebar.css">

<div class="admin-mobile-nav" id="adminMobileNav">
    <div class="admin-mobile-nav-brand">
        <img src="admin_assets/neust_logo.png" alt="Logo">
        <span>ADMIN</span>
    </div>
    <i class="fa-solid fa-chevron-right admin-mobile-toggle" id="adminSidebarToggle"></i>
</div>

<div class="admin-sidebar-overlay" id="adminSidebarOverlay"></div>

<aside class="admin-sidebar" id="adminSidebar">
    <div class="admin-sidebar-top">
        <div style="display: flex; align-items: center; gap: 10px;">
            <img src="admin_assets/neust_logo.png" alt="NEUST Logo" class="admin-sidebar-logo">
            <div>
                <div class="admin-sidebar-title"><?= htmlspecialchars(strtoupper($adminName)) ?></div>
                <div class="admin-sidebar-sub"><?= $adminRole === 'super_admin' ? 'SUPER ADMINISTRATOR' : 'SITE SUPERVISOR' ?></div>
            </div>
        </div>
    </div>

    <ul class="admin-sidebar-menu">
        <?php foreach ($menuItems as $item): ?>
            <li>
                <a href="<?= htmlspecialchars($item['file']) ?>" class="<?= $current === $item['file'] ? 'active' : '' ?>">
                    <span class="nav-icon"><i class="<?= htmlspecialchars($item['icon']) ?>" aria-hidden="true"></i></span>
                    <span><?= htmlspecialchars($item['label']) ?></span>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>

    <div class="admin-sidebar-spacer"></div>

    <div class="admin-sidebar-card">
        <a href="logout.php" class="logout-link" style="width: 100%; text-align: center; display: block;">Sign Out</a>
    </div>
</aside>

<?php include(__DIR__ . '/../logout_modal.php'); ?>


<script>
    (function () {
        // Ensure proper mobile scaling even if page templates omit viewport meta.
        if (!document.querySelector('meta[name="viewport"]')) {
            var viewportMeta = document.createElement('meta');
            viewportMeta.name = 'viewport';
            viewportMeta.content = 'width=device-width, initial-scale=1';
            document.head.appendChild(viewportMeta);
        }

        var sidebar = document.getElementById('adminSidebar');
        var toggle = document.getElementById('adminSidebarToggle');
        var overlay = document.getElementById('adminSidebarOverlay');
        if (!sidebar || !toggle || !overlay) return;

        function closeSidebar() {
            sidebar.classList.remove('open');
            overlay.classList.remove('open');
            document.body.classList.remove('admin-mobile-menu-open');
        }

        toggle.addEventListener('click', function () {
            var willOpen = !sidebar.classList.contains('open');
            if (willOpen) {
                sidebar.classList.add('open');
                overlay.classList.add('open');
                document.body.classList.add('admin-mobile-menu-open');
            } else {
                closeSidebar();
            }
        });

        overlay.addEventListener('click', closeSidebar);
        window.addEventListener('resize', function () {
            if (window.innerWidth > 960) closeSidebar();
        });

        // Back to Top Logic
        var btt = document.getElementById('backToTop');
        if (btt) {
            window.addEventListener('scroll', function () {
                if (window.pageYOffset > 300) {
                    btt.classList.add('show');
                } else {
                    btt.classList.remove('show');
                }
            });
            btt.addEventListener('click', function () {
                window.scrollTo({ top: 0, behavior: 'smooth' });
            });
        }
    })();
</script>

<button id="backToTop" class="back-to-top" title="Back to Top">
    <i class="fa-solid fa-arrow-up"></i>
</button>

<!-- Anime.js & Staggered Page Animations -->
<script src="../assets/js/anime.min.js"></script>
<script src="../assets/js/dashboard-animations.js" defer></script>

