<!-- Reusable Custom Logout Confirmation Modal -->
<div id="customLogoutModal" class="custom-logout-modal" aria-hidden="true" role="dialog" aria-labelledby="customLogoutTitle" aria-describedby="customLogoutDesc">
    <div class="custom-logout-backdrop"></div>
    <div class="custom-logout-container">
        <div class="custom-logout-icon">
            <i class="fa-solid fa-right-from-bracket"></i>
        </div>
        <h2 id="customLogoutTitle">Are you sure?</h2>
        <p id="customLogoutDesc">You are about to log out</p>
        <div class="custom-logout-actions">
            <button type="button" id="customLogoutCancel" class="custom-logout-btn custom-btn-cancel">Cancel</button>
            <a href="logout.php" id="customLogoutConfirm" class="custom-logout-btn custom-btn-confirm">Sign Out</a>
        </div>
    </div>
</div>

<style>
.custom-logout-modal {
    position: fixed;
    inset: 0;
    z-index: 999999;
    display: flex;
    align-items: center;
    justify-content: center;
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.25s cubic-bezier(0.4, 0, 0.2, 1);
}

.custom-logout-modal.show {
    opacity: 1;
    pointer-events: auto;
}

.custom-logout-backdrop {
    position: absolute;
    inset: 0;
    background: rgba(15, 23, 42, 0.3);
    backdrop-filter: blur(8px);
    -webkit-backdrop-filter: blur(8px);
    transition: opacity 0.25s ease;
}

.custom-logout-container {
    position: relative;
    background: rgba(255, 255, 255, 0.9);
    backdrop-filter: blur(20px);
    -webkit-backdrop-filter: blur(20px);
    border: 1px solid rgba(255, 255, 255, 0.6);
    border-radius: 24px;
    padding: 32px 24px 28px;
    width: 90%;
    max-width: 380px;
    box-shadow: 
        0 4px 6px -1px rgba(0, 0, 0, 0.05),
        0 20px 25px -5px rgba(15, 23, 42, 0.15),
        0 10px 10px -5px rgba(15, 23, 42, 0.08),
        inset 0 1px 0 rgba(255, 255, 255, 0.8);
    text-align: center;
    transform: scale(0.9) translateY(15px);
    transition: transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
}

.custom-logout-modal.show .custom-logout-container {
    transform: scale(1) translateY(0);
}

.custom-logout-icon {
    width: 60px;
    height: 60px;
    background: #ffe4e6;
    color: #e11d48;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 22px;
    margin: 0 auto 20px;
    box-shadow: 0 0 0 8px rgba(254, 226, 226, 0.5);
}

.custom-logout-container h2 {
    font-family: 'Inter', system-ui, -apple-system, sans-serif;
    color: #0f172a;
    font-size: 20px;
    font-weight: 700;
    margin: 0 0 10px;
    letter-spacing: -0.02em;
}

.custom-logout-container p {
    font-family: 'Inter', system-ui, -apple-system, sans-serif;
    color: #475569;
    font-size: 14px;
    line-height: 1.5;
    margin: 0 0 24px;
}

.custom-logout-actions {
    display: flex;
    gap: 12px;
}

.custom-logout-btn {
    flex: 1;
    padding: 12px 16px;
    border-radius: 12px;
    font-family: 'Inter', system-ui, -apple-system, sans-serif;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    border: none;
    outline: none;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}

.custom-logout-btn.custom-btn-cancel {
    background: #f1f5f9;
    color: #475569;
    border: 1px solid #e2e8f0;
}

.custom-logout-btn.custom-btn-cancel:hover {
    background: #e2e8f0;
    color: #1e293b;
}

.custom-logout-btn.custom-btn-confirm {
    background: #e11d48;
    color: #ffffff;
    box-shadow: 0 4px 6px -1px rgba(225, 29, 72, 0.2);
}

.custom-logout-btn.custom-btn-confirm:hover {
    background: #be123c;
    box-shadow: 0 10px 15px -3px rgba(225, 29, 72, 0.3);
}

@media (max-width: 480px) {
    .custom-logout-container {
        padding: 24px 20px 20px;
        max-width: 320px;
    }
    
    .custom-logout-container h2 {
        font-size: 18px;
    }
    
    .custom-logout-container p {
        font-size: 13px;
        margin-bottom: 20px;
    }
}
</style>

<script>
(function() {
    function initLogoutModal() {
        // Find logout button/link elements
        const logoutTriggers = document.querySelectorAll('.logout-btn, .logout-link');
        const modal = document.getElementById('customLogoutModal');
        const cancelBtn = document.getElementById('customLogoutCancel');
        const backdrop = modal ? modal.querySelector('.custom-logout-backdrop') : null;

        if (!modal || !cancelBtn) return;

        logoutTriggers.forEach(function(trigger) {
            // Remove native browser confirmation if inline
            if (trigger.hasAttribute('onclick')) {
                trigger.removeAttribute('onclick');
            }
            
            trigger.addEventListener('click', function(e) {
                e.preventDefault();
                showModal();
            });
        });

        function showModal() {
            modal.classList.add('show');
            modal.setAttribute('aria-hidden', 'false');
            document.body.style.overflow = 'hidden';
            
            setTimeout(function() {
                cancelBtn.focus();
            }, 100);
        }

        function hideModal() {
            modal.classList.remove('show');
            modal.setAttribute('aria-hidden', 'true');
            document.body.style.overflow = '';
        }

        cancelBtn.addEventListener('click', hideModal);

        if (backdrop) {
            backdrop.addEventListener('click', hideModal);
        }

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && modal.classList.contains('show')) {
                hideModal();
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initLogoutModal);
    } else {
        initLogoutModal();
    }
})();
</script>

<?php include_once(__DIR__ . '/session_timeout_modal.php'); ?>
