<?php
// ============================================================
// FleetIoT — Projet Simandou 2040
// Fichier : api/stats.php
// Rôle    : Retourne en JSON des statistiques agrégées (KPI +
//           évolution sur N jours) — pour graphiques (charts.js)
//           admin voit tout, superviseur ne voit que ses véhicules
// ============================================================

session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireLogin();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

$estSuperviseurSeul = isSuperviseur() && !isAdmin();
$idSup = $_SESSION['user_id'];

// Nombre de jours d'historique demandés (7 par défaut, max 90)
$nbJours = isset($_GET['jours']) ? (int) $_GET['jours'] : 7;
$nbJours = max(1, min(90, $nbJours));

// ====== KPI INSTANTANÉS ======
$sqlVehActifs = "SELECT COUNT(*) FROM vehicules WHERE statut = 'actif'";
$sqlAlertesNT = "
    SELECT COUNT(*) FROM alertes a
    JOIN vehicules v ON a.id_vehicule = v.id_vehicule
    WHERE a.statut = 'non_traitee'
";

if ($estSuperviseurSeul) {
    $sqlVehActifs .= " AND id_superviseur = ?";
    $sqlAlertesNT .= " AND v.id_superviseur = ?";
}

$stmt = $pdo->prepare($sqlVehActifs);
$stmt->execute($estSuperviseurSeul ? [$idSup] : []);
$vehiculesActifs = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare($sqlAlertesNT);
$stmt->execute($estSuperviseurSeul ? [$idSup] : []);
$alertesNonTraitees = (int) $stmt->fetchColumn();

// ====== ÉVOLUTION SUR N JOURS — KM PARCOURUS PAR JOUR ======
// kilometrage est un compteur cumulatif par véhicule : la distance
// d'un jour = MAX(kilometrage) - MIN(kilometrage) ce jour-là, sommé sur tous les véhicules.
$sqlKm = "
    SELECT jour, SUM(km_max - km_min) AS km_brut
    FROM (
        SELECT t.id_vehicule, DATE(t.horodatage) AS jour,
               MAX(t.kilometrage) AS km_max, MIN(t.kilometrage) AS km_min
        FROM telemetrie t
        JOIN vehicules v ON t.id_vehicule = v.id_vehicule
        WHERE t.horodatage >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
        " . ($estSuperviseurSeul ? "AND v.id_superviseur = ?" : "") . "
        GROUP BY t.id_vehicule, DATE(t.horodatage)
    ) par_vehicule_jour
    GROUP BY jour ORDER BY jour
";

$paramsKm = $estSuperviseurSeul ? [$nbJours, $idSup] : [$nbJours];
$stmt = $pdo->prepare($sqlKm);
$stmt->execute($paramsKm);
$kmParJourRaw = $stmt->fetchAll(PDO::FETCH_KEY_PAIR); // jour => km_brut

// ====== ÉVOLUTION SUR N JOURS — NOMBRE D'ALERTES PAR JOUR ======
$sqlAlertesJour = "
    SELECT DATE(a.horodatage) AS jour, COUNT(*) AS nb
    FROM alertes a
    JOIN vehicules v ON a.id_vehicule = v.id_vehicule
    WHERE a.horodatage >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
    " . ($estSuperviseurSeul ? "AND v.id_superviseur = ?" : "") . "
    GROUP BY DATE(a.horodatage) ORDER BY jour
";

$paramsAlertes = $estSuperviseurSeul ? [$nbJours, $idSup] : [$nbJours];
$stmt = $pdo->prepare($sqlAlertesJour);
$stmt->execute($paramsAlertes);
$alertesParJourRaw = $stmt->fetchAll(PDO::FETCH_KEY_PAIR); // jour => nb

// ====== CONSTRUCTION D'UNE SÉRIE CONTINUE (jours sans données = 0, pas de trou) ======
$periode = [];
$kmParJour = [];
$alertesParJour = [];

for ($i = $nbJours - 1; $i >= 0; $i--) {
    $jour = date('Y-m-d', strtotime("-$i days"));
    $periode[] = $jour;
    $kmParJour[] = isset($kmParJourRaw[$jour]) ? round((float) $kmParJourRaw[$jour], 1) : 0;
    $alertesParJour[] = isset($alertesParJourRaw[$jour]) ? (int) $alertesParJourRaw[$jour] : 0;
}

// ====== RÉPARTITION DES ALERTES PAR TYPE (sur la période) ======
$sqlRepartition = "
    SELECT a.type_alerte, COUNT(*) AS nb
    FROM alertes a
    JOIN vehicules v ON a.id_vehicule = v.id_vehicule
    WHERE a.horodatage >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
    " . ($estSuperviseurSeul ? "AND v.id_superviseur = ?" : "") . "
    GROUP BY a.type_alerte
";

$stmt = $pdo->prepare($sqlRepartition);
$stmt->execute($paramsAlertes);
$repartitionTypes = $stmt->fetchAll(PDO::FETCH_KEY_PAIR); // type_alerte => nb (déjà des int via PDO)
foreach ($repartitionTypes as $k => $v) {
    $repartitionTypes[$k] = (int) $v;
}

// ====== TONNAGE ACTUEL (camions miniers actifs) ======
$stmtTonnage = $pdo->query("
    SELECT COALESCE(SUM(t.poids_charge_kg), 0)
    FROM vehicules v
    INNER JOIN (
        SELECT t1.* FROM telemetrie t1
        INNER JOIN (
            SELECT id_vehicule, MAX(horodatage) AS max_horo
            FROM telemetrie GROUP BY id_vehicule
        ) t2 ON t1.id_vehicule = t2.id_vehicule AND t1.horodatage = t2.max_horo
    ) t ON v.id_vehicule = t.id_vehicule
    WHERE v.statut = 'actif' AND v.type = 'minier' AND t.poids_charge_kg > 0
");
$tonnageKg = (int) $stmtTonnage->fetchColumn();
$tonnageTonnes = round($tonnageKg / 1000, 1);

jsonResponse([
    'kpi' => [
        'vehicules_actifs'     => $vehiculesActifs,
        'alertes_non_traitees' => $alertesNonTraitees,
    ],
    'evolution' => [
        'periode' => $periode,
        'km'      => $kmParJour,
        'alertes' => $alertesParJour,
    ],
    'repartition_alertes' => $repartitionTypes,
    'tonnage_actuel_t'    => $tonnageTonnes,
]);