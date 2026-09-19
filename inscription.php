<?php
// ============================================================
// FleetIoT — Projet Simandou 2040
// Fichier : inscription.php
// Rôle    : Création d'un compte superviseur (en attente de
//           validation par un administrateur)
// ============================================================

session_start();
require_once 'includes/db.php';
require_once 'includes/functions.php';
require_once 'includes/auth.php';

// Si déjà connecté, redirection directe vers le bon dashboard
if (isset($_SESSION['user_id'])) {
    header('Location: ' . ($_SESSION['user_role'] === 'admin' ? 'admin/dashboard.php' : 'superviseur/dashboard.php'));
    exit();
}

$error = '';
$success = false;

// ====== TRAITEMENT DU FORMULAIRE ======
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
        $error = 'Session expirée, veuillez réessayer.';
    } else {

        $nom = clean($_POST['nom'] ?? '');
        $prenom = clean($_POST['prenom'] ?? '');
        $email = clean($_POST['email'] ?? '');
        $telephone = clean($_POST['telephone'] ?? '');
        $password = $_POST['password'] ?? '';
        $passwordConfirm = $_POST['password_confirm'] ?? '';

        $erreurValidationMdp = validerMotDePasse($password);

        if (empty($nom) || empty($prenom) || empty($email) || empty($password)) {
            $error = 'Veuillez renseigner tous les champs obligatoires.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Adresse email invalide.';
        } elseif ($erreurValidationMdp) {
            $error = $erreurValidationMdp;
        } elseif ($password !== $passwordConfirm) {
            $error = 'Les mots de passe ne correspondent pas.';
        } elseif (($_SESSION['derniere_inscription'] ?? 0) > time() - 30) {
            $error = 'Veuillez attendre quelques instants avant de réessayer.';
        } else {

            $hash = password_hash($password, PASSWORD_BCRYPT);

            try {
                // Compte créé en statut "inactif" : un administrateur doit
                // valider l'accès avant la première connexion (admin/superviseurs.php)
                $stmt = $pdo->prepare("
                    INSERT INTO utilisateurs (nom, prenom, email, telephone, mot_de_passe, role, statut)
                    VALUES (?, ?, ?, ?, ?, 'superviseur', 'inactif')
                ");
                $stmt->execute([$nom, $prenom, $email, $telephone, $hash]);
                $_SESSION['derniere_inscription'] = time();
                $success = true;
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) {
                    $error = 'Cette adresse email est déjà utilisée par un compte existant.';
                } else {
                    $error = 'Une erreur est survenue lors de la création du compte.';
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
    <title>Inscription — FleetIoT</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/responsive.css">
    <style>
        .auth-wrapper {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, var(--color-primary-dark) 0%, var(--color-primary) 60%, var(--color-primary-light) 100%);
            padding: 24px;
        }
        .auth-card {
            background: #fff;
            border-radius: var(--radius-lg);
            padding: 44px 40px;
            width: 100%;
            max-width: 460px;
            box-shadow: var(--shadow-md);
        }
        .auth-logo {
            display: flex;
            align-items: center;
            gap: 10px;
            color: var(--color-primary);
            font-weight: 700;
            font-size: 19px;
            margin-bottom: 28px;
        }
        .auth-logo svg { width: 26px; height: 26px; }
        .auth-card h1 {
            font-size: 22px;
            margin-bottom: 6px;
            color: var(--color-text);
        }
        .auth-card p.subtitle {
            color: var(--color-text-secondary);
            font-size: 14px;
            margin-bottom: 26px;
        }
        .auth-alert {
            background: var(--color-danger-light);
            color: var(--color-danger-dark);
            padding: 12px 16px;
            border-radius: var(--radius-sm);
            font-size: 13px;
            margin-bottom: 18px;
        }
        .auth-alert.success {
            background: var(--color-success-light, #E1F5EE);
            color: var(--color-success-dark, #085041);
        }
        .btn-submit {
            width: 100%;
            background: var(--color-primary);
            color: #fff;
            padding: 13px;
            border-radius: var(--radius-md);
            font-weight: 600;
            font-size: 15px;
            transition: var(--transition);
            margin-top: 8px;
        }
        .btn-submit:hover { background: var(--color-primary-light); }
        .auth-links {
            margin-top: 22px;
            text-align: center;
            font-size: 13px;
            color: var(--color-text-secondary);
        }
        .auth-links a {
            color: var(--color-primary);
            font-weight: 600;
        }
        .back-home {
            position: absolute;
            top: 24px;
            left: 24px;
            color: var(--color-text-light);
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .name-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
        }
    </style>
</head>
<body>

    <div class="auth-wrapper">

        <a href="index.php" class="back-home">&#8592; Retour à l'accueil</a>

        <div class="auth-card">
            <div class="auth-logo">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M3 16V8a1 1 0 011-1h10l4 4v5a1 1 0 01-1 1H4a1 1 0 01-1-1z"/>
                    <circle cx="7" cy="17" r="1.5"/>
                    <circle cx="16" cy="17" r="1.5"/>
                </svg>
                FleetIoT
            </div>

            <?php if ($success): ?>

                <h1>Demande envoyée</h1>
                <div class="auth-alert success">
                    Votre compte superviseur a été créé avec succès. Il doit maintenant être
                    <strong>validé par un administrateur</strong> avant que vous puissiez vous connecter.
                    Vous serez informé dès que l'accès sera activé.
                </div>
                <div class="auth-links">
                    <a href="connexion.php">Retour à la connexion</a>
                </div>

            <?php else: ?>

                <h1>Créer un compte superviseur</h1>
                <p class="subtitle">Votre demande sera examinée par un administrateur avant activation.</p>

                <?php if ($error): ?>
                    <div class="auth-alert"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <form method="POST" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

                    <div class="name-grid">
                        <div class="form-group">
                            <label for="nom">Nom *</label>
                            <input type="text" id="nom" name="nom" required
                                   value="<?= htmlspecialchars($_POST['nom'] ?? '') ?>" placeholder="Diallo">
                        </div>
                        <div class="form-group">
                            <label for="prenom">Prénom *</label>
                            <input type="text" id="prenom" name="prenom" required
                                   value="<?= htmlspecialchars($_POST['prenom'] ?? '') ?>" placeholder="Aïssatou">
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="email">Adresse email *</label>
                        <input type="email" id="email" name="email" required
                               value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" placeholder="vous@fleetiot.gn">
                    </div>

                    <div class="form-group">
                        <label for="telephone">Téléphone</label>
                        <input type="text" id="telephone" name="telephone"
                               value="<?= htmlspecialchars($_POST['telephone'] ?? '') ?>" placeholder="+224 6XX XX XX XX">
                    </div>

                    <div class="form-group">
                        <label for="password">Mot de passe *</label>
                        <input type="password" id="password" name="password" required minlength="8" placeholder="8 car. min., 1 majuscule, 1 minuscule, 1 chiffre">
                    </div>

                    <div class="form-group">
                        <label for="password_confirm">Confirmer le mot de passe *</label>
                        <input type="password" id="password_confirm" name="password_confirm" required minlength="8" placeholder="••••••••">
                    </div>

                    <button type="submit" class="btn-submit">Créer mon compte</button>
                </form>

                <div class="auth-links">
                    Déjà un compte ? <a href="connexion.php">Se connecter</a>
                </div>

            <?php endif; ?>
        </div>
    </div>

</body>
</html>