# syntax=docker/dockerfile:1
FROM node:24-bookworm-slim AS frontend
WORKDIR /src/frontend
COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci
COPY frontend/ ./
RUN npm run build

FROM golang:1.26-bookworm AS backend
WORKDIR /src/backend
COPY backend/go.mod backend/go.sum ./
RUN go mod download
COPY backend/ ./
RUN mkdir -p /out/media
RUN CGO_ENABLED=0 go build -trimpath -ldflags='-s -w' -o /out/flc ./cmd/server

FROM gcr.io/distroless/static-debian12:nonroot
COPY --from=backend /out/flc /flc
COPY --from=frontend /src/frontend/dist /frontend
ENV HTTP_ADDR=:8080 FRONTEND_DIR=/frontend
COPY --from=backend --chown=nonroot:nonroot /out/media /data/media
ENV MEDIA_ROOT=/data/media
VOLUME ["/data/media"]
EXPOSE 8080
ENTRYPOINT ["/flc"]
