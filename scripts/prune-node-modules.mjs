// Cuts node_modules down to what the production image runs, after the Vite
// build (railpack.json runs it as the last build command):
//
//   - @takumi-rs/*: scripts/takumi-render.mjs imports it (App\Support\TakumiRenderer)
//   - isomorphic-dompurify: the one package the SSR bundle leaves external
//     (see ssr.external in vite.config.ts), because jsdom under it reads its
//     own files at load time and cannot be bundled
//
// Every other package is either bundled into bootstrap/ssr or only needed to
// build. The kept packages bring their full runtime dependency tree, resolved
// the way Node resolves it, so a dependency bump never needs this list edited.
//
// Usage: node scripts/prune-node-modules.mjs --confirm <package>...
// It DELETES from ./node_modules, so it refuses to run without --confirm.
import fs from 'node:fs';
import path from 'node:path';

const args = process.argv.slice(2);
const roots = args.filter((arg) => arg !== '--confirm');

if (!args.includes('--confirm') || roots.length === 0) {
    console.error('usage: node scripts/prune-node-modules.mjs --confirm <package>...');
    process.exit(1);
}

const nodeModules = path.resolve('node_modules');
const kept = new Set();

/** The installed directory of `name` as Node would resolve it from `fromDir`. */
function resolvePackage(name, fromDir) {
    for (let dir = fromDir; ; dir = path.dirname(dir)) {
        const candidate = path.join(dir, 'node_modules', name);

        if (fs.existsSync(path.join(candidate, 'package.json'))) {
            return candidate;
        }

        if (path.dirname(dir) === dir) {
            return null;
        }
    }
}

function keep(name, fromDir, required) {
    const dir = resolvePackage(name, fromDir);

    if (dir === null) {
        // Optional and peer dependencies that were never installed are fine.
        if (required) {
            throw new Error(`prune-node-modules: ${name} is not installed`);
        }

        return;
    }

    if (kept.has(dir)) {
        return;
    }

    kept.add(dir);

    const manifest = JSON.parse(fs.readFileSync(path.join(dir, 'package.json'), 'utf8'));

    for (const dep of Object.keys(manifest.dependencies ?? {})) {
        keep(dep, dir, true);
    }

    for (const dep of Object.keys({ ...manifest.optionalDependencies, ...manifest.peerDependencies })) {
        keep(dep, dir, false);
    }
}

for (const root of roots) {
    keep(root, process.cwd(), true);
}

// Top-level packages (a scoped one is `@scope/name`) that are not kept. A
// kept package keeps its own nested node_modules whole. Dot entries stay:
// .cache and .vite are Railpack cache mounts, .package-lock.json is harmless;
// .bin goes, since its links would point at deleted packages.
const keptTopLevel = new Set(
    [...kept].map((dir) => {
        const [first, second] = path.relative(nodeModules, dir).split(path.sep);

        return first.startsWith('@') ? `${first}/${second}` : first;
    }),
);
let removed = 0;

for (const entry of fs.readdirSync(nodeModules)) {
    if (entry.startsWith('.') && entry !== '.bin') {
        continue;
    }

    const names = entry.startsWith('@') ? fs.readdirSync(path.join(nodeModules, entry)).map((name) => `${entry}/${name}`) : [entry];

    for (const name of names) {
        if (!keptTopLevel.has(name)) {
            fs.rmSync(path.join(nodeModules, name), { recursive: true, force: true });
            removed++;
        }
    }

    if (entry.startsWith('@') && fs.readdirSync(path.join(nodeModules, entry)).length === 0) {
        fs.rmdirSync(path.join(nodeModules, entry));
    }
}

console.log(`prune-node-modules: kept ${kept.size} packages, removed ${removed} top-level entries`);
