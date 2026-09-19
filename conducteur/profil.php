<?php
// ============================================================
// FleetIoT — Projet Simandou 2040
// Fichier : conducteur/profil.php
// Rôle    : Permet au conducteur de changer son mot de passe
//           temporaire reçu par email lors de la création de l'accès
// ============================================================

session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireConducteur();

$idConducteur = $_SESSION['user_id'];
$message = '';
$messageType = '';

// ====== CHANGEMENT DE MOT DE PASSE ======
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'changer_mdp') {

    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $message = 'Session expirée, veuillez réessayer.';
        $messageType = 'error';
    } else {
        $ancienMdp = $_POST['ancien_mdp'] ?? '';
        $nouveauMdp = $_POST['nouveau_mdp'] ?? '';
        $confirmationMdp = $_POST['confirmation_mdp'] ?? '';

        $stmt = $pdo->prepare("SELECT mot_de_passe FROM conducteurs WHERE id_conducteur = ?");
        $stmt->execute([$idConducteur]);
        $hashActuel = $stmt->fetchColumn();

        $erreurMdp = validerMotDePasse($nouveauMdp);

        if (!password_verify($ancienMdp, $hashActuel)) {
            $message = 'Le mot de passe actuel est incorrect.';
            $messageType = 'error';
        } elseif ($erreurMdp) {
            $message = $erreurMdp;
            $messageType = 'error';
        } elseif ($nouveauMdp !== $confirmationMdp) {
            $message = 'Les mots de passe ne correspondent pas.';
            $messageType = 'error';
        } else {
            $nouveauHash = password_hash($nouveauMdp, PASSWORD_BCRYPT);
            $stmt = $pdo->prepare("UPDATE conducteurs SET mot_de_passe = ? WHERE id_conducteur = ?");
            $stmt->execute([$nouveauHash, $idConducteur]);

            enregistrerAudit($pdo, 'changement_mdp_conducteur', 'Conducteur ID ' . $idConducteur);
            $message = 'Mot de passe modifié avec succès.';
            $messageType = 'success';
        }
    }
}

$stmt = $pdo->prepare("SELECT * FROM conducteurs WHERE id_conducteur = ?");
$stmt->execute([$idConducteur]);
$conducteur = $stmt->fetch();

$csrfToken = generateCsrfToken();
$pageTitle = 'Mon profil';
require_once '../includes/header.php';
?>

<div class="dash-header-row" style="max-width:760px;margin:0 auto 20px;">
    <div>
        <h1>Mon profil</h1>
        <p class="subtitle">Gérez vos informations de connexion</p>
    </div>
</div>

<?php if ($message): ?>
    <div class="panel" style="padding:12px 16px;margin:0 auto 14px;max-width:760px;background:<?= $messageType === 'success' ? '#E1F5EE' : '#FCEBEB' ?>;color:<?= $messageType === 'success' ? '#085041' : '#A32D2D' ?>;border:none;">
        <?= htmlspecialchars($message) ?>
    </div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:240px 1fr;gap:20px;max-width:760px;margin:0 auto;">

    <!-- ====== COLONNE PHOTO ====== -->
    <div class="panel" style="padding:24px;text-align:center;">
        <?php if ($conducteur['photo']): ?>
            <img src="../<?= htmlspecialchars($conducteur['photo']) ?>" alt="Photo" style="width:120px;height:120px;border-radius:50%;object-fit:cover;border:3px solid var(--color-border);margin:0 auto 16px;">
        <?php else: ?>
            <div style="width:120px;height:120px;border-radius:50%;background:#E1F5EE;color:#0F6E56;display:flex;align-items:center;justify-content:center;font-size:32px;font-weight:700;margin:0 auto 16px;">
                <?= strtoupper(substr($conducteur['prenom'], 0, 1) . substr($conducteur['nom'], 0, 1)) ?>
            </div>
        <?php endif; ?>
        <h3 style="margin-bottom:6px;font-size:16px;"><?= htmlspecialchars($conducteur['prenom'] . ' ' . $conducteur['nom']) ?></h3>
        <span class="badge badge-success">Conducteur</span>
        <p style="font-size:12px;color:#999;margin-top:14px;word-break:break-word;"><?= htmlspecialchars($conducteur['email']) ?></p>
    </div>

    <!-- ====== COLONNE FORMULAIRE ====== -->
    <div class="panel" style="padding:24px;">
        <h3 style="margin-bottom:18px;font-size:15px;">Changer mon mot de passe</h3>
        <form method="POST">
            <input type="hidden" name="action" value="changer_mdp">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

            <div class="form-group">
                <label>Mot de passe actuel</label>
                <div class="champ-mdp-wrapper">
                    <input type="password" name="ancien_mdp" required class="champ-mdp">
                    <button type="button" class="btn-afficher-mdp" tabindex="-1">&#128065;</button>
                </div>
            </div>

            <div class="form-group">
                <label>Nouveau mot de passe</label>
                <div class="champ-mdp-wrapper">
                    <input type="password" name="nouveau_mdp" required minlength="8" class="champ-mdp"
                           placeholder="8 car. min., 1 majuscule, 1 minuscule, 1 chiffre">
                    <button type="button" class="btn-afficher-mdp" tabindex="-1">&#128065;</button>
                </div>
            </div>

            <div class="form-group">
                <label>Confirmer le nouveau mot de passe</label>
                <div class="champ-mdp-wrapper">
                    <input type="password" name="confirmation_mdp" required minlength="8" class="champ-mdp">
                    <button type="button" class="btn-afficher-mdp" tabindex="-1">&#128065;</button>
                </div>
            </div>

            <button type="submit" class="btn-dash btn-dash-primary" style="width:100%;margin-top:8px;">Mettre à jour le mot de passe</button>
        </form>
    </div>
</div>

<style>
.champ-mdp-wrapper { position: relative; }
.champ-mdp { width: 100%; padding-right: 40px; }
.btn-afficher-mdp {
    position: absolute; right: 8px; top: 50%; transform: translateY(-50%);
    background: none; border: none; cursor: pointer; font-size: 16px; opacity: 0.5;
}
.btn-afficher-mdp:hover { opacity: 1; }
</style>

<script>
document.querySelectorAll('.btn-afficher-mdp').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var champ = this.previousElementSibling;
        champ.type = champ.type === 'password' ? 'text' : 'password';
    });
});
</script>

<?php require_once '../includes/footer.php'; ?>