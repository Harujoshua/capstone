<?php
/**
 * NEUST Gatepass - Reusable Session Timeout Inactivity Modal & Client Guard
 * Automatically warns the user and signs them out after a period of screen/website inactivity.
 */
require_once __DIR__ . '/session_guard.php';

$session_timeout_seconds = get_session_timeout_seconds();
// Warning duration: 60 seconds (or 30s if total timeout <= 120s)
$session_warning_seconds = ($session_timeout_seconds > 120) ? 60 : 30;
?>

<!-- Session Inactivity Warning Modal -->
<div id="sessionTimeoutModal" 
     class="session-timeout-modal" 
     aria-hidden="true" 
     role="dialog" 
     aria-labelledby="sessionTimeoutTitle" 
     aria-describedby="sessionTimeoutDesc"
     data-timeout="<?= htmlspecialchars($session_timeout_seconds) ?>"
     data-warning="<?= htmlspecialchars($session_warning_seconds) ?>">
    <div class="session-timeout-backdrop"></div>
    <div class="session-timeout-container">
        
        <h2 id="sessionTimeoutTitle">Session Expiring Soon</h2>
        <p id="sessionTimeoutDesc">
            You have been inactive for a while. For your security and privacy, you will be automatically logged out in:
        </p>

        <div class="session-countdown-wrapper">
            <div class="session-countdown-badge">
                <span id="sessionCountdownTimer" class="session-countdown-digits">01:00</span>
            </div>
            <div class="session-countdown-bar-container">
                <div id="sessionCountdownBar" class="session-countdown-fill"></div>
            </div>
        </div>

        <div class="session-timeout-actions">
            <button type="button" id="sessionStayLoggedInBtn" class="session-timeout-btn session-btn-stay">
                <i class="fa-solid fa-rotate-right"></i>
                <span>Stay Logged In</span>
            </button>
            <a href="logout.php?expired=1" id="sessionLogoutNowBtn" class="session-timeout-btn session-btn-logout">
                Sign Out
            </a>
        </div>
    </div>
</div>

<style>
.session-timeout-modal {
    position: fixed;
    inset: 0;
    z-index: 9999999;
    display: flex;
    align-items: center;
    justify-content: center;
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.28s cubic-bezier(0.4, 0, 0.2, 1);
}

.session-timeout-modal.show {
    opacity: 1;
    pointer-events: auto;
}

.session-timeout-backdrop {
    position: absolute;
    inset: 0;
    background: rgba(15, 23, 42, 0.45);
    backdrop-filter: blur(10px);
    -webkit-backdrop-filter: blur(10px);
}

.session-timeout-container {
    position: relative;
    background: rgba(255, 255, 255, 0.96);
    backdrop-filter: blur(24px);
    -webkit-backdrop-filter: blur(24px);
    border: 1px solid rgba(255, 255, 255, 0.8);
    border-radius: 28px;
    padding: 34px 28px 28px;
    width: 90%;
    max-width: 420px;
    box-shadow: 
        0 10px 15px -3px rgba(0, 0, 0, 0.08),
        0 25px 40px -8px rgba(15, 23, 42, 0.22),
        inset 0 1px 0 rgba(255, 255, 255, 0.9);
    text-align: center;
}

.session-timeout-container h2 {
    font-family: 'Inter', system-ui, -apple-system, sans-serif;
    color: #0f172a;
    font-size: 21px;
    font-weight: 700;
    margin: 0 0 10px;
    letter-spacing: -0.025em;
}

.session-timeout-container p {
    font-family: 'Inter', system-ui, -apple-system, sans-serif;
    color: #475569;
    font-size: 14px;
    line-height: 1.55;
    margin: 0 0 20px;
}

.session-countdown-wrapper {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    padding: 16px;
    margin-bottom: 24px;
}

.session-countdown-badge {
    margin-bottom: 12px;
}

.session-countdown-digits {
    font-family: 'Inter', ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
    font-size: 32px;
    font-weight: 800;
    color: #d97706;
    letter-spacing: 0.04em;
    font-variant-numeric: tabular-nums;
    text-shadow: 0 1px 2px rgba(217, 119, 6, 0.15);
    transition: color 0.3s ease;
}

.session-countdown-digits.critical {
    color: #dc2626;
}

.session-countdown-bar-container {
    width: 100%;
    height: 6px;
    background: #e2e8f0;
    border-radius: 999px;
    overflow: hidden;
}

.session-countdown-fill {
    width: 100%;
    height: 100%;
    background: linear-gradient(90deg, #f59e0b, #ef4444);
    border-radius: 999px;
    transition: width 0.9s linear;
}

.session-timeout-actions {
    display: flex;
    gap: 12px;
}

.session-timeout-btn {
    flex: 1;
    padding: 13px 18px;
    border-radius: 14px;
    font-family: 'Inter', system-ui, -apple-system, sans-serif;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.22s cubic-bezier(0.4, 0, 0.2, 1);
    border: none;
    outline: none;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
}

.session-timeout-btn.session-btn-stay {
    background: linear-gradient(135deg, #1a56db 0%, #2563eb 100%);
    color: #ffffff;
    box-shadow: 0 4px 12px rgba(37, 99, 235, 0.25);
}

.session-timeout-btn.session-btn-stay:hover {
    background: linear-gradient(135deg, #1e40af 0%, #1d4ed8 100%);
    transform: translateY(-1px);
    box-shadow: 0 6px 16px rgba(37, 99, 235, 0.35);
}

.session-timeout-btn.session-btn-stay:active {
    transform: translateY(0);
}

.session-timeout-btn.session-btn-logout {
    background: #f1f5f9;
    color: #475569;
    border: 1px solid #e2e8f0;
}

.session-timeout-btn.session-btn-logout:hover {
    background: #fee2e2;
    color: #b91c1c;
    border-color: #fecaca;
}

@media (max-width: 480px) {
    .session-timeout-container {
        padding: 26px 20px 22px;
        max-width: 330px;
        border-radius: 22px;
    }
    .session-timeout-container h2 {
        font-size: 19px;
    }
    .session-countdown-digits {
        font-size: 28px;
    }
    .session-timeout-actions {
        flex-direction: column-reverse;
    }
}
</style>

<script>
(function() {
    'use strict';

    function initSessionInactivityGuard() {
        const modal = document.getElementById('sessionTimeoutModal');
        if (!modal) return;

        const totalTimeout = parseInt(modal.getAttribute('data-timeout'), 10) || 900; // in seconds
        const warningWindow = parseInt(modal.getAttribute('data-warning'), 10) || 60; // in seconds
        
        const countdownTimerEl = document.getElementById('sessionCountdownTimer');
        const countdownBarEl = document.getElementById('sessionCountdownBar');
        const stayLoggedInBtn = document.getElementById('sessionStayLoggedInBtn');
        const logoutNowBtn = document.getElementById('sessionLogoutNowBtn');

        const STORAGE_KEY_ACTIVITY = 'neust_last_active_time';
        const STORAGE_KEY_EXPIRED = 'neust_session_expired_flag';
        const KEEP_ALIVE_URL = 'keep_alive.php';
        const LOGOUT_URL = 'logout.php?expired=1';

        let lastLocalActivity = Date.now();
        let lastServerPing = Date.now();
        let isWarningVisible = false;
        let isLoggingOut = false;

        // Sync initial activity timestamp
        try {
            const stored = parseInt(localStorage.getItem(STORAGE_KEY_ACTIVITY), 10);
            if (!stored || Date.now() - stored > (totalTimeout * 1000)) {
                localStorage.setItem(STORAGE_KEY_ACTIVITY, Date.now().toString());
            } else {
                lastLocalActivity = stored;
            }
        } catch (e) {
            // LocalStorage might be restricted
        }

        // Format seconds into MM:SS
        function formatTime(seconds) {
            const s = Math.max(0, Math.floor(seconds));
            const mins = Math.floor(s / 60);
            const secs = s % 60;
            return (mins < 10 ? '0' : '') + mins + ':' + (secs < 10 ? '0' : '') + secs;
        }

        // Record active user interaction
        function recordUserActivity(explicitPing) {
            if (isLoggingOut) return;

            const now = Date.now();

            // Throttle general mouse/key events to once per 1.5 seconds
            if (!explicitPing && (now - lastLocalActivity < 1500)) {
                return;
            }

            lastLocalActivity = now;
            try {
                localStorage.setItem(STORAGE_KEY_ACTIVITY, now.toString());
            } catch (e) {}

            // If warning modal is shown and user performed an explicit action (e.g. clicked button or pressed key)
            if (isWarningVisible && explicitPing) {
                hideWarningModal();
            }

            // Ping server if explicit OR if user is active and ping is older than 60s
            if (explicitPing || (now - lastServerPing > 60000)) {
                lastServerPing = now;
                pingKeepAlive();
            }
        }

        function pingKeepAlive() {
            try {
                fetch(KEEP_ALIVE_URL + '?action=ping&_t=' + Date.now(), {
                    method: 'GET',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    cache: 'no-store'
                }).then(function(resp) {
                    if (resp.status === 401) {
                        // Backend session is already expired or unauthorized
                        triggerAutoLogout();
                    }
                }).catch(function() {
                    // Ignore network glitches; local timer will safely enforce logout if offline
                });
            } catch (err) {}
        }

        function showWarningModal() {
            if (isWarningVisible || isLoggingOut) return;
            isWarningVisible = true;
            modal.classList.add('show');
            modal.setAttribute('aria-hidden', 'false');
            if (stayLoggedInBtn) {
                stayLoggedInBtn.focus();
            }
        }

        function hideWarningModal() {
            if (!isWarningVisible) return;
            isWarningVisible = false;
            modal.classList.remove('show');
            modal.setAttribute('aria-hidden', 'true');
            if (countdownTimerEl) {
                countdownTimerEl.classList.remove('critical');
            }
        }

        function triggerAutoLogout() {
            if (isLoggingOut) return;
            isLoggingOut = true;

            try {
                localStorage.setItem(STORAGE_KEY_EXPIRED, Date.now().toString());
            } catch (e) {}

            window.location.href = LOGOUT_URL;
        }

        // Main check loop (runs every 1000ms)
        function checkSessionInactivity() {
            if (isLoggingOut) return;

            const now = Date.now();
            let effectiveLastActivity = lastLocalActivity;

            try {
                const storedTime = parseInt(localStorage.getItem(STORAGE_KEY_ACTIVITY), 10);
                if (storedTime && storedTime > effectiveLastActivity) {
                    effectiveLastActivity = storedTime;
                    lastLocalActivity = storedTime;
                }
            } catch (e) {}

            const idleSeconds = Math.floor((now - effectiveLastActivity) / 1000);
            const remainingSeconds = totalTimeout - idleSeconds;

            if (remainingSeconds <= 0) {
                // Inactivity limit reached - perform auto logout
                triggerAutoLogout();
                return;
            }

            if (remainingSeconds <= warningWindow) {
                // In warning window - show warning modal
                showWarningModal();

                if (countdownTimerEl) {
                    countdownTimerEl.textContent = formatTime(remainingSeconds);
                    if (remainingSeconds <= 15) {
                        countdownTimerEl.classList.add('critical');
                    } else {
                        countdownTimerEl.classList.remove('critical');
                    }
                }

                if (countdownBarEl) {
                    const pct = Math.max(0, Math.min(100, (remainingSeconds / warningWindow) * 100));
                    countdownBarEl.style.width = pct + '%';
                }
            } else {
                // User has active time remaining
                if (isWarningVisible) {
                    hideWarningModal();
                }
            }
        }

        // Attach user interaction listeners (throttled)
        const activityEvents = ['mousemove', 'mousedown', 'keydown', 'touchstart', 'scroll', 'click', 'wheel'];
        activityEvents.forEach(function(evt) {
            window.addEventListener(evt, function() {
                // If warning is open, passive mousemove does not automatically dismiss it;
                // user must click or interact intentionally.
                if (!isWarningVisible) {
                    recordUserActivity(false);
                }
            }, { passive: true });
        });

        // "Stay Logged In" button click
        if (stayLoggedInBtn) {
            stayLoggedInBtn.addEventListener('click', function(e) {
                e.preventDefault();
                recordUserActivity(true);
            });
        }

        // "Sign Out" button click
        if (logoutNowBtn) {
            logoutNowBtn.addEventListener('click', function(e) {
                isLoggingOut = true;
                try {
                    localStorage.setItem(STORAGE_KEY_EXPIRED, Date.now().toString());
                } catch (err) {}
            });
        }

        // Cross-tab synchronization via localStorage events
        window.addEventListener('storage', function(e) {
            if (e.key === STORAGE_KEY_EXPIRED) {
                // Another tab timed out or logged out
                triggerAutoLogout();
            } else if (e.key === STORAGE_KEY_ACTIVITY && e.newValue) {
                const remoteTime = parseInt(e.newValue, 10);
                if (remoteTime > lastLocalActivity) {
                    lastLocalActivity = remoteTime;
                    if (isWarningVisible) {
                        hideWarningModal();
                    }
                }
            }
        });

        // Instant check when page visibility changes or window gains focus (e.g. laptop wake or tab switch)
        document.addEventListener('visibilitychange', function() {
            if (!document.hidden) {
                checkSessionInactivity();
            }
        });

        window.addEventListener('focus', function() {
            checkSessionInactivity();
        });

        // Periodic timer check (every 1 second)
        setInterval(checkSessionInactivity, 1000);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initSessionInactivityGuard);
    } else {
        initSessionInactivityGuard();
    }
})();
</script>
