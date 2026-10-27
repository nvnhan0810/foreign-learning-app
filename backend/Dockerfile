FROM golang:1.26-bookworm AS build
WORKDIR /src
COPY go.mod go.sum ./
RUN go mod download
COPY . .
RUN mkdir -p /out/media
RUN CGO_ENABLED=0 go build -trimpath -ldflags='-s -w' -o /out/flc ./cmd/server
FROM gcr.io/distroless/static-debian12:nonroot
COPY --from=build /out/flc /flc
ENV HTTP_ADDR=:8080 BACKEND_ONLY=true
COPY --from=build --chown=nonroot:nonroot /out/media /data/media
ENV MEDIA_ROOT=/data/media
VOLUME ["/data/media"]
EXPOSE 8080
ENTRYPOINT ["/flc"]
