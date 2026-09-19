<?php
// ============================================================
// FleetIoT — Projet Simandou 2040
// Fichier : deconnexion.php
// Rôle    : Destruction de la session utilisateur
// ============================================================

session_start();
session_unset();
session_destroy();

header('Location: connexion.php');
exit();
