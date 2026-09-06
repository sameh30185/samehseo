FROM golang:1.22-alpine AS build
WORKDIR /src
RUN apk add --no-cache git ca-certificates
COPY go.mod go.sum* ./
RUN go mod download || true
COPY . .
RUN go mod tidy && CGO_ENABLED=0 go build -o /sameh-api ./cmd/server

FROM alpine:3.20
RUN apk add --no-cache ca-certificates
WORKDIR /app
COPY --from=build /sameh-api /app/sameh-api
COPY migrations /app/migrations
ENV MIGRATIONS_DIR=/app/migrations
ENV HTTP_ADDR=:8080
EXPOSE 8080
CMD ["/app/sameh-api"]
