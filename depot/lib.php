<?php
/**
 * Le coffre — bibliothèque commune au dépôt de fichiers et au conservatoire de preuves.
 * Pas de base de données : tout tient dans des fichiers JSON, dans coffre/.
 */

declare(strict_types=1);

const COFFRE = __DIR__ . '/coffre';

// Toutes les dates affichées et enregistrées sont à l'heure de Paris.
date_default_timezone_set('Europe/Paris');

function config(): array
{
    static $c = null;
    if ($c === null) {
        $f = COFFRE . '/config.php';
        if (!is_file($f)) {
            http_response_code(500);
            exit("Le coffre n'est pas configuré : coffre/config.php est absent.");
        }
        $c = require $f;
    }
    return $c;
}

/* ---------------------------------------------------------------- fichiers */

function lire_json(string $nom): array
{
    $f = COFFRE . '/' . $nom;
    if (!is_file($f)) {
        return [];
    }
    $t = file_get_contents($f);
    $d = json_decode($t === false ? '' : $t, true);
    return is_array($d) ? $d : [];
}

function ecrire_json(string $nom, array $donnees): void
{
    $f = COFFRE . '/' . $nom;
    $tmp = $f . '.tmp';
    file_put_contents($tmp, json_encode($donnees, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    rename($tmp, $f);
}

function supprimer_dossier(string $chemin): void
{
    if (!is_dir($chemin)) {
        return;
    }
    foreach (scandir($chemin) ?: [] as $e) {
        if ($e === '.' || $e === '..') {
            continue;
        }
        $sous = $chemin . '/' . $e;
        is_dir($sous) ? supprimer_dossier($sous) : @unlink($sous);
    }
    @rmdir($chemin);
}

/* ------------------------------------------------------------------ divers */

function jeton(int $octets = 16): string
{
    return rtrim(strtr(base64_encode(random_bytes($octets)), '+/', '-_'), '=');
}

function taille_lisible(int $o): string
{
    $u = ['o', 'Ko', 'Mo', 'Go', 'To'];
    $i = 0;
    $n = (float) $o;
    while ($n >= 1024 && $i < 4) {
        $n /= 1024;
        $i++;
    }
    return ($i === 0 ? (string) $o : number_format($n, $n < 10 ? 1 : 0, ',', ' ')) . ' ' . $u[$i];
}

function date_fr(string $iso): string
{
    $t = strtotime($iso);
    if ($t === false) {
        return $iso;
    }
    $mois = ['', 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet',
             'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    return date('j', $t) . ' ' . $mois[(int) date('n', $t)] . ' ' . date('Y', $t) . ' à ' . date('H\hi', $t);
}

/** Nom de fichier sûr : on garde le nom d'origine mais on retire tout ce qui peut nuire. */
function nom_sur(string $nom): string
{
    $nom = basename(str_replace('\\', '/', $nom));
    $nom = preg_replace('/[^\p{L}\p{N}._ -]+/u', '_', $nom) ?? 'fichier';
    $nom = trim($nom, '. ');
    if ($nom === '' || $nom === null) {
        $nom = 'fichier';
    }
    // Rien qui puisse être exécuté par Apache dans le dossier public.
    if (preg_match('/\.(php\d?|phtml|phar|cgi|pl|py|htaccess)$/i', $nom)) {
        $nom .= '.txt';
    }
    return mb_substr($nom, 0, 120);
}

/** L'adresse de la feuille de style porte sa date de modification :
 *  le navigateur recharge automatiquement dès qu'elle change. */
function css(): string
{
    $f = __DIR__ . '/style.css';
    return 'style.css?v=' . (is_file($f) ? filemtime($f) : '1');
}

function h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/* ------------------------------------------------------------------- accès */

function session_demarrer(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    // Le cookie est cantonné au dossier du coffre : il n'est pas envoyé
    // au reste du site, WordPress compris.
    $chemin = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/') . '/';
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => $chemin,
        'secure'   => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('coffre');
    session_start();
}

function connecte(): bool
{
    session_demarrer();
    return !empty($_SESSION['coffre_ok']);
}

/**
 * Freine les essais répétés. Chaque tentative est inscrite sous verrou exclusif,
 * avant même la vérification du mot de passe : plusieurs requêtes lancées en
 * parallèle ne peuvent donc pas se faufiler ensemble à travers le compteur.
 *
 * Rend le nombre de tentatives des quinze dernières minutes.
 */
function verrou_essai(): int
{
    $f = fopen(COFFRE . '/essais.json', 'c+');
    if ($f === false) {
        return 0;
    }
    flock($f, LOCK_EX);
    $brut = stream_get_contents($f) ?: '';
    $essais = json_decode($brut, true);
    $essais = is_array($essais) ? $essais : [];
    $essais = array_values(array_filter($essais, fn($t) => is_int($t) && $t > time() - 900));
    $essais[] = time();
    $essais = array_slice($essais, -100);
    ftruncate($f, 0);
    rewind($f);
    fwrite($f, json_encode($essais));
    fflush($f);
    flock($f, LOCK_UN);
    fclose($f);
    return count($essais);
}

function verrou_reussite(): void
{
    @unlink(COFFRE . '/essais.json');
}

function exiger_connexion(): void
{
    if (!connecte()) {
        header('Location: index.php');
        exit;
    }
}

/** Parcourt un dossier partagé et rend la liste de ce qu'il contient. */
function dossier_lister(string $racine, string $prefixe = ''): array
{
    $liste = [];
    $entrees = scandir($racine) ?: [];
    sort($entrees);
    foreach ($entrees as $e) {
        if ($e === '.' || $e === '..' || $e === '.htaccess' || $e === '.DS_Store') {
            continue;
        }
        $chemin = $racine . '/' . $e;
        if (is_dir($chemin)) {
            $liste = array_merge($liste, dossier_lister($chemin, $prefixe . $e . '/'));
        } elseif (is_file($chemin)) {
            $liste[] = ['chemin' => $prefixe . $e, 'taille' => filesize($chemin)];
        }
    }
    return $liste;
}

/** Le chemin d'un fichier dans une adresse : chaque segment encodé, les barres gardées. */
function url_chemin(string $chemin): string
{
    return implode('/', array_map('rawurlencode', explode('/', $chemin)));
}

function partage_dossier(array $e): string
{
    return __DIR__ . '/f/' . $e['jeton'];
}

/* ---------------------------------------------------------------- partages */

function partages(): array
{
    return lire_json('partages.json');
}

function partages_ecrire(array $p): void
{
    ecrire_json('partages.json', $p);
}

/** Efface les partages arrivés à échéance. Appelé à chaque visite, donc jamais oublié. */
function partages_nettoyer(): int
{
    $p = partages();
    $restants = [];
    $efface = 0;
    foreach ($p as $e) {
        if (!empty($e['expire']) && strtotime($e['expire']) < time()) {
            supprimer_dossier(__DIR__ . '/f/' . $e['jeton']);
            $efface++;
            continue;
        }
        $restants[] = $e;
    }
    if ($efface > 0) {
        partages_ecrire($restants);
    }
    return $efface;
}

function partage_par_jeton(string $jeton): ?array
{
    foreach (partages() as $e) {
        if (hash_equals($e['jeton'], $jeton)) {
            return $e;
        }
    }
    return null;
}

function partage_compter(string $jeton): void
{
    $p = partages();
    foreach ($p as &$e) {
        if ($e['jeton'] === $jeton) {
            $e['retraits'] = (int) ($e['retraits'] ?? 0) + 1;
            $e['dernier_retrait'] = date('c');
        }
    }
    unset($e);
    partages_ecrire($p);
}

function lien_partage(string $jeton): string
{
    return rtrim(config()['adresse'], '/') . '/d/' . $jeton;
}

/* ----------------------------------------------------------------- preuves */

function preuves(): array
{
    return lire_json('preuves.json');
}

function preuves_ecrire(array $p): void
{
    ecrire_json('preuves.json', $p);
}

/**
 * Chaînage : l'empreinte de chaque pièce inclut celle de la précédente.
 * Modifier une pièce ancienne casserait toutes les suivantes — c'est le but.
 */
function preuve_maillon(array $preuve, string $precedent): string
{
    return hash('sha256', $precedent . '|' . $preuve['id'] . '|' . $preuve['url']
        . '|' . $preuve['capture'] . '|' . ($preuve['empreintes_concat'] ?? ''));
}

/**
 * Retire une pièce : les fichiers sont effacés, la fiche reste, marquée.
 * La chaîne n'est pas cassée — elle repose sur les empreintes relevées le jour
 * de la capture, pas sur les fichiers eux-mêmes. Un retrait se voit donc
 * toujours, et c'est le but : une archive de preuves qu'on peut vider en
 * silence ne vaut rien.
 */
function preuve_retirer(string $id, string $motif): bool
{
    $liste = preuves();
    $trouve = false;
    foreach ($liste as &$p) {
        if ($p['id'] !== $id || !empty($p['retiree'])) {
            continue;
        }
        supprimer_dossier(preuve_dossier($p));
        $p['retiree'] = date('c');
        $p['motif']   = mb_substr(trim($motif), 0, 300);
        $trouve = true;
    }
    unset($p);
    if ($trouve) {
        preuves_ecrire($liste);
    }
    return $trouve;
}

function preuve_par_id(string $id): ?array
{
    foreach (preuves() as $p) {
        if ($p['id'] === $id) {
            return $p;
        }
    }
    return null;
}

function preuve_dossier(array $p): string
{
    return COFFRE . '/preuves/' . $p['id'];
}
