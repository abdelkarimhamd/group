import fs from "node:fs";
import { VIOLATIONS_FILE } from "../playwright.config";

// Fails the run when the site sent the API a request the contract does not
// allow. Response violations are only reported: Prism ignores `nullable` on
// properties of schemas reached through allOf, so valid nulls show up there.
export default function globalTeardown() {
  if (!fs.existsSync(VIOLATIONS_FILE)) return;
  const lines = fs.readFileSync(VIOLATIONS_FILE, "utf8").split("\n").filter(Boolean);
  const requests = lines.filter((line) => line.includes("Violation: request."));
  const responses = lines.length - requests.length;
  if (responses > 0) console.warn(`Prism reported ${responses} response violation(s); see ${VIOLATIONS_FILE}.`);
  if (requests.length > 0) {
    throw new Error(`The site sent requests that break the API contract:\n${[...new Set(requests)].join("\n")}`);
  }
}
