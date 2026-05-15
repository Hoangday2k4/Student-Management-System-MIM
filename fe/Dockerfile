# ── Stage 1: Build ──────────────────────────────────────────────────────────
FROM node:20-alpine AS builder

WORKDIR /app

# Install pnpm (same version as CI)
RUN npm install -g pnpm@9

# Copy lockfile + manifest first for layer caching
COPY package.json pnpm-lock.yaml ./

RUN pnpm install --frozen-lockfile

# Copy source and build
COPY . .

# VITE_BASE_URL is passed at build time (default /mimsms/)
ARG VITE_BASE_URL=/mimsms/
ENV VITE_BASE_URL=$VITE_BASE_URL

RUN pnpm run build

# ── Stage 2: Serve ───────────────────────────────────────────────────────────
FROM nginx:1.27-alpine

COPY --from=builder /app/dist /usr/share/nginx/html
COPY nginx.conf /etc/nginx/conf.d/default.conf

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=15s --retries=3 \
  CMD wget -qO- http://localhost/ || exit 1
