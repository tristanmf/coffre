<?php
/** Onglet « Partages » : dépôt d'un fichier et liste des liens en cours. */

$liste = partages();
usort($liste, fn($a, $b) => strcmp($b['cree'], $a['cree']));
$total = array_sum(array_column($liste, 'taille'));
$max = ini_get('upload_max_filesize');
?>
<h2>Déposer</h2>
<form method="post" enctype="multipart/form-data">
  <input type="hidden" name="action" value="deposer">
  <input type="hidden" name="csrf" value="<?= h(csrf()) ?>">
  <label for="fichier">Fichiers ou dossier <span class="meta">(jusqu'à <?= h($max) ?> par fichier ; au-delà, la commande <code>partage</code> sur le Mac)</span></label>
  <div class="depose" id="depose">
    <input type="file" id="fichier" name="fichier[]" multiple required hidden>
    <svg viewBox="0 0 24 24" fill="none" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
      <path d="M12 16V4"/><path d="m7 9 5-5 5 5"/><path d="M4 15v3a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-3"/>
    </svg>
    <p class="depose-mot">Glisse tes fichiers ou un dossier ici<small>ou clique pour les choisir</small></p>
    <ul class="depose-liste" id="depose-liste"></ul>
  </div>
  <div class="duree">
    <span class="duree-mot">Disponible</span>
    <label><input type="radio" name="jours" value="2"><span>2 jours</span></label>
    <label><input type="radio" name="jours" value="7" checked><span>7 jours</span></label>
    <label><input type="radio" name="jours" value="30"><span>30 jours</span></label>
    <label><input type="radio" name="jours" value="365"><span>1 an</span></label>
  </div>
  <div class="actions"><button>Déposer et créer le lien</button></div>
</form>

<h2>Liens en cours <span class="meta">(<?= count($liste) ?>, <?= h(taille_lisible($total)) ?>)</span></h2>
<?php if (!$liste): ?>
  <p class="chapo">Rien pour l'instant.</p>
<?php endif; ?>
<?php foreach ($liste as $e): ?>
  <div class="carte">
    <h3><?= h($e['nom']) ?><?php
      $n = (int) ($e['nombre'] ?? 0);
      if (($e['type'] ?? 'fichier') === 'groupe' && $e['nom'] !== "$n fichiers") {
          echo ' <span class="meta">— ', $n, ' fichiers</span>';
      }
    ?></h3>
    <div class="meta">
      <span><?= h(taille_lisible((int) $e['taille'])) ?></span>
      <span>déposé le <?= h(date_fr($e['cree'])) ?></span>
      <span>expire le <?= h(date_fr($e['expire'])) ?></span>
      <span><?= (int) ($e['retraits'] ?? 0) ?> retrait<?= ((int) ($e['retraits'] ?? 0)) > 1 ? 's' : '' ?></span>
    </div>
    <?php if (!empty($e['note'])): ?>
      <div class="meta" style="margin-top:.3rem"><?= h($e['note']) ?></div>
    <?php endif; ?>
    <div class="lien-boite">
      <input readonly value="<?= h(lien_partage($e['jeton'])) ?>" onclick="this.select()">
    </div>
    <div class="actions">
      <form method="post" onsubmit="return confirm('Supprimer ce fichier du serveur ?')">
        <input type="hidden" name="action" value="supprimer_partage">
        <input type="hidden" name="csrf" value="<?= h(csrf()) ?>">
        <input type="hidden" name="jeton" value="<?= h($e['jeton']) ?>">
        <button class="danger">Supprimer</button>
      </form>
      <form method="post">
        <input type="hidden" name="action" value="prolonger">
        <input type="hidden" name="csrf" value="<?= h(csrf()) ?>">
        <input type="hidden" name="jeton" value="<?= h($e['jeton']) ?>">
        <button class="discret">+ 7 jours</button>
      </form>
    </div>
  </div>
<?php endforeach; ?>

<script>
/*
 * Dépôt par glisser-déposer, fichiers ou dossiers entiers.
 * Chaque fichier part dans sa propre requête : la limite du serveur s'applique
 * par fichier, et on peut montrer où on en est.
 * Sans JavaScript, le formulaire classique reste utilisable tel quel.
 */
(function () {
  var zone   = document.getElementById('depose');
  var champ  = document.getElementById('fichier');
  var liste  = document.getElementById('depose-liste');
  var form   = zone && zone.closest('form');
  var bouton = form && form.querySelector('button');
  if (!zone || !champ || !form) { return; }

  var choisis = [];   // { fichier, chemin }
  var csrf = form.querySelector('input[name=csrf]').value;

  function lisible(o) {
    var u = ['o', 'Ko', 'Mo', 'Go'], i = 0;
    while (o >= 1024 && i < 3) { o /= 1024; i++; }
    return (i ? o.toFixed(1) : o) + ' ' + u[i];
  }

  function mot(texte, detail) {
    var m = zone.querySelector('.depose-mot');
    if (m) { m.innerHTML = texte + (detail ? '<small>' + detail + '</small>' : ''); }
  }

  function montrer() {
    liste.innerHTML = '';
    var total = 0;
    choisis.forEach(function (c) {
      total += c.fichier.size;
      var li = document.createElement('li');
      var n = document.createElement('span'); n.textContent = c.chemin;
      var t = document.createElement('span'); t.className = 'meta'; t.textContent = lisible(c.fichier.size);
      li.appendChild(n); li.appendChild(t); liste.appendChild(li);
    });
    if (choisis.length > 1) {
      var tot = document.createElement('li');
      tot.className = 'total';
      tot.textContent = choisis.length + ' fichiers, ' + lisible(total) + ' en tout';
      liste.appendChild(tot);
    }
    zone.classList.toggle('rempli', choisis.length > 0);
    mot(choisis.length ? 'Prêt à partir' : 'Glisse tes fichiers ou un dossier ici',
        choisis.length ? '— clique pour changer' : 'ou clique pour les choisir');
  }

  /* ------------------------------------------------ lecture d'un dossier */

  function lireDossier(entree, prefixe) {
    return new Promise(function (fini) {
      var lecteur = entree.createReader();
      var tout = [];
      (function lot() {
        lecteur.readEntries(function (entrees) {
          if (!entrees.length) {
            Promise.all(tout).then(function (r) { fini(r.flat()); });
            return;
          }
          entrees.forEach(function (e) { tout.push(lire(e, prefixe + entree.name + '/')); });
          lot();
        }, function () { fini([]); });
      })();
    });
  }

  function lire(entree, prefixe) {
    if (entree.isDirectory) { return lireDossier(entree, prefixe); }
    return new Promise(function (fini) {
      entree.file(function (f) {
        fini(f.name === '.DS_Store' ? [] : [{ fichier: f, chemin: prefixe + f.name }]);
      }, function () { fini([]); });
    });
  }

  /* ----------------------------------------------------------- réception */

  zone.addEventListener('click', function () { champ.click(); });
  champ.addEventListener('change', function () {
    choisis = Array.prototype.map.call(champ.files, function (f) {
      return { fichier: f, chemin: f.webkitRelativePath || f.name };
    });
    montrer();
  });

  ['dragenter', 'dragover'].forEach(function (n) {
    zone.addEventListener(n, function (e) { e.preventDefault(); zone.classList.add('survol'); });
  });
  ['dragleave', 'drop'].forEach(function (n) {
    zone.addEventListener(n, function (e) { e.preventDefault(); zone.classList.remove('survol'); });
  });

  zone.addEventListener('drop', function (e) {
    var items = e.dataTransfer.items;
    if (!items || !items[0] || !items[0].webkitGetAsEntry) {
      choisis = Array.prototype.map.call(e.dataTransfer.files, function (f) {
        return { fichier: f, chemin: f.name };
      });
      montrer();
      return;
    }
    mot('Lecture du dossier…', '');
    var entrees = [];
    for (var i = 0; i < items.length; i++) {
      var en = items[i].webkitGetAsEntry();
      if (en) { entrees.push(lire(en, '')); }
    }
    Promise.all(entrees).then(function (r) {
      choisis = r.flat();
      montrer();
    });
  });

  /* ------------------------------------------------------- l'envoi lui-même */

  function poster(donnees) {
    return fetch('televerse.php', { method: 'POST', body: donnees, credentials: 'same-origin' })
      .then(function (r) { return r.json().then(function (j) { return r.ok ? j : Promise.reject(j.erreur || 'erreur'); }); });
  }

  form.addEventListener('submit', function (e) {
    if (!choisis.length) { return; }          // laisse le navigateur signaler le champ vide
    e.preventDefault();
    bouton.disabled = true;

    var jours = (form.querySelector('input[name=jours]:checked') || {}).value || 7;
    // Nom du lot : le dossier déposé, s'il y en a un.
    var racine = '';
    if (choisis[0].chemin.indexOf('/') > -1) {
      racine = choisis[0].chemin.split('/')[0];
      choisis.forEach(function (c) {
        if (c.chemin.split('/')[0] !== racine) { racine = ''; }
      });
    }

    var barre = document.createElement('div');
    barre.className = 'jauge';
    barre.innerHTML = '<div class="jauge-fond"><div class="jauge-avance"></div></div><p class="jauge-mot"></p>';
    zone.after(barre);
    var avance = barre.querySelector('.jauge-avance');
    var texte = barre.querySelector('.jauge-mot');

    var d = new FormData(); d.append('quoi', 'ouvrir'); d.append('csrf', csrf);
    poster(d).then(function (r) {
      var jeton = r.jeton;
      var i = 0;
      function suivant() {
        if (i >= choisis.length) {
          var f = new FormData();
          f.append('quoi', 'clore'); f.append('csrf', csrf); f.append('jeton', jeton);
          f.append('jours', jours); f.append('nom', racine);
          return poster(f).then(function (fin) {
            location.href = 'index.php?nouveau=' + encodeURIComponent(fin.jeton);
          });
        }
        var c = choisis[i];
        texte.textContent = (i + 1) + ' / ' + choisis.length + ' — ' + c.chemin;
        avance.style.width = Math.round(i / choisis.length * 100) + '%';
        var f = new FormData();
        f.append('quoi', 'piece'); f.append('csrf', csrf); f.append('jeton', jeton);
        // le nom du dossier déposé sert de titre : inutile de le répéter sur chaque ligne
        var chemin = racine ? c.chemin.slice(racine.length + 1) : c.chemin;
        f.append('chemin', chemin); f.append('fichier', c.fichier, c.fichier.name);
        return poster(f).then(function () { i++; return suivant(); });
      }
      return suivant();
    }).catch(function (err) {
      texte.textContent = 'Échec : ' + err;
      barre.classList.add('rate');
      bouton.disabled = false;
    });
  });

  champ.removeAttribute('required');
  montrer();
})();
</script>
