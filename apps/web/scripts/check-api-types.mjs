// Fails when src/lib/api/schema.d.ts is out of date with the contract.
// Fix with: npm run api:types
import { execFileSync } from "node:child_process";
import { readFileSync, mkdtempSync, rmSync } from "node:fs";
import { tmpdir } from "node:os";
import path from "node:path";
import { fileURLToPath } from "node:url";

const root = path.dirname(path.dirname(fileURLToPath(import.meta.url)));
const dir = mkdtempSync(path.join(tmpdir(), "api-types-"));
const fresh = path.join(dir, "schema.d.ts");
try {
  execFileSync(
    path.join(root, "node_modules/.bin/openapi-typescript"),
    [path.resolve(root, "../api/openapi/v1.yaml"), "-o", fresh],
    { stdio: "ignore" },
  );
  const current = readFileSync(path.join(root, "src/lib/api/schema.d.ts"), "utf8");
  if (readFileSync(fresh, "utf8") !== current) {
    console.error("src/lib/api/schema.d.ts is out of date with apps/api/openapi/v1.yaml. Run: npm run api:types");
    process.exit(1);
  }
  console.log("API types match the contract.");
} finally {
  rmSync(dir, { recursive: true, force: true });
}
