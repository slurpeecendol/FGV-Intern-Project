<?php
session_start();
require_once 'config/db.php';
require_once 'includes/auth_check.php';
$db = getDB();
$page_title = 'IT Request Form';
$active_nav = 'it_request_form';
require_once 'includes/layout.php';
?>

<style>
/* ── Page header ── */
.itrf-page-title {
  font-family: 'Syne', sans-serif;
  font-size: 28px;
  font-weight: 800;
  color: var(--text);
  letter-spacing: -0.5px;
  margin-bottom: 14px;
}
.itrf-back-link {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 13px;
  font-weight: 600;
  color: var(--accent);
  text-decoration: none;
  padding: 6px 14px;
  border: 1.5px solid rgba(var(--accent-rgb),.3);
  border-radius: 7px;
  background: rgba(var(--accent-rgb),.05);
  margin-bottom: 18px;
  transition: background .15s;
}
.itrf-back-link:hover { background: rgba(var(--accent-rgb),.12); color: var(--accent); }
.itrf-info-banner {
  display: flex;
  align-items: flex-start;
  gap: 12px;
  background: #eff6ff;
  border-left: 4px solid #3b82f6;
  border-radius: 0 8px 8px 0;
  padding: 13px 18px;
  margin-bottom: 22px;
  font-size: 13px;
  color: #1e40af;
}
.itrf-info-banner i { font-size: 17px; flex-shrink: 0; margin-top: 1px; }
.itrf-info-banner span { color: #dc2626; font-weight: 700; }

/* ── Card ── */
.itrf-card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 14px;
  overflow: hidden;
  box-shadow: 0 1px 3px rgba(0,0,0,0.06);
}

/* ── Top strip ── */
.itrf-strip {
  background: var(--body-bg);
  border-bottom: 1px solid var(--border);
  padding: 12px 24px;
  display: flex;
  align-items: center;
  gap: 14px;
}
.itrf-badge {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 11px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: .06em;
  padding: 3px 10px;
  border-radius: 20px;
  background: rgba(var(--accent-rgb),.12);
  color: var(--accent);
}
.itrf-badge-dot {
  width: 5px; height: 5px;
  border-radius: 50%;
  background: currentColor;
}
.itrf-strip-note {
  font-size: 11.5px;
  color: var(--muted);
  margin-left: auto;
}

/* ── Subject row ── */
.itrf-subject {
  padding: 20px 24px 18px;
  border-bottom: 1px solid var(--border);
  display: grid;
  grid-template-columns: 1fr 180px;
  gap: 16px;
  align-items: start;
}
.itrf-subject-main { display: flex; flex-direction: column; }
.itrf-status-box { display: flex; flex-direction: column; }

/* ── Tabs ── */
.itrf-tabs {
  display: flex;
  border-bottom: 1px solid var(--border);
  background: var(--body-bg);
  padding: 0 24px;
}
.itrf-tab {
  display: flex;
  align-items: center;
  gap: 7px;
  font-size: 13px;
  font-weight: 500;
  color: var(--muted);
  padding: 13px 18px 11px;
  border: none;
  background: none;
  cursor: pointer;
  border-bottom: 2.5px solid transparent;
  margin-bottom: -1px;
  transition: color .15s;
  font-family: 'Plus Jakarta Sans', sans-serif;
}
.itrf-tab:hover { color: var(--text); }
.itrf-tab.active {
  color: var(--accent);
  border-bottom-color: var(--accent);
  font-weight: 700;
}
.itrf-tab i { font-size: 14px; }

/* ── Tab panels ── */
.itrf-panel { display: none; }
.itrf-panel.active { display: block; }

/* ── Section ── */
.itrf-section {
  border-top: 1px solid var(--border);
}
.itrf-section-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 15px 24px;
}
.itrf-section-title {
  font-family: 'Syne', sans-serif;
  font-size: 13.5px;
  font-weight: 700;
  color: var(--text);
}
.itrf-section-handle {
  font-size: 15px;
  letter-spacing: 2px;
  color: var(--muted);
  opacity: .5;
}
.itrf-section-body {
  padding: 4px 24px 26px;
}

/* ── Fields ── */
.itrf-label {
  font-size: 12px;
  font-weight: 600;
  color: var(--text);
  margin-bottom: 6px;
  display: flex;
  align-items: center;
  gap: 3px;
}
.itrf-req { color: var(--red); font-size: 13px; }
.itrf-hint { font-size: 11px; color: var(--muted); margin-top: 4px; }

.itrf-input {
  width: 100%;
  font-family: 'Plus Jakarta Sans', sans-serif;
  font-size: 13px;
  color: var(--text);
  background: var(--surface);
  border: 1.5px solid var(--border);
  border-radius: 8px;
  padding: 9px 12px;
  outline: none;
  transition: border-color .15s, box-shadow .15s;
}
.itrf-input:focus {
  border-color: var(--accent);
  box-shadow: 0 0 0 3px rgba(var(--accent-rgb),.1);
}
.itrf-input::placeholder { color: var(--muted); opacity: .7; }

textarea.itrf-input { resize: vertical; min-height: 100px; }

select.itrf-input {
  appearance: none;
  background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%2364748b' stroke-width='2'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");
  background-repeat: no-repeat;
  background-position: right 10px center;
  padding-right: 32px;
  cursor: pointer;
}

/* ── Grid ── */
.g2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.g3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; }
.g4 { display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: 14px; }
.fg { margin-bottom: 16px; }
.fg:last-child { margin-bottom: 0; }

/* ── Request type row ── */
.itrf-type-row {
  display: grid;
  grid-template-columns: 210px 1fr;
  gap: 28px;
}
.itrf-sub-label {
  font-size: 10.5px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: .08em;
  color: var(--muted);
  margin-bottom: 10px;
}

/* ── Check/Radio ── */
.check-stack { display: flex; flex-direction: column; gap: 8px; margin-bottom: 0; }
.check-item {
  display: flex; align-items: center; gap: 8px;
  font-size: 13px; cursor: pointer; user-select: none; color: var(--text);
}
.check-item input[type="checkbox"],
.check-item input[type="radio"] {
  width: 15px; height: 15px;
  accent-color: var(--accent);
  cursor: pointer; flex-shrink: 0;
}
.item-chips {
  display: flex; flex-wrap: wrap; gap: 8px 16px; margin-bottom: 18px;
}
.chip-item {
  display: flex; align-items: center; gap: 6px;
  font-size: 12.5px; cursor: pointer; user-select: none; color: var(--text);
  white-space: nowrap;
}
.chip-item input { width: 14px; height: 14px; accent-color: var(--accent); cursor: pointer; }

/* ── Upload ── */
.upload-notice {
  border-radius: 7px;
  padding: 10px 13px;
  font-size: 11.5px;
  line-height: 1.65;
  margin-bottom: 8px;
}
.upload-notice.warn {
  background: #fffbeb;
  border: 1px solid #fcd34d;
  color: #78350f;
}
.upload-notice.warn strong { color: var(--red); }
.upload-notice.info {
  background: #f0f9ff;
  border: 1px solid #bae6fd;
  color: #0c4a6e;
}
.upload-row {
  display: flex; align-items: center; gap: 10px;
  border: 1.5px solid var(--border); border-radius: 8px;
  padding: 8px 10px; background: var(--surface);
}
.upload-row input[type="file"] { display: none; }
.upload-filename { flex: 1; font-size: 12.5px; color: var(--muted); }
.btn-browse {
  font-family: 'Plus Jakarta Sans', sans-serif;
  font-size: 12px; font-weight: 600;
  padding: 6px 16px;
  background: var(--body-bg);
  border: 1.5px solid var(--border);
  border-radius: 7px; color: var(--text);
  cursor: pointer; transition: background .15s;
}
.btn-browse:hover { background: var(--border); }

/* ── Action section ── */
.itrf-actions {
  border-top: 1px solid var(--border);
}
.itrf-action-header {
  display: flex; align-items: center; justify-content: space-between;
  padding: 15px 24px;
}
.itrf-action-title {
  font-family: 'Syne', sans-serif;
  font-size: 13.5px; font-weight: 700; color: var(--text);
}
.itrf-action-btns {
  padding: 0 24px 24px;
  display: flex; gap: 10px;
}
.btn-submit {
  font-family: 'Plus Jakarta Sans', sans-serif;
  font-size: 13px; font-weight: 700;
  padding: 9px 24px;
  background: var(--accent); color: white;
  border: none; border-radius: 8px;
  cursor: pointer; transition: background .15s;
}
.btn-submit:hover { background: var(--accent-h); }
.btn-draft {
  font-family: 'Plus Jakarta Sans', sans-serif;
  font-size: 13px; font-weight: 600;
  padding: 9px 20px;
  background: var(--body-bg);
  border: 1.5px solid var(--border);
  border-radius: 8px; color: var(--text);
  cursor: pointer; transition: background .15s;
}
.btn-draft:hover { background: var(--border); }

/* ── Coming soon ── */
.coming-soon {
  padding: 60px 24px; text-align: center; color: var(--muted);
}
.coming-soon i { font-size: 36px; opacity: .2; display: block; margin-bottom: 12px; }
.coming-soon p { font-size: 13.5px; }
</style>

<h1 class="itrf-page-title">IT Service Request Form</h1>
<a href="dashboard.php" class="itrf-back-link">
  <i class="bi bi-house-fill"></i> Back to IT Home
</a>
<div class="itrf-info-banner">
  <i class="bi bi-exclamation-triangle-fill"></i>
  <div>Please fill up the form. The field with <span>*</span> is mandatory.</div>
</div>

<div class="itrf-card">

  <!-- Subject + Status -->
  <div class="itrf-subject">
    <div class="itrf-subject-main">
      <div class="itrf-label">Subject</div>
      <input class="itrf-input" type="text" placeholder="please fill in the request subject" maxlength="200"/>
      <div class="itrf-hint">(i) Max 200 characters</div>
    </div>
    <div class="itrf-status-box">
      <div class="itrf-label">Status</div>
      <input class="itrf-input" type="text" value="New" readonly style="background:var(--body-bg);color:var(--muted);cursor:default;"/>
    </div>
  </div>

  <!-- Request Type & Item label + Tabs -->
  <div style="padding: 20px 24px 0; border-top: 1px solid var(--border);">
    <div style="font-family:'Syne',sans-serif; font-size:15px; font-weight:700; color:var(--text); margin-bottom:14px;">Request Type &amp; Item</div>
  </div>
  <div class="itrf-tabs" style="border-top:none; margin-top:0;">
    <button class="itrf-tab active" onclick="switchTab('hardware', this)">
      <i class="bi bi-laptop"></i> Hardware
    </button>
    <button class="itrf-tab" onclick="switchTab('software', this)">
      <i class="bi bi-code-slash"></i> Software
    </button>
    <button class="itrf-tab" onclick="switchTab('system', this)">
      <i class="bi bi-hdd-network"></i> System
    </button>
    <button class="itrf-tab" onclick="switchTab('service', this)">
      <i class="bi bi-headset"></i> Service
    </button>
  </div>

  <!-- ═══ HARDWARE PANEL ═══ -->
  <div class="itrf-panel active" id="panel-hardware">

    <!-- 1. Request Type & Item -->
    <div class="itrf-section">
      <div class="itrf-section-header">
        <span class="itrf-section-title">Request Type &amp; Item</span>
        <span class="itrf-section-handle">///</span>
      </div>
      <div class="itrf-section-body">
        <div class="itrf-type-row">
          <!-- Left -->
          <div>
            <div class="itrf-sub-label">Type of Request – Hardware</div>
            <div class="check-stack">
              <label class="check-item"><input type="radio" name="reqType" value="new"/> New</label>
              <label class="check-item"><input type="radio" name="reqType" value="replacement" checked/> Replacement</label>
              <label class="check-item"><input type="radio" name="reqType" value="transfer_staff"/> Transfer to Other Staff</label>
              <label class="check-item"><input type="radio" name="reqType" value="transfer_company"/> Transfer to Other Company</label>
            </div>
          </div>
          <!-- Right -->
          <div>
            <div class="itrf-sub-label">Type of Item</div>
            <div class="item-chips">
              <label class="chip-item"><input type="checkbox" checked/> Laptop</label>
              <label class="chip-item"><input type="checkbox"/> Desktop PC</label>
              <label class="chip-item"><input type="checkbox"/> Printer</label>
              <label class="chip-item"><input type="checkbox"/> Handphone</label>
              <label class="chip-item"><input type="checkbox"/> Tablet</label>
              <label class="chip-item"><input type="checkbox"/> IP Phone</label>
              <label class="chip-item"><input type="checkbox"/> Switch/Hub</label>
              <label class="chip-item"><input type="checkbox"/> UPS</label>
              <label class="chip-item"><input type="checkbox"/> Allow Install Software</label>
              <label class="chip-item"><input type="checkbox"/> Walkie-Talkie</label>
              <label class="chip-item"><input type="checkbox"/> Allow USB Drive</label>
              <label class="chip-item"><input type="checkbox"/> Color Printing Quota</label>
              <label class="chip-item"><input type="checkbox"/> Other (describe in details section)</label>
            </div>
            <div class="fg">
              <div class="itrf-label">PC/Laptop No. <span class="itrf-req">*</span></div>
              <input class="itrf-input" type="text" style="max-width:360px"/>
              <div class="itrf-hint">Please provide your current PC/Laptop No.</div>
            </div>
            <div class="fg">
              <div class="itrf-label">Printer No. <span class="itrf-req">*</span></div>
              <input class="itrf-input" type="text" style="max-width:360px"/>
              <div class="itrf-hint">Please provide your current Printer No.</div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- 2. Type of User & Justifications -->
    <div class="itrf-section">
      <div class="itrf-section-header">
        <span class="itrf-section-title">Type of user &amp; Justifications</span>
        <span class="itrf-section-handle">///</span>
      </div>
      <div class="itrf-section-body">
        <div class="g2 fg">
          <div>
            <div class="fg">
              <div class="itrf-label">Type of user <span class="itrf-req">*</span></div>
              <select class="itrf-input">
                <option value="">-- Select --</option>
                <option>New Staff</option>
                <option>Existing Staff</option>
                <option selected>Resign</option>
                <option>Contractor</option>
              </select>
            </div>
            <div class="fg">
              <div class="itrf-label">Exit Date <span class="itrf-req">*</span></div>
              <input class="itrf-input" type="date" style="max-width:220px"/>
              <div class="itrf-hint">MM/DD/YYYY</div>
            </div>
          </div>
          <div>
            <div class="itrf-label">Justifications <span class="itrf-req">*</span></div>
            <textarea class="itrf-input" placeholder="Please describe your request in details..."></textarea>
          </div>
        </div>
        <!-- Upload -->
        <div class="itrf-label" style="margin-bottom:8px">Upload file</div>
        <div class="upload-notice warn">
          <strong>(!) Bagi permohonan pertukaran laptop/desktop,</strong> sila sertakan <strong>Report Diagnosis dari Prodata</strong>. Permohonan akan ditolak sekiranya Report Diagnosis tidak disertakan.
        </div>
        <div class="upload-notice info">
          Sila pastikan nama lampiran tidak mengandungi simbol berikut: &amp; @ # $ % ^ * ( ) { } [ ] \ / : ' "
          dan saiz lampiran tidak melebihi <strong>2MB</strong>
        </div>
        <div class="upload-row">
          <span class="upload-filename" id="hw-upload-name">Choose file</span>
          <input type="file" id="hw-file-input"
            onchange="document.getElementById('hw-upload-name').textContent = this.files[0]?.name || 'Choose file'"/>
          <button class="btn-browse" onclick="document.getElementById('hw-file-input').click()">Browse</button>
        </div>
      </div>
    </div>

    <!-- 3. User Details -->
    <div class="itrf-section">
      <div class="itrf-section-header">
        <span class="itrf-section-title">User Details</span>
        <span class="itrf-section-handle">///</span>
      </div>
      <div class="itrf-section-body">
        <div class="g2 fg">
          <div class="fg">
            <div class="itrf-label">Name <span class="itrf-req">*</span></div>
            <input class="itrf-input" type="text"/>
          </div>
          <div class="fg">
            <div class="itrf-label">Email <span class="itrf-req">*</span></div>
            <input class="itrf-input" type="email"/>
          </div>
        </div>
        <div class="fg">
          <div class="itrf-label">Address <span class="itrf-req">*</span></div>
          <input class="itrf-input" type="text"/>
        </div>
        <div class="g4">
          <div class="fg">
            <div class="itrf-label">Department <span class="itrf-req">*</span></div>
            <input class="itrf-input" type="text"/>
          </div>
          <div class="fg">
            <div class="itrf-label">Designation <span class="itrf-req">*</span></div>
            <input class="itrf-input" type="text"/>
          </div>
          <div class="fg">
            <div class="itrf-label">Staff ID <span class="itrf-req">*</span></div>
            <input class="itrf-input" type="text" placeholder="12345678"/>
          </div>
          <div class="fg">
            <div class="itrf-label">Contact No. <span class="itrf-req">*</span></div>
            <input class="itrf-input" type="text"/>
          </div>
        </div>
      </div>
    </div>

    <!-- 4. Requester Details -->
    <div class="itrf-section">
      <div class="itrf-section-header">
        <span class="itrf-section-title">Requester Details</span>
        <span class="itrf-section-handle">///</span>
      </div>
      <div class="itrf-section-body">
        <div class="g3 fg">
          <div class="fg">
            <div class="itrf-label">Name <span class="itrf-req">*</span></div>
            <input class="itrf-input" type="text" placeholder="Enter a name or email address..."/>
          </div>
          <div class="fg">
            <div class="itrf-label">Department <span class="itrf-req">*</span></div>
            <input class="itrf-input" type="text"/>
          </div>
          <div class="fg">
            <div class="itrf-label">Staff ID <span class="itrf-req">*</span></div>
            <input class="itrf-input" type="text"/>
          </div>
        </div>
        <div class="g3">
          <div class="fg">
            <div class="itrf-label">Designation <span class="itrf-req">*</span></div>
            <input class="itrf-input" type="text"/>
          </div>
          <div class="fg">
            <div class="itrf-label">Contact <span class="itrf-req">*</span></div>
            <input class="itrf-input" type="text"/>
          </div>
          <div class="fg">
            <div class="itrf-label">Company <span class="itrf-req">*</span></div>
            <select class="itrf-input">
              <option value="">&lt; Select Company &gt;</option>
              <option>FJB Johor Bulkers Sdn Bhd</option>
              <option>FGV Holdings Berhad</option>
              <option>FGV Plantation</option>
            </select>
          </div>
        </div>
      </div>
    </div>

    <!-- 5. Approver Details -->
    <div class="itrf-section">
      <div class="itrf-section-header">
        <span class="itrf-section-title">Approver Details</span>
        <span class="itrf-section-handle">///</span>
      </div>
      <div class="itrf-section-body">
        <div class="fg">
          <div class="itrf-label">Name <span class="itrf-req">*</span></div>
          <input class="itrf-input" type="text"/>
        </div>
        <div class="g4">
          <div class="fg">
            <div class="itrf-label">Department</div>
            <input class="itrf-input" type="text"/>
          </div>
          <div class="fg">
            <div class="itrf-label">Designation</div>
            <input class="itrf-input" type="text"/>
          </div>
          <div class="fg">
            <div class="itrf-label">Contact</div>
            <input class="itrf-input" type="text"/>
          </div>
          <div class="fg">
            <div class="itrf-label">Company <span class="itrf-req">*</span></div>
            <select class="itrf-input">
              <option value="">&lt; Select Company &gt;</option>
              <option>FJB Johor Bulkers Sdn Bhd</option>
              <option>FGV Holdings Berhad</option>
              <option>FGV Plantation</option>
            </select>
          </div>
        </div>
      </div>
    </div>

    <!-- Action -->
    <div class="itrf-actions">
      <div class="itrf-action-header">
        <span class="itrf-action-title">Action</span>
      </div>
      <div class="itrf-action-btns">
        <button class="btn-submit">Submit Form</button>
        <button class="btn-draft">Save As Draft</button>
      </div>
    </div>

  </div><!-- /hardware -->

  <!-- SOFTWARE -->
  <div class="itrf-panel" id="panel-software">
    <div class="coming-soon">
      <i class="bi bi-code-slash"></i>
      <p>Software section — coming soon.</p>
    </div>
  </div>

  <!-- SYSTEM -->
  <div class="itrf-panel" id="panel-system">
    <div class="coming-soon">
      <i class="bi bi-hdd-network"></i>
      <p>System section — coming soon.</p>
    </div>
  </div>

  <!-- SERVICE -->
  <div class="itrf-panel" id="panel-service">
    <div class="coming-soon">
      <i class="bi bi-headset"></i>
      <p>Service section — coming soon.</p>
    </div>
  </div>

</div><!-- /card -->

<script>
function switchTab(name, el) {
  document.querySelectorAll('.itrf-tab').forEach(b => b.classList.remove('active'));
  document.querySelectorAll('.itrf-panel').forEach(p => p.classList.remove('active'));
  el.classList.add('active');
  document.getElementById('panel-' + name).classList.add('active');
}
</script>

<?php require_once 'includes/layout_end.php'; ?>
