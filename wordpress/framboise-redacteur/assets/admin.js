/* Framboise Rédacteur – écran Rédiger et Réglages */
(function ($) {
  'use strict';

  var state = { seo: null, ideas: [], secondary: [], images: [], running: false, stop: false };

  function post(action, data) {
    return $.ajax({
      url: FRR.ajax,
      method: 'POST',
      timeout: 300000,
      data: $.extend({ action: action, nonce: FRR.nonce }, data)
    }).then(function (r) {
      if (r && r.success) { return r.data; }
      return $.Deferred().reject({ message: (r && r.data && r.data.message) || 'Erreur inconnue.' });
    }, function (xhr, status) {
      var m = status === 'timeout'
        ? 'Le serveur a mis trop de temps à répondre. Essayez une longueur plus courte.'
        : 'Le serveur a renvoyé une erreur (' + (xhr.status || status) + ').';
      if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) { m = xhr.responseJSON.data.message; }
      return $.Deferred().reject({ message: m });
    });
  }

  function el(tag, cls, text) {
    var e = document.createElement(tag);
    if (cls) { e.className = cls; }
    if (text !== undefined) { e.textContent = text; }
    return e;
  }

  function busy($btn, on, label) {
    if (on) {
      $btn.data('label', $btn.text()).prop('disabled', true).addClass('frr-busy').text(label || 'Patientez…');
    } else {
      $btn.prop('disabled', false).removeClass('frr-busy').text($btn.data('label') || $btn.text());
    }
  }

  /* ---------- Réglages : test de la clé ---------- */
  $('#frr-test').on('click', function () {
    var $b = $(this), $o = $('#frr-test-out');
    if ($('#frr-key').val()) { $o.text('Enregistrez d\'abord les réglages, puis testez.').attr('class', 'frr-note frr-err'); return; }
    if (!FRR.hasKey) { $o.text('Aucune clé enregistrée.').attr('class', 'frr-note frr-err'); return; }
    busy($b, true, 'Test en cours…');
    post('frr_test', {}).then(function (d) {
      $o.text(d.message).attr('class', 'frr-note frr-ok');
    }, function (e) {
      $o.text(e.message).attr('class', 'frr-note frr-err');
    }).always(function () { busy($b, false); });
  });

  if (!document.getElementById('frr-go')) { return; }

  /* ---------- Étape 1 : analyse SEO ---------- */
  function chip(text, selectable) {
    var c = el(selectable ? 'button' : 'span', 'frr-chip', text);
    if (selectable) {
      c.type = 'button';
      c.setAttribute('aria-pressed', 'false');
      c.addEventListener('click', function () {
        var on = c.getAttribute('aria-pressed') !== 'true';
        c.setAttribute('aria-pressed', on ? 'true' : 'false');
        var i = state.secondary.indexOf(text);
        if (on && i < 0) { state.secondary.push(text); }
        if (!on && i >= 0) { state.secondary.splice(i, 1); }
      });
    }
    return c;
  }

  function group(title, items, selectable) {
    var g = el('div', 'frr-group');
    g.appendChild(el('h3', '', title));
    var w = el('div', 'frr-chips');
    (items || []).forEach(function (t) { w.appendChild(chip(String(t), selectable)); });
    g.appendChild(w);
    return g;
  }

  function renderSeo(d) {
    var box = document.getElementById('frr-seo');
    box.innerHTML = '';
    state.seo = d;
    state.secondary = [];
    if (d.intention) {
      var p = el('p', 'frr-intent');
      p.appendChild(el('strong', '', 'Intention de recherche : '));
      p.appendChild(document.createTextNode(d.intention));
      box.appendChild(p);
    }
    if (d.mot_cle_principal) { box.appendChild(group('Mot-clé principal', [d.mot_cle_principal], false)); }
    var sec = group('Mots-clés secondaires (cliquez pour les imposer dans les articles)', d.mots_cles_secondaires, true);
    box.appendChild(sec);
    box.appendChild(group('Longue traîne', d.longue_traine, true));
    box.appendChild(group('Questions des internautes', d.questions, false));
    if (d.segments && d.segments.length) {
      var sg = el('div', 'frr-group');
      sg.appendChild(el('h3', '', 'Segments'));
      var ul = el('ul', 'frr-segments');
      d.segments.forEach(function (s) {
        var li = el('li');
        li.appendChild(el('strong', '', (s.nom || '') + ' : '));
        li.appendChild(document.createTextNode((s.mots_cles || []).join(', ')));
        ul.appendChild(li);
      });
      sg.appendChild(ul);
      box.appendChild(sg);
    }
    var ig = el('div', 'frr-group');
    ig.appendChild(el('h3', '', 'Sujets d\'articles proposés (cochez ceux à rédiger)'));
    var list = el('ul', 'frr-ideas');
    state.ideas = (d.idees || []).map(function (idea) {
      var li = el('li');
      var lab = el('label');
      var cb = el('input');
      cb.type = 'checkbox';
      cb.checked = true;
      cb.addEventListener('change', syncCount);
      var tx = el('span');
      var t = el('input', 'frr-idea-title');
      t.type = 'text';
      t.value = idea.titre || '';
      t.setAttribute('aria-label', 'Titre de l\'article');
      tx.appendChild(t);
      tx.appendChild(el('small', '', 'Mot-clé : ' + (idea.mot_cle || '') + (idea.angle ? ' · ' + idea.angle : '')));
      lab.appendChild(cb);
      lab.appendChild(tx);
      li.appendChild(lab);
      list.appendChild(li);
      return { cb: cb, input: t, keyword: idea.mot_cle || '', angle: idea.angle || '' };
    });
    ig.appendChild(list);
    box.appendChild(ig);
    box.hidden = false;
    syncCount();
  }

  function checkedIdeas() {
    return state.ideas.filter(function (i) { return i.cb.checked; }).map(function (i) {
      return { title: i.input.value.trim(), keyword: i.keyword, angle: i.angle };
    });
  }

  function syncCount() {
    var n = checkedIdeas().length;
    if (n) { $('#frr-count').val(n); }
    planSummary();
  }

  $('#frr-analyze').on('click', function () {
    var $b = $(this), $n = $('#frr-analyze-note');
    var theme = $('#frr-theme').val().trim(), kw = $('#frr-kw').val().trim();
    if (!theme && !kw) { $n.text('Indiquez une thématique ou des mots-clés.').attr('class', 'frr-note frr-err'); return; }
    busy($b, true, 'Analyse en cours…');
    $n.text('Environ 20 à 40 secondes.').attr('class', 'frr-note');
    post('frr_analyze', { theme: theme, keywords: kw, count: Math.max(3, parseInt($('#frr-count').val(), 10) || 3) })
      .then(function (d) { renderSeo(d); $n.text(''); }, function (e) { $n.text(e.message).attr('class', 'frr-note frr-err'); })
      .always(function () { busy($b, false); });
  });

  /* ---------- Étape 3 : images ---------- */
  var frame = null;
  function renderThumbs() {
    var ul = document.getElementById('frr-thumbs');
    ul.innerHTML = '';
    state.images.forEach(function (img, i) {
      var li = el('li');
      var im = el('img');
      im.src = img.url;
      im.alt = img.alt || '';
      li.appendChild(im);
      if (i === 0) { li.appendChild(el('span', 'frr-tag', 'À la une')); }
      var bar = el('div', 'frr-thumb-bar');
      var crop = el('a', '', 'Recadrer');
      crop.href = img.edit;
      crop.target = '_blank';
      crop.rel = 'noopener';
      var rm = el('button', 'button-link', 'Retirer');
      rm.type = 'button';
      rm.addEventListener('click', function () { state.images.splice(i, 1); renderThumbs(); });
      bar.appendChild(crop);
      bar.appendChild(rm);
      li.appendChild(bar);
      ul.appendChild(li);
    });
    $('#frr-images-clear').prop('hidden', !state.images.length);
  }
  $('#frr-images').on('click', function () {
    if (!window.wp || !wp.media) { return; }
    if (!frame) {
      frame = wp.media({ title: 'Choisir les images des articles', button: { text: 'Utiliser ces images' }, library: { type: 'image' }, multiple: 'add' });
      frame.on('open', function () { try { frame.content.mode('browse'); } catch (e) {} });
      frame.on('select', function () {
        frame.state().get('selection').each(function (a) {
          var j = a.toJSON();
          if (state.images.some(function (x) { return x.id === j.id; })) { return; }
          var u = (j.sizes && (j.sizes.medium || j.sizes.thumbnail || j.sizes.full)) || j;
          state.images.push({ id: j.id, url: u.url, alt: j.alt, edit: j.editLink || ('post.php?post=' + j.id + '&action=edit') });
        });
        renderThumbs();
      });
    }
    frame.open();
  });
  $('#frr-images-clear').on('click', function () { state.images = []; renderThumbs(); });

  /* ---------- Étape 4 : publication ---------- */
  function pad(n) { return (n < 10 ? '0' : '') + n; }
  (function defaultStart() {
    var d = new Date();
    d.setDate(d.getDate() + 1);
    $('#frr-start').val(d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()));
  })();

  function slotDate(i) {
    var s = $('#frr-start').val(), h = $('#frr-hour').val() || '09:00';
    if (!s) { return ''; }
    var p = s.split('-');
    var d = new Date(+p[0], +p[1] - 1, +p[2]);
    d.setDate(d.getDate() + i * (parseInt($('#frr-every').val(), 10) || 2));
    return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + ' ' + h;
  }
  function human(str) {
    if (!str) { return ''; }
    var p = str.split(/[- :]/);
    var d = new Date(+p[0], +p[1] - 1, +p[2], +p[3], +p[4]);
    return d.toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long' }) + ' à ' + p[3] + 'h' + p[4];
  }
  function mode() { return $('input[name="frr-mode"]:checked').val(); }
  function planSummary() {
    var n = Math.max(1, parseInt($('#frr-count').val(), 10) || 1);
    if (mode() !== 'plan') { return; }
    var first = slotDate(0), last = slotDate(n - 1);
    $('#frr-plan-summary').text(n === 1
      ? 'Publication prévue le ' + human(first) + '.'
      : n + ' articles, du ' + human(first) + ' au ' + human(last) + '.');
  }
  $('input[name="frr-mode"]').on('change', function () {
    var plan = mode() === 'plan';
    $('#frr-plan').prop('hidden', !plan);
    $('#frr-now').prop('hidden', plan);
    planSummary();
  });
  $('#frr-start, #frr-hour, #frr-every, #frr-count').on('change input', planSummary);

  /* ---------- Rédaction ---------- */
  function addResult(job, i) {
    var li = el('li', 'frr-pending');
    var t = el('span', 'frr-r-title', job.title || job.keyword || 'Article ' + (i + 1));
    var s = el('span', 'frr-r-state', 'En attente');
    li.appendChild(t);
    li.appendChild(s);
    document.getElementById('frr-results').appendChild(li);
    job.li = li;
    job.stateEl = s;
    job.titleEl = t;
  }

  function payload(job, i, total) {
    var ids = state.images.map(function (x) { return x.id; });
    var imgs = total === 1 ? ids : (ids.length ? [ids[i % ids.length]] : []);
    var plan = mode() === 'plan';
    return {
      title: job.title,
      keyword: job.keyword,
      angle: job.angle,
      secondary: state.secondary.length ? state.secondary.join(', ') : $('#frr-kw').val().trim(),
      theme: $('#frr-theme').val().trim(),
      length: $('#frr-length').val(),
      tone: $('#frr-tone').val(),
      category: $('#frr-cat').val(),
      links: $('#frr-links').is(':checked') ? '1' : '0',
      target: $('#frr-target').is(':checked') ? '1' : '0',
      status: plan ? 'future' : $('#frr-status').val(),
      date: plan ? slotDate(i) : '',
      images: imgs
    };
  }

  var LABELS = { draft: 'Brouillon', pending: 'En attente', publish: 'Publié', future: 'Programmé' };

  function runJob(job, i, total) {
    job.li.className = 'frr-running';
    job.stateEl.textContent = 'Rédaction en cours…';
    return post('frr_write', payload(job, i, total)).then(function (d) {
      job.li.className = 'frr-done';
      job.titleEl.textContent = '';
      var a = el('a', '', d.title);
      a.href = d.edit;
      a.target = '_blank';
      a.rel = 'noopener';
      job.titleEl.appendChild(a);
      job.stateEl.textContent = (LABELS[d.status] || d.status) + (d.status === 'future' ? ' le ' + d.date : '') + ' · ' + d.words + ' mots' + (d.truncated ? ' · texte coupé, à relire' : '');
      var v = el('a', 'frr-r-view', 'Voir');
      v.href = d.view;
      v.target = '_blank';
      v.rel = 'noopener';
      job.li.appendChild(v);
      return true;
    }, function (e) {
      job.li.className = 'frr-failed';
      job.stateEl.textContent = e.message;
      var r = el('button', 'button button-small', 'Relancer');
      r.type = 'button';
      r.addEventListener('click', function () {
        r.remove();
        runJob(job, i, total);
      });
      job.li.appendChild(r);
      return $.Deferred().resolve(false);
    });
  }

  function progress(done, total) {
    $('#frr-bar').css('width', Math.round(done / total * 100) + '%');
    $('#frr-progress-note').text(done < total ? 'Article ' + (done + 1) + ' sur ' + total + '. Gardez cette page ouverte.' : 'Terminé. Les articles s\'ouvrent dans l\'éditeur WordPress pour relecture.');
  }

  function start(jobs) {
    var total = jobs.length, i = 0;
    state.running = true;
    state.stop = false;
    $('#frr-results').empty();
    jobs.forEach(addResult);
    $('#frr-progress').prop('hidden', false);
    $('#frr-stop').prop('hidden', false);
    busy($('#frr-go'), true, 'Rédaction en cours…');
    progress(0, total);
    (function next() {
      if (i >= total || state.stop) {
        state.running = false;
        busy($('#frr-go'), false);
        $('#frr-stop').prop('hidden', true);
        progress(total, total);
        if (state.stop && i < total) { $('#frr-progress-note').text('Arrêté. ' + i + ' article(s) traité(s).'); }
        return;
      }
      runJob(jobs[i], i, total).always(function () { i++; progress(i, total); next(); });
    })();
  }

  $('#frr-stop').on('click', function () { state.stop = true; $(this).prop('hidden', true); });
  window.addEventListener('beforeunload', function (e) { if (state.running) { e.preventDefault(); e.returnValue = ''; } });

  $('#frr-go').on('click', function () {
    var $b = $(this);
    var total = Math.max(1, Math.min(15, parseInt($('#frr-count').val(), 10) || 1));
    var theme = $('#frr-theme').val().trim(), kw = $('#frr-kw').val().trim();
    if (!FRR.hasKey) { window.alert('Ajoutez d\'abord votre clé API dans Réglages.'); return; }
    if (!theme && !kw && !checkedIdeas().length) { $('#frr-theme').focus(); $('#frr-analyze-note').text('Indiquez une thématique ou des mots-clés.').attr('class', 'frr-note frr-err'); return; }
    if (mode() === 'plan' && !$('#frr-start').val()) { $('#frr-start').focus(); return; }

    var ideas = checkedIdeas();
    if (ideas.length >= total || (total === 1 && !ideas.length)) {
      var jobs = ideas.length ? ideas.slice(0, total) : [{ title: '', keyword: kw ? kw.split(',')[0].trim() : theme, angle: '' }];
      start(jobs);
      return;
    }
    // Not enough subjects yet: ask the SEO analysis for them first.
    busy($b, true, 'Choix des sujets…');
    post('frr_analyze', { theme: theme, keywords: kw, count: total })
      .then(function (d) {
        busy($b, false);
        renderSeo(d);
        start(checkedIdeas().slice(0, total));
      }, function (e) {
        busy($b, false);
        $('#frr-analyze-note').text(e.message).attr('class', 'frr-note frr-err');
      });
  });
})(jQuery);
