/* Framboise CRM – interface (vanilla JS, une page) */
(function () {
  'use strict';

  var root = document.getElementById('frcrm-app');
  if (!root || !window.FRCRM) { return; }
  var B = null;            // boot data
  var dirty = false;       // unsaved quote/client edits

  /* ------------------------------------------------------------------ */
  /* Helpers                                                            */
  /* ------------------------------------------------------------------ */

  function api(method, path, body) {
    return fetch(FRCRM.rest + path, {
      method: method,
      credentials: 'same-origin',
      headers: { 'X-WP-Nonce': FRCRM.nonce, 'Content-Type': 'application/json' },
      body: body ? JSON.stringify(body) : undefined
    }).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (j) {
        if (!r.ok) { throw new Error((j && j.message) || ('Erreur ' + r.status)); }
        return j;
      });
    });
  }

  function h(tag, attrs) {
    var e = document.createElement(tag);
    if (attrs) {
      Object.keys(attrs).forEach(function (k) {
        var v = attrs[k];
        if (v === null || v === undefined || v === false) { return; }
        if (k === 'class') { e.className = v; }
        else if (k === 'text') { e.textContent = v; }
        else if (k.slice(0, 2) === 'on') { e.addEventListener(k.slice(2), v); }
        else if (k === 'value') { e.value = v; }
        else if (k === 'checked') { e.checked = !!v; }
        else { e.setAttribute(k, v === true ? '' : v); }
      });
    }
    for (var i = 2; i < arguments.length; i++) { add(e, arguments[i]); }
    return e;
  }
  function add(parent, c) {
    if (c === null || c === undefined || c === false) { return; }
    if (Array.isArray(c)) { c.forEach(function (x) { add(parent, x); }); return; }
    parent.appendChild(typeof c === 'object' ? c : document.createTextNode(String(c)));
  }

  var EUR = new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'EUR' });
  function money(v) { return EUR.format(+v || 0); }
  function fdate(d) {
    if (!d) { return ''; }
    var p = String(d).slice(0, 10).split('-');
    return new Date(+p[0], +p[1] - 1, +p[2]).toLocaleDateString('fr-FR', { day: 'numeric', month: 'short', year: 'numeric' });
  }
  function fdt(d) {
    if (!d) { return ''; }
    var x = new Date(String(d).replace(' ', 'T'));
    return x.toLocaleDateString('fr-FR', { day: 'numeric', month: 'short' }) + ' ' + x.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });
  }
  function today() {
    var d = new Date();
    return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
  }
  function num(v) { return parseFloat(String(v).replace(/\s/g, '').replace(',', '.')) || 0; }

  function toast(msg, bad) {
    var t = h('div', { class: 'frcrm-toast' + (bad ? ' bad' : ''), role: 'status', text: msg });
    document.body.appendChild(t);
    setTimeout(function () { t.remove(); }, 3200);
  }
  function fail(e) { toast(e.message || String(e), true); }

  function stLabel(k) { return (B.statuses[k] || [k])[0]; }
  function stColor(k) { return (B.statuses[k] || [, 'grey'])[1]; }
  function stPill(k) { return h('span', { class: 'frcrm-pill c-' + stColor(k), text: stLabel(k) }); }
  function qPill(k) { return h('span', { class: 'frcrm-pill q-' + k, text: B.quoteStatuses[k] || k }); }
  function entityName(k) { var e = B.entities.filter(function (x) { return x.key === k; })[0]; return e ? e.name : ''; }

  function field(label, input, hint) {
    return h('label', { class: 'frcrm-field' }, h('span', { text: label }), input, hint ? h('small', { text: hint }) : null);
  }
  function input(val, attrs) { return h('input', Object.assign({ type: 'text', value: val == null ? '' : val }, attrs || {})); }
  function area(val, rows, attrs) { var t = h('textarea', Object.assign({ rows: rows || 3 }, attrs || {})); t.value = val || ''; return t; }
  function select(options, val, attrs) {
    var s = h('select', attrs || {});
    options.forEach(function (o) {
      var v = Array.isArray(o) ? o[0] : o, l = Array.isArray(o) ? o[1] : o;
      s.appendChild(h('option', { value: v, text: l, selected: String(v) === String(val) ? true : null }));
    });
    return s;
  }
  function btn(label, onclick, cls) { return h('button', { type: 'button', class: 'frcrm-btn ' + (cls || ''), onclick: onclick, text: label }); }
  function busy(b, on, label) {
    if (on) { b.dataset.l = b.textContent; b.textContent = label || 'Patientez…'; b.disabled = true; }
    else { b.textContent = b.dataset.l || b.textContent; b.disabled = false; }
  }
  function statusOptions() { return Object.keys(B.statuses).map(function (k) { return [k, B.statuses[k][0]]; }); }
  function card(title, body, actions) {
    return h('section', { class: 'frcrm-card' }, title ? h('div', { class: 'frcrm-card-h' }, h('h2', { text: title }), actions || null) : null, body);
  }
  function empty(t) { return h('p', { class: 'frcrm-empty', text: t }); }

  /* ------------------------------------------------------------------ */
  /* Shell and router                                                   */
  /* ------------------------------------------------------------------ */

  var NAV = [['/dashboard', 'Tableau de bord'], ['/clients', 'Clients'], ['/quotes', 'Devis'], ['/services', 'Prestations'], ['/settings', 'Réglages']];
  var view;

  function shell() {
    root.innerHTML = '';
    var nav = h('nav', { class: 'frcrm-nav', 'aria-label': 'Sections du CRM' });
    NAV.forEach(function (n) { nav.appendChild(h('a', { href: '#' + n[0], 'data-r': n[0], text: n[1] })); });
    root.appendChild(h('header', { class: 'frcrm-top' },
      h('div', { class: 'frcrm-brand' }, h('b', { text: 'Framboise' }), ' CRM'),
      nav,
      h('div', { class: 'frcrm-top-act' }, btn('+ Nouveau client', function () { location.hash = '#/client/new'; }, 'primary'))));
    view = h('div', { class: 'frcrm-view' });
    root.appendChild(view);
  }

  function route() {
    var hsh = location.hash.replace(/^#/, '') || '/dashboard';
    var parts = hsh.split('?')[0].split('/').filter(Boolean);
    var qs = new URLSearchParams(hsh.split('?')[1] || '');
    root.querySelectorAll('.frcrm-nav a').forEach(function (a) {
      var on = hsh.indexOf(a.dataset.r) === 0 || (a.dataset.r === '/clients' && parts[0] === 'client') || (a.dataset.r === '/quotes' && parts[0] === 'quote');
      a.setAttribute('aria-current', on ? 'page' : 'false');
    });
    dirty = false;
    view.innerHTML = '';
    view.appendChild(h('p', { class: 'frcrm-loading', text: 'Chargement…' }));
    window.scrollTo(0, 0);
    var p = parts[0];
    if (p === 'clients') { return vClients(qs); }
    if (p === 'client') { return parts[1] === 'new' ? vClientForm(null) : (parts[2] === 'edit' ? vClientEdit(+parts[1]) : vClient(+parts[1], qs)); }
    if (p === 'quotes') { return vQuotes(); }
    if (p === 'quote') { return vQuote(parts[1] === 'new' ? null : +parts[1], qs); }
    if (p === 'services') { return vServices(); }
    if (p === 'settings') { return vSettings(); }
    return vDashboard();
  }

  var lastHash = location.hash;
  window.addEventListener('hashchange', function () {
    if (dirty && !window.confirm('Des modifications ne sont pas enregistrées. Quitter quand même ?')) {
      history.replaceState(null, '', lastHash);
      return;
    }
    lastHash = location.hash;
    route();
  });
  window.addEventListener('beforeunload', function (e) { if (dirty) { e.preventDefault(); e.returnValue = ''; } });

  function render() { view.innerHTML = ''; for (var i = 0; i < arguments.length; i++) { add(view, arguments[i]); } }
  function title(t, sub, actions) {
    return h('div', { class: 'frcrm-title' }, h('div', null, h('h1', { text: t }), sub ? h('p', { class: 'frcrm-sub' }, sub) : null), actions ? h('div', { class: 'frcrm-actions' }, actions) : null);
  }

  /* ------------------------------------------------------------------ */
  /* Dashboard                                                          */
  /* ------------------------------------------------------------------ */

  function vDashboard() {
    api('GET', '/dashboard').then(function (d) {
      var active = 0, total = 0;
      Object.keys(d.byStatus).forEach(function (k) { total += d.byStatus[k]; if (['perdu', 'livre', 'pause', 'prospect'].indexOf(k) < 0) { active += d.byStatus[k]; } });
      var max = Math.max.apply(null, Object.keys(d.byStatus).map(function (k) { return d.byStatus[k]; }).concat([1]));
      var kpis = h('div', { class: 'frcrm-kpis' },
        kpi('Clients en cours', active, total + (total > 1 ? ' fiches' : ' fiche') + ' au total'),
        kpi('Devis en attente', money(d.pipeline), 'HT, envoyés sans réponse'),
        kpi('Signé cette année', money(d.signed), 'HT, devis acceptés'),
        kpi('Taux de transformation', d.rate === null ? '–' : d.rate + ' %', 'devis acceptés / envoyés'));

      var funnel = h('div', { class: 'frcrm-funnel' });
      Object.keys(B.statuses).forEach(function (k) {
        funnel.appendChild(h('a', { class: 'frcrm-frow', href: '#/clients?status=' + k },
          h('span', { text: stLabel(k) }),
          h('span', { class: 'frcrm-fbar' }, h('i', { class: 'c-' + stColor(k), style: 'width:' + Math.round(d.byStatus[k] / max * 100) + '%' })),
          h('b', { text: d.byStatus[k] })));
      });

      render(
        title('Tableau de bord', 'Bonjour ' + FRCRM.user + ', voici où en sont vos clients.'),
        kpis,
        h('div', { class: 'frcrm-grid2' },
          card('À faire', todoList(d.todo, vDashboard)),
          card('Clients par état', funnel)),
        card('Activité récente', activityList(d.activity, true)));
    }).catch(fail);
  }
  function kpi(label, value, sub) { return h('div', { class: 'frcrm-kpi' }, h('span', { text: label }), h('b', { text: value }), h('small', { text: sub })); }

  function todoList(t, refresh) {
    var box = h('div', { class: 'frcrm-todo' });
    var n = 0;
    if (t.actions.length) {
      box.appendChild(h('h3', { text: 'Actions issues des comptes-rendus' }));
      t.actions.forEach(function (a) {
        n++;
        var cb = h('input', { type: 'checkbox', 'aria-label': 'Marquer comme fait' });
        cb.addEventListener('change', function () {
          api('POST', '/reports/' + a.report_id + '/action', { index: a.index, done: cb.checked }).then(function () { toast('Action cochée'); refresh(); }).catch(fail);
        });
        box.appendChild(h('div', { class: 'frcrm-todo-i' + (a.late ? ' late' : '') }, cb,
          h('div', null, h('a', { href: '#/client/' + a.client_id, text: a.company }), ' : ', a.text, a.who ? ' (' + a.who + ')' : ''),
          h('span', { class: 'frcrm-when', text: (a.late ? 'En retard · ' : '') + fdate(a.due) })));
      });
    }
    if (t.quotes.length) {
      box.appendChild(h('h3', { text: 'Devis à relancer' }));
      t.quotes.forEach(function (q) {
        n++;
        box.appendChild(h('div', { class: 'frcrm-todo-i' }, h('span', { class: 'frcrm-dot' }),
          h('div', null, h('a', { href: '#/quote/' + q.id, text: q.number }), ' · ', q.company, ' · ', money(q.total_ht)),
          h('span', { class: 'frcrm-when', text: 'expire le ' + fdate(q.valid_until) })));
      });
    }
    if (t.followup.length) {
      box.appendChild(h('h3', { text: 'Relances prévues' }));
      t.followup.forEach(function (c) {
        n++;
        box.appendChild(h('div', { class: 'frcrm-todo-i' + (c.next_date < today() ? ' late' : '') }, h('span', { class: 'frcrm-dot' }),
          h('div', null, h('a', { href: '#/client/' + c.id, text: c.company }), ' : ', c.next_action || 'relance'),
          h('span', { class: 'frcrm-when', text: fdate(c.next_date) })));
      });
    }
    if (t.stale.length) {
      box.appendChild(h('h3', { text: 'Sans nouvelles depuis un moment' }));
      t.stale.forEach(function (c) {
        n++;
        box.appendChild(h('div', { class: 'frcrm-todo-i' }, h('span', { class: 'frcrm-dot' }),
          h('div', null, h('a', { href: '#/client/' + c.id, text: c.company }), ' ', stPill(c.status)),
          h('span', { class: 'frcrm-when', text: 'dernière activité ' + fdate(c.updated_at) })));
      });
    }
    if (!n) { box.appendChild(empty('Rien d\'urgent. Les actions des comptes-rendus, les devis à relancer et les relances prévues apparaîtront ici.')); }
    return box;
  }

  function activityList(rows, withClient) {
    if (!rows || !rows.length) { return empty('Aucune activité pour l\'instant.'); }
    var ul = h('ul', { class: 'frcrm-activity' });
    rows.forEach(function (a) {
      ul.appendChild(h('li', { class: 't-' + a.type },
        h('span', { class: 'frcrm-when', text: fdt(a.created_at) }),
        h('span', null, withClient && a.company ? [h('a', { href: '#/client/' + a.client_id, text: a.company }), ' · '] : null, a.message)));
    });
    return ul;
  }

  /* ------------------------------------------------------------------ */
  /* Clients list and Kanban                                            */
  /* ------------------------------------------------------------------ */

  var clientMode = 'list';
  try { clientMode = localStorage.getItem('frcrm-mode') || 'list'; } catch (e) {}

  function vClients(qs) {
    var search = qs.get('q') || '', status = qs.get('status') || '';
    api('GET', '/clients' + (search ? '?search=' + encodeURIComponent(search) : '')).then(function (rows) {
      var s = input(search, { type: 'search', placeholder: 'Rechercher un client, un contact, un mot de la problématique…', class: 'frcrm-search' });
      var timer;
      s.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(function () { history.replaceState(null, '', '#/clients' + (s.value ? '?q=' + encodeURIComponent(s.value) : '')); lastHash = location.hash; vClients(new URLSearchParams(s.value ? 'q=' + s.value : '')); }, 350); });
      var stSel = select([['', 'Tous les états']].concat(statusOptions()), status, { 'aria-label': 'Filtrer par état' });
      var mode = h('div', { class: 'frcrm-seg', role: 'group', 'aria-label': 'Affichage' },
        h('button', { type: 'button', 'aria-pressed': clientMode === 'list' ? 'true' : 'false', text: 'Liste', onclick: function () { setMode('list'); } }),
        h('button', { type: 'button', 'aria-pressed': clientMode === 'kanban' ? 'true' : 'false', text: 'Tableau par état', onclick: function () { setMode('kanban'); } }));
      function setMode(m) { clientMode = m; try { localStorage.setItem('frcrm-mode', m); } catch (e) {} vClients(qs); }

      var body = h('div');
      function draw() {
        body.innerHTML = '';
        var list = stSel.value ? rows.filter(function (r) { return r.status === stSel.value; }) : rows;
        if (clientMode === 'kanban') { body.appendChild(kanban(rows)); return; }
        if (!list.length) { body.appendChild(empty(rows.length ? 'Aucun client dans cet état.' : 'Aucun client pour l\'instant. Ajoutez le premier avec « Nouveau client ».')); return; }
        var tb = h('tbody');
        list.forEach(function (c) {
          var st = select(statusOptions(), c.status, { 'aria-label': 'État de ' + c.company, class: 'frcrm-st c-' + stColor(c.status) });
          st.addEventListener('change', function () {
            api('POST', '/clients/' + c.id + '/status', { status: st.value }).then(function () { c.status = st.value; st.className = 'frcrm-st c-' + stColor(c.status); toast('État mis à jour'); }).catch(fail);
          });
          tb.appendChild(h('tr', null,
            h('td', null, h('a', { href: '#/client/' + c.id, class: 'frcrm-strong', text: c.company }), c.sector ? h('small', { text: c.sector }) : null),
            h('td', null, c.contact || '', c.contact_email ? h('small', null, h('a', { href: 'mailto:' + c.contact_email, text: c.contact_email })) : null),
            h('td', null, st),
            h('td', { class: 'n' }, +c.open_quotes ? money(c.open_quotes) : '–'),
            h('td', null, c.next_action || '', c.next_date ? h('small', { class: c.next_date < today() ? 'late' : '', text: fdate(c.next_date) }) : null),
            h('td', null, h('small', { text: fdate(c.updated_at) }))));
        });
        body.appendChild(h('div', { class: 'frcrm-table-wrap' }, h('table', { class: 'frcrm-table' },
          h('thead', null, h('tr', null, h('th', { text: 'Client' }), h('th', { text: 'Contact principal' }), h('th', { text: 'État' }), h('th', { class: 'n', text: 'Devis en attente' }), h('th', { text: 'Prochaine action' }), h('th', { text: 'Mis à jour' }))),
          tb)));
      }
      stSel.addEventListener('change', draw);
      render(
        title('Clients', rows.length + ' fiche' + (rows.length > 1 ? 's' : ''), btn('+ Nouveau client', function () { location.hash = '#/client/new'; }, 'primary')),
        h('div', { class: 'frcrm-toolbar' }, s, clientMode === 'list' ? stSel : null, mode),
        body);
      draw();
      if (search) { s.focus(); s.setSelectionRange(s.value.length, s.value.length); }
    }).catch(fail);
  }

  function kanban(rows) {
    var board = h('div', { class: 'frcrm-kanban' });
    Object.keys(B.statuses).forEach(function (k) {
      var items = rows.filter(function (r) { return r.status === k; });
      var col = h('div', { class: 'frcrm-col', 'data-st': k },
        h('div', { class: 'frcrm-col-h c-' + stColor(k) }, h('span', { text: stLabel(k) }), h('b', { text: items.length })));
      var list = h('div', { class: 'frcrm-col-b' });
      items.forEach(function (c) {
        var cardEl = h('a', { class: 'frcrm-kcard', href: '#/client/' + c.id, draggable: 'true', 'data-id': c.id },
          h('b', { text: c.company }),
          c.contact ? h('small', { text: c.contact }) : null,
          +c.open_quotes ? h('small', { class: 'frcrm-amount', text: money(c.open_quotes) + ' HT' }) : null,
          c.next_date ? h('small', { class: c.next_date < today() ? 'late' : '', text: (c.next_action || 'Relance') + ' · ' + fdate(c.next_date) }) : null);
        cardEl.addEventListener('dragstart', function (e) { e.dataTransfer.setData('text/plain', c.id); cardEl.classList.add('drag'); });
        cardEl.addEventListener('dragend', function () { cardEl.classList.remove('drag'); });
        list.appendChild(cardEl);
      });
      col.addEventListener('dragover', function (e) { e.preventDefault(); col.classList.add('over'); });
      col.addEventListener('dragleave', function () { col.classList.remove('over'); });
      col.addEventListener('drop', function (e) {
        e.preventDefault();
        col.classList.remove('over');
        var id = +e.dataTransfer.getData('text/plain');
        var c = rows.filter(function (r) { return +r.id === id; })[0];
        if (!c || c.status === k) { return; }
        api('POST', '/clients/' + id + '/status', { status: k }).then(function () {
          c.status = k;
          var parent = board.parentNode;
          parent.replaceChild(kanban(rows), board);
          toast(c.company + ' : ' + stLabel(k));
        }).catch(fail);
      });
      col.appendChild(list);
      board.appendChild(col);
    });
    return h('div', null, h('p', { class: 'frcrm-hint', text: 'Glissez une carte d\'une colonne à l\'autre pour changer l\'état du client.' }), board);
  }

  /* ------------------------------------------------------------------ */
  /* Client form                                                        */
  /* ------------------------------------------------------------------ */

  function vClientEdit(id) { api('GET', '/clients/' + id).then(vClientForm).catch(fail); }

  function vClientForm(c) {
    var isNew = !c;
    c = c || { contacts: [{}], status: 'prospect', entity: B.defaultEntity };
    if (!c.contacts.length) { c.contacts = [{}]; }
    var f = {
      company: input(c.company, { placeholder: 'Ex. : Maison Lumière', required: true }),
      sector: select([['', '–']].concat(B.sectors), c.sector),
      source: select([['', '–']].concat(B.sources), c.source),
      status: select(statusOptions(), c.status),
      entity: select(B.entities.map(function (e) { return [e.key, e.name]; }), c.entity || B.defaultEntity),
      website: input(c.website, { type: 'url', placeholder: 'https://' }),
      address: area(c.address, 2),
      budget: input(c.budget, { placeholder: 'Ex. : 8 à 12 k€' }),
      problem: area(c.problem, 5, { placeholder: 'Ce que vit le client, ce qu\'il cherche à résoudre, ses enjeux, ses délais…' }),
      next_action: input(c.next_action, { placeholder: 'Ex. : rappeler pour fixer l\'atelier' }),
      next_date: input(c.next_date, { type: 'date' }),
      notes: area(c.notes, 3)
    };
    if (!isNew) { f.status.disabled = true; f.status.title = 'L\'état se change depuis la fiche ou le tableau.'; }
    Object.keys(f).forEach(function (k) { f[k].addEventListener('input', function () { dirty = true; }); });

    var contacts = h('div', { class: 'frcrm-contacts' });
    function contactRow(k, i) {
      var row = h('div', { class: 'frcrm-contact-row' });
      var fields = {
        first_name: input(k.first_name, { placeholder: 'Prénom', 'aria-label': 'Prénom' }),
        last_name: input(k.last_name, { placeholder: 'Nom', 'aria-label': 'Nom' }),
        role: input(k.role, { placeholder: 'Fonction', 'aria-label': 'Fonction' }),
        email: input(k.email, { type: 'email', placeholder: 'E-mail', 'aria-label': 'E-mail' }),
        phone: input(k.phone, { type: 'tel', placeholder: 'Téléphone', 'aria-label': 'Téléphone' })
      };
      row._get = function () { var o = { id: k.id }; Object.keys(fields).forEach(function (n) { o[n] = fields[n].value.trim(); }); return o; };
      Object.keys(fields).forEach(function (n) { row.appendChild(fields[n]); fields[n].addEventListener('input', function () { dirty = true; }); });
      row.appendChild(h('span', { class: 'frcrm-main', text: i === 0 ? 'Principal' : '' }));
      row.appendChild(h('button', { type: 'button', class: 'frcrm-icon', 'aria-label': 'Retirer ce contact', text: '×', onclick: function () { row.remove(); dirty = true; relabel(); } }));
      return row;
    }
    function relabel() { contacts.querySelectorAll('.frcrm-main').forEach(function (m, i) { m.textContent = i === 0 ? 'Principal' : ''; }); }
    c.contacts.forEach(function (k, i) { contacts.appendChild(contactRow(k, i)); });

    function save(b) {
      var body = {};
      Object.keys(f).forEach(function (k) { body[k] = f[k].value; });
      body.contacts = [].map.call(contacts.querySelectorAll('.frcrm-contact-row'), function (r) { return r._get(); });
      busy(b, true, 'Enregistrement…');
      api(isNew ? 'POST' : 'PUT', '/clients' + (isNew ? '' : '/' + c.id), body).then(function (saved) {
        dirty = false;
        toast('Fiche enregistrée');
        location.hash = '#/client/' + saved.id;
      }).catch(function (e) { busy(b, false); fail(e); });
    }
    var saveBtn = btn(isNew ? 'Créer la fiche' : 'Enregistrer', function () { save(saveBtn); }, 'primary');

    render(
      title(isNew ? 'Nouveau client' : 'Modifier ' + c.company, null, [isNew ? null : btn('Annuler', function () { dirty = false; location.hash = '#/client/' + c.id; }), saveBtn]),
      h('div', { class: 'frcrm-grid2' },
        card('Société', h('div', { class: 'frcrm-form' },
          field('Nom du client ou de la société', f.company),
          h('div', { class: 'frcrm-row' }, field('Secteur', f.sector), field('Origine du contact', f.source)),
          h('div', { class: 'frcrm-row' }, field('État', f.status), field('Structure qui suit le client', f.entity)),
          field('Site internet', f.website),
          field('Adresse (pour les devis)', f.address))),
        card('Problématique', h('div', { class: 'frcrm-form' },
          field('Problématique du client', f.problem),
          field('Budget annoncé', f.budget),
          h('div', { class: 'frcrm-row' }, field('Prochaine action', f.next_action), field('Pour le', f.next_date)),
          field('Notes internes', f.notes)))),
      card('Contacts', h('div', null, contacts, btn('+ Ajouter un contact', function () { contacts.appendChild(contactRow({}, contacts.children.length)); dirty = true; }, 'ghost')))
    );
    if (isNew) { f.company.focus(); }
  }

  /* ------------------------------------------------------------------ */
  /* Client page                                                        */
  /* ------------------------------------------------------------------ */

  function vClient(id, qs) {
    api('GET', '/clients/' + id).then(function (c) {
      var tab = qs.get('tab') || 'quotes';
      var st = select(statusOptions(), c.status, { 'aria-label': 'État du client', class: 'frcrm-st big c-' + stColor(c.status) });
      st.addEventListener('change', function () {
        api('POST', '/clients/' + c.id + '/status', { status: st.value }).then(function () { toast('État : ' + stLabel(st.value)); vClient(id, qs); }).catch(fail);
      });

      var info = h('dl', { class: 'frcrm-dl' });
      [['Secteur', c.sector], ['Origine', c.source], ['Suivi par', entityName(c.entity)], ['Budget', c.budget], ['Site', c.website ? h('a', { href: c.website, target: '_blank', rel: 'noopener', text: c.website.replace(/^https?:\/\//, '') }) : ''], ['Adresse', c.address]].forEach(function (r) {
        if (r[1]) { info.appendChild(h('dt', { text: r[0] })); info.appendChild(h('dd', null, r[1])); }
      });

      var contacts = h('div', { class: 'frcrm-people' });
      c.contacts.forEach(function (k) {
        contacts.appendChild(h('div', { class: 'frcrm-person' },
          h('b', { text: [k.first_name, k.last_name].join(' ').trim() || '(sans nom)' }),
          k.role ? h('small', { text: k.role }) : null,
          k.email ? h('a', { href: 'mailto:' + k.email, text: k.email }) : null,
          k.phone ? h('a', { href: 'tel:' + k.phone.replace(/\s/g, ''), text: k.phone }) : null));
      });
      if (!c.contacts.length) { contacts.appendChild(empty('Aucun contact.')); }

      var tabs = h('div', { class: 'frcrm-tabs', role: 'tablist' });
      [['quotes', 'Devis (' + c.quotes.length + ')'], ['reports', 'Comptes-rendus (' + c.reports.length + ')'], ['history', 'Historique']].forEach(function (t) {
        tabs.appendChild(h('button', { type: 'button', role: 'tab', 'aria-selected': t[0] === tab ? 'true' : 'false', text: t[1], onclick: function () { history.replaceState(null, '', '#/client/' + id + '?tab=' + t[0]); lastHash = location.hash; vClient(id, new URLSearchParams('tab=' + t[0])); } }));
      });
      var pane = h('div', { class: 'frcrm-pane' });
      if (tab === 'quotes') { pane.appendChild(quotesPane(c)); }
      if (tab === 'reports') { pane.appendChild(reportsPane(c, function () { vClient(id, new URLSearchParams('tab=reports')); })); }
      if (tab === 'history') { pane.appendChild(historyPane(c, function () { vClient(id, new URLSearchParams('tab=history')); })); }

      render(
        title(c.company, h('span', null, 'Fiche créée le ' + fdate(c.created_at)), [
          st,
          btn('Modifier', function () { location.hash = '#/client/' + c.id + '/edit'; }),
          btn('Supprimer', function () {
            if (window.confirm('Supprimer définitivement ' + c.company + ' avec ses contacts, devis et comptes-rendus ?')) {
              api('DELETE', '/clients/' + c.id).then(function () { toast('Client supprimé'); location.hash = '#/clients'; }).catch(fail);
            }
          }, 'danger ghost')]),
        h('div', { class: 'frcrm-client' },
          h('div', { class: 'frcrm-client-side' },
            card('Problématique', h('div', null,
              c.problem ? h('p', { class: 'frcrm-pre', text: c.problem }) : empty('Pas encore renseignée.'),
              c.next_action || c.next_date ? h('p', { class: 'frcrm-next' + (c.next_date && c.next_date < today() ? ' late' : '') }, h('b', { text: 'Prochaine action : ' }), c.next_action || 'relance', c.next_date ? ' · ' + fdate(c.next_date) : '') : null)),
            card('Contacts', contacts),
            info.children.length ? card('Informations', info) : null,
            c.notes ? card('Notes internes', h('p', { class: 'frcrm-pre', text: c.notes })) : null),
          h('div', { class: 'frcrm-client-main' }, tabs, pane)));
    }).catch(fail);
  }

  function quotesPane(c) {
    var box = h('div');
    box.appendChild(h('div', { class: 'frcrm-pane-h' }, h('p', { class: 'frcrm-hint', text: 'Les devis reprennent la problématique et les coordonnées du client.' }), btn('+ Nouveau devis', function () { location.hash = '#/quote/new?client=' + c.id; }, 'primary')));
    if (!c.quotes.length) { box.appendChild(empty('Aucun devis pour ce client.')); return box; }
    box.appendChild(quotesTable(c.quotes, false));
    return box;
  }

  function quotesTable(rows, withClient) {
    var tb = h('tbody');
    rows.forEach(function (q) {
      tb.appendChild(h('tr', null,
        h('td', null, h('a', { href: '#/quote/' + q.id, class: 'frcrm-strong', text: q.number }), h('small', { text: q.title })),
        withClient ? h('td', null, h('a', { href: '#/client/' + q.client_id, text: q.company || '' })) : null,
        h('td', null, qPill(q.status)),
        h('td', null, fdate(q.issue_date), h('small', { text: 'valable jusqu\'au ' + fdate(q.valid_until) })),
        h('td', null, h('small', { text: entityName(q.entity) })),
        h('td', { class: 'n' }, money(q.total_ht), h('small', { text: money(q.total_ttc) + ' TTC' }))));
    });
    return h('div', { class: 'frcrm-table-wrap' }, h('table', { class: 'frcrm-table' },
      h('thead', null, h('tr', null, h('th', { text: 'Devis' }), withClient ? h('th', { text: 'Client' }) : null, h('th', { text: 'Statut' }), h('th', { text: 'Date' }), h('th', { text: 'Structure' }), h('th', { class: 'n', text: 'Montant HT' }))), tb));
  }

  function historyPane(c, refresh) {
    var note = area('', 2, { placeholder: 'Ajouter une note à l\'historique (appel, échange, info utile)…', 'aria-label': 'Note' });
    var b = btn('Ajouter la note', function () {
      if (!note.value.trim()) { return; }
      api('POST', '/clients/' + c.id + '/note', { message: note.value }).then(function () { toast('Note ajoutée'); refresh(); }).catch(fail);
    }, 'primary');
    return h('div', null, h('div', { class: 'frcrm-note' }, note, b), activityList(c.activity, false));
  }

  /* ------------------------------------------------------------------ */
  /* Reports                                                            */
  /* ------------------------------------------------------------------ */

  var RTYPES = [['rdv', 'Rendez-vous'], ['visio', 'Visio'], ['appel', 'Appel'], ['atelier', 'Atelier'], ['email', 'Échange e-mail'], ['autre', 'Autre']];

  function reportsPane(c, refresh) {
    var box = h('div');
    var formSlot = h('div');
    box.appendChild(h('div', { class: 'frcrm-pane-h' }, h('p', { class: 'frcrm-hint', text: 'Un compte-rendu par échange. Les actions avec une échéance remontent dans le tableau de bord et les e-mails d\'alerte.' }),
      btn('+ Nouveau compte-rendu', function () { formSlot.innerHTML = ''; formSlot.appendChild(reportForm(c, null, refresh, function () { formSlot.innerHTML = ''; })); }, 'primary')));
    box.appendChild(formSlot);
    if (!c.reports.length) { box.appendChild(empty('Aucun compte-rendu pour l\'instant.')); return box; }
    c.reports.forEach(function (r) {
      var acts = h('ul', { class: 'frcrm-acts' });
      r.actions.forEach(function (a, i) {
        var cb = h('input', { type: 'checkbox', checked: !!+a.done, 'aria-label': 'Fait' });
        cb.addEventListener('change', function () { api('POST', '/reports/' + r.id + '/action', { index: i, done: cb.checked }).then(function () { li.classList.toggle('done', cb.checked); }).catch(fail); });
        var li = h('li', { class: +a.done ? 'done' : '' }, h('label', null, cb, h('span', { text: a.text })), a.who ? h('small', { text: a.who }) : null, a.due ? h('small', { class: !+a.done && a.due < today() ? 'late' : '', text: fdate(a.due) }) : null);
        acts.appendChild(li);
      });
      var slot = h('div');
      var art = h('article', { class: 'frcrm-report' },
        h('div', { class: 'frcrm-report-h' },
          h('div', null, h('b', { text: r.title || (RTYPES.filter(function (t) { return t[0] === r.type; })[0] || [, 'Échange'])[1] }), h('small', { text: fdate(r.meeting_date) + (r.participants ? ' · ' + r.participants : '') })),
          h('div', null,
            btn('Modifier', function () { slot.innerHTML = ''; art.hidden = true; slot.appendChild(reportForm(c, r, refresh, function () { slot.innerHTML = ''; art.hidden = false; })); }, 'small ghost'),
            btn('Supprimer', function () { if (window.confirm('Supprimer ce compte-rendu ?')) { api('DELETE', '/reports/' + r.id).then(refresh).catch(fail); } }, 'small ghost danger'))),
        r.notes ? h('p', { class: 'frcrm-pre', text: r.notes }) : null,
        r.decisions ? h('div', null, h('h4', { text: 'Décisions' }), h('p', { class: 'frcrm-pre', text: r.decisions })) : null,
        r.actions.length ? h('div', null, h('h4', { text: 'Actions' }), acts) : null);
      box.appendChild(slot);
      box.appendChild(art);
    });
    return box;
  }

  function reportForm(c, r, done, cancel) {
    r = r || { meeting_date: today(), type: 'rdv', actions: [] };
    var f = {
      meeting_date: input(r.meeting_date, { type: 'date' }),
      type: select(RTYPES, r.type),
      title: input(r.title, { placeholder: 'Ex. : Atelier de cadrage' }),
      participants: input(r.participants, { placeholder: 'Qui était présent' }),
      notes: area(r.notes, 7, { placeholder: 'Vos notes, même brutes ou dictées.' }),
      decisions: area(r.decisions, 3, { placeholder: 'Une décision par ligne' })
    };
    var acts = h('div', { class: 'frcrm-actrows' });
    function actRow(a) {
      var t = input(a.text, { placeholder: 'Action à mener', 'aria-label': 'Action' });
      var w = input(a.who, { placeholder: 'Qui', 'aria-label': 'Responsable' });
      var d = input(a.due, { type: 'date', 'aria-label': 'Échéance' });
      var row = h('div', { class: 'frcrm-actrow' }, t, w, d, h('button', { type: 'button', class: 'frcrm-icon', 'aria-label': 'Retirer', text: '×', onclick: function () { row.remove(); } }));
      row._get = function () { return { text: t.value.trim(), who: w.value.trim(), due: d.value, done: a.done || 0 }; };
      return row;
    }
    (r.actions.length ? r.actions : [{}]).forEach(function (a) { acts.appendChild(actRow(a)); });

    var aiBtn = B.ai ? btn('Mettre au propre avec l\'IA', function () {
      busy(aiBtn, true, 'Rédaction…');
      api('POST', '/ai', { task: 'cr', client_id: c.id, text: f.notes.value }).then(function (j) {
        if (j.notes) { f.notes.value = j.notes; }
        if (j.decisions) { f.decisions.value = Array.isArray(j.decisions) ? j.decisions.join('\n') : j.decisions; }
        if (Array.isArray(j.actions) && j.actions.length) {
          acts.innerHTML = '';
          j.actions.forEach(function (a) { acts.appendChild(actRow({ text: a.text, who: a.who, due: /^\d{4}-\d{2}-\d{2}$/.test(a.due || '') ? a.due : '' })); });
        }
        toast('Compte-rendu mis au propre : relisez-le avant d\'enregistrer');
      }).catch(fail).finally(function () { busy(aiBtn, false); });
    }, 'ghost') : null;

    var save = btn('Enregistrer le compte-rendu', function () {
      var body = { client_id: c.id };
      Object.keys(f).forEach(function (k) { body[k] = f[k].value; });
      body.actions = [].map.call(acts.children, function (row) { return row._get(); }).filter(function (a) { return a.text; });
      busy(save, true);
      api(r.id ? 'PUT' : 'POST', '/reports' + (r.id ? '/' + r.id : ''), body).then(function () { toast('Compte-rendu enregistré'); done(); }).catch(function (e) { busy(save, false); fail(e); });
    }, 'primary');

    return h('div', { class: 'frcrm-card frcrm-report-form' },
      h('div', { class: 'frcrm-row' }, field('Date', f.meeting_date), field('Type', f.type), field('Titre', f.title)),
      field('Participants', f.participants),
      field('Compte-rendu', f.notes),
      aiBtn,
      field('Décisions', f.decisions),
      h('div', { class: 'frcrm-field' }, h('span', { text: 'Actions à suivre' }), acts, btn('+ Ajouter une action', function () { acts.appendChild(actRow({})); }, 'ghost small')),
      h('div', { class: 'frcrm-actions' }, btn('Annuler', cancel), save));
  }

  /* ------------------------------------------------------------------ */
  /* Quotes list                                                        */
  /* ------------------------------------------------------------------ */

  function vQuotes() {
    api('GET', '/quotes').then(function (rows) {
      var f = select([['', 'Tous les statuts']].concat(Object.keys(B.quoteStatuses).map(function (k) { return [k, B.quoteStatuses[k]]; })), '', { 'aria-label': 'Filtrer par statut' });
      var slot = h('div');
      function draw() {
        slot.innerHTML = '';
        var list = f.value ? rows.filter(function (q) { return q.status === f.value; }) : rows;
        slot.appendChild(list.length ? quotesTable(list, true) : empty(rows.length ? 'Aucun devis avec ce statut.' : 'Aucun devis. Créez-en un depuis une fiche client.'));
      }
      f.addEventListener('change', draw);
      render(title('Devis', 'Un devis se crée depuis la fiche du client.'), h('div', { class: 'frcrm-toolbar' }, f), slot);
      draw();
    }).catch(fail);
  }

  /* ------------------------------------------------------------------ */
  /* Quote editor                                                       */
  /* ------------------------------------------------------------------ */

  function vQuote(id, qs) {
    var pClient = id ? null : +qs.get('client');
    Promise.all([
      id ? api('GET', '/quotes/' + id) : Promise.resolve(null),
      api('GET', '/services'),
      pClient ? api('GET', '/clients/' + pClient) : Promise.resolve(null)
    ]).then(function (res) {
      var q = res[0], services = res[1].filter(function (s) { return +s.active; }), client = q ? q.client : res[2];
      if (!client) { render(title('Nouveau devis'), empty('Ouvrez d\'abord la fiche d\'un client, puis « Nouveau devis ».')); return; }
      quoteEditor(q, client, services);
    }).catch(fail);
  }

  function quoteEditor(q, client, services) {
    var isNew = !q;
    if (isNew) {
      var specs = B.specs.map(function (s) {
        var t = s.text;
        if (s.key === 'contexte' && client.problem) { t = client.problem; }
        return { key: s.key, title: s.title, text: t, on: 1 };
      });
      q = {
        entity: client.entity || B.defaultEntity,
        contact_id: client.contacts[0] ? client.contacts[0].id : 0,
        title: '',
        issue_date: today(),
        valid_until: '',
        intro: 'Suite à nos échanges, nous avons le plaisir de vous adresser notre proposition. Elle reprend vos enjeux, le périmètre de la mission, les livrables et les modalités de réalisation.',
        specs: specs,
        items: [],
        discount: 0,
        deposit: B.deposit,
        payment_terms: B.paymentTerms,
        notes: '',
        status: 'brouillon'
      };
    }
    var items = q.items.map(function (x) { return Object.assign({}, x); });
    var specsData = q.specs.map(function (x) { return Object.assign({ on: 1 }, x); });

    var f = {
      entity: select(B.entities.map(function (e) { return [e.key, e.name]; }), q.entity),
      contact_id: select([[0, '–']].concat(client.contacts.map(function (k) { return [k.id, [k.first_name, k.last_name].join(' ').trim() || k.email]; })), q.contact_id),
      title: input(q.title, { placeholder: 'Ex. : Audit de l\'expérience client omnicanale' }),
      issue_date: input(q.issue_date, { type: 'date' }),
      valid_until: input(q.valid_until, { type: 'date' }),
      intro: area(q.intro, 3),
      discount: input(+q.discount || 0, { type: 'number', min: 0, max: 100, step: '0.5', class: 'frcrm-num' }),
      deposit: input(+q.deposit || 0, { type: 'number', min: 0, max: 100, step: '1', class: 'frcrm-num' }),
      payment_terms: area(q.payment_terms, 3),
      notes: area(q.notes, 2, { placeholder: 'Remarques visibles par le client (facultatif)' })
    };
    Object.keys(f).forEach(function (k) { f[k].addEventListener('input', function () { dirty = true; if (k === 'discount' || k === 'entity') { totals(); } }); f[k].addEventListener('change', function () { dirty = true; if (k === 'entity') { totals(); } }); });

    function exempt() { var e = B.entities.filter(function (x) { return x.key === f.entity.value; })[0]; return e && +e.vat_exempt; }

    /* --- lines --- */
    var linesBody = h('tbody');
    var totBox = h('div', { class: 'frcrm-totals' });

    function totals() {
      var sub = 0, vat = {};
      items.forEach(function (it) {
        if (it.type === 'title') { return; }
        var ht = num(it.qty) * num(it.price) * (1 - num(it.discount) / 100);
        sub += ht;
        var r = exempt() ? 0 : num(it.vat);
        vat[r] = (vat[r] || 0) + ht;
      });
      var fct = 1 - num(f.discount.value) / 100, ht = sub * fct, tva = 0;
      totBox.innerHTML = '';
      if (num(f.discount.value) > 0) {
        totBox.appendChild(h('div', null, h('span', { text: 'Sous-total HT' }), h('b', { text: money(sub) })));
        totBox.appendChild(h('div', null, h('span', { text: 'Remise ' + num(f.discount.value) + ' %' }), h('b', { text: '−' + money(sub - ht) })));
      }
      totBox.appendChild(h('div', null, h('span', { text: 'Total HT' }), h('b', { text: money(ht) })));
      Object.keys(vat).forEach(function (r) {
        if (+r > 0) { var a = vat[r] * fct * r / 100; tva += a; totBox.appendChild(h('div', null, h('span', { text: 'TVA ' + r + ' %' }), h('b', { text: money(a) }))); }
      });
      if (exempt()) { totBox.appendChild(h('div', { class: 'frcrm-hint' }, h('span', { text: 'TVA non applicable (art. 293 B du CGI)' }))); }
      totBox.appendChild(h('div', { class: 'big' }, h('span', { text: exempt() ? 'Total' : 'Total TTC' }), h('b', { text: money(ht + tva) })));
      if (num(f.deposit.value) > 0) { totBox.appendChild(h('div', null, h('span', { text: 'Acompte ' + num(f.deposit.value) + ' %' }), h('b', { text: money((ht + tva) * num(f.deposit.value) / 100) }))); }
    }

    function drawLines() {
      linesBody.innerHTML = '';
      if (!items.length) { linesBody.appendChild(h('tr', null, h('td', { colspan: 8, class: 'frcrm-empty', text: 'Ajoutez des prestations depuis le catalogue ou une ligne libre.' }))); }
      items.forEach(function (it, i) {
        function inp(k, attrs) {
          var e = input(it[k], attrs);
          e.addEventListener('input', function () { it[k] = e.value; dirty = true; totals(); if (lineTot) { lineTot.textContent = money(num(it.qty) * num(it.price) * (1 - num(it.discount) / 100)); } });
          return e;
        }
        var move = h('div', { class: 'frcrm-move' },
          h('button', { type: 'button', 'aria-label': 'Monter', text: '↑', disabled: i === 0 ? true : null, onclick: function () { items.splice(i - 1, 0, items.splice(i, 1)[0]); dirty = true; drawLines(); } }),
          h('button', { type: 'button', 'aria-label': 'Descendre', text: '↓', disabled: i === items.length - 1 ? true : null, onclick: function () { items.splice(i + 1, 0, items.splice(i, 1)[0]); dirty = true; drawLines(); } }));
        var del = h('button', { type: 'button', class: 'frcrm-icon', 'aria-label': 'Supprimer la ligne', text: '×', onclick: function () { items.splice(i, 1); dirty = true; drawLines(); } });
        var lineTot = null;
        if (it.type === 'title') {
          linesBody.appendChild(h('tr', { class: 'sec' }, h('td', null, move), h('td', { colspan: 6 }, inp('name', { placeholder: 'Titre de section', 'aria-label': 'Titre de section' })), h('td', null, del)));
          return;
        }
        var desc = area(it.description, 2, { placeholder: 'Description (facultatif)', 'aria-label': 'Description' });
        desc.addEventListener('input', function () { it.description = desc.value; dirty = true; });
        lineTot = h('td', { class: 'n frcrm-linetot', text: money(num(it.qty) * num(it.price) * (1 - num(it.discount) / 100)) });
        linesBody.appendChild(h('tr', null,
          h('td', null, move),
          h('td', { class: 'frcrm-designation' }, inp('name', { placeholder: 'Désignation', 'aria-label': 'Désignation' }), desc),
          h('td', null, inp('qty', { type: 'number', step: '0.5', min: 0, class: 'frcrm-num', 'aria-label': 'Quantité' }), inp('unit', { placeholder: 'unité', class: 'frcrm-unit', 'aria-label': 'Unité' })),
          h('td', null, inp('price', { type: 'number', step: '0.01', class: 'frcrm-num w', 'aria-label': 'Prix unitaire HT' })),
          h('td', null, inp('discount', { type: 'number', step: '1', min: 0, max: 100, class: 'frcrm-num', 'aria-label': 'Remise %' })),
          h('td', null, inp('vat', { type: 'number', step: '0.1', min: 0, class: 'frcrm-num', 'aria-label': 'TVA %' })),
          lineTot,
          h('td', null, del)));
      });
      totals();
    }

    var svcSel = select([['', 'Ajouter une prestation du catalogue…']].concat(services.map(function (s) { return [s.id, s.name + (+s.price ? ' · ' + money(s.price) + ' / ' + s.unit : '')]; })), '', { 'aria-label': 'Prestation du catalogue' });
    svcSel.addEventListener('change', function () {
      var s = services.filter(function (x) { return String(x.id) === svcSel.value; })[0];
      svcSel.value = '';
      if (!s) { return; }
      items.push({ type: 'line', name: s.name, description: s.description || '', qty: 1, unit: s.unit, price: +s.price, vat: +s.vat, discount: 0, service_id: +s.id });
      // The service deliverables go into the "Livrables" section of the specification.
      if (s.deliverables) {
        var liv = specsData.filter(function (x) { return x.key === 'livrables'; })[0];
        if (liv) {
          var add = s.deliverables.split(/\n/).map(function (l) { return l.trim(); }).filter(Boolean).filter(function (l) { return liv.text.indexOf(l) < 0; }).map(function (l) { return '- ' + l; });
          if (add.length) { liv.text = (liv.text ? liv.text.replace(/\s+$/, '') + '\n' : '') + add.join('\n'); drawSpecs(); }
        }
      }
      dirty = true;
      drawLines();
    });

    /* --- specification --- */
    var specsBox = h('div', { class: 'frcrm-specs' });
    function drawSpecs() {
      specsBox.innerHTML = '';
      specsData.forEach(function (sp, i) {
        var on = h('input', { type: 'checkbox', checked: !!+sp.on, 'aria-label': 'Inclure cette section' });
        var t = input(sp.title, { class: 'frcrm-spec-title', 'aria-label': 'Titre de la section' });
        var a = area(sp.text, Math.min(10, Math.max(3, (sp.text || '').split('\n').length + 1)), { 'aria-label': sp.title });
        on.addEventListener('change', function () { sp.on = on.checked ? 1 : 0; box.classList.toggle('off', !on.checked); dirty = true; });
        t.addEventListener('input', function () { sp.title = t.value; dirty = true; });
        a.addEventListener('input', function () { sp.text = a.value; dirty = true; });
        var box = h('div', { class: 'frcrm-spec' + (+sp.on ? '' : ' off') },
          h('div', { class: 'frcrm-spec-h' }, h('label', null, on, h('span', { class: 'frcrm-spec-n', text: (i + 1) + '.' })), t,
            h('button', { type: 'button', class: 'frcrm-icon', 'aria-label': 'Supprimer la section', text: '×', onclick: function () { specsData.splice(i, 1); dirty = true; drawSpecs(); } })),
          a);
        specsBox.appendChild(box);
      });
    }

    var aiSpecs = B.ai ? btn('Rédiger le cahier des charges avec l\'IA', function () {
      if (!items.length) { toast('Ajoutez d\'abord les prestations.', true); return; }
      if (!window.confirm('Le texte des sections cochées sera remplacé par une proposition de l\'IA. Continuer ?')) { return; }
      busy(aiSpecs, true, 'Rédaction en cours…');
      api('POST', '/ai', { task: 'specs', client_id: client.id, items: items, specs: specsData.filter(function (s) { return +s.on; }) }).then(function (j) {
        specsData.forEach(function (s) { if (+s.on && typeof j[s.title] === 'string' && j[s.title].trim()) { s.text = j[s.title].trim(); } });
        dirty = true;
        drawSpecs();
        toast('Proposition insérée : relisez et ajustez.');
      }).catch(fail).finally(function () { busy(aiSpecs, false); });
    }, 'ghost') : null;

    /* --- save and status --- */
    function payload() {
      var b = { client_id: client.id, items: items, specs: specsData };
      Object.keys(f).forEach(function (k) { b[k] = f[k].value; });
      return b;
    }
    function save() {
      busy(saveBtn, true, 'Enregistrement…');
      return api(isNew ? 'POST' : 'PUT', '/quotes' + (isNew ? '' : '/' + q.id), payload()).then(function (r) {
        dirty = false;
        toast('Devis ' + r.number + ' enregistré');
        if (isNew) { lastHash = '#/quote/' + r.id; history.replaceState(null, '', lastHash); }
        return r;
      }).finally(function () { busy(saveBtn, false); });
    }
    var saveBtn = btn('Enregistrer', function () { save().then(function (r) { quoteEditor(r, r.client, services); }).catch(fail); }, 'primary');
    var printBtn = btn('Aperçu et PDF', function () {
      var w = window.open('', '_blank');
      (dirty || isNew ? save() : Promise.resolve(q)).then(function (r) {
        if (w) { w.location = FRCRM.print + r.id; } else { location.href = FRCRM.print + r.id; }
        if (isNew || r.id !== q.id) { quoteEditor(r, r.client, services); }
      }).catch(function (e) { if (w) { w.close(); } fail(e); });
    });

    function setStatus(st, label) {
      (dirty ? save() : Promise.resolve(q)).then(function (r) {
        return api('POST', '/quotes/' + r.id + '/status', { status: st });
      }).then(function (r) { toast('Devis ' + label.toLowerCase()); quoteEditor(r, r.client, services); }).catch(fail);
    }
    var statusBar = isNew ? null : h('div', { class: 'frcrm-statusbar' },
      h('span', null, 'Statut : ', qPill(q.status)),
      q.status !== 'envoye' ? btn('Marquer envoyé', function () { setStatus('envoye', 'Envoyé'); }, 'small') : null,
      q.status !== 'accepte' ? btn('Accepté', function () { setStatus('accepte', 'Accepté'); }, 'small ok') : null,
      q.status !== 'refuse' ? btn('Refusé', function () { setStatus('refuse', 'Refusé'); }, 'small danger') : null,
      q.status !== 'brouillon' ? btn('Repasser en brouillon', function () { setStatus('brouillon', 'Brouillon'); }, 'small ghost') : null);

    var more = isNew ? null : [
      btn('Dupliquer', function () { api('POST', '/quotes/' + q.id + '/duplicate').then(function (r) { toast('Copie créée : ' + r.number); location.hash = '#/quote/' + r.id; }).catch(fail); }, 'ghost'),
      btn('Supprimer', function () { if (window.confirm('Supprimer le devis ' + q.number + ' ?')) { api('DELETE', '/quotes/' + q.id).then(function () { dirty = false; toast('Devis supprimé'); location.hash = '#/client/' + client.id; }).catch(fail); } }, 'ghost danger')];

    render(
      title(isNew ? 'Nouveau devis' : 'Devis ' + q.number, h('span', null, 'Pour ', h('a', { href: '#/client/' + client.id, text: client.company })), [more, printBtn, saveBtn]),
      statusBar,
      card('En-tête', h('div', { class: 'frcrm-form' },
        h('div', { class: 'frcrm-row' }, field('Émis par', f.entity), field('À l\'attention de', f.contact_id), field('Date', f.issue_date), field('Valable jusqu\'au', f.valid_until, isNew ? 'Vide : ' + B.validityDays + ' jours après la date' : null)),
        field('Objet du devis', f.title),
        field('Introduction', f.intro))),
      card('Cahier des charges de livraison', h('div', null,
        h('p', { class: 'frcrm-hint', text: 'Cochez les sections à inclure. Les livrables des prestations ajoutées s\'inscrivent automatiquement dans « Livrables ». Une ligne commençant par « - » devient une puce dans le PDF.' }),
        aiSpecs, specsBox,
        btn('+ Ajouter une section', function () { specsData.push({ key: '', title: 'Nouvelle section', text: '', on: 1 }); dirty = true; drawSpecs(); }, 'ghost small'))),
      card('Proposition financière', h('div', null,
        h('div', { class: 'frcrm-toolbar' }, svcSel,
          btn('+ Ligne libre', function () { items.push({ type: 'line', name: '', description: '', qty: 1, unit: 'forfait', price: 0, vat: 20, discount: 0 }); dirty = true; drawLines(); }, 'ghost'),
          btn('+ Titre de section', function () { items.push({ type: 'title', name: 'Phase ' + (items.filter(function (x) { return x.type === 'title'; }).length + 1) }); dirty = true; drawLines(); }, 'ghost')),
        h('div', { class: 'frcrm-table-wrap' }, h('table', { class: 'frcrm-table frcrm-lines' },
          h('thead', null, h('tr', null, h('th', { 'aria-label': 'Ordre' }), h('th', { text: 'Désignation' }), h('th', { text: 'Qté' }), h('th', { text: 'PU HT' }), h('th', { text: 'Remise %' }), h('th', { text: 'TVA %' }), h('th', { class: 'n', text: 'Total HT' }), h('th'))),
          linesBody)),
        h('div', { class: 'frcrm-totrow' },
          h('div', { class: 'frcrm-form' }, h('div', { class: 'frcrm-row' }, field('Remise globale (%)', f.discount), field('Acompte (%)', f.deposit))),
          totBox))),
      card('Conditions', h('div', { class: 'frcrm-form' }, field('Conditions de règlement', f.payment_terms), field('Remarques', f.notes))),
      h('div', { class: 'frcrm-actions frcrm-bottom' }, printBtn.cloneNode(true), btn('Enregistrer', function () { saveBtn.click(); }, 'primary')));
    view.querySelector('.frcrm-bottom').firstChild.addEventListener('click', function () { printBtn.click(); });
    drawLines();
    drawSpecs();
    if (isNew) { dirty = true; }
  }

  /* ------------------------------------------------------------------ */
  /* Services                                                           */
  /* ------------------------------------------------------------------ */

  function vServices() {
    api('GET', '/services').then(function (rows) { drawServices(rows); }).catch(fail);
  }
  function drawServices(rows) {
    var formSlot = h('div');
    function form(s) {
      s = s || { unit: 'forfait', vat: 20, active: 1, price: 0 };
      var f = {
        name: input(s.name, { placeholder: 'Ex. : Audit UX', required: true }),
        category: input(s.category, { placeholder: 'Ex. : Conseil', list: 'frcrm-cats' }),
        unit: select(['forfait', 'jour', 'demi-journée', 'heure', 'mois', 'page', 'unité'], s.unit),
        price: input(s.price, { type: 'number', step: '0.01', min: 0 }),
        vat: input(s.vat, { type: 'number', step: '0.1', min: 0 }),
        description: area(s.description, 3, { placeholder: 'Description reprise sur le devis' }),
        deliverables: area(s.deliverables, 3, { placeholder: 'Un livrable par ligne (ajoutés au cahier des charges)' }),
        active: h('input', { type: 'checkbox', checked: s.id ? !!+s.active : true })
      };
      var cats = h('datalist', { id: 'frcrm-cats' });
      rows.map(function (r) { return r.category; }).filter(function (v, i, a) { return v && a.indexOf(v) === i; }).forEach(function (v) { cats.appendChild(h('option', { value: v })); });
      var sv = btn(s.id ? 'Enregistrer' : 'Ajouter au catalogue', function () {
        var b = {};
        Object.keys(f).forEach(function (k) { b[k] = k === 'active' ? (f[k].checked ? 1 : 0) : f[k].value; });
        busy(sv, true);
        api(s.id ? 'PUT' : 'POST', '/services' + (s.id ? '/' + s.id : ''), b).then(function (list) { toast('Prestation enregistrée'); drawServices(list); }).catch(function (e) { busy(sv, false); fail(e); });
      }, 'primary');
      formSlot.innerHTML = '';
      formSlot.appendChild(h('div', { class: 'frcrm-card' }, cats,
        h('div', { class: 'frcrm-row' }, field('Prestation', f.name), field('Catégorie', f.category)),
        h('div', { class: 'frcrm-row' }, field('Unité', f.unit), field('Prix unitaire HT (€)', f.price), field('TVA (%)', f.vat)),
        h('div', { class: 'frcrm-row' }, field('Description', f.description), field('Livrables', f.deliverables)),
        h('label', { class: 'frcrm-check' }, f.active, ' Proposée dans les devis'),
        h('div', { class: 'frcrm-actions' }, btn('Annuler', function () { formSlot.innerHTML = ''; }), sv)));
      f.name.focus();
      formSlot.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
    var tb = h('tbody');
    rows.forEach(function (s) {
      tb.appendChild(h('tr', { class: +s.active ? '' : 'off' },
        h('td', null, h('b', { text: s.name }), s.description ? h('small', { text: s.description }) : null),
        h('td', null, s.category || ''),
        h('td', { class: 'n' }, +s.price ? money(s.price) : h('span', { class: 'frcrm-warn', text: 'à définir' }), h('small', { text: '/ ' + s.unit })),
        h('td', { class: 'n', text: (+s.vat) + ' %' }),
        h('td', null, +s.active ? 'Oui' : 'Non'),
        h('td', { class: 'n' }, btn('Modifier', function () { form(s); }, 'small ghost'),
          btn('Supprimer', function () { if (window.confirm('Supprimer « ' + s.name + ' » du catalogue ? Les devis existants ne changent pas.')) { api('DELETE', '/services/' + s.id).then(drawServices).catch(fail); } }, 'small ghost danger'))));
    });
    render(
      title('Prestations', 'Votre catalogue : il alimente les lignes des devis et la section Livrables.', btn('+ Nouvelle prestation', function () { form(null); }, 'primary')),
      formSlot,
      rows.length ? h('div', { class: 'frcrm-table-wrap' }, h('table', { class: 'frcrm-table' },
        h('thead', null, h('tr', null, h('th', { text: 'Prestation' }), h('th', { text: 'Catégorie' }), h('th', { class: 'n', text: 'Prix HT' }), h('th', { class: 'n', text: 'TVA' }), h('th', { text: 'Active' }), h('th'))), tb)) : empty('Aucune prestation.'));
  }

  /* ------------------------------------------------------------------ */
  /* Settings                                                           */
  /* ------------------------------------------------------------------ */

  function vSettings() {
    api('GET', '/settings').then(function (s) {
      var ents = s.entities.map(function (e) { return Object.assign({}, e); });
      var specs = s.specs.map(function (x) { return Object.assign({}, x); });
      var entBox = h('div', { class: 'frcrm-ents' });
      function drawEnts() {
        entBox.innerHTML = '';
        ents.forEach(function (e, i) {
          function inp(k, attrs, multi) {
            var el = multi ? area(e[k], 3) : input(e[k], attrs);
            el.addEventListener('input', function () { e[k] = el.value; });
            return el;
          }
          var ex = h('input', { type: 'checkbox', checked: !!+e.vat_exempt });
          ex.addEventListener('change', function () { e.vat_exempt = ex.checked ? 1 : 0; });
          var logoImg = h('img', { src: e.logo || '', alt: '', class: 'frcrm-logo', hidden: e.logo ? null : true });
          var logoBtn = btn(e.logo ? 'Changer le logo' : 'Choisir un logo', function () {
            if (!window.wp || !wp.media) { return; }
            var fr = wp.media({ title: 'Logo de ' + e.name, library: { type: 'image' }, multiple: false });
            fr.on('select', function () { var a = fr.state().get('selection').first().toJSON(); e.logo = a.url; logoImg.src = a.url; logoImg.hidden = false; });
            fr.open();
          }, 'ghost small');
          entBox.appendChild(h('div', { class: 'frcrm-card frcrm-ent' },
            h('div', { class: 'frcrm-card-h' }, h('h3', { text: e.name || 'Nouvelle structure' }), ents.length > 1 ? btn('Retirer', function () { ents.splice(i, 1); drawEnts(); }, 'small ghost danger') : null),
            h('div', { class: 'frcrm-row' }, field('Nom', inp('name')), field('Préfixe des devis', inp('prefix', { maxlength: 6 }), 'Ex. : LFD-2026-001'), field('Couleur', inp('color', { type: 'color' }))),
            h('div', { class: 'frcrm-row' }, field('Adresse', inp('address', null, true)), field('Coordonnées bancaires (facultatif)', inp('bank', null, true))),
            h('div', { class: 'frcrm-row' }, field('SIRET', inp('siret')), field('N° de TVA intracommunautaire', inp('vat_number')), field('E-mail', inp('email', { type: 'email' })), field('Téléphone', inp('phone'))),
            h('div', { class: 'frcrm-row' }, field('Site', inp('website', { type: 'url' })), h('div', { class: 'frcrm-field' }, h('span', { text: 'Logo' }), logoImg, logoBtn)),
            h('label', { class: 'frcrm-check' }, ex, ' Franchise en base de TVA (micro-entreprise) : « TVA non applicable, art. 293 B du CGI »')));
        });
      }
      drawEnts();

      var specBox = h('div', { class: 'frcrm-specs' });
      function drawSpecs() {
        specBox.innerHTML = '';
        specs.forEach(function (sp, i) {
          var t = input(sp.title, { class: 'frcrm-spec-title', 'aria-label': 'Titre' });
          var a = area(sp.text, 3, { 'aria-label': 'Texte par défaut' });
          t.addEventListener('input', function () { sp.title = t.value; });
          a.addEventListener('input', function () { sp.text = a.value; });
          specBox.appendChild(h('div', { class: 'frcrm-spec' },
            h('div', { class: 'frcrm-spec-h' }, h('span', { class: 'frcrm-spec-n', text: (i + 1) + '.' }), t,
              h('div', { class: 'frcrm-move' },
                h('button', { type: 'button', 'aria-label': 'Monter', text: '↑', disabled: i === 0 ? true : null, onclick: function () { specs.splice(i - 1, 0, specs.splice(i, 1)[0]); drawSpecs(); } }),
                h('button', { type: 'button', 'aria-label': 'Descendre', text: '↓', disabled: i === specs.length - 1 ? true : null, onclick: function () { specs.splice(i + 1, 0, specs.splice(i, 1)[0]); drawSpecs(); } })),
              h('button', { type: 'button', class: 'frcrm-icon', 'aria-label': 'Supprimer', text: '×', onclick: function () { specs.splice(i, 1); drawSpecs(); } })),
            a));
        });
      }
      drawSpecs();

      var g = {
        default_entity: select(ents.map(function (e) { return [e.key, e.name]; }), s.default_entity),
        validity_days: input(s.validity_days, { type: 'number', min: 1 }),
        deposit: input(s.deposit, { type: 'number', min: 0, max: 100 }),
        payment_terms: area(s.payment_terms, 4),
        legal: area(s.legal, 3),
        notify_to: input(s.notify_to, { placeholder: 'vous@exemple.fr, associe@exemple.fr' }),
        stale_days: input(s.stale_days, { type: 'number', min: 1 }),
        expire_warn: input(s.expire_warn, { type: 'number', min: 0 })
      };
      var toggles = {};
      [['notify_status', 'À chaque changement d\'état d\'un client'], ['notify_quote', 'Quand un devis est accepté ou refusé'], ['notify_daily', 'Chaque matin : actions du jour, devis à relancer, relances prévues'], ['notify_weekly', 'Le lundi : le point de la semaine, avec les clients sans nouvelles']].forEach(function (t) {
        toggles[t[0]] = h('label', { class: 'frcrm-check' }, h('input', { type: 'checkbox', checked: !!+s[t[0]] }), ' ' + t[1]);
      });

      var sv = btn('Enregistrer les réglages', function () {
        var b = { entities: ents, specs: specs };
        Object.keys(g).forEach(function (k) { b[k] = g[k].value; });
        Object.keys(toggles).forEach(function (k) { b[k] = toggles[k].querySelector('input').checked ? 1 : 0; });
        busy(sv, true);
        api('PUT', '/settings', b).then(function () { return api('GET', '/boot'); }).then(function (boot) { B = boot; toast('Réglages enregistrés'); vSettings(); }).catch(function (e) { busy(sv, false); fail(e); });
      }, 'primary');

      render(
        title('Réglages', B.isAdmin ? null : 'Seul un administrateur peut enregistrer ces réglages.', sv),
        card('Structures qui émettent les devis', h('div', null, entBox, btn('+ Ajouter une structure', function () { ents.push({ key: 'e' + Date.now(), name: '', prefix: 'D', color: '#b0124f' }); drawEnts(); }, 'ghost'))),
        card('Devis', h('div', { class: 'frcrm-form' },
          h('div', { class: 'frcrm-row' }, field('Structure par défaut', g.default_entity), field('Validité (jours)', g.validity_days), field('Acompte par défaut (%)', g.deposit)),
          field('Conditions de règlement par défaut', g.payment_terms),
          field('Mentions en bas de devis', g.legal))),
        card('Gabarit du cahier des charges de livraison', h('div', null,
          h('p', { class: 'frcrm-hint', text: 'Ces sections et leur texte servent de point de départ à chaque nouveau devis. La section « Contexte et objectifs » reprend automatiquement la problématique du client.' }),
          specBox, btn('+ Ajouter une section', function () { specs.push({ key: '', title: 'Nouvelle section', text: '' }); drawSpecs(); }, 'ghost'))),
        card('Alertes par e-mail', h('div', { class: 'frcrm-form' },
          field('Destinataires', g.notify_to, 'Séparez plusieurs adresses par une virgule.'),
          Object.keys(toggles).map(function (k) { return toggles[k]; }),
          h('div', { class: 'frcrm-row' }, field('Client « sans nouvelles » après (jours)', g.stale_days), field('Prévenir avant expiration d\'un devis (jours)', g.expire_warn)),
          h('p', { class: 'frcrm-hint', text: 'Conseil : sur un hébergement OVH, installez une extension d\'envoi SMTP (par exemple WP Mail SMTP) reliée à votre boîte mail, pour que ces alertes n\'arrivent pas en spam.' }))),
        h('div', { class: 'frcrm-actions frcrm-bottom' }, sv.cloneNode(true)));
      view.querySelector('.frcrm-bottom button').addEventListener('click', function () { sv.click(); });
    }).catch(fail);
  }

  /* ------------------------------------------------------------------ */
  /* Start                                                              */
  /* ------------------------------------------------------------------ */

  api('GET', '/boot').then(function (b) {
    B = b;
    shell();
    route();
  }).catch(function (e) {
    root.innerHTML = '';
    root.appendChild(h('p', { class: 'frcrm-empty', text: 'Le CRM n\'a pas pu démarrer : ' + e.message }));
  });
})();
