const state = {
  csrf: '',
  me: null,
  groups: [],
  phones: [],
  links: [],
  media: { type: '', url: '' }
};

const $ = (id) => document.getElementById(id);
const qsa = (sel) => Array.from(document.querySelectorAll(sel));

function toast(text) {
  const box = $('toast');
  box.textContent = text;
  box.classList.add('show');
  setTimeout(() => box.classList.remove('show'), 2200);
}

async function api(path, options = {}) {
  const init = { ...options, headers: { ...(options.headers || {}) } };
  if (init.method && init.method.toUpperCase() === 'POST') {
    init.headers['X-CSRF-Token'] = state.csrf;
  }
  const res = await fetch(path, init);
  const type = res.headers.get('content-type') || '';
  if (type.includes('application/json')) {
    const data = await res.json();
    if (data.csrf) state.csrf = data.csrf;
    if (data.code === 401) location.href = '/admin/login';
    return data;
  }
  return { code: res.ok ? 1 : 0, text: await res.text() };
}

function fd(obj) {
  const data = new FormData();
  for (const [key, value] of Object.entries(obj)) {
    data.append(key, Array.isArray(value) ? JSON.stringify(value) : value);
  }
  return data;
}

function selected(name) {
  return qsa(`input[name="${name}"]:checked`).map((item) => item.value);
}

function selectedGroup(selectId) {
  const id = parseInt($(selectId).value || '0', 10);
  if (!id) toast('请先选择分组');
  return id;
}

function openModal(title, fields, onSubmit) {
  const form = $('modalForm');
  form.innerHTML = `<h2>${title}</h2>` + fields.map((f) => {
    if (f.type === 'textarea') return `<label>${f.label}<textarea name="${f.name}" ${f.required ? 'required' : ''}>${f.value || ''}</textarea></label>`;
    if (f.type === 'select') return `<label>${f.label}<select name="${f.name}">${f.options}</select></label>`;
    return `<label>${f.label}<input name="${f.name}" type="${f.type || 'text'}" value="${f.value || ''}" ${f.min ? `min="${f.min}"` : ''} ${f.required ? 'required' : ''}></label>`;
  }).join('') + '<div class="toolbar"><button class="primary" value="submit">确定</button><button value="cancel">取消</button></div>';
  form.onsubmit = async (event) => {
    event.preventDefault();
    await onSubmit(Object.fromEntries(new FormData(form).entries()));
    $('modal').close();
  };
  $('modal').showModal();
}

function groupOptions(selectedId = '') {
  return '<option value="">选择分组</option>' + state.groups.map((g) => `<option value="${g.id}" ${String(g.id) === String(selectedId) ? 'selected' : ''}>${g.name}</option>`).join('');
}

async function boot() {
  const me = await api('/admin/me');
  if (me.code !== 1) return;
  state.me = me.data;
  state.csrf = me.csrf;
  $('accountLine').textContent = `当前账号：${state.me.username}${state.me.switched_by_super ? `，由 ${state.me.original_username} 切换` : ''}`;
  $('returnSuperBtn').classList.toggle('hidden', !state.me.switched_by_super);
  qsa('.super-only').forEach((item) => item.classList.toggle('hidden', !state.me.is_super));
  bindTabs();
  bindActions();
  await loadGroups();
  if (state.me.is_super) await loadAdmins();
  await loadPhones();
  await loadLinks();
}

function bindTabs() {
  qsa('.nav').forEach((btn) => btn.addEventListener('click', () => {
    qsa('.nav').forEach((item) => item.classList.remove('active'));
    qsa('.tab').forEach((item) => item.classList.remove('active'));
    btn.classList.add('active');
    $(btn.dataset.tab).classList.add('active');
    $('pageTitle').textContent = btn.textContent;
  }));
}

function bindActions() {
  $('returnSuperBtn').onclick = async () => {
    const data = await api('/admin/returnToSuper', { method: 'POST', body: fd({}) });
    toast(data.msg || '完成');
    if (data.code === 1) location.reload();
  };
  $('newGroupBtn').onclick = () => openModal('新建分组', [
    { label: '名称', name: 'name', required: true },
    { label: '备注', name: 'remark', type: 'textarea' }
  ], async (data) => { await api('/group/create', { method: 'POST', body: fd(data) }); await loadGroups(); });
  $('addPhoneBtn').onclick = () => openModal('添加号码', [
    { label: '分组', name: 'group_id', type: 'select', options: groupOptions($('phoneGroup').value), required: true },
    { label: '手机号', name: 'phone', required: true },
    { label: 'API 地址', name: 'api_url', required: true },
    { label: '最大使用次数', name: 'max_uses', type: 'number', value: '1', min: '1' }
  ], async (data) => { await api('/group/addPhone', { method: 'POST', body: fd(data) }); await loadPhones(); });
  $('batchPhoneBtn').onclick = () => openModal('批量导入号码', [
    { label: '分组', name: 'group_id', type: 'select', options: groupOptions($('phoneGroup').value), required: true },
    { label: '每行格式：手机号----API地址', name: 'phones', type: 'textarea', required: true },
    { label: '最大使用次数', name: 'max_uses', type: 'number', value: '1', min: '1' }
  ], async (data) => { await api('/group/batchAddPhone', { method: 'POST', body: fd(data) }); await loadPhones(); });
  $('loadPhonesBtn').onclick = loadPhones;
  $('loadLinksBtn').onclick = loadLinks;
  $('exportPhonesBtn').onclick = () => { const id = selectedGroup('phoneGroup'); if (id) location.href = `/group/exportPhones?group_id=${id}`; };
  $('exportLinksBtn').onclick = () => { const id = selectedGroup('linkGroup'); if (id) location.href = `/group/exportLinks?id=${id}`; };
  $('exportValidLinksBtn').onclick = () => { const id = selectedGroup('linkGroup'); if (id) location.href = `/group/exportValidLinks?id=${id}`; };
  $('checkAllPhones').onchange = (e) => qsa('input[name="phone_ids"]').forEach((c) => c.checked = e.target.checked);
  $('checkAllLinks').onchange = (e) => qsa('input[name="link_ids"]').forEach((c) => c.checked = e.target.checked);
  $('batchResetPhonesBtn').onclick = () => batchPhone('/group/batchResetPhoneUsage');
  $('batchDeletePhonesBtn').onclick = () => batchPhone('/group/batchDeletePhone');
  $('batchDisablePhonesBtn').onclick = () => batchPhone('/group/batchTogglePhoneStatus', { status: 0 });
  $('genLinksBtn').onclick = () => openModal('批量生成链接', [
    { label: '分组', name: 'group_id', type: 'select', options: groupOptions($('linkGroup').value), required: true },
    { label: '数量', name: 'count', type: 'number', value: '10', min: '1' },
    { label: '有效期分钟', name: 'expire_minutes', type: 'number', value: '15', min: '5' },
    { label: '界面', name: 'interface_type', type: 'select', options: '<option>A</option><option>B</option><option>C</option>' }
  ], async (data) => { await api('/group/generateLinks', { method: 'POST', body: fd(data) }); await loadLinks(); });
  $('genByPhonesBtn').onclick = () => {
    const ids = selected('phone_ids');
    if (!ids.length) { toast('请先在号码池选择号码'); return; }
    openModal('按号码生成链接', [
      { label: '分组', name: 'group_id', type: 'select', options: groupOptions($('phoneGroup').value || $('linkGroup').value), required: true },
      { label: '每个号码生成数量', name: 'count_per_phone', type: 'number', value: '1', min: '1' },
      { label: '有效期分钟', name: 'expire_minutes', type: 'number', value: '15', min: '5' },
      { label: '界面', name: 'interface_type', type: 'select', options: '<option>A</option><option>B</option><option>C</option>' }
    ], async (data) => { data.phone_ids = ids; await api('/group/generateLinksByPhones', { method: 'POST', body: fd(data) }); await loadLinks(); });
  };
  $('batchResetLinksBtn').onclick = () => batchLinks('/group/batchResetLinks');
  $('batchDeleteLinksBtn').onclick = () => batchLinks('/group/batchDeleteLink');
  $('deleteByCodesBtn').onclick = deleteByCodes;
  $('recycleBtn').onclick = () => postCodes('/group/recycleLinks', 'recycleCodes');
  $('disableByCodesBtn').onclick = () => postCodes('/group/batchDisablePhonesByLinks', 'disableByCodes');
  $('instructionGroup').onchange = loadInstruction;
  $('saveInstructionBtn').onclick = saveInstruction;
  $('imageUpload').onchange = () => upload('imageUpload', '/upload/image');
  $('videoUpload').onchange = () => upload('videoUpload', '/upload/video');
  $('scheduleGroup').onchange = loadSchedule;
  $('saveScheduleBtn').onclick = saveSchedule;
  $('manualResetBtn').onclick = () => manualSchedule('/group/manualResetPhoneUsage');
  $('manualDeleteBtn').onclick = () => manualSchedule('/group/manualDeleteExpirePhones');
  $('newAdminBtn').onclick = () => openModal('新建管理员', [
    { label: '用户名', name: 'username', required: true },
    { label: '密码', name: 'password', type: 'password', required: true }
  ], async (data) => { await api('/admin/create', { method: 'POST', body: fd(data) }); await loadAdmins(); });
}

async function loadGroups() {
  const data = await api('/group/list');
  if (data.code !== 1) return;
  state.groups = data.data || [];
  $('groupRows').innerHTML = state.groups.map((g) => `<tr><td>${g.id}</td><td>${g.name}</td><td>${g.remark || ''}</td><td>${g.created_at || ''}</td><td class="toolbar"><button onclick="editGroup(${g.id})">编辑</button><button class="danger" onclick="deleteGroup(${g.id})">删除</button></td></tr>`).join('');
  ['phoneGroup', 'linkGroup', 'instructionGroup', 'scheduleGroup'].forEach((id) => { const old = $(id).value; $(id).innerHTML = groupOptions(old); });
}

async function loadAdmins() {
  const data = await api('/admin/list');
  if (data.code !== 1) return;
  $('adminRows').innerHTML = (data.data || []).map((a) => `<tr><td>${a.id}</td><td>${a.username}</td><td>${badge(a.status == 1)}</td><td>${a.created_at || ''}</td><td class="toolbar"><button onclick="switchAdmin(${a.id})">切换</button><button onclick="toggleAdmin(${a.id},${a.status == 1 ? 0 : 1})">${a.status == 1 ? '禁用' : '启用'}</button><button onclick="changeAdminPassword(${a.id})">改密</button><button class="danger" onclick="deleteAdmin(${a.id})">删除</button></td></tr>`).join('');
}

async function loadPhones() {
  const params = new URLSearchParams({ id: $('phoneGroup').value || 'all', keyword: $('phoneKeyword').value || '', pageSize: 500 });
  const data = await api('/group/phones?' + params);
  state.phones = data.data?.data || [];
  $('phoneRows').innerHTML = state.phones.map((p) => `<tr><td><input type="checkbox" name="phone_ids" value="${p.id}"></td><td>${p.id}</td><td>${p.phone}</td><td>${maskToken(p.api_url)}</td><td>${p.used_count}/${p.max_uses}</td><td>${badge(p.status == 1)}</td><td class="toolbar"><button onclick="togglePhone(${p.id})">${p.status == 1 ? '禁用' : '启用'}</button><button onclick="resetPhone(${p.id})">重置</button><button class="danger" onclick="deletePhone(${p.id})">删除</button></td></tr>`).join('');
}

async function loadLinks() {
  const groupId = $('linkGroup').value || state.groups[0]?.id || '';
  if (!groupId) return;
  $('linkGroup').value = groupId;
  const params = new URLSearchParams({ id: groupId, searchPhone: $('linkPhoneSearch').value || '', pageSize: 500 });
  const data = await api('/group/links?' + params);
  state.links = data.data?.data || [];
  $('linkRows').innerHTML = state.links.map((l) => {
    const url = `${location.origin}/link/${l.link_code}`;
    return `<tr><td><input type="checkbox" name="link_ids" value="${l.id}"></td><td>${l.id}</td><td><a href="${url}" target="_blank">${l.link_code}</a></td><td>${l.phone || '未绑定'}</td><td>${l.verify_code || ''}</td><td>${badge(l.status == 1 && !l.verify_code)}</td><td>${l.interface_type}</td><td class="toolbar"><button onclick="copyText('${url}')">复制</button><button class="danger" onclick="deleteLink(${l.id})">删除</button></td></tr>`;
  }).join('');
}

function badge(on) { return `<span class="badge ${on ? 'ok' : 'off'}">${on ? '启用' : '停用'}</span>`; }
function maskToken(url) { return String(url || '').replace(/(token=)[^&]{8,}/i, (_, p) => p + '****'); }
function lines(id) { return $(id).value.split(/\r?\n/).map((s) => s.trim()).filter(Boolean); }
function linkCodes(id) {
  return lines(id).map((item) => {
    const match = item.match(/\/link\/([A-Za-z0-9_-]+)/);
    return match ? match[1] : item;
  });
}
function copyText(text) { navigator.clipboard?.writeText(text); toast('已复制'); }

window.editGroup = (id) => {
  const g = state.groups.find((item) => Number(item.id) === Number(id));
  openModal('编辑分组', [
    { label: '名称', name: 'name', value: g.name, required: true },
    { label: '备注', name: 'remark', type: 'textarea', value: g.remark || '' }
  ], async (data) => { data.id = id; await api('/group/update', { method: 'POST', body: fd(data) }); await loadGroups(); });
};
window.deleteGroup = async (id) => { if (confirm('确认删除分组及其数据？')) { await api(`/group/delete/${id}`, { method: 'POST', body: fd({}) }); await loadGroups(); } };
window.deletePhone = async (id) => { await api('/group/deletePhone', { method: 'POST', body: fd({ id }) }); await loadPhones(); };
window.togglePhone = async (id) => { await api(`/group/togglePhoneStatus/${id}`, { method: 'POST', body: fd({}) }); await loadPhones(); };
window.resetPhone = async (id) => { await api(`/group/resetPhoneUsage/${id}`, { method: 'POST', body: fd({}) }); await loadPhones(); };
window.deleteLink = async (id) => { await api('/group/deleteLink', { method: 'POST', body: fd({ id }) }); await loadLinks(); };
window.deleteAdmin = async (id) => { if (confirm('确认删除管理员？')) { await api('/admin/delete', { method: 'POST', body: fd({ id }) }); await loadAdmins(); } };
window.toggleAdmin = async (id, status) => { await api('/admin/updateStatus', { method: 'POST', body: fd({ id, status }) }); await loadAdmins(); };
window.switchAdmin = async (admin_id) => { const data = await api('/admin/switchTo', { method: 'POST', body: fd({ admin_id }) }); if (data.code === 1) location.reload(); };
window.changeAdminPassword = (admin_id) => openModal('修改管理员密码', [
  { label: '新密码', name: 'new_password', type: 'password', required: true },
  { label: '确认密码', name: 'confirm_password', type: 'password', required: true }
], async (data) => { data.admin_id = admin_id; await api('/admin/changeAdminPassword', { method: 'POST', body: fd(data) }); });

async function batchPhone(path, extra = {}) {
  const ids = selected('phone_ids');
  if (!ids.length) { toast('请选择号码'); return; }
  await api(path, { method: 'POST', body: fd({ ids, ...extra }) });
  await loadPhones();
}

async function batchLinks(path) {
  const ids = selected('link_ids');
  if (!ids.length) { toast('请选择链接'); return; }
  await api(path, { method: 'POST', body: fd({ ids }) });
  await loadLinks();
}

async function deleteByCodes() {
  for (const code of lines('deleteCodes')) {
    await api('/group/deleteLinkByCode', { method: 'POST', body: fd({ code }) });
  }
  toast('处理完成');
  await loadLinks();
}

async function postCodes(path, field) {
  const link_codes = linkCodes(field);
  if (!link_codes.length) { toast('请输入链接代码'); return; }
  const data = await api(path, { method: 'POST', body: fd({ link_codes }) });
  toast(data.msg || '处理完成');
  await loadLinks();
  await loadPhones();
}

async function loadInstruction() {
  const id = selectedGroup('instructionGroup');
  if (!id) return;
  const data = await api('/group/getInstructions?id=' + id);
  $('instructionContent').value = data.data?.content || '';
  state.media.type = data.data?.media_type || '';
  state.media.url = data.data?.media_url || '';
  $('mediaUrl').textContent = state.media.url ? `当前媒体：${state.media.url}` : '';
}

async function saveInstruction() {
  const group_id = selectedGroup('instructionGroup');
  if (!group_id) return;
  await api('/group/updateInstructions', { method: 'POST', body: fd({ group_id, content: $('instructionContent').value, media_type: state.media.type, media_url: state.media.url }) });
  toast('已保存');
}

async function upload(inputId, path) {
  const input = $(inputId);
  if (!input.files.length) return;
  const body = new FormData();
  body.append('file', input.files[0]);
  const data = await api(path, { method: 'POST', body });
  if (data.errno === 0) {
    state.media.type = path.includes('image') ? 'image' : 'video';
    state.media.url = data.data.url;
    $('mediaUrl').textContent = `当前媒体：${state.media.url}`;
  }
  toast(data.message || '上传完成');
}

async function loadSchedule() {
  const id = selectedGroup('scheduleGroup');
  if (!id) return;
  const data = await api(`/group/getScheduleSettings/${id}`);
  const s = data.data || {};
  $('resetEnabled').checked = Number(s.reset_phone_usage_enabled) === 1;
  $('resetTime').value = s.reset_phone_usage_time || '00:00';
  $('deleteExpireEnabled').checked = Number(s.delete_expire_phones_enabled) === 1;
  $('deleteExpireHours').value = s.delete_expire_phones_hours || 24;
}

async function saveSchedule() {
  const group_id = selectedGroup('scheduleGroup');
  if (!group_id) return;
  await api('/group/updateScheduleSettings', { method: 'POST', body: fd({
    group_id,
    reset_phone_usage_enabled: $('resetEnabled').checked ? 1 : 0,
    reset_phone_usage_time: $('resetTime').value,
    delete_expire_phones_enabled: $('deleteExpireEnabled').checked ? 1 : 0,
    delete_expire_phones_hours: $('deleteExpireHours').value
  }) });
  toast('已保存');
}

async function manualSchedule(path) {
  const group_id = selectedGroup('scheduleGroup');
  if (!group_id) return;
  const data = await api(path, { method: 'POST', body: fd({ group_id }) });
  toast(data.msg || '完成');
}

boot().catch((err) => {
  console.error(err);
  toast('页面初始化失败');
});
