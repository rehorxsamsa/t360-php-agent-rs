# MCP server pro čtení MariaDB (stdio). Spouští se přes `docker compose run --rm -T mcp-mariadb`.
FROM node:24.21.0-alpine

# Pevná verze balíčku (nikdy @latest). npm běží jen při buildu v Dockeru.
RUN npm install -g @benborla29/mcp-server-mysql@2.0.9 \
    && npm cache clean --force

USER node
ENTRYPOINT ["mcp-server-mysql"]
