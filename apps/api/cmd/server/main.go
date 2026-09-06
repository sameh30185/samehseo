package main

import (
	"context"
	"log"
	"net/http"
	"os"
	"os/signal"
	"path/filepath"
	"syscall"
	"time"

	"github.com/sameh-ai/sameh-12.0/apps/api/internal/db"
	"github.com/sameh-ai/sameh-12.0/apps/api/internal/httpapi"
)

func main() {
	ctx := context.Background()
	databaseURL := envOr("DATABASE_URL", "postgres://sameh:sameh@localhost:5432/sameh?sslmode=disable")
	if os.Getenv("SESSION_SECRET") == "" {
		log.Println("WARN: SESSION_SECRET empty — set a strong secret in production")
	}
	if os.Getenv("ENCRYPTION_KEY") == "" {
		log.Fatal("ENCRYPTION_KEY is required (32 bytes or 64 hex chars)")
	}

	pool, err := db.Connect(ctx, databaseURL)
	if err != nil {
		log.Fatalf("db connect: %v", err)
	}
	defer pool.Close()

	migDir := envOr("MIGRATIONS_DIR", findMigrations())
	if err := db.Migrate(ctx, pool, migDir); err != nil {
		log.Fatalf("migrate: %v", err)
	}
	log.Printf("migrations applied from %s", migDir)

	srv := httpapi.NewServer(pool)
	addr := envOr("HTTP_ADDR", ":8080")
	httpServer := &http.Server{
		Addr:              addr,
		Handler:           srv.Router(),
		ReadHeaderTimeout: 10 * time.Second,
	}

	go func() {
		log.Printf("SAMEH API listening on %s", addr)
		if err := httpServer.ListenAndServe(); err != nil && err != http.ErrServerClosed {
			log.Fatalf("listen: %v", err)
		}
	}()

	stop := make(chan os.Signal, 1)
	signal.Notify(stop, syscall.SIGINT, syscall.SIGTERM)
	<-stop
	shutdownCtx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
	defer cancel()
	_ = httpServer.Shutdown(shutdownCtx)
	log.Println("shutdown complete")
}

func envOr(k, def string) string {
	if v := os.Getenv(k); v != "" {
		return v
	}
	return def
}

func findMigrations() string {
	candidates := []string{
		"migrations",
		"apps/api/migrations",
		filepath.Join(filepath.Dir(os.Args[0]), "migrations"),
	}
	for _, c := range candidates {
		if st, err := os.Stat(c); err == nil && st.IsDir() {
			abs, _ := filepath.Abs(c)
			return abs
		}
	}
	return "migrations"
}
