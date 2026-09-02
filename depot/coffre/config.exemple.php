<?php
/**
 * Configuration du coffre — MODÈLE. Ne pas remplir à la main : la commande
 * bin/coffre-preparer fabrique le vrai fichier (config.php) avec l'empreinte du
 * mot de passe et une clé tirée au hasard, sans jamais rien afficher à l'écran.
 * config.php ne doit jamais être servi par le web (le .htaccess de ce dossier
 * l'interdit) ni entrer dans un dépôt public (il est dans le .gitignore).
 */
return [
    'adresse'            => 'https://exemple.fr/depot',   // adresse publique du coffre, sans barre finale
    'empreinte_mdp'      => '',                            // password_hash() du mot de passe de la page
    'cle_api'            => '',                            // clé des commandes du Mac (openssl rand -hex 24)
    'expiration_defaut'  => 7,                             // durée de vie par défaut d'un partage, en jours
    'proprietaire'       => '',                            // affiché sur la page de retrait : « partagé par … » (facultatif)
    'prenom'             => '',                            // « redemande-le à … » (facultatif)
];
