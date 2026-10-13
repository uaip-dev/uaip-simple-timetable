// Builds the plugin's scripts and styles into plugin/build/:
//   front/   Script Module (index.js) + style.css for the timetable on the site
//   editor/  block editor sidebar panel for events  } copied as written: plain scripts on the
//   block/   Timetable and Upcoming Classes blocks   } wp.* globals, so /* translators: */
//                                                      comments survive for translate.wordpress.org
//   admin/   small helpers for the classic admin screens (colour pickers, meta box fallback)
// Usage: node scripts/build.mjs [--watch]
import * as esbuild from "esbuild";
import { copyFileSync, existsSync, mkdirSync, readdirSync, rmSync, watch as watchFile } from "node:fs";
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
    entryPoints: { index: join(root, "src", "admin", "index.js"), style: join(root, "src", "admin", "style.css") },
    outdir: join(out, "admin"),
    format: "iife",
  },
];

const plain = ["editor", "block"];
const copyPlain = () => {
  for (const dir of plain) {
    mkdirSync(join(out, dir), { recursive: true });
    copyFileSync(join(root, "src", dir, "index.js"), join(out, dir, "index.js"));
  }
};
copyPlain();

if (watch) {
  for (const dir of plain) watchFile(join(root, "src", dir), copyPlain);
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
