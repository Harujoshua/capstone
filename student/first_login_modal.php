<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Only render the modal if student has first login pending
if (empty($_SESSION['student_is_first_login'])) {
    return;
}

$studentDisplayName = $_SESSION['student_name'] ?? 'Student';
?>
<!-- First Login: Create New Password Modal -->
<div id="firstLoginPasswordModal" class="first-login-modal show" role="dialog" aria-modal="true" aria-labelledby="firstLoginTitle">
    <div class="first-login-backdrop"></div>
    <div class="first-login-card">
        <!-- Input Form View -->
        <div id="firstLoginFormView">
            <div class="first-login-icon">
                <i class="fa-solid fa-key"></i>
            </div>
            <h2 id="firstLoginTitle">Welcome, <?= htmlspecialchars($studentDisplayName) ?>!</h2>
            <p class="first-login-desc">Please set a new password to activate and secure your student portal account.</p>

            <div id="firstLoginAlert" class="first-login-alert" style="display: none;"></div>

            <form id="firstLoginForm" method="POST" action="set_first_password.php" novalidate>
                <div class="first-login-form-group">
                    <label for="first_new_password">New Password</label>
                    <div class="first-login-pw-wrap">
                        <input type="password" id="first_new_password" name="new_password" required minlength="6" placeholder="Enter new password (min. 6 chars)" autocomplete="new-password">
                        <button type="button" class="first-login-toggle-pw" aria-label="Show password">
                            <i class="fa-regular fa-eye"></i>
                        </button>
                    </div>
                </div>

                <div class="first-login-form-group">
                    <label for="first_confirm_password">Confirm New Password</label>
                    <div class="first-login-pw-wrap">
                        <input type="password" id="first_confirm_password" name="confirm_password" required minlength="6" placeholder="Confirm your new password" autocomplete="new-password">
                        <button type="button" class="first-login-toggle-pw" aria-label="Show password">
                            <i class="fa-regular fa-eye"></i>
                        </button>
                    </div>
                </div>

                <div class="first-login-hint">
                    <i class="fa-solid fa-circle-info"></i> Must be at least 6 characters long.
                </div>

                <button type="submit" id="firstLoginSubmitBtn" class="first-login-btn">
                    <span class="btn-text">Set Password</span>
                    <span class="btn-spinner" style="display: none;"><i class="fa-solid fa-spinner fa-spin"></i> Saving...</span>
                </button>
                
                <div class="first-login-footer">
                    <a href="logout.php" class="first-login-signout">Sign out for now</a>
                </div>
            </form>
        </div>

        <!-- Success Feedback View -->
        <div id="firstLoginSuccessView" style="display: none;">
            <div class="first-login-icon success-icon">
                <i class="fa-solid fa-check"></i>
            </div>
            <h2>Password Created!</h2>
            <p class="first-login-desc">Your password has been set successfully. You can now use it to log in next time.</p>
            <div class="first-login-redirecting">
                <i class="fa-solid fa-circle-notch fa-spin"></i> Taking you to your dashboard...
            </div>
        </div>
    </div>
</div>

<style>
.first-login-modal {
    position: fixed;
    inset: 0;
    z-index: 999998;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 16px;
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    font-family: 'Inter', system-ui, -apple-system, sans-serif;
}

.first-login-modal.show {
    opacity: 1;
    pointer-events: auto;
}

.first-login-backdrop {
    position: absolute;
    inset: 0;
    background: rgba(15, 23, 42, 0.65);
    backdrop-filter: blur(8px);
    -webkit-backdrop-filter: blur(8px);
}

.first-login-card {
    position: relative;
    background: #ffffff;
    border-radius: 20px;
    padding: 34px 28px 26px;
    width: 100%;
    max-width: 420px;
    box-shadow: 
        0 4px 6px -1px rgba(0, 0, 0, 0.05),
        0 20px 25px -5px rgba(15, 23, 42, 0.25),
        0 10px 10px -5px rgba(15, 23, 42, 0.1);
    text-align: center;
    transform: scale(0.92) translateY(10px);
    transition: transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
    box-sizing: border-box;
}

.first-login-modal.show .first-login-card {
    transform: scale(1) translateY(0);
}

.first-login-icon {
    width: 60px;
    height: 60px;
    background: #eff6ff;
    color: #1a56db;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
    margin: 0 auto 16px;
    box-shadow: 0 0 0 8px rgba(219, 234, 254, 0.6);
}

.first-login-icon.success-icon {
    background: #dcfce7;
    color: #16a34a;
    box-shadow: 0 0 0 8px rgba(220, 252, 231, 0.6);
}

.first-login-card h2 {
    font-size: 1.3rem;
    font-weight: 700;
    color: #0f172a;
    margin: 0 0 8px;
    letter-spacing: -0.01em;
}

.first-login-desc {
    font-size: 0.9rem;
    color: #475569;
    margin: 0 0 20px;
    line-height: 1.45;
}

.first-login-alert {
    padding: 10px 14px;
    border-radius: 8px;
    font-size: 0.88rem;
    margin-bottom: 16px;
    text-align: left;
    display: flex;
    align-items: center;
    gap: 8px;
    line-height: 1.4;
}

.first-login-alert.alert-danger {
    background: #fee2e2;
    color: #991b1b;
    border: 1px solid #fecaca;
}

.first-login-alert.alert-success {
    background: #dcfce7;
    color: #166534;
    border: 1px solid #bbf7d0;
}

.first-login-form-group {
    margin-bottom: 14px;
    text-align: left;
}

.first-login-form-group label {
    display: block;
    font-size: 0.86rem;
    font-weight: 600;
    color: #1e293b;
    margin-bottom: 5px;
}

.first-login-pw-wrap {
    position: relative;
    display: flex;
    align-items: center;
}

.first-login-pw-wrap input {
    width: 100%;
    padding: 11px 40px 11px 14px;
    border: 1.5px solid #e2e8f0;
    border-radius: 10px;
    font-size: 0.94rem;
    font-family: 'Inter', system-ui, -apple-system, sans-serif;
    color: #0f172a;
    outline: none;
    transition: border-color 0.2s, box-shadow 0.2s;
    background: #f8fafc;
    box-sizing: border-box;
}

.first-login-pw-wrap input:focus {
    background: #ffffff;
    border-color: #1a56db;
    box-shadow: 0 0 0 3px rgba(26, 86, 219, 0.15);
}

.first-login-toggle-pw {
    position: absolute;
    right: 10px;
    background: none;
    border: none;
    color: #64748b;
    cursor: pointer;
    padding: 6px;
    font-size: 0.95rem;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: color 0.15s;
    outline: none;
}

.first-login-toggle-pw:hover {
    color: #0f172a;
}

.first-login-hint {
    font-size: 0.8rem;
    color: #64748b;
    margin: -4px 0 18px 0;
    text-align: left;
    display: flex;
    align-items: center;
    gap: 6px;
}

.first-login-btn {
    width: 100%;
    padding: 12px 16px;
    background: #1a56db;
    color: #ffffff;
    font-weight: 600;
    font-size: 0.96rem;
    font-family: 'Inter', system-ui, -apple-system, sans-serif;
    border: none;
    border-radius: 10px;
    cursor: pointer;
    box-shadow: 0 4px 10px rgba(26, 86, 219, 0.25);
    transition: all 0.2s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    box-sizing: border-box;
}

.first-login-btn:hover {
    background: #1e40af;
    box-shadow: 0 6px 14px rgba(26, 86, 219, 0.35);
}

.first-login-btn:disabled {
    opacity: 0.75;
    cursor: not-allowed;
}

.first-login-footer {
    margin-top: 16px;
    font-size: 0.86rem;
}

.first-login-signout {
    color: #64748b;
    text-decoration: none;
    font-weight: 500;
    transition: color 0.15s;
}

.first-login-signout:hover {
    color: #e11d48;
    text-decoration: underline;
}

.first-login-redirecting {
    font-size: 0.9rem;
    color: #166534;
    font-weight: 600;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    margin-top: 16px;
}

@media (max-width: 480px) {
    .first-login-card {
        padding: 26px 20px 20px;
    }
    .first-login-card h2 {
        font-size: 1.18rem;
    }
}
</style>

<script>
(function() {
    function initFirstLoginModal() {
        const modal = document.getElementById('firstLoginPasswordModal');
        if (!modal) return;

        // Prevent body scrolling while first login is mandatory
        document.body.style.overflow = 'hidden';

        const form = document.getElementById('firstLoginForm');
        const submitBtn = document.getElementById('firstLoginSubmitBtn');
        const alertBox = document.getElementById('firstLoginAlert');
        const newPwInput = document.getElementById('first_new_password');
        const confirmPwInput = document.getElementById('first_confirm_password');
        const formView = document.getElementById('firstLoginFormView');
        const successView = document.getElementById('firstLoginSuccessView');

        // Toggle Password Show/Hide
        const toggleButtons = modal.querySelectorAll('.first-login-toggle-pw');
        toggleButtons.forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                const wrap = btn.closest('.first-login-pw-wrap');
                const input = wrap.querySelector('input');
                const icon = btn.querySelector('i');
                if (!input) return;

                if (input.type === 'password') {
                    input.type = 'text';
                    icon.classList.remove('fa-eye');
                    icon.classList.add('fa-eye-slash');
                    btn.setAttribute('aria-label', 'Hide password');
                } else {
                    input.type = 'password';
                    icon.classList.remove('fa-eye-slash');
                    icon.classList.add('fa-eye');
                    btn.setAttribute('aria-label', 'Show password');
                }
            });
        });

        function showAlert(message, type) {
            if (!alertBox) return;
            alertBox.className = 'first-login-alert ' + (type === 'success' ? 'alert-success' : 'alert-danger');
            const iconHtml = type === 'success' ? '<i class="fa-solid fa-circle-check"></i>' : '<i class="fa-solid fa-circle-exclamation"></i>';
            alertBox.innerHTML = iconHtml + '<span>' + message + '</span>';
            alertBox.style.display = 'flex';
        }

        function hideAlert() {
            if (!alertBox) return;
            alertBox.style.display = 'none';
        }

        if (form) {
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                hideAlert();

                const newPw = newPwInput.value.trim();
                const confirmPw = confirmPwInput.value.trim();

                if (!newPw || !confirmPw) {
                    showAlert('Please fill in both password fields.', 'danger');
                    return;
                }

                if (newPw.length < 6) {
                    showAlert('Password must be at least 6 characters long.', 'danger');
                    newPwInput.focus();
                    return;
                }

                if (newPw !== confirmPw) {
                    showAlert('Passwords do not match. Please verify.', 'danger');
                    confirmPwInput.focus();
                    return;
                }

                // Show loading spinner
                const btnText = submitBtn.querySelector('.btn-text');
                const btnSpinner = submitBtn.querySelector('.btn-spinner');
                submitBtn.disabled = true;
                if (btnText) btnText.style.display = 'none';
                if (btnSpinner) btnSpinner.style.display = 'inline-block';

                const formData = new FormData();
                formData.append('ajax', '1');
                formData.append('new_password', newPw);
                formData.append('confirm_password', confirmPw);

                fetch('set_first_password.php', {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(function(res) {
                    return res.json();
                })
                .then(function(data) {
                    if (data && data.status === 'success') {
                        // Switch to success view
                        formView.style.display = 'none';
                        successView.style.display = 'block';

                        setTimeout(function() {
                            modal.classList.remove('show');
                            document.body.style.overflow = '';
                            // Reload to refresh the session state without query params
                            window.location.reload();
                        }, 1300);
                    } else {
                        showAlert(data.message || 'An error occurred while setting your password.', 'danger');
                        submitBtn.disabled = false;
                        if (btnText) btnText.style.display = 'inline-block';
                        if (btnSpinner) btnSpinner.style.display = 'none';
                    }
                })
                .catch(function(err) {
                    showAlert('Network error. Please try again.', 'danger');
                    submitBtn.disabled = false;
                    if (btnText) btnText.style.display = 'inline-block';
                    if (btnSpinner) btnSpinner.style.display = 'none';
                });
            });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initFirstLoginModal);
    } else {
        initFirstLoginModal();
    }
})();
</script>
