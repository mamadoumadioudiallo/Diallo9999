<?php
// ============================================================
// FleetIoT — Projet Simandou 2040
// Fichier : rapports/export_csv.php
// Rôle    : Export CSV des rapports (consommation, kilométrage, alertes)
// ============================================================

session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireSuperviseur();

$idSup = $_SESSION['user_id'];
$estAdmin = isAdmin();

$type = $_GET['type'] ?? 'conso';
$dateDebut = $_GET['date_debut'] ?? date('Y-m-d', strtotime('-7 days'));
$dateFin = $_GET['date_fin'] ?? date('Y-m-d');

// ====== PRÉPARATION DU FICHIER CSV ======
$nomFichier = 'fleetiot_rapport_' . $type . '_' . date('Y-m-d') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $nomFichier . '"');

$output = fopen('php://output', 'w');
// BOM UTF-8 pour un affichage correct des accents dans Excel
fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

if ($type === 'conso') {

    $coefConso = ['minier' => 0.35, 'routier' => 0.12];

    $sqlVeh = "SELECT id_vehicule, immatriculation, marque, modele, type, capacite_carburant FROM vehicules";
    $paramsVeh = [];
    if (!$estAdmin) {
        $sqlVeh .= " WHERE id_superviseur = ?";
        $paramsVeh[] = $idSup;
    }
    $sqlVeh .= " ORDER BY immatriculation";
    $stmt = $pdo->prepare($sqlVeh);
    $stmt->execute($paramsVeh);
    $vehicules = $stmt->fetchAll();

    fputcsv($output, ['Immatriculation', 'Marque', 'Modele', 'Type', 'Distance (km)', 'Carburant estime (L)', 'Conso moyenne (L/100km)']);

    foreach ($vehicules as $v) {
        $stmt = $pdo->prepare("
            SELECT MIN(kilometrage) AS km_min, MAX(kilometrage) AS km_max
            FROM telemetrie WHERE id_vehicule = ? AND horodatage BETWEEN ? AND ?
        ");
        $stmt->execute([$v['id_vehicule'], $dateDebut . ' 00:00:00', $dateFin . ' 23:59:59']);
        $res = $stmt->fetch();

        $distance = ($res['km_min'] !== null) ? max(0, $res['km_max'] - $res['km_min']) : 0;
        $coef = $coefConso[$v['type']] ?? 0.15;
        $litres = $distance * ($coef / 100) * $v['capacite_carburant'];
        $consoMoyenne = $distance > 0 ? ($litres / $distance) * 100 : 0;

        fputcsv($output, [
            $v['immatriculation'], $v['marque'], $v['modele'], $v['type'],
            round($distance, 1), round($litres, 1), round($consoMoyenne, 1),
        ]);
    }

} elseif ($type === 'km') {

    $sqlVeh = "SELECT id_vehicule, immatriculation, marque, modele, type FROM vehicules";
    $paramsVeh = [];
    if (!$estAdmin) {
        $sqlVeh .= " WHERE id_superviseur = ?";
        $paramsVeh[] = $idSup;
    }
    $sqlVeh .= " ORDER BY immatriculation";
    $stmt = $pdo->prepare($sqlVeh);
    $stmt->execute($paramsVeh);
    $vehicules = $stmt->fetchAll();

    fputcsv($output, ['Immatriculation', 'Marque', 'Modele', 'Type', 'Distance parcourue (km)', 'Releves telemetrie']);

    foreach ($vehicules as $v) {
        $stmt = $pdo->prepare("
            SELECT MIN(kilometrage) AS km_min, MAX(kilometrage) AS km_max, COUNT(*) AS nb_releves
            FROM telemetrie WHERE id_vehicule = ? AND horodatage BETWEEN ? AND ?
        ");
        $stmt->execute([$v['id_vehicule'], $dateDebut . ' 00:00:00', $dateFin . ' 23:59:59']);
        $res = $stmt->fetch();

        $distance = ($res['km_min'] !== null) ? max(0, $res['km_max'] - $res['km_min']) : 0;

        fputcsv($output, [
            $v['immatriculation'], $v['marque'], $v['modele'], $v['type'],
            round($distance, 1), (int) $res['nb_releves'],
        ]);
    }

} elseif ($type === 'alertes') {

    $filtreType = $_GET['type_alerte'] ?? '';

    $sql = "
        SELECT a.type_alerte, a.valeur_declenchante, a.seuil_configure, a.statut, a.horodatage,
               v.immatriculation, v.type AS type_vehicule,
               u.nom AS traitant_nom, u.prenom AS traitant_prenom, u.role AS traitant_role
        FROM alertes a
        JOIN vehicules v ON a.id_vehicule = v.id_vehicule
        LEFT JOIN utilisateurs u ON a.id_traitant = u.id_user
        WHERE a.horodatage BETWEEN ? AND ?
    ";
    $params = [$dateDebut . ' 00:00:00', $dateFin . ' 23:59:59'];

    if (!$estAdmin) {
        $sql .= " AND v.id_superviseur = ?";
        $params[] = $idSup;
    }
    if ($filtreType) {
        $sql .= " AND a.type_alerte = ?";
        $params[] = $filtreType;
    }
    $sql .= " ORDER BY a.horodatage DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $alertes = $stmt->fetchAll();

    fputcsv($output, ['Immatriculation', 'Type vehicule', 'Type alerte', 'Valeur declenchante', 'Seuil configure', 'Date', 'Statut', 'Traite par']);

    foreach ($alertes as $a) {
        $traitant = $a['traitant_nom']
            ? $a['traitant_prenom'] . ' ' . $a['traitant_nom'] . ' (' . ($a['traitant_role'] === 'admin' ? 'Admin' : 'Superviseur') . ')'
            : '-';

        fputcsv($output, [
            $a['immatriculation'], $a['type_vehicule'], $a['type_alerte'],
            $a['valeur_declenchante'], $a['seuil_configure'],
            date('d/m/Y H:i', strtotime($a['horodatage'])), $a['statut'],
            $traitant,
        ]);
    }

} else {
    fputcsv($output, ['Erreur : type de rapport inconnu.']);
}

fclose($output);
exit();