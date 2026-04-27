(function () {
  const data = window.ReviewData;
  data.ensureSeed();
  if (!data.requireSession()) {
    location.href = "/admin/login";
  }

  const $ = (id) => document.getElementById(id);
  const qsa = (selector) => Array.from(document.querySelectorAll(selector));

  function toast(text) {
    const box = $("toast");
    box.textContent = text;
    box.classList.add("show");
    setTimeout(() => box.classList.remove("show"), 2200);
  }

  function boot() {
    bindTabs();
    bindActions();
    renderContext();
    renderGroups();
    renderPhones();
    renderLinks();
    renderAdmins();
    renderOverview();
    hydrateInstruction();
    hydrateSchedule();
  }

  function bindTabs() {
    qsa(".nav").forEach((button) => {
      button.addEventListener("click", () => {
        qsa(".nav").forEach((item) => item.classList.remove("active"));
        qsa(".tab").forEach((item) => item.classList.remove("active"));
        button.classList.add("active");
        $(button.dataset.tab).classList.add("active");
        $("title").textContent = button.textContent;
      });
    });
  }

  function bindActions() {
    $("logoutBtn").onclick = () => {
      data.logout();
      location.href = "/admin/login";
    };
    $("resetDemoBtn").onclick = () => {
      data.resetDemo();
      location.href = "/admin/login";
    };
    $("returnSuperBtn").onclick = () => {
      data.returnToSuper();
      location.reload();
    };
    $("createGroupBtn").onclick = () => openModal("新建分组", [
      field("name", "名称"),
      field("remark", "备注", "textarea")
    ], (payload) => {
      data.createGroup(payload);
      rerenderAll();
    });
    $("addPhoneBtn").onclick = () => openModal("添加号码", [
      selectField("group_id", "分组", groupOptions()),
      field("phone", "手机号"),
      field("api_url", "API 地址"),
      field("max_uses", "最大使用次数", "number", "1")
    ], (payload) => {
      data.addPhone(payload);
      renderPhones();
      renderOverview();
    });
    $("importPhoneBtn").onclick = () => openModal("批量导入号码", [
      selectField("group_id", "分组", groupOptions()),
      field("phones", "每行格式：手机号----API地址", "textarea"),
      field("max_uses", "最大使用次数", "number", "1")
    ], (payload) => {
      data.importPhones(payload);
      renderPhones();
      renderOverview();
    });
    $("phoneSearchBtn").onclick = renderPhones;
    $("createLinksBtn").onclick = () => openModal("生成链接", [
      selectField("group_id", "分组", groupOptions()),
      field("count", "数量", "number", "5"),
      selectField("interface_type", "界面", ["A", "B", "C"].map((item) => `<option value="${item}">${item}</option>`).join(""))
    ], (payload) => {
      data.createLinks(payload);
      renderLinks();
      renderOverview();
    });
    $("createByPhonesBtn").onclick = () => {
      const selected = selectedPhoneIds();
      if (!selected.length) {
        toast("请先在号码池里选择一批号码");
        return;
      }
      openModal("按号码生成链接", [
        selectField("group_id", "分组", groupOptions()),
        field("count_per_phone", "每个号码生成数量", "number", "1"),
        selectField("interface_type", "界面", ["A", "B", "C"].map((item) => `<option value="${item}">${item}</option>`).join(""))
      ], (payload) => {
        data.createLinksByPhones(payload, selected);
        renderLinks();
        renderOverview();
      });
    };
    $("linkSearchBtn").onclick = renderLinks;
    $("saveInstructionBtn").onclick = () => {
      const groupId = Number($("instructionGroup").value || 0);
      data.saveInstruction(groupId, $("instructionContent").value, $("instructionMedia").value);
      toast("说明已保存");
    };
    $("instructionGroup").onchange = hydrateInstruction;
    $("saveScheduleBtn").onclick = () => {
      const groupId = Number($("scheduleGroup").value || 0);
      data.saveSchedule(groupId, {
        resetEnabled: $("resetEnabled").checked,
        resetTime: $("resetTime").value,
        deleteEnabled: $("deleteEnabled").checked,
        deleteHours: $("deleteHours").value
      });
      toast("定时任务设置已保存");
    };
    $("scheduleGroup").onchange = hydrateSchedule;
    if ($("createAdminBtn")) {
      $("createAdminBtn").onclick = () => openModal("新建管理员", [
        field("username", "用户名"),
        field("password", "密码", "password", "editor123456")
      ], (payload) => {
        data.createAdmin(payload);
        renderAdmins();
      });
    }
  }

  function renderContext() {
    const ctx = data.currentContext();
    $("accountLine").textContent = `当前账号：${ctx.username}${ctx.switched_by_super ? `，由 ${ctx.original_username} 切换` : ""}`;
    $("returnSuperBtn").classList.toggle("hidden", !ctx.switched_by_super);
    qsa(".super-only").forEach((item) => item.classList.toggle("hidden", !ctx.is_super));
  }

  function renderOverview() {
    const stats = data.stats();
    $("statGroups").textContent = stats.groups;
    $("statPhones").textContent = stats.phones;
    $("statLinks").textContent = stats.links;
  }

  function renderGroups() {
    const rows = data.groupsForCurrent().map((group) => {
      return `<tr>
        <td>${group.id}</td>
        <td>${group.name}</td>
        <td>${group.remark || ""}</td>
        <td>${group.created_at}</td>
        <td class="toolbar">
          <button onclick="ReviewApp.editGroup(${group.id})">编辑</button>
          <button onclick="ReviewApp.deleteGroup(${group.id})">删除</button>
        </td>
      </tr>`;
    }).join("");
    $("groupRows").innerHTML = rows;
    syncGroupSelects();
  }

  function renderPhones() {
    const groupId = $("phoneGroup").value || "all";
    const keyword = $("phoneKeyword").value || "";
    const rows = data.phonesForCurrent(groupId, keyword).map((phone) => {
      return `<tr>
        <td><input type="checkbox" name="selected-phone" value="${phone.id}"></td>
        <td>${phone.id}</td>
        <td>${phone.phone}</td>
        <td>${mask(phone.api_url)}</td>
        <td>${phone.used_count}/${phone.max_uses}</td>
        <td>${badge(phone.status === 1)}</td>
        <td class="toolbar">
          <button onclick="ReviewApp.togglePhone(${phone.id})">${phone.status === 1 ? "禁用" : "启用"}</button>
          <button onclick="ReviewApp.resetPhone(${phone.id})">重置</button>
          <button onclick="ReviewApp.deletePhone(${phone.id})">删除</button>
        </td>
      </tr>`;
    }).join("");
    $("phoneRows").innerHTML = rows;
  }

  function renderLinks() {
    const groupId = $("linkGroup").value || firstGroupId();
    if (groupId) $("linkGroup").value = String(groupId);
    const keyword = $("linkSearch").value || "";
    const rows = data.linksForCurrent(groupId, keyword).map((link) => {
      const href = `/link/${link.link_code}`;
      return `<tr>
        <td>${link.id}</td>
        <td><a href="${href}" target="_blank">${link.link_code}</a></td>
        <td>${link.phone || "未绑定"}</td>
        <td>${link.verify_code || ""}</td>
        <td>${badge(link.status === 1 && !link.verify_code)}</td>
        <td>${link.interface_type}</td>
        <td class="toolbar">
          <button onclick="navigator.clipboard.writeText('${location.origin}${href}')">复制</button>
          <button onclick="ReviewApp.deleteLink(${link.id})">删除</button>
        </td>
      </tr>`;
    }).join("");
    $("linkRows").innerHTML = rows;
  }

  function renderAdmins() {
    const body = $("adminRows");
    if (!body) return;
    body.innerHTML = data.adminsForCurrent().map((admin) => {
      return `<tr>
        <td>${admin.id}</td>
        <td>${admin.username}</td>
        <td>${badge(admin.status === 1)}</td>
        <td>${admin.created_at}</td>
        <td class="toolbar">
          <button onclick="ReviewApp.switchAdmin(${admin.id})">切换</button>
          <button onclick="ReviewApp.toggleAdmin(${admin.id}, ${admin.status === 1 ? 0 : 1})">${admin.status === 1 ? "禁用" : "启用"}</button>
          <button onclick="ReviewApp.changeAdminPassword(${admin.id})">改密</button>
          <button onclick="ReviewApp.deleteAdmin(${admin.id})">删除</button>
        </td>
      </tr>`;
    }).join("");
  }

  function hydrateInstruction() {
    const groupId = Number($("instructionGroup").value || firstGroupId());
    const group = data.instructionForGroup(groupId);
    $("instructionGroup").value = String(groupId);
    $("instructionContent").value = group ? group.instruction || "" : "";
    $("instructionMedia").value = group ? group.media_url || "" : "";
  }

  function hydrateSchedule() {
    const groupId = Number($("scheduleGroup").value || firstGroupId());
    const group = data.scheduleForGroup(groupId);
    $("scheduleGroup").value = String(groupId);
    $("resetEnabled").checked = !!group.reset_phone_usage_enabled;
    $("resetTime").value = group.reset_phone_usage_time || "00:00";
    $("deleteEnabled").checked = !!group.delete_expire_phones_enabled;
    $("deleteHours").value = group.delete_expire_phones_hours || 24;
  }

  function syncGroupSelects() {
    const html = `<option value="all">全部分组</option>` + data.groupsForCurrent().map((group) => `<option value="${group.id}">${group.name}</option>`).join("");
    $("phoneGroup").innerHTML = html;
    $("linkGroup").innerHTML = html.replace('<option value="all">全部分组</option>', "");
    $("instructionGroup").innerHTML = html.replace('<option value="all">全部分组</option>', "");
    $("scheduleGroup").innerHTML = html.replace('<option value="all">全部分组</option>', "");
  }

  function openModal(title, fields, onSubmit) {
    const form = $("modalForm");
    form.innerHTML = `<h2>${title}</h2>` + fields.map(renderField).join("") + `<div class="toolbar"><button class="primary" value="submit">保存</button><button value="cancel">取消</button></div>`;
    form.onsubmit = (event) => {
      event.preventDefault();
      onSubmit(Object.fromEntries(new FormData(form).entries()));
      $("modal").close();
      toast("已更新");
    };
    $("modal").showModal();
  }

  function renderField(fieldConfig) {
    if (fieldConfig.kind === "select") {
      return `<label>${fieldConfig.label}<select name="${fieldConfig.name}">${fieldConfig.options}</select></label>`;
    }
    if (fieldConfig.kind === "textarea") {
      return `<label>${fieldConfig.label}<textarea name="${fieldConfig.name}">${fieldConfig.value || ""}</textarea></label>`;
    }
    return `<label>${fieldConfig.label}<input type="${fieldConfig.kind}" name="${fieldConfig.name}" value="${fieldConfig.value || ""}"></label>`;
  }

  function field(name, label, kind = "text", value = "") {
    return { name, label, kind, value };
  }

  function selectField(name, label, options) {
    return { name, label, kind: "select", options };
  }

  function groupOptions() {
    return data.groupsForCurrent().map((group) => `<option value="${group.id}">${group.name}</option>`).join("");
  }

  function badge(on) {
    return `<span class="badge ${on ? "" : "off"}">${on ? "启用" : "停用"}</span>`;
  }

  function mask(url) {
    return String(url || "").replace(/(token=)[^&]{6,}/i, "$1****");
  }

  function firstGroupId() {
    return data.groupsForCurrent()[0] ? data.groupsForCurrent()[0].id : "";
  }

  function selectedPhoneIds() {
    return qsa('input[name="selected-phone"]:checked').map((item) => item.value);
  }

  function rerenderAll() {
    renderGroups();
    renderPhones();
    renderLinks();
    renderAdmins();
    renderOverview();
    hydrateInstruction();
    hydrateSchedule();
  }

  window.ReviewApp = {
    editGroup(id) {
      const group = data.groupsForCurrent().find((item) => item.id === id);
      openModal("编辑分组", [
        field("name", "名称", "text", group.name),
        field("remark", "备注", "textarea", group.remark || "")
      ], (payload) => {
        data.updateGroup(id, payload);
        rerenderAll();
      });
    },
    deleteGroup(id) {
      data.deleteGroup(id);
      rerenderAll();
    },
    togglePhone(id) {
      data.togglePhone(id);
      renderPhones();
    },
    resetPhone(id) {
      data.resetPhone(id);
      renderPhones();
    },
    deletePhone(id) {
      data.deletePhone(id);
      renderPhones();
      renderLinks();
      renderOverview();
    },
    deleteLink(id) {
      data.deleteLink(id);
      renderLinks();
      renderOverview();
    },
    switchAdmin(id) {
      data.switchAdmin(id);
      location.reload();
    },
    toggleAdmin(id, status) {
      data.toggleAdmin(id, status);
      renderAdmins();
    },
    deleteAdmin(id) {
      data.deleteAdmin(id);
      renderAdmins();
    },
    changeAdminPassword(id) {
      openModal("修改管理员密码", [
        field("password", "新密码", "password", "editor123456")
      ], (payload) => {
        data.changeAdminPassword(id, payload.password);
        toast("管理员密码已更新");
      });
    }
  };

  boot();
}());
