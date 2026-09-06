package httpapi

import (
	"context"
	"encoding/json"
	"net/http"
	"os"
	"time"

	"github.com/go-chi/chi/v5"
	"github.com/go-chi/chi/v5/middleware"
	"github.com/jackc/pgx/v5/pgxpool"
)

type Server struct {
	DB             *pgxpool.Pool
	SessionSecret  string
	EncryptionKey  []byte
	CookieSecure   bool
	SessionTTL     time.Duration
}

func NewServer(db *pgxpool.Pool) *Server {
	ttl := 24 * time.Hour
	return &Server{
		DB:            db,
		SessionSecret: os.Getenv("SESSION_SECRET"),
		EncryptionKey: []byte(os.Getenv("ENCRYPTION_KEY")),
		CookieSecure:  os.Getenv("COOKIE_SECURE") == "true",
		SessionTTL:    ttl,
	}
}

func (s *Server) Router() http.Handler {
	r := chi.NewRouter()
	r.Use(middleware.RequestID)
	r.Use(middleware.RealIP)
	r.Use(middleware.Logger)
	r.Use(middleware.Recoverer)
	r.Use(corsMiddleware)

	r.Get("/health", s.handleHealth)

	r.Route("/auth", func(r chi.Router) {
		r.Post("/register", s.handleRegister)
		r.Post("/login", s.handleLogin)
		r.Post("/logout", s.handleLogout)
		r.With(s.requireAuth).Post("/totp/setup", s.handleTOTPSetup)
		r.With(s.requireAuth).Post("/totp/verify", s.handleTOTPVerify)
	})

	r.With(s.requireAuth).Get("/me", s.handleMe)
	r.With(s.requireAuth).Get("/sites", s.handleListSites)
	r.With(s.requireAuth).Post("/sites", s.handleCreateSite)
	r.With(s.requireAuth).Get("/sites/{id}", s.handleGetSite)
	r.With(s.requireAuth).Get("/security/kill-switch", s.handleGetKillSwitch)
	r.With(s.requireAuth).Post("/security/kill-switch", s.handleSetKillSwitch)
	r.With(s.requireAuth).Get("/audit", s.handleListAudit)

	return r
}

func corsMiddleware(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		origin := r.Header.Get("Origin")
		if origin == "" {
			origin = "*"
		}
		w.Header().Set("Access-Control-Allow-Origin", origin)
		w.Header().Set("Access-Control-Allow-Credentials", "true")
		w.Header().Set("Access-Control-Allow-Headers", "Content-Type, Authorization")
		w.Header().Set("Access-Control-Allow-Methods", "GET, POST, PUT, PATCH, DELETE, OPTIONS")
		if r.Method == http.MethodOptions {
			w.WriteHeader(http.StatusNoContent)
			return
		}
		next.ServeHTTP(w, r)
	})
}

func writeJSON(w http.ResponseWriter, status int, v any) {
	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	w.WriteHeader(status)
	_ = json.NewEncoder(w).Encode(v)
}

func writeError(w http.ResponseWriter, status int, msg string) {
	writeJSON(w, status, map[string]string{"error": msg})
}

func (s *Server) handleHealth(w http.ResponseWriter, r *http.Request) {
	ctx, cancel := context.WithTimeout(r.Context(), 2*time.Second)
	defer cancel()
	status := "ok"
	dbOK := true
	if err := s.DB.Ping(ctx); err != nil {
		status = "degraded"
		dbOK = false
	}
	code := http.StatusOK
	if !dbOK {
		code = http.StatusServiceUnavailable
	}
	writeJSON(w, code, map[string]any{
		"status":  status,
		"service": "sameh-api",
		"version": "12.0.0-mvp",
		"db":      dbOK,
	})
}
