<!DOCTYPE html>
<html lang="{{ session('locale', 'en') }}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>{{ $title ?? 'Kanozay Foods ERP' }}</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
<style>
:root{--navy:#081d61;--blue:#0868ef;--soft:#eef6ff;--line:#e2eaf4;--text:#101f58;--muted:#5e73a5;--bg:#f4faff}
*{box-sizing:border-box}body{margin:0;font-family:Arial,'Segoe UI',sans-serif;font-size:12px;color:var(--text);background:linear-gradient(110deg,#f6fbff,#eef8ff)}a{text-decoration:none;color:inherit}button,input,select{font:inherit}.top{height:60px;position:fixed;top:0;left:0;right:0;display:flex;align-items:center;gap:18px;padding:0 18px;background:#fff;border-bottom:1px solid #e4eaf4;z-index:10}.brand{width:270px;flex-shrink:0;display:flex;align-items:center;gap:10px}.brand-icon{width:40px;height:40px;border-radius:50%;background:#0d7b43;color:#fff;display:grid;place-items:center;font-size:22px}.brand-name{color:#09663d;font-size:19px;line-height:17px;letter-spacing:1px;font-weight:900}.brand-name small{display:block;text-align:center;letter-spacing:2px;font-size:13px}.brand-divider{height:37px;width:1px;background:#dce6f1;margin:0 3px}.brand-sub{font-weight:800;font-size:14px;white-space:nowrap}.brand-sub small{display:block;font-size:9px;font-weight:400;color:var(--muted);margin-top:4px}.search{height:38px;border:1px solid #dce7f6;border-radius:5px;display:flex;align-items:center;gap:12px;padding:0 14px;color:var(--muted);flex:1;max-width:970px}.search input{border:0;outline:0;flex:1;min-width:0;color:var(--text)}.search kbd{background:#f1f5ff;border-radius:4px;padding:4px;color:var(--navy)}.tools{margin-left:auto;display:flex;align-items:center;gap:24px;font-size:18px}.tools a{position:relative}.tools .count{position:absolute;top:-8px;right:-8px;background:red;color:white;border-radius:20px;font-size:10px;padding:2px 5px}.profile{border-left:1px solid var(--line);padding-left:14px;display:flex;align-items:center;gap:9px;font-size:12px;white-space:nowrap}.avatar{width:35px;height:35px;border-radius:50%;display:grid;place-items:center;background:#e2edfa;color:var(--navy);font-weight:800}.profile small{display:block;color:var(--muted);margin-top:3px}.rail{position:fixed;top:60px;bottom:0;left:0;width:86px;background:#f8fcff;border-right:1px solid #edf1f8;display:flex;flex-direction:column;align-items:center;padding:14px 7px;gap:7px;overflow-y:auto;z-index:5}.rail a{width:70px;min-height:61px;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:8px;border-radius:6px;font-size:11px;color:#14275c;text-align:center}.rail a i{font-size:20px}.rail a.active,.rail a:hover{background:#eaf4ff;color:var(--navy);font-weight:700}.rail a.settings{margin-top:auto}.sidebar{position:fixed;left:86px;top:60px;bottom:0;width:218px;background:#fff;border-right:1px solid #e7eef6;padding:20px 11px;overflow-y:auto}.sidebar .module-title{height:40px;display:flex;align-items:center;gap:10px;background:#edf6ff;border-left:3px solid var(--blue);padding:0 13px;color:var(--navy);font-weight:700;font-size:13px}.sidebar a{display:flex;align-items:center;gap:11px;min-height:37px;padding:9px 12px;color:#203766;font-size:12px}.sidebar a i{width:15px;color:#44649d}.sidebar a.active{background:#edf7ff;color:#0067ed;font-weight:700}.sidebar a.active i{color:#0067ed}.sidebar hr{border:0;border-top:1px solid var(--line);margin:10px 0}.content{margin-left:304px;padding:20px 16px 24px;margin-top:60px;min-height:calc(100vh - 60px)}.heading{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:15px}.heading h1{font-size:24px;letter-spacing:-.5px;margin:0 0 7px}.heading p{font-size:15px;color:#5874aa;margin:0}.actions{display:flex;gap:12px;align-items:center}.control{background:white;border:1px solid #dce7f6;border-radius:5px;padding:11px 13px;min-width:155px;color:#12255f}.btn{border:0;border-radius:5px;background:#063b8b;color:#fff;padding:11px 17px;cursor:pointer}.card,.panel{background:#fff;border:1px solid #e9eff8;border-radius:6px;box-shadow:0 2px 10px #1e4e8307}.card{padding:14px}.panel{padding:14px}.panel h3{font-size:13px;margin:0 0 12px;border-bottom:1px solid var(--line);padding-bottom:10px}.muted{color:var(--muted)}.empty{color:#7185ac;text-align:center;padding:35px 10px}.cards{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:11px}.cards .card{min-height:134px}.metric{display:flex;gap:11px}.metric-icon{height:43px;width:43px;flex-shrink:0;display:grid;place-items:center;background:#e9f5ff;border-radius:8px;color:var(--blue);font-size:23px}.metric-label{font-size:11px;line-height:1.5}.metric-value{font-size:16px;font-weight:800;margin-top:5px;white-space:nowrap}.metric-foot{margin-top:22px;color:#8193b5;font-size:10px;text-align:right}.grid-main{display:grid;grid-template-columns:1.35fr 1.35fr .85fr;gap:11px;margin-top:12px}.grid-secondary{display:grid;grid-template-columns:1fr 1.12fr;gap:11px;margin-top:12px}.calendar{display:grid;grid-template-columns:repeat(7,1fr);gap:7px;text-align:center;color:#5872a4}.calendar b{color:#10265b}.calendar .today{background:#0a73ec;color:#fff;border-radius:50%;padding:8px}.table-wrap{overflow-x:auto}table{width:100%;border-collapse:collapse;font-size:11px}th{background:#f4f8fd;color:#284b85}th,td{text-align:left;padding:9px;border-bottom:1px solid #eaf0f7}input,select{border:1px solid #dce7f6;border-radius:5px;padding:9px}.status{border-radius:20px;padding:4px 8px;background:#e6f6f1;color:#07875d}.dashboard-table{margin-top:12px}.logout{border:0;background:transparent;color:var(--navy);cursor:pointer}@media(max-width:1450px){.cards{grid-template-columns:repeat(3,minmax(0,1fr))}.grid-main{grid-template-columns:1fr 1fr}.calendar-panel{grid-column:span 2}}@media(max-width:950px){.sidebar{display:none}.content{margin-left:86px}.brand{width:auto}.brand-sub,.brand-divider{display:none}.grid-main,.grid-secondary{grid-template-columns:1fr}.calendar-panel{grid-column:auto}}@media(max-width:650px){.top{gap:8px}.search,.tools>a{display:none}.cards{grid-template-columns:repeat(2,minmax(0,1fr))}.heading{display:block}.actions{margin-top:12px;flex-wrap:wrap}.content{padding:12px}}
 .top-dropdown{position:relative;font-size:18px}.top-dropdown summary{list-style:none;cursor:pointer;display:flex;align-items:center}.top-dropdown summary::-webkit-details-marker{display:none}.dropdown-panel{position:absolute;right:0;top:calc(100% + 15px);width:240px;padding:16px;background:white;border:1px solid var(--line);box-shadow:0 12px 35px #152e6530;border-radius:8px;z-index:30;font-size:12px}.dropdown-panel a{display:block;padding:10px;border-radius:5px}.dropdown-panel a:hover{background:#eef6ff}.dropdown-panel p{line-height:1.6}.dropdown-panel .logout{padding:10px;display:block;width:100%;text-align:left;border-top:1px solid var(--line);margin-top:8px}.profile-menu{font-size:12px}.profile-menu .profile{cursor:pointer}.rail a[aria-disabled=true]{opacity:.55;cursor:not-allowed}.panel{margin-bottom:16px}.panel h2{font-size:16px;margin:0 0 18px}.panel form:not(.inline){display:flex;gap:10px;flex-wrap:wrap;align-items:center}.panel form input,.panel form select{min-height:38px}.panel form button,.panel button{background:var(--blue);border:0;color:white;border-radius:5px;padding:10px 15px;cursor:pointer}.panel details{margin:7px 0}.panel details summary{color:var(--blue);cursor:pointer}.panel .permissions{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:10px;width:100%;padding:12px 0}.panel .permissions label{display:flex;align-items:center;gap:7px}.panel .permissions input{min-height:auto}.panel table{margin-top:12px}.error{color:#b42318}.panel .inline{display:inline}.panel .inline button{background:#b42318}.panel form .permissions+button{margin-top:12px} 
 .k-modal{border:0;border-radius:12px;padding:0;width:min(520px,calc(100vw - 30px));max-height:90vh;box-shadow:0 24px 90px #0b215550;color:var(--text)}.k-modal::backdrop{background:#06153b90}.modal-form{display:block!important}.modal-head,.modal-foot{padding:17px 22px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--line)}.modal-head h2{margin:0;font-size:18px}.modal-close{background:transparent!important;color:var(--text)!important;font-size:25px!important;padding:0 5px!important}.modal-body{padding:20px 22px;display:grid;gap:14px;max-height:65vh;overflow:auto}.modal-body label{font-weight:600;display:grid;gap:7px}.modal-body input,.modal-body select{width:100%;min-height:40px}.modal-foot{justify-content:flex-end;gap:10px;border-bottom:0;border-top:1px solid var(--line)}.btn-light{background:#edf3fb!important;color:var(--text)!important}.danger-btn{background:#fff1f1!important;color:#b42318!important;border:1px solid #ffcece!important}.lang-form{margin:0}.lang-form select{min-height:32px;padding:5px 8px;background:white;border:1px solid var(--line);color:var(--text);cursor:pointer}.top-dropdown[open]>.dropdown-panel{display:block} 
.language-trigger{display:flex;gap:8px;align-items:center;border:1px solid #e0e9f6;background:#f8fbff;border-radius:7px;padding:9px 11px;color:#143c7d;white-space:nowrap;font-size:12px;font-weight:700}.language-trigger:hover{background:#edf5ff}.language-trigger>.fa-globe{font-size:16px}.language-options{width:190px}.language-options form{margin-top:10px}.language-options button{display:flex;justify-content:space-between;align-items:center;gap:10px;width:100%;padding:11px 9px;background:transparent;border:0;border-radius:6px;text-align:left;cursor:pointer;color:#203766;font-size:13px}.language-options button:hover,.language-options button.selected{background:#edf5ff;color:#0868ef}.language-options button i{font-size:12px}

/* Distinct, restrained module colors: icons retain their color in active and hover states. */
.rail a{transition:background-color .18s,color .18s,transform .18s}.rail a:hover{transform:translateY(-1px)}
.rail a i{width:38px;height:34px;display:grid;place-items:center;border-radius:9px;transition:background-color .18s}
.rail a:nth-child(1){--module-color:#2169d5;--module-bg:#e8f1ff}
.rail a:nth-child(2){--module-color:#e18b16;--module-bg:#fff3dd}
.rail a:nth-child(3){--module-color:#139b78;--module-bg:#e0f8ef}
.rail a:nth-child(4){--module-color:#8855cf;--module-bg:#f1eafe}
.rail a:nth-child(5){--module-color:#0d9cb5;--module-bg:#e2f7fc}
.rail a:nth-child(6){--module-color:#e16a5b;--module-bg:#fff0ed}
.rail a:nth-child(7){--module-color:#21875e;--module-bg:#e4f7eb}
.rail a:nth-child(8){--module-color:#b65aaf;--module-bg:#faeaf8}
.rail a:nth-child(9){--module-color:#5476c9;--module-bg:#eaf0ff}
.rail a:nth-child(10){--module-color:#d17c2a;--module-bg:#fff0df}
.rail a:nth-child(11){--module-color:#52647e;--module-bg:#eaf0f6}
.rail a i{color:var(--module-color,#2169d5)!important;background:var(--module-bg,#e8f1ff)}
.rail a.active,.rail a:hover{background:var(--module-bg,#eaf4ff);color:var(--module-color,#0a4d9d)}
.sidebar a i{background:#edf3ff;color:#346bc1;width:27px;height:27px;display:grid;place-items:center;border-radius:7px;flex:0 0 27px}
.sidebar a:nth-of-type(4n + 2) i{background:#e6f7ef;color:#15946c}
.sidebar a:nth-of-type(4n + 3) i{background:#fff1e3;color:#cb7c20}
.sidebar a:nth-of-type(4n + 4) i{background:#f3eafd;color:#8957bc}
.sidebar a.active i{background:#dbeaff;color:#0868ef}
/* ERP-wide Select2 control: stable vertical alignment after initialization and selection. */
:root{--k-field-height:44px;--k-field-border:#d7e3f1;--k-field-radius:8px}
.select2-container{width:100%!important;max-width:100%;min-width:0}
.select2-container--default .select2-selection--single{position:relative!important;display:block!important;height:var(--k-field-height)!important;min-height:var(--k-field-height)!important;padding:0!important;border:1px solid var(--k-field-border)!important;border-radius:var(--k-field-radius)!important;background:#fff;box-sizing:border-box!important}
.select2-container--default .select2-selection--single .select2-selection__rendered{position:absolute!important;top:50%!important;left:0!important;right:28px!important;transform:translateY(-50%)!important;display:block!important;width:auto!important;height:auto!important;max-height:40px!important;margin:0!important;padding:0 0 0 12px!important;line-height:20px!important;font-size:12px!important;font-weight:400!important;color:#253e64!important;text-align:left;white-space:nowrap!important;overflow:hidden!important;text-overflow:ellipsis!important}
.select2-container--default .select2-selection--single .select2-selection__arrow{position:absolute!important;top:50%!important;right:7px!important;transform:translateY(-50%)!important;height:30px!important}
.select2-container--default .select2-selection--single .select2-selection__placeholder{color:#8191a8;font-weight:400}
.select2-container--default.select2-container--focus .select2-selection--single,.select2-container--default.select2-container--open .select2-selection--single{border-color:#4389d6!important;box-shadow:0 0 0 3px #2780e71a;outline:0}
.select2-container--default .select2-selection--multiple{min-height:var(--k-field-height);border:1px solid var(--k-field-border)!important;border-radius:var(--k-field-radius)!important;padding:4px 7px}
.select2-dropdown{border:1px solid var(--k-field-border);border-radius:8px;box-shadow:0 12px 25px #1c3e651c;overflow:hidden}
.select2-container--default .select2-results__option--highlighted.select2-results__option--selectable{background:#e8f2ff;color:#174d89}
.select2-container--default.select2-container--disabled .select2-selection--single{background:#f2f5fa;opacity:.7}
/* Finance workspace: consistent institutional styling without altering the top navbar or navigation rail. */
.finance-workspace{--fin-ink:#18375e;--fin-line:#dce6f2;--fin-muted:#637b9b;color:var(--fin-ink)}
.finance-workspace .heading{padding:6px 2px 16px;border-bottom:1px solid var(--fin-line);margin-bottom:20px}
.finance-workspace .heading h1{font-size:23px;letter-spacing:-.45px;font-weight:800;display:flex;align-items:center;gap:11px}
.finance-workspace .heading h1 i{width:42px;height:42px;display:grid;place-items:center;background:#eaf4f1;border-radius:11px;font-size:19px}
.finance-workspace .heading p{font-size:12px;line-height:1.7;color:var(--fin-muted);margin-left:53px}
.finance-workspace .panel{padding:22px;border-radius:13px;border:1px solid var(--fin-line);box-shadow:0 5px 22px #183e6709;margin-bottom:16px;min-width:0}
.finance-workspace .panel h2{font-size:16px;letter-spacing:-.2px;font-weight:800;color:var(--fin-ink);padding-bottom:14px;margin:0 0 16px;border-bottom:1px solid #e9eef5}
.finance-workspace .panel form.money-form,.finance-workspace .panel form.journal-form,.finance-workspace .panel form.fa-form,.finance-workspace .panel form.gl-filters{display:grid;align-items:stretch;gap:13px}
.finance-workspace .panel form label{font-size:12px;line-height:1.5;color:#304f76;font-weight:700}
.finance-workspace .panel form input:not([type=checkbox]),.finance-workspace .panel form select,.finance-workspace .panel form textarea{height:44px;min-height:44px;border:1px solid #d4e0ed;border-radius:9px;padding:10px 12px;background:#fff;color:#203b61;box-shadow:none;outline:none;transition:border-color .15s,box-shadow .15s}
.finance-workspace .panel form textarea{height:auto;min-height:85px;resize:vertical}
.finance-workspace .panel form input:focus,.finance-workspace .panel form select:focus,.finance-workspace .panel form textarea:focus{border-color:#397dd2;box-shadow:0 0 0 3px #397dd21c}
.finance-workspace .panel form input::placeholder,.finance-workspace .panel form textarea::placeholder{color:#98a8bb}
.finance-workspace .panel form .btn,.finance-workspace .panel form button[type=submit]{min-height:43px;border-radius:9px;background:#115aa8;color:white;font-weight:700;box-shadow:0 3px 8px #115aa81c}
.finance-workspace .panel form .btn:hover,.finance-workspace .panel form button[type=submit]:hover{background:#0b477f}
.finance-workspace .table-wrap{border:1px solid #e2eaf4;border-radius:10px;overflow:auto;background:white}
.finance-workspace .panel table{margin:0;border-collapse:separate;border-spacing:0;min-width:670px;font-size:12px}
.finance-workspace table th{position:relative;background:#f2f6fb;color:#365779;font-size:10px;font-weight:800;letter-spacing:.35px;text-transform:uppercase;padding:13px 12px;white-space:nowrap;border-bottom:1px solid #dfe9f3}
.finance-workspace table td{padding:13px 12px;color:#304b6e;border-bottom:1px solid #eaf0f6;vertical-align:middle;line-height:1.5}
.finance-workspace table tbody tr:hover{background:#f8fbff}
.finance-workspace table tbody tr:last-child td{border-bottom:0}
.finance-workspace table td strong{font-weight:800;color:#153d6a}
.finance-workspace .fa-filter a,.finance-workspace .money-tabs a{display:inline-flex;align-items:center;min-height:35px;border-radius:8px;font-weight:700;transition:background .15s}
.finance-workspace .fa-filter a.active,.finance-workspace .money-tabs a.active{background:#e5f1ff;color:#14579e;border-color:#b7d3f4}
.finance-workspace .journal-line{border:1px solid #e3ebf4;background:#f8fbfe;padding:13px;border-radius:10px}
.finance-workspace .journal-actions button,.finance-workspace .fa-edit{background:#eef5ff!important;color:#145a9b!important;border:1px solid #cfe0f4!important;border-radius:8px!important;font-weight:700}
.finance-workspace .gl-summary>div{background:#f7faff;border:1px solid #e1eaf4;border-radius:10px;padding:14px 16px}
.finance-workspace .gl-summary strong{font-variant-numeric:tabular-nums}
.finance-workspace .gl-num{font-variant-numeric:tabular-nums}
.finance-workspace .k-pagination{margin-top:16px}
@media(max-width:680px){.finance-workspace .panel{padding:14px}.finance-workspace .heading h1{font-size:19px}.finance-workspace .heading p{margin-left:0}.finance-workspace .gl-filters{grid-template-columns:1fr!important}}
/* Unified institutional pagination */
.k-pagination{display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap;padding:16px 4px 4px;margin-top:14px;border-top:1px solid #e6edf6;font-size:12px}.k-pagination-info{display:flex;align-items:center;gap:12px;flex-wrap:wrap;color:#647b9f}.k-pagination-total{font-weight:800;color:#1d3f72;background:#eff5ff;border:1px solid #e0eafd;padding:8px 11px;border-radius:8px}.k-pagination-range strong{color:#173d74}.k-pagination-controls,.k-page-numbers{display:flex;align-items:center;gap:5px}.k-page-btn{min-width:36px;height:36px;display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:0 11px;border:1px solid #dce6f4;border-radius:8px;background:#fff;color:#28517e;font-size:12px;font-weight:700;white-space:nowrap;text-decoration:none;transition:background .15s,border-color .15s,box-shadow .15s}.k-page-btn:hover:not(.k-page-disabled):not(.k-page-current){background:#edf5ff;border-color:#9fc2f4;color:#075bb7}.k-page-btn:focus-visible{outline:3px solid #8ebaff;outline-offset:2px}.k-page-current{background:#0a5dbb;border-color:#0a5dbb;color:#fff;box-shadow:0 2px 7px #0a5dbb22}.k-page-disabled{opacity:.43;cursor:not-allowed}.k-page-ellipsis{padding:0 5px;color:#8ca0bc}@media(max-width:700px){.k-pagination{justify-content:center;text-align:center}.k-pagination-info{justify-content:center;width:100%}.k-pagination-controls{justify-content:center;flex-wrap:wrap}.k-page-btn{min-width:34px;height:34px;padding:0 9px}}
/* Institutional form and dialog system shared by all ERP modules. */
:root{--k-form-border:#d5e1ee;--k-form-ink:#1d3658;--k-form-muted:#6c809a;--k-form-focus:#2877cb}
.content form:not(.inline):not(.search) label,.k-modal label,.k-create-dialog label{font-size:12px;font-weight:700;line-height:1.5;color:#344f71}
.content form:not(.inline) input:not([type=checkbox]):not([type=radio]):not([type=hidden]),.content form:not(.inline) textarea,.content form:not(.inline) select,.k-modal input:not([type=checkbox]):not([type=radio]),.k-modal textarea,.k-create-dialog input:not([type=checkbox]):not([type=radio]),.k-create-dialog textarea{box-sizing:border-box;max-width:100%;border:1px solid var(--k-form-border);border-radius:9px;color:var(--k-form-ink);background:#fff;transition:border-color .16s,box-shadow .16s}
.content form:not(.inline) input:focus-visible,.content form:not(.inline) textarea:focus-visible,.k-modal input:focus-visible,.k-modal textarea:focus-visible,.k-create-dialog input:focus-visible,.k-create-dialog textarea:focus-visible{outline:0;border-color:var(--k-form-focus);box-shadow:0 0 0 3px #2877cb1b}
.content form:not(.inline) input:disabled,.content form:not(.inline) textarea:disabled,.k-modal input:disabled,.k-create-dialog input:disabled{background:#f2f5f9;color:#8090a5;cursor:not-allowed}
.content form:not(.inline) input::placeholder,.k-modal input::placeholder,.k-create-dialog input::placeholder{color:#99a9bb}
.k-create-dialog,.k-modal{border:1px solid #d9e4f0!important;border-radius:16px!important;box-shadow:0 30px 90px #11274752!important;background:#fff;max-width:calc(100vw - 28px);max-height:min(92dvh,920px)}
.k-create-dialog::backdrop,.k-modal::backdrop{background:#102846a8;backdrop-filter:blur(3px)}
.k-create-dialog{width:min(720px,calc(100vw - 28px))!important;overflow:hidden}
.k-create-dialog .k-create-head{padding:18px 24px;background:linear-gradient(180deg,#fff,#f8fbff);border-bottom:1px solid #e4edf6}
.k-create-dialog .k-create-head h2{font-size:18px;line-height:1.4;color:#1b365b;letter-spacing:-.25px}
.k-create-dialog .k-create-head button{display:grid;place-items:center;width:36px;height:36px;transition:background .16s,color .16s}
.k-create-dialog .k-create-head button:hover{background:#dfeafb;color:#164f91}
.k-create-dialog .k-create-body{padding:22px 24px 24px;max-height:calc(92dvh - 76px);overscroll-behavior:contain}
.k-create-dialog .k-create-body>p{font-size:12px;line-height:1.7;color:var(--k-form-muted);margin:0 0 16px}
.k-create-dialog form{gap:16px!important}
.k-create-dialog form>label,.k-create-dialog form>div>label{display:grid;gap:7px;min-width:0}
.k-create-dialog form input:not([type=checkbox]):not([type=radio]),.k-create-dialog form textarea{min-height:44px;padding:10px 13px;font-size:13px}
.k-create-dialog form textarea{min-height:90px;resize:vertical}
.k-create-dialog form button[type=submit]{width:100%;padding:11px 16px;min-height:46px;margin-top:6px;box-shadow:0 4px 12px #125ca520;transition:background .16s,transform .16s}
.k-create-dialog form button[type=submit]:hover{background:#0c4c90;transform:translateY(-1px)}
.k-create-dialog .select2-container--default .select2-selection--single{height:44px!important;min-height:44px!important}
.k-create-dialog .select2-dropdown,.k-modal .select2-dropdown{border-color:#b9d1e9;box-shadow:0 10px 25px #17375a20;border-radius:8px;overflow:hidden}
.k-create-dialog .select2-search__field,.k-modal .select2-search__field{border-radius:7px!important;padding:8px!important;min-height:36px!important}
.k-modal .modal-body{max-height:calc(92dvh - 160px);overflow-y:auto;overscroll-behavior:contain}
.k-modal .modal-form{max-width:100%}
.k-modal .modal-body label{min-width:0}
.k-modal .modal-body input:not([type=checkbox]),.k-modal .modal-body textarea{width:100%;min-height:44px}
@media(max-width:650px){.k-create-dialog .k-create-head{padding:14px 16px}.k-create-dialog .k-create-body{padding:16px}.k-create-dialog{width:calc(100vw - 18px)!important}.k-modal{max-width:calc(100vw - 18px)!important}}
/* Two-column data entry for long institutional forms; single-column on mobile. */
@media(min-width:680px){
 .k-create-dialog:has(form.fx-create-form){width:min(860px,calc(100vw - 36px))!important}
 .k-create-dialog form.fx-create-form{grid-template-columns:repeat(2,minmax(0,1fr))!important;column-gap:18px!important;row-gap:15px!important}
 .k-create-dialog form.fx-create-form>label,.k-create-dialog form.fx-create-form>#fx-external-fields{min-width:0}
 .k-create-dialog form.fx-create-form>#fx-external-fields{grid-column:1/-1;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px}
 .k-create-dialog form.fx-create-form>label:has(textarea),.k-create-dialog form.fx-create-form>.fx-filter-note,.k-create-dialog form.fx-create-form>button[type=submit]{grid-column:1/-1}
 .k-create-dialog form.fx-create-form>button[type=submit]{width:auto;justify-self:end;min-width:215px}
}
@media(max-width:679px){.k-create-dialog form.fx-create-form{grid-template-columns:minmax(0,1fr)!important}.k-create-dialog form.fx-create-form>#fx-external-fields{grid-template-columns:minmax(0,1fr)}}
/* Responsive paired fields across long create forms and existing edit dialogs. */
@media(min-width:680px){
 .k-create-dialog:has(form[data-new-entry-form]){width:min(820px,calc(100vw - 36px))!important}
 .k-create-dialog form[data-new-entry-form]:not(.journal-form){grid-template-columns:repeat(2,minmax(0,1fr))!important;column-gap:18px!important;row-gap:15px!important}
 .k-create-dialog form[data-new-entry-form]:not(.journal-form)>label{min-width:0}
 .k-create-dialog form[data-new-entry-form]:not(.journal-form)>label:has(textarea),
 .k-create-dialog form[data-new-entry-form]:not(.journal-form)>p,
 .k-create-dialog form[data-new-entry-form]:not(.journal-form)>button[type=submit],
 .k-create-dialog form[data-new-entry-form]:not(.journal-form)>[class*=wide]{grid-column:1/-1}
 .k-create-dialog form[data-new-entry-form]:not(.journal-form)>button[type=submit]{width:auto;justify-self:end;min-width:200px}
 .k-modal:not(.permission-dialog){width:min(780px,calc(100vw - 36px))!important}
 .k-modal:not(.permission-dialog) .modal-body:has(>label){display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:15px 18px}
 .k-modal:not(.permission-dialog) .modal-body>label:has(textarea),.k-modal:not(.permission-dialog) .modal-body>[class*=wide]{grid-column:1/-1}
}
@media(max-width:679px){.k-create-dialog form[data-new-entry-form]:not(.journal-form){grid-template-columns:minmax(0,1fr)!important}.k-modal .modal-body{grid-template-columns:minmax(0,1fr)!important}}
/* Consistent accessible creation dialogs across ERP pages. */
.k-create-dialog{border:1px solid #dbe6f2;border-radius:14px;padding:0;width:min(650px,calc(100vw - 28px));max-height:92vh;box-shadow:0 24px 80px #102b5044;color:#203b61}
.k-create-dialog::backdrop{background:#0d224d9c}
.k-create-dialog .k-create-head{position:sticky;top:0;background:white;z-index:2;display:flex;align-items:center;justify-content:space-between;gap:14px;padding:18px 23px;border-bottom:1px solid #e5edf6}
.k-create-dialog .k-create-head h2{margin:0;font-size:18px}
.k-create-dialog .k-create-head button{border:0;background:#edf4fd;border-radius:8px;width:33px;height:33px;cursor:pointer;color:#30567e;font-size:20px}
.k-create-dialog .k-create-body{padding:22px 23px;max-height:calc(92vh - 74px);overflow:auto}
.k-create-dialog form{display:grid!important;gap:13px!important;align-items:stretch!important;width:100%}
.k-create-dialog form label{display:grid;gap:6px;font-weight:700;color:#315174;font-size:12px}
.k-create-dialog form input:not([type=checkbox]),.k-create-dialog form select,.k-create-dialog form textarea{width:100%;min-height:44px;border:1px solid #d7e3f1;border-radius:8px;padding:10px 12px;background:white;color:#253e64}
.k-create-dialog form button[type=submit]{min-height:43px;border:0;background:#115aa8;color:white;border-radius:8px;font-weight:700;cursor:pointer}
.k-create-trigger{display:inline-flex;align-items:center;gap:9px;justify-content:center;min-height:39px;padding:9px 15px;border:0;border-radius:9px;background:#125eac;color:white;font-size:12px;font-weight:800;cursor:pointer}
.k-create-trigger:hover{background:#0b487f}
/* Creation forms live in dialogs; registers use the full available card width. */
.coa-grid,.fx-grid,.rate-grid,.fa-layout,.money-layout{display:block!important;width:100%!important;min-width:0!important}
.coa-grid>section.panel,.fx-grid>section.panel,.rate-grid>section.panel,.fa-layout>section.panel,.money-layout>section.panel{width:100%!important;max-width:none!important;min-width:0!important}
.k-create-source{display:none!important}
/* Never hide a creation form when its modal trigger has not been mounted. */
body:not(.k-create-ready) .k-create-source{display:block!important}
.k-register-header{display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap;margin-bottom:16px;min-height:42px}
.k-register-header h2{margin:0!important;padding:0!important;border:0!important}
.k-register-header .k-create-trigger{margin-left:auto;white-space:nowrap}
.k-register-panel>.table-wrap{width:100%;max-width:100%;overflow-x:auto}
.k-register-panel table{width:100%}
/* Full-width register workspace: remove the former create-form column completely. */
.content:has(.k-register-panel){width:auto;max-width:none;min-width:0;display:flex;flex-direction:column}
.content:has(.k-register-panel)>div.coa-grid,.content:has(.k-register-panel)>div.fx-grid,.content:has(.k-register-panel)>div.rate-grid,.content:has(.k-register-panel)>div.fa-layout,.content:has(.k-register-panel)>div.money-layout{flex:1 1 auto;width:100%!important;max-width:none!important;min-width:0;display:block!important}
.coa-grid:has(.k-register-panel),.fx-grid:has(.k-register-panel),.rate-grid:has(.k-register-panel),.fa-layout:has(.k-register-panel),.money-layout:has(.k-register-panel){display:block!important;grid-template-columns:none!important;width:100%!important;max-width:none!important}
.k-register-panel{display:flex;flex-direction:column;width:100%!important;max-width:none!important;min-width:0;min-height:0!important;height:auto!important;margin:0!important}
.k-register-panel>.table-wrap{flex:0 1 auto;width:100%!important;max-width:100%!important;min-width:0;overflow-x:auto}
.k-register-panel table{width:100%!important;min-width:100%;table-layout:auto}
.k-register-panel>.k-pagination{margin-top:15px;padding-top:0}
@media(max-width:950px){.k-register-panel{min-height:0!important}}
/* All registers scroll with the page vertically; only wide tables scroll sideways. */
.content .table-wrap,.finance-workspace .table-wrap,.k-register-panel>.table-wrap,
.content:has(.k-register-panel) .k-register-panel>.table-wrap{
 height:auto!important;max-height:none!important;min-height:0!important;
 overflow-x:auto!important;overflow-y:clip!important;
 scrollbar-width:thin;scrollbar-color:#bfd1e6 transparent;
}
.content .k-register-panel{overflow:visible!important;height:auto!important;max-height:none!important}
.content .k-register-panel table{width:100%!important}
/* Pagination remains outside the scroll container, never clipped at its bottom. */
.content .k-register-panel .k-pagination{position:relative;clear:both;width:100%;margin-top:15px}
/* Accessible, consistent empty table states with natural card height. */
.k-empty-state{width:100%;min-height:205px;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;gap:9px;padding:28px 18px;color:#637b9b}
.k-empty-state .k-empty-icon{display:grid;place-items:center;width:58px;height:58px;border:1px solid #d9e8f5;border-radius:17px;background:linear-gradient(145deg,#f0f8ff,#e8f3ff);color:#3278b9;font-size:23px;margin-bottom:3px}
.k-empty-state strong{font-size:15px;letter-spacing:-.2px;color:#213f65;font-weight:800}
.k-empty-state p{font-size:12px;line-height:1.7;max-width:390px;margin:0;color:#70839c;font-weight:400}
.table-wrap td:has(>.k-empty-state){padding:0!important;text-align:center!important;border-bottom:0!important}
@media(max-width:650px){.k-empty-state{min-height:165px;padding:22px 12px}.k-empty-state .k-empty-icon{width:48px;height:48px;font-size:19px}}

/* Receivables / payables: title and create action share one compact card header. */
.content .k-register-panel>.k-register-header{display:flex!important;align-items:center!important;justify-content:space-between!important;flex-wrap:wrap!important;gap:12px!important;min-height:46px!important;margin:0 0 14px!important;padding:0 0 13px!important;border-bottom:1px solid #e7eef6!important}
.content .k-register-panel>.k-register-header h2{margin:0!important;padding:0!important;line-height:1.4!important}
.content .k-register-panel>.k-register-header .k-create-trigger{margin:0 0 0 auto!important;max-width:100%;white-space:normal;text-align:center;flex-shrink:0}
@media(max-width:600px){.content .k-register-panel>.k-register-header{align-items:flex-start!important}.content .k-register-panel>.k-register-header .k-create-trigger{margin-left:0!important}}
/* Unified full-area table workspace, including Journal Entries. */
.content:has(.k-register-panel){display:flex!important;flex-direction:column!important;align-items:stretch!important;min-width:0}
/* Pre-render layout: hide modal-only source cards before JS executes.
   This prevents a two-column form flash on navigation and pagination. */
.content section.panel:has(> form[data-new-entry-form]),
.content section.panel:has(> p > form[data-new-entry-form]){display:none!important}
.content .coa-grid:has(form[data-new-entry-form]),
.content .fx-grid:has(form[data-new-entry-form]),
.content .rate-grid:has(form[data-new-entry-form]),
.content .fa-layout:has(form[data-new-entry-form]),
.content .money-layout:has(form[data-new-entry-form]),
.content .journal-layout:has(form[data-new-entry-form]),
.content .sequence-layout:has(form[data-new-entry-form]){display:block!important;grid-template-columns:none!important;width:100%!important;max-width:none!important;min-width:0!important}
.content .coa-grid:has(form[data-new-entry-form])>section.panel:not(:has(form[data-new-entry-form])),
.content .fx-grid:has(form[data-new-entry-form])>section.panel:not(:has(form[data-new-entry-form])),
.content .rate-grid:has(form[data-new-entry-form])>section.panel:not(:has(form[data-new-entry-form])),
.content .fa-layout:has(form[data-new-entry-form])>section.panel:not(:has(form[data-new-entry-form])),
.content .money-layout:has(form[data-new-entry-form])>section.panel:not(:has(form[data-new-entry-form])),
.content .journal-layout:has(form[data-new-entry-form])>section.panel:not(:has(form[data-new-entry-form])),
.content .sequence-layout:has(form[data-new-entry-form])>section.panel:not(:has(form[data-new-entry-form])){width:100%!important;max-width:none!important;min-width:0!important}
/* Register cards grow only with content, not viewport height. */
.content:has(.k-register-panel) .k-register-panel{height:auto!important;min-height:0!important;flex:0 0 auto!important}
.content:has(.k-register-panel) .k-register-panel>.table-wrap{height:auto!important;min-height:0!important;flex:0 0 auto!important}
.content:has(.k-register-panel)>div.sequence-layout{display:block!important;width:100%!important;grid-template-columns:none!important;max-width:none!important}
.sequence-layout:has(.k-register-panel){display:block!important;width:100%!important;grid-template-columns:none!important}
.content:has(.k-register-panel)>div{min-width:0}

.content:has(.k-register-panel)>div.journal-layout{flex:1 1 auto;display:block!important;grid-template-columns:none!important;width:100%!important;max-width:none!important;min-width:0!important}
.journal-layout:has(.k-register-panel){display:block!important;grid-template-columns:none!important;width:100%!important;max-width:none!important;min-width:0!important}
.content:has(.k-register-panel) .k-register-panel{box-sizing:border-box;display:flex!important;flex-direction:column!important;width:100%!important;max-width:none!important;min-width:0!important;min-height:0!important}
.content:has(.k-register-panel) .k-register-panel>.table-wrap{width:100%!important;flex:0 1 auto!important;min-width:0!important;overflow-x:auto!important}
.content:has(.k-register-panel) .k-register-panel>.table-wrap>table{width:100%!important;min-width:100%!important}
.content:has(.k-register-panel) .k-register-panel>.table-wrap>table tbody tr:only-child td.empty{text-align:center;padding:42px 16px}
@media(max-width:650px){.content:has(.k-register-panel) .k-register-panel{min-height:0!important}}



.k-create-dialog .select2-container{width:100%!important}

/* Unified rounded institutional controls — content workspace only. */
:root{--k-pill-border:#d6d9df;--k-pill-radius:22px;--k-pill-focus:#3a82d5}
.content input:not([type=checkbox]):not([type=radio]):not([type=hidden]):not([type=color]):not([type=file]),
.content select,.content textarea,.k-create-dialog input:not([type=checkbox]):not([type=radio]):not([type=hidden]):not([type=file]),.k-create-dialog select,.k-create-dialog textarea,
.k-modal input:not([type=checkbox]):not([type=radio]):not([type=hidden]):not([type=file]),.k-modal select,.k-modal textarea{border:1px solid var(--k-pill-border)!important;border-radius:var(--k-pill-radius)!important;background-color:#fff;min-height:40px;transition:border-color .16s,box-shadow .16s}
.content input:not([type=checkbox]):not([type=radio]):focus,.content select:focus,.content textarea:focus,
.k-create-dialog input:focus,.k-create-dialog select:focus,.k-create-dialog textarea:focus,.k-modal input:focus,.k-modal select:focus,.k-modal textarea:focus{border-color:var(--k-pill-focus)!important;outline:0;box-shadow:0 0 0 3px #3a82d51a!important}
.content .btn,.content button[type=submit]:not(.direction-choice),.content button[type=button]:not(.direction-choice):not(.party-remove-opening):not(.modal-close),
.k-create-dialog button[type=submit],.k-modal button[type=submit]{border-radius:22px!important;min-height:36px;transition:background-color .16s,border-color .16s,box-shadow .16s}
.content .btn:focus-visible,.content button:focus-visible,.k-create-dialog button:focus-visible,.k-modal button:focus-visible{outline:2px solid #70a6ed;outline-offset:2px}
.content .control{border:1px solid var(--k-pill-border);border-radius:22px}
.content .select2-container--default .select2-selection--single,.content .select2-container--default .select2-selection--multiple,
.k-create-dialog .select2-container--default .select2-selection--single,.k-create-dialog .select2-container--default .select2-selection--multiple,
.k-modal .select2-container--default .select2-selection--single,.k-modal .select2-container--default .select2-selection--multiple{border-radius:22px!important;border-color:var(--k-pill-border)!important}
.content .select2-container--default.select2-container--focus .select2-selection,.k-create-dialog .select2-container--default.select2-container--focus .select2-selection{border-color:var(--k-pill-focus)!important;box-shadow:0 0 0 3px #3a82d51a}
.content .select2-container--default .select2-selection--multiple .select2-selection__choice{border-radius:14px}
.content input:disabled,.content select:disabled,.content textarea:disabled{background:#f3f5f8!important}
@media(max-width:650px){.content .btn,.content button[type=submit]{min-height:40px}}

/* Unified semantic finance amount display */
.k-finance-amount{display:inline-flex;align-items:center;gap:5px;white-space:nowrap;font-weight:750;font-variant-numeric:tabular-nums;color:#24456f}
.k-finance-amount--in{color:#15803d}.k-finance-amount--out{color:#dc2626}.k-finance-amount i{font-size:11px}
.content .panel .k-filter-actions{grid-column:1/-1!important;display:flex!important;justify-content:flex-end!important;gap:10px!important;padding-top:14px!important;border-top:1px solid #e6edf5}
.content .panel .k-filter-actions>.btn{display:inline-flex!important;align-items:center!important;gap:8px!important;min-height:42px!important;padding:0 18px!important}
@media(max-width:650px){.content .panel .k-filter-actions{flex-direction:column-reverse!important}.content .panel .k-filter-actions>.btn{width:100%!important;justify-content:center!important}}

/* Filter overflow containment: the action row stays inside the card at every viewport. */
.finance-workspace .k-collapsible-filters,.finance-workspace .k-filter-toggle{min-width:0;max-width:100%;box-sizing:border-box}
.finance-workspace .k-filter-toggle>form.gl-filters,.finance-workspace .k-filter-toggle>form.report-filter-form{width:auto!important;max-width:calc(100% - 36px)!important;box-sizing:border-box!important;margin:14px 18px!important;min-width:0!important}
.finance-workspace .k-filter-toggle .k-filter-actions{width:100%!important;min-width:0!important;max-width:100%!important;box-sizing:border-box!important;flex-wrap:wrap!important}
.finance-workspace .k-filter-toggle .k-filter-actions>.btn{width:auto!important;max-width:100%!important;box-sizing:border-box!important;white-space:normal!important;text-align:center!important;flex:0 1 auto!important}
.finance-workspace .k-filter-toggle .k-filter-actions>button[type=submit]{grid-column:auto!important;justify-self:auto!important;min-width:0!important;margin:0!important}
.finance-workspace .k-filter-toggle .k-filter-actions>a{min-width:0!important}
.finance-workspace .k-filter-toggle .k-filter-actions .btn i{flex-shrink:0}
@media(max-width:650px){.finance-workspace .k-filter-toggle>form.gl-filters,.finance-workspace .k-filter-toggle>form.report-filter-form{max-width:calc(100% - 28px)!important;margin:12px 14px!important}.finance-workspace .k-filter-toggle .k-filter-actions{flex-direction:column-reverse!important}.finance-workspace .k-filter-toggle .k-filter-actions>.btn{width:100%!important;flex:0 0 auto!important}}
/* Compact expandable filter toolbar */
.finance-workspace .k-collapsible-filters{padding:0!important;overflow:visible;margin-bottom:14px}
.finance-workspace .k-filter-toggle>summary{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:13px 18px;cursor:pointer;list-style:none;font-weight:750;color:#24466d;font-size:13px}
.finance-workspace .k-filter-toggle>summary::-webkit-details-marker{display:none}
.finance-workspace .k-filter-toggle>summary>span{display:inline-flex;align-items:center;gap:9px}
.finance-workspace .k-filter-toggle>summary i.fa-sliders{color:#2876bd}
.finance-workspace .k-filter-toggle-hint{color:#778da5;font-size:11px;font-weight:600}
.finance-workspace .k-filter-toggle[open]>summary{border-bottom:1px solid #e6edf5;background:#f7faff;border-radius:12px 12px 0 0}
.finance-workspace .k-filter-toggle[open] .k-filter-toggle-hint i{transform:rotate(180deg)}
.finance-workspace .k-filter-toggle>form,.finance-workspace .k-filter-toggle>.report-section-head{margin:14px 18px}
.finance-workspace .k-filter-toggle>.report-section-head{margin-bottom:10px}
.finance-workspace .k-filter-toggle>.report-section-head h2{font-size:13px}
.finance-workspace .k-filter-toggle>.report-filter-form{padding-bottom:16px}
.finance-workspace .k-filter-toggle .k-filter-actions{padding-top:10px!important}
@media(max-width:650px){.finance-workspace .k-filter-toggle>summary{padding:12px 14px}.finance-workspace .k-filter-toggle>form,.finance-workspace .k-filter-toggle>.report-section-head{margin:12px 14px}.finance-workspace .k-filter-toggle-hint{font-size:10px}}
/* Consistent filter toolbar: inputs occupy a clean grid; actions live in a separate right-aligned footer. */
.content .panel form.gl-filters,.content .panel form.report-filter-form{
 display:grid!important;grid-template-columns:repeat(4,minmax(0,1fr))!important;
 align-items:end!important;column-gap:16px!important;row-gap:16px!important;width:100%;min-width:0
}
.content .panel form.gl-filters>label,.content .panel form.report-filter-form>label{
 display:flex!important;flex-direction:column!important;gap:8px!important;min-width:0!important;
 font-size:12px!important;font-weight:700!important;color:#3d5878!important;letter-spacing:.1px
}
.content .panel form.gl-filters>label select,.content .panel form.gl-filters>label input,
.content .panel form.report-filter-form>label select,.content .panel form.report-filter-form>label input{
 width:100%!important;min-width:0!important;min-height:44px!important;box-sizing:border-box!important
}
.content .panel form.gl-filters>button[type=submit],.content .panel form.report-filter-form>button[type=submit]{
 grid-column:1/-1!important;justify-self:end!important;align-self:center!important;
 display:inline-flex!important;align-items:center!important;justify-content:center!important;gap:8px!important;
 min-width:152px!important;min-height:42px!important;margin:0!important;padding:0 22px!important
}
.content .panel form.gl-filters>button[type=submit]::before{content:"";display:none}
.content .panel form.gl-filters>button[type=submit],.content .panel form.report-filter-form>button[type=submit]{box-shadow:0 5px 14px #1769b21a}
.content .panel form.gl-filters>button[type=submit]:hover,.content .panel form.report-filter-form>button[type=submit]:hover{transform:translateY(-1px)}
@media(max-width:1100px){.content .panel form.gl-filters,.content .panel form.report-filter-form{grid-template-columns:repeat(2,minmax(0,1fr))!important}}
@media(max-width:650px){.content .panel form.gl-filters,.content .panel form.report-filter-form{grid-template-columns:1fr!important;gap:13px!important}.content .panel form.gl-filters>button[type=submit],.content .panel form.report-filter-form>button[type=submit]{width:100%!important;justify-self:stretch!important}}
/* Report workspaces: polished, responsive, scoped to report pages. */
.finance-workspace .report-tabs{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 17px}
.finance-workspace .report-tabs a{display:inline-flex;align-items:center;gap:9px;padding:11px 17px;border-radius:11px;background:#fff;border:1px solid #dbe6f1;color:#46617f;font-weight:750;font-size:12px}
.finance-workspace .report-tabs a.active{background:#e7f2ff;border-color:#a9cbed;color:#1159a3}
.finance-workspace .report-filter{padding:20px 22px}
.finance-workspace .report-filter-form{display:flex;gap:12px;align-items:end;flex-wrap:wrap}
.finance-workspace .report-filter-form label{display:grid;gap:7px;min-width:190px}
.finance-workspace .report-filter-form button{height:43px;padding:0 19px}
.finance-workspace .report-section-head{display:flex;align-items:center;justify-content:space-between;gap:15px;margin-bottom:18px}
.finance-workspace .report-section-head h2{padding:0;margin:0 0 5px;border:0}
.finance-workspace .report-section-head p{margin:0;color:#7488a2;font-size:12px;line-height:1.6}
.finance-workspace .report-metrics{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;margin-bottom:17px}
.finance-workspace .report-metric{display:flex;flex-direction:column;gap:9px;background:#fff;border:1px solid #dce6f1;border-radius:14px;padding:20px 22px;box-shadow:0 5px 19px #143d6508}
.finance-workspace .report-metric span{color:#607792;font-size:12px;font-weight:700}
.finance-workspace .report-metric span i{color:#2372bb;margin-right:6px}
.finance-workspace .report-metric strong{font-size:25px;color:#173c66;letter-spacing:-.6px}
.finance-workspace .report-metric small{font-size:11px;color:#8494aa}
.finance-workspace .report-count,.finance-workspace .report-currency{background:#edf4fb;border:1px solid #dce8f4;border-radius:7px;padding:5px 9px;font-size:11px;font-weight:800;color:#285682;white-space:nowrap}
.finance-workspace .report-panel{padding:22px}
.finance-workspace .report-panel table td:nth-child(n+3){font-variant-numeric:tabular-nums}
.finance-workspace .report-status{display:inline-flex;align-items:center;gap:5px;border-radius:20px;padding:6px 10px;font-size:11px;font-weight:750;white-space:nowrap}
.finance-workspace .report-status.is-current{background:#e7f7ef;color:#16734b}
.finance-workspace .report-status.is-overdue{background:#fff1e8;color:#a95b23}
.finance-workspace .report-note{display:flex;align-items:start;gap:10px;padding:13px 16px;border:1px solid #cfe2f5;background:#f3f8fe;border-radius:10px;color:#496889;line-height:1.7;margin-bottom:18px;font-size:12px}
.finance-workspace .report-note i{margin-top:3px;color:#2673b5}
@media(max-width:760px){.finance-workspace .report-metrics{grid-template-columns:1fr}.finance-workspace .report-filter-form label,.finance-workspace .report-filter-form button{width:100%}.finance-workspace .report-panel,.finance-workspace .report-filter{padding:15px}}
</style></head><body>
<header class="top"><a class="brand" href="{{ route('dashboard') }}"><span class="brand-icon"><i class="fa-solid fa-leaf"></i></span><span class="brand-name">KANOZAY<small>FOODS</small></span><span class="brand-divider"></span><span class="brand-sub">ERP System<small>Quality • Nutrition • Growth</small></span></a><div class="search"><i class="fa-solid fa-magnifying-glass"></i><input aria-label="Search" placeholder="Search for products, transactions, suppliers, employees, documents..." disabled><kbd>⌘ K</kbd></div><div class="tools"><details class="top-dropdown language-dropdown"><summary class="language-trigger" aria-label="Select language"><i class="fa-solid fa-globe"></i><span>{{ ['en'=>'EN','fa'=>'دری','ps'=>'پښتو'][session('locale','en')] ?? 'EN' }}</span><i class="fa-solid fa-chevron-down" style="font-size:10px"></i></summary><div class="dropdown-panel language-options"><b>Interface Language</b><form method="POST" action="{{ route('language.update') }}">@csrf<button type="submit" name="locale" value="en" @if(session('locale','en')==='en')class="selected"@endif>🇬🇧 English @if(session('locale','en')==='en')<i class="fa-solid fa-check"></i>@endif</button><button type="submit" name="locale" value="fa" @if(session('locale','en')==='fa')class="selected"@endif>🇦🇫 دری @if(session('locale','en')==='fa')<i class="fa-solid fa-check"></i>@endif</button><button type="submit" name="locale" value="ps" @if(session('locale','en')==='ps')class="selected"@endif>🇦🇫 پښتو @if(session('locale','en')==='ps')<i class="fa-solid fa-check"></i>@endif</button></form></div></details><details class="top-dropdown"><summary aria-label="Notifications" title="Notifications"><i class="fa-regular fa-bell"></i>@if(auth()->user()->unreadNotifications()->count())<span class="count">{{ auth()->user()->unreadNotifications()->count() > 99 ? '99+' : auth()->user()->unreadNotifications()->count() }}</span>@endif</summary><div class="dropdown-panel"><b>Notifications</b>@forelse(auth()->user()->unreadNotifications()->latest()->limit(4)->get() as $notice)<a href="{{ route('notifications.index') }}"><strong>{{ $notice->data['title'] ?? 'Notification' }}</strong><small style="display:block;color:#64799e;margin-top:4px">{{ $notice->data['message'] ?? '' }}</small></a>@empty<p class="muted">You're all caught up.</p>@endforelse<a href="{{ route('notifications.index') }}" style="color:#0868ef;font-weight:700;border-top:1px solid #e6edf7">View all notifications</a></div></details><details class="top-dropdown"><summary aria-label="Help" title="Help"><i class="fa-regular fa-circle-question"></i></summary><div class="dropdown-panel"><b>Help & Support</b><p class="muted">Support options will be available after setup.</p></div></details><details class="top-dropdown"><summary aria-label="Applications" title="Applications"><i class="fa-solid fa-border-all"></i></summary><div class="dropdown-panel"><b>Available modules</b><a href="{{ route('dashboard') }}">Dashboard</a>@can('administration.view')<a href="{{ route('admin.index') }}">Administration</a>@endcan @can('users.view')<a href="{{ route('admin.users.index') }}">User Management</a>@endcan @can('roles.view')<a href="{{ route('admin.roles.index') }}">Roles & Permissions</a>@endcan</div></details><details class="top-dropdown profile-menu"><summary class="profile"><span class="avatar">{{ strtoupper(substr(auth()->user()->name,0,1)) }}</span><span><b>{{ auth()->user()->name }}</b><small>{{ auth()->user()->roles->pluck('name')->first() ?? 'Employee' }}</small></span><i class="fa-solid fa-chevron-down" style="font-size:11px"></i></summary><div class="dropdown-panel"><b>My Account</b><a href="{{ route('profile.edit') }}">Profile & Security</a>@can('users.view')<a href="{{ route('admin.users.index') }}">Manage Users</a>@endcan<form action="{{ route('logout') }}" method="POST">@csrf<button class="logout" type="submit"><i class="fa-solid fa-right-from-bracket"></i> Sign out</button></form></div></details></div></header>
@php($menu=[['Dashboard','house','dashboard','dashboard'],['Purchases','cart-shopping','purchases',null],['Inventory','boxes-stacked','inventory',null],['Manufacturing','industry','manufacturing',null],['Laboratory','flask','laboratory',null],['Sales','truck','sales',null],['CRM','address-book','crm',null],['Shareholders','users','shareholders',null],['Finance','wallet','finance',null],['HR','user-tie','hr',null],['Assets','screwdriver-wrench','assets',null],['Documents','folder-open','documents',null],['Reports','chart-column','reports',null],['Admin','gear','administration','admin.index']])
<nav class="rail">@foreach($menu as $item)<a @if($item[3]) href="{{ route($item[3]) }}" @elseif($item[0] === 'Finance') href="{{ route('finance.overview') }}" @elseif($item[0] === 'Reports') href="{{ route('workspace.page',['reports','overview']) }}" @elseif(config('erp_pages.'.$item[2].'.pages')) href="{{ route('workspace.page',[$item[2],'overview']) }}" @else aria-disabled="true" tabindex="-1" @endif class="{{ ($loop->first && (request()->routeIs('dashboard') || request()->routeIs('approvals.*'))) || ($item[0] === 'Purchases' && request()->routeIs('purchases.orders.*')) || ($item[0] === 'Admin' && request()->routeIs('admin.*')) || ($item[0] === 'Finance' && request()->routeIs('finance.*') && !request()->routeIs('finance.aging.*')) || ($item[0] === 'Reports' && request()->routeIs('finance.aging.*')) || (request()->routeIs('workspace.page') && request()->route('module')===$item[2]) ? 'active' : '' }} {{ $loop->last ? 'settings' : '' }}" title="{{ $item[0] }}"><i class="fa-solid fa-{{ $item[1] }}"></i>{{ $item[0] }}</a>@endforeach</nav>
<aside class="sidebar">@if(request()->routeIs('finance.aging.*'))<div class="module-title"><i class="fa-solid fa-chart-column"></i> Reports</div><a class="active" href="{{ route('finance.aging.index') }}"><i class="fa-solid fa-hourglass-half"></i> Receivables & Payables Aging</a>@elseif(request()->routeIs('workspace.page'))@php($workspaceModule=request()->route('module'))@php($workspaceData=config('erp_pages.'.$workspaceModule))<div class="module-title"><i class="fa-solid fa-{{ $workspaceData['icon'] }}"></i> {{ $workspaceData['label'] }}</div><hr>@can('administration.view')<a class="{{ request()->route('page')==='overview'?'active':'' }}" href="{{ route('workspace.page',[$workspaceModule,'overview']) }}"><i class="fa-solid fa-chart-pie"></i>Overview</a>@foreach($workspaceData['pages'] as $workspaceSlug=>$workspaceLabel)@continue(!in_array($workspaceSlug,config('erp_navigation.'.$workspaceModule,[]),true))<a class="{{ request()->route('page')===$workspaceSlug?'active':'' }}" href="{{ route('workspace.page',[$workspaceModule,$workspaceSlug]) }}"><i class="fa-solid fa-file-lines"></i>{{ $workspaceLabel }}</a>@endforeach @php($selectedPage=request()->route('page'))@if($selectedPage!=='overview' && isset($workspaceData['pages'][$selectedPage]) && !in_array($selectedPage,config('erp_navigation.'.$workspaceModule,[]),true))<a class="active" aria-current="page" href="{{ route('workspace.page',[$workspaceModule,$selectedPage]) }}"><i class="fa-solid fa-layer-group"></i>{{ $workspaceData['pages'][$selectedPage] }}</a>@endif @if($workspaceModule==='reports')<a href="{{ route('finance.aging.index') }}"><i class="fa-solid fa-hourglass-half"></i>Receivables & Payables Aging</a>@endif @endcan @elseif(request()->routeIs('purchases.orders.*'))<div class="module-title"><i class="fa-solid fa-cart-shopping"></i> Purchases</div><hr><a href="{{ route('workspace.page',['purchases','overview']) }}"><i class="fa-solid fa-chart-pie"></i>Overview</a><a class="active" aria-current="page" href="{{ route('purchases.orders.index') }}"><i class="fa-solid fa-file-lines"></i>Purchase Orders</a>@elseif(request()->routeIs('finance.*'))<div class="module-title"><i class="fa-solid fa-wallet"></i> Finance & Accounting</div>@elseif(request()->routeIs('admin.*'))<div class="module-title"><i class="fa-solid fa-gear"></i> Administration <i class="fa-solid fa-chevron-up" style="margin-left:auto"></i></div>@else<div class="module-title"><i class="fa-solid fa-house"></i> Dashboard <i class="fa-solid fa-chevron-up" style="margin-left:auto"></i></div><a class="{{ request()->routeIs('dashboard') ? 'active' : '' }}" href="{{ route('dashboard') }}"><i class="fa-solid fa-circle-check"></i>Executive Overview</a><a class="{{ request()->routeIs('approvals.*') ? 'active' : '' }}" href="{{ route('approvals.index') }}"><i class="fa-regular fa-circle-check"></i>My Tasks & Approvals</a><a href="{{ route('dashboard') }}#production"><i class="fa-regular fa-file-lines"></i>Production Overview</a><a href="{{ route('dashboard') }}#finance"><i class="fa-solid fa-chart-pie"></i>Financial Overview</a>@endif @if(request()->routeIs('admin.*'))<hr><a class="{{ request()->routeIs('admin.index') ? 'active' : '' }}" href="{{ route('admin.index') }}"><i class="fa-solid fa-chart-pie"></i>Overview</a>@can('administration.view')<a class="{{ request()->routeIs('admin.company.*') ? 'active' : '' }}" href="{{ route('admin.company.edit') }}"><i class="fa-solid fa-building"></i>Company & Factory Profile</a><a class="{{ request()->routeIs('admin.currencies.*') ? 'active' : '' }}" href="{{ route('admin.currencies.index') }}"><i class="fa-solid fa-coins"></i>Currencies</a><a class="{{ request()->routeIs('admin.exchange-rates.*') ? 'active' : '' }}" href="{{ route('admin.exchange-rates.index') }}"><i class="fa-solid fa-arrow-right-arrow-left"></i>Exchange Rates</a><a class="{{ request()->routeIs('admin.financial-settings.*') ? 'active' : '' }}" href="{{ route('admin.financial-settings.edit') }}"><i class="fa-solid fa-sliders" style="color:#21875e;background:#e4f7eb"></i>Financial Configuration</a><a class="{{ request()->routeIs('admin.sequences.*') ? 'active' : '' }}" href="{{ route('admin.sequences.index') }}"><i class="fa-solid fa-hashtag" style="color:#8957bc;background:#f3eafd"></i>Document Numbering</a><a class="{{ request()->routeIs('admin.accounts.*') ? 'active' : '' }}" href="{{ route('admin.accounts.index') }}"><i class="fa-solid fa-sitemap" style="color:#2b8d70;background:#e5f6ef"></i>Chart of Accounts</a>@endcan @can('users.view')<a class="{{ request()->routeIs('admin.users.*') ? 'active' : '' }}" href="{{ route('admin.users.index') }}"><i class="fa-solid fa-users"></i>User Management</a>@endcan @can('roles.view')<a class="{{ request()->routeIs('admin.roles.*') ? 'active' : '' }}" href="{{ route('admin.roles.index') }}"><i class="fa-solid fa-shield-halved"></i>Roles & Permissions</a>@endcan @can('administration.view')<a class="{{ request()->routeIs('admin.activity.*') ? 'active' : '' }}" href="{{ route('admin.activity.index') }}"><i class="fa-solid fa-clock-rotate-left"></i>Audit Activity</a>@endcan @endif @if(request()->routeIs('finance.*') && !request()->routeIs('finance.aging.*'))<hr>@can('administration.view')<a class="{{ request()->routeIs('finance.overview') ? 'active' : '' }}" href="{{ route('finance.overview') }}"><i class="fa-solid fa-chart-pie"></i>Overview</a><a class="{{ request()->routeIs('finance.customers.*') || (request()->routeIs('finance.parties.*') && request('type','customer')==='customer') ? 'active' : '' }}" href="{{ route('finance.customers.index') }}"><i class="fa-solid fa-users" style="color:#1a7c9c;background:#e5f6fb"></i>Customers</a><a class="{{ request()->routeIs('finance.suppliers.*') || (request()->routeIs('finance.parties.*') && request('type')==='supplier') ? 'active' : '' }}" href="{{ route('finance.suppliers.index') }}"><i class="fa-solid fa-truck-field" style="color:#95651b;background:#fff4dc"></i>Suppliers</a><a class="{{ request()->routeIs('finance.journals.*') ? 'active' : '' }}" href="{{ route('finance.journals.index') }}"><i class="fa-solid fa-book" style="color:#21875e;background:#e4f7eb"></i>Journal Entries</a><a class="{{ request()->routeIs('finance.accounts.*') ? 'active' : '' }}" href="{{ route('finance.accounts.index') }}"><i class="fa-solid fa-building-columns" style="color:#15946c;background:#e6f7ef"></i>Financial Accounts</a><a class="{{ request()->routeIs('finance.money.*') ? 'active' : '' }}" href="{{ route('finance.money.index') }}"><i class="fa-solid fa-money-bill-transfer" style="color:#a46b1d;background:#fff2df"></i>Receipts & Payments</a><a class="{{ request()->routeIs('finance.documents.*') ? 'active' : '' }}" href="{{ route('finance.documents.index') }}"><i class="fa-solid fa-file-invoice-dollar" style="color:#227a69;background:#e7f6f1"></i>Receivables & Payables</a><a class="{{ request()->routeIs('finance.exchanges.*') ? 'active' : '' }}" href="{{ route('finance.exchanges.index') }}"><i class="fa-solid fa-arrow-right-arrow-left" style="color:#7858bb;background:#f1eaff"></i>Currency Exchange</a><a class="{{ request()->routeIs('finance.ledger.*') ? 'active' : '' }}" href="{{ route('finance.ledger.index') }}"><i class="fa-solid fa-list-ol" style="color:#2266ad;background:#e8f1ff"></i>General Ledger</a><a class="{{ request()->routeIs('finance.reconciliation.*') ? 'active' : '' }}" href="{{ route('finance.reconciliation.index') }}"><i class="fa-solid fa-scale-balanced"></i>Settlement Reconciliation</a>@endcan @endif</aside>
<main class="content {{ request()->routeIs('finance.*') ? 'finance-workspace' : '' }}">@yield('content')</main>
<script>
(function(){
 const rail=document.querySelector('nav.rail');
 const activeModule=rail?.querySelector('a.active');
 if(rail && activeModule){
   const top=activeModule.offsetTop-rail.offsetTop;
   if(top<rail.scrollTop || top+activeModule.offsetHeight>rail.scrollTop+rail.clientHeight){
     rail.scrollTop=Math.max(0,top-rail.clientHeight/2+activeModule.offsetHeight/2);
   }
 }
 const sidebar=document.querySelector('aside.sidebar');
 const activeSubmenu=sidebar?.querySelector('a.active');
 if(sidebar && activeSubmenu){
   const top=activeSubmenu.offsetTop-sidebar.offsetTop;
   if(top<sidebar.scrollTop || top+activeSubmenu.offsetHeight>sidebar.scrollTop+sidebar.clientHeight){
     sidebar.scrollTop=Math.max(0,top-sidebar.clientHeight/2+activeSubmenu.offsetHeight/2);
   }
 }
 const menus=[...document.querySelectorAll('.top-dropdown')];
 menus.forEach(menu=>menu.addEventListener('toggle',()=>{if(menu.open)menus.forEach(other=>{if(other!==menu)other.open=false;});}));
 document.addEventListener('click',event=>{menus.forEach(menu=>{if(!menu.contains(event.target))menu.open=false;});});
 document.addEventListener('keydown',event=>{if(event.key==='Escape')menus.forEach(menu=>menu.open=false);});
 document.querySelectorAll('[data-modal-open]').forEach(btn=>btn.addEventListener('click',()=>{const dialog=document.getElementById(btn.dataset.modalOpen);if(dialog&&!dialog.open)dialog.showModal();}));
 document.querySelectorAll('[data-modal-close]').forEach(btn=>btn.addEventListener('click',()=>btn.closest('dialog')?.close()));
 document.querySelectorAll('dialog.k-modal').forEach(dialog=>dialog.addEventListener('click',event=>{if(event.target===dialog)dialog.close();}));
})();
</script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css">
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
(function(){
 function enhanceSelects(root){
  if(!window.jQuery||!jQuery.fn.select2)return;
  jQuery(root).find('select').addBack('select').each(function(){
   if(this.dataset.noSelect2==='1'||jQuery(this).hasClass('select2-hidden-accessible'))return;
   const dialog=this.closest('dialog');
   jQuery(this).select2({width:'100%',dropdownAutoWidth:false,dropdownParent:dialog?jQuery(dialog):jQuery(document.body)});
  });
 }
 // Shared entity creation for searchable record selectors. Only explicit, supported entity types are offered.
 function setupEntityQuickCreate(){
  const endpoints={currency:@json(route('purchases.quick-currency')),supplier:@json(route('purchases.quick-supplier'))};
  const canCreate=@json(auth()->check() && auth()->user()->can('administration.update'));
  if(!canCreate)return;
  const modal=document.createElement('dialog');modal.className='k-create-dialog';modal.id='k-entity-quick-create';
  modal.innerHTML='<div class="k-create-head"><h2 id="k-entity-quick-title">Add New</h2><button type="button" data-close aria-label="Close">×</button></div><div class="k-create-body"><form id="k-entity-quick-form"><div id="k-entity-quick-fields"></div><p id="k-entity-quick-error" role="alert" style="color:#a83232" hidden></p><button type="submit" class="btn">Save and Select</button></form></div>';
  document.body.append(modal);modal.querySelector('[data-close]').onclick=()=>modal.close();
  let target=null,kind=null;
  const labels={currency:'Currency',supplier:'Supplier'};
  const fieldHtml={currency:'<label>Code (3 letters)<input name="code" required maxlength="3"></label><label>Name<input name="name" required maxlength="100"></label><label>Symbol<input name="symbol" maxlength="12"></label>',supplier:'<label>Supplier name<input name="name" required maxlength="180"></label><label>Phone<input name="phone" maxlength="80"></label>'};
  function show(select,type){
   target=select;kind=type;modal.querySelector('h2').textContent='Add New '+labels[type];modal.querySelector('#k-entity-quick-fields').innerHTML=fieldHtml[type];modal.querySelector('form').reset();modal.querySelector('#k-entity-quick-error').hidden=true;
   if(type==='supplier'){
    const currency=select.form?.querySelector('select[name="currency_id"]');
    if(!currency?.value){alert('Select a currency first, then add the supplier.');return;}
   }
   modal.showModal();
  }
  function register(root){
   root.querySelectorAll('select').forEach(select=>{
    if(select.dataset.quickRegistered==='1'||select.disabled||select.multiple||!select.form||select.form.method.toLowerCase()==='get'||select.closest('#k-entity-quick-create'))return;
    const name=select.name||'';
    const type=select.dataset.createEntity||(name==='supplier_id'?'supplier':name==='currency_id'||name==='from_currency_id'||name==='to_currency_id'?'currency':'');
    if(!endpoints[type]||select.closest('.po-combo'))return;
    select.dataset.quickRegistered='1';
    const opt=new Option('+ Add New '+labels[type]+'…','__create_new__');
    opt.dataset.quickCreate=type;select.add(opt);
    const previous=select.value==='__create_new__'?'':select.value;
    let last=previous;
    select.addEventListener('change',()=>{
     if(select.value==='__create_new__'){select.value=last;if(window.jQuery)jQuery(select).trigger('change.select2');show(select,type)}
     else last=select.value;
    });
   });
  }
  register(document.body);
  modal.querySelector('form').addEventListener('submit',async e=>{
   e.preventDefault();const form=e.currentTarget,button=form.querySelector('button[type="submit"]'),error=modal.querySelector('#k-entity-quick-error'),data=new FormData(form);
   if(kind==='supplier')data.append('currency_id',target.form?.querySelector('select[name="currency_id"]')?.value||'');
   data.append('_token',document.querySelector('input[name="_token"]')?.value||@json(csrf_token()));
   button.disabled=true;error.hidden=true;
   try{
    const response=await fetch(endpoints[kind],{method:'POST',headers:{Accept:'application/json','X-Requested-With':'XMLHttpRequest'},body:data});
    const result=await response.json();
    if(!response.ok)throw new Error(Object.values(result.errors||{}).flat().join(' ')||result.message||'Could not save');
    document.querySelectorAll('select').forEach(s=>{
     if(s===target||s.name===target.name){
      s.add(new Option(result.text,result.id,s===target,s===target));
      if(s===target){s.value=result.id;if(window.jQuery)jQuery(s).trigger('change');}
     }
    });
    modal.close();
   }catch(ex){error.textContent=ex.message;error.hidden=false}finally{button.disabled=false}
  });
 }
 function setupCreationDialogs(){
  document.querySelectorAll('form[data-new-entry-form]').forEach((form,index)=>{
   if(form.closest('dialog'))return;
   const panel=form.closest('section.panel');if(!panel)return;
   const heading=panel.querySelector('h2');const title=heading?.textContent.trim()||'New Entry';
   const dialog=document.createElement('dialog');dialog.className='k-create-dialog';dialog.id='k-create-'+index;dialog.setAttribute('aria-labelledby',dialog.id+'-title');
   const head=document.createElement('div');head.className='k-create-head';
   const label=document.createElement('h2');label.id=dialog.id+'-title';label.textContent=title;const close=document.createElement('button');close.type='button';close.setAttribute('aria-label','Close dialog');close.textContent='×';close.addEventListener('click',()=>dialog.close());head.append(label,close);
   const body=document.createElement('div');body.className='k-create-body';
   const intro=[...panel.children].filter(child=>child.tagName==='P');intro.forEach(el=>body.append(el));
   body.append(form);dialog.append(head,body);document.body.append(dialog);
   const trigger=document.createElement('button');trigger.type='button';trigger.className='k-create-trigger';trigger.innerHTML='<i class="fa-solid fa-plus"></i> '+title;
   trigger.setAttribute('aria-haspopup','dialog');trigger.setAttribute('aria-controls',dialog.id);trigger.addEventListener('click',()=>{dialog.showModal();enhanceSelects(dialog);});
   // Place the action inside the full-width register card header, not the form card.
   const container=panel.parentElement;
   const register=[...container.children].find(el=>el!==panel&&el.matches?.('section.panel'));
   if(register){
    register.classList.add('k-register-panel');
    let header=register.querySelector(':scope > .k-register-header');
    if(!header){header=document.createElement('div');header.className='k-register-header';const registerTitle=register.querySelector(':scope > h2');if(registerTitle)header.append(registerTitle);register.prepend(header);}
    header.append(trigger);
    panel.classList.add('k-create-source');
   }else if(heading){heading.insertAdjacentElement('afterend',trigger);}
   dialog.addEventListener('click',e=>{if(e.target===dialog)dialog.close();});
   if(index===0&&(document.querySelector('.error')||document.querySelector('[data-form-errors]')))dialog.showModal();
  });
 }
 document.addEventListener('DOMContentLoaded',()=>{
  try{setupCreationDialogs();document.body.classList.add('k-create-ready');}catch(error){console.error('Create form initialization failed',error);document.querySelectorAll('.k-create-source').forEach(panel=>panel.classList.remove('k-create-source'));}
  try{enhanceSelects(document.body);}catch(error){console.error('Select2 initialization failed',error);}
  try{setupEntityQuickCreate();}catch(error){console.error('Quick create initialization failed',error);}
  // Select2 consumes native change handling differently across browsers.
  // Bind filters to the enhanced selection and submit the correct GET form.
  if(window.jQuery&&jQuery.fn.select2){
   jQuery('select[data-auto-submit="1"]').off('change.kFilter').on('change.kFilter',function(){
    const form=this.form;if(form&&form.method.toLowerCase()==='get')form.requestSubmit();
   });
  }else{
   document.querySelectorAll('select[data-auto-submit="1"]').forEach(select=>select.addEventListener('change',()=>select.form?.requestSubmit()));
  }
 });
 // Enhance dynamic journal rows and selects added after initial page load.
 new MutationObserver(records=>{for(const record of records)for(const node of record.addedNodes)if(node.nodeType===1&&node.matches?.('.journal-line'))enhanceSelects(node);}).observe(document.documentElement,{childList:true,subtree:true});
})();
</script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
@if(session()->has('status'))
<script>
document.addEventListener('DOMContentLoaded',function(){
 const rawStatus = @json(session('status'));
 const message = ({'profile-updated':'Profile updated successfully.','password-updated':'Password changed successfully.'})[rawStatus] || rawStatus;
 if(typeof Swal !== 'undefined') Swal.fire({icon:'success',title:'Success',text:message,confirmButtonColor:'#0868ef',timer:2600,timerProgressBar:true});
 else { const alertBox=document.createElement('div');alertBox.setAttribute('role','status');alertBox.textContent=message;alertBox.style.cssText='position:fixed;right:20px;top:75px;z-index:9999;background:#e6f6f1;color:#087a47;padding:16px 22px;border:1px solid #b4e4d0;border-radius:8px;box-shadow:0 8px 25px #0002';document.body.append(alertBox);setTimeout(()=>alertBox.remove(),4500); }
});
</script>
@endif
@stack('scripts')
</body></html>