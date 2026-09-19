<?php
// ============================================================
// FleetIoT — Projet Simandou 2040
// Fichier : api/ingest.php
// Rôle    : Point d'entrée RÉEL pour les boîtiers IoT embarqués
//           dans les camions. Authentification par clé API.
//
// Supporte 2 modes :
//
// MODE 1 — Lecture unique (connecté en temps réel) :
//   { "api_key": "...", "latitude": 8.452, "longitude": -9.041,
//     "vitesse": 42.5, "carburant": 78.0, "temperature_moteur": 89.0 }
//
// MODE 2 — Lot hors-ligne (le boîtier était sans réseau et envoie
//          tout son buffer local dès que la connexion revient) :
//   { "api_key": "...", "lectures": [
//       { "horodatage": "2026-06-28 09:00:00", "latitude": 8.40, "longitude": -9.02, "vitesse": 38 },
//       { "horodatage": "2026-06-28 09:01:00", "latitude": 8.41, "longitude": -9.03, "vitesse": 41 }
//   ] }
//
// Réponse JSON : {"success": true, "lectures_traitees": N, "alertes_declenchees": [...]}
// ============================================================

require_once '../includes/db.php';
require_once '../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'error' => 'Methode non autorisee, utilisez POST.'], 405);
}

$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$apiKey = $payload['api_key'] ?? '';
if (empty($apiKey)) {
    jsonResponse(['success' => false, 'error' => 'Cle API manquante.'], 400);
}

$stmt = $pdo->prepare("SELECT * FROM vehicules WHERE api_key = ? AND statut = 'actif'");
$stmt->execute([$apiKey]);
$vehicule = $stmt->fetch();

if (!$vehicule) {
    jsonResponse(['success' => false, 'error' => 'Cle API invalide ou vehicule inactif.'], 401);
}

$idVehicule = $vehicule['id_vehicule'];

if ($vehicule['api_key_expire'] && strtotime($vehicule['api_key_expire']) < time()) {
    jsonResponse(['success' => false, 'error' => 'Cle API expiree. Contactez un administrateur pour la renouveler.'], 401);
}

enregistrerAppelApi($pdo, $idVehicule);
if (limiteApiDepassee($pdo, $idVehicule)) {
    jsonResponse(['success' => false, 'error' => 'Limite de requetes depassee. Reessayez plus tard.'], 429);
}

if (isset($payload['lectures']) && is_array($payload['lectures'])) {
    $lectures = $payload['lectures'];
    usort($lectures, function ($a, $b) {
        return strtotime($a['horodatage'] ?? 'now') <=> strtotime($b['horodatage'] ?? 'now');
    });
} else {
    $lectures = [[
        'horodatage'       => date('Y-m-d H:i:s'),
        'latitude'         => $payload['latitude']         ?? null,
        'longitude'        => $payload['longitude']        ?? null,
        'vitesse'          => $payload['vitesse']          ?? 0,
        'carburant'        => $payload['carburant']        ?? null,
        'temperature_moteur' => $payload['temperature_moteur'] ?? null,
        'moteur_etat'      => $payload['moteur_etat']      ?? null,
        'poids_charge_kg'  => $payload['poids_charge_kg']  ?? null,
        'poids_total_kg'   => $payload['poids_total_kg']   ?? null,
        'tpms'             => $payload['tpms']             ?? null,
    ]];
}

if (empty($lectures)) {
    jsonResponse(['success' => false, 'error' => 'Aucune lecture a traiter.'], 400);
}

$stmt = $pdo->prepare("SELECT * FROM telemetrie WHERE id_vehicule = ? ORDER BY horodatage DESC LIMIT 1");
$stmt->execute([$idVehicule]);
$dernierEtat = $stmt->fetch();

$kmCourant            = $dernierEtat ? (float) $dernierEtat['kilometrage'] : 0;
$latPrecedente        = $dernierEtat ? (float) $dernierEtat['latitude'] : null;
$lngPrecedente        = $dernierEtat ? (float) $dernierEtat['longitude'] : null;
$carburantPrecedent   = $dernierEtat ? (float) $dernierEtat['carburant'] : 100;
$temperaturePrecedente = $dernierEtat ? (float) $dernierEtat['temperature_moteur'] : 75;
$moteurEtatPrecedent  = $dernierEtat['moteur_etat'] ?? 'off';
$poidsChargePrecedent = $dernierEtat['poids_charge_kg'] ?? null;
$tpmsPrecedent        = (!empty($dernierEtat['tpms_pression_json']))
    ? json_decode($dernierEtat['tpms_pression_json'], true)
    : null;

$configStmt = $pdo->query("SELECT parametre, valeur FROM configurations");
$config = [];
foreach ($configStmt->fetchAll() as $row) {
    $config[$row['parametre']] = $row['valeur'];
}
$corridor = getCorridorMinier();

$insertStmt = $pdo->prepare("
    INSERT INTO telemetrie
        (id_vehicule, latitude, longitude, vitesse, carburant, temperature_moteur,
         moteur_etat, poids_charge_kg, tpms_pression_json, tpms_alerte, kilometrage, horodatage)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
");

$toutesLesAlertes  = [];
$nbLecturesTraitees = 0;

foreach ($lectures as $lecture) {
    $lat = isset($lecture['latitude'])  ? (float) $lecture['latitude']  : null;
    $lng = isset($lecture['longitude']) ? (float) $lecture['longitude'] : null;

    if ($lat === null || $lng === null) continue;

    $horodatage  = $lecture['horodatage'] ?? date('Y-m-d H:i:s');
    $vitesse     = isset($lecture['vitesse'])           ? (float) $lecture['vitesse']           : 0;
    $carburant   = isset($lecture['carburant'])         ? (float) $lecture['carburant']         : $carburantPrecedent;
    $temperature = isset($lecture['temperature_moteur']) ? (float) $lecture['temperature_moteur'] : $temperaturePrecedente;

    // État moteur
    if (isset($lecture['moteur_etat']) && in_array($lecture['moteur_etat'], ['on', 'off'], true)) {
        $moteurEtat = $lecture['moteur_etat'];
    } else {
        $moteurEtat = ($vitesse > 0) ? 'on' : $moteurEtatPrecedent;
    }

    // Poids de chargement
    // Le boîtier envoie poids_total_kg → serveur calcule charge nette = total - tare
    // Ou envoie poids_charge_kg directement
    if (isset($lecture['poids_total_kg']) && $lecture['poids_total_kg'] !== null && !empty($vehicule['poids_tare_kg'])) {
        $poidsCharge = max(0, (int) $lecture['poids_total_kg'] - (int) $vehicule['poids_tare_kg']);
    } elseif (isset($lecture['poids_charge_kg']) && $lecture['poids_charge_kg'] !== null) {
        $poidsCharge = (int) $lecture['poids_charge_kg'];
    } else {
        $poidsCharge = $poidsChargePrecedent;
    }

    // TPMS
    $tpms = (isset($lecture['tpms']) && is_array($lecture['tpms']) && !empty($lecture['tpms']))
        ? $lecture['tpms']
        : $tpmsPrecedent;

    $tpmsAlerteFlag = 0;
    if ($tpms !== null) {
        $seuilPressionMin = (float) ($config['seuil_pression_tpms_min'] ?? 6.5);
        $seuilPressionMax = (float) ($config['seuil_pression_tpms_max'] ?? 9.0);
        $seuilTempPneu    = (float) ($config['seuil_temperature_pneu']  ?? 70);
        foreach ($tpms as $roue) {
            $p = $roue['pression']    ?? null;
            $t = $roue['temperature'] ?? null;
            if (($p !== null && ($p < $seuilPressionMin || $p > $seuilPressionMax)) || ($t !== null && $t > $seuilTempPneu)) {
                $tpmsAlerteFlag = 1;
                break;
            }
        }
    }

    if ($latPrecedente !== null) {
        $kmCourant += haversineDistance($latPrecedente, $lngPrecedente, $lat, $lng);
    }

    $insertStmt->execute([
        $idVehicule, $lat, $lng, $vitesse, $carburant, $temperature,
        $moteurEtat, $poidsCharge, $tpms !== null ? json_encode($tpms) : null, $tpmsAlerteFlag,
        $kmCourant, $horodatage,
    ]);

    $declenchees = evaluerAlertesVehicule(
        $pdo, $vehicule, $vitesse, $carburant, $temperature, $lat, $lng, $config, $corridor,
        $poidsCharge, $tpms
    );
    foreach ($declenchees as $typeAlerte) {
        $toutesLesAlertes[] = $typeAlerte;
    }

    $latPrecedente         = $lat;
    $lngPrecedente         = $lng;
    $carburantPrecedent    = $carburant;
    $temperaturePrecedente = $temperature;
    $moteurEtatPrecedent   = $moteurEtat;
    $poidsChargePrecedent  = $poidsCharge;
    $tpmsPrecedent         = $tpms;
    $nbLecturesTraitees++;
}

jsonResponse([
    'success'             => true,
    'vehicule'            => $vehicule['immatriculation'],
    'mode'                => count($lectures) > 1 ? 'batch_hors_ligne' : 'temps_reel',
    'lectures_traitees'   => $nbLecturesTraitees,
    'kilometrage_total'   => round($kmCourant, 2),
    'alertes_declenchees' => $toutesLesAlertes,
]);