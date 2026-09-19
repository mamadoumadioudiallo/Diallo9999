<?php
// ============================================================
// FleetIoT — Projet Simandou 2040
// Fichier : scripts/verifier_websocket.php
// Rôle    : Supervision du serveur WebSocket — vérifie qu'il
//           répond bien, et envoie une alerte email s'il est down.
//
// À planifier via cron toutes les 5-10 minutes en production :
//   */10 * * * * php /var/www/fleet_iot/scripts/verifier_websocket.php
// ============================================================

require dirname(__DIR__) . '/includes/mailer.php';

$hote = '127.0.0.1';   // en production : l'adresse du serveur WebSocket
$port = 8080;
$timeoutSecondes = 3;

// Fichier témoin : évite de spammer un email à chaque vérification ratée,
// on n'alerte qu'au premier échec détecté (pas à chaque cycle cron suivant)
$fichierEtat = __DIR__ . '/../backups/.websocket_etat';

$connexion = @fsockopen($hote, $port, $errno, $errstr, $timeoutSecondes);

if ($connexion) {
    // Le serveur répond : tout va bien
    fclose($connexion);

    // S'il était précédemment marqué "down", on envoie un email de "retour à la normale"
    if (file_exists($fichierEtat)) {
        $corpsEmail = "<h3>FleetIoT — Serveur WebSocket de nouveau opérationnel</h3>
            <p>Le service a été détecté comme fonctionnel à nouveau le " . date('d/m/Y à H:i:s') . ".</p>";
        envoyerEmail(EMAIL_DESTINATAIRE_CONTACT, '[FleetIoT] Serveur WebSocket rétabli', $corpsEmail);
        unlink($fichierEtat);
    }

    echo "[" . date('Y-m-d H:i:s') . "] OK — serveur WebSocket opérationnel.\n";

} else {
    // Le serveur ne répond pas : alerte uniquement si ce n'était pas déjà signalé
    if (!file_exists($fichierEtat)) {
        $corpsEmail = "<h3>&#9888; FleetIoT — Serveur WebSocket hors service</h3>
            <p>Le serveur WebSocket ne répond plus depuis le " . date('d/m/Y à H:i:s') . ".</p>
            <p>Erreur technique : $errstr (code $errno)</p>
            <p>Le dashboard basculera automatiquement en mode standard (rafraîchissement 5s) en attendant,
            mais une intervention est recommandée pour rétablir le temps réel.</p>";
        envoyerEmail(EMAIL_DESTINATAIRE_CONTACT, '[FleetIoT] ALERTE - Serveur WebSocket hors service', $corpsEmail);

        file_put_contents($fichierEtat, date('Y-m-d H:i:s'));
    }

    echo "[" . date('Y-m-d H:i:s') . "] ECHEC — serveur WebSocket inaccessible ($errstr).\n";
}