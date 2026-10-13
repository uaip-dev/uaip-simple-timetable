// Builds the plugin's scripts and styles into plugin/build/:
//   front/   Script Module (index.js) + style.css for the timetable on the site
//   editor/  block editor sidebar panel for events (classic script using wp.* globals)
//   block/   Timetable and Upcoming Classes blocks (classic script using wp.* globals)
//   admin/   small helpers for the classic admin screens (colour pickers, meta box fallback)
// Usage: node scripts/build.mjs [--watch]
import * as esbuild from "esbuild";
import { existsSync, mkdirSync, readdirSync, rmSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";

const root = join(dirname(fileURLToPath(import.meta.url)), "..");
const out = join(root, "plugin", "build");
const watch = process.argv.includes("--watch");

rmSync(out, { recursive: true, force: true });
mkdirSync(out, { recursive: true });

const common = {
  bundle: true,
  minify: !watch,
  sourcemap: watch ? "linked" : false,
  target: ["es2020", "chrome100", "firefox100", "safari15"],
  legalComments: "linked",
  logLevel: "info",
};

const configs = [
  {
    ...common,
    entryPoints: { index: join(root, "src", "front", "index.ts"), style: join(root, "src", "front", "style.css") },
    outdir: join(out, "front"),
    format: "esm",
  },
  {
    ...common,
    entryPoints: { index: join(root, "src", "editor", "index.js") },
    outdir: join(out, "editor"),
    format: "iife",
  },
  {
    ...common,
    entryPoints: { index: join(root, "src", "block", "index.js") },
    outdir: join(out, "block"),
    format: "iife",
  },
  {
    ...common,
    entryPoints: { index: join(root, "src", "admin", "index.js"), style: join(root, "src", "admin", "style.css") },
    outdir: join(out, "admin"),
    format: "iife",
  },
];

if (watch) {
  for (const config of configs) await (await esbuild.context(config)).watch();
  console.log("Watching for changes…");
} else {
  await Promise.all(configs.map((c) => esbuild.build(c)));
  const files = [];
  const walk = (d) => {
    for (const e of readdirSync(d, { withFileTypes: true })) {
      const p = join(d, e.name);
      e.isDirectory() ? walk(p) : files.push(p);
    }
  };
  if (existsSync(out)) walk(out);
  console.log(`Built ${files.length} files into plugin/build/`);
}
