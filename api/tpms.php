<?php
// ============================================================
// FleetIoT — Projet Simandou 2040
// Fichier : api/tpms.php
// Rôle    : API polling TPMS — retourne l'état temps réel
//           des pneus de tous les véhicules actifs
// ============================================================

session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireLogin();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

$configStmt = $pdo->query("SELECT parametre, valeur FROM configurations WHERE parametre IN ('seuil_pression_tpms_min','seuil_pression_tpms_max','seuil_temperature_pneu')");
$config = [];
foreach ($configStmt->fetchAll() as $row) {
    $config[$row['parametre']] = (float) $row['valeur'];
}
$seuilPMin = $config['seuil_pression_tpms_min'] ?? 6.5;
$seuilPMax = $config['seuil_pression_tpms_max'] ?? 9.0;
$seuilTMax = $config['seuil_temperature_pneu']  ?? 70;

// Filtre superviseur si applicable
$whereExtra = '';
$params = [];
if (isSuperviseur() && !isAdmin()) {
    $whereExtra = "AND v.id_superviseur = ?";
    $params[] = $_SESSION['user_id'];
}

$stmt = $pdo->prepare("
    SELECT v.id_vehicule, v.immatriculation, v.type, v.marque, v.modele,
           t.tpms_pression_json, t.tpms_alerte, t.horodatage,
           u.nom AS sup_nom, u.prenom AS sup_prenom
    FROM vehicules v
    INNER JOIN (
        SELECT t1.* FROM telemetrie t1
        INNER JOIN (
            SELECT id_vehicule, MAX(horodatage) AS max_horo
            FROM telemetrie GROUP BY id_vehicule
        ) t2 ON t1.id_vehicule = t2.id_vehicule AND t1.horodatage = t2.max_horo
    ) t ON v.id_vehicule = t.id_vehicule
    LEFT JOIN utilisateurs u ON v.id_superviseur = u.id_user
    WHERE v.statut = 'actif' AND t.tpms_pression_json IS NOT NULL
    $whereExtra
    ORDER BY t.tpms_alerte DESC, v.immatriculation ASC
");
$stmt->execute($params);
$vehicules = $stmt->fetchAll();

// Enrichir chaque véhicule avec l'état de chaque roue
$resultat = [];
$nbAlertes = 0;
foreach ($vehicules as $v) {
    $roues = json_decode($v['tpms_pression_json'], true) ?? [];
    $rouesEnrichies = [];
    $alerteVehicule = false;

    foreach ($roues as $roue) {
        $p  = (float) $roue['pression'];
        $t  = (float) ($roue['temperature'] ?? 0);
        $ok = $p >= $seuilPMin && $p <= $seuilPMax && $t <= $seuilTMax;
        if (!$ok) $alerteVehicule = true;
        $rouesEnrichies[] = [
            'roue'        => $roue['roue'],
            'pression'    => $p,
            'temperature' => $t,
            'ok'          => $ok,
        ];
    }

    if ($alerteVehicule) $nbAlertes++;

    $resultat[] = [
        'id_vehicule'    => $v['id_vehicule'],
        'immatriculation'=> $v['immatriculation'],
        'type'           => $v['type'],
        'marque'         => $v['marque'],
        'modele'         => $v['modele'],
        'sup_nom'        => $v['sup_nom'],
        'sup_prenom'     => $v['sup_prenom'],
        'tpms_alerte'    => $alerteVehicule,
        'roues'          => $rouesEnrichies,
        'horodatage'     => $v['horodatage'],
    ];
}

// Pression moyenne actuelle (pour la courbe)
$avgStmt = $pdo->prepare("
    SELECT AVG(JSON_EXTRACT(t.tpms_pression_json, '\$[*].pression')) AS pression_moy
    FROM telemetrie t
    JOIN vehicules v ON t.id_vehicule = v.id_vehicule
    WHERE v.statut = 'actif' AND t.tpms_pression_json IS NOT NULL
    AND t.horodatage >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)
    $whereExtra
");
$avgStmt->execute($params);
$pressionMoyActuelle = round((float) $avgStmt->fetchColumn(), 2);

jsonResponse([
    'vehicules'          => $resultat,
    'nb_alertes'         => $nbAlertes,
    'pression_moy'       => $pressionMoyActuelle,
    'seuil_pmin'         => $seuilPMin,
    'seuil_pmax'         => $seuilPMax,
    'seuil_tmax'         => $seuilTMax,
    'timestamp'          => date('H:i:s'),
]);
