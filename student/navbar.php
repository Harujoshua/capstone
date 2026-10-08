<?php 
include_once('auth.php');
$current = basename($_SERVER['PHP_SELF']); 
$studentName = $_SESSION['student_name'] ?? 'Student';

$menuItems = [
    ['file' => 'dashboard.php', 'label' => 'Dashboard', 'icon' => 'fa-solid fa-house'],
    ['file' => 'courses.php', 'label' => 'My Subjects', 'icon' => 'fa-solid fa-book'],
    ['file' => 'schedule.php', 'label' => 'My Schedule', 'icon' => 'fa-solid fa-calendar-days'],
    ['file' => 'profile.php', 'label' => 'My Profile', 'icon' => 'fa-solid fa-user'],
];
?>

<link rel="stylesheet" href="../faculty/faculty_assets/faculty_sidebar.css">

<div class="mobile-nav">
    <div class="mobile-nav-brand">
        <img src="../faculty/faculty_assets/neust_logo.png" alt="Logo">
        <span>STUDENT</span>
    </div>
    <i class="fa-solid fa-chevron-right toggle" id="mobileToggleIcon"></i>
</div>

<div class="sidebar-overlay" id="facultySidebarOverlay"></div>

<aside class="sidebar" id="facultySidebar">
    <header>
        <div class="sidebar-top">
            <div style="display: flex; align-items: center; gap: 10px;">
                <img src="../faculty/faculty_assets/neust_logo.png" alt="NEUST Logo" class="sidebar-logo">
                <div>
                    <div class="sidebar-title">STUDENT</div>
                    <div class="sidebar-sub">PORTAL</div>
                </div>
            </div>
        </div>
        <i class="fa-solid fa-chevron-right toggle" id="toggle"></i>
    </header>

    <ul class="sidebar-menu">
        <?php foreach ($menuItems as $item): ?>
            <li>
                <a href="<?= htmlspecialchars($item['file']) ?>" class="<?= $current === $item['file'] ? 'active' : '' ?>">
                    <span class="nav-icon"><i class="<?= htmlspecialchars($item['icon']) ?>" aria-hidden="true"></i></span>
                    <span><?= htmlspecialchars($item['label']) ?></span>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>

    <div class="sidebar-spacer"></div>

    <div class="sidebar-footer">
        <div class="profile-info">
            <div class="details">
                <div class="name"><?= htmlspecialchars($studentName) ?></div>
                <div class="role">Student</div>
            </div>
        </div>
        <a href="logout.php" class="logout-btn">
            Sign Out
        </a>
    </div>
</aside>

<?php include(__DIR__ . '/../logout_modal.php'); ?>
<?php include(__DIR__ . '/first_login_modal.php'); ?>

<script src="../faculty/faculty_assets/faculty_sidebar.js"></script>
<!-- Anime.js & Staggered Page Animations -->
<script src="../assets/js/anime.min.js"></script>
<script src="../assets/js/dashboard-animations.js" defer></script>