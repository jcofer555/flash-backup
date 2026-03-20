'use strict';

// ── Custom confirm/alert dialogs ──────────────────────────────────────────────
// Replaces all native alert() and confirm() calls with styled modals.

function fbbAlert(msg, title) {
  const overlay = document.getElementById('fbb-alert-overlay');
  const msgEl   = document.getElementById('fbb-alert-msg');
  const titEl   = document.getElementById('fbb-alert-title');
  const okBtn   = document.getElementById('fbb-alert-ok');
  if (!overlay) { return; }
  if (titEl) titEl.textContent = title || 'Notice';
  msgEl.textContent = msg;
  overlay.classList.add('open');
  function handleOk() {
    overlay.classList.remove('open');
    okBtn.removeEventListener('click', handleOk);
  }
  okBtn.addEventListener('click', handleOk);
}

function fbbConfirm(msg, onOk, title) {
  const overlay  = document.getElementById('fbb-confirm-overlay');
  const msgEl    = document.getElementById('fbb-confirm-msg');
  const titEl    = document.getElementById('fbb-confirm-title');
  const okBtn    = document.getElementById('fbb-confirm-ok');
  const canBtn   = document.getElementById('fbb-confirm-cancel');
  if (!overlay) { if (onOk) onOk(); return; }
  if (titEl) titEl.textContent = title || 'Confirm';
  msgEl.textContent = msg;
  overlay.classList.add('open');
  function cleanup() {
    overlay.classList.remove('open');
    okBtn.removeEventListener('click',  handleOk);
    canBtn.removeEventListener('click', handleCancel);
  }
  function handleOk()     { cleanup(); if (onOk) onOk(); }
  function handleCancel() { cleanup(); }
  okBtn.addEventListener('click',  handleOk);
  canBtn.addEventListener('click', handleCancel);
}

// ── Mode switcher ─────────────────────────────────────────────────────────────
function fbbSwitchMode(mode) {
  const isLocal = (mode === 'local');
  document.getElementById('flash-backup-settings').style.display        = isLocal ? '' : 'none';
  document.getElementById('flash-backup-settings-remote').style.display = isLocal ? 'none' : '';
  document.getElementById('fbb-active-card-title').textContent = isLocal ? 'Local Backup' : 'Remote Backup';
  document.getElementById('fbb-local-status-wrap').style.display  = isLocal ? '' : 'none';
  document.getElementById('fbb-remote-status-wrap').style.display = isLocal ? 'none' : '';
  if (isLocal) {
    if (typeof resetScheduleUIremote === 'function') resetScheduleUIremote();
  } else {
    if (typeof resetScheduleUI === 'function') resetScheduleUI();
    $.get('/plugins/flash-backup/helpers/schedule_list_remote.php', function(html) {
      $('#schedule-list-remote').html(html);
      if ($('#schedule-list-remote .TableContainer').length > 0) {
        const localVisible = $('#fbb-sched-title-local').is(':visible');
        $('#fbb-sched-title-remote').css('margin-top', localVisible ? '12px' : '0').show();
      } else {
        $('#fbb-sched-title-remote').hide();
      }
    });
  }
}

// ── Status dot updater ────────────────────────────────────────────────────────
(function() {
  function updateDot(dotId, textId) {
    const dot  = document.getElementById(dotId);
    const text = document.getElementById(textId);
    if (!dot || !text) return;
    const s = (text.textContent || '').toLowerCase();
    const running = s !== 'idle' && s.indexOf('not running') === -1 && s.trim() !== '';
    dot.classList.toggle('active', running);
  }
  setInterval(function() {
    updateDot('fbb-local-dot',  'status-text');
    updateDot('fbb-remote-dot', 'status-text-remote');
  }, 1000);
})();

// ── Tooltipster ───────────────────────────────────────────────────────────────
$(document).on('mouseenter', '.flash-backuptip', function () {
  const $el = $(this);
  const isButtonWrapper = $el.is('span') && $el.children('button').length > 0;
  const isCheckboxLabel = $el.is('label') && $el.find('input[type="checkbox"]').length > 0;
  const isButton        = $el.is('button');
  const isTableTip      = $el.is('span') && $el.closest('table').length > 0;
  if (!isButtonWrapper && !isCheckboxLabel && !isButton && !isTableTip) return;
  if (!$el.hasClass('tooltipstered')) {
    const tipContent = $el.attr('title') || $el.data('tooltip') || '';
    $el.tooltipster({ maxWidth: 300, content: tipContent });
    $el.removeAttr('title');
    setTimeout(() => { if ($el.is(':hover')) $el.tooltipster('open'); }, 500);
  }
});

// ── Webhook / notification helpers ───────────────────────────────────────────
const SERVICE_CONFIG = {
  Discord:  { label:'Discord Webhook URL',    tooltip:'Enter your Discord webhook URL e.g. https://discord.com/api/webhooks/WEBHOOK or just WEBHOOK', placeholder:'https://discord.com/api/webhooks/WEBHOOK', prefix:'https://discord.com/api/webhooks/', needsUserKey:false, needsUrl:true },
  Gotify:   { label:'Gotify URL',             tooltip:'Enter your Gotify server message URL',     placeholder:'https://gotify.example.com/message?token=TOKEN', prefix:'https://gotify.example.com/message?token=', needsUserKey:false, needsUrl:true },
  Ntfy:     { label:'Ntfy URL',               tooltip:'Enter your Ntfy topic URL e.g. https://ntfy.sh/yourtopic', placeholder:'https://ntfy.sh/yourtopic', prefix:'https://ntfy.sh/', needsUserKey:false, needsUrl:true },
  Pushover: { label:'Pushover App Token URL', tooltip:'Enter your Pushover app token URL',        placeholder:'https://api.pushover.net/YOURAPPTOKEN', prefix:'https://api.pushover.net/', needsUserKey:true, needsUrl:true },
  Slack:    { label:'Slack Webhook URL',      tooltip:'Enter your Slack webhook URL',             placeholder:'https://hooks.slack.com/services/ID', prefix:'https://hooks.slack.com/services/', needsUserKey:false, needsUrl:true },
  Unraid:   { label:null, tooltip:"Uses Unraid's built-in notification system", placeholder:null, prefix:null, needsUserKey:false, needsUrl:false }
};

function normalizeWebhookUrl(val, service) {
  val = val.trim();
  if (!val) return val;
  if (val.startsWith('https://')) return val;
  const cfg = SERVICE_CONFIG[service];
  if (cfg && cfg.prefix) return cfg.prefix + val;
  return val;
}
function validateWebhookUrl(val, service) {
  if (!val) return true;
  const cfg = SERVICE_CONFIG[service];
  if (!cfg || !cfg.prefix) return true;
  return val.startsWith(cfg.prefix);
}
function getSelectedServices(suffix) {
  const listId = suffix ? '#notification-service-list-' + suffix : '#notification-service-list';
  return $(listId).find('input:checked').map(function() { return $(this).val(); }).get();
}
function updateServiceLabel(suffix) {
  const services = getSelectedServices(suffix);
  const labelId  = suffix ? '#notification-service-label-' + suffix : '#notification-service-label';
  $(labelId).text(services.length ? services.join(', ') : 'Select service(s)');
}
function rebuildWebhookFields(suffix) {
  const s           = suffix || '';
  const containerId = s ? '#webhook-fields-container-' + s : '#webhook-fields-container';
  const services    = getSelectedServices(s);
  const container   = $(containerId);
  container.empty();
  const savedWebhooks   = s ? SAVED_WEBHOOKS_REMOTE : SAVED_WEBHOOKS;
  const savedPushoverKey = s ? SAVED_PUSHOVER_USER_KEY_REMOTE : SAVED_PUSHOVER_USER_KEY;
  services.forEach(function(service) {
    const cfg = SERVICE_CONFIG[service]; if (!cfg) return;
    const s_dash  = s ? '-' + s : '';
    const s_under = s ? '_' + s : '';
    if (cfg.needsUrl) {
      const urlFieldId = 'webhook_url_' + service.toLowerCase() + s_under;
      const errorId    = 'webhook-error-' + service.toLowerCase() + s_dash;
      const savedVal   = savedWebhooks[service.toUpperCase()] || '';
      const urlRow = $(`
        <div class="form-pair" id="webhook-row-${service.toLowerCase()}${s_dash}">
          <label><span class="flash-backuptip" title="${cfg.tooltip}">${cfg.label}:</span></label>
          <div class="input-wrapper">
            <span><input type="text" id="${urlFieldId}" class="short-input webhook-url-input"
              data-service="${service}" data-suffix="${s}"
              placeholder="${cfg.placeholder}" value="${savedVal}"></span>
            <div id="${errorId}" style="color:yellow;font-size:1.00em;display:none;">* Invalid ${service} URL</div>
          </div>
        </div>`);
      container.append(urlRow);
    }
    if (cfg.needsUserKey) {
      const pkFieldId = 'pushover_user_key' + s_under;
      const pkErrorId = 'pushover-user-key-error' + s_dash;
      const pkRow = $(`
        <div class="form-pair" id="pushover-user-key-row${s_dash}">
          <label><span class="flash-backuptip" title="Your Pushover user key from pushover.net/dashboard">Pushover User Key:</span></label>
          <div class="input-wrapper">
            <span><input type="text" id="${pkFieldId}" name="PUSHOVER_USER_KEY${s ? '_REMOTE' : ''}"
              class="short-input" placeholder="user key from pushover.net/dashboard" value="${savedPushoverKey}"></span>
            <div id="${pkErrorId}" style="color:yellow;font-size:1.00em;display:none;">* Pushover user key is required</div>
          </div>
        </div>`);
      container.append(pkRow);
    }
  });
  container.find('.webhook-url-input').on('input', function() {
    const service = $(this).data('service');
    const val     = $(this).val().trim();
    const errorId = '#webhook-error-' + service.toLowerCase() + (s ? '-' + s : '');
    const valid   = val === '' || validateWebhookUrl(val, service);
    $(errorId).toggle(!valid);
  }).on('blur', function() {
    const service    = $(this).data('service');
    const normalized = normalizeWebhookUrl($(this).val(), service);
    $(this).val(normalized).trigger('input');
  });
  if (window._fbbProcessLabels) window._fbbProcessLabels(container);
}
function toggleNotificationRows(suffix) {
  const s                = suffix || '';
  const notifSelectId    = s ? '#notifications_' + s : '#notifications';
  const serviceRowId     = s ? '#notification-service-row-' + s : '#notification-service-row';
  const webhookContainer = s ? '#webhook-fields-container-' + s : '#webhook-fields-container';
  if ($(notifSelectId).val() === 'yes') {
    $(serviceRowId).removeClass('fbb-row-hidden');
    if (window._fbbProcessLabels) window._fbbProcessLabels($(serviceRowId));
    rebuildWebhookFields(s); setTimeout(fbbWrapSelects, 50);
  } else {
    $(serviceRowId).addClass('fbb-row-hidden');
    $(webhookContainer).empty();
  }
}

// ── Dropdown wiring ───────────────────────────────────────────────────────────
$('#notification_service').on('click',        function(e) { e.stopPropagation(); $('#notification-service-list').toggle(); });
$('#notification_service_remote').on('click', function(e) { e.stopPropagation(); $('#notification-service-list-remote').toggle(); });
$('#notification-service-list').on('click',        function(e) { e.stopPropagation(); });
$('#notification-service-list-remote').on('click', function(e) { e.stopPropagation(); });
$(document).on('click', function(e) {
  if (!$(e.target).closest('#notification_service').length)        $('#notification-service-list').hide();
  if (!$(e.target).closest('#notification_service_remote').length) $('#notification-service-list-remote').hide();
});
$('#notification-service-list').on('change',        'input[type=checkbox]', function() { updateServiceLabel('');       rebuildWebhookFields(''); });
$('#notification-service-list-remote').on('change', 'input[type=checkbox]', function() { updateServiceLabel('remote'); rebuildWebhookFields('remote'); });
$('#notifications').on('change',        function() { toggleNotificationRows(''); });
$('#notifications_remote').on('change', function() { toggleNotificationRows('remote'); });
toggleNotificationRows('');
toggleNotificationRows('remote');
updateServiceLabel('');
updateServiceLabel('remote');

$('#rclone_config_remote').on('click', function(e) { e.stopPropagation(); $('#rclone-config-list-remote').toggle(); });
$('#rclone-config-list-remote').on('click', function(e) { e.stopPropagation(); });
$(document).on('click', function(e) { if (!$(e.target).closest('#rclone_config_remote').length) $('#rclone-config-list-remote').hide(); });

$('#rclone-config-list-remote').on('change', 'input[type=checkbox]', function() {
  const selected = $('#rclone-config-list-remote').find('input:checked').map(function() { return $(this).val(); }).get();
  $('#rclone-config-label-remote').text(selected.length ? selected.join(', ') : 'Select config(s)');
  $('#rclone_config_remote_hidden option').each(function() { $(this).prop('selected', selected.includes($(this).val())); });
  updateBucketVisibility();
});

// ── Rclone config poller (1 s) ────────────────────────────────────────────────
// Polls get_rclone_remotes.php every second and updates the multiselect if the
// list of remotes has changed (added / removed / renamed).
let _fbbRcloneSnapshot = '';
(function pollRcloneConfigs() {
  fetch('/plugins/flash-backup/helpers/get_rclone_remotes.php')
    .then(r => r.json())
    .then(data => {
      const remotes = (data.remotes || []);
      const types   = (data.types   || {});
      const snap    = JSON.stringify(remotes);
      if (snap === _fbbRcloneSnapshot) return;          // nothing changed
      _fbbRcloneSnapshot = snap;

      // Capture currently selected values before rebuilding
      const currentlySelected = $('#rclone_config_remote_hidden').val() || [];

      // Rebuild the hidden <select> options
      const $hidden = $('#rclone_config_remote_hidden');
      $hidden.empty();
      remotes.forEach(r => {
        const sel = currentlySelected.includes(r) ? ' selected' : '';
        $hidden.append(`<option value="${r}"${sel}>${r}</option>`);
      });

      // Rebuild the dropdown list checkboxes
      const $list = $('#rclone-config-list-remote');
      $list.empty();
      const rtype = remoteTypes || {}; // from inline PHP JSON
      remotes.forEach(r => {
        const t   = types[r] || rtype[r] || 'unknown';
        const chk = currentlySelected.includes(r) ? ' checked' : '';
        $list.append(`<div><label><input type="checkbox" value="${r}"${chk}>${t} - ${r}</label></div>`);
      });

      // Re-wire the change handler for new checkboxes
      $list.off('change', 'input[type=checkbox]').on('change', 'input[type=checkbox]', function() {
        const sel = $list.find('input:checked').map(function() { return $(this).val(); }).get();
        $('#rclone-config-label-remote').text(sel.length ? sel.join(', ') : 'Select config(s)');
        $hidden.find('option').each(function() { $(this).prop('selected', sel.includes($(this).val())); });
        updateBucketVisibility();
      });

      // Update visible label
      const stillSelected = currentlySelected.filter(r => remotes.includes(r));
      $('#rclone-config-label-remote').text(stillSelected.length ? stillSelected.join(', ') : 'Select config(s)');

      // Toggle disabled state when there are no remotes
      const $widget = $('#rclone_config_remote');
      if (remotes.length === 0) {
        $widget.addClass('fbb-rclone-disabled');
        $('#rclone-config-label-remote').text('No rclone configs found');
      } else {
        $widget.removeClass('fbb-rclone-disabled');
        if (!stillSelected.length) $('#rclone-config-label-remote').text('Select config(s)');
      }

      // Refresh bucket fields in case a selected remote was removed
      updateBucketVisibility();
    })
    .catch(() => {})
    .finally(() => setTimeout(pollRcloneConfigs, 1000));
})();

// ── Schedule UI lock helpers ──────────────────────────────────────────────────
let scheduleUILocked = false;
function lockScheduleUI()   { scheduleUILocked = true;  $('.schedule-action-btn').prop('disabled', true); }
function unlockScheduleUI() { scheduleUILocked = false; $('.schedule-action-btn').prop('disabled', false); }

let scheduleUILockedremote = false;
function lockScheduleUIremote()   { scheduleUILockedremote = true;  $('.schedule-action-btn-remote').prop('disabled', true); }
function unlockScheduleUIremote() { scheduleUILockedremote = false; $('.schedule-action-btn-remote').prop('disabled', false); }

// ── Validation helpers ────────────────────────────────────────────────────────
const BUCKET_REMOTE_TYPES = ['b2', 'crypt-b2', 's3', 'crypt-s3'];

function normalizeBucketName(val, isB2Only) {
  val = val.trim(); if (!val) return val;
  val = val.replace(/\/+/g, '/');
  if (isB2Only) val = val.toLowerCase();
  if (!val.endsWith('/')) val += '/';
  return val;
}
function collectBucketNames() {
  const map = {};
  $('#bucket-fields-container .bucket-name-input').each(function() {
    const remote = $(this).data('remote');
    const val    = $(this).val().trim();
    if (remote && val) map[remote] = val;
  });
  return map;
}
function updateBucketVisibility() {
  const selected = $('#rclone_config_remote_hidden').val() || [];
  const container = $('#bucket-fields-container');
  const existing  = collectBucketNames();
  container.empty();
  const bucketRemotes = selected.filter(r => BUCKET_REMOTE_TYPES.includes(remoteTypes[r]));
  bucketRemotes.forEach(function(remote) {
    const isB2       = remoteTypes[remote] === 'b2' || remoteTypes[remote] === 'crypt-b2';
    const typeLabel  = isB2 ? 'B2' : 'S3';
    const placeholder = isB2 ? 'my-b2-bucket' : 'my-s3-bucket';
    const fieldId    = 'bucket_name_' + remote.replace(/[^a-zA-Z0-9_]/g, '_');
    const tooltip    = `Bucket name for ${remote} (${typeLabel}) — do not include a leading slash`;
    let savedVal = existing[remote] || SAVED_BUCKET_NAMES[remote] || '';
    if (!savedVal && isB2 && SAVED_B2_BUCKET_NAME_LEGACY) savedVal = SAVED_B2_BUCKET_NAME_LEGACY;
    const row = $(`
      <div class="form-pair bucket-field-row" data-remote="${remote}">
        <label><span class="flash-backuptip" title="${tooltip}">Bucket (${remote}):</span></label>
        <span><input type="text" id="${fieldId}" class="bucket-name-input"
          data-remote="${remote}" data-is-b2="${isB2 ? '1' : '0'}"
          placeholder="${placeholder}" autocomplete="off"
          style="height:29px !important;box-sizing:border-box !important;"
          value="${savedVal}"></span>
      </div>`);
    container.append(row);
  });
  container.find('.bucket-name-input').off('blur').on('blur', function() {
    const isB2 = $(this).data('is-b2') === '1' || $(this).data('is-b2') === 1;
    $(this).val(normalizeBucketName($(this).val(), isB2));
  });
  container.find('.bucket-name-input').each(function() {
    const isB2 = $(this).data('is-b2') === '1' || $(this).data('is-b2') === 1;
    if ($(this).val().trim()) $(this).val(normalizeBucketName($(this).val(), isB2));
  });
  if (window._fbbProcessLabels) window._fbbProcessLabels(container);
}
function updateB2BucketVisibility() { updateBucketVisibility(); }
updateBucketVisibility();

function validateBackupPrereqs() {
  const dest = $('#backup_destination').val()?.trim();
  if (!dest) { fbbAlert('Please select a backup destination for the schedule'); return false; }
  if ($('#notifications').val() === 'yes' && getSelectedServices('').includes('Pushover')) {
    if (!$('#pushover_user_key').val().trim()) { fbbAlert('Please enter your Pushover user key'); return false; }
  }
  return true;
}
function validateBackupPrereqsremote() {
  const selectedRemotes = $('#rclone_config_remote_hidden').val() || [];
  if (!selectedRemotes.length) { fbbAlert('Please select at least one rclone config'); return false; }
  const remotePath = $('#remote_path_in_config').val().trim();
  if (remotePath !== '' && !remotePath.startsWith('/')) { fbbAlert('Path In Config must start with a "/" or be left blank to use default /Flash_Backups'); return false; }
  if (remotePath !== '' && !remotePath.endsWith('/'))   { fbbAlert('Path In Config must end with a "/" or be left blank to use default /Flash_Backups'); return false; }
  if (remotePath !== '') {
    const inner = remotePath.replace(/^\/+|\/+$/g, '');
    const parts = inner.split('/');
    const validName = /^[A-Za-z0-9._+\-@ ]+$/;
    for (const p of parts) { if (!validName.test(p)) { fbbAlert('Invalid character detected in folder name: "' + p + '"\n\nAllowed characters:\nletters, numbers, space, _ - . + @'); return false; } }
  }
  const bucketRemotes = selectedRemotes.filter(r => BUCKET_REMOTE_TYPES.includes(remoteTypes[r]));
  for (const remote of bucketRemotes) {
    const input     = $(`#bucket-fields-container .bucket-name-input[data-remote="${remote}"]`);
    const bucketVal = input.val().trim();
    if (!bucketVal) { fbbAlert(`Bucket name is required for remote "${remote}"`); input.focus(); return false; }
    const bucketStripped = bucketVal.replace(/\/+$/, '');
    if (!/^[A-Za-z0-9._\-]+$/.test(bucketStripped)) { fbbAlert(`Invalid bucket name for "${remote}": "${bucketVal}"\n\nAllowed characters:\nletters, numbers, - . _`); input.focus(); return false; }
  }
  if ($('#notifications_remote').val() === 'yes' && getSelectedServices('remote').includes('Pushover')) {
    if (!$('#pushover_user_key_remote').val().trim()) { fbbAlert('Please enter your Pushover user key'); return false; }
  }
  return true;
}

// ── Folder picker toast ───────────────────────────────────────────────────────
function showFolderToast(msg) {
  const t = document.getElementById('folderToast');
  if (!t) return;
  t.textContent = msg;
  t.classList.add('visible');
  clearTimeout(t._timer);
  t._timer = setTimeout(() => { t.classList.remove('visible'); }, 2000);
}

// ── Backup status polling ─────────────────────────────────────────────────────
function updateBackupStatus() {
  fetch('/plugins/flash-backup/helpers/backup_status_check.php')
    .then(res => res.json()).then(data => { document.getElementById('status-text').textContent = data.status; })
    .catch(() => { document.getElementById('status-text').textContent = 'Local Backup Not Running'; });
}
updateBackupStatus();
setInterval(updateBackupStatus, 1000);

function updateRestoreStatus() {
  fetch('/plugins/flash-backup/helpers/remote_status_check.php')
    .then(res => res.json()).then(data => { document.getElementById('status-text-remote').textContent = data.status; })
    .catch(() => { document.getElementById('status-text-remote').textContent = 'Remote Backup Not Running'; });
}
updateRestoreStatus();
setInterval(updateRestoreStatus, 1000);

// ── Cron mode helpers ─────────────────────────────────────────────────────────
(function waitForSchedulingToggle() {
  function initSchedulingToggle() {
    const cronModeSelect = document.getElementById('cron_mode'); if (!cronModeSelect) return false;
    const hourlyOptions  = document.getElementById('hourly-options');
    const dailyOptions   = document.getElementById('daily-options');
    const weeklyOptions  = document.getElementById('weekly-options');
    const monthlyOptions = document.getElementById('monthly-options');
    const hourlyFreq     = document.getElementById('hourly_frequency');
    const dailyTime      = document.getElementById('daily_time');
    const weeklyDay      = document.getElementById('weekly_day');
    const weeklyTime     = document.getElementById('weekly_time');
    const monthlyDay     = document.getElementById('monthly_day');
    const monthlyTime    = document.getElementById('monthly_time');
    function toggleCronOptions(value) {
      hourlyOptions.style.display  = (value === 'hourly')  ? 'block' : 'none';
      dailyOptions.style.display   = (value === 'daily')   ? 'block' : 'none';
      weeklyOptions.style.display  = (value === 'weekly')  ? 'block' : 'none';
      monthlyOptions.style.display = (value === 'monthly') ? 'block' : 'none';
      updateCronExpression();
    }
    function updateCronExpression() {
      let cronString = '';
      if      (cronModeSelect.value === 'hourly'  && hourlyFreq)  cronString = `0 */${parseInt(hourlyFreq.value, 10)} * * *`;
      else if (cronModeSelect.value === 'daily'   && dailyTime)   cronString = `${parseInt(document.getElementById('daily_minute').value, 10)} ${parseInt(dailyTime.value, 10)} * * *`;
      else if (cronModeSelect.value === 'weekly'  && weeklyDay && weeklyTime) { const dm = {Sunday:0,Monday:1,Tuesday:2,Wednesday:3,Thursday:4,Friday:5,Saturday:6}; cronString = `${parseInt(document.getElementById('weekly_minute').value, 10)} ${parseInt(weeklyTime.value, 10)} * * ${dm[weeklyDay.value]}`; }
      else if (cronModeSelect.value === 'monthly' && monthlyDay && monthlyTime) cronString = `${parseInt(document.getElementById('monthly_minute').value, 10)} ${parseInt(monthlyTime.value, 10)} ${parseInt(monthlyDay.value, 10)} * *`;
      let hidden = document.getElementById('cron_expression_hidden');
      if (!hidden) { hidden = document.createElement('input'); hidden.type = 'hidden'; hidden.id = 'cron_expression_hidden'; hidden.name = 'CRON_EXPRESSION'; cronModeSelect.closest('.form-pair').appendChild(hidden); }
      hidden.value = cronString;
    }
    window._toggleCronOptions = toggleCronOptions;
    toggleCronOptions(cronModeSelect.value);
    $(cronModeSelect).on('change', (e) => toggleCronOptions(e.target.value));
    hourlyFreq?.addEventListener('change', updateCronExpression);
    dailyTime?.addEventListener('change', updateCronExpression);
    document.getElementById('daily_minute')?.addEventListener('change', updateCronExpression);
    weeklyDay?.addEventListener('change', updateCronExpression);
    weeklyTime?.addEventListener('change', updateCronExpression);
    document.getElementById('weekly_minute')?.addEventListener('change', updateCronExpression);
    monthlyDay?.addEventListener('change', updateCronExpression);
    monthlyTime?.addEventListener('change', updateCronExpression);
    document.getElementById('monthly_minute')?.addEventListener('change', updateCronExpression);
    return true;
  }
  if (!initSchedulingToggle()) {
    const obs = new MutationObserver(() => { if (initSchedulingToggle()) obs.disconnect(); });
    obs.observe(document.body, { childList:true, subtree:true });
  }
})();

(function waitForSchedulingToggleRemote() {
  function initSchedulingToggleRemote() {
    const cronModeSelect = document.getElementById('cron_mode_remote'); if (!cronModeSelect) return false;
    const hourlyOptions  = document.getElementById('hourly-options-remote');
    const dailyOptions   = document.getElementById('daily-options-remote');
    const weeklyOptions  = document.getElementById('weekly-options-remote');
    const monthlyOptions = document.getElementById('monthly-options-remote');
    const hourlyFreq     = document.getElementById('hourly_frequency_remote');
    const dailyTime      = document.getElementById('daily_time_remote');
    const weeklyDay      = document.getElementById('weekly_day_remote');
    const weeklyTime     = document.getElementById('weekly_time_remote');
    const monthlyDay     = document.getElementById('monthly_day_remote');
    const monthlyTime    = document.getElementById('monthly_time_remote');
    function toggleCronOptions(value) {
      hourlyOptions.style.display  = (value === 'hourly')  ? 'block' : 'none';
      dailyOptions.style.display   = (value === 'daily')   ? 'block' : 'none';
      weeklyOptions.style.display  = (value === 'weekly')  ? 'block' : 'none';
      monthlyOptions.style.display = (value === 'monthly') ? 'block' : 'none';
      updateCronExpressionRemote();
    }
    function updateCronExpressionRemote() {
      let cronString = '';
      if      (cronModeSelect.value === 'hourly'  && hourlyFreq)  cronString = `0 */${parseInt(hourlyFreq.value, 10)} * * *`;
      else if (cronModeSelect.value === 'daily'   && dailyTime)   cronString = `${parseInt(document.getElementById('daily_minute_remote').value, 10)} ${parseInt(dailyTime.value, 10)} * * *`;
      else if (cronModeSelect.value === 'weekly'  && weeklyDay && weeklyTime) { const dm = {Sunday:0,Monday:1,Tuesday:2,Wednesday:3,Thursday:4,Friday:5,Saturday:6}; cronString = `${parseInt(document.getElementById('weekly_minute_remote').value, 10)} ${parseInt(weeklyTime.value, 10)} * * ${dm[weeklyDay.value]}`; }
      else if (cronModeSelect.value === 'monthly' && monthlyDay && monthlyTime) cronString = `${parseInt(document.getElementById('monthly_minute_remote').value, 10)} ${parseInt(monthlyTime.value, 10)} ${parseInt(monthlyDay.value, 10)} * *`;
      let hidden = document.getElementById('cron_expression_hidden_remote');
      if (!hidden) { hidden = document.createElement('input'); hidden.type = 'hidden'; hidden.id = 'cron_expression_hidden_remote'; hidden.name = 'CRON_EXPRESSION_REMOTE'; cronModeSelect.closest('.form-pair').appendChild(hidden); }
      hidden.value = cronString;
    }
    window._toggleCronOptionsRemote = toggleCronOptions;
    toggleCronOptions(cronModeSelect.value);
    $(cronModeSelect).on('change', (e) => toggleCronOptions(e.target.value));
    hourlyFreq?.addEventListener('change', updateCronExpressionRemote);
    dailyTime?.addEventListener('change', updateCronExpressionRemote);
    document.getElementById('daily_minute_remote')?.addEventListener('change', updateCronExpressionRemote);
    weeklyDay?.addEventListener('change', updateCronExpressionRemote);
    weeklyTime?.addEventListener('change', updateCronExpressionRemote);
    document.getElementById('weekly_minute_remote')?.addEventListener('change', updateCronExpressionRemote);
    monthlyDay?.addEventListener('change', updateCronExpressionRemote);
    monthlyTime?.addEventListener('change', updateCronExpressionRemote);
    document.getElementById('monthly_minute_remote')?.addEventListener('change', updateCronExpressionRemote);
    return true;
  }
  if (!initSchedulingToggleRemote()) {
    const obs = new MutationObserver(() => { if (initSchedulingToggleRemote()) obs.disconnect(); });
    obs.observe(document.body, { childList:true, subtree:true });
  }
})();

// ── Activity log ──────────────────────────────────────────────────────────────
var logAutoScroll = false;   // start at top, not auto-scrolling
var logDebugMode  = false;
var _logRawSnapshot = '';

function applyLogSearch() {
  const logEl   = document.getElementById('last-run-log');
  const countEl = document.getElementById('log-search-count');
  const term    = (document.getElementById('log-search').value || '').trim();
  const raw     = logEl.dataset.raw || '';
  if (!term) {
    logEl.textContent = raw || 'Flash backup log not found';
    countEl.classList.remove('visible');
    return;
  }
  const escaped = term.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  const re      = new RegExp('(' + escaped + ')', 'gi');
  const parts   = raw.split(re);
  let matches   = 0;
  logEl.innerHTML = '';
  parts.forEach(function(part) {
    if (re.test(part)) {
      matches++;
      const mark = document.createElement('mark');
      mark.className   = 'log-highlight';
      mark.textContent = part;
      logEl.appendChild(mark);
      re.lastIndex = 0;
    } else {
      logEl.appendChild(document.createTextNode(part));
    }
  });
  countEl.textContent = matches + ' match' + (matches !== 1 ? 'es' : '');
  countEl.classList.toggle('visible', matches > 0);
}

function fbbSwitchLog(isDebug) {
  logDebugMode  = isDebug;
  logAutoScroll = false;          // return to top when toggling debug mode
  _logRawSnapshot = '';           // force a re-render
  const logEl = document.getElementById('last-run-log');
  logEl.scrollTop = 0;
  loadLastRunLog();
}

function loadLastRunLog() {
  fetch('/plugins/flash-backup/helpers/fetch_last_run_log.php?debug=' + (logDebugMode ? '1' : '0'))
    .then(res => res.text()).then(data => {
      const logEl   = document.getElementById('last-run-log');
      const emptyMsg = logDebugMode ? 'Flash backup debug log not found' : 'Flash backup log not found';
      // Reverse lines so newest content is at the top, matching automover behaviour
      const raw  = data ? data.split('\n').filter(l => l.trim()).reverse().join('\n') : '';
      const text = raw || emptyMsg;
      if (text === _logRawSnapshot) return;   // nothing changed
      _logRawSnapshot = text;
      logEl.dataset.raw = text;
      applyLogSearch();
      if (logAutoScroll) logEl.scrollTop = logEl.scrollHeight;
      // Extract timestamp for "Last Run" display
      const lines = data ? data.split('\n').filter(l => l.trim()) : [];
      let ts = null;
      for (let i = 0; i < lines.length; i++) {
        const m = lines[i].match(/\[?(\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2})/);
        if (m) { ts = new Date(m[1].replace(' ', 'T')); break; }
      }
      const el = document.getElementById('fbb-log-last-run');
      if (el) el.dataset.ts = ts ? ts.toISOString() : '';
    })
    .catch(() => {
      const logEl   = document.getElementById('last-run-log');
      const errMsg  = logDebugMode ? 'Error loading flash backup debug log' : 'Error loading flash backup log';
      if (errMsg !== _logRawSnapshot) {
        _logRawSnapshot   = errMsg;
        logEl.dataset.raw = errMsg;
        logEl.textContent = errMsg;
      }
    });
}
loadLastRunLog();
setInterval(loadLastRunLog, 1000);

// "Last run" relative time ticker
function fbbTimeAgo(date) {
  const sec = Math.floor((Date.now() - date.getTime()) / 1000);
  if (sec < 5)  return 'just now';
  if (sec < 60) return sec + 's ago';
  const min = Math.floor(sec / 60);
  if (min < 60) return min + 'm ago';
  const hr = Math.floor(min / 60);
  if (hr < 24)  return hr + 'h ago';
  return Math.floor(hr / 24) + 'd ago';
}
setInterval(function() {
  const el = document.getElementById('fbb-log-last-run');
  if (!el) return;
  const ts = el.dataset.ts;
  el.textContent = ts ? 'Last Run: ' + fbbTimeAgo(new Date(ts)) : 'No last run available';
}, 1000);

// Log search input wiring
const logSearchEl    = document.getElementById('log-search');
const logSearchClear = document.getElementById('log-search-clear');
function fbbUpdateSearchClear() { logSearchClear.style.display = logSearchEl.value ? 'flex' : 'none'; }
logSearchEl.addEventListener('input', function() { applyLogSearch(); fbbUpdateSearchClear(); });
logSearchClear.addEventListener('mousedown', function(e) {
  e.preventDefault();
  logSearchEl.value = '';
  applyLogSearch();
  fbbUpdateSearchClear();
  logSearchEl.focus();
});

// Scroll buttons
document.getElementById('log-autoscroll-btn').addEventListener('click', function() {
  logAutoScroll = !logAutoScroll;
  if (logAutoScroll) { const logEl = document.getElementById('last-run-log'); logEl.scrollTop = logEl.scrollHeight; }
});
document.getElementById('log-scroll-top-btn').addEventListener('click', function() {
  logAutoScroll = false;
  document.getElementById('last-run-log').scrollTop = 0;
});
document.getElementById('last-run-log').addEventListener('scroll', function() {
  if (this.scrollHeight - this.scrollTop - this.clientHeight < 8) return;
  logAutoScroll = false;
});

// Clear log button — uses custom dialog
const clearLastRunBtn = document.getElementById('clear-last-run-log');
if (clearLastRunBtn) {
  clearLastRunBtn.addEventListener('click', function() {
    const label = logDebugMode ? 'debug log' : 'flash backup log';
    fbbConfirm('Clear the ' + label + '?', function() {
      fetch('/plugins/flash-backup/helpers/clear_log.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-TOKEN': csrfToken },
        body:    'log=last&debug=' + (logDebugMode ? '1' : '0') + '&csrf_token=' + encodeURIComponent(csrfToken)
      })
        .then(r => r.json())
        .then(data => {
          if (data.ok) {
            const logEl = document.getElementById('last-run-log');
            logEl.dataset.raw = '';
            logEl.textContent = '';
            _logRawSnapshot   = '';
            document.getElementById('log-search-count').classList.remove('visible');
            showLogToast(logDebugMode ? 'Debug log cleared' : 'Log cleared');
          }
        })
        .catch(() => { fbbAlert('Failed to clear log'); });
    });
  });
}

// ── Backup owner dropdown ─────────────────────────────────────────────────────
// -- Select/picker wrap: identical solid triangle on all fields --------------
// Excluded: mode-row selects, webhook text inputs, log search input
function fbbWrapSelects() {
  // Mark mode-row selects so they are never touched
  document.querySelectorAll('#fbb-mode-row select').forEach(function(el) {
    el.classList.add('fbb-wrapped');
  });
  // Wrap all other <select> elements inside #fbb-page
  // Exclude: .vm-multiselect descendants, hidden selects, selects inside .fbb-field-wrap
  // (hidden selects beside .vm-multiselect divs would create a phantom ::after arrow)
  document.querySelectorAll('#fbb-page select:not(.fbb-wrapped)').forEach(function(sel) {
    if (sel.closest('.fbb-select-wrap')) return;
    if (sel.closest('.vm-multiselect')) return;
    if (sel.style.display === 'none' || sel.hasAttribute('multiple') && sel.style.display === 'none') return;
    if (getComputedStyle(sel).display === 'none') return;
    const wrap = document.createElement('div');
    wrap.className = 'fbb-select-wrap';
    sel.parentNode.insertBefore(wrap, sel);
    wrap.appendChild(sel);
    sel.classList.add('fbb-wrapped');
  });
  // Wrap picker text input (#backup_destination only)
  // #rclone_config_remote is a .vm-multiselect div — already has ::after via CSS
  const bdInp = document.getElementById('backup_destination');
  if (bdInp && !bdInp.closest('.fbb-select-wrap')) {
    const wrap = document.createElement('div');
    wrap.className = 'fbb-select-wrap';
    bdInp.parentNode.insertBefore(wrap, bdInp);
    wrap.appendChild(bdInp);
  }
}

$(document).ready(function() {
  const select   = $('#backup_owner');
  const selected = select.data('selected') || 'nobody';
  $.getJSON('/plugins/flash-backup/helpers/list_users_group100.php', function(data) {
    select.empty();
    data.users.forEach(user => {
      const opt = $('<option>', { value: user, text: user });
      if (user === selected) opt.prop('selected', true);
      select.append(opt);
    });
  });
});

// ── Inline help system ────────────────────────────────────────────────────────
$(document).ready(function() {
  let _fbbLastClick = 0;
  let _fbbF1Open    = false;

  function attachHelp($trigger, helpText, $insertAfter) {
    const helpId  = 'fbb-help-' + Math.random().toString(36).substr(2, 9);
    const $helpDiv = $('<div class="fbb-help-text" id="' + helpId + '" style="display:none;">' + helpText + '</div>');
    $insertAfter.after($helpDiv);
    $helpDiv.data('fbb-guard', $insertAfter);
    $trigger.addClass('fbb-has-help');
    $trigger.on('click', function() {
      _fbbLastClick = Date.now();
      const $help = $('#' + helpId);
      if ($help.is(':visible')) $help.slideUp(150); else $help.slideDown(150);
    });
  }
  window._fbbAttachHelp = attachHelp;

  function processHelpLabels($scope) {
    $scope.find('label > span[class*="betatip"][title]').each(function() {
      const $span    = $(this);
      const helpText = $span.attr('title');
      if (!helpText) return;
      $span.removeAttr('title').attr('class', '');
      const $label = $span.closest('label');
      if ($label.hasClass('fbb-has-help')) return;
      const $formPair     = $label.closest('.form-pair');
      const $insertAfter  = $formPair.length ? $formPair : $label;
      attachHelp($label, helpText, $insertAfter);
    });
  }
  window._fbbProcessLabels = processHelpLabels;
  processHelpLabels($(document));

  const $modeRow = $('#fbb-mode-row');
  if ($('#fbb-plugin-label').length) attachHelp($('#fbb-plugin-label'), 'Easily switch between your installed jcofer555 plugins', $modeRow);
  attachHelp($('#fbb-mode-label'), 'Switch between modes', $modeRow);
  attachHelp($('#fbb-debug-log-label'), 'Enable to view the debug log', $('#fbb-debug-log-label').closest('.fbb-log-toolbar'));

  $('span.status-label.flash-backuptip[title], span.remote-status-label.flash-backuptip[title]').each(function() {
    const $span    = $(this);
    const helpText = $span.attr('title');
    if (!helpText) return;
    $span.removeAttr('title').removeClass('flash-backuptip');
    const $statusRow   = $span.closest('.status-row, .remote-status-row');
    const $insertAfter = $statusRow.length ? $statusRow : $span;
    attachHelp($span, helpText, $insertAfter);
  });

  $('span.flash-backuptip').each(function() {
    if ($(this).children('button').length > 0) return;
    if ($(this).closest('table').length > 0) return;
    $(this).removeAttr('title').removeClass('flash-backuptip');
  });
  $('div.flash-backuptip').each(function() {
    if ($(this).closest('table').length > 0) return;
    $(this).removeAttr('title').removeClass('flash-backuptip');
  });

  $(document).on('keydown', function(e) {
    if (e.key !== 'F1') return;
    e.preventDefault();
    if (_fbbF1Open) { $('.fbb-help-text').slideUp(150); _fbbF1Open = false; }
    else { $('.fbb-help-text').each(function() { const $g = $(this).data('fbb-guard'); if ($g && !$g.is(':visible')) return; $(this).slideDown(150); }); _fbbF1Open = true; }
  });
  $(document).on('click', function() { if (Date.now() - _fbbLastClick < 150) return; $('.fbb-help-text').slideUp(150); _fbbF1Open = false; });
});

function closeTooltips() { $('.flash-backuptip').trigger('mouseleave'); }
$('select').on('click', closeTooltips);

// ── Cron valid / backup running state ────────────────────────────────────────
let cronValid = true, backupRunning = false;
let cronValidRemote = true, backupRunningRemote = false;
const backupbtn       = document.getElementById('backupbtn');
const backupbtnRemote = document.getElementById('backupbtn_remote');

function updateBackupButtonState()       { if (backupbtn)       backupbtn.disabled       = !(cronValid       && !backupRunning); }
function updateBackupButtonStateRemote() { if (backupbtnRemote) backupbtnRemote.disabled = !(cronValidRemote && !backupRunningRemote); }
updateBackupButtonState(); updateBackupButtonStateRemote();

// ── Banner helpers ────────────────────────────────────────────────────────────
let bannerStopTarget = null;
let prevAnyRunning   = false;

function showBanner(label, stopTarget) {
  bannerStopTarget = stopTarget;
  const which = (stopTarget === 'remote') ? 'remote' : 'local';
  $('#backup-banner-text-' + which).text(label);
  $('#stop-toast-banner-' + which).removeClass('visible');
  $('#backup-banner-' + which).show();
}
function hideBanner() {
  const which = (bannerStopTarget === 'remote') ? 'remote' : 'local';
  $('#backup-banner-' + which).hide();
  $('#stop-toast-banner-' + which).removeClass('visible');
  bannerStopTarget = null;
}
function stopFromBanner() {
  const url = bannerStopTarget === 'remote'
    ? '/plugins/flash-backup/helpers/stop_remote.php'
    : '/plugins/flash-backup/helpers/stop_backup.php';
  $.post(url, { csrf_token: csrfToken })
    .done(function() {
      const which = (bannerStopTarget === 'remote') ? 'remote' : 'local';
      $('#stop-toast-banner-' + which).addClass('visible');
      setTimeout(function() { $('#stop-toast-banner-' + which).removeClass('visible'); }, 3000);
    })
    .fail(function() { fbbAlert('Error sending stop request'); });
}

function setAllBackupButtonsDisabled(disabled) {
  $('#backupbtn, #backupbtn_remote').prop('disabled', disabled);
  document.querySelectorAll('.schedule-run-btn').forEach(b => { b.disabled = disabled; });
}
function updateBackupUI(running)       { backupRunning       = running; syncBannerState(); }
function updateBackupUIRemote(running) { backupRunningRemote = running; syncBannerState(); }

function syncBannerState() {
  const anyRunning = backupRunning || backupRunningRemote;
  if (anyRunning && !prevAnyRunning) {
    const target = bannerStopTarget || (backupRunning ? 'local' : 'remote');
    const label  = target === 'remote' ? '⚠ Remote backup in progress' : '⚠ Local backup in progress';
    showBanner(label, target);
    setAllBackupButtonsDisabled(true);
  } else if (!anyRunning && prevAnyRunning) {
    hideBanner();
    setAllBackupButtonsDisabled(false);
  }
  prevAnyRunning = anyRunning;
}

function pollBackupStatus()       { $.getJSON('/plugins/flash-backup/helpers/backup_status.php',  function(res) { updateBackupButtonState();       updateBackupUI(res.running === true); }); }
function pollBackupStatusRemote() { $.getJSON('/plugins/flash-backup/helpers/remote_status.php',  function(res) { updateBackupButtonStateRemote(); updateBackupUIRemote(res.running === true); }); }
$(document).ready(function() {
  pollBackupStatus(); pollBackupStatusRemote();
  setInterval(pollBackupStatus, 1000); setInterval(pollBackupStatusRemote, 1000);
});

// ── Backup now buttons ────────────────────────────────────────────────────────
let backupRequestInProgress = false, backupRequestInProgressRemote = false;

$('#backupbtn').on('click', async function() {
  if (backupRequestInProgress) return;
  const dest = $('#backup_destination').val().trim();
  if (!dest) { fbbAlert('Please select a backup destination'); return; }
  if ($('#notifications').val() === 'yes' && getSelectedServices('').includes('Pushover')) {
    if (!$('#pushover_user_key').val().trim()) { fbbAlert('Please enter your Pushover user key'); return; }
  }
  backupRequestInProgress = true;
  const webhookParams = {};
  $('#webhook-fields-container .webhook-url-input').each(function() {
    webhookParams['WEBHOOK_' + $(this).data('service').toUpperCase()] = $(this).val().trim();
  });
  $.post('/plugins/flash-backup/helpers/save_settings.php', {
    BACKUP_DESTINATION:   dest,
    BACKUP_OWNER:         $('#backup_owner').val(),
    BACKUPS_TO_KEEP:      $('#backups_to_keep').val(),
    DRY_RUN:              $('#dry_run').val(),
    MINIMAL_BACKUP:       $('#minimal_backup').is(':checked') ? 'yes' : 'no',
    NOTIFICATION_SERVICE: getSelectedServices('').join(','),
    NOTIFICATIONS:        $('#notifications').val(),
    PUSHOVER_USER_KEY:    $('#pushover_user_key').val() || '',
    WEBHOOK_DISCORD:      webhookParams['WEBHOOK_DISCORD']  || '',
    WEBHOOK_GOTIFY:       webhookParams['WEBHOOK_GOTIFY']   || '',
    WEBHOOK_NTFY:         webhookParams['WEBHOOK_NTFY']     || '',
    WEBHOOK_PUSHOVER:     webhookParams['WEBHOOK_PUSHOVER'] || '',
    WEBHOOK_SLACK:        webhookParams['WEBHOOK_SLACK']    || '',
    csrf_token: csrfToken
  })
    .done(function(res) { if (res && res.status === 'ok') startBackup(); else fbbAlert('Failed to save settings'); })
    .fail(function() { fbbAlert('Error saving settings'); })
    .always(function() { backupRequestInProgress = false; });
});

$('#backupbtn_remote').on('click', async function() {
  if (backupRequestInProgressRemote) return;
  const selectedRemotes = $('#rclone_config_remote_hidden').val() || [];
  if (!selectedRemotes.length) { fbbAlert('Please select at least one rclone config'); return; }
  const remotePath = $('#remote_path_in_config').val().trim();
  if (remotePath !== '' && !remotePath.startsWith('/')) { fbbAlert('Path In Config must start with a "/"'); return; }
  if (remotePath !== '' && !remotePath.endsWith('/'))   { fbbAlert('Path In Config must end with a "/"');   return; }
  if (remotePath !== '') {
    const inner = remotePath.replace(/^\/+|\/+$/g, '');
    const parts = inner.split('/');
    const validName = /^[A-Za-z0-9._+\-@ ]+$/;
    for (const p of parts) { if (!validName.test(p)) { fbbAlert('Invalid character in folder name: "' + p + '"'); return; } }
  }
  const bucketRemotes = selectedRemotes.filter(r => BUCKET_REMOTE_TYPES.includes(remoteTypes[r]));
  for (const remote of bucketRemotes) {
    const input     = $(`#bucket-fields-container .bucket-name-input[data-remote="${remote}"]`);
    const bucketVal = input.val().trim();
    if (!bucketVal) { fbbAlert(`Bucket name is required for remote "${remote}"`); input.focus(); return; }
    if (!/^[A-Za-z0-9._\-]+$/.test(bucketVal.replace(/\/+$/, ''))) { fbbAlert(`Invalid bucket name for "${remote}"\n\nAllowed characters:\nletters, numbers, - . _`); input.focus(); return; }
  }
  if ($('#notifications_remote').val() === 'yes' && getSelectedServices('remote').includes('Pushover')) {
    if (!$('#pushover_user_key_remote').val().trim()) { fbbAlert('Please enter your Pushover user key'); return; }
  }
  backupRequestInProgressRemote = true;
  const finalPath        = remotePath === '' ? '/Flash_Backups/' : remotePath;
  const bucketNamesMap   = collectBucketNames();
  const webhookParamsRem = {};
  $('#webhook-fields-container-remote .webhook-url-input').each(function() {
    webhookParamsRem['WEBHOOK_' + $(this).data('service').toUpperCase() + '_REMOTE'] = $(this).val().trim();
  });
  $.post('/plugins/flash-backup/helpers/save_settings_remote.php', {
    BUCKET_NAMES:                JSON.stringify(bucketNamesMap),
    BACKUPS_TO_KEEP_REMOTE:      $('#backups_to_keep_remote').val(),
    DRY_RUN_REMOTE:              $('#dry_run_remote').val(),
    MINIMAL_BACKUP_REMOTE:       $('#minimal_backup_remote').is(':checked') ? 'yes' : 'no',
    NOTIFICATION_SERVICE_REMOTE: getSelectedServices('remote').join(','),
    NOTIFICATIONS_REMOTE:        $('#notifications_remote').val(),
    PUSHOVER_USER_KEY_REMOTE:    $('#pushover_user_key_remote').val() || '',
    RCLONE_CONFIG_REMOTE:        selectedRemotes,
    REMOTE_PATH_IN_CONFIG:       finalPath,
    WEBHOOK_DISCORD_REMOTE:      webhookParamsRem['WEBHOOK_DISCORD_REMOTE']  || '',
    WEBHOOK_GOTIFY_REMOTE:       webhookParamsRem['WEBHOOK_GOTIFY_REMOTE']   || '',
    WEBHOOK_NTFY_REMOTE:         webhookParamsRem['WEBHOOK_NTFY_REMOTE']     || '',
    WEBHOOK_PUSHOVER_REMOTE:     webhookParamsRem['WEBHOOK_PUSHOVER_REMOTE'] || '',
    WEBHOOK_SLACK_REMOTE:        webhookParamsRem['WEBHOOK_SLACK_REMOTE']    || '',
    csrf_token: csrfToken
  })
    .done(function(res) { if (res && res.status === 'ok') startBackupRemote(); else fbbAlert('Failed to save remote settings'); })
    .fail(function() { fbbAlert('Error saving remote settings'); })
    .always(function() { backupRequestInProgressRemote = false; });
});

function startBackup() {
  bannerStopTarget = 'local';
  $.get('/plugins/flash-backup/helpers/backup.php', { csrf_token: csrfToken })
    .done(function(res) { if (res && res.status === 'ok') console.log('Backup started, PID:', res.pid); else { bannerStopTarget = null; fbbAlert(res.message || 'Failed to start backup'); } })
    .fail(function() { bannerStopTarget = null; fbbAlert('Error starting backup'); });
}
function startBackupRemote() {
  bannerStopTarget = 'remote';
  $.get('/plugins/flash-backup/helpers/remote_backup.php', { csrf_token: csrfToken })
    .done(function(res) { if (res && res.status === 'ok') console.log('Remote backup started, PID:', res.pid); else { bannerStopTarget = null; fbbAlert(res.message || 'Failed to start remote backup'); } })
    .fail(function() { bannerStopTarget = null; fbbAlert('Error starting remote backup'); });
}

// ── Cron conflict helpers ─────────────────────────────────────────────────────
function cronToMinutesOfWeek(expr) {
  const parts = expr.trim().split(/\s+/); if (parts.length !== 5) return [];
  const [min, hour, dom, month, dow] = parts; const minutes = []; const MINS_IN_WEEK = 7 * 24 * 60;
  const hInterval = hour.match(/^\*\/(\d+)$/);
  if (min === '0' && hInterval && dom === '*' && month === '*' && dow === '*') { const n = parseInt(hInterval[1], 10); for (let h = 0; h < 7 * 24; h += n) minutes.push(h * 60); return minutes; }
  const mMin = parseInt(min, 10);
  if (!isNaN(mMin) && /^\d+$/.test(hour) && dom === '*' && month === '*' && dow === '*') { const h = parseInt(hour, 10); for (let d = 0; d < 7; d++) minutes.push(d * 24 * 60 + h * 60 + mMin); return minutes; }
  if (!isNaN(mMin) && /^\d+$/.test(hour) && dom === '*' && month === '*' && /^\d+$/.test(dow)) { minutes.push(parseInt(dow, 10) * 24 * 60 + parseInt(hour, 10) * 60 + mMin); return minutes; }
  if (!isNaN(mMin) && /^\d+$/.test(hour) && /^\d+$/.test(dom) && month === '*' && dow === '*') { minutes.push(((parseInt(dom, 10) - 1) % 7) * 24 * 60 + parseInt(hour, 10) * 60 + mMin); return minutes; }
  return [];
}
function getHourlyInterval(expr) {
  const parts = expr.trim().split(/\s+/);
  if (parts.length === 5) { const m = parts[1].match(/^\*\/(\d+)$/); if (m && parts[0] === '0') return parseInt(m[1], 10) * 60; }
  return null;
}
function checkCronConflicts(newCron, existingCrons, excludeId) {
  const newTimes    = cronToMinutesOfWeek(newCron); if (!newTimes.length) return null;
  const newInterval = getHourlyInterval(newCron);
  const MINS_IN_WEEK = 7 * 24 * 60;
  for (const entry of existingCrons) {
    if (entry.id === excludeId) continue;
    const existingTimes    = cronToMinutesOfWeek(entry.cron);
    const existingInterval = getHourlyInterval(entry.cron);
    if (newInterval !== null && existingInterval !== null) {
      for (const nt of newTimes) { for (const et of existingTimes) { if (Math.min(Math.abs(nt - et), MINS_IN_WEEK - Math.abs(nt - et)) === 0) return entry.cron; } }
      continue;
    }
    const threshold = (newInterval !== null || existingInterval !== null) ? 30 : 15;
    for (const nt of newTimes) { for (const et of existingTimes) { if (Math.min(Math.abs(nt - et), MINS_IN_WEEK - Math.abs(nt - et)) < threshold) return entry.cron; } }
  }
  return null;
}
async function fetchExistingCrons() { return $.getJSON('/plugins/flash-backup/helpers/schedule_cron_check.php'); }

// ── Local schedule CRUD ───────────────────────────────────────────────────────
window.editingScheduleId = null;
function loadSchedules() {
  return $.get('/plugins/flash-backup/helpers/schedule_list.php', function(html) {
    $('#schedule-list').html(html);
    $('#fbb-sched-title-local').toggle($('#schedule-list .TableContainer').length > 0);
  }).always(() => unlockScheduleUI());
}
function buildCronFromUI() {
  const mode = $('#cron_mode').val();
  switch (mode) {
    case 'hourly':  return { valid:true, expression:`0 */${parseInt($('#hourly_frequency').val(), 10)} * * *` };
    case 'daily':   return { valid:true, expression:`${parseInt($('#daily_minute').val(), 10)} ${parseInt($('#daily_time').val(), 10)} * * *` };
    case 'weekly':  { const dm={Sunday:0,Monday:1,Tuesday:2,Wednesday:3,Thursday:4,Friday:5,Saturday:6}; return { valid:true, expression:`${parseInt($('#weekly_minute').val(), 10)} ${parseInt($('#weekly_time').val(), 10)} * * ${dm[$('#weekly_day').val()]}` }; }
    case 'monthly': return { valid:true, expression:`${parseInt($('#monthly_minute').val(), 10)} ${parseInt($('#monthly_time').val(), 10)} ${parseInt($('#monthly_day').val(), 10)} * *` };
    default: return { valid:false };
  }
}
async function scheduleJob(type) {
  if (!validateBackupPrereqs()) return;
  if (scheduleUILocked) return; lockScheduleUI();
  const cron = buildCronFromUI(); if (!cron.valid) { unlockScheduleUI(); fbbAlert('Invalid cron expression'); return; }
  const existingCrons = await fetchExistingCrons();
  const conflict = checkCronConflicts(cron.expression, existingCrons, window.editingScheduleId);
  if (conflict) { unlockScheduleUI(); fbbAlert('This schedule is within 15 minutes of an existing schedule (' + conflict + '). Please choose a different time.'); return; }
  const settings = {};
  $('input[name], select[name]').each(function() { if ($(this).is(':checkbox')) settings[this.name] = $(this).is(':checked') ? 'yes' : 'no'; else settings[this.name] = $(this).val(); });
  const url = window.editingScheduleId ? 'schedule_update.php' : 'schedule_create.php';
  $.ajax({
    type:'POST', url:`/plugins/flash-backup/helpers/${url}`, data:{ id:window.editingScheduleId, type, cron:cron.expression, settings },
    success: function() { const wasEdit = !!window.editingScheduleId; resetScheduleUI(); window.editingScheduleId = null; loadSchedules(); showPopup('Schedule saved!'); },
    error:   function(xhr) { unlockScheduleUI(); if (xhr.status === 409) fbbAlert('Duplicate schedule detected!'); else fbbAlert('Error creating/updating schedule: ' + xhr.responseText); }
  });
}
function editSchedule(id) {
  fbbSwitchMode('local'); $('#fbb-mode-switcher').val('local');
  if (scheduleUILocked) return; lockScheduleUI();
  $.getJSON('/plugins/flash-backup/helpers/schedule_load.php', { id }, function(s) {
    const settings = s.SETTINGS || {};
    if (s.TYPE) $('[name="type"]').val(s.TYPE).trigger('change');
    const _cmMode = detectCronMode(s.CRON); document.getElementById('cron_mode').value = _cmMode; if (window._toggleCronOptions) window._toggleCronOptions(_cmMode);
    for (const k in settings) { const el = $('[name="' + k + '"]'); if (!el.length) continue; if (el.is(':checkbox')) { const v = String(settings[k]).toLowerCase(); el.prop('checked', v === 'yes' || v === '1' || v === 'true'); } else if (el.is(':radio')) $('[name="' + k + '"][value="' + settings[k] + '"]').prop('checked', true); else el.val(settings[k]).trigger('change'); }
    window.editingScheduleId = id;
    $('#schedule-local-backup').text('Update');
    $('#cancelEditBtn').show(); unlockScheduleUI();
  });
}
$('#cancelEditBtn').on('click', function() { location.reload(); });
function showPopup(message) { const popup = $('#popupMessage'); popup.text(message).fadeIn(150); setTimeout(() => { popup.fadeOut(200, () => { popup.text(''); popup.hide(); }); }, 3000); }
function resetScheduleUI() { $('#schedule-local-backup').text('Schedule It'); $('#cancelEditBtn').hide(); $('#popupMessage').stop(true, true).hide().text(''); }
function deleteSchedule(id) {
  if (scheduleUILocked) return;
  fbbConfirm('Delete this schedule?', function() {
    lockScheduleUI();
    $.post('/plugins/flash-backup/helpers/schedule_delete.php', { id }).always(() => loadSchedules());
  });
}
function runScheduleBackup(id, btn) {
  fbbSwitchMode('local'); $('#fbb-mode-switcher').val('local');
  if (scheduleUILocked) return;
  fbbConfirm('Run this backup now?', function() {
    lockScheduleUI(); btn.disabled = true;
    $.post('/plugins/flash-backup/helpers/run_schedule.php', { id })
      .done(function(res) {
        if (!res.started) { fbbAlert('Failed to start backup'); btn.disabled = false; unlockScheduleUI(); return; }
        showBanner('⚠ Scheduled local backup in progress...', 'local');
        setAllBackupButtonsDisabled(true);
        const poll = setInterval(function() { $.getJSON('/plugins/flash-backup/helpers/check_lock.php', function(res) { if (!res.locked) { clearInterval(poll); btn.disabled = false; setAllBackupButtonsDisabled(false); unlockScheduleUI(); } }); }, 1000);
      })
      .fail(function(xhr, status, err) { fbbAlert('Failed to start backup: ' + (xhr.responseJSON?.error || err)); btn.disabled = false; unlockScheduleUI(); });
  });
}
function toggleSchedule(id, isEnabled) {
  if (scheduleUILocked) return;
  fbbConfirm(isEnabled ? 'Disable this schedule?' : 'Enable this schedule?', function() {
    lockScheduleUI();
    $.post('/plugins/flash-backup/helpers/schedule_toggle.php', { id }).always(() => loadSchedules());
  });
}
$(document).ready(function() {
  loadSchedules();
  $(document).on('click', '#schedule-local-backup', function() { scheduleJob('local-backup'); });
});

// ── Remote schedule CRUD ──────────────────────────────────────────────────────
window.editingScheduleIdremote = null;
function loadSchedulesremote() {
  return $.get('/plugins/flash-backup/helpers/schedule_list_remote.php', function(html) {
    $('#schedule-list-remote').html(html);
    if ($('#schedule-list-remote .TableContainer').length > 0) {
      const localVisible = $('#fbb-sched-title-local').is(':visible');
      $('#fbb-sched-title-remote').css('margin-top', localVisible ? '12px' : '0').show();
    } else { $('#fbb-sched-title-remote').hide(); }
  }).always(() => unlockScheduleUIremote());
}
function buildCronFromUIremote() {
  const mode = $('#cron_mode_remote').val();
  switch (mode) {
    case 'hourly':  return { valid:true, expression:`0 */${parseInt($('#hourly_frequency_remote').val(), 10)} * * *` };
    case 'daily':   return { valid:true, expression:`${parseInt($('#daily_minute_remote').val(), 10)} ${parseInt($('#daily_time_remote').val(), 10)} * * *` };
    case 'weekly':  { const dm={Sunday:0,Monday:1,Tuesday:2,Wednesday:3,Thursday:4,Friday:5,Saturday:6}; return { valid:true, expression:`${parseInt($('#weekly_minute_remote').val(), 10)} ${parseInt($('#weekly_time_remote').val(), 10)} * * ${dm[$('#weekly_day_remote').val()]}` }; }
    case 'monthly': return { valid:true, expression:`${parseInt($('#monthly_minute_remote').val(), 10)} ${parseInt($('#monthly_time_remote').val(), 10)} ${parseInt($('#monthly_day_remote').val(), 10)} * *` };
    default: return { valid:false };
  }
}
async function scheduleJobremote(type) {
  if (!validateBackupPrereqsremote()) return;
  if (scheduleUILockedremote) return; lockScheduleUIremote();
  const cron = buildCronFromUIremote(); if (!cron.valid) { unlockScheduleUIremote(); fbbAlert('Invalid cron expression'); return; }
  const existingCrons = await fetchExistingCrons();
  const conflict = checkCronConflicts(cron.expression, existingCrons, window.editingScheduleIdremote);
  if (conflict) { unlockScheduleUIremote(); fbbAlert('This remote schedule conflicts with an existing schedule (' + conflict + '). Please choose a different time.'); return; }
  const settings = {};
  $('input[name], select[name]').each(function() { const key = this.name.replace(/\[\]$/, ''); if ($(this).is(':checkbox')) settings[key] = $(this).is(':checked') ? 'yes' : 'no'; else { const val = $(this).val(); settings[key] = Array.isArray(val) ? val.join(',') : val; } });
  const url = window.editingScheduleIdremote ? 'schedule_update_remote.php' : 'schedule_create_remote.php';
  $.ajax({
    type:'POST', url:`/plugins/flash-backup/helpers/${url}`, data:{ id:window.editingScheduleIdremote, type, cron:cron.expression, settings },
    success: function() { resetScheduleUIremote(); window.editingScheduleIdremote = null; loadSchedulesremote(); showPopupremote('Schedule saved!'); },
    error:   function(xhr) { unlockScheduleUIremote(); if (xhr.status === 409) fbbAlert('Duplicate remote schedule!'); else fbbAlert('Error creating/updating remote schedule: ' + xhr.responseText); }
  });
}
function editScheduleremote(id) {
  fbbSwitchMode('remote'); $('#fbb-mode-switcher').val('remote');
  if (scheduleUILockedremote) return; lockScheduleUIremote();
  $.getJSON('/plugins/flash-backup/helpers/schedule_load_remote.php', { id }, function(s) {
    const settings = s.SETTINGS || {};
    if (s.TYPE) $('[name="type_remote"]').val(s.TYPE).trigger('change');
    const _cmrMode = detectCronMode(s.CRON); document.getElementById('cron_mode_remote').value = _cmrMode; if (window._toggleCronOptionsRemote) window._toggleCronOptionsRemote(_cmrMode);
    for (const k in settings) { const el = $('[name="' + k + '"]'); if (!el.length) continue; if (el.is(':checkbox')) { const v = String(settings[k]).toLowerCase(); el.prop('checked', v === 'yes' || v === '1' || v === 'true'); } else if (el.is(':radio')) $('[name="' + k + '"][value="' + settings[k] + '"]').prop('checked', true); else el.val(settings[k]).trigger('change'); }
    if (settings.RCLONE_CONFIG_REMOTE !== undefined) {
      const vals = String(settings.RCLONE_CONFIG_REMOTE).split(',').map(v => v.trim()).filter(Boolean);
      $('#rclone-config-list-remote input[type=checkbox]').each(function() { $(this).prop('checked', vals.includes($(this).val())); });
      $('#rclone-config-label-remote').text(vals.length ? vals.join(', ') : 'Select config(s)');
      $('#rclone_config_remote_hidden option').each(function() { $(this).prop('selected', vals.includes($(this).val())); });
      updateBucketVisibility();
      let savedBuckets = {};
      try { const b64 = settings.BUCKET_NAMES || ''; if (b64) savedBuckets = JSON.parse(atob(b64)) || {}; } catch(e) {}
      const legacyBucket = settings.B2_BUCKET_NAME || '';
      $('#bucket-fields-container .bucket-name-input').each(function() {
        const remote = $(this).data('remote');
        let val = savedBuckets[remote] || '';
        if (!val && legacyBucket) val = legacyBucket;
        if (val) $(this).val(val);
      });
    }
    window.editingScheduleIdremote = id;
    $('#schedule-remote-backup').text('Update');
    $('#cancelEditBtnremote').show(); unlockScheduleUIremote();
  });
}
$('#cancelEditBtnremote').on('click', function() { location.reload(); });
function showPopupremote(message) { const popup = $('#popupMessageremote'); popup.text(message).fadeIn(150); setTimeout(() => { popup.fadeOut(200, () => { popup.text(''); popup.hide(); }); }, 3000); }
function resetScheduleUIremote() { $('#schedule-remote-backup').text('Schedule It'); $('#cancelEditBtnremote').hide(); $('#popupMessageremote').stop(true, true).hide().text(''); }
function deleteScheduleremote(id) {
  if (scheduleUILockedremote) return;
  fbbConfirm('Delete this remote schedule?', function() {
    lockScheduleUIremote();
    $.post('/plugins/flash-backup/helpers/schedule_delete_remote.php', { id }).always(() => loadSchedulesremote());
  });
}
function runScheduleBackupremote(id, btn) {
  fbbSwitchMode('remote'); $('#fbb-mode-switcher').val('remote');
  if (scheduleUILockedremote) return;
  fbbConfirm('Run this remote backup now?', function() {
    lockScheduleUIremote(); btn.disabled = true;
    $.post('/plugins/flash-backup/helpers/run_schedule_remote.php', { id })
      .done(function(res) {
        if (!res.started) { fbbAlert('Failed to start remote backup'); btn.disabled = false; unlockScheduleUIremote(); return; }
        showBanner('⚠ Scheduled remote backup in progress...', 'remote');
        setAllBackupButtonsDisabled(true);
        const poll = setInterval(function() { $.getJSON('/plugins/flash-backup/helpers/check_lock.php', function(res) { if (!res.locked) { clearInterval(poll); btn.disabled = false; setAllBackupButtonsDisabled(false); unlockScheduleUIremote(); } }); }, 1000);
      })
      .fail(function(xhr, status, err) { fbbAlert('Failed to start remote backup: ' + (xhr.responseJSON?.error || err)); btn.disabled = false; unlockScheduleUIremote(); });
  });
}
function toggleScheduleremote(id, isEnabled) {
  if (scheduleUILockedremote) return;
  fbbConfirm(isEnabled ? 'Disable this remote schedule?' : 'Enable this remote schedule?', function() {
    lockScheduleUIremote();
    $.post('/plugins/flash-backup/helpers/schedule_toggle_remote.php', { id }).always(() => loadSchedulesremote());
  });
}
$(document).ready(function() {
  loadSchedulesremote();
  $(document).on('click', '#schedule-remote-backup', function() { scheduleJobremote('remote-backup'); });
});

// ── Cron mode detection ───────────────────────────────────────────────────────
function detectCronMode(cron) {
  if (!cron) return 'daily';
  if (/^0 \*\/(4|6|8) \* \* \*$/.test(cron)) return 'hourly';
  if (/^\d+ \d+ \* \* \*$/.test(cron))        return 'daily';
  if (/^\d+ \d+ \* \* [0-6]$/.test(cron))     return 'weekly';
  if (/^\d+ \d+ \d+ \* \*$/.test(cron))       return 'monthly';
  return 'daily';
}

// ── CA plugin update check ────────────────────────────────────────────────────
if (typeof caPluginUpdateCheck === 'function') {
  caPluginUpdateCheck('flash-backup.plg', { name: 'flash-backup' });
  fbbWrapSelects();
}

// ── Folder picker ─────────────────────────────────────────────────────────────
let currentPath = '/mnt';
let selectedFolders = [], accumulatedFolders = [], persistentFolders = [];
let activeInputFieldId = null;

function loadFolders(path) {
  // Hide the create-folder bar when navigating
  document.getElementById('fbb-create-folder-bar').style.display = 'none';
  document.getElementById('newFolderName').value = '';
  $.getJSON('/plugins/flash-backup/helpers/list_folders.php', { path, field: activeInputFieldId }, function(data) {
    currentPath = data.current; 
    const parts = currentPath.split('/').filter(p => p !== '');
    let breadcrumbHTML = '', buildPath = '';
    parts.forEach((part, index) => {
      buildPath += '/' + part;
      breadcrumbHTML += `<span class="breadcrumb-part" data-path="${buildPath}" style="cursor:pointer;">${part}</span>`;
      if (index < parts.length - 1) breadcrumbHTML += ' / ';
    });
    $('#folderBreadcrumb').html(breadcrumbHTML);
    let html = '';
    if (data.parent) html += `<div class="vm-folder-item browse-row" data-path="${data.parent}" style="cursor:pointer;display:flex;align-items:center;">.. Up Directory</div>`;
    data.folders.forEach(folder => {
      const isChecked    = persistentFolders.includes(folder.path) ? 'checked' : '';
      const disabledAttr = folder.selectable ? '' : 'disabled';
      html += `<div class="vm-folder-item browse-row" data-path="${folder.path}" style="display:flex;align-items:center;gap:0px;">
        <label class="folder-check-label" style="display:flex;align-items:center;cursor:pointer;padding:9px 2px 4px 4px;"><input type="checkbox" class="folder-checkbox" value="${folder.path}" ${isChecked} ${disabledAttr}></label>
        <span class="folder-name-label" style="flex:1;cursor:pointer;">${folder.name}</span>
      </div>`;
    });
    $('#folderList').html(html);
    $('.breadcrumb-part').off('click').on('click', function() { loadFolders($(this).data('path')); });
    $('.browse-row').off('click').on('click', function(e) {
      if ($(e.target).closest('.folder-check-label').length || $(e.target).closest('.folder-name-label').length) return;
      loadFolders($(this).data('path'));
    });
    $('.folder-name-label').off('click').on('click', function() { loadFolders($(this).closest('.browse-row').data('path')); });
    $('.folder-check-label').off('click').on('click', function(e) { e.stopPropagation(); });
    $('.folder-checkbox').off('change').on('change', function(e) {
      if (this.disabled) return;
      const path = $(this).val();
      if (this.checked) { if (!persistentFolders.includes(path)) { persistentFolders.push(path); showFolderToast('Folder selected'); } }
      else { persistentFolders = persistentFolders.filter(p => p !== path); showFolderToast('Removed'); }
      e.stopPropagation();
    });
    $('#clearSelectedFolders').off('click').on('click', function() {
      persistentFolders = []; selectedFolders = []; accumulatedFolders = [];
      $('.folder-checkbox').prop('checked', false); showFolderToast('Selections cleared');
    });
  });
}

$('input[data-picker-title]').on('click', function() {
  activeInputFieldId = $(this).attr('id');
  const existing = $(this).val().trim();
  persistentFolders = existing.length > 0 ? existing.split(',').map(s => s.trim()) : [];
  selectedFolders = []; accumulatedFolders = [];
  $('#folderPickerTitle').text($(this).data('picker-title'));
  $('#folderPickerModal').css('display', 'flex').css('align-items', 'center').css('justify-content', 'center');
  const savedPath = $(this).val();
  loadFolders(savedPath && savedPath.startsWith('/mnt') ? savedPath : '/mnt');
});
$('#closeFolderPicker').on('click', function() {
  document.getElementById('fbb-create-folder-bar').style.display = 'none';
  document.getElementById('newFolderName').value = '';
  $('#folderPickerModal').hide();
});
$('#confirmFolderSelection').off('click').on('click', function(e) {
  e.preventDefault();
  if (!activeInputFieldId) return;
  $('#' + activeInputFieldId).val(persistentFolders.join(',')).trigger('change');
  $('#folderPickerModal').hide();
});
$('#createFolderBtn').on('click', function() {
  const bar = document.getElementById('fbb-create-folder-bar');
  bar.style.display = 'flex';
  document.getElementById('newFolderName').value = '';
  document.getElementById('newFolderName').focus();
});
$('#newFolderCancel').on('click', function() {
  document.getElementById('fbb-create-folder-bar').style.display = 'none';
  document.getElementById('newFolderName').value = '';
});
$('#newFolderOk').on('click', function() {
  const name = document.getElementById('newFolderName').value.trim();
  if (!name) return;
  $.post('/plugins/flash-backup/helpers/create_folder.php', { path: currentPath, name, csrf_token: csrfToken }, function(res) {
    if (res.success) {
      document.getElementById('fbb-create-folder-bar').style.display = 'none';
      document.getElementById('newFolderName').value = '';
      showFolderToast('✅ Folder created');
      loadFolders(currentPath);
    } else fbbAlert(res.error || 'Failed to create folder');
  }, 'json');
});
document.getElementById('newFolderName')?.addEventListener('keydown', function(e) {
  if (e.key === 'Enter')  document.getElementById('newFolderOk').click();
  if (e.key === 'Escape') document.getElementById('newFolderCancel').click();
});

// ── Remote path normalizer ────────────────────────────────────────────────────
let remotePathTimer = null;
$('#remote_path_in_config').on('input', function() {
  let val = $(this).val(); if (val.trim() === '') { clearTimeout(remotePathTimer); return; }
  val = val.replace(/\/+/g, '/'); if (!val.startsWith('/')) val = '/' + val; $(this).val(val);
  clearTimeout(remotePathTimer);
  remotePathTimer = setTimeout(() => { let v = $('#remote_path_in_config').val().trim(); if (v && !v.endsWith('/')) $('#remote_path_in_config').val(v + '/'); }, 2000);
});
$('#remote_path_in_config').on('blur', function() {
  let val = $(this).val().trim(); if (!val) return;
  val = val.replace(/\/+/g, '/'); if (!val.startsWith('/')) val = '/' + val; if (!val.endsWith('/')) val += '/'; $(this).val(val);
});
$(function() { let val = $('#remote_path_in_config').val().trim(); if (!val) return; val = val.replace(/\/+/g, '/'); if (!val.startsWith('/')) val = '/' + val; if (!val.endsWith('/')) val += '/'; $('#remote_path_in_config').val(val); });

// ── Copy log button ───────────────────────────────────────────────────────────
document.getElementById('copy-last-run-log').addEventListener('click', function() {
  const logEl = document.getElementById('last-run-log');
  const text  = logEl.dataset.raw || logEl.textContent || '';
  if (!text.trim() || text.includes('Flash backup log not found')) return;
  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(text).then(() => showCopiedFeedback(this)).catch(() => { fallbackCopyText(text, this); });
  } else { fallbackCopyText(text, this); }
});
function fallbackCopyText(text, btn) {
  const textarea = document.createElement('textarea'); textarea.value = text; document.body.appendChild(textarea); textarea.select();
  try { document.execCommand('copy'); showCopiedFeedback(btn); } catch(err) { fbbAlert('Failed to copy log'); }
  document.body.removeChild(textarea);
}
function showLogToast(message) {
  const toast = document.getElementById('log-toast');
  if (!toast) return;
  toast.textContent = message;
  toast.classList.add('visible');
  clearTimeout(toast._hideTimer);
  toast._hideTimer = setTimeout(() => { toast.classList.remove('visible'); }, 2000);
}
function showCopiedFeedback(btn) { showLogToast(logDebugMode ? 'Debug log copied' : 'Log copied'); }

// ── Lock poller for run buttons ───────────────────────────────────────────────
(function() {
  const CHECK_INTERVAL = 1000;
  function updateRunButtons(locked) {
    document.querySelectorAll('.run-schedule-btn').forEach(btn => {
      btn.disabled = locked;
      if (locked) btn.classList.add('disabled'); else btn.classList.remove('disabled');
    });
  }
  async function pollLock() {
    try { const res = await fetch('/plugins/flash-backup/helpers/check_lock.php'); const data = await res.json(); updateRunButtons(Boolean(data.locked)); } catch(e) {}
  }
  pollLock(); setInterval(pollLock, CHECK_INTERVAL);
})();

// ── Debounce helper ───────────────────────────────────────────────────────────
function debounceButton(btn, delay) {
  delay = delay || 1000;
  let cooling = false;
  btn.addEventListener('click', function() { if (cooling) return; cooling = true; setTimeout(() => cooling = false, delay); });
}
['backupbtn','restorebtn','schedule-backup','cancelEditBtn','clear-last-run-log','copy-last-run-log',
 'confirmFolderSelection','closeFolderPicker','backupbtn_remote','schedule-local-backup',
 'schedule-remote-backup','cancelEditBtnremote','clearSelectedFolders'
].forEach(id => { const btn = document.getElementById(id); if (btn) debounceButton(btn); });