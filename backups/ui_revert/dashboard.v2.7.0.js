(function(window, document){
    'use strict';
    const config = window.ARSHLINE_DASHBOARD || {};
    const ARSHLINE_REST = config.restUrl || '';
    const ARSHLINE_NONCE = config.restNonce || '';
    const ARSHLINE_CAN_MANAGE = !!config.canManage;
    const ARSHLINE_LOGIN_URL = config.loginUrl || '';
    const STRINGS = config.strings || {};
    const t = (key, fallback) => {
        const value = STRINGS[key];
        return typeof value !== 'undefined' ? value : fallback;
    };

    // Minimal fallback: Show warning message
    document.addEventListener('DOMContentLoaded', function() {
        const content = document.getElementById('arshlineDashboardContent');
        if (content) {
            content.innerHTML = '<div class=\"card glass\" style=\"padding:2rem;text-align:center;\"><h2>' + t('dashboard_unavailable', 'داشبورد موقتاً غیرفعال است') + '</h2><p>' + t('maintenance_message', 'سیستم در حال تعمیر و نگهداری است. لطفاً بعداً دوباره امتحان کنید.') + '</p></div>';
        }
    });

    // Expose notify function for compatibility
    window.ARSHLINE = window.ARSHLINE || {};
    window.ARSHLINE.notify = function(message, options) {
        console.warn('Dashboard notification:', message, options);
    };
    window.ARSHLINE.t = t;
})(window, document);
