FROM node:20-alpine

WORKDIR /app

ENV NODE_ENV=production

COPY server.js ui.html ./

USER node

EXPOSE 3000

HEALTHCHECK --interval=15s --timeout=3s --start-period=5s --retries=3 \
  CMD wget -qO- http://127.0.0.1:3000/api/health >/dev/null || exit 1

CMD ["node", "server.js"]
