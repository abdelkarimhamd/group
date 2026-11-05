# The admin portal: a static build served by nginx. Build from apps/admin:
#   docker build -t groupbuy-admin apps/admin
# The portal calls /api/v1 on its own origin, so the edge proxy sends the
# admin host's /api/* to the API (deploy/Caddyfile). Set VITE_API_BASE_URL
# only when the API lives on another origin.

FROM node:22-bookworm-slim AS build
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY . .
ARG VITE_API_BASE_URL
RUN npm run build

FROM nginxinc/nginx-unprivileged:1.27-alpine
COPY nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=build /app/dist /usr/share/nginx/html
EXPOSE 8080
HEALTHCHECK --interval=30s --timeout=5s CMD wget -q -O /dev/null http://127.0.0.1:8080/healthz || exit 1
