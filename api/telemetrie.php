<?php
// ============================================================
// FleetIoT — Projet Simandou 2040
// Fichier : api/telemetrie.php
// Rôle    : Retourne en JSON les dernières données de télémétrie
//           + nb alertes — appelé en polling toutes les 5 sec
// ============================================================

session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireLogin();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

// Dernière position de chaque véhicule actif
$sql = "
    SELECT v.id_vehicule, v.immatriculation, v.type, v.statut,
           t.latitude, t.longitude, t.vitesse, t.carburant,
           t.temperature_moteur, t.moteur_etat, t.poids_charge_kg, t.tpms_alerte, t.horodatage,
           c.nom AS c_nom, c.prenom AS c_prenom
    FROM vehicules v
    LEFT JOIN (
        SELECT t1.* FROM telemetrie t1
        INNER JOIN (
            SELECT id_vehicule, MAX(horodatage) AS max_horo
            FROM telemetrie GROUP BY id_vehicule
        ) t2 ON t1.id_vehicule = t2.id_vehicule AND t1.horodatage = t2.max_horo
    ) t ON v.id_vehicule = t.id_vehicule
    LEFT JOIN missions m ON m.id_vehicule = v.id_vehicule AND m.statut = 'en_cours'
    LEFT JOIN conducteurs c ON m.id_conducteur = c.id_conducteur
    WHERE v.statut = 'actif'
";

if (isSuperviseur() && !isAdmin()) {
    $sql .= " AND v.id_superviseur = :uid";
}

$stmt = $pdo->prepare($sql);
if (isSuperviseur() && !isAdmin()) {
    $stmt->execute(['uid' => $_SESSION['user_id']]);
} else {
    $stmt->execute();
}
$vehicules = $stmt->fetchAll();

// Nombre d'alertes non traitées
// Nombre d'alertes non traitées — filtré par superviseur si applicable
if (isSuperviseur() && !isAdmin()) {
    $stmtAlertes = $pdo->prepare("
        SELECT COUNT(*) FROM alertes a
        JOIN vehicules v ON a.id_vehicule = v.id_vehicule
        WHERE a.statut = 'non_traitee' AND v.id_superviseur = ?
    ");
    $stmtAlertes->execute([$_SESSION['user_id']]);
    $nbAlertes = (int) $stmtAlertes->fetchColumn();
} else {
    $nbAlertes = (int) $pdo->query("SELECT COUNT(*) FROM alertes WHERE statut = 'non_traitee'")->fetchColumn();
}

jsonResponse([
    'vehicules'  => $vehicules,
    'nb_alertes' => $nbAlertes,
    'timestamp'  => date('H:i:s'),
]);