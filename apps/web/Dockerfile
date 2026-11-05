# The customer website. Build from apps/web:
#   docker build -t groupbuy-web \
#     --build-arg NEXT_PUBLIC_SITE_URL=https://example.sa ... apps/web
# NEXT_PUBLIC_* values are compiled into the pages, so each environment
# (staging, production) needs its own image. API_BASE_URL is read when the
# server starts.

FROM node:22-bookworm-slim AS build
WORKDIR /app
ENV NEXT_TELEMETRY_DISABLED=1
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY . .
ARG NEXT_PUBLIC_SITE_URL
ARG NEXT_PUBLIC_PAYMENTS_MODE=moyasar
ARG NEXT_PUBLIC_MOYASAR_PUBLISHABLE_KEY
ARG NEXT_PUBLIC_APPLE_PAY_LABEL="Group Buy"
ARG NEXT_PUBLIC_REVERB_APP_KEY
ARG NEXT_PUBLIC_REVERB_HOST
ARG NEXT_PUBLIC_REVERB_PORT=443
ARG NEXT_PUBLIC_REVERB_SCHEME=https
# Only the fallback rewrite reads it at build time; the edge proxy sends
# /api/* to the API before the website sees it.
ARG API_BASE_URL=http://api:8000/api/v1
RUN test -n "$NEXT_PUBLIC_SITE_URL" || (echo "Set --build-arg NEXT_PUBLIC_SITE_URL" >&2; exit 1)
RUN npm run build && npm prune --omit=dev --no-audit --no-fund && rm -rf .next/cache

FROM node:22-bookworm-slim
WORKDIR /app
ENV NODE_ENV=production \
    NEXT_TELEMETRY_DISABLED=1 \
    PORT=3000 \
    HOSTNAME=0.0.0.0 \
    API_BASE_URL=http://api:8000/api/v1
COPY --from=build --chown=node:node /app/package.json /app/next.config.ts ./
COPY --from=build --chown=node:node /app/node_modules ./node_modules
COPY --from=build --chown=node:node /app/.next ./.next
USER node
EXPOSE 3000
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s \
    CMD node -e "fetch('http://127.0.0.1:3000/robots.txt').then(r => process.exit(r.ok ? 0 : 1)).catch(() => process.exit(1))"
CMD ["node_modules/.bin/next", "start"]
