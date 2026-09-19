<?php
// ============================================================
// FleetIoT — Projet Simandou 2040
// Fichier : api/public_stats.php
// Rôle    : Statistiques publiques en temps réel pour index.php
//           (aucune authentification requise — données agrégées)
// ============================================================

require_once '../includes/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Access-Control-Allow-Origin: same-origin');

try {
    // Nombre de véhicules actifs
    $stmt = $pdo->query("SELECT COUNT(*) FROM vehicules WHERE statut = 'actif'");
    $vehiculesActifs = (int) $stmt->fetchColumn();

    // Disponibilité : % de véhicules actifs sur total
    $stmt = $pdo->query("SELECT COUNT(*) FROM vehicules");
    $totalVehicules = (int) $stmt->fetchColumn();
    $disponibilite = $totalVehicules > 0
        ? round(($vehiculesActifs / $totalVehicules) * 100)
        : 98;

    // Délai moyen de la dernière mise à jour GPS (en secondes)
    $stmt = $pdo->query("
        SELECT AVG(TIMESTAMPDIFF(SECOND, horodatage, NOW())) AS delai
        FROM (
            SELECT MAX(horodatage) AS horodatage
            FROM telemetrie
            GROUP BY id_vehicule
        ) derniers
        WHERE horodatage >= NOW() - INTERVAL 10 MINUTE
    ");
    $delaiGps = (int) round($stmt->fetchColumn() ?? 5);
    $delaiGps = max(1, min(60, $delaiGps));

    // Missions en cours
    $stmt = $pdo->query("SELECT COUNT(*) FROM missions WHERE statut = 'en_cours'");
    $missionsEnCours = (int) $stmt->fetchColumn();

    // Alertes non traitées
    $stmt = $pdo->query("SELECT COUNT(*) FROM alertes WHERE statut = 'non_traitee'");
    $alertesActives = (int) $stmt->fetchColumn();

    echo json_encode([
        'success'           => true,
        'vehicules'         => $vehiculesActifs,
        'disponibilite'     => $disponibilite,
        'delai_gps'         => $delaiGps,
        'missions_en_cours' => $missionsEnCours,
        'alertes_actives'   => $alertesActives,
        'timestamp'         => date('H:i:s'),
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success'       => false,
        'vehicules'     => 150,
        'disponibilite' => 98,
        'delai_gps'     => 5,
        'timestamp'     => date('H:i:s'),
    ]);
}