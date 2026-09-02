<?php
/**
 * Téléversement pièce par pièce, pour les dossiers déposés dans le navigateur.
 * Chaque fichier arrive dans sa propre requête : la limite du serveur s'applique
 * par fichier et non à l'ensemble, et on peut afficher une progression.
 */

declare(strict_types=1);
require __DIR__ . '/lib.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');

function rep(int $code, array $corps): never
{
    http_response_code($code);
    echo json_encode($corps, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

session_demarrer();
if (!connecte()) {
    rep(403, ['erreur' => 'session fermée, recharge la page']);
}
if (!hash_equals($_SESSION['jeton_csrf'] ?? '', (string) ($_POST['csrf'] ?? ''))) {
    rep(400, ['erreur' => 'formulaire expiré, recharge la page']);
}

$cfg = config();
$quoi = $_POST['quoi'] ?? '';
$jeton = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($_POST['jeton'] ?? '')) ?? '';

/* --------------------------------------------------------- on ouvre un dépôt */

if ($quoi === 'ouvrir') {
    $j = jeton(18);
    @mkdir(__DIR__ . '/f/' . $j, 0755, true);
    rep(200, ['jeton' => $j]);
}

if ($jeton === '' || !is_dir(__DIR__ . '/f/' . $jeton)) {
    rep(404, ['erreur' => 'dépôt inconnu']);
}
$racine = __DIR__ . '/f/' . $jeton;

/* ------------------------------------------------------------- une pièce */

if ($quoi === 'piece') {
    $f = $_FILES['fichier'] ?? null;
    if (!$f || $f['error'] !== UPLOAD_ERR_OK) {
        $code = (int) ($f['error'] ?? -1);
        rep(400, ['erreur' => match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'trop lourd (limite ' . ini_get('upload_max_filesize') . ')',
            default => "envoi interrompu (code $code)",
        }]);
    }

    // Le chemin relatif est reconstruit segment par segment, chacun nettoyé.
    $segments = [];
    foreach (explode('/', str_replace('\\', '/', (string) ($_POST['chemin'] ?? ''))) as $seg) {
        $seg = nom_sur($seg);
        if ($seg !== '' && $seg !== '.' && $seg !== '..') {
            $segments[] = $seg;
        }
    }
    if (count($segments) > 8) {
        $segments = array_slice($segments, -8);
    }
    $nom = array_pop($segments) ?? nom_sur((string) $f['name']);
    $dossier = $racine . ($segments ? '/' . implode('/', $segments) : '');

    if (count(dossier_lister($racine)) >= 500) {
        rep(413, ['erreur' => 'plus de 500 fichiers : passe par la commande partage sur le Mac']);
    }

    @mkdir($dossier, 0755, true);
    if (!move_uploaded_file($f['tmp_name'], $dossier . '/' . $nom)) {
        rep(500, ['erreur' => "impossible d'écrire $nom"]);
    }
    rep(200, ['recu' => $nom]);
}

/* ------------------------------------------------------ on referme le dépôt */

if ($quoi === 'clore') {
    $contenu = dossier_lister($racine);
    if (!$contenu) {
        supprimer_dossier($racine);
        rep(400, ['erreur' => 'aucun fichier reçu']);
    }
    $jours = max(1, min(365, (int) ($_POST['jours'] ?? $cfg['expiration_defaut'])));
    $total = array_sum(array_column($contenu, 'taille'));

    $commun = [
        'jeton'    => $jeton,
        'note'     => '',
        'cree'     => date('c'),
        'expire'   => date('c', time() + $jours * 86400),
        'retraits' => 0,
        'origine'  => 'web',
    ];
    $p = partages();
    if (count($contenu) === 1 && !str_contains($contenu[0]['chemin'], '/')) {
        $nom = $contenu[0]['chemin'];
        $p[] = $commun + [
            'type'   => 'fichier',
            'nom'    => $nom,
            'taille' => $contenu[0]['taille'],
            'sha256' => hash_file('sha256', $racine . '/' . $nom),
        ];
    } else {
        $p[] = $commun + [
            'type'   => 'groupe',
            'nom'    => mb_substr(trim((string) ($_POST['nom'] ?? '')) ?: count($contenu) . ' fichiers', 0, 120),
            'taille' => $total,
            'nombre' => count($contenu),
        ];
    }
    partages_ecrire($p);
    rep(200, ['lien' => lien_partage($jeton), 'jeton' => $jeton, 'fichiers' => count($contenu)]);
}

rep(400, ['erreur' => 'action inconnue']);
