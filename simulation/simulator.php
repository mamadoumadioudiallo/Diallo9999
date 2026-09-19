<?php
// ============================================================
// FleetIoT — Projet Simandou 2040
// Fichier : simulation/simulator.php
// Rôle    : Génère des données de télémétrie réalistes pour
//           chaque véhicule actif et déclenche les alertes
//           (positions GPS, carburant, température, moteur,
//           poids de charge, TPMS)
// ============================================================

require_once '../includes/db.php';
require_once '../includes/functions.php';

function runSimulationCycle(PDO $pdo) {

    // ====== POINTS DE DÉPART (zone Simandou / route minière) ======
    $pointsDepart = [
        ['lat' => 8.45, 'lng' => -9.05],   // Zone minière Simandou
        ['lat' => 8.90, 'lng' => -9.60],   // Le long de la route
        ['lat' => 9.30, 'lng' => -10.20],  // Continuation
        ['lat' => 9.90, 'lng' => -10.80],  // Vers Kindia
        ['lat' => 9.70, 'lng' => -13.20],  // Approche Conakry
    ];

    // Récupérer les seuils configurés
    $configStmt = $pdo->query("SELECT parametre, valeur FROM configurations");
    $config = [];
    foreach ($configStmt->fetchAll() as $row) {
        $config[$row['parametre']] = $row['valeur'];
    }

    $intervalle = (int) ($config['intervalle_simulation'] ?? 5);

    // ====== RÉCUPÉRER LES VÉHICULES ACTIFS ======
    $vehicules = $pdo->query("SELECT * FROM vehicules WHERE statut = 'actif'")->fetchAll();

    $log = [];

    foreach ($vehicules as $vehicule) {
        $idVehicule = $vehicule['id_vehicule'];
        $type = $vehicule['type'];
        $nbRoues = (int) ($vehicule['nb_roues'] ?? 6);
        $poidsMaxKg = $vehicule['poids_max_kg'] ?? null;

        // ====== RÉCUPÉRER LE DERNIER ÉTAT TÉLÉMÉTRIQUE ======
        $stmt = $pdo->prepare("SELECT * FROM telemetrie WHERE id_vehicule = ? ORDER BY horodatage DESC LIMIT 1");
        $stmt->execute([$idVehicule]);
        $dernierEtat = $stmt->fetch();

        $tpmsPrecedent = null;
        if ($dernierEtat) {
            $lat = (float) $dernierEtat['latitude'];
            $lng = (float) $dernierEtat['longitude'];
            $vitesse = (float) $dernierEtat['vitesse'];
            $carburant = (float) $dernierEtat['carburant'];
            $temperature = (float) $dernierEtat['temperature_moteur'];
            $km = (float) $dernierEtat['kilometrage'];
            $moteurEtatPrecedent = $dernierEtat['moteur_etat'] ?? 'off';
            $poidsChargePrecedent = $dernierEtat['poids_charge_kg'];
            $cap = mt_rand(0, 359);
            if (!empty($dernierEtat['tpms_pression_json'])) {
                $tpmsPrecedent = json_decode($dernierEtat['tpms_pression_json'], true);
            }
        } else {
            // Initialisation : position de départ aléatoire sur le corridor
            $depart = $pointsDepart[array_rand($pointsDepart)];
            $lat = $depart['lat'];
            $lng = $depart['lng'];
            $vitesse = 0;
            $carburant = 100;
            $temperature = 75;
            $km = 0;
            $moteurEtatPrecedent = 'off';
            $poidsChargePrecedent = null;
            $cap = mt_rand(0, 359);
        }

        // ====== CALCUL GPS (navigation sphérique) ======
        $cap += mt_rand(-10, 10);
        $vitesseRef = ($type === 'minier') ? 60 : 90;

        // Petite probabilité d'arrêt (zone de chargement / contrôle)
        $arret = (mt_rand(1, 100) <= 8);

        if ($arret) {
            $nouvelleVitesse = max(0, $vitesse - mt_rand(10, 25));
        } else {
            $bruit = mt_rand(-15, 15);
            $nouvelleVitesse = max(0, min($vitesseRef + 25, $vitesseRef + $bruit));
        }

        $distanceKm = ($nouvelleVitesse / 3600) * $intervalle;

        $dLat = ($distanceKm / 111.32) * cos(deg2rad($cap));
        $dLng = ($distanceKm / (111.32 * cos(deg2rad($lat)))) * sin(deg2rad($cap));

        $nouvelleLat = $lat + $dLat;
        $nouvelleLng = $lng + $dLng;

        // ====== CALCUL CARBURANT ======
        $coefConso = ($type === 'minier') ? 0.35 : 0.12;
        $nouveauCarburant = max(0, $carburant - ($distanceKm * $coefConso));

        if ($nouveauCarburant < 3 && mt_rand(1, 100) <= 30) {
            $nouveauCarburant = 100;
        }

        // ====== CALCUL TEMPÉRATURE MOTEUR ======
        if ($nouvelleVitesse > 0) {
            $nouvelleTemperature = $temperature + ($nouvelleVitesse / 100) * 0.5 + mt_rand(-1, 2);
        } else {
            $nouvelleTemperature = max(70, $temperature - 1.5);
        }
        $nouvelleTemperature = min(110, max(70, $nouvelleTemperature));

        $nouveauKm = $km + $distanceKm;

        // ====== ÉTAT MOTEUR (ON/OFF) ======
        // Le moteur est "on" dès que le véhicule roule. À l'arrêt, il reste
        // "on" la plupart du temps (ralenti / chargement) avec une petite
        // chance de coupure (pause conducteur, fin de service).
        if ($nouvelleVitesse > 0) {
            $nouveauMoteurEtat = 'on';
        } else {
            $nouveauMoteurEtat = (mt_rand(1, 100) <= 25) ? 'off' : $moteurEtatPrecedent;
        }

        // ====== POIDS DE CHARGEMENT ======
        // Simule un chargement/déchargement aux arrêts (zone minière = chargement de minerai).
        // En roulant, la charge reste stable (transport en cours).
        if ($poidsMaxKg) {
            if ($arret && mt_rand(1, 100) <= 20) {
                // Chargement ou déchargement complet/partiel à l'arrêt
                $nouveauPoidsCharge = (mt_rand(1, 100) <= 50)
                    ? mt_rand((int) ($poidsMaxKg * 0.85), (int) ($poidsMaxKg * 1.08)) // charge (parfois légère surcharge)
                    : 0; // déchargement
            } else {
                $nouveauPoidsCharge = $poidsChargePrecedent ?? 0;
            }
        } else {
            $nouveauPoidsCharge = null;
        }

        // ====== TPMS — miniers uniquement ======
        // Les routiers (voitures, camions légers) n'ont pas de capteur TPMS
        // avec les seuils miniers (6.5-9.0 bar). On ne simule pas pour eux.
        $tpmsRoues = null;
        $tpmsAlerte = 0;
        $tpmsJson = null;

        if ($type === 'minier') {
            $tpmsRoues = genererTpms($nbRoues, $tpmsPrecedent);
            foreach ($tpmsRoues as $roue) {
                $pressionMin = (float) ($config['seuil_pression_tpms_min'] ?? 6.5);
                $pressionMax = (float) ($config['seuil_pression_tpms_max'] ?? 9.0);
                $tempMax = (float) ($config['seuil_temperature_pneu'] ?? 70);
                if ($roue['pression'] < $pressionMin || $roue['pression'] > $pressionMax || $roue['temperature'] > $tempMax) {
                    $tpmsAlerte = 1;
                    break;
                }
            }
            $tpmsJson = json_encode($tpmsRoues);
        }

        // ====== INSERTION TÉLÉMÉTRIE ======
        $stmt = $pdo->prepare("
            INSERT INTO telemetrie
                (id_vehicule, latitude, longitude, vitesse, carburant, temperature_moteur,
                 moteur_etat, poids_charge_kg, tpms_pression_json, tpms_alerte, kilometrage)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $idVehicule, $nouvelleLat, $nouvelleLng, $nouvelleVitesse, $nouveauCarburant, $nouvelleTemperature,
            $nouveauMoteurEtat, $nouveauPoidsCharge, $tpmsJson, $tpmsAlerte, $nouveauKm,
        ]);

        // ====== ÉVALUATION DES ALERTES (logique centralisée, partagée avec le futur boîtier IoT) ======
        $declenchees = evaluerAlertesVehicule(
            $pdo, $vehicule, $nouvelleVitesse, $nouveauCarburant, $nouvelleTemperature,
            $nouvelleLat, $nouvelleLng, $config, $pointsDepart,
            $nouveauPoidsCharge, $tpmsRoues
        );

        foreach ($declenchees as $typeAlerte) {
            $log[] = $vehicule['immatriculation'] . ' : alerte ' . $typeAlerte . ' déclenchée';
        }

        $log[] = $vehicule['immatriculation'] . ' mis à jour : ' . round($nouvelleVitesse) . ' km/h, '
            . round($nouveauCarburant) . '% carburant, ' . round($nouvelleTemperature) . '°C, moteur '
            . strtoupper($nouveauMoteurEtat) . ($nouveauPoidsCharge !== null ? ', charge ' . round($nouveauPoidsCharge) . ' kg' : '');
    }

    return $log;
}

// ====== EXÉCUTION DIRECTE (appel CLI ou navigateur) ======
if (php_sapi_name() === 'cli' || isset($_GET['run'])) {
    $resultat = runSimulationCycle($pdo);

    if (isset($_GET['run'])) {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'ok', 'log' => $resultat, 'timestamp' => date('Y-m-d H:i:s')]);
    } else {
        echo implode("\n", $resultat) . "\n";
    }
}
