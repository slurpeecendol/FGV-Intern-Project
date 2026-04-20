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
/* ══════════════════════════════════════════
   IT REQUEST FORM — WIZARD REDESIGN
══════════════════════════════════════════ */
.itr-wrap { max-width: 900px; }

.itr-page-title {
  font-family: 'Syne', sans-serif;
  font-size: 22px; font-weight: 800;
  color: var(--text); letter-spacing: -.3px; margin-bottom: 4px;
}
.itr-page-sub { font-size: 13px; color: var(--muted); margin-bottom: 24px; }

/* ── Progress ── */
.itr-progress { display: flex; align-items: center; gap: 0; margin-bottom: 28px; }
.itr-step { display: flex; align-items: center; gap: 8px; font-size: 12.5px; font-weight: 600; color: var(--muted); }
.itr-step-num {
  width: 26px; height: 26px; border-radius: 50%;
  border: 2px solid var(--border);
  display: flex; align-items: center; justify-content: center;
  font-size: 11px; font-weight: 700;
  background: var(--surface); color: var(--muted); transition: all .2s;
}
.itr-step.done .itr-step-num { background: var(--accent); border-color: var(--accent); color: white; }
.itr-step.active .itr-step-num { background: var(--accent); border-color: var(--accent); color: white; box-shadow: 0 0 0 4px rgba(242,140,40,.2); }
.itr-step.active { color: var(--accent); }
.itr-step.done { color: var(--text); }
.itr-step-line { flex: 1; height: 2px; background: var(--border); margin: 0 10px; border-radius: 2px; transition: background .3s; }
.itr-step-line.done { background: var(--accent); }

/* ── Step 1 type cards ── */
.itr-type-grid { display: grid; grid-template-columns: repeat(4,1fr); gap: 14px; margin-bottom: 8px; }
.itr-type-card {
  background: var(--surface); border: 2px solid var(--border);
  border-radius: 14px; padding: 22px 16px; cursor: pointer;
  transition: all .2s; text-align: center; position: relative; user-select: none;
}
.itr-type-card:hover { border-color: var(--accent); box-shadow: 0 4px 20px rgba(242,140,40,.15); transform: translateY(-2px); }
.itr-type-card.selected { border-color: var(--accent); background: rgba(242,140,40,.06); box-shadow: 0 4px 20px rgba(242,140,40,.2); }
.itr-type-card.locked { opacity: .35; cursor: not-allowed; pointer-events: none; filter: grayscale(.4); }
.itr-type-icon { width: 52px; height: 52px; border-radius: 14px; margin: 0 auto 14px; display: flex; align-items: center; justify-content: center; font-size: 24px; transition: all .2s; }
.hw-icon { background: rgba(59,130,246,.1); color: #3b82f6; }
.sw-icon { background: rgba(139,92,246,.1); color: #8b5cf6; }
.sys-icon { background: rgba(16,185,129,.1); color: #10b981; }
.svc-icon { background: rgba(242,140,40,.1); color: var(--accent); }
.itr-type-card.selected .itr-type-icon { transform: scale(1.08); }
.itr-type-name { font-family: 'Syne', sans-serif; font-size: 14px; font-weight: 700; color: var(--text); margin-bottom: 6px; }
.itr-type-desc { font-size: 11.5px; color: var(--muted); line-height: 1.5; }
.itr-type-check { position: absolute; top: 10px; right: 10px; width: 20px; height: 20px; border-radius: 50%; background: var(--accent); color: white; display: none; align-items: center; justify-content: center; font-size: 11px; }
.itr-type-card.selected .itr-type-check { display: flex; }
.itr-locked-note { font-size: 12px; color: var(--muted); text-align: center; margin-top: 6px; min-height: 18px; }

/* ── Step 2 ── */
#step2 { display: none; }
.itr-selected-banner {
  display: flex; align-items: center; gap: 12px;
  background: var(--surface); border: 1.5px solid var(--accent);
  border-radius: 12px; padding: 14px 18px; margin-bottom: 20px;
}
.itr-selected-pill {
  display: flex; align-items: center; gap: 8px;
  background: rgba(242,140,40,.1); color: var(--accent);
  font-size: 13px; font-weight: 700; padding: 5px 14px; border-radius: 20px;
}
.itr-selected-pill i { font-size: 15px; }
.itr-banner-text { font-size: 13px; color: var(--muted); flex: 1; }
.itr-change-btn {
  display: flex; align-items: center; gap: 6px;
  font-family: 'Plus Jakarta Sans', sans-serif;
  font-size: 12.5px; font-weight: 600; color: var(--red);
  background: rgba(239,68,68,.07); border: 1.5px solid rgba(239,68,68,.2);
  border-radius: 8px; padding: 7px 14px; cursor: pointer; transition: all .15s;
}
.itr-change-btn:hover { background: rgba(239,68,68,.14); }

.itr-subject-row { display: grid; grid-template-columns: 1fr 160px; gap: 14px; margin-bottom: 20px; }

/* ── Section cards ── */
.itr-section { background: var(--surface); border: 1px solid var(--border); border-radius: 12px; overflow: hidden; margin-bottom: 14px; }
.itr-section-head { display: flex; align-items: center; gap: 10px; padding: 14px 20px; border-bottom: 1px solid var(--border); background: var(--body-bg); }
.itr-section-num { width: 24px; height: 24px; border-radius: 50%; background: rgba(242,140,40,.12); color: var(--accent); font-size: 11px; font-weight: 800; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.itr-section-title { font-family: 'Syne', sans-serif; font-size: 13.5px; font-weight: 700; color: var(--text); flex: 1; }
.itr-section-body { padding: 20px; }

/* ── Fields ── */
.itr-label { font-size: 12px; font-weight: 600; color: var(--text); margin-bottom: 6px; display: flex; align-items: center; gap: 3px; }
.itr-req { color: var(--red); }
.itr-hint { font-size: 11px; color: var(--muted); margin-top: 4px; }
.itr-input { width: 100%; font-family: 'Plus Jakarta Sans', sans-serif; font-size: 13px; color: var(--text); background: var(--surface); border: 1.5px solid var(--border); border-radius: 8px; padding: 9px 12px; outline: none; transition: border-color .15s, box-shadow .15s; }
.itr-input:focus { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(242,140,40,.12); }
.itr-input::placeholder { color: var(--muted); opacity: .6; }
textarea.itr-input { resize: vertical; min-height: 100px; }
select.itr-input { appearance: none; cursor: pointer; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%2364748b' stroke-width='2'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 10px center; padding-right: 32px; }
.itr-input[readonly] { background: var(--body-bg); color: var(--muted); cursor: default; }

/* ── Grid ── */
.g2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.g3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 14px; }
.g4 { display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: 12px; }
.fg { margin-bottom: 14px; }
.fg:last-child { margin-bottom: 0; }

/* ── Radio pills ── */
.itr-radio-group { display: flex; flex-wrap: wrap; gap: 8px; }
.itr-radio-pill { display: flex; align-items: center; gap: 7px; padding: 7px 14px; border: 1.5px solid var(--border); border-radius: 20px; cursor: pointer; font-size: 12.5px; font-weight: 500; color: var(--text); background: var(--surface); transition: all .15s; user-select: none; }
.itr-radio-pill input { display: none; }
.itr-radio-pill:hover { border-color: var(--accent); color: var(--accent); }
.itr-radio-pill.checked { border-color: var(--accent); background: rgba(242,140,40,.08); color: var(--accent); font-weight: 600; }

/* ── Check chips ── */
.itr-check-grid { display: flex; flex-wrap: wrap; gap: 8px; }
.itr-check-chip { display: flex; align-items: center; gap: 6px; padding: 6px 13px; border: 1.5px solid var(--border); border-radius: 20px; cursor: pointer; font-size: 12.5px; color: var(--text); background: var(--surface); transition: all .15s; user-select: none; }
.itr-check-chip input { display: none; }
.itr-check-chip:hover { border-color: var(--accent); }
.itr-check-chip.checked { border-color: var(--accent); background: rgba(242,140,40,.08); color: var(--accent); font-weight: 600; }
.chip-dot { width: 7px; height: 7px; border-radius: 50%; border: 1.5px solid currentColor; transition: all .15s; }
.itr-check-chip.checked .chip-dot { background: var(--accent); border-color: var(--accent); }

/* ── Upload ── */
.itr-upload-zone { border: 2px dashed var(--border); border-radius: 10px; padding: 18px 20px; transition: border-color .15s; }
.itr-upload-zone:hover { border-color: var(--accent); }
.itr-notice { display: flex; align-items: flex-start; gap: 9px; font-size: 12px; line-height: 1.6; padding: 9px 12px; border-radius: 7px; margin-bottom: 8px; }
.itr-notice.warn { background: #fffbeb; border: 1px solid #fcd34d; color: #78350f; }
.itr-notice.warn strong { color: var(--red); }
.itr-notice.info { background: #f0f9ff; border: 1px solid #bae6fd; color: #0c4a6e; }
.itr-notice i { font-size: 14px; flex-shrink: 0; margin-top: 1px; }
.itr-upload-row { display: flex; align-items: center; gap: 10px; margin-top: 10px; }
.itr-upload-row input[type="file"] { display: none; }
.itr-filename { flex: 1; font-size: 12.5px; color: var(--muted); background: var(--body-bg); border: 1.5px solid var(--border); border-radius: 7px; padding: 8px 12px; }
.itr-browse-btn { font-family: 'Plus Jakarta Sans', sans-serif; font-size: 12.5px; font-weight: 600; padding: 8px 18px; background: var(--body-bg); border: 1.5px solid var(--border); border-radius: 7px; color: var(--text); cursor: pointer; transition: all .15s; }
.itr-browse-btn:hover { border-color: var(--accent); color: var(--accent); }

.itr-divider { border: none; border-top: 1px solid var(--border); margin: 18px 0; }

/* ── Actions ── */
.itr-action-bar { display: flex; gap: 10px; margin-top: 20px; padding-top: 20px; border-top: 1px solid var(--border); }
.itr-btn-submit { font-family: 'Plus Jakarta Sans', sans-serif; font-size: 13.5px; font-weight: 700; padding: 11px 28px; background: var(--accent); color: white; border: none; border-radius: 9px; cursor: pointer; transition: background .15s; display: flex; align-items: center; gap: 7px; }
.itr-btn-submit:hover { background: var(--accent-h); }
.itr-btn-draft { font-family: 'Plus Jakarta Sans', sans-serif; font-size: 13px; font-weight: 600; padding: 11px 22px; background: var(--body-bg); border: 1.5px solid var(--border); border-radius: 9px; color: var(--text); cursor: pointer; transition: all .15s; display: flex; align-items: center; gap: 7px; }
.itr-btn-draft:hover { border-color: var(--accent); color: var(--accent); }

@media (max-width: 720px) {
  .itr-type-grid { grid-template-columns: 1fr 1fr; }
  .g2,.g3,.g4,.itr-subject-row { grid-template-columns: 1fr; }
}
</style>

<div class="itr-wrap">
  <div class="itr-page-title">IT Request Form</div>
  <div class="itr-page-sub">Submit a new IT service request in just two steps.</div>

  <!-- Progress -->
  <div class="itr-progress">
    <div class="itr-step active" id="prog-step1"><div class="itr-step-num">1</div> Choose Request Type</div>
    <div class="itr-step-line" id="prog-line"></div>
    <div class="itr-step" id="prog-step2"><div class="itr-step-num">2</div> Fill in Details</div>
  </div>

  <!-- ══ STEP 1 ══ -->
  <div id="step1">
    <div class="itr-type-grid">
      <div class="itr-type-card" id="card-hardware" onclick="selectType('hardware')">
        <div class="itr-type-check"><i class="bi bi-check"></i></div>
        <div class="itr-type-icon hw-icon"><i class="bi bi-laptop"></i></div>
        <div class="itr-type-name">Hardware</div>
        <div class="itr-type-desc">Laptops, desktops, printers, phones &amp; peripherals</div>
      </div>
      <div class="itr-type-card" id="card-software" onclick="selectType('software')">
        <div class="itr-type-check"><i class="bi bi-check"></i></div>
        <div class="itr-type-icon sw-icon"><i class="bi bi-code-slash"></i></div>
        <div class="itr-type-name">Software</div>
        <div class="itr-type-desc">New or amendment to software &amp; applications</div>
      </div>
      <div class="itr-type-card" id="card-system" onclick="selectType('system')">
        <div class="itr-type-check"><i class="bi bi-check"></i></div>
        <div class="itr-type-icon sys-icon"><i class="bi bi-hdd-network"></i></div>
        <div class="itr-type-name">System</div>
        <div class="itr-type-desc">Email, SAP, FGVHub, Procurehere &amp; system access</div>
      </div>
      <div class="itr-type-card" id="card-service" onclick="selectType('service')">
        <div class="itr-type-check"><i class="bi bi-check"></i></div>
        <div class="itr-type-icon svc-icon"><i class="bi bi-wifi"></i></div>
        <div class="itr-type-name">Service</div>
        <div class="itr-type-desc">Network, IP reservation, port &amp; internet access</div>
      </div>
    </div>
    <div class="itr-locked-note" id="locked-note"></div>
  </div>

  <!-- ══ STEP 2 ══ -->
  <div id="step2">

    <div class="itr-selected-banner">
      <div class="itr-selected-pill"><i id="banner-icon"></i><span id="banner-label"></span></div>
      <div class="itr-banner-text">Complete all required fields below, then submit your request.</div>
      <button class="itr-change-btn" onclick="changeType()"><i class="bi bi-arrow-left"></i> Change Type</button>
    </div>

    <div class="itr-subject-row">
      <div class="fg" style="margin-bottom:0">
        <div class="itr-label">Request Subject <span class="itr-req">*</span></div>
        <input class="itr-input" type="text" placeholder="Briefly describe your request…" maxlength="200"/>
        <div class="itr-hint">(i) Max 200 characters</div>
      </div>
      <div class="fg" style="margin-bottom:0">
        <div class="itr-label">Status</div>
        <input class="itr-input" type="text" value="New" readonly/>
      </div>
    </div>

    <!-- ░ HARDWARE ░ -->
    <div id="form-hardware" style="display:none">
      <div class="itr-section">
        <div class="itr-section-head"><div class="itr-section-num">1</div><div class="itr-section-title">Request Type &amp; Item Selection</div></div>
        <div class="itr-section-body">
          <div class="g2">
            <div>
              <div class="itr-label">Type of Request <span class="itr-req">*</span></div>
              <div class="itr-radio-group" id="hw-req-type">
                <div class="itr-radio-pill" data-group="hw-req-type" onclick="pillSelect(this)" data-name="hwReq" data-value="new"> New</div>
                <div class="itr-radio-pill" data-group="hw-req-type" onclick="pillSelect(this)" data-name="hwReq" data-value="replacement"> Replacement</div>
                <div class="itr-radio-pill" data-group="hw-req-type" onclick="pillSelect(this)" data-name="hwReq" data-value="transfer_staff"> Transfer to Other Staff</div>
                <div class="itr-radio-pill" data-group="hw-req-type" onclick="pillSelect(this)" data-name="hwReq" data-value="transfer_company"> Transfer to Other Company</div>
              </div>
            </div>
            <div>
              <div class="itr-label" style="margin-bottom:10px">Type of Item <span class="itr-req">*</span></div>
              <div class="itr-check-grid">
                <div class="itr-check-chip" onclick="chipToggle(this)"><span class="chip-dot"></span>Laptop</div>
                <div class="itr-check-chip" onclick="chipToggle(this)"><span class="chip-dot"></span>Desktop PC</div>
                <div class="itr-check-chip" onclick="chipToggle(this)"><span class="chip-dot"></span>Printer</div>
                <div class="itr-check-chip" onclick="chipToggle(this)"><span class="chip-dot"></span>Handphone</div>
                <div class="itr-check-chip" onclick="chipToggle(this)"><span class="chip-dot"></span>Tablet</div>
                <div class="itr-check-chip" onclick="chipToggle(this)"><span class="chip-dot"></span>IP Phone</div>
                <div class="itr-check-chip" onclick="chipToggle(this)"><span class="chip-dot"></span>Switch/Hub</div>
                <div class="itr-check-chip" onclick="chipToggle(this)"><span class="chip-dot"></span>UPS</div>
                <div class="itr-check-chip" onclick="chipToggle(this)"><span class="chip-dot"></span>Walkie-Talkie</div>
                <div class="itr-check-chip" onclick="chipToggle(this)"><span class="chip-dot"></span>Allow Install Software</div>
                <div class="itr-check-chip" onclick="chipToggle(this)"><span class="chip-dot"></span>Allow USB Drive</div>
                <div class="itr-check-chip" onclick="chipToggle(this)"><span class="chip-dot"></span>Color Printing Quota</div>
                <div class="itr-check-chip" onclick="chipToggle(this)"><span class="chip-dot"></span>Other</div>
              </div>
            </div>
          </div>
          <hr class="itr-divider"/>
          <div class="g2">
            <div class="fg"><div class="itr-label">PC/Laptop No. <span class="itr-req">*</span></div><input class="itr-input" type="text"/><div class="itr-hint">Please provide your current PC/Laptop No.</div></div>
            <div class="fg"><div class="itr-label">Printer No. <span class="itr-req">*</span></div><input class="itr-input" type="text"/><div class="itr-hint">Please provide your current Printer No.</div></div>
          </div>
        </div>
      </div>

      <div class="itr-section">
        <div class="itr-section-head"><div class="itr-section-num">2</div><div class="itr-section-title">Type of User &amp; Justification</div></div>
        <div class="itr-section-body">
          <div class="g2 fg">
            <div>
              <div class="fg"><div class="itr-label">Type of User <span class="itr-req">*</span></div><select class="itr-input"><option value="">-- Select --</option><option>New Hire</option><option>Intern</option><option>Resign</option><option>Existing</option><option>Vendor</option></select></div>
              <div class="fg"><div class="itr-label">Exit Date <span class="itr-req">*</span></div><input class="itr-input" type="date" style="max-width:200px"/><div class="itr-hint">MM/DD/YYYY</div></div>
            </div>
            <div><div class="itr-label">Justification <span class="itr-req">*</span></div><textarea class="itr-input" placeholder="Describe why this request is needed…"></textarea></div>
          </div>
          <div class="itr-label" style="margin-bottom:10px">Supporting Document</div>
          <div class="itr-upload-zone">
            <div class="itr-notice warn"><i class="bi bi-exclamation-triangle-fill"></i><div><strong>(!) Bagi permohonan pertukaran laptop/desktop,</strong> sila sertakan <strong>Report Diagnosis dari Prodata</strong>. Permohonan akan ditolak sekiranya Report Diagnosis tidak disertakan.</div></div>
            <div class="itr-notice info"><i class="bi bi-info-circle-fill"></i><div>Sila pastikan nama lampiran tidak mengandungi simbol berikut: &amp; @ # $ % ^ * ( ) { } [ ] \ / : ' " dan saiz lampiran tidak melebihi <strong>2MB</strong></div></div>
            <div class="itr-upload-row"><div class="itr-filename" id="hw-fname">No file chosen</div><input type="file" id="hw-file" onchange="setFilename('hw-file','hw-fname')"/><button class="itr-browse-btn" onclick="document.getElementById('hw-file').click()"><i class="bi bi-paperclip"></i> Browse</button></div>
          </div>
        </div>
      </div>

      <div class="itr-section">
        <div class="itr-section-head"><div class="itr-section-num">3</div><div class="itr-section-title">User Details</div></div>
        <div class="itr-section-body">
          <div class="g2 fg"><div class="fg"><div class="itr-label">Name <span class="itr-req">*</span></div><input class="itr-input" type="text"/></div><div class="fg"><div class="itr-label">Email <span class="itr-req">*</span></div><input class="itr-input" type="email"/></div></div>
          <div class="fg"><div class="itr-label">Address <span class="itr-req">*</span></div><input class="itr-input" type="text"/></div>
          <div class="g4"><div class="fg"><div class="itr-label">Department <span class="itr-req">*</span></div><input class="itr-input" type="text"/></div><div class="fg"><div class="itr-label">Designation <span class="itr-req">*</span></div><input class="itr-input" type="text"/></div><div class="fg"><div class="itr-label">Staff ID <span class="itr-req">*</span></div><input class="itr-input" type="text" placeholder="12345678"/></div><div class="fg"><div class="itr-label">Contact No. <span class="itr-req">*</span></div><input class="itr-input" type="text"/></div></div>
        </div>
      </div>

      <div class="itr-section">
        <div class="itr-section-head"><div class="itr-section-num">4</div><div class="itr-section-title">Requester Details</div></div>
        <div class="itr-section-body">
          <div class="g3 fg"><div class="fg"><div class="itr-label">Name <span class="itr-req">*</span></div><input class="itr-input" type="text" placeholder="Enter a name or email address…"/></div><div class="fg"><div class="itr-label">Department <span class="itr-req">*</span></div><input class="itr-input" type="text"/></div><div class="fg"><div class="itr-label">Staff ID <span class="itr-req">*</span></div><input class="itr-input" type="text"/></div></div>
          <div class="g3"><div class="fg"><div class="itr-label">Designation <span class="itr-req">*</span></div><input class="itr-input" type="text"/></div><div class="fg"><div class="itr-label">Contact <span class="itr-req">*</span></div><input class="itr-input" type="text"/></div><div class="fg"><div class="itr-label">Company <span class="itr-req">*</span></div><select class="itr-input"><option value="">&lt; Select Company &gt;</option><option>FJB Johor Bulkers Sdn Bhd</option><option>FGV Holdings Berhad</option><option>FGV Plantation</option></select></div></div>
        </div>
      </div>

      <div class="itr-section">
        <div class="itr-section-head"><div class="itr-section-num">5</div><div class="itr-section-title">Approver Details</div></div>
        <div class="itr-section-body">
          <div class="fg"><div class="itr-label">Name <span class="itr-req">*</span></div><input class="itr-input" type="text" placeholder="Enter a name or email address…"/></div>
          <div class="g4"><div class="fg"><div class="itr-label">Department</div><input class="itr-input" type="text"/></div><div class="fg"><div class="itr-label">Designation</div><input class="itr-input" type="text"/></div><div class="fg"><div class="itr-label">Contact</div><input class="itr-input" type="text"/></div><div class="fg"><div class="itr-label">Company <span class="itr-req">*</span></div><select class="itr-input"><option value="">&lt; Select Company &gt;</option><option>FJB Johor Bulkers Sdn Bhd</option><option>FGV Holdings Berhad</option><option>FGV Plantation</option></select></div></div>
        </div>
      </div>
      <div class="itr-action-bar"><button class="itr-btn-submit"><i class="bi bi-send-fill"></i> Submit Request</button><button class="itr-btn-draft"><i class="bi bi-floppy"></i> Save as Draft</button></div>
    </div>

    <!-- ░ SOFTWARE ░ -->
    <div id="form-software" style="display:none">
      <div class="itr-section">
        <div class="itr-section-head"><div class="itr-section-num">1</div><div class="itr-section-title">Software Request Details</div></div>
        <div class="itr-section-body">
          <div class="g2 fg">
            <div>
              <div class="itr-label">Type of Request <span class="itr-req">*</span></div>
              <div class="itr-radio-group" id="sw-req-type">
                <div class="itr-radio-pill" data-group="sw-req-type" onclick="pillSelect(this)" data-name="swReq" data-value="new"> New</div>
                <div class="itr-radio-pill" data-group="sw-req-type" onclick="pillSelect(this)" data-name="swReq" data-value="amendment"> Amendment</div>
              </div>
              <div class="itr-hint" style="margin-top:8px">New &amp; Amend - (GIT)</div>
            </div>
            <div><div class="itr-label">Software Name or Suggestions</div><input class="itr-input" type="text"/><div class="itr-hint">Please provide or suggest software name, if any.</div></div>
          </div>
          <div class="g4">
            <div class="fg"><div class="itr-label">Budgeted? <span class="itr-req">*</span></div><div class="itr-radio-group" id="sw-budgeted" style="margin-top:4px"><div class="itr-radio-pill" data-group="sw-budgeted" onclick="pillSelect(this)" data-name="swBudget" data-value="yes"> Yes</div><div class="itr-radio-pill" data-group="sw-budgeted" onclick="pillSelect(this)" data-name="swBudget" data-value="no"> No</div></div></div>
            <div class="fg"><div class="itr-label">Opex / Capex <span class="itr-req">*</span></div><div class="itr-radio-group" id="sw-opex" style="margin-top:4px"><div class="itr-radio-pill" data-group="sw-opex" onclick="pillSelect(this)" data-name="swOpex" data-value="opex"> Opex</div><div class="itr-radio-pill" data-group="sw-opex" onclick="pillSelect(this)" data-name="swOpex" data-value="capex"> Capex</div></div></div>
            <div class="fg"><div class="itr-label">Cost Center <span class="itr-req">*</span></div><input class="itr-input" type="text" placeholder="CC-XXX"/><div class="itr-hint">Fill only if Budgeted is Yes</div></div>
            <div class="fg"><div class="itr-label">Expected Product Value <span class="itr-req">*</span></div><input class="itr-input" type="text" placeholder="RM"/><div class="itr-hint">Amount in RM</div></div>
          </div>
        </div>
      </div>
      <div class="itr-section">
        <div class="itr-section-head"><div class="itr-section-num">2</div><div class="itr-section-title">Type of User &amp; Justification</div></div>
        <div class="itr-section-body">
          <div class="g2 fg"><div><div class="fg"><div class="itr-label">Type of User <span class="itr-req">*</span></div><select class="itr-input"><option value="">-- Select --</option><option>New Hire</option><option>Intern</option><option>Resign</option><option>Existing</option><option>Vendor</option></select></div><div class="fg"><div class="itr-label">Join Date <span class="itr-req">*</span></div><input class="itr-input" type="date" style="max-width:200px"/><div class="itr-hint">MM/DD/YYYY</div></div></div><div><div class="itr-label">Justification <span class="itr-req">*</span></div><textarea class="itr-input" placeholder="Describe why this request is needed…"></textarea></div></div>
          <div class="itr-label" style="margin-bottom:10px">Supporting Document</div>
          <div class="itr-upload-zone"><div class="itr-notice warn"><i class="bi bi-exclamation-triangle-fill"></i><div><strong>(!) Bagi permohonan pertukaran laptop/desktop,</strong> sila sertakan <strong>Report Diagnosis dari Prodata</strong>. Permohonan akan ditolak sekiranya Report Diagnosis tidak disertakan.</div></div><div class="itr-notice info"><i class="bi bi-info-circle-fill"></i><div>Sila pastikan nama lampiran tidak mengandungi simbol berikut: &amp; @ # $ % ^ * ( ) { } [ ] \ / : ' " dan saiz lampiran tidak melebihi <strong>2MB</strong></div></div><div class="itr-upload-row"><div class="itr-filename" id="sw-fname">No file chosen</div><input type="file" id="sw-file" onchange="setFilename('sw-file','sw-fname')"/><button class="itr-browse-btn" onclick="document.getElementById('sw-file').click()"><i class="bi bi-paperclip"></i> Browse</button></div></div>
        </div>
      </div>
      <div class="itr-section"><div class="itr-section-head"><div class="itr-section-num">3</div><div class="itr-section-title">User Details</div></div><div class="itr-section-body"><div class="g2 fg"><div class="fg"><div class="itr-label">Name <span class="itr-req">*</span></div><input class="itr-input" type="text"/></div><div class="fg"><div class="itr-label">Email <span class="itr-req">*</span></div><input class="itr-input" type="email"/></div></div><div class="fg"><div class="itr-label">Address <span class="itr-req">*</span></div><input class="itr-input" type="text"/></div><div class="g4"><div class="fg"><div class="itr-label">Department <span class="itr-req">*</span></div><input class="itr-input" type="text"/></div><div class="fg"><div class="itr-label">Designation <span class="itr-req">*</span></div><input class="itr-input" type="text"/></div><div class="fg"><div class="itr-label">Staff ID <span class="itr-req">*</span></div><input class="itr-input" type="text" placeholder="12345678"/></div><div class="fg"><div class="itr-label">Contact No. <span class="itr-req">*</span></div><input class="itr-input" type="text"/></div></div></div></div>
      <div class="itr-section"><div class="itr-section-head"><div class="itr-section-num">4</div><div class="itr-section-title">Requester Details</div></div><div class="itr-section-body"><div class="g3 fg"><div class="fg"><div class="itr-label">Name <span class="itr-req">*</span></div><input class="itr-input" type="text" placeholder="Enter a name or email address…"/></div><div class="fg"><div class="itr-label">Department <span class="itr-req">*</span></div><input class="itr-input" type="text"/></div><div class="fg"><div class="itr-label">Staff ID <span class="itr-req">*</span></div><input class="itr-input" type="text"/></div></div><div class="g3"><div class="fg"><div class="itr-label">Designation <span class="itr-req">*</span></div><input class="itr-input" type="text"/></div><div class="fg"><div class="itr-label">Contact <span class="itr-req">*</span></div><input class="itr-input" type="text"/></div><div class="fg"><div class="itr-label">Company <span class="itr-req">*</span></div><select class="itr-input"><option value="">&lt; Select Company &gt;</option><option>FJB Johor Bulkers Sdn Bhd</option><option>FGV Holdings Berhad</option><option>FGV Plantation</option></select></div></div></div></div>
      <div class="itr-section"><div class="itr-section-head"><div class="itr-section-num">5</div><div class="itr-section-title">Approver Details</div></div><div class="itr-section-body"><div class="fg"><div class="itr-label">Name <span class="itr-req">*</span></div><input class="itr-input" type="text" placeholder="Enter a name or email address…"/></div><div class="g4"><div class="fg"><div class="itr-label">Department</div><input class="itr-input" type="text"/></div><div class="fg"><div class="itr-label">Designation</div><input class="itr-input" type="text"/></div><div class="fg"><div class="itr-label">Contact</div><input class="itr-input" type="text"/></div><div class="fg"><div class="itr-label">Company <span class="itr-req">*</span></div><select class="itr-input"><option value="">&lt; Select Company &gt;</option><option>FJB Johor Bulkers Sdn Bhd</option><option>FGV Holdings Berhad</option><option>FGV Plantation</option></select></div></div></div></div>
      <div class="itr-action-bar"><button class="itr-btn-submit"><i class="bi bi-send-fill"></i> Submit Request</button><button class="itr-btn-draft"><i class="bi bi-floppy"></i> Save as Draft</button></div>
    </div>

    <!-- ░ SYSTEM ░ -->
    <div id="form-system" style="display:none">
      <div class="itr-section">
        <div class="itr-section-head"><div class="itr-section-num">1</div><div class="itr-section-title">System Request Details</div></div>
        <div class="itr-section-body">
          <div class="g2 fg">
            <div><div class="itr-label">Type of Request <span class="itr-req">*</span></div><div class="itr-radio-group" id="sys-req-type"><div class="itr-radio-pill" data-group="sys-req-type" onclick="pillSelect(this)" data-name="sysReq" data-value="new"> New</div><div class="itr-radio-pill" data-group="sys-req-type" onclick="pillSelect(this)" data-name="sysReq" data-value="amendment"> Amendment</div></div></div>
            <div><div class="itr-label" style="margin-bottom:10px">Type of Item <span class="itr-req">*</span></div><div class="itr-check-grid"><div class="itr-check-chip" onclick="chipToggle(this)"><span class="chip-dot"></span>Email</div><div class="itr-check-chip" onclick="chipToggle(this)"><span class="chip-dot"></span>Shared Folder</div><div class="itr-check-chip" onclick="chipToggle(this)"><span class="chip-dot"></span>SAP</div><div class="itr-check-chip" onclick="chipToggle(this)"><span class="chip-dot"></span>FGVHub</div><div class="itr-check-chip" onclick="chipToggle(this)"><span class="chip-dot"></span>Procurehere</div><div class="itr-check-chip" onclick="chipToggle(this)"><span class="chip-dot"></span>e-Daftar</div><div class="itr-check-chip" onclick="chipToggle(this)"><span class="chip-dot"></span>e-CRM (SSC)</div><div class="itr-check-chip" onclick="chipToggle(this)"><span class="chip-dot"></span>Other</div></div></div>
          </div>
        </div>
      </div>
      <div class="itr-section"><div class="itr-section-head"><div class="itr-section-num">2</div><div class="itr-section-title">Type of User &amp; Justification</div></div><div class="itr-section-body"><div class="g2 fg"><div><div class="fg"><div class="itr-label">Type of User <span class="itr-req">*</span></div><select class="itr-input"><option value="">-- Select --</option><option>New Hire</option><option>Intern</option><option>Resign</option><option>Existing</option><option>Vendor</option></select></div><div class="fg"><div class="itr-label">Join Date <span class="itr-req">*</span></div><input class="itr-input" type="date" style="max-width:200px"/><div class="itr-hint">MM/DD/YYYY</div></div></div><div><div class="itr-label">Justification <span class="itr-req">*</span></div><textarea class="itr-input" placeholder="Describe why this request is needed…"></textarea></div></div><div class="itr-label" style="margin-bottom:10px">Supporting Document</div><div class="itr-upload-zone"><div class="itr-notice warn"><i class="bi bi-exclamation-triangle-fill"></i><div><strong>(!) Bagi permohonan pertukaran laptop/desktop,</strong> sila sertakan <strong>Report Diagnosis dari Prodata</strong>. Permohonan akan ditolak sekiranya Report Diagnosis tidak disertakan.</div></div><div class="itr-notice info"><i class="bi bi-info-circle-fill"></i><div>Sila pastikan nama lampiran tidak mengandungi simbol berikut: &amp; @ # $ % ^ * ( ) { } [ ] \ / : ' " dan saiz lampiran tidak melebihi <strong>2MB</strong></div></div><div class="itr-upload-row"><div class="itr-filename" id="sys-fname">No file chosen</div><input type="file" id="sys-file" onchange="setFilename('sys-file','sys-fname')"/><button class="itr-browse-btn" onclick="document.getElementById('sys-file').click()"><i class="bi bi-paperclip"></i> Browse</button></div></div></div></div>
      <div class="itr-section"><div class="itr-section-head"><div class="itr-section-num">3</div><div class="itr-section-title">User Details</div></div><div class="itr-section-body"><div class="g2 fg"><div class="fg"><div class="itr-label">Name <span class="itr-req">*</span></div><input class="itr-input" type="text"/></div><div class="fg"><div class="itr-label">Email <span class="itr-req">*</span></div><input class="itr-input" type="email"/></div></div><div class="fg"><div class="itr-label">Address <span class="itr-req">*</span></div><input class="itr-input" type="text"/></div><div class="g4"><div class="fg"><div class="itr-label">Department <span class="itr-req">*</span></div><input class="itr-input" type="text"/></div><div class="fg"><div class="itr-label">Designation <span class="itr-req">*</span></div><input class="itr-input" type="text"/></div><div class="fg"><div class="itr-label">Staff ID <span class="itr-req">*</span></div><input class="itr-input" type="text" placeholder="12345678"/></div><div class="fg"><div class="itr-label">Contact No. <span class="itr-req">*</span></div><input class="itr-input" type="text"/></div></div></div></div>
      <div class="itr-section"><div class="itr-section-head"><div class="itr-section-num">4</div><div class="itr-section-title">Requester Details</div></div><div class="itr-section-body"><div class="g3 fg"><div class="fg"><div class="itr-label">Name <span class="itr-req">*</span></div><input class="itr-input" type="text" placeholder="Enter a name or email address…"/></div><div class="fg"><div class="itr-label">Department <span class="itr-req">*</span></div><input class="itr-input" type="text"/></div><div class="fg"><div class="itr-label">Staff ID <span class="itr-req">*</span></div><input class="itr-input" type="text"/></div></div><div class="g3"><div class="fg"><div class="itr-label">Designation <span class="itr-req">*</span></div><input class="itr-input" type="text"/></div><div class="fg"><div class="itr-label">Contact <span class="itr-req">*</span></div><input class="itr-input" type="text"/></div><div class="fg"><div class="itr-label">Company <span class="itr-req">*</span></div><select class="itr-input"><option value="">&lt; Select Company &gt;</option><option>FJB Johor Bulkers Sdn Bhd</option><option>FGV Holdings Berhad</option><option>FGV Plantation</option></select></div></div></div></div>
      <div class="itr-section"><div class="itr-section-head"><div class="itr-section-num">5</div><div class="itr-section-title">Approver Details</div></div><div class="itr-section-body"><div class="fg"><div class="itr-label">Name <span class="itr-req">*</span></div><input class="itr-input" type="text" placeholder="Enter a name or email address…"/></div><div class="g4"><div class="fg"><div class="itr-label">Department</div><input class="itr-input" type="text"/></div><div class="fg"><div class="itr-label">Designation</div><input class="itr-input" type="text"/></div><div class="fg"><div class="itr-label">Contact</div><input class="itr-input" type="text"/></div><div class="fg"><div class="itr-label">Company <span class="itr-req">*</span></div><select class="itr-input"><option value="">&lt; Select Company &gt;</option><option>FJB Johor Bulkers Sdn Bhd</option><option>FGV Holdings Berhad</option><option>FGV Plantation</option></select></div></div></div></div>
      <div class="itr-action-bar"><button class="itr-btn-submit"><i class="bi bi-send-fill"></i> Submit Request</button><button class="itr-btn-draft"><i class="bi bi-floppy"></i> Save as Draft</button></div>
    </div>

    <!-- ░ SERVICE ░ -->
    <div id="form-service" style="display:none">
      <div class="itr-section">
        <div class="itr-section-head"><div class="itr-section-num">1</div><div class="itr-section-title">Service Request Type</div></div>
        <div class="itr-section-body">
          <div class="itr-label" style="margin-bottom:10px">Type of Request <span class="itr-req">*</span></div>
          <div class="itr-check-grid"><div class="itr-check-chip" onclick="chipToggle(this)"><span class="chip-dot"></span>Network (Reserve IP, Open Port, Internet Access)</div></div>
        </div>
      </div>
      <div class="itr-section"><div class="itr-section-head"><div class="itr-section-num">2</div><div class="itr-section-title">Type of User &amp; Justification</div></div><div class="itr-section-body"><div class="g2 fg"><div><div class="fg"><div class="itr-label">Type of User <span class="itr-req">*</span></div><select class="itr-input"><option value="">-- Select --</option><option>New Hire</option><option>Intern</option><option>Resign</option><option>Existing</option><option>Vendor</option></select></div><div class="fg"><div class="itr-label">Join Date <span class="itr-req">*</span></div><input class="itr-input" type="date" style="max-width:200px"/><div class="itr-hint">MM/DD/YYYY</div></div></div><div><div class="itr-label">Justification <span class="itr-req">*</span></div><textarea class="itr-input" placeholder="Describe why this request is needed…"></textarea></div></div><div class="itr-label" style="margin-bottom:10px">Supporting Document</div><div class="itr-upload-zone"><div class="itr-notice warn"><i class="bi bi-exclamation-triangle-fill"></i><div><strong>(!) Bagi permohonan pertukaran laptop/desktop,</strong> sila sertakan <strong>Report Diagnosis dari Prodata</strong>. Permohonan akan ditolak sekiranya Report Diagnosis tidak disertakan.</div></div><div class="itr-notice info"><i class="bi bi-info-circle-fill"></i><div>Sila pastikan nama lampiran tidak mengandungi simbol berikut: &amp; @ # $ % ^ * ( ) { } [ ] \ / : ' " dan saiz lampiran tidak melebihi <strong>2MB</strong></div></div><div class="itr-upload-row"><div class="itr-filename" id="svc-fname">No file chosen</div><input type="file" id="svc-file" onchange="setFilename('svc-file','svc-fname')"/><button class="itr-browse-btn" onclick="document.getElementById('svc-file').click()"><i class="bi bi-paperclip"></i> Browse</button></div></div></div></div>
      <div class="itr-section"><div class="itr-section-head"><div class="itr-section-num">3</div><div class="itr-section-title">User Details</div></div><div class="itr-section-body"><div class="g2 fg"><div class="fg"><div class="itr-label">Name <span class="itr-req">*</span></div><input class="itr-input" type="text"/></div><div class="fg"><div class="itr-label">Email <span class="itr-req">*</span></div><input class="itr-input" type="email"/></div></div><div class="fg"><div class="itr-label">Address <span class="itr-req">*</span></div><input class="itr-input" type="text"/></div><div class="g4"><div class="fg"><div class="itr-label">Department <span class="itr-req">*</span></div><input class="itr-input" type="text"/></div><div class="fg"><div class="itr-label">Designation <span class="itr-req">*</span></div><input class="itr-input" type="text"/></div><div class="fg"><div class="itr-label">Staff ID <span class="itr-req">*</span></div><input class="itr-input" type="text" placeholder="12345678"/></div><div class="fg"><div class="itr-label">Contact No. <span class="itr-req">*</span></div><input class="itr-input" type="text"/></div></div></div></div>
      <div class="itr-section"><div class="itr-section-head"><div class="itr-section-num">4</div><div class="itr-section-title">Requester Details</div></div><div class="itr-section-body"><div class="g3 fg"><div class="fg"><div class="itr-label">Name <span class="itr-req">*</span></div><input class="itr-input" type="text" placeholder="Enter a name or email address…"/></div><div class="fg"><div class="itr-label">Department <span class="itr-req">*</span></div><input class="itr-input" type="text"/></div><div class="fg"><div class="itr-label">Staff ID <span class="itr-req">*</span></div><input class="itr-input" type="text"/></div></div><div class="g3"><div class="fg"><div class="itr-label">Designation <span class="itr-req">*</span></div><input class="itr-input" type="text"/></div><div class="fg"><div class="itr-label">Contact <span class="itr-req">*</span></div><input class="itr-input" type="text"/></div><div class="fg"><div class="itr-label">Company <span class="itr-req">*</span></div><select class="itr-input"><option value="">&lt; Select Company &gt;</option><option>FJB Johor Bulkers Sdn Bhd</option><option>FGV Holdings Berhad</option><option>FGV Plantation</option></select></div></div></div></div>
      <div class="itr-section"><div class="itr-section-head"><div class="itr-section-num">5</div><div class="itr-section-title">Approver Details</div></div><div class="itr-section-body"><div class="fg"><div class="itr-label">Name <span class="itr-req">*</span></div><input class="itr-input" type="text" placeholder="Enter a name or email address…"/></div><div class="g4"><div class="fg"><div class="itr-label">Department</div><input class="itr-input" type="text"/></div><div class="fg"><div class="itr-label">Designation</div><input class="itr-input" type="text"/></div><div class="fg"><div class="itr-label">Contact</div><input class="itr-input" type="text"/></div><div class="fg"><div class="itr-label">Company <span class="itr-req">*</span></div><select class="itr-input"><option value="">&lt; Select Company &gt;</option><option>FJB Johor Bulkers Sdn Bhd</option><option>FGV Holdings Berhad</option><option>FGV Plantation</option></select></div></div></div></div>
      <div class="itr-action-bar"><button class="itr-btn-submit"><i class="bi bi-send-fill"></i> Submit Request</button><button class="itr-btn-draft"><i class="bi bi-floppy"></i> Save as Draft</button></div>
    </div>

  </div><!-- /step2 -->
</div><!-- /itr-wrap -->

<script>
const typeConfig = {
  hardware: { label: 'Hardware',  icon: 'bi-laptop',      form: 'form-hardware' },
  software: { label: 'Software',  icon: 'bi-code-slash',  form: 'form-software' },
  system:   { label: 'System',    icon: 'bi-hdd-network', form: 'form-system'   },
  service:  { label: 'Service',   icon: 'bi-wifi',        form: 'form-service'  }
};
let activeType = null;

function selectType(type) {
  activeType = type;
  Object.keys(typeConfig).forEach(t => {
    const card = document.getElementById('card-' + t);
    card.classList.toggle('selected', t === type);
    card.classList.toggle('locked', t !== type);
  });
  document.getElementById('locked-note').textContent = 'Other request types are locked. Click "Change Type" to start over.';

  const cfg = typeConfig[type];
  document.getElementById('banner-icon').className = 'bi ' + cfg.icon;
  document.getElementById('banner-label').textContent = cfg.label + ' Request';

  Object.values(typeConfig).forEach(c => { document.getElementById(c.form).style.display = 'none'; });
  document.getElementById(cfg.form).style.display = 'block';

  document.getElementById('prog-step1').className = 'itr-step done';
  document.getElementById('prog-step2').className = 'itr-step active';
  document.getElementById('prog-line').className = 'itr-step-line done';

  const s1 = document.getElementById('step1');
  s1.style.opacity = '0';
  setTimeout(() => {
    s1.style.display = 'none';
    const s2 = document.getElementById('step2');
    s2.style.display = 'block'; s2.style.opacity = '0';
    requestAnimationFrame(() => { s2.style.transition = 'opacity .25s'; s2.style.opacity = '1'; });
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }, 200);
}

function changeType() {
  activeType = null;
  Object.keys(typeConfig).forEach(t => { document.getElementById('card-' + t).classList.remove('selected','locked'); });
  document.getElementById('locked-note').textContent = '';
  document.getElementById('prog-step1').className = 'itr-step active';
  document.getElementById('prog-step2').className = 'itr-step';
  document.getElementById('prog-line').className = 'itr-step-line';
  const s2 = document.getElementById('step2');
  s2.style.opacity = '0';
  setTimeout(() => {
    s2.style.display = 'none';
    const s1 = document.getElementById('step1');
    s1.style.display = 'block'; s1.style.opacity = '0';
    requestAnimationFrame(() => { s1.style.transition = 'opacity .25s'; s1.style.opacity = '1'; });
  }, 200);
}

function pillSelect(el) {
  var groupId = el.getAttribute('data-group');
  document.getElementById(groupId).querySelectorAll('.itr-radio-pill').forEach(p => p.classList.remove('checked'));
  el.classList.add('checked');
}

function chipToggle(el) {
  el.classList.toggle('checked');
}

function setFilename(inputId, displayId) {
  const f = document.getElementById(inputId).files[0];
  document.getElementById(displayId).textContent = f ? f.name : 'No file chosen';
}
</script>

<?php require_once 'includes/layout_end.php'; ?>
