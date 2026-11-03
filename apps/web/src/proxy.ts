import { NextResponse, type NextRequest } from "next/server";
import { defaultLocale, isLocale, LOCALE_COOKIE, type Locale } from "@/i18n/config";
import { safeNextPath } from "@/lib/paths";

// Runs before every page: puts a locale in the URL, remembers it, and keeps
// signed-out visitors away from pages that need an account. The token itself
// is checked by the API; an expired one comes back as 401 and the page sends
// the customer to /sign-in?expired=1, where the stale cookie is dropped.

const SESSION_COOKIE = "gb_session";
const PROTECTED = /^\/(ar|en)\/(account(\/.*)?|campaigns\/[^/]+\/join|payments\/return)$/;
const SIGN_IN = /^\/(ar|en)\/sign-in$/;

function preferredLocale(request: NextRequest): Locale {
  const saved = request.cookies.get(LOCALE_COOKIE)?.value;
  if (isLocale(saved)) return saved;
  const header = request.headers.get("accept-language") ?? "";
  for (const part of header.split(",")) {
    const language = part.split(";")[0]?.trim().slice(0, 2).toLowerCase();
    if (isLocale(language)) return language;
  }
  return defaultLocale;
}

export function proxy(request: NextRequest) {
  const { pathname, search } = request.nextUrl;
  const first = pathname.split("/")[1];

  if (!isLocale(first)) {
    const url = request.nextUrl.clone();
    url.pathname = `/${preferredLocale(request)}${pathname === "/" ? "" : pathname}`;
    return NextResponse.redirect(url);
  }

  const locale: Locale = first;
  const signedIn = Boolean(request.cookies.get(SESSION_COOKIE)?.value);
  let response: NextResponse;

  if (PROTECTED.test(pathname) && !signedIn) {
    const url = request.nextUrl.clone();
    url.pathname = `/${locale}/sign-in`;
    url.search = `?next=${encodeURIComponent(pathname + search)}`;
    response = NextResponse.redirect(url);
  } else if (SIGN_IN.test(pathname)) {
    const expired = request.nextUrl.searchParams.get("expired") === "1";
    if (signedIn && !expired) {
      const next = safeNextPath(request.nextUrl.searchParams.get("next"), locale);
      response = NextResponse.redirect(new URL(next, request.url));
    } else {
      response = NextResponse.next();
      if (expired) response.cookies.delete(SESSION_COOKIE);
    }
  } else {
    response = NextResponse.next();
  }

  if (request.cookies.get(LOCALE_COOKIE)?.value !== locale) {
    response.cookies.set(LOCALE_COOKIE, locale, {
      path: "/",
      maxAge: 60 * 60 * 24 * 365,
      sameSite: "lax",
    });
  }
  return response;
}

export const config = {
  matcher: [
    // Everything except Next internals, the API path, files with an extension
    // and /.well-known/ (Apple Pay's domain check reads
    // /.well-known/apple-developer-merchantid-domain-association as it is).
    "/((?!_next/|api/|\\.well-known/|.*\\.[a-zA-Z0-9]+$).*)",
  ],
};
