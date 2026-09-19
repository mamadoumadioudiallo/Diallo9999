<?php
// ============================================================
// FleetIoT — Projet Simandou 2040
// Fichier : includes/functions.php
// Rôle    : Fonctions utilitaires partagées
// ============================================================

// ====== UNITÉ LISIBLE POUR LA VALEUR D'UNE ALERTE (selon son type) ======
function formaterValeurAlerte(string $typeAlerte, $valeur): string {
    if ($valeur === null) return '—';
    switch ($typeAlerte) {
        case 'vitesse_excessive':
            return round($valeur) . ' km/h';
        case 'temperature_critique':
            return round($valeur, 1) . ' °C';
        case 'carburant_bas':
            return round($valeur) . ' %';
        case 'hors_zone':
            return round($valeur, 1) . ' km du corridor';
        case 'surcharge':
            return number_format($valeur, 0, ',', ' ') . ' kg';
        case 'tpms_pression':
            return round($valeur, 2) . ' bar';
        case 'tpms_temperature':
            return round($valeur, 1) . ' °C (pneu)';
        default:
            return (string) $valeur;
    }
}

// Nettoyer les entrées utilisateur
function clean($data) {
    return htmlspecialchars(strip_tags(trim($data)));
}

// Formater une date en français
function formatDate($date) {
    if (!$date) return '—';
    return date('d/m/Y H:i', strtotime($date));
}

// Formater un niveau de carburant avec couleur
function fuelColor($level) {
    if ($level <= 10) return 'danger';
    if ($level <= 25) return 'warning';
    return 'success';
}

// Formater la température avec couleur
function tempColor($temp) {
    if ($temp >= 95) return 'danger';
    if ($temp >= 85) return 'warning';
    return 'success';
}

// Formater la vitesse avec couleur selon le type
function speedColor($speed, $type) {
    $seuil = ($type === 'minier') ? 80 : 120;
    if ($speed > $seuil) return 'danger';
    if ($speed > $seuil * 0.85) return 'warning';
    return 'success';
}

// Calculer la distance entre deux points GPS (formule Haversine)
function haversineDistance($lat1, $lng1, $lat2, $lng2) {
    $R = 6371; // Rayon Terre en km
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat/2) * sin($dLat/2) +
         cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
         sin($dLng/2) * sin($dLng/2);
    $c = 2 * atan2(sqrt($a), sqrt(1-$a));
    return $R * $c;
}

// ====== CORRIDOR MINIER SIMANDOU -> CONAKRY ======
// Tracé de référence utilisé pour le géofencing (simulateur ET boîtier IoT réel)
function getCorridorMinier() {
    return [
        ['lat' => 8.45, 'lng' => -9.05],   // Zone minière Simandou
        ['lat' => 8.90, 'lng' => -9.60],   // Le long de la route
        ['lat' => 9.30, 'lng' => -10.20],  // Continuation
        ['lat' => 9.90, 'lng' => -10.80],  // Vers Kindia
        ['lat' => 9.70, 'lng' => -13.20],  // Approche Conakry
    ];
}

// ====== GÉOFENCING ======
// Distance minimale (en km) entre une position GPS et le corridor minier
// (tracé défini par une liste de points ['lat'=>.., 'lng'=>..]).
// Approche : on échantillonne chaque segment du corridor en plusieurs points
// intermédiaires, puis on prend la distance haversine la plus courte trouvée.
// Suffisant pour un seuil de quelques km, sans nécessiter de géométrie complexe.
function distanceToCorridor($lat, $lng, $corridor, $echantillonsParSegment = 25) {
    $distanceMin = INF;

    for ($i = 0; $i < count($corridor) - 1; $i++) {
        $p1 = $corridor[$i];
        $p2 = $corridor[$i + 1];

        for ($t = 0; $t <= $echantillonsParSegment; $t++) {
            $ratio = $t / $echantillonsParSegment;
            $latPoint = $p1['lat'] + ($p2['lat'] - $p1['lat']) * $ratio;
            $lngPoint = $p1['lng'] + ($p2['lng'] - $p1['lng']) * $ratio;

            $d = haversineDistance($lat, $lng, $latPoint, $lngPoint);
            if ($d < $distanceMin) {
                $distanceMin = $d;
            }
        }
    }

    return $distanceMin;
}

// ====== ÉVALUATION DES SEUILS D'ALERTE (partagée simulateur + boîtier IoT réel) ======
// Reçoit un véhicule, ses valeurs télémétriques actuelles, la config des seuils,
// et le corridor minier. Insère les alertes nécessaires (avec déduplication 5 min)
// et retourne la liste des alertes effectivement déclenchées (pour log/notif).
function evaluerAlertesVehicule(PDO $pdo, array $vehicule, float $vitesse, float $carburant, float $temperature, float $lat, float $lng, array $config, array $corridor, ?int $poidsChargeKg = null, ?array $tpmsPressions = null) {
    $idVehicule = $vehicule['id_vehicule'];
    $type = $vehicule['type'];

    $seuilVitesse = ($type === 'minier')
        ? (float) ($config['seuil_vitesse_minier'] ?? 80)
        : (float) ($config['seuil_vitesse_routier'] ?? 120);
    $seuilTemperature = (float) ($config['seuil_temperature'] ?? 95);
    $seuilCarburant = (float) ($config['seuil_carburant'] ?? 10);
    $seuilCorridor = (float) ($config['seuil_corridor_km'] ?? 15);
    $seuilPressionMin = (float) ($config['seuil_pression_tpms_min'] ?? 6.5);
    $seuilPressionMax = (float) ($config['seuil_pression_tpms_max'] ?? 9.0);
    $seuilTempPneu = (float) ($config['seuil_temperature_pneu'] ?? 70);
    $seuilSurchargePourcent = (float) ($config['seuil_surcharge_pourcent'] ?? 10);

    $alertesADeclencher = [];

    if ($vitesse > $seuilVitesse) {
        $alertesADeclencher[] = ['type' => 'vitesse_excessive', 'valeur' => $vitesse, 'seuil' => $seuilVitesse];
    }
    if ($temperature > $seuilTemperature) {
        $alertesADeclencher[] = ['type' => 'temperature_critique', 'valeur' => $temperature, 'seuil' => $seuilTemperature];
    }
    if ($carburant < $seuilCarburant) {
        $alertesADeclencher[] = ['type' => 'carburant_bas', 'valeur' => $carburant, 'seuil' => $seuilCarburant];
    }

    $distanceCorridor = distanceToCorridor($lat, $lng, $corridor);
    if ($distanceCorridor > $seuilCorridor) {
        $alertesADeclencher[] = ['type' => 'hors_zone', 'valeur' => $distanceCorridor, 'seuil' => $seuilCorridor];
    }

    // ====== SURCHARGE : poids transporté au-delà du poids_max_kg + tolérance ======
    if ($poidsChargeKg !== null && !empty($vehicule['poids_max_kg'])) {
        $poidsMax = (float) $vehicule['poids_max_kg'];
        $seuilSurcharge = $poidsMax * (1 + $seuilSurchargePourcent / 100);
        if ($poidsChargeKg > $seuilSurcharge) {
            $alertesADeclencher[] = ['type' => 'surcharge', 'valeur' => $poidsChargeKg, 'seuil' => round($seuilSurcharge)];
        }
    }

    // ====== TPMS : pression et température — miniers uniquement ======
    // Les véhicules routiers (voitures, camions légers) ont des pressions
    // normales de 2.0-3.5 bar, incompatibles avec les seuils miniers (6.5-9.0 bar).
    if ($tpmsPressions !== null && $type === 'minier') {
        $pressionHorsSeuil = null;
        $tempHorsSeuil = null;

        foreach ($tpmsPressions as $roue) {
            if ($roue['pression'] < $seuilPressionMin || $roue['pression'] > $seuilPressionMax) {
                if ($pressionHorsSeuil === null || abs($roue['pression'] - ($seuilPressionMin + $seuilPressionMax) / 2) > abs($pressionHorsSeuil - ($seuilPressionMin + $seuilPressionMax) / 2)) {
                    $pressionHorsSeuil = $roue['pression'];
                }
            }
            if ($roue['temperature'] > $seuilTempPneu) {
                if ($tempHorsSeuil === null || $roue['temperature'] > $tempHorsSeuil) {
                    $tempHorsSeuil = $roue['temperature'];
                }
            }
        }

        if ($pressionHorsSeuil !== null) {
            $alertesADeclencher[] = ['type' => 'tpms_pression', 'valeur' => $pressionHorsSeuil, 'seuil' => $seuilPressionMin . '-' . $seuilPressionMax];
        }
        if ($tempHorsSeuil !== null) {
            $alertesADeclencher[] = ['type' => 'tpms_temperature', 'valeur' => $tempHorsSeuil, 'seuil' => $seuilTempPneu];
        }
    }

    $declenchees = [];

    foreach ($alertesADeclencher as $alerte) {
        // Déduplication : pas de nouvelle alerte si une du même type existe depuis < 5 min
        $checkStmt = $pdo->prepare("
            SELECT COUNT(*) FROM alertes
            WHERE id_vehicule = ? AND type_alerte = ?
            AND horodatage > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
        ");
        $checkStmt->execute([$idVehicule, $alerte['type']]);

        if ($checkStmt->fetchColumn() == 0) {
            $insertStmt = $pdo->prepare("
                INSERT INTO alertes (id_vehicule, type_alerte, valeur_declenchante, seuil_configure)
                VALUES (?, ?, ?, ?)
            ");
            $insertStmt->execute([$idVehicule, $alerte['type'], round((float) $alerte['valeur'], 1), is_numeric($alerte['seuil']) ? $alerte['seuil'] : null]);
            $declenchees[] = $alerte['type'];
        }
    }

    // ====== RÉSOLUTION AUTOMATIQUE ======
    // Si une condition est revenue à la normale, on résout automatiquement
    // les alertes actives correspondantes pour ce véhicule.
    // L'admin n'a pas à intervenir manuellement — c'est le capteur qui confirme.

    $typesActifsDeclenchees = array_column($alertesADeclencher, 'type');

    // Types à vérifier pour résolution automatique
    $typesAVerifier = [
        'vitesse_excessive',
        'temperature_critique',
        'carburant_bas',
        'hors_zone',
        'surcharge',
        'tpms_pression',
        'tpms_temperature',
    ];

    foreach ($typesAVerifier as $type) {
        // Si ce type est encore en anomalie dans ce cycle → on ne résout pas
        if (in_array($type, $typesActifsDeclenchees)) continue;

        // Si le type n'est pas concerné par les données disponibles → on skip
        // (ex: pas de données TPMS → on ne résout pas les alertes TPMS)
        if (in_array($type, ['tpms_pression', 'tpms_temperature']) && $tpmsPressions === null) continue;
        if ($type === 'surcharge' && $poidsChargeKg === null) continue;

        // Résoudre toutes les alertes actives de ce type pour ce véhicule
        $resolveStmt = $pdo->prepare("
            UPDATE alertes
            SET statut = 'resolue',
                date_traitement = NOW()
            WHERE id_vehicule = ?
            AND type_alerte = ?
            AND statut != 'resolue'
        ");
        $resolveStmt->execute([$idVehicule, $type]);
    }

    return $declenchees;
}

// ====== GÉNÉRATION TPMS RÉALISTE POUR UN VÉHICULE ======
// Simule la pression et température de chaque pneu, avec une faible probabilité
// d'anomalie (sous-gonflage, surchauffe) pour tester les alertes.
function genererTpms(int $nbRoues, ?array $etatPrecedent = null): array {
    $roues = [];
    for ($i = 1; $i <= $nbRoues; $i++) {
        $precedente = $etatPrecedent[$i - 1] ?? null;
        $pressionBase = $precedente['pression'] ?? (7.5 + (mt_rand(-50, 50) / 100));
        $tempBase = $precedente['temperature'] ?? (45 + mt_rand(-5, 5));

        // Variation légère autour de l'état précédent (réalisme)
        $pression = $pressionBase + (mt_rand(-10, 10) / 100);
        $temperature = $tempBase + mt_rand(-2, 3);

        // 3% de chance de simuler une anomalie nette (crevaison lente, surchauffe)
        if (mt_rand(1, 100) <= 3) {
            $pression = (mt_rand(0, 1) === 0) ? mt_rand(40, 55) / 10 : mt_rand(95, 110) / 10;
        }
        if (mt_rand(1, 100) <= 3) {
            $temperature = mt_rand(72, 85);
        }

        $roues[] = [
            'roue'        => $i,
            'pression'    => round(max(0, $pression), 2),
            'temperature' => round(max(20, $temperature), 1),
        ];
    }
    return $roues;
}

// ====== UPLOAD SÉCURISÉ DE FICHIER (photos, documents, certificats...) ======
// Réutilisée par admin/fiche_conducteur.php et superviseur/fiche_conducteur.php
function uploaderFichier($champFichier, $prefixe, $idReference, $extensionsAutorisees, $dossier, $tailleMax) {
    if (empty($_FILES[$champFichier]['name'])) {
        return ['success' => true, 'chemin' => null]; // rien à uploader, pas une erreur
    }

    $fichier = $_FILES[$champFichier];

    if ($fichier['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'erreur' => 'Erreur lors du transfert du fichier.'];
    }
    if ($fichier['size'] > $tailleMax) {
        return ['success' => false, 'erreur' => 'Fichier trop volumineux (max 5 Mo).'];
    }

    $extension = strtolower(pathinfo($fichier['name'], PATHINFO_EXTENSION));
    if (!in_array($extension, $extensionsAutorisees, true)) {
        return ['success' => false, 'erreur' => 'Format non autorisé (' . implode(', ', $extensionsAutorisees) . ' uniquement).'];
    }

    // Vérification du CONTENU réel du fichier, pas seulement son extension
    // déclarée (un script malveillant pourrait être nommé "photo.jpg")
    if (!verifierTypeMimeReel($fichier['tmp_name'], $extensionsAutorisees)) {
        return ['success' => false, 'erreur' => 'Le contenu du fichier ne correspond pas à un format autorisé.'];
    }

    // Nom de fichier non prévisible (sécurité), basé sur l'ID + un aléa
    $nomFichier = $prefixe . '_' . $idReference . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
    $cheminComplet = $dossier . $nomFichier;

    if (!move_uploaded_file($fichier['tmp_name'], $cheminComplet)) {
        return ['success' => false, 'erreur' => 'Impossible d\'enregistrer le fichier sur le serveur.'];
    }

    // Retourner le chemin relatif depuis la racine du site (sans le ../ initial)
    $cheminRelatif = ltrim(str_replace('../', '', $dossier), '/') . $nomFichier;
    return ['success' => true, 'chemin' => $cheminRelatif];
}

// ====== VALIDATION DE LA ROBUSTESSE D'UN MOT DE PASSE ======
// Retourne null si le mot de passe est valide, ou un message d'erreur sinon.
function validerMotDePasse($password) {
    if (strlen($password) < 8) {
        return 'Le mot de passe doit contenir au moins 8 caractères.';
    }
    if (!preg_match('/[A-Z]/', $password)) {
        return 'Le mot de passe doit contenir au moins une majuscule.';
    }
    if (!preg_match('/[a-z]/', $password)) {
        return 'Le mot de passe doit contenir au moins une minuscule.';
    }
    if (!preg_match('/[0-9]/', $password)) {
        return 'Le mot de passe doit contenir au moins un chiffre.';
    }
    return null; // valide
}

// ====== JOURNAL D'AUDIT ======
// Trace les actions sensibles (connexions, création de comptes, changements
// de mot de passe, génération de clés API...) pour pouvoir répondre à toute
// question de traçabilité d'un régulateur ou en cas d'incident de sécurité.
function enregistrerAudit(PDO $pdo, $action, $details = '', $idUser = null) {
    if ($idUser === null && isset($_SESSION['user_id'])) {
        $idUser = $_SESSION['user_id'];
    }
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'inconnue';

    try {
        $stmt = $pdo->prepare("
            INSERT INTO journal_audit (id_user, action, details, adresse_ip)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$idUser, $action, $details, $ip]);
    } catch (PDOException $e) {
        // Le journal d'audit ne doit jamais faire planter l'application
        // si la table n'existe pas encore ou en cas d'erreur ponctuelle.
    }
}

// ====== PROTECTION ANTI-BRUTE-FORCE PAR ADRESSE IP ======
// Complète la protection par session (qu'un attaquant peut contourner en
// effaçant ses cookies) par une vraie limite côté serveur, basée sur l'IP,
// stockée en base et donc impossible à réinitialiser côté client.
function enregistrerTentativeEchouee(PDO $pdo, $ip, $email) {
    try {
        $stmt = $pdo->prepare("INSERT INTO connexion_tentatives (adresse_ip, email) VALUES (?, ?)");
        $stmt->execute([$ip, $email]);
    } catch (PDOException $e) {
        // Ne doit jamais bloquer la connexion si la table est indisponible
    }
}

function ipEstBloquee(PDO $pdo, $ip, $maxTentatives = 10, $fenetreMinutes = 15) {
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM connexion_tentatives
            WHERE adresse_ip = ? AND horodatage > DATE_SUB(NOW(), INTERVAL ? MINUTE)
        ");
        $stmt->execute([$ip, $fenetreMinutes]);
        return $stmt->fetchColumn() >= $maxTentatives;
    } catch (PDOException $e) {
        return false; // en cas d'erreur, ne pas bloquer l'utilisateur légitime
    }
}

// ====== VALIDATION DU CONTENU RÉEL D'UN FICHIER (pas juste son extension) ======
// Une extension ".jpg" peut être collée sur n'importe quel fichier (y compris
// un script malveillant). On vérifie ici le VRAI type MIME détecté par le
// contenu binaire du fichier, pas le nom déclaré par le navigateur.
function verifierTypeMimeReel($cheminTemporaire, $extensionsAutorisees) {
    $mimesAutorises = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
        'png' => 'image/png', 'webp' => 'image/webp',
        'pdf' => 'application/pdf',
    ];

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeReel = finfo_file($finfo, $cheminTemporaire);
    finfo_close($finfo);

    foreach ($extensionsAutorisees as $ext) {
        if (isset($mimesAutorises[$ext]) && $mimesAutorises[$ext] === $mimeReel) {
            return true;
        }
    }
    return false;
}
// ====== LIMITATION DE DÉBIT SUR L'API D'INGESTION (anti-abus clé API) ======
// Si une clé API est compromise (boîtier volé, clé fuitée), elle ne doit pas
// pouvoir spammer le serveur de requêtes illimitées. On autorise un débit
// généreux (le boîtier peut envoyer un lot après une coupure réseau prolongée),
// mais pas un flot anormal et continu.
function enregistrerAppelApi(PDO $pdo, $idVehicule) {
    try {
        $stmt = $pdo->prepare("INSERT INTO api_appels (id_vehicule) VALUES (?)");
        $stmt->execute([$idVehicule]);
    } catch (PDOException $e) {
        // Ne doit jamais bloquer l'ingestion si la table est indisponible
    }
}

function limiteApiDepassee(PDO $pdo, $idVehicule, $maxAppels = 60, $fenetreSecondes = 60) {
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM api_appels
            WHERE id_vehicule = ? AND horodatage > DATE_SUB(NOW(), INTERVAL ? SECOND)
        ");
        $stmt->execute([$idVehicule, $fenetreSecondes]);
        return $stmt->fetchColumn() >= $maxAppels;
    } catch (PDOException $e) {
        return false; // ne pas bloquer un boîtier légitime en cas d'erreur technique
    }
}
// ====== CHIFFREMENT DES DONNÉES SENSIBLES AU REPOS ======
// Protège les champs les plus personnels (adresse, contact d'urgence) en cas
// d'accès direct non autorisé à la base de données. AES-256-CBC avec un IV
// aléatoire à chaque chiffrement (stocké avec la donnée, ce n'est pas un secret).
require_once __DIR__ . '/secrets.php';

function chiffrer($texteClair) {
    if (empty($texteClair)) {
        return null;
    }
    $iv = random_bytes(openssl_cipher_iv_length('aes-256-cbc'));
    $chiffre = openssl_encrypt($texteClair, 'aes-256-cbc', ENCRYPTION_KEY, 0, $iv);
    return base64_encode($iv . $chiffre);
}

function dechiffrer($texteChiffre) {
    if (empty($texteChiffre)) {
        return '';
    }
    $donnees = base64_decode($texteChiffre);
    $tailleIv = openssl_cipher_iv_length('aes-256-cbc');
    $iv = substr($donnees, 0, $tailleIv);
    $chiffre = substr($donnees, $tailleIv);
    $resultat = openssl_decrypt($chiffre, 'aes-256-cbc', ENCRYPTION_KEY, 0, $iv);
    return $resultat !== false ? $resultat : '';
}
// Retourner une réponse JSON
function jsonResponse($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit();
}

// Vérifier si une requête est AJAX
function isAjax() {
    return isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
           strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

// Paginer des résultats
function paginate($total, $perPage, $currentPage) {
    $totalPages = ceil($total / $perPage);
    $offset = ($currentPage - 1) * $perPage;
    return ['totalPages' => $totalPages, 'offset' => $offset];
}