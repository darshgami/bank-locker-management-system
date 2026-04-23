/**
 * theme.js - Theme Transition System
 * Handles Dark/Light mode switching and localStorage persistence.
 */

document.addEventListener('DOMContentLoaded', () => {
    const themeToggle = document.getElementById('themeToggle');
    if (!themeToggle) return;

    themeToggle.addEventListener('click', () => {
        const currentTheme = document.documentElement.getAttribute('data-theme') || 'dark';
        const newTheme = currentTheme === 'dark' ? 'light' : 'dark';

        // Update DOM
        document.documentElement.setAttribute('data-theme', newTheme);

        // Update Icon
        updateThemeIcon(newTheme);

        // Persist
        localStorage.setItem('bank_locker_theme', newTheme);

        // Dispatch event for other components (like Charts)
        window.dispatchEvent(new CustomEvent('themeChanged', { detail: { theme: newTheme } }));
    });

    // Initial icon state
    const savedTheme = localStorage.getItem('bank_locker_theme') || 'dark';
    updateThemeIcon(savedTheme);
});

function updateThemeIcon(theme) {
    const icon = document.querySelector('#themeToggle i');
    if (!icon) return;

    if (theme === 'light') {
        icon.className = 'fas fa-sun';
    } else {
        icon.className = 'fas fa-moon';
    }
}
