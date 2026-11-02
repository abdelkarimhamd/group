import type { NextConfig } from "next";

// Where the Laravel API (or a mock of it) answers, including any path prefix:
//   http://localhost:8000/api/v1   local Laravel
//   http://localhost:4010          Prism, from the contract (npm run mock)
const apiBaseUrl = (process.env.API_BASE_URL ?? "http://localhost:4010").replace(/\/+$/, "");

const nextConfig: NextConfig = {
  cacheComponents: true,
  partialPrefetching: true,
  poweredByHeader: false,
  turbopack: {
    rules: {
      "*.css": {
        loaders: ["@tailwindcss/turbopack"],
        as: "*.css",
      },
    },
  },
  // In production the load balancer sends /api/v1 to Laravel (the contract's
  // same-origin server). Locally, Next forwards it, so the browser can poll
  // public endpoints such as campaign progress without CORS.
  async rewrites() {
    return {
      beforeFiles: [],
      afterFiles: [],
      fallback: [{ source: "/api/v1/:path*", destination: `${apiBaseUrl}/:path*` }],
    };
  },
  async headers() {
    return [
      {
        source: "/:path*",
        headers: [
          { key: "X-Content-Type-Options", value: "nosniff" },
          { key: "Referrer-Policy", value: "strict-origin-when-cross-origin" },
          { key: "X-Frame-Options", value: "DENY" },
          { key: "Permissions-Policy", value: "camera=(), microphone=(), geolocation=()" },
        ],
      },
    ];
  },
};

export default nextConfig;
