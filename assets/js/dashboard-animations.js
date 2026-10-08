/**
 * Dashboard & Table Animations powered by Anime.js
 * NEUST Gatepass Portal
 */
(function () {
    'use strict';

    // Respect user's motion preferences
    const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (prefersReducedMotion) return;

    function initAnimations() {
        if (typeof anime === 'undefined') {
            console.warn('[Anime.js] Library not found. Skipping animations.');
            return;
        }

        // 1. Staggered Stat Cards Entrance
        const statCards = document.querySelectorAll('.stat-card, .rate-card');
        if (statCards.length > 0) {
            anime({
                targets: statCards,
                opacity: [0, 1],
                translateY: [24, 0],
                scale: [0.95, 1],
                delay: anime.stagger(70, { start: 100 }),
                duration: 750,
                easing: 'easeOutCubic'
            });
        }

        // 2. Number Counter Up on Stat Values
        const counterElements = document.querySelectorAll('.stat-value, .rate-value, .donut-percentage, .quick-stats .value');
        counterElements.forEach((el, index) => {
            if (el.children.length > 0) return;
            const rawText = el.textContent.trim();
            const match = rawText.match(/^([0-9,]+(?:\.[0-9]+)?)(.*)$/);
            if (!match) return;

            const numericStr = match[1].replace(/,/g, '');
            const targetVal = parseFloat(numericStr);
            if (isNaN(targetVal)) return;

            const suffix = match[2] || '';
            const isFloat = numericStr.includes('.');
            const hasCommas = match[1].includes(',');

            const obj = { val: 0 };
            anime({
                targets: obj,
                val: targetVal,
                round: isFloat ? 10 : 1,
                delay: 200 + (index * 60),
                duration: 1100,
                easing: 'easeOutExpo',
                update: function () {
                    let formatted = isFloat ? obj.val.toFixed(1) : Math.round(obj.val).toString();
                    if (hasCommas) {
                        formatted = formatted.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
                    }
                    el.textContent = formatted + suffix;
                }
            });
        });

        // 3. Staggered Entrance for Main Cards & Panels
        const mainCards = document.querySelectorAll('.card, .filter-card, .attendance-summary, .chart-card');
        if (mainCards.length > 0) {
            anime({
                targets: mainCards,
                opacity: [0, 1],
                translateY: [18, 0],
                delay: anime.stagger(80, { start: 180 }),
                duration: 650,
                easing: 'easeOutQuad'
            });
        }

        // 4. Staggered Table Rows Entrance (First 35 rows for crisp performance)
        const tableRows = Array.from(document.querySelectorAll('table tbody tr')).slice(0, 35);
        if (tableRows.length > 0) {
            anime({
                targets: tableRows,
                opacity: [0, 1],
                translateX: [-15, 0],
                delay: anime.stagger(28, { start: 300 }),
                duration: 480,
                easing: 'easeOutQuad'
            });
        }

        // 5. Activity Feed Items / List items
        const activityItems = document.querySelectorAll('.activity-item, .badge-item');
        if (activityItems.length > 0) {
            anime({
                targets: activityItems,
                opacity: [0, 1],
                translateY: [12, 0],
                delay: anime.stagger(40, { start: 350 }),
                duration: 500,
                easing: 'easeOutQuad'
            });
        }

        // 6. Interactive Click Pulse for Action Buttons
        const buttons = document.querySelectorAll('.action-btn, .btn, .view-rate-students-btn, .custom-logout-btn');
        buttons.forEach(btn => {
            btn.addEventListener('mousedown', function () {
                anime({
                    targets: this,
                    scale: 0.93,
                    duration: 120,
                    easing: 'easeOutQuad'
                });
            });
            const resetScale = function () {
                anime({
                    targets: this,
                    scale: 1,
                    duration: 200,
                    easing: 'easeOutElastic(1, .8)'
                });
            };
            btn.addEventListener('mouseup', resetScale);
            btn.addEventListener('mouseleave', resetScale);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAnimations);
    } else {
        initAnimations();
    }
})();
