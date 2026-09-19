<?php
// ============================================================
// FleetIoT — Projet Simandou 2040
// Fichier : api/vehicules.php
// Rôle    : Retourne en JSON la liste des véhicules (avec dernier
//           état télémétrique) — admin voit tout, superviseur
//           ne voit que ses véhicules assignés
// ============================================================

session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireLogin();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

$sql = "
    SELECT v.id_vehicule, v.immatriculation, v.marque, v.modele, v.annee,
           v.type, v.capacite_carburant, v.statut,
           u.id_user AS id_superviseur, u.nom AS sup_nom, u.prenom AS sup_prenom,
           t.latitude, t.longitude, t.vitesse, t.carburant, t.temperature_moteur,
           t.kilometrage, t.horodatage AS derniere_maj
    FROM vehicules v
    LEFT JOIN utilisateurs u ON v.id_superviseur = u.id_user
    LEFT JOIN (
        SELECT t1.* FROM telemetrie t1
        INNER JOIN (
            SELECT id_vehicule, MAX(horodatage) AS max_horo
            FROM telemetrie GROUP BY id_vehicule
        ) t2 ON t1.id_vehicule = t2.id_vehicule AND t1.horodatage = t2.max_horo
    ) t ON v.id_vehicule = t.id_vehicule
";

// Si superviseur : ne retourner que ses véhicules assignés
if (isSuperviseur() && !isAdmin()) {
    $sql .= " WHERE v.id_superviseur = :uid";
}

$sql .= " ORDER BY v.immatriculation";

$stmt = $pdo->prepare($sql);
if (isSuperviseur() && !isAdmin()) {
    $stmt->execute(['uid' => $_SESSION['user_id']]);
} else {
    $stmt->execute();
}

$result = $stmt->fetchAll();

jsonResponse($result);