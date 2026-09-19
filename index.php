<?php
// ============================================================
// FleetIoT — Projet Simandou 2040
// Fichier : index.php
// Rôle    : Page d'accueil publique
// ============================================================

require_once 'includes/db.php';
require_once 'includes/functions.php';
require_once 'includes/auth.php';
require_once 'includes/mailer.php';

$success = '';
$errors = [];

// Traitement du formulaire de contact
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['contact_submit'])) {

    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Session expirée, veuillez recharger la page et réessayer.';
    } elseif (!empty($_POST['site_web'])) {
        // Piège à robots (champ invisible) : un humain ne le remplit jamais.
        // On affiche un faux succès pour ne pas indiquer au bot qu'il a été détecté.
        $success = 'Merci, votre message a bien été envoyé.';
    } else {
        // Anti-spam simple : 1 envoi toutes les 30 secondes maximum par session
        $derniereSoumission = $_SESSION['derniere_soumission_contact'] ?? 0;
        if (time() - $derniereSoumission < 30) {
            $errors[] = 'Veuillez attendre quelques instants avant de renvoyer un message.';
        } else {
            $nom = clean($_POST['nom'] ?? '');
            $email = clean($_POST['email'] ?? '');
            $sujet = clean($_POST['sujet'] ?? '');
            $message = clean($_POST['message'] ?? '');

            if (empty($nom)) {
                $errors[] = 'Le nom est obligatoire.';
            }
            if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Une adresse email valide est obligatoire.';
            }
            if (empty($message)) {
                $errors[] = 'Le message ne peut pas être vide.';
            }

            if (empty($errors)) {
                $sujetLabels = [
                    'information' => 'Demande d\'information',
                    'demo' => 'Demande de démonstration',
                ];
                $sujetEmail = '[FleetIoT Contact] ' . ($sujetLabels[$sujet] ?? 'Nouveau message');

                $corpsEmail = "
                    <h3>Nouveau message depuis le site FleetIoT</h3>
                    <p><strong>Nom :</strong> " . htmlspecialchars($nom) . "</p>
                    <p><strong>Email :</strong> " . htmlspecialchars($email) . "</p>
                    <p><strong>Objet :</strong> " . htmlspecialchars($sujetLabels[$sujet] ?? $sujet) . "</p>
                    <p><strong>Message :</strong></p>
                    <p>" . nl2br(htmlspecialchars($message)) . "</p>
                ";

                $resultatEnvoi = envoyerEmail(EMAIL_DESTINATAIRE_CONTACT, $sujetEmail, $corpsEmail, $email);

                if ($resultatEnvoi === true) {
                    $_SESSION['derniere_soumission_contact'] = time();
                    $success = 'Merci ' . htmlspecialchars($nom) . ', votre message a bien été envoyé. Nous vous répondrons rapidement.';
                } else {
                    $errors[] = 'L\'envoi du message a échoué. Veuillez réessayer ou nous contacter directement.';
                }
            }
        }
    }
}

$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FleetIoT — Gestion intelligente de flotte | Simandou 2040</title>
    <meta name="description" content="Système IoT de gestion intelligente de flotte pour le transport minier et routier du projet Simandou 2040, Guinée.">
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/responsive.css">
</head>
<body>

    <!-- ============ NAVBAR ============ -->
    <nav class="navbar">
        <a href="#" class="navbar-logo">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M3 16V8a1 1 0 011-1h10l4 4v5a1 1 0 01-1 1H4a1 1 0 01-1-1z"/>
                <circle cx="7" cy="17" r="1.5"/>
                <circle cx="16" cy="17" r="1.5"/>
            </svg>
            FleetIoT
        </a>
        <button class="navbar-toggle" id="navToggle" aria-label="Menu">&#9776;</button>
        <ul class="navbar-menu" id="navMenu">
            <li><a href="#accueil">Accueil</a></li>
            <li><a href="#fonctionnalites">Services</a></li>
            <li><a href="#apropos">À propos</a></li>
            <li><a href="#contact">Contact</a></li>
            <li><a href="connexion.php" class="btn-nav-login">Connexion</a></li>
        </ul>
    </nav>

    <!-- ============ HERO ============ -->
    <section class="hero" id="accueil">
        <span class="hero-badge">Système IoT de surveillance en temps réel</span>
        <h1>Gérez votre flotte de véhicules miniers et routiers</h1>
        <p>Surveillez, analysez et optimisez vos véhicules en temps réel grâce à notre plateforme IoT centralisée Conçue pour le projet Simandou 2040.</p>
        <div class="hero-actions">
            <a href="connexion.php" class="btn btn-primary">Accéder au tableau de bord</a>
            <a href="#contact" class="btn btn-outline">Nous contacter</a>
        </div>
        <div class="hero-stats" id="heroStats">
        <div class="hero-stat">
            <strong id="stat-vehicules">150+</strong>
            <span>Véhicules gérés</span>
        </div>
        <div class="hero-stat">
            <strong id="stat-dispo">98%</strong>
            <span>Disponibilité</span>
        </div>
        <div class="hero-stat">
            <strong id="stat-gps">5 sec</strong>
            <span>Mise à jour GPS</span>
        </div>
        </div>
<div class="live-indicator">
    <span class="live-dot"></span>
    <span class="live-label">Données en direct — <span id="live-time">--:--:--</span></span>
</div>
    </section>

    <!-- ============ FONCTIONNALITÉS ============ -->
    <section id="fonctionnalites">
        <div class="container">
            <h2 class="section-title">Nos fonctionnalités principales</h2>
            <p class="section-subtitle">Une plateforme complète pensée pour les contraintes du terrain minier guinéen.</p>

            <div class="features-grid">
                <div class="feature-card">
                    <div class="feature-icon">&#128205;</div>
                    <h3>Suivi GPS temps réel</h3>
                    <p>Positionnement de tous vos véhicules sur une carte interactive Leaflet, mise à jour automatique toutes les 5 secondes.</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">&#128276;</div>
                    <h3>Alertes automatiques</h3>
                    <p>Détection de vitesse excessive, température moteur critique, carburant bas et sortie de zone.</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">&#128202;</div>
                    <h3>Rapports analytiques</h3>
                    <p>Consommation, kilométrage, incidents — visualisés avec Chart.js et exportables en CSV.</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">&#128101;</div>
                    <h3>Gestion multi-rôles</h3>
                    <p>Administrateur et superviseurs avec des droits d'accès personnalisés selon leurs responsabilités.</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">&#128187;</div>
                    <h3>Simulation IoT</h3>
                    <p>Module de simulation réaliste des capteurs embarqués, sans nécessiter de matériel physique.</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">&#128274;</div>
                    <h3>Sécurité renforcée</h3>
                    <p>Authentification bcrypt, requêtes PDO préparées, protection CSRF sur tous les formulaires.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- ============ À PROPOS ============ -->
    <section class="about" id="apropos">
        <div class="container">
            <div class="about-grid">
                <div class="about-text">
                    <h2>À propos de FleetIoT</h2>
                    <p>FleetIoT est un système de gestion intelligente de flotte développé dans le cadre du projet Simandou 2040, le plus grand gisement de fer non exploité au monde, situé dans le sud-est de la Guinée.</p>
                    <p>Notre plateforme permet aux entreprises de transport minier et routier de surveiller leurs véhicules en temps réel, d'anticiper les pannes mécaniques et d'optimiser leurs opérations logistiques sur l'ensemble du corridor minier.</p>
                </div>
                <div class="about-stats">
                    <div class="about-stat-card">
                        <strong>670 km</strong>
                        <span>Corridor minier</span>
                    </div>
                    <div class="about-stat-card">
                        <strong>24/7</strong>
                        <span>Surveillance continue</span>
                    </div>
                    <div class="about-stat-card">
                        <strong>4</strong>
                        <span>Types d'alertes</span>
                    </div>
                    <div class="about-stat-card">
                        <strong>2 Md</strong>
                        <span>Tonnes de minerai</span>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ============ CONTACT ============ -->
    <section id="contact">
        <div class="container">
            <h2 class="section-title">Nous contacter</h2>
            <p class="section-subtitle">Une question sur notre solution ? Écrivez-nous, nous vous répondrons rapidement.</p>

            <div class="contact-form-wrap">

                <?php if ($success): ?>
                    <div class="form-success active"><?= $success ?></div>
                <?php endif; ?>

                <?php if (!empty($errors)): ?>
                    <div class="form-success active" style="background:#FCEBEB;color:#A32D2D;">
                        <?= implode('<br>', array_map('htmlspecialchars', $errors)) ?>
                    </div>
                <?php endif; ?>

                <form method="POST" id="contactForm" novalidate>
                    <form method="POST" id="contactForm" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <!-- Piège à robots : champ invisible, jamais rempli par un humain -->
                    <input type="text" name="site_web" value="" style="position:absolute;left:-9999px;" tabindex="-1" autocomplete="off">
                    <div class="contact-grid">
                        <div class="form-group">
                            <label for="nom">Nom complet *</label>
                            <input type="text" id="nom" name="nom" required>
                            <span class="form-error" id="errNom">Le nom est obligatoire.</span>
                        </div>
                        <div class="form-group">
                            <label for="email">Adresse email *</label>
                            <input type="email" id="email" name="email" required>
                            <span class="form-error" id="errEmail">Email invalide.</span>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="sujet">Objet</label>
                        <select id="sujet" name="sujet">
                            <option value="information">Demande d'information</option>
                            <option value="demo">Demande de démonstration</option>
                            <option value="support">Support technique</option>
                            <option value="autre">Autre</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="message">Votre message *</label>
                        <textarea id="message" name="message" rows="5" maxlength="500" required></textarea>
                        <span class="form-error" id="errMessage">Le message ne peut pas être vide.</span>
                    </div>

                    <button type="submit" name="contact_submit" class="btn btn-primary" style="width:100%;background:#0F6E56;color:#fff;">
                        Envoyer le message
                    </button>
                </form>
            </div>
        </div>
    </section>

    <!-- ============ FOOTER ============ -->
    <footer class="site-footer">
        <span>&copy; <?= date('Y') ?> FleetIoT </span>
        <div class="footer-tech">
            <span>PHP 8.2</span>
            <span>MySQL</span>
            <span>Leaflet.js</span>
        </div>
    </footer>

    <script src="assets/js/contact-form.js"></script>
    <script>
    (function () {
        const elVehicules = document.getElementById('stat-vehicules');
        const elDispo     = document.getElementById('stat-dispo');
        const elGps       = document.getElementById('stat-gps');
        const elTime      = document.getElementById('live-time');

        function animerNombre(el, cible, suffixe) {
            const texteActuel = el.textContent.replace(/[^0-9]/g, '');
            const depart = parseInt(texteActuel) || 0;
            const duree = 600;
            const debut = performance.now();

            function step(now) {
                const progress = Math.min((now - debut) / duree, 1);
                const eased = 1 - Math.pow(1 - progress, 3);
                const valeur = Math.round(depart + (cible - depart) * eased);
                el.textContent = valeur + suffixe;
                if (progress < 1) requestAnimationFrame(step);
            }
            requestAnimationFrame(step);
        }

        function mettreAJour() {
            fetch('api/public_stats.php')
                .then(r => r.json())
                .then(data => {
                    const v = data.vehicules;
                    if (v >= 150) {
                        elVehicules.textContent = '150+';
                    } else {
                        animerNombre(elVehicules, v, '+');
                    }

                    animerNombre(elDispo, data.disponibilite, '%');
                    animerNombre(elGps, data.delai_gps ?? 5, ' sec');

                    if (elTime) elTime.textContent = data.timestamp;

                    document.querySelectorAll('.hero-stat strong').forEach(el => {
                        el.classList.remove('stat-pulse');
                        void el.offsetWidth;
                        el.classList.add('stat-pulse');
                    });
                })
                .catch(() => {});
        }

        mettreAJour();
        setInterval(mettreAJour, 1000);
    })();
    </script>
</body>
</html>
</body>
</html>