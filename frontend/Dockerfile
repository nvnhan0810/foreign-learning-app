FROM node:24-bookworm-slim AS build
WORKDIR /src
COPY package.json package-lock.json ./
RUN npm ci
COPY . .
RUN npm run build
FROM nginx:1.28-alpine
COPY nginx.conf /etc/nginx/conf.d/default.conf
COPY proxy_params_flc /etc/nginx/proxy_params_flc
COPY --from=build /src/dist /usr/share/nginx/html
EXPOSE 80
