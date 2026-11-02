import { describe, expect, it } from "vitest";
import { safeNextPath, switchLocalePath } from "@/lib/paths";

describe("paths", () => {
  it("follows same-site paths only", () => {
    expect(safeNextPath("/ar/campaigns/1/join?qty=2", "ar")).toBe("/ar/campaigns/1/join?qty=2");
    expect(safeNextPath("//evil.example/x", "ar")).toBe("/ar/account");
    expect(safeNextPath("https://evil.example", "en")).toBe("/en/account");
    expect(safeNextPath("/\\evil.example", "ar")).toBe("/ar/account");
    expect(safeNextPath(null, "en")).toBe("/en/account");
  });

  it("switches the language prefix", () => {
    expect(switchLocalePath("/ar/campaigns", "en")).toBe("/en/campaigns");
    expect(switchLocalePath("/ar", "en")).toBe("/en");
    expect(switchLocalePath("/en/account/joins/1", "ar")).toBe("/ar/account/joins/1");
  });
});
