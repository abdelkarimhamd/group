// Starts the fixture API (mock/server.mts on :4011) behind Prism (:4010).
// Prism validates every request and response against the contract and
// answers with an error when either side breaks it, so the website and the
// fixtures both stay honest. Point the site at http://localhost:4010.
import { spawn } from "node:child_process";
import fs from "node:fs";
import { fileURLToPath } from "node:url";
import path from "node:path";

const root = path.dirname(path.dirname(fileURLToPath(import.meta.url)));
const contract = path.resolve(root, "../api/openapi/v1.yaml");
const prismBin = path.resolve(root, "node_modules/.bin/prism");
// MOCK_STRICT=1 turns contract violations into error responses. Off by
// default: Prism does not apply `nullable` to properties of schemas reached
// through allOf (CampaignDetail, Order...), so valid nulls there are
// reported. Violations are always logged either way.
const strict = process.env.MOCK_STRICT === "1";

// MOCK_VIOLATIONS_FILE=path also appends every contract violation Prism
// reports to that file; the browser tests fail when the site sent a request
// the contract does not allow.
const violationsFile = process.env.MOCK_VIOLATIONS_FILE;
if (violationsFile) {
  fs.mkdirSync(path.dirname(violationsFile), { recursive: true });
  fs.writeFileSync(violationsFile, "");
}

const prism = spawn(
  prismBin,
  ["proxy", contract, "http://127.0.0.1:4011", "-h", "127.0.0.1", "-p", "4010", ...(strict ? ["--errors"] : [])],
  { stdio: ["ignore", "pipe", "pipe"], env: process.env },
);
for (const stream of [prism.stdout, prism.stderr]) {
  let buffered = "";
  stream.on("data", (chunk) => {
    process.stdout.write(chunk);
    if (!violationsFile) return;
    buffered += chunk.toString();
    const lines = buffered.split("\n");
    buffered = lines.pop() ?? "";
    const found = lines.filter((line) => line.includes("Violation:"));
    if (found.length > 0) fs.appendFileSync(violationsFile, found.join("\n") + "\n");
  });
}

const children = [
  spawn(process.execPath, [path.join(root, "mock/server.mts")], { stdio: "inherit", env: process.env }),
  prism,
];

function stop(code = 0) {
  for (const child of children) child.kill("SIGTERM");
  process.exit(code);
}

for (const child of children) {
  child.on("exit", (code) => stop(code ?? 1));
}
process.on("SIGINT", () => stop(0));
process.on("SIGTERM", () => stop(0));
