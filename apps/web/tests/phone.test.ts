import { describe, expect, it } from "vitest";
import { digitsOnly, formatSaudiMobile, normalizeSaudiMobile, toAsciiDigits } from "@/lib/phone";

describe("Saudi mobile numbers", () => {
  it.each([
    ["0512345678", "+966512345678"],
    ["512345678", "+966512345678"],
    ["+966512345678", "+966512345678"],
    ["00966512345678", "+966512345678"],
    ["966512345678", "+966512345678"],
    ["051 234 5678", "+966512345678"],
    ["٠٥١٢٣٤٥٦٧٨", "+966512345678"],
    ["(05) 1234-5678", "+966512345678"],
  ])("normalizes %s", (input, expected) => {
    expect(normalizeSaudiMobile(input)).toBe(expected);
  });

  it.each(["0112345678", "05123", "+971512345678", "abc", "", "05123456789"])("rejects %s", (input) => {
    expect(normalizeSaudiMobile(input)).toBeNull();
  });

  it("formats for display", () => {
    expect(formatSaudiMobile("+966512345678")).toBe("051 234 5678");
    expect(formatSaudiMobile("not a number")).toBe("not a number");
  });

  it("keeps digits only", () => {
    expect(toAsciiDigits("۱۲٣")).toBe("123");
    expect(digitsOnly("12a3٤5 6 7", 6)).toBe("123456");
  });
});
