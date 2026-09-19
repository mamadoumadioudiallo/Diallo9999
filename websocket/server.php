<?php
// ============================================================
// FleetIoT — Projet Simandou 2040
// Fichier : websocket/server.php
// Rôle    : Serveur WebSocket — pousse les positions des véhicules
//           en temps réel vers tous les dashboards connectés.
//
// Lancement : php websocket/server.php
// (à exécuter dans un terminal séparé, qui doit rester ouvert
//  pendant toute la durée d'utilisation du dashboard temps réel)
// ============================================================

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/includes/db.php';

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;

class FleetIotPush implements MessageComponentInterface {

    protected \SplObjectStorage $clients;
    protected PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->clients = new \SplObjectStorage();
        $this->pdo = $pdo;
    }

    // Un navigateur vient de se connecter au flux temps réel
    public function onOpen(ConnectionInterface $conn) {
        $this->clients->attach($conn);
        echo "[+] Client connecté (" . count($this->clients) . " au total)\n";
    }

    public function onClose(ConnectionInterface $conn) {
        $this->clients->detach($conn);
        echo "[-] Client déconnecté (" . count($this->clients) . " restants)\n";
    }

    public function onError(ConnectionInterface $conn, \Exception $e) {
        echo "[!] Erreur connexion : " . $e->getMessage() . "\n";
        $conn->close();
    }

    // On n'attend pas de messages entrants des navigateurs (flux à sens unique)
    public function onMessage(ConnectionInterface $from, $msg) {}

    // Interroge la base et envoie les dernières positions à tous les clients connectés
    public function diffuserTelemetrie() {
        if (count($this->clients) === 0) {
            return; // personne ne regarde, inutile de requêter la base
        }

        try {
            $stmt = $this->pdo->query("
                SELECT v.id_vehicule, v.immatriculation, v.type,
                       t.latitude, t.longitude, t.vitesse, t.carburant, t.temperature_moteur, t.horodatage
                FROM vehicules v
                LEFT JOIN (
                    SELECT t1.* FROM telemetrie t1
                    INNER JOIN (
                        SELECT id_vehicule, MAX(horodatage) AS max_horo
                        FROM telemetrie GROUP BY id_vehicule
                    ) t2 ON t1.id_vehicule = t2.id_vehicule AND t1.horodatage = t2.max_horo
                ) t ON v.id_vehicule = t.id_vehicule
                WHERE v.statut = 'actif'
            ");
            $vehicules = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $payload = json_encode(['type' => 'telemetrie', 'vehicules' => $vehicules]);

            foreach ($this->clients as $client) {
                $client->send($payload);
            }
        } catch (\PDOException $e) {
            // Connexion MySQL perdue (ex: serveur redémarré) : on tente une reconnexion simple
            echo "[!] Erreur base de données : " . $e->getMessage() . "\n";
            try {
                $this->pdo = new PDO(
                    "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET,
                    DB_USER, DB_PASS,
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
                );
                echo "[+] Reconnexion MySQL réussie.\n";
            } catch (\PDOException $e2) {
                echo "[!] Reconnexion impossible : " . $e2->getMessage() . "\n";
            }
        }
    }
}

// ====== DÉMARRAGE DU SERVEUR ======
$push = new FleetIotPush($pdo);

$server = IoServer::factory(
    new HttpServer(new WsServer($push)),
    8080 // port d'écoute du serveur WebSocket
);

// Diffusion automatique toutes les 2 secondes
$server->loop->addPeriodicTimer(2, function () use ($push) {
    $push->diffuserTelemetrie();
});

echo "============================================\n";
echo " FleetIoT - Serveur WebSocket démarré\n";
echo " Écoute sur ws://localhost:8080\n";
echo " Laisser cette fenêtre ouverte.\n";
echo "============================================\n";

$server->run();