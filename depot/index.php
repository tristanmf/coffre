<?php
/** Le coffre — page d'administration. Tout passe par ici, sauf le retrait des fichiers (d.php). */

declare(strict_types=1);
require __DIR__ . '/lib.php';

session_demarrer();
$cfg = config();
$avis = null;
$erreur = null;

/* ------------------------------------------------------------- connexion */

if (($_POST['action'] ?? '') === 'connexion') {
    // La tentative est comptée avant d'être vérifiée : impossible d'en glisser
    // plusieurs en parallèle sous le compteur.
    $essais = verrou_essai();

    if ($essais > 8) {
        // Chaque essai supplémentaire coûte plus cher, jusqu'à cinq secondes.
        usleep((int) min(5_000_000, 500_000 * ($essais - 8)));
        $erreur = "Trop d'essais. La porte se rouvre un quart d'heure après le dernier.";
    } elseif (password_verify((string) ($_POST['mdp'] ?? ''), $cfg['empreinte_mdp'])) {
        session_regenerate_id(true);
        $_SESSION['coffre_ok'] = true;
        $_SESSION['jeton_csrf'] = jeton();
        verrou_reussite();
        header('Location: index.php');
        exit;
    } else {
        usleep(700000);
        $erreur = 'Mot de passe incorrect.';
        if ($essais >= 5) {
            $erreur .= ' (' . (9 - $essais) . ' essai' . (9 - $essais > 1 ? 's' : '') . ' avant blocage)';
        }
    }
}

if (isset($_GET['sortie'])) {
    $_SESSION = [];
    session_destroy();
    header('Location: index.php');
    exit;
}

if (!connecte()) {
    afficher_connexion($erreur);
    exit;
}

/* ------------------------------------------------------------- protection */

function csrf(): string
{
    return $_SESSION['jeton_csrf'] ??= jeton();
}

function csrf_verifier(): void
{
    if (!hash_equals($_SESSION['jeton_csrf'] ?? '', (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('Formulaire expiré. Recharge la page.');
    }
}

/* ---------------------------------------------------------------- actions */

$action = $_POST['action'] ?? '';

if ($action === 'deposer') {
    csrf_verifier();
    // Le formulaire accepte plusieurs fichiers d'un coup : $_FILES arrive en tableau.
    $f = $_FILES['fichier'] ?? null;
    $noms = is_array($f['name'] ?? null) ? $f['name'] : [];
    $retenus = [];
    foreach ($noms as $i => $nom) {
        if ((int) $f['error'][$i] === UPLOAD_ERR_OK) {
            $retenus[] = $i;
        }
    }

    if (!$retenus) {
        $premier = (int) ($f['error'][0] ?? UPLOAD_ERR_NO_FILE);
        $erreur = match ($premier) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE =>
                'Fichier trop lourd pour le formulaire (limite ' . ini_get('upload_max_filesize')
                . '). Passe par la commande « partage » sur le Mac : elle n\'a pas de limite.',
            UPLOAD_ERR_NO_FILE => 'Aucun fichier choisi.',
            default => "L'envoi a échoué (code $premier).",
        };
    } else {
        $jours = max(1, min(365, (int) ($_POST['jours'] ?? $cfg['expiration_defaut'])));
        $jeton = jeton(18);
        $dossier = __DIR__ . '/f/' . $jeton;
        @mkdir($dossier, 0755, true);

        $poses = [];
        foreach ($retenus as $i) {
            $nom = nom_sur((string) $f['name'][$i]);
            // deux fichiers du même nom ne doivent pas s'écraser
            $j = 2;
            while (isset($poses[$nom])) {
                $nom = preg_replace('/(\.[^.]+)?$/', '-' . $j . '$1', nom_sur((string) $f['name'][$i]), 1);
                $j++;
            }
            if (move_uploaded_file($f['tmp_name'][$i], $dossier . '/' . $nom)) {
                $poses[$nom] = filesize($dossier . '/' . $nom);
            }
        }

        if (!$poses) {
            supprimer_dossier($dossier);
            $erreur = "Impossible d'écrire sur le serveur.";
        } else {
            $p = partages();
            $commun = [
                'jeton'    => $jeton,
                'note'     => trim((string) ($_POST['note'] ?? '')),
                'cree'     => date('c'),
                'expire'   => date('c', time() + $jours * 86400),
                'retraits' => 0,
                'origine'  => 'web',
            ];
            if (count($poses) === 1) {
                $nom = array_key_first($poses);
                $p[] = $commun + [
                    'type'   => 'fichier',
                    'nom'    => $nom,
                    'taille' => $poses[$nom],
                    'sha256' => hash_file('sha256', $dossier . '/' . $nom),
                ];
            } else {
                $p[] = $commun + [
                    'type'   => 'groupe',
                    'nom'    => count($poses) . ' fichiers',
                    'taille' => array_sum($poses),
                    'nombre' => count($poses),
                ];
            }
            partages_ecrire($p);
            header('Location: index.php?nouveau=' . urlencode($jeton));
            exit;
        }
    }
}

if ($action === 'retirer_preuve') {
    csrf_verifier();
    $ok = preuve_retirer((string) ($_POST['piece'] ?? ''), (string) ($_POST['motif'] ?? ''));
    header('Location: index.php?section=preuves' . ($ok ? '&retiree=1' : ''));
    exit;
}

if ($action === 'supprimer_partage') {
    csrf_verifier();
    $cible = (string) ($_POST['jeton'] ?? '');
    $reste = [];
    foreach (partages() as $e) {
        if ($e['jeton'] === $cible) {
            supprimer_dossier(__DIR__ . '/f/' . $e['jeton']);
            continue;
        }
        $reste[] = $e;
    }
    partages_ecrire($reste);
    header('Location: index.php?efface=1');
    exit;
}

if ($action === 'prolonger') {
    csrf_verifier();
    $cible = (string) ($_POST['jeton'] ?? '');
    $p = partages();
    foreach ($p as &$e) {
        if ($e['jeton'] === $cible) {
            $e['expire'] = date('c', time() + 7 * 86400);
        }
    }
    unset($e);
    partages_ecrire($p);
    header('Location: index.php?prolonge=1');
    exit;
}

$efface = partages_nettoyer();
$section = $_GET['section'] ?? 'partages';

/* ---------------------------------------------------------------- gabarit */

function entete(string $titre, bool $barre = true): void
{
    $section = $_GET['section'] ?? 'partages';
    echo '<!doctype html><html lang="fr"><head><meta charset="utf-8">'
       , '<meta name="viewport" content="width=device-width, initial-scale=1">'
       , '<meta name="robots" content="noindex, nofollow">'
       , '<title>', h($titre), '</title>'
       , '<link rel="icon" href="favicon.png" type="image/png">'
       , '<link rel="stylesheet" href="', css(), '"></head><body><div class="page">';
    if ($barre) {
        echo '<header class="enseigne">',
             '<img src="logo.png" alt="" width="60" height="60">',
             '<div><h1>Le coffre</h1><p class="devise">Transferts éphémères et archives datées</p></div>',
             '</header>';
        echo '<div class="onglets">';
        echo '<a href="?section=partages" class="', $section === 'partages' ? 'actif' : '', '">Partages</a>';
        echo '<a href="?section=preuves" class="', $section === 'preuves' ? 'actif' : '', '">Preuves</a>';
        echo '<a href="?sortie=1" style="margin-left:auto">Fermer la session</a>';
        echo '</div>';
    }
}

function pied(): void
{
    echo '</div></body></html>';
}

function afficher_connexion(?string $erreur): void
{
    entete('Le coffre', false);
    echo '<div class="etroit portail">',
         '<img class="logo" src="logo.png" alt="" width="120" height="120">',
         '<h1>Le coffre</h1><p class="chapo">Accès privé.</p>';
    if ($erreur) {
        echo '<div class="avis erreur">', h($erreur), '</div>';
    }
    echo '<form method="post"><input type="hidden" name="action" value="connexion">',
         '<label for="mdp">Mot de passe</label>',
         '<input type="password" id="mdp" name="mdp" autofocus autocomplete="current-password">',
         '<div class="actions"><button>Entrer</button></div></form></div>';
    pied();
}

entete('Le coffre');

if (isset($_GET['nouveau'])) {
    $p = partage_par_jeton((string) $_GET['nouveau']);
    if ($p) {
        $quoi = ($p['type'] ?? 'fichier') === 'groupe'
            ? (int) ($p['nombre'] ?? 0) . ' fichiers déposés.'
            : 'Fichier déposé.';
        echo '<div class="avis"><strong>', h($quoi), '</strong> Voici le lien à envoyer — il expire le ',
             h(date_fr($p['expire'])), '.<div class="lien-boite"><input readonly value="', h(lien_partage($p['jeton'])),
             '" onclick="this.select()"></div></div>';
    }
}
if (isset($_GET['efface'])) {
    echo '<div class="avis">Partage supprimé.</div>';
}
if (isset($_GET['prolonge'])) {
    echo '<div class="avis">Partage prolongé de sept jours.</div>';
}
if (isset($_GET['retiree'])) {
    echo '<div class="avis">Pièce retirée : ses fichiers sont effacés du serveur, sa fiche reste au registre.</div>';
}
if ($efface > 0) {
    echo '<div class="avis">', $efface, ' partage', $efface > 1 ? 's arrivés' : ' arrivé',
         ' à échéance, effacé', $efface > 1 ? 's' : '', ' du serveur.</div>';
}
if ($erreur) {
    echo '<div class="avis erreur">', h($erreur), '</div>';
}

if ($section === 'preuves') {
    require __DIR__ . '/vue-preuves.php';
} else {
    require __DIR__ . '/vue-partages.php';
}

pied();
