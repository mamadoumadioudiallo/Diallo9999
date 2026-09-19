// ============================================================
// FleetIoT — Projet Simandou 2040
// Fichier : assets/js/contact-form.js
// Rôle    : Menu mobile + validation côté client du formulaire contact
// ============================================================

document.addEventListener('DOMContentLoaded', function () {

    // ---------- Menu hamburger mobile ----------
    var toggle = document.getElementById('navToggle');
    var menu = document.getElementById('navMenu');

    if (toggle && menu) {
        toggle.addEventListener('click', function () {
            menu.classList.toggle('open');
        });
    }

    // ---------- Validation formulaire de contact ----------
    var form = document.getElementById('contactForm');
    if (!form) return;

    var nomInput = document.getElementById('nom');
    var emailInput = document.getElementById('email');
    var messageInput = document.getElementById('message');

    var errNom = document.getElementById('errNom');
    var errEmail = document.getElementById('errEmail');
    var errMessage = document.getElementById('errMessage');

    function isValidEmail(value) {
        return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value);
    }

    form.addEventListener('submit', function (e) {
        var valid = true;

        // Nom
        if (nomInput.value.trim() === '') {
            errNom.classList.add('active');
            valid = false;
        } else {
            errNom.classList.remove('active');
        }

        // Email
        if (!isValidEmail(emailInput.value.trim())) {
            errEmail.classList.add('active');
            valid = false;
        } else {
            errEmail.classList.remove('active');
        }

        // Message
        if (messageInput.value.trim() === '') {
            errMessage.classList.add('active');
            valid = false;
        } else {
            errMessage.classList.remove('active');
        }

        if (!valid) {
            e.preventDefault();
        }
    });
});