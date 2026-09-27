FROM node:20-bookworm-slim
# Chromium for open-wa (puppeteer), fonts for Arabic QR/screens
RUN apt-get update && apt-get install -y --no-install-recommends chromium fonts-noto-core ca-certificates \
    && rm -rf /var/lib/apt/lists/*
ENV PUPPETEER_SKIP_DOWNLOAD=true CHROME_PATH=/usr/bin/chromium PUPPETEER_EXECUTABLE_PATH=/usr/bin/chromium NODE_ENV=production
WORKDIR /app
COPY package.json ./
RUN npm install --omit=dev --no-audit --no-fund
COPY server.js ./
VOLUME ["/data/session"]
EXPOSE 8085
HEALTHCHECK --interval=30s --timeout=5s CMD node -e "fetch('http://127.0.0.1:8085/health').then(r=>process.exit(r.ok?0:1)).catch(()=>process.exit(1))"
CMD ["node", "server.js"]
