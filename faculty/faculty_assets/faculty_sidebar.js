(function () {
    const body = document.querySelector('body');
    const sidebar = document.getElementById('facultySidebar');
    const toggles = document.querySelectorAll('.toggle');
    const overlay = document.getElementById('facultySidebarOverlay');

    if (!sidebar || !overlay) return;

    // Toggle Sidebar (Mini/Full or Mobile Open/Close)
    toggles.forEach(toggle => {
        toggle.addEventListener("click", () => {
            if (window.innerWidth <= 960) {
                sidebar.classList.toggle("open");
                overlay.classList.toggle("open");
                body.classList.toggle("mobile-menu-open");
            } else {
                sidebar.classList.toggle("close");
                if (sidebar.classList.contains("close")) {
                    body.classList.add("sidebar-closed");
                    localStorage.setItem("faculty-sidebar-status", "closed");
                } else {
                    body.classList.remove("sidebar-closed");
                    localStorage.setItem("faculty-sidebar-status", "open");
                }
            }
        });
    });

    function closeMobileSidebar() {
        sidebar.classList.remove('open');
        overlay.classList.remove('open');
        body.classList.remove("mobile-menu-open");
    }

    overlay.addEventListener('click', closeMobileSidebar);

    // Load preferences
    window.addEventListener('DOMContentLoaded', () => {
        const savedStatus = localStorage.getItem("faculty-sidebar-status");
        if (savedStatus === "closed") {
            sidebar.classList.add("close");
            body.classList.add("sidebar-closed");
        }
    });

    window.addEventListener('resize', function () {
        if (window.innerWidth > 960) {
            closeMobileSidebar();
        }
    });
})();
