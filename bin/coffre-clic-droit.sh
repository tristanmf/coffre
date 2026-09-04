#!/bin/bash
#
# coffre-clic-droit.sh — « Partager par le coffre », dans le menu du clic droit du Finder.
# Appelé par l'Action rapide du même nom. Reçoit les fichiers sélectionnés en "$@".
#
# Demande la durée, lance « partage » en SFTP (aucune limite de taille), et
# prévient par une notification quand le lien est prêt — il est alors déjà dans
# le presse-papier, prêt à coller dans WhatsApp ou un mail.
#
# ─────────────────────────────────────────────────────────────────────────────

# Une Action rapide démarre avec un PATH minimal : on le pose nous-mêmes.
# lftp (moteur du transfert) vit dans /opt/homebrew, partage dans ~/.local/bin.
export PATH="$HOME/.local/bin:/opt/homebrew/bin:/usr/local/bin:/usr/bin:/bin:/usr/sbin:/sbin"

[ "$#" -gt 0 ] || exit 0

notifier() {
  osascript - "$1" "$2" <<'AS' >/dev/null 2>&1 || true
on run argv
  display notification (item 2 of argv) with title "Le coffre" subtitle (item 1 of argv)
end run
AS
}

alerter() {
  osascript - "$1" <<'AS' >/dev/null 2>&1 || true
on run argv
  display dialog ("L'envoi n'a pas abouti." & return & return & (item 1 of argv)) ¬
    with title "Le coffre" buttons {"OK"} default button "OK" with icon caution
end run
AS
}

# Une seule question : combien de temps le lien reste valable.
jours="$(osascript <<'AS' 2>/dev/null
try
  set r to button returned of (display dialog ¬
    "Combien de temps ce partage doit-il rester disponible ?" ¬
    with title "Le coffre" buttons {"2 jours", "7 jours", "30 jours"} ¬
    default button "7 jours" with icon note)
on error
  return ""
end try
if r is "2 jours" then return "2"
if r is "30 jours" then return "30"
return "7"
AS
)"
[ -n "$jours" ] || exit 0   # dialogue annulé : on ne fait rien

if [ "$#" -eq 1 ]; then
  quoi="$(basename "$1")"
else
  quoi="$# éléments"
fi
notifier "$quoi" "Envoi en cours…"

# partage copie le lien dans le presse-papier et notifie lui-même. Mais une
# notification se rate — mode Concentration, écran secondaire, tête ailleurs.
# On montre donc aussi une fenêtre, qui attend. Le lien y est écrit en clair :
# même si le presse-papier a été écrasé entre-temps, il reste lisible.
if sortie="$(partage "$@" -j "$jours" 2>&1)"; then
  lien="$(printf '%s' "$sortie" | grep -oE 'https?://[^[:space:]]+' | tail -1)"
  osascript - "$quoi" "${lien:-(lien introuvable — voir coffre-liens)}" <<'AS' >/dev/null 2>&1 || true
on run argv
  display dialog ("C'est en ligne : " & (item 1 of argv) & return & return ¬
    & "Le lien est déjà dans le presse-papier, prêt à coller." & return & return ¬
    & (item 2 of argv)) ¬
    with title "Le coffre" buttons {"OK"} default button "OK" with icon note ¬
    giving up after 600
end run
AS
  exit 0
fi

motif="$(printf '%s' "$sortie" | grep -v '^[[:space:]]*$' | tail -3)"
alerter "${motif:-Envoi impossible.}"
exit 1
