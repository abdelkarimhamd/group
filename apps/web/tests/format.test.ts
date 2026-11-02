import { describe, expect, it } from "vitest";
import {
  formatDate,
  formatMoney,
  interpolate,
  minorToDecimalString,
  multiplyMoney,
  plural,
  sar,
  splitDuration,
} from "@/i18n/format";
import { ar } from "@/i18n/dictionaries/ar";
import { en } from "@/i18n/dictionaries/en";

describe("money", () => {
  it("builds decimal strings from integer halalas", () => {
    expect(minorToDecimalString(6900)).toBe("69.00");
    expect(minorToDecimalString(6905)).toBe("69.05");
    expect(minorToDecimalString(5)).toBe("0.05");
    expect(minorToDecimalString(-150)).toBe("-1.50");
  });

  it("refuses anything but whole minor units", () => {
    expect(() => minorToDecimalString(69.5)).toThrow(RangeError);
    expect(() => minorToDecimalString(Number.MAX_SAFE_INTEGER + 2)).toThrow(RangeError);
  });

  it("formats SAR in Arabic and English with Western digits", () => {
    expect(formatMoney(sar(6900), "ar")).toBe("69 ر.س");
    expect(formatMoney(sar(6900), "en")).toBe("SAR 69");
    expect(formatMoney(sar(123456), "ar")).toBe("1,234.56 ر.س");
    expect(formatMoney(sar(10), "en")).toBe("SAR 0.10");
  });

  it("keeps exact halalas where floats would drift", () => {
    // 0.1 + 0.2 style errors: 1001 halalas x 3 must be exactly 30.03.
    expect(formatMoney(multiplyMoney(sar(1001), 3), "en")).toBe("SAR 30.03");
    expect(formatMoney(sar(999_999_999_99), "en")).toBe("SAR 999,999,999.99");
  });

  it("multiplies by whole quantities only", () => {
    expect(multiplyMoney(sar(7900), 2)).toEqual(sar(15800));
    expect(() => multiplyMoney(sar(7900), 1.5)).toThrow(RangeError);
    expect(() => multiplyMoney(sar(7900), -1)).toThrow(RangeError);
  });
});

describe("text", () => {
  it("uses the Arabic plural forms", () => {
    expect(plural(ar.common.units, 1, "ar")).toBe("وحدة واحدة");
    expect(plural(ar.common.units, 2, "ar")).toBe("وحدتان");
    expect(plural(ar.common.units, 3, "ar")).toBe("3 وحدات");
    expect(plural(ar.common.units, 11, "ar")).toBe("11 وحدة");
    expect(plural(ar.common.units, 100, "ar")).toBe("100 وحدة");
    expect(plural(en.common.units, 1, "en")).toBe("1 unit");
    expect(plural(en.common.units, 5, "en")).toBe("5 units");
  });

  it("fills placeholders and leaves unknown ones", () => {
    expect(interpolate("{a} and {b}", { a: 1 })).toBe("1 and {b}");
  });

  it("splits durations", () => {
    expect(splitDuration(90_061_000)).toEqual({ days: 1, hours: 1, minutes: 1, seconds: 1 });
    expect(splitDuration(-5)).toEqual({ days: 0, hours: 0, minutes: 0, seconds: 0 });
  });

  it("shows times in Riyadh with the Gregorian calendar", () => {
    // 21:30 UTC is 00:30 the next day in Riyadh (UTC+3).
    const shown = formatDate("2026-11-01T21:30:00Z", "en", "dateTime");
    expect(shown).toContain("Nov 2, 2026");
    expect(shown).toMatch(/12:30/);
    expect(formatDate("2026-11-01T21:30:00Z", "ar", "date")).toMatch(/2026/);
  });
});
