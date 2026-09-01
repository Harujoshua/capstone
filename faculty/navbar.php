<?php
$current = basename($_SERVER['PHP_SELF']);
$teacherName = $_SESSION['faculty_name'] ?? 'Teacher';

$menuItems = [
    ['file' => 'dashboard.php', 'label' => 'Dashboard', 'icon' => 'fa-solid fa-house'],
    ['file' => 'attendance.php', 'label' => 'Attendance', 'icon' => 'fa-solid fa-clipboard-check'],
    ['file' => 'classes.php', 'label' => 'Classes', 'icon' => 'fa-solid fa-calendar-days'],
    ['file' => 'students.php', 'label' => 'Students', 'icon' => 'fa-solid fa-user-graduate'],
    ['file' => 'excuse_letters.php', 'label' => 'Excuse Letters', 'icon' => 'fa-solid fa-file-medical'],
    ['file' => 'reports.php', 'label' => 'Reports', 'icon' => 'fa-solid fa-file-lines'],
];
?>

<div class="mobile-nav">
    <div class="mobile-nav-brand">
        <img src="faculty_assets/neust_logo.png" alt="Logo">
        <span>FACULTY</span>
    </div>
    <i class="fa-solid fa-chevron-right toggle" id="mobileToggleIcon"></i>
</div>

<div class="sidebar-overlay" id="facultySidebarOverlay"></div>

<aside class="sidebar" id="facultySidebar">
    <header>
        <div class="sidebar-top">
            <div style="display: flex; align-items: center; gap: 10px;">
                <img src="faculty_assets/neust_logo.png" alt="NEUST Logo" class="sidebar-logo">
                <div>
                    <div class="sidebar-title">FACULTY</div>
                    <div class="sidebar-sub">NEUST CARRANGLAN</div>
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
                <div class="name"><?= htmlspecialchars($teacherName) ?></div>
                <div class="role">Faculty Member</div>
            </div>
        </div>
        <a href="logout.php" class="logout-btn">
            Sign Out
        </a>
    </div>
</aside>

<?php include(__DIR__ . '/../logout_modal.php'); ?>

<script src="faculty_assets/faculty_sidebar.js"></script>
