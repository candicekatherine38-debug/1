import fs from "node:fs";
import path from "node:path";

const root = process.cwd();
const src = path.join(root, "vercel_review");
const dist = path.join(root, "dist");

fs.rmSync(dist, { recursive: true, force: true });
fs.mkdirSync(dist, { recursive: true });

copyDir(path.join(src, "assets"), path.join(dist, "assets"));
copyPage("admin-login.html", path.join(dist, "admin", "login", "index.html"));
copyPage("admin-change-password.html", path.join(dist, "admin", "changePassword", "index.html"));
copyPage("group.html", path.join(dist, "group", "index.html"));
copyPage("link.html", path.join(dist, "link.html"));
copyPage("index.html", path.join(dist, "index.html"));

function copyPage(inputName, outputPath) {
  const from = path.join(src, inputName);
  const to = outputPath;
  fs.mkdirSync(path.dirname(to), { recursive: true });
  fs.copyFileSync(from, to);
}

function copyDir(from, to) {
  fs.mkdirSync(to, { recursive: true });
  for (const entry of fs.readdirSync(from, { withFileTypes: true })) {
    const fromPath = path.join(from, entry.name);
    const toPath = path.join(to, entry.name);
    if (entry.isDirectory()) {
      copyDir(fromPath, toPath);
    } else {
      fs.copyFileSync(fromPath, toPath);
    }
  }
}
