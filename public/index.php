<?php
declare(strict_types=1);
?><!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Disposition PDF Generator (Event-basiert)</title>
<style>
body{font-family:Arial,Helvetica,sans-serif;margin:32px;}
h1{margin:0 0 12px 0}
form{display:grid;grid-template-columns:1fr 1fr 1fr auto;gap:8px;max-width:980px}
label{font-size:12px;color:#444}
input,select{padding:8px;border:1px solid #ccc;border-radius:6px}
button{padding:10px 16px;border-radius:8px;border:0;cursor:pointer;background:#0f766e;color:#fff}
.note{margin-top:12px;color:#666;font-size:12px}
.small{font-size:12px;color:#666}
.hidden{display:none}
</style>
</head>
<body>
<h1>Disposition erzeugen (pro Event)</h1>

<form method="post" action="generate.php" id="gen-form">
  <div>
    <label>Project ID</label>
    <input type="number" name="project_id" id="project_id" min="1" required>
    <div class="small">Tippe Projekt-ID, dann werden die Jobs geladen.</div>
  </div>

  <div>
    <label>Job (Jobnummer)</label>
    <select name="job_id" id="job_id" required disabled>
      <option value="">— wähle einen Job —</option>
    </select>
    <div id="job_help" class="small">Wird nach Projekt-Auswahl befüllt.</div>
  </div>

  <div>
    <label>Event</label>
    <select name="event_id" id="event_id" required disabled>
      <option value="">— wähle ein Event —</option>
    </select>
    <div id="event_help" class="small">Wird nach Job-Auswahl befüllt.</div>
  </div>

  <div>
    <label>Filter</label>
    <input type="text" id="job_filter" placeholder="optional: Textfilter (Ort/Nummer/Datum)" disabled>
  </div>

  <div style="align-self:end">
    <button type="submit" id="submit_btn" disabled>Generieren</button>
  </div>
</form>

<div class="note">Template: <code>templates/doc_template.docx</code>. Ausgabe: PDF (Renderer vorhanden) oder DOCX.</div>

<script>
(function(){
  const pidInput   = document.getElementById('project_id');
  const jobSelect  = document.getElementById('job_id');
  const eventSelect = document.getElementById('event_id');
  const jobFilter  = document.getElementById('job_filter');
  const submitBtn  = document.getElementById('submit_btn');
  const jobHelp    = document.getElementById('job_help');
  const eventHelp  = document.getElementById('event_help');

  let debounce = null;
  let cacheJobs = [];
  let cacheEvents = [];

  function msg(str){
    jobHelp.textContent = str;
  }

  function resetEvents(){
    eventSelect.innerHTML = '<option value="">— wähle ein Event —</option>';
    eventSelect.disabled = true;
    eventHelp.textContent = 'Wird nach Job-Auswahl befüllt.';
    submitBtn.disabled = true;
    cacheEvents = [];
  }

  function populateJobs(rows){
    jobSelect.innerHTML = '<option value="">— wähle einen Job —</option>';
    rows.forEach(r => {
      const opt = document.createElement('option');
      opt.value = r.id;
      const num = (r.jobnummer && r.jobnummer !== '') ? r.jobnummer : ('#' + (r.id ?? ''));
      const dt  = r.datum ?? '';
      const beg = r.uhrzeit_beginn ?? '';
      const end = r.uhrzeit_ende ?? '';
      const time = (beg || end) ? (beg + (end ? '–' + end : '')) : '';
      opt.textContent = `${num} ${dt} ${time}`.trim();
      jobSelect.appendChild(opt);
    });
    jobSelect.disabled = rows.length === 0;
    jobFilter.disabled = rows.length === 0;
    submitBtn.disabled = true;
    resetEvents();
  }

  function populateEvents(rows){
    eventSelect.innerHTML = '<option value="">— wähle ein Event —</option>';
    rows.forEach(r => {
      const opt = document.createElement('option');
      opt.value = r.id;
      const dt  = r.datum_beginn ?? '';
      const beg = r.uhrzeit_beginn ?? '';
      const end = r.uhrzeit_ende ?? '';
      const time = (beg || end) ? (beg + (end ? '–' + end : '')) : '';
      const art  = r.art ? `(${r.art})` : '';
      opt.textContent = `${dt} ${time} ${art}`.trim();
      eventSelect.appendChild(opt);
    });
    eventSelect.disabled = rows.length === 0;
    eventHelp.textContent = rows.length ? 'Event gewählt, dann Disposition generieren.' : 'Keine Events gefunden.';
    submitBtn.disabled = !eventSelect.value;
  }

  function applyFilter(){
    const q = jobFilter.value.toLowerCase().trim();
    if(!q){ populateJobs(cacheJobs); return; }
    const rows = cacheJobs.filter(r => {
      return [r.id, r.jobnummer, r.datum, r.uhrzeit_beginn, r.uhrzeit_ende, r.ort]
        .map(x => (x==null?'':String(x).toLowerCase()))
        .some(s => s.includes(q));
    });
    populateJobs(rows);
  }

  function fetchJobs(pid){
    jobSelect.innerHTML = '<option value="">Lade Jobs…</option>';
    jobSelect.disabled = true;
    jobFilter.disabled = true;
    submitBtn.disabled = true;
    resetEvents();
    msg('Lade…');

    fetch('jobs_by_project.php?project_id=' + encodeURIComponent(pid), {credentials:'same-origin'})
      .then(r => r.json())
      .then(data => {
        if (data.error) {
          console.error('Endpoint error:', data.error, data.tried || []);
          msg('Fehler: ' + data.error);
          cacheJobs = [];
          populateJobs(cacheJobs);
          return;
        }
        if (!Array.isArray(data.items)) {
          console.error('Unerwartete Antwort:', data);
          msg('Fehlerhafte Antwort vom Server.');
          cacheJobs = [];
          populateJobs(cacheJobs);
          return;
        }
        cacheJobs = data.items;
        msg(data.items.length ? data.items.length + ' Jobs geladen.' : 'Keine Jobs gefunden.');
        populateJobs(cacheJobs);
        jobFilter.disabled = data.items.length === 0;
      })
      .catch(err => {
        console.error(err);
        msg('Fehler beim Laden.');
        cacheJobs = [];
        populateJobs(cacheJobs);
      });
  }

  function fetchEvents(jid){
    resetEvents();
    if (!jid) return;

    eventSelect.innerHTML = '<option value="">Lade Events…</option>';
    eventSelect.disabled = true;
    eventHelp.textContent = 'Lade…';
    submitBtn.disabled = true;

    fetch('events_by_job.php?job_id=' + encodeURIComponent(jid), {credentials:'same-origin'})
      .then(r => r.json())
      .then(data => {
        if (data.error) {
          console.error('Endpoint error:', data.error);
          eventHelp.textContent = 'Fehler: ' + data.error;
          cacheEvents = [];
          populateEvents(cacheEvents);
          return;
        }
        if (!Array.isArray(data.items)) {
          console.error('Unerwartete Antwort:', data);
          eventHelp.textContent = 'Fehlerhafte Antwort vom Server.';
          cacheEvents = [];
          populateEvents(cacheEvents);
          return;
        }
        cacheEvents = data.items;
        populateEvents(cacheEvents);
      })
      .catch(err => {
        console.error(err);
        eventHelp.textContent = 'Fehler beim Laden.';
        cacheEvents = [];
        populateEvents(cacheEvents);
      });
  }

  // Debounced loading when project_id changes
  pidInput.addEventListener('input', function(){
    const pid = this.value.trim();
    if (!pid) {
      cacheJobs = [];
      populateJobs(cacheJobs);
      msg('Projekt-ID eingeben.');
      return;
    }
    clearTimeout(debounce);
    debounce = setTimeout(() => fetchJobs(pid), 200);
  });

  jobFilter.addEventListener('input', applyFilter);

  jobSelect.addEventListener('change', () => {
    const jid = jobSelect.value;
    fetchEvents(jid);
  });

  eventSelect.addEventListener('change', () => {
    submitBtn.disabled = !eventSelect.value;
  });
})();
</script>
</body>
</html>
