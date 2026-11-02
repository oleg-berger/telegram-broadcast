# syntax=docker/dockerfile:1
FROM golang:1.26.10-alpine@sha256:a2012b4a1d1f9f7e9f44e0d39b805a901e856b1dd80b4e6ee4338db547050308 AS build
ENV GOTOOLCHAIN=local CGO_ENABLED=0
RUN apk add --no-cache python3
WORKDIR /src
ADD --checksum=sha256:da33c2e1c24a5d7122cd50b6430f6b26488359d88cc4c5eb1f903c0136909582 \
    https://codeload.github.com/wppconnect-team/wppconnect-server-go/tar.gz/cf1098f91c1a1e2028e5d864490f45c72f6b8358 /tmp/upstream.tar.gz
RUN tar -xzf /tmp/upstream.tar.gz --strip-components=1 -C /src

# Keep the server release pinned and adapt its SDK boundary below.
RUN go mod edit -replace=go.mau.fi/whatsmeow=github.com/wppconnect-team/whatsmeow@v0.0.0-20261101180530-9d2171c39a10 \
    && go mod tidy
RUN apk add --no-cache patch
COPY compat.patch /tmp/compat.patch
RUN patch --batch --fuzz=0 -p1 < /tmp/compat.patch \
    && gofmt -w internal/account/account.go internal/media/download_test.go
RUN python3 scripts/dependency-notices.py && go mod vendor
COPY framesocket_test.go /src/vendor/go.mau.fi/whatsmeow/socket/local_framesocket_test.go
COPY sdk-frame.patch /tmp/sdk-frame.patch
RUN patch --batch --fuzz=0 -p1 < /tmp/sdk-frame.patch
RUN --mount=type=cache,target=/root/.cache/go-build go test ./vendor/go.mau.fi/whatsmeow/socket && go test ./...
RUN --mount=type=cache,target=/root/.cache/go-build go build -trimpath -o /out/ ./cmd/server ./cmd/token

FROM wppconnect/wppconnect-server-go:v0.1.10@sha256:094427e6ec4ac13c76ff2b67de4001e4d9009aa20f27c559b144d618e5473d2f
COPY --from=build /out/server /app/server
COPY --from=build /out/token /app/token
COPY --from=build /src/third_party /app/third_party
COPY --from=build /src/vendor/go.mau.fi/whatsmeow/socket/framesocket.go /app/third_party/whatsmeow/framesocket.go
LABEL org.opencontainers.image.version="v0.1.10-local-whatsmeow-9d2171c39a10-framefix" \
      io.telegram-broadcast.whatsmeow.revision="9d2171c39a1037e867d7df1b9188757d27634918" \
      io.telegram-broadcast.whatsmeow.frame-fix="true"
