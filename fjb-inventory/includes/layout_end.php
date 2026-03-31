  </div><!-- /page-body -->
</div><!-- /main-content -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script>
// ── WRITE OFF DROPDOWN ──
function toggleWriteoff() {
  const menu = document.getElementById('writeoffMenu');
  const chev = document.getElementById('writeoffChevron');
  const open = menu.style.display === 'none' || menu.style.display === '';
  menu.style.display = open ? 'block' : 'none';
  chev.style.transform = open ? 'rotate(180deg)' : '';
}

// ── REPORTS DROPDOWN ──
function toggleReports() {
  const menu  = document.getElementById('reportsMenu');
  const chev  = document.getElementById('reportsChevron');
  const open  = menu.style.display === 'none' || menu.style.display === '';
  menu.style.display = open ? 'block' : 'none';
  chev.style.transform = open ? 'rotate(180deg)' : '';
}


const DARK_VARS = {
  '--sidebar-bg':    '#1a2235',
  '--sidebar-hover': '#243044',
  '--body-bg':       '#111827',
  '--surface':       '#1f2937',
  '--surface2':      '#263042',
  '--border':        '#374151',
  '--text':          '#d1d5db',
  '--muted':         '#6b7280',
  '--table-hover':   'rgba(255,255,255,.04)',
  '--form-input-bg': '#263042',
  '--form-input-border': '#374151',
  '--form-input-color': '#d1d5db',
  '--table-head-bg': '#1a2235',
  '--table-head-color': '#9ca3af',
};
const LIGHT_VARS = {
  '--sidebar-bg':    '#1a2332',
  '--sidebar-hover': '#243044',
  '--body-bg':       '#f1f5f9',
  '--surface':       '#ffffff',
  '--surface2':      '#f8fafc',
  '--border':        '#e2e8f0',
  '--text':          '#1e293b',
  '--muted':         '#64748b',
  '--table-hover':   '#f8fafc',
  '--form-input-bg': '#ffffff',
  '--form-input-border': '#e2e8f0',
  '--form-input-color': '#1e293b',
  '--table-head-bg': '#e2e8f0',
  '--table-head-color': '#475569',
};

function applyTheme(dark) {
  const vars = dark ? DARK_VARS : LIGHT_VARS;
  for (const [k,v] of Object.entries(vars))
    document.documentElement.style.setProperty(k, v);

  const icon = document.getElementById('themeIcon');
  if (icon) icon.className = dark ? 'bi bi-moon-fill' : 'bi bi-sun-fill';

  // Apply form input styles
  document.querySelectorAll('.form-control, .form-select').forEach(el => {
    el.style.background      = vars['--form-input-bg'];
    el.style.borderColor     = vars['--form-input-border'];
    el.style.color           = vars['--form-input-color'];
  });

  // Apply table header styles
  document.querySelectorAll('thead th').forEach(el => {
    el.style.backgroundColor = vars['--table-head-bg'];
    el.style.color           = vars['--table-head-color'];
  });

  // Apply table body
  document.querySelectorAll('table.dataTable tbody tr, tbody tr').forEach(el => {
    el.style.backgroundColor = vars['--surface'];
    el.style.color           = vars['--text'];
  });

  document.body.style.backgroundColor = vars['--body-bg'];
  document.body.style.color           = vars['--text'];
}
function toggleTheme() {
  const isDark = localStorage.getItem('fjb-theme') === 'dark';
  const next = isDark ? 'light' : 'dark';
  localStorage.setItem('fjb-theme', next);
  applyTheme(next === 'dark');
}
// Init on load
(function(){ applyTheme(localStorage.getItem('fjb-theme') === 'dark'); })();

// ── DATATABLES ──
$(document).ready(function () {
  if ($('.data-table').length) {
    const isInventory = window.location.pathname.includes('inventory');
    $('.data-table').DataTable({
      pageLength: 25,
      responsive: true,
      dom: isInventory
        ? '<"dt-table"t><"dt-footer d-flex align-items-center justify-content-between mt-3"<"dt-info"i><"dt-pages"p>>'
        : '<"d-flex align-items-center justify-content-between mb-2"<"dt-len"l><"dt-search"f>><"dt-table"t><"d-flex align-items-center justify-content-between mt-3"<"dt-info"i><"dt-pages"p>>',
      language: {
        search: '',
        searchPlaceholder: 'Search...',
        lengthMenu: 'Show _MENU_',
        info: 'Showing _START_–_END_ of _TOTAL_ items',
        paginate: { previous: '← Previous', next: 'Next →' }
      }
    });
  }
  setTimeout(() => $('.alert-success-custom, .alert-danger-custom').fadeOut(500), 4000);
});
</script>
</body>
</html>
