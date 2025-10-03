/**
 * Persian Utilities - مجموعه ابزارهای مشترک فارسی
 * Version: 7.0.0
 * 
 * این فایل شامل توابع مشترک برای کار با متن‌های فارسی است
 * که در سراسر پروژه استفاده می‌شوند.
 */

(function(window) {
    'use strict';

    // Namespace برای ابزارهای فارسی
    if (!window.ARSHLINE) window.ARSHLINE = {};
    if (!window.ARSHLINE.Persian) window.ARSHLINE.Persian = {};

    /**
     * تبدیل ارقام فارسی و عربی به انگلیسی
     * @param {string} str - رشته ورودی
     * @returns {string} رشته با ارقام انگلیسی
     */
    function normalizeDigits(str) {
        if (typeof str !== 'string') return str;
        
        const farsiDigits = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
        const arabicDigits = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
        const englishDigits = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];

        let result = str;
        
        // تبدیل ارقام فارسی
        farsiDigits.forEach((farsi, index) => {
            result = result.replace(new RegExp(farsi, 'g'), englishDigits[index]);
        });
        
        // تبدیل ارقام عربی
        arabicDigits.forEach((arabic, index) => {
            result = result.replace(new RegExp(arabic, 'g'), englishDigits[index]);
        });
        
        return result;
    }

    /**
     * تبدیل ارقام انگلیسی به فارسی
     * @param {string} str - رشته ورودی
     * @returns {string} رشته با ارقام فارسی
     */
    function toPersianDigits(str) {
        if (typeof str !== 'string') return str;
        
        const englishDigits = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
        const farsiDigits = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];

        let result = str;
        englishDigits.forEach((english, index) => {
            result = result.replace(new RegExp(english, 'g'), farsiDigits[index]);
        });
        
        return result;
    }

    /**
     * اعتبارسنجی کد ملی ایران
     * @param {string} nationalId - کد ملی
     * @returns {boolean} معتبر یا نامعتبر
     */
    function validateNationalId(nationalId) {
        const id = normalizeDigits(nationalId.toString()).replace(/\D/g, '');
        
        if (id.length !== 10) return false;
        if (/^(\d)\1{9}$/.test(id)) return false; // همه ارقام یکسان
        
        let sum = 0;
        for (let i = 0; i < 9; i++) {
            sum += parseInt(id[i]) * (10 - i);
        }
        
        const remainder = sum % 11;
        const checkDigit = parseInt(id[9]);
        
        return remainder < 2 ? checkDigit === remainder : checkDigit === 11 - remainder;
    }

    /**
     * اعتبارسنجی شماره موبایل ایران
     * @param {string} mobile - شماره موبایل
     * @returns {boolean} معتبر یا نامعتبر
     */
    function validateIranMobile(mobile) {
        const cleaned = normalizeDigits(mobile.toString()).replace(/\D/g, '');
        return /^09[0-9]{9}$/.test(cleaned);
    }

    /**
     * اعتبارسنجی کد پستی ایران
     * @param {string} postalCode - کد پستی
     * @returns {boolean} معتبر یا نامعتبر
     */
    function validateIranPostalCode(postalCode) {
        const cleaned = normalizeDigits(postalCode.toString()).replace(/\D/g, '');
        return /^[1-9][0-9]{9}$/.test(cleaned);
    }

    /**
     * فرمت کردن شماره موبایل ایران
     * @param {string} mobile - شماره موبایل
     * @returns {string} شماره فرمت شده
     */
    function formatIranMobile(mobile) {
        const cleaned = normalizeDigits(mobile.toString()).replace(/\D/g, '');
        if (cleaned.length === 11 && cleaned.startsWith('09')) {
            return `${cleaned.slice(0, 4)}-${cleaned.slice(4, 7)}-${cleaned.slice(7)}`;
        }
        return mobile;
    }

    /**
     * فرمت کردن کد پستی ایران
     * @param {string} postalCode - کد پستی
     * @returns {string} کد پستی فرمت شده
     */
    function formatIranPostalCode(postalCode) {
        const cleaned = normalizeDigits(postalCode.toString()).replace(/\D/g, '');
        if (cleaned.length === 10) {
            return `${cleaned.slice(0, 5)}-${cleaned.slice(5)}`;
        }
        return postalCode;
    }

    /**
     * تشخیص نوع تاریخ (شمسی یا میلادی)
     * @param {string} date - تاریخ ورودی
     * @returns {string} 'jalali' | 'gregorian' | 'unknown'
     */
    function detectDateType(date) {
        const cleaned = normalizeDigits(date.toString());
        
        // شمسی: سال بین 1300-1500
        if (/^13[0-9]{2}[\/-][0-1][0-9][\/-][0-3][0-9]$/.test(cleaned)) {
            return 'jalali';
        }
        
        // میلادی: سال بین 1900-2100
        if (/^(19|20)[0-9]{2}[\/-][0-1][0-9][\/-][0-3][0-9]$/.test(cleaned)) {
            return 'gregorian';
        }
        
        return 'unknown';
    }

    /**
     * پاکسازی متن از کاراکترهای غیرضروری
     * @param {string} text - متن ورودی
     * @returns {string} متن پاکسازی شده
     */
    function cleanText(text) {
        if (typeof text !== 'string') return text;
        
        return text
            .replace(/[\u200B-\u200D\uFEFF]/g, '') // حذف zero-width characters
            .replace(/\u00A0/g, ' ') // تبدیل non-breaking space به space عادی
            .trim();
    }

    // Export کردن توابع
    const PersianUtils = {
        normalizeDigits,
        toPersianDigits,
        validateNationalId,
        validateIranMobile,
        validateIranPostalCode,
        formatIranMobile,
        formatIranPostalCode,
        detectDateType,
        cleanText
    };

    // تعیین exports
    window.ARSHLINE.Persian = PersianUtils;

    // Backward compatibility
    window.normalizeDigits = normalizeDigits;

})(window);