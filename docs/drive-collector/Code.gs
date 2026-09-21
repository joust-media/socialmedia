/**
 * Joust portal — Google Drive storage collector (Google Apps Script).
 *
 * Runs nightly in the agency Google account, measures My Drive with metadata-only access and POSTs a
 * snapshot to the portal's drive-ingest.php in parts. The portal (drive.php) only ever reads what
 * this script sent. Install steps: README.md next to this file.
 *
 * What it reads (scope https://www.googleapis.com/auth/drive.metadata.readonly only — no file
 * contents, no writes):
 *   Drive.About.get({fields: 'storageQuota,user'})              quota limit / usage / Drive / trash
 *   Drive.Files.list({q: "'me' in owners and trashed = false"}) every owned, non-trashed file and folder,
 *     1000 per page, fields id,name,mimeType,size,quotaBytesUsed,parents,modifiedTime,viewedByMeTime,
 *     createdTime,md5Checksum,webViewLink. quotaBytesUsed is the number that counts against storage.
 *
 * Division of labour (portal contract: drive-design.md §0):
 *   here    quota numbers, per-folder rollups (bytes / stale bytes / file count / last activity), which
 *           folder belongs to which client (children of CLIENTS_ROOT_FOLDER_ID, else the top-level My
 *           Drive folders; everything else is "(unfiled)"), the trimmed tree (4 levels below each client).
 *   portal  type buckets, idle days, score, offboard candidates, by-type totals, duplicates, old versions,
 *           burn rate / days-to-full from its history, which threshold alerts are due.
 *   To make that possible every file that uses quota (quotaBytesUsed > 0) is streamed to the portal page
 *   by page (?part=files), so nothing large is ever held here. Only the folder map and the per-folder
 *   aggregates stay in memory; when a run nears the 6-minute execution limit they are parked in the
 *   portal (?part=state), a one-off trigger continues one minute later and picks them back up.
 *
 * Script Properties (File > Project properties > Script properties, or the gear icon):
 *   PORTAL_INGEST_URL        https://joustmedia.com/portal/drive-ingest.php
 *   INGEST_SECRET            the drive_ingest_secret value from the portal's config.php (24+ chars)
 *   CLIENTS_ROOT_FOLDER_ID   optional — the folder whose child folders are the clients (default: My Drive itself)
 *   ALERT_EMAIL              where threshold emails go (80 / 90 / 95 % used, < 14 days to full)
 *   MIN_SEND_BYTES           optional — skip files smaller than this many bytes (default 0 = send every file that uses quota)
 *
 * Entry points: runNightly() (the trigger target; also fine to run by hand), setupTrigger() once,
 * verify() to check the connection, resetRun() to abandon a stuck run.
 */

var SCRIPT_VERSION = '1.0.0';
var PAGE_SIZE = 1000;
var TIME_BUDGET_MS = 5 * 60 * 1000;          // park the run before Apps Script's 6-minute limit
var RESUME_AFTER_MS = 60 * 1000;             // one-off trigger delay when a run is parked
var STATE_MAX_AGE_MS = 12 * 60 * 60 * 1000;  // a parked run older than this is abandoned, not resumed
var STALE_DAYS = 180;                        // must match DRIVE_STALE_DAYS in the portal's drive-lib.php
var TREE_DEPTH = 4;                          // levels kept below each client folder in the tree
var TREE_MAX_CHILDREN = 50;                  // largest children kept per tree node (the rest are counted in `truncated`)
var FOLDERS_PER_POST = 2000;
var FOLDER_MIME = 'application/vnd.google-apps.folder';
var FILE_FIELDS = 'nextPageToken, files(id,name,mimeType,size,quotaBytesUsed,parents,modifiedTime,viewedByMeTime,createdTime,md5Checksum,webViewLink)';
var LIST_QUERY = "'me' in owners and trashed = false";
var PROP_RUN_STATE = 'RUN_STATE';

// ------------------------------------------------------------------------------------------------
// Entry points
// ------------------------------------------------------------------------------------------------

/** Nightly entry (trigger target). Safe to run by hand. */
function runNightly() {
  run_(false);
}

/** Target of the one-off "continue" trigger a parked run schedules. */
function resumeRun_() {
  run_(true);
}

/** Create the nightly trigger (2–3 AM in the script's time zone). Run once after installing. */
function setupTrigger() {
  deleteTriggers_('runNightly');
  ScriptApp.newTrigger('runNightly').timeBased().everyDays(1).atHour(2).create();
  Logger.log('Nightly trigger installed (runNightly, 2–3 AM ' + Session.getScriptTimeZone() + ').');
}

/** Check properties, the portal secret and the Drive scope. Read the log (View > Logs / Executions). */
function verify() {
  var cfg = config_();
  var health = request_('GET', cfg.url + '?health=1', null, cfg);
  Logger.log('Portal health: ' + JSON.stringify(health));
  if (!health.migrated) Logger.log('Portal says the drive_* tables are missing — open migrate.php as admin first.');
  var about = Drive.About.get({ fields: 'storageQuota,user' });
  var q = quotaFromAbout_(about);
  Logger.log('Account ' + (about.user && about.user.emailAddress) + ': usage ' + fmtBytes_(q.usage) + ' of ' + (q.limit === null ? 'no limit' : fmtBytes_(q.limit)) + ' (Drive ' + fmtBytes_(q.drive) + ', trash ' + fmtBytes_(q.trash) + ')');
  var page = Drive.Files.list({ q: LIST_QUERY, pageSize: 5, fields: FILE_FIELDS });
  Logger.log('files.list works — first ' + ((page.files || []).length) + ' of the listing: ' + (page.files || []).map(function (f) { return f.name; }).join(', '));
  if (cfg.clientsRoot) {
    var root = Drive.Files.get(cfg.clientsRoot, { fields: 'id,name,mimeType' });
    Logger.log('CLIENTS_ROOT_FOLDER_ID = ' + root.name + ' (' + root.mimeType + ')');
  }
  Logger.log('OK — run setupTrigger() once, then runNightly() for the first snapshot.');
}

/** Abandon a parked run (clears the resume state and one-off triggers). The portal marks the partial snapshot failed after 48 h. */
function resetRun() {
  PropertiesService.getScriptProperties().deleteProperty(PROP_RUN_STATE);
  deleteTriggers_('resumeRun_');
  Logger.log('Run state cleared.');
}

// ------------------------------------------------------------------------------------------------
// The run
// ------------------------------------------------------------------------------------------------

function run_(resuming) {
  var startedAt = Date.now();
  var cfg = config_();
  var props = PropertiesService.getScriptProperties();
  var lock = LockService.getScriptLock();
  if (!lock.tryLock(30 * 1000)) {
    Logger.log('Another run is in progress — skipping.');
    return;
  }
  try {
    var state = readState_(props);
    var mem = { folders: {}, agg: {}, rootId: null };   // folders: id → {n,p,l,m}; agg: parentId → [bytes, staleBytes, count, lastMs]

    if (state && Date.now() - state.startedAt > STATE_MAX_AGE_MS) {
      Logger.log('Parked run ' + state.snapshotId + ' is too old — starting over.');
      state = null;
      props.deleteProperty(PROP_RUN_STATE);
    }
    if (state) {
      Logger.log('Resuming snapshot ' + state.snapshotId + ' after ' + state.pagesDone + ' pages.');
      var parked = request_('GET', cfg.url + '?part=state&snapshot_id=' + state.snapshotId, null, cfg);
      if (parked.state && parked.state.folders) {
        mem.folders = parked.state.folders;
        mem.agg = parked.state.agg || {};
        mem.rootId = parked.state.rootId || null;
      } else if (state.pagesDone > 0) {
        throw new Error('Parked state for snapshot ' + state.snapshotId + ' is missing on the portal — run resetRun() and try again.');
      }
    } else {
      if (resuming) { Logger.log('Nothing to resume.'); return; }
      state = begin_(cfg);
      props.setProperty(PROP_RUN_STATE, JSON.stringify(state));
    }
    if (!mem.rootId) mem.rootId = Drive.Files.get('root', { fields: 'id' }).id;

    // ---- stream pages ----
    var now = Date.now();
    var staleCutoff = now - STALE_DAYS * 86400000;
    while (true) {
      var page = Drive.Files.list({
        q: LIST_QUERY, pageSize: PAGE_SIZE, fields: FILE_FIELDS, pageToken: state.nextPageToken || undefined,
        corpora: 'user', includeItemsFromAllDrives: false, supportsAllDrives: false
      });
      var rows = [];
      var files = page.files || [];
      for (var i = 0; i < files.length; i++) {
        var f = files[i];
        var parent = (f.parents && f.parents.length) ? f.parents[0] : '';
        if (f.mimeType === FOLDER_MIME) {
          mem.folders[f.id] = { n: f.name || '', p: parent, l: f.webViewLink || '', m: f.modifiedTime || '' };
          state.foldersSeen++;
          continue;
        }
        var bytes = Number(f.quotaBytesUsed || f.size || 0) || 0;
        var lastMs = Math.max(Date.parse(f.modifiedTime || '') || 0, Date.parse(f.viewedByMeTime || '') || 0);
        var a = mem.agg[parent] || (mem.agg[parent] = [0, 0, 0, 0]);
        a[0] += bytes;
        if (lastMs && lastMs < staleCutoff) a[1] += bytes;
        a[2] += 1;
        if (lastMs > a[3]) a[3] = lastMs;
        state.filesSeen++;
        state.bytesSeen += bytes;
        if (bytes > 0 && bytes >= cfg.minSendBytes) {
          rows.push({
            id: f.id, name: f.name || '', mimeType: f.mimeType || '', size: String(bytes), parentId: parent || null,
            modifiedTime: f.modifiedTime || null, viewedByMeTime: f.viewedByMeTime || null, createdTime: f.createdTime || null,
            md5: f.md5Checksum || null, webViewLink: f.webViewLink || null
          });
        }
      }
      if (rows.length) post_('files', { snapshot_id: state.snapshotId, batch: state.pagesDone, rows: rows }, cfg);
      state.pagesDone++;
      state.nextPageToken = page.nextPageToken || '';
      props.setProperty(PROP_RUN_STATE, JSON.stringify(state));
      if (!state.nextPageToken) break;

      if (Date.now() - startedAt > TIME_BUDGET_MS) {
        post_('state', { snapshot_id: state.snapshotId, state: { folders: mem.folders, agg: mem.agg, rootId: mem.rootId } }, cfg);
        deleteTriggers_('resumeRun_');
        ScriptApp.newTrigger('resumeRun_').timeBased().after(RESUME_AFTER_MS).create();
        Logger.log('Parked after ' + state.pagesDone + ' pages (' + state.filesSeen + ' files); continuing in a minute.');
        return;
      }
    }

    // ---- roll up and finish ----
    finish_(cfg, state, mem, startedAt);
    props.deleteProperty(PROP_RUN_STATE);
    deleteTriggers_('resumeRun_');
  } finally {
    lock.releaseLock();
  }
}

/** About → quota → ?part=begin. Returns the fresh run state. */
function begin_(cfg) {
  var about = Drive.About.get({ fields: 'storageQuota,user' });
  var q = quotaFromAbout_(about);
  var takenAt = new Date().toISOString();
  var reply = post_('begin', {
    takenAt: takenAt,
    quota: { limit: q.limit === null ? null : String(q.limit), usage: String(q.usage), drive: String(q.drive), trash: String(q.trash), other: String(q.other) },
    quotaNote: q.note || '',
    account: { email: (about.user && about.user.emailAddress) || '' },
    meta: { scriptVersion: SCRIPT_VERSION }
  }, cfg);
  Logger.log('Snapshot ' + reply.snapshot_id + ' begun: ' + fmtBytes_(q.usage) + (q.limit === null ? '' : ' of ' + fmtBytes_(q.limit)) + (q.note ? ' (' + q.note + ')' : ''));
  return { snapshotId: reply.snapshot_id, takenAt: takenAt, startedAt: Date.now(), nextPageToken: '', pagesDone: 0, filesSeen: 0, foldersSeen: 0, bytesSeen: 0, quota: q };
}

/**
 * storageQuota → {limit|null, usage, drive, trash, other, note}. Semantics (Drive API v3 About):
 *   usage            everything counted against the account (Drive + Gmail + Photos)
 *   usageInDrive     Drive files INCLUDING the trash;  usageInDriveTrash  the trashed subset
 * so the segments are drive − trash, trash, other = usage − usageInDrive. Self-check: they must sum to
 * usage; when Drive exceeds the account total (it happens right after big deletes) `other` is clamped to
 * 0 and the note says so. limit is absent for unlimited accounts → null (the portal hides percent / countdown).
 */
function quotaFromAbout_(about) {
  var s = (about && about.storageQuota) || {};
  var usage = Number(s.usage || 0), drive = Number(s.usageInDrive || 0), trash = Number(s.usageInDriveTrash || 0);
  var limit = (s.limit === undefined || s.limit === null || s.limit === '') ? null : Number(s.limit);
  var note = '';
  if (trash > drive) { note = 'trash (' + fmtBytes_(trash) + ') reported above Drive usage; clamped'; trash = drive; }
  var other = usage - drive;
  if (other < 0) { note = (note ? note + '; ' : '') + 'Drive usage exceeds the account total by ' + fmtBytes_(-other) + '; other clamped to 0'; other = 0; }
  if ((drive - trash) + trash + other !== usage && !note) note = 'segments do not sum to usage';
  return { limit: limit, usage: usage, drive: drive, trash: trash, other: other, free: limit === null ? null : Math.max(0, limit - usage), note: note };
}

/** Folder rollups, clients, tree → folders / clients / tree / quickwins / finish → alert emails → alerted. */
function finish_(cfg, state, mem, startedAt) {
  var folders = mem.folders, agg = mem.agg, rootId = mem.rootId;
  var ids = Object.keys(folders);

  // children index
  var children = {};
  ids.forEach(function (id) {
    var p = folders[id].p || '';
    (children[p] || (children[p] = [])).push(id);
  });

  // client roots: children of CLIENTS_ROOT_FOLDER_ID (else of My Drive), slugified + de-duplicated
  var clientsParent = cfg.clientsRoot || rootId;
  var clientRoots = (children[clientsParent] || []).slice();
  var slugOf = {}, usedSlugs = {};
  clientRoots.sort(function (a, b) { return folders[a].n.localeCompare(folders[b].n); }).forEach(function (id) {
    var base = slugify_(folders[id].n) || 'client', slug = base, n = 2;
    while (usedSlugs[slug] || slug === 'unfiled') slug = base + '-' + (n++);
    usedSlugs[slug] = true;
    slugOf[id] = slug;
  });

  // depth / path / client per folder (walk down from every top: folders whose parent is unknown or the root)
  var info = {};   // id → {depth, path, client}
  var tops = ids.filter(function (id) { var p = folders[id].p; return !p || !folders[p]; });
  var stack = tops.map(function (id) { return [id, 0, '', null]; });
  while (stack.length) {
    var cur = stack.pop(), id = cur[0], depth = cur[1], parentPath = cur[2], client = cur[3];
    var path = parentPath + '/' + folders[id].n;
    var myClient = slugOf[id] || client;
    info[id] = { depth: depth, path: path, client: myClient };
    (children[id] || []).forEach(function (c) { if (!info[c]) stack.push([c, depth + 1, path, myClient]); });
  }

  // post-order rollup: total = own aggregate + children's totals
  var total = {};   // id → [bytes, stale, count, lastMs]
  var order = [];
  var seen = {};
  tops.forEach(function (top) {
    var st = [[top, false]];
    while (st.length) {
      var e = st.pop(), id = e[0];
      if (e[1]) { order.push(id); continue; }
      if (seen[id]) continue;
      seen[id] = true;
      st.push([id, true]);
      (children[id] || []).forEach(function (c) { if (!seen[c]) st.push([c, false]); });
    }
  });
  order.forEach(function (id) {
    var t = (agg[id] || [0, 0, 0, 0]).slice();
    (children[id] || []).forEach(function (c) {
      var ct = total[c] || [0, 0, 0, 0];
      t[0] += ct[0]; t[1] += ct[1]; t[2] += ct[2]; if (ct[3] > t[3]) t[3] = ct[3];
    });
    total[id] = t;
  });

  // grand totals over every file seen (including files whose parent is unknown or the root itself)
  var grand = [0, 0, 0, 0];
  Object.keys(agg).forEach(function (p) { var a = agg[p]; grand[0] += a[0]; grand[1] += a[1]; grand[2] += a[2]; if (a[3] > grand[3]) grand[3] = a[3]; });

  // folders part (root row first, then every folder) in batches
  var folderRows = [{ id: rootId, name: 'My Drive', parentId: null, path: '/', depth: 0, clientSlug: null,
                      bytes: String(grand[0]), staleBytes: String(grand[1]), fileCount: grand[2], lastActivityAt: iso_(grand[3]), webViewLink: null }];
  ids.forEach(function (id) {
    var t = total[id], inf = info[id];
    folderRows.push({ id: id, name: folders[id].n, parentId: folders[id].p || null, path: inf.path, depth: inf.depth + 1, clientSlug: inf.client,
                      bytes: String(t[0]), staleBytes: String(t[1]), fileCount: t[2], lastActivityAt: iso_(t[3]), webViewLink: folders[id].l || null });
  });
  for (var i = 0; i < folderRows.length; i += FOLDERS_PER_POST) {
    post_('folders', { snapshot_id: state.snapshotId, rows: folderRows.slice(i, i + FOLDERS_PER_POST) }, cfg);
  }

  // clients part: each client root's rollup + "(unfiled)" = everything else
  var clientRows = [], clientSum = [0, 0, 0, 0];
  clientRoots.forEach(function (id) {
    var t = total[id];
    clientSum[0] += t[0]; clientSum[1] += t[1]; clientSum[2] += t[2]; if (t[3] > clientSum[3]) clientSum[3] = t[3];
    clientRows.push({ slug: slugOf[id], name: folders[id].n, folderId: id, bytes: String(t[0]), staleBytes: String(t[1]), fileCount: t[2],
                      lastActivityAt: iso_(t[3]), webLink: folders[id].l || null });
  });
  var unfiledLast = 0;
  Object.keys(agg).forEach(function (p) {
    var inClient = p && info[p] && info[p].client;
    if (!inClient && agg[p][3] > unfiledLast) unfiledLast = agg[p][3];
  });
  clientRows.push({ slug: 'unfiled', name: '(unfiled)', folderId: null, bytes: String(Math.max(0, grand[0] - clientSum[0])), staleBytes: String(Math.max(0, grand[1] - clientSum[1])),
                    fileCount: Math.max(0, grand[2] - clientSum[2]), lastActivityAt: iso_(unfiledLast), webLink: null });
  post_('clients', { snapshot_id: state.snapshotId, rows: clientRows }, cfg);

  // tree: My Drive → client folders (+ "(unfiled)" holding the other top-level folders), TREE_DEPTH levels below each
  var node = function (id, depth) {
    var t = total[id], inf = info[id];
    var n = { id: id, name: folders[id].n, path: inf.path, bytes: String(t[0]), staleBytes: String(t[1]), fileCount: t[2], lastActivityAt: iso_(t[3]),
              webLink: folders[id].l || null, clientSlug: inf.client, truncated: 0, children: [] };
    if (depth > 0) {
      var kids = (children[id] || []).slice().sort(function (a, b) { return total[b][0] - total[a][0]; });
      n.truncated = Math.max(0, kids.length - TREE_MAX_CHILDREN);
      kids.slice(0, TREE_MAX_CHILDREN).forEach(function (c) { n.children.push(node(c, depth - 1)); });
    }
    return n;
  };
  var clientNodes = clientRoots.slice().sort(function (a, b) { return total[b][0] - total[a][0]; }).map(function (id) { return node(id, TREE_DEPTH); });
  // "(unfiled)" holds every top-level folder that is not a client (and, with CLIENTS_ROOT_FOLDER_ID set, not the clients root itself)
  var otherTops = tops.filter(function (id) { return !slugOf[id] && id !== cfg.clientsRoot; })
    .sort(function (a, b) { return total[b][0] - total[a][0]; });
  var unfiledRow = clientRows[clientRows.length - 1];
  var unfiledNode = { id: 'unfiled', name: '(unfiled)', path: '/(unfiled)', bytes: unfiledRow.bytes, staleBytes: unfiledRow.staleBytes, fileCount: unfiledRow.fileCount,
                      lastActivityAt: unfiledRow.lastActivityAt, webLink: null, clientSlug: null, truncated: Math.max(0, otherTops.length - TREE_MAX_CHILDREN), children: [] };
  otherTops.slice(0, TREE_MAX_CHILDREN).forEach(function (id) { unfiledNode.children.push(node(id, TREE_DEPTH)); });
  var tree = { id: rootId, name: 'My Drive', path: '/', bytes: String(grand[0]), staleBytes: String(grand[1]), fileCount: grand[2], lastActivityAt: iso_(grand[3]),
               webLink: null, clientSlug: null, truncated: 0, children: clientNodes.concat([unfiledNode]) };
  post_('tree', { snapshot_id: state.snapshotId, tree: tree }, cfg);

  post_('quickwins', { snapshot_id: state.snapshotId, trash: { bytes: String(state.quota ? state.quota.trash : 0) } }, cfg);

  var done = post_('finish', { snapshot_id: state.snapshotId,
    meta: { durationMs: Date.now() - startedAt, fileCount: state.filesSeen, folderCount: ids.length, listedBytes: String(grand[0]) } }, cfg);
  Logger.log('Snapshot ' + state.snapshotId + ' complete: ' + JSON.stringify(done.totals) + ' · ' + (done.projection && done.projection.label) + ' · alerts due: ' + JSON.stringify(done.alerts_due));

  // threshold emails — one per crossing; the portal remembers what was sent
  if (done.alerts_due && done.alerts_due.length && cfg.alertEmail) {
    var sent = [];
    done.alerts_due.forEach(function (kind) {
      try {
        MailApp.sendEmail(cfg.alertEmail, alertSubject_(kind, done), alertBody_(kind, done, cfg));
        sent.push(kind);
      } catch (e) {
        Logger.log('Alert email ' + kind + ' failed: ' + e);
      }
    });
    if (sent.length) post_('alerted', { snapshot_id: state.snapshotId, kinds: sent }, cfg);
  }
}

// ------------------------------------------------------------------------------------------------
// Alerts
// ------------------------------------------------------------------------------------------------

function alertSubject_(kind, done) {
  var pct = done.quota && done.quota.pctUsed !== null ? Math.round(done.quota.pctUsed) + '% used' : fmtBytes_(done.quota.usage) + ' used';
  if (kind === 'days14') return 'Google Drive: about ' + done.projection.daysToFull + ' days until full (' + pct + ')';
  return 'Google Drive is ' + pct + ' — ' + { pct80: 'time to plan', pct90: 'getting tight', pct95: 'almost full' }[kind];
}

function alertBody_(kind, done, cfg) {
  var q = done.quota, p = done.projection;
  var portal = cfg.url.replace(/drive-ingest\.php.*$/, 'drive.php');
  var lines = [
    'Nightly Drive check for ' + Session.getEffectiveUser().getEmail() + ':',
    '',
    '  Used     ' + fmtBytes_(q.usage) + (q.limit === null ? '' : ' of ' + fmtBytes_(q.limit) + ' (' + Math.round(q.pctUsed) + '%)'),
    '  Drive    ' + fmtBytes_(q.drive - q.trash) + '   Trash ' + fmtBytes_(q.trash) + '   Other (Gmail / Photos) ' + fmtBytes_(q.other),
    '  Trend    ' + (p.label || '') + (p.basisLabel ? ' ' + p.basisLabel : '') + (p.fullLabel ? ' — ' + p.fullLabel : ''),
    '  Offboard ' + (done.totals ? done.totals.candidates : 0) + ' candidate files over 100 MB idle for 6+ months',
    '',
    'Open the storage view: ' + portal,
    '',
    'This email is sent once per threshold crossing (80 / 90 / 95 % and under 14 days to full).'
  ];
  return lines.join('\n');
}

// ------------------------------------------------------------------------------------------------
// Portal HTTP
// ------------------------------------------------------------------------------------------------

function config_() {
  var p = PropertiesService.getScriptProperties();
  var url = (p.getProperty('PORTAL_INGEST_URL') || '').trim();
  var secret = (p.getProperty('INGEST_SECRET') || '').trim();
  if (!/^https:\/\/.+drive-ingest\.php$/.test(url)) throw new Error('Script property PORTAL_INGEST_URL must be the https URL of drive-ingest.php');
  if (secret.length < 24) throw new Error('Script property INGEST_SECRET must be the 24+ character drive_ingest_secret from config.php');
  return {
    url: url, secret: secret,
    clientsRoot: (p.getProperty('CLIENTS_ROOT_FOLDER_ID') || '').trim() || null,
    alertEmail: (p.getProperty('ALERT_EMAIL') || '').trim() || Session.getEffectiveUser().getEmail(),
    minSendBytes: Number(p.getProperty('MIN_SEND_BYTES') || 0) || 0
  };
}

/** POST one part; retries transient failures; throws on {ok:false}. A duplicate part is fine. */
function post_(part, body, cfg) {
  return request_('POST', cfg.url + '?part=' + part, body, cfg);
}

function request_(method, url, body, cfg) {
  var payload = body === null ? null : JSON.stringify(body);
  var headers = { Authorization: 'Bearer ' + cfg.secret, 'X-Drive-Secret': cfg.secret };
  if (payload !== null) headers['X-Drive-Part-Hash'] = sha256_(payload);
  var opts = { method: method.toLowerCase(), headers: headers, muteHttpExceptions: true, followRedirects: false };
  if (payload !== null) { opts.contentType = 'application/json'; opts.payload = payload; }
  var last = null;
  for (var attempt = 1; attempt <= 4; attempt++) {
    try {
      var res = UrlFetchApp.fetch(url, opts);
      var code = res.getResponseCode();
      var text = res.getContentText();
      var json = null;
      try { json = JSON.parse(text); } catch (e) { json = null; }
      if (code >= 200 && code < 300 && json && json.ok) return json;
      last = 'HTTP ' + code + ' ' + (json && json.error ? json.error : text.slice(0, 200));
      if (code >= 500 || code === 429 || code === 0) { Utilities.sleep(2000 * attempt); continue; }
      break;   // 4xx: retrying will not help
    } catch (e) {
      last = String(e);
      Utilities.sleep(2000 * attempt);
    }
  }
  throw new Error('Portal ' + method + ' ' + url.replace(cfg.secret, '…') + ' failed: ' + last);
}

// ------------------------------------------------------------------------------------------------
// Utilities
// ------------------------------------------------------------------------------------------------

function readState_(props) {
  var raw = props.getProperty(PROP_RUN_STATE);
  if (!raw) return null;
  try { var s = JSON.parse(raw); return (s && s.snapshotId) ? s : null; } catch (e) { return null; }
}

function deleteTriggers_(handler) {
  ScriptApp.getProjectTriggers().forEach(function (t) { if (t.getHandlerFunction() === handler) ScriptApp.deleteTrigger(t); });
}

function sha256_(text) {
  var bytes = Utilities.computeDigest(Utilities.DigestAlgorithm.SHA_256, text, Utilities.Charset.UTF_8);
  return bytes.map(function (b) { return ('0' + ((b + 256) % 256).toString(16)).slice(-2); }).join('');
}

/** Folder name → client slug ([a-z0-9-], ≤ 120); mirrors driveSlugify() in the portal so company names match. */
function slugify_(name) {
  return String(name || '').toLowerCase().normalize('NFKD').replace(/[\u0300-\u036f]/g, '').replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 120);
}

function iso_(ms) {
  return ms > 0 ? new Date(ms).toISOString() : null;
}

function fmtBytes_(n) {
  n = Number(n) || 0;
  var u = ['B', 'KB', 'MB', 'GB', 'TB'], i = 0;
  while (n >= 1024 && i < u.length - 1) { n /= 1024; i++; }
  return (i >= 3 ? n.toFixed(1) : Math.round(n)) + ' ' + u[i];
}
