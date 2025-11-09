<?php
declare(strict_types=1);
?><!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Disposition PDF Generator</title>
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
<h1>Disposition erzeugen</h1>

<form method="post" action="generate.php" id="gen-form">
  <div>
    <label>Project ID</label>
    <input type="number" name="project_id" id="project_id" min="1" required>
    <div class="small">Tippe Projekt-ID, dann werden die Jobs geladen.</div>
  </div>

  <div>
    <label>Job ID</label>
    <select name="job_id" id="job_id" required disabled>
      <option value="">— wähle einen Job —</option>
    </select>
    <div id="job_help" class="small">Wird nach Projekt-Auswahl befüllt.</div>
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
  const pidInput = document.getElementById('project_id');
  const jobSelect = document.getElementById('job_id');
  const jobFilter = document.getElementById('job_filter');
  const submitBtn = document.getElementById('submit_btn');
  const jobHelp = document.getElementById('job_help');

  let cache = [];
  let debounce = null;

  function msg(text){ jobHelp.textContent = text; }

  function optionLabel(row){
    const id = row.id ?? '';
    const num = row.jobnummer ?? '';
    const dt  = row.datum ?? '';
    const beg = row.uhrzeit_beginn ?? '';
    const end = row.uhrzeit_ende ?? '';
    const ort = row.ort ?? '';
    const time = (beg || end) ? (beg + (end ? '–' + end : '')) : '';
    const mid = [dt, time].filter(Boolean).join(' ');
    const right = [mid, ort].filter(Boolean).join(' | ');
    const left  = ['#'+id, num].filter(Boolean).join(' ');
    return [left, right].filter(Boolean).join('  ·  ');
  }

  function populate(rows){
    jobSelect.innerHTML = '<option value="">— wähle einen Job —</option>';
    rows.forEach(r=>{
      const opt = document.createElement('option');
      opt.value = r.id;
      opt.textContent = optionLabel(r);
      jobSelect.appendChild(opt);
    });
    jobSelect.disabled = rows.length === 0;
    jobFilter.disabled = rows.length === 0;
    submitBtn.disabled = rows.length === 0 || !jobSelect.value;
    msg(rows.length ? `Gefundene Jobs: ${rows.length}` : 'Keine Jobs für dieses Projekt gefunden');
  }

  function applyFilter(){
    const q = jobFilter.value.toLowerCase().trim();
    if(!q){ populate(cache); return; }
    const rows = cache.filter(r => {
      return [r.id, r.jobnummer, r.datum, r.uhrzeit_beginn, r.uhrzeit_ende, r.ort]
        .map(x => (x==null?'':String(x).toLowerCase()))
        .some(s => s.includes(q));
    });
    populate(rows);
  }

  function fetchJobs(pid){
    jobSelect.innerHTML = '<option value="">Lade Jobs…</option>';
    jobSelect.disabled = true;
    jobFilter.disabled = true;
    submitBtn.disabled = true;
    msg('Lade…');

    fetch('jobs_by_project.php?project_id=' + encodeURIComponent(pid), {credentials:'same-origin'})
      .then(r => r.json()) // always parse JSON; endpoint always returns JSON
      .then(data => {
      if (data.error) {
        console.error('Endpoint error:', data.error, data.tried || []);
        msg('Fehler: ' + data.error);
        cache = [];
        populate(cache);
        return;
      }
      if (data.tried) {
        const picked = (data.tried.find(x => x.count > 0) || {}).fk || '—';
        const summary = data.tried.map(x => `${x.fk}:${x.count}`).join(', ');
        console.log('FK probe:', summary, 'picked:', picked);
        msg((data.items?.length || 0) ? `Gefundene Jobs: ${data.items.length} (FK=${picked})` : `Keine Jobs gefunden (Probe: ${summary})`);
      }
      cache = Array.isArray(data.items) ? data.items : [];
      populate(cache);
    })
;
  }

  pidInput.addEventListener('input', () => {
    const pid = parseInt(pidInput.value, 10);
    if(!pid || pid <= 0){
      cache = [];
      populate(cache);
      return;
    }
    clearTimeout(debounce);
    debounce = setTimeout(() => fetchJobs(pid), 200);
  });

  jobFilter.addEventListener('input', applyFilter);
  jobSelect.addEventListener('change', () => { submitBtn.disabled = !jobSelect.value; });
})();
</script>
</body>
</html>
