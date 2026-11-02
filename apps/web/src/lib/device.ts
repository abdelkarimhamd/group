/** "Safari on iPhone"-style label for the account's session list (max 100 chars). */
export function deviceNameFrom(userAgent: string | null | undefined): string {
  const ua = userAgent ?? "";
  const browser = /Edg\//.test(ua)
    ? "Edge"
    : /SamsungBrowser\//.test(ua)
      ? "Samsung Internet"
      : /(Chrome|CriOS)\//.test(ua)
        ? "Chrome"
        : /(Firefox|FxiOS)\//.test(ua)
          ? "Firefox"
          : /Safari\//.test(ua)
            ? "Safari"
            : "Browser";
  const device = /iPhone/.test(ua)
    ? "iPhone"
    : /iPad/.test(ua)
      ? "iPad"
      : /Android/.test(ua)
        ? "Android"
        : /Mac OS X/.test(ua)
          ? "Mac"
          : /Windows/.test(ua)
            ? "Windows"
            : /Linux/.test(ua)
              ? "Linux"
              : "web";
  return `${browser} on ${device}`.slice(0, 100);
}
