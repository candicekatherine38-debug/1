(function () {
  const STORE_KEY = "secure-v2-review-data";
  const SESSION_KEY = "secure-v2-review-session";

  function seed() {
    return {
      admins: [
        { id: 1, username: "admin", password: "admin123456", is_super: true, parent_id: null, status: 1, created_at: "2026-04-27 10:00:00" },
        { id: 2, username: "editor", password: "editor123456", is_super: false, parent_id: 1, status: 1, created_at: "2026-04-27 10:05:00" }
      ],
      groups: [
        { id: 101, admin_id: 1, name: "默认分组", remark: "A 模板演示", created_at: "2026-04-27 10:10:00", instruction: "请在页面中查看手机号并获取验证码。", media_type: "image", media_url: "https://images.unsplash.com/photo-1516321318423-f06f85e504b3?auto=format&fit=crop&w=900&q=80", reset_phone_usage_enabled: true, reset_phone_usage_time: "09:00", delete_expire_phones_enabled: false, delete_expire_phones_hours: 24 },
        { id: 102, admin_id: 1, name: "营销活动", remark: "B 模板演示", created_at: "2026-04-27 10:12:00", instruction: "短信预计在几秒后返回。", media_type: "video", media_url: "https://interactive-examples.mdn.mozilla.net/media/cc0-videos/flower.mp4", reset_phone_usage_enabled: false, reset_phone_usage_time: "00:00", delete_expire_phones_enabled: true, delete_expire_phones_hours: 12 }
      ],
      phones: [
        { id: 201, group_id: 101, admin_id: 1, phone: "13800138000", api_url: "https://sms.example.com/api?token=alpha123456789", max_uses: 5, used_count: 1, status: 1 },
        { id: 202, group_id: 101, admin_id: 1, phone: "13900139000", api_url: "https://sms.example.com/api?token=beta123456789", max_uses: 3, used_count: 0, status: 1 },
        { id: 203, group_id: 102, admin_id: 1, phone: "13700137000", api_url: "https://sms.example.com/api?token=gamma123456789", max_uses: 8, used_count: 2, status: 0 }
      ],
      links: [
        { id: 301, group_id: 101, phone_id: 201, link_code: "LINK-A001", verify_code: "", status: 1, interface_type: "A", created_at: "2026-04-27 10:20:00" },
        { id: 302, group_id: 102, phone_id: 203, link_code: "LINK-B001", verify_code: "", status: 1, interface_type: "B", created_at: "2026-04-27 10:22:00" },
        { id: 303, group_id: 101, phone_id: null, link_code: "LINK-C001", verify_code: "", status: 1, interface_type: "C", created_at: "2026-04-27 10:24:00" }
      ]
    };
  }

  function getState() {
    const raw = localStorage.getItem(STORE_KEY);
    return raw ? JSON.parse(raw) : seed();
  }

  function setState(next) {
    localStorage.setItem(STORE_KEY, JSON.stringify(next));
  }

  function ensureSeed() {
    if (!localStorage.getItem(STORE_KEY)) {
      setState(seed());
    }
  }

  function getSession() {
    const raw = localStorage.getItem(SESSION_KEY);
    return raw ? JSON.parse(raw) : null;
  }

  function setSession(value) {
    localStorage.setItem(SESSION_KEY, JSON.stringify(value));
  }

  function requireSession() {
    return !!getSession();
  }

  function login(username, password) {
    const data = getState();
    const admin = data.admins.find((item) => item.username === username && item.password === password && item.status === 1);
    if (!admin) return { ok: false, message: "用户名或密码错误" };
    setSession({
      admin_id: admin.id,
      username: admin.username,
      is_super: admin.is_super,
      original: null
    });
    return { ok: true };
  }

  function logout() {
    localStorage.removeItem(SESSION_KEY);
  }

  function currentAdmin() {
    const session = getSession();
    if (!session) return null;
    const data = getState();
    return data.admins.find((item) => item.id === session.admin_id) || null;
  }

  function currentContext() {
    const session = getSession();
    const admin = currentAdmin();
    if (!session || !admin) return null;
    return {
      id: admin.id,
      username: admin.username,
      is_super: !!session.is_super,
      switched_by_super: !!(session.original && session.original.is_super),
      original_username: session.original ? session.original.username : ""
    };
  }

  function groupsForCurrent() {
    const admin = currentAdmin();
    const data = getState();
    if (!admin) return [];
    if (admin.is_super) return data.groups;
    return data.groups.filter((group) => group.admin_id === admin.id);
  }

  function phonesForCurrent(groupId, keyword) {
    const allowed = new Set(groupsForCurrent().map((item) => item.id));
    return getState().phones.filter((phone) => {
      if (!allowed.has(phone.group_id)) return false;
      if (groupId && groupId !== "all" && Number(groupId) !== phone.group_id) return false;
      if (keyword && !phone.phone.includes(keyword)) return false;
      return true;
    });
  }

  function linksForCurrent(groupId, keyword) {
    const data = getState();
    const allowed = new Set(groupsForCurrent().map((item) => item.id));
    return data.links.filter((link) => {
      if (!allowed.has(link.group_id)) return false;
      if (groupId && Number(groupId) !== link.group_id) return false;
      const phone = data.phones.find((item) => item.id === link.phone_id);
      if (keyword && phone && !phone.phone.includes(keyword)) return false;
      return true;
    }).map((link) => {
      const phone = data.phones.find((item) => item.id === link.phone_id);
      return { ...link, phone: phone ? phone.phone : "" };
    });
  }

  function createGroup(payload) {
    const data = getState();
    const admin = currentAdmin();
    const nextId = Math.max(...data.groups.map((item) => item.id), 100) + 1;
    data.groups.unshift({
      id: nextId,
      admin_id: admin.id,
      name: payload.name,
      remark: payload.remark || "",
      created_at: now(),
      instruction: "",
      media_type: "image",
      media_url: "",
      reset_phone_usage_enabled: false,
      reset_phone_usage_time: "00:00",
      delete_expire_phones_enabled: false,
      delete_expire_phones_hours: 24
    });
    setState(data);
  }

  function updateGroup(id, payload) {
    const data = getState();
    const group = data.groups.find((item) => item.id === id);
    if (!group) return;
    group.name = payload.name;
    group.remark = payload.remark || "";
    setState(data);
  }

  function deleteGroup(id) {
    const data = getState();
    data.groups = data.groups.filter((item) => item.id !== id);
    data.phones = data.phones.filter((item) => item.group_id !== id);
    data.links = data.links.filter((item) => item.group_id !== id);
    setState(data);
  }

  function addPhone(payload) {
    const data = getState();
    const nextId = Math.max(...data.phones.map((item) => item.id), 200) + 1;
    data.phones.unshift({
      id: nextId,
      group_id: Number(payload.group_id),
      admin_id: currentAdmin().id,
      phone: payload.phone,
      api_url: payload.api_url,
      max_uses: Number(payload.max_uses || 1),
      used_count: 0,
      status: 1
    });
    setState(data);
  }

  function importPhones(payload) {
    for (const line of String(payload.phones || "").split(/\r?\n/)) {
      const parts = line.split("----");
      if (parts.length === 2) {
        addPhone({ group_id: payload.group_id, phone: parts[0].trim(), api_url: parts[1].trim(), max_uses: payload.max_uses });
      }
    }
  }

  function togglePhone(id) {
    const data = getState();
    const phone = data.phones.find((item) => item.id === id);
    if (!phone) return;
    phone.status = phone.status === 1 ? 0 : 1;
    setState(data);
  }

  function resetPhone(id) {
    const data = getState();
    const phone = data.phones.find((item) => item.id === id);
    if (!phone) return;
    phone.used_count = 0;
    setState(data);
  }

  function deletePhone(id) {
    const data = getState();
    data.phones = data.phones.filter((item) => item.id !== id);
    data.links = data.links.map((item) => item.phone_id === id ? { ...item, phone_id: null } : item);
    setState(data);
  }

  function createLinks(payload, phoneIds) {
    const data = getState();
    const nextBase = Math.max(...data.links.map((item) => item.id), 300) + 1;
    const count = Number(payload.count || 1);
    const created = [];
    for (let i = 0; i < count; i += 1) {
      const id = nextBase + created.length;
      const code = "LINK-" + Math.random().toString(36).slice(2, 8).toUpperCase();
      data.links.unshift({
        id,
        group_id: Number(payload.group_id),
        phone_id: phoneIds && phoneIds.length ? Number(phoneIds[i % phoneIds.length]) : null,
        link_code: code,
        verify_code: "",
        status: 1,
        interface_type: payload.interface_type || "A",
        created_at: now()
      });
      created.push(code);
    }
    setState(data);
    return created;
  }

  function createLinksByPhones(payload, selectedPhoneIds) {
    const countPerPhone = Number(payload.count_per_phone || 1);
    const ids = [];
    for (const id of selectedPhoneIds) {
      for (let i = 0; i < countPerPhone; i += 1) ids.push(Number(id));
    }
    return createLinks({ group_id: payload.group_id, count: ids.length, interface_type: payload.interface_type }, ids);
  }

  function deleteLink(id) {
    const data = getState();
    data.links = data.links.filter((item) => item.id !== id);
    setState(data);
  }

  function adminsForCurrent() {
    const admin = currentAdmin();
    const data = getState();
    if (!admin || !admin.is_super) return [];
    return data.admins.filter((item) => !item.is_super && item.parent_id === admin.id);
  }

  function createAdmin(payload) {
    const data = getState();
    const nextId = Math.max(...data.admins.map((item) => item.id), 2) + 1;
    data.admins.push({
      id: nextId,
      username: payload.username,
      password: payload.password,
      is_super: false,
      parent_id: currentAdmin().id,
      status: 1,
      created_at: now()
    });
    setState(data);
  }

  function toggleAdmin(id, status) {
    const data = getState();
    const admin = data.admins.find((item) => item.id === id);
    if (!admin) return;
    admin.status = status;
    setState(data);
  }

  function deleteAdmin(id) {
    const data = getState();
    data.admins = data.admins.filter((item) => item.id !== id);
    setState(data);
  }

  function switchAdmin(id) {
    const target = getState().admins.find((item) => item.id === id);
    const current = currentAdmin();
    if (!target || !current || !current.is_super) return;
    setSession({
      admin_id: target.id,
      username: target.username,
      is_super: false,
      original: {
        admin_id: current.id,
        username: current.username,
        is_super: current.is_super
      }
    });
  }

  function returnToSuper() {
    const session = getSession();
    if (!session || !session.original) return;
    setSession({
      admin_id: session.original.admin_id,
      username: session.original.username,
      is_super: true,
      original: null
    });
  }

  function changeAdminPassword(id, password) {
    const data = getState();
    const admin = data.admins.find((item) => item.id === id);
    if (!admin) return;
    admin.password = password;
    setState(data);
  }

  function changeOwnPassword(oldPassword, nextPassword, confirmPassword) {
    const data = getState();
    const admin = currentAdmin();
    if (!admin) return { ok: false, message: "会话已失效" };
    const target = data.admins.find((item) => item.id === admin.id);
    if (!target || target.password !== oldPassword) return { ok: false, message: "原密码错误" };
    if (nextPassword.length < 8) return { ok: false, message: "新密码至少 8 位" };
    if (nextPassword !== confirmPassword) return { ok: false, message: "两次输入不一致" };
    target.password = nextPassword;
    setState(data);
    logout();
    return { ok: true, message: "密码已修改，请重新登录" };
  }

  function instructionForGroup(id) {
    return getState().groups.find((item) => item.id === id) || null;
  }

  function saveInstruction(id, content, mediaUrl) {
    const data = getState();
    const group = data.groups.find((item) => item.id === id);
    if (!group) return;
    group.instruction = content;
    group.media_url = mediaUrl;
    group.media_type = mediaUrl.includes(".mp4") ? "video" : "image";
    setState(data);
  }

  function scheduleForGroup(id) {
    return getState().groups.find((item) => item.id === id) || null;
  }

  function saveSchedule(id, payload) {
    const data = getState();
    const group = data.groups.find((item) => item.id === id);
    if (!group) return;
    group.reset_phone_usage_enabled = !!payload.resetEnabled;
    group.reset_phone_usage_time = payload.resetTime;
    group.delete_expire_phones_enabled = !!payload.deleteEnabled;
    group.delete_expire_phones_hours = Number(payload.deleteHours || 24);
    setState(data);
  }

  function stats() {
    const allowedGroups = groupsForCurrent().map((item) => item.id);
    const phoneCount = phonesForCurrent("all", "").length;
    const linkCount = linksForCurrent(allowedGroups[0] || null, "").length;
    return {
      groups: allowedGroups.length,
      phones: phoneCount,
      links: getState().links.filter((item) => allowedGroups.includes(item.group_id)).length
    };
  }

  function getLinkByCode(code) {
    const data = getState();
    const link = data.links.find((item) => item.link_code === code) || data.links[0];
    const group = data.groups.find((item) => item.id === link.group_id);
    const phone = data.phones.find((item) => item.id === link.phone_id);
    return {
      ...link,
      phone: phone ? phone.phone : "未绑定",
      instruction: group ? group.instruction : "",
      media_type: group ? group.media_type : "",
      media_url: group ? group.media_url : "",
      status_text: link.verify_code ? "已获取验证码" : "等待获取验证码"
    };
  }

  function simulateCode(code) {
    const data = getState();
    const link = data.links.find((item) => item.link_code === code) || data.links[0];
    if (!link.phone_id) {
      const fallback = data.phones.find((item) => item.group_id === link.group_id && item.status === 1);
      if (fallback) link.phone_id = fallback.id;
    }
    link.verify_code = String(Math.floor(100000 + Math.random() * 900000));
    link.status = 0;
    const phone = data.phones.find((item) => item.id === link.phone_id);
    if (phone) phone.used_count += 1;
    setState(data);
    return getLinkByCode(code);
  }

  function resetDemo() {
    setState(seed());
    logout();
  }

  function now() {
    return new Date().toISOString().slice(0, 19).replace("T", " ");
  }

  window.ReviewData = {
    ensureSeed,
    requireSession,
    login,
    logout,
    currentContext,
    groupsForCurrent,
    phonesForCurrent,
    linksForCurrent,
    createGroup,
    updateGroup,
    deleteGroup,
    addPhone,
    importPhones,
    togglePhone,
    resetPhone,
    deletePhone,
    createLinks,
    createLinksByPhones,
    deleteLink,
    adminsForCurrent,
    createAdmin,
    toggleAdmin,
    deleteAdmin,
    switchAdmin,
    returnToSuper,
    changeAdminPassword,
    changeOwnPassword,
    instructionForGroup,
    saveInstruction,
    scheduleForGroup,
    saveSchedule,
    stats,
    getLinkByCode,
    simulateCode,
    resetDemo
  };
}());
