<?php
// ============================================================
// FleetIoT — Projet Simandou 2040
// Fichier : includes/auth.php
// Rôle    : Vérification session + contrôle d'accès par rôle
// ============================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ====== DÉFINITION DYNAMIQUE DE BASE_URL ======
// Calculée automatiquement à partir de la racine du projet, pour que les
// redirections fonctionnent peu importe le sous-dossier d'où le script est appelé
// (admin/, superviseur/, rapports/, api/, ou la racine).
if (!defined('BASE_URL')) {
    $racineProjet = str_replace('\\', '/', dirname(__DIR__));      // ex: C:/xampp/htdocs/fleet_iot
    $racineServeur = str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''); // ex: C:/xampp/htdocs
    $cheminRelatif = str_replace($racineServeur, '', $racineProjet); // ex: /fleet_iot
    define('BASE_URL', rtrim($cheminRelatif, '/') . '/');
}

function requireLogin() {
    // Empêche le navigateur de mettre en cache les pages protégées : sans ça,
    // les boutons "Précédent"/"Suivant" peuvent réafficher une page sensible
    // depuis le cache local, même après déconnexion, sans repasser par le serveur.
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    if (!isset($_SESSION['user_id'])) {
        header('Location: ' . BASE_URL . 'connexion.php');
        exit();
    }

    // ====== DÉCONNEXION AUTOMATIQUE APRÈS INACTIVITÉ ======
    // Évite qu'une session reste ouverte indéfiniment sur un poste partagé
    // ou laissé sans surveillance (30 minutes d'inactivité maximum).
    $dureeMaxInactivite = 1800; // 30 minutes en secondes
    if (isset($_SESSION['derniere_activite']) && (time() - $_SESSION['derniere_activite'] > $dureeMaxInactivite)) {
        session_unset();
        session_destroy();
        header('Location: ' . BASE_URL . 'connexion.php?expiree=1');
        exit();
    }
    $_SESSION['derniere_activite'] = time();
}

function requireAdmin() {
    requireLogin();
    if ($_SESSION['user_role'] !== 'admin') {
        header('Location: ' . BASE_URL . 'superviseur/dashboard.php');
        exit();
    }
}

function requireSuperviseur() {
    requireLogin();
    if (!in_array($_SESSION['user_role'], ['admin', 'superviseur'])) {
        header('Location: ' . BASE_URL . 'connexion.php');
        exit();
    }
}

function requireConducteur() {
    requireLogin();
    if ($_SESSION['user_role'] !== 'conducteur') {
        header('Location: ' . BASE_URL . 'connexion.php');
        exit();
    }
}

function isAdmin() {
    return isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin';
}

function isSuperviseur() {
    return isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'superviseur';
}

function generateCsrfToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrfToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}