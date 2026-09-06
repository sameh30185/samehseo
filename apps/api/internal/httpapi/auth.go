package httpapi

import (
	"context"
	"encoding/json"
	"net/http"
	"strings"
	"time"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"
	"github.com/pquerna/otp/totp"

	"github.com/sameh-ai/sameh-12.0/apps/api/internal/auth"
	"github.com/sameh-ai/sameh-12.0/apps/api/internal/security"
)

const sessionCookie = "sameh_session"

type ctxKey int

const ctxUserID ctxKey = 1

type registerReq struct {
	Email       string `json:"email"`
	Password    string `json:"password"`
	DisplayName string `json:"display_name"`
	OrgName     string `json:"org_name"`
}

type loginReq struct {
	Email    string `json:"email"`
	Password string `json:"password"`
	TOTPCode string `json:"totp_code"`
}

func (s *Server) handleRegister(w http.ResponseWriter, r *http.Request) {
	var req registerReq
	if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
		writeError(w, http.StatusBadRequest, "invalid json")
		return
	}
	req.Email = strings.TrimSpace(strings.ToLower(req.Email))
	if req.Email == "" || req.Password == "" {
		writeError(w, http.StatusBadRequest, "email and password required")
		return
	}
	if req.DisplayName == "" {
		req.DisplayName = strings.Split(req.Email, "@")[0]
	}
	if req.OrgName == "" {
		req.OrgName = req.DisplayName + " Org"
	}

	hash, err := auth.HashPassword(req.Password)
	if err != nil {
		writeError(w, http.StatusBadRequest, err.Error())
		return
	}

	ctx := r.Context()
	tx, err := s.DB.Begin(ctx)
	if err != nil {
		writeError(w, http.StatusInternalServerError, "db error")
		return
	}
	defer tx.Rollback(ctx)

	var userCount int
	if err := tx.QueryRow(ctx, `SELECT COUNT(*) FROM users`).Scan(&userCount); err != nil {
		writeError(w, http.StatusInternalServerError, "db error")
		return
	}
	isOwner := userCount == 0

	var userID uuid.UUID
	err = tx.QueryRow(ctx, `
		INSERT INTO users (email, password_hash, display_name, is_platform_owner)
		VALUES ($1, $2, $3, $4) RETURNING id`,
		req.Email, hash, req.DisplayName, isOwner,
	).Scan(&userID)
	if err != nil {
		if strings.Contains(err.Error(), "unique") || strings.Contains(err.Error(), "duplicate") {
			writeError(w, http.StatusConflict, "email already registered")
			return
		}
		writeError(w, http.StatusInternalServerError, "could not create user")
		return
	}

	slug := slugify(req.OrgName)
	var orgID uuid.UUID
	err = tx.QueryRow(ctx, `
		INSERT INTO organizations (name, slug) VALUES ($1, $2) RETURNING id`,
		req.OrgName, slug+"-"+userID.String()[:8],
	).Scan(&orgID)
	if err != nil {
		writeError(w, http.StatusInternalServerError, "could not create org")
		return
	}

	roleName := "org_admin"
	if isOwner {
		roleName = "platform_owner"
	}
	var roleID uuid.UUID
	if err := tx.QueryRow(ctx, `SELECT id FROM roles WHERE name=$1`, roleName).Scan(&roleID); err != nil {
		writeError(w, http.StatusInternalServerError, "role missing")
		return
	}
	if _, err := tx.Exec(ctx, `
		INSERT INTO memberships (user_id, organization_id, role_id) VALUES ($1,$2,$3)`,
		userID, orgID, roleID,
	); err != nil {
		writeError(w, http.StatusInternalServerError, "membership failed")
		return
	}

	if _, err := tx.Exec(ctx, `
		INSERT INTO audit_events (actor_user_id, organization_id, action, resource_type, resource_id, ip_address)
		VALUES ($1,$2,'user.register','user',$3,$4)`,
		userID, orgID, userID.String(), clientIP(r),
	); err != nil {
		writeError(w, http.StatusInternalServerError, "audit failed")
		return
	}

	if err := tx.Commit(ctx); err != nil {
		writeError(w, http.StatusInternalServerError, "commit failed")
		return
	}

	if err := s.createSession(w, r, userID); err != nil {
		writeError(w, http.StatusInternalServerError, "session failed")
		return
	}

	writeJSON(w, http.StatusCreated, map[string]any{
		"id":                 userID,
		"email":              req.Email,
		"display_name":       req.DisplayName,
		"is_platform_owner":  isOwner,
		"organization_id":    orgID,
		"message":            "registered; enable 2FA via POST /auth/totp/setup",
	})
}

func (s *Server) handleLogin(w http.ResponseWriter, r *http.Request) {
	var req loginReq
	if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
		writeError(w, http.StatusBadRequest, "invalid json")
		return
	}
	req.Email = strings.TrimSpace(strings.ToLower(req.Email))
	ctx := r.Context()

	var userID uuid.UUID
	var hash string
	var totpEnabled bool
	err := s.DB.QueryRow(ctx, `
		SELECT id, password_hash, totp_enabled FROM users WHERE email=$1`, req.Email,
	).Scan(&userID, &hash, &totpEnabled)
	if err != nil {
		writeError(w, http.StatusUnauthorized, "invalid credentials")
		return
	}
	ok, err := auth.VerifyPassword(hash, req.Password)
	if err != nil || !ok {
		writeError(w, http.StatusUnauthorized, "invalid credentials")
		return
	}

	if totpEnabled {
		if req.TOTPCode == "" {
			writeJSON(w, http.StatusUnauthorized, map[string]any{
				"error":         "totp_required",
				"totp_required": true,
			})
			return
		}
		var enc []byte
		err := s.DB.QueryRow(ctx, `SELECT secret_encrypted FROM totp_secrets WHERE user_id=$1`, userID).Scan(&enc)
		if err != nil {
			writeError(w, http.StatusUnauthorized, "totp not configured")
			return
		}
		secret, err := security.Decrypt(s.EncryptionKey, enc)
		if err != nil {
			writeError(w, http.StatusInternalServerError, "totp decrypt failed")
			return
		}
		if !totp.Validate(req.TOTPCode, string(secret)) {
			writeError(w, http.StatusUnauthorized, "invalid totp code")
			return
		}
	}

	if err := s.createSession(w, r, userID); err != nil {
		writeError(w, http.StatusInternalServerError, "session failed")
		return
	}
	_, _ = s.DB.Exec(ctx, `
		INSERT INTO audit_events (actor_user_id, action, resource_type, resource_id, ip_address)
		VALUES ($1,'user.login','user',$2,$3)`, userID, userID.String(), clientIP(r))

	writeJSON(w, http.StatusOK, map[string]any{"ok": true, "user_id": userID})
}

func (s *Server) handleLogout(w http.ResponseWriter, r *http.Request) {
	c, err := r.Cookie(sessionCookie)
	if err == nil && c.Value != "" {
		th := auth.HashSessionToken(c.Value)
		_, _ = s.DB.Exec(r.Context(), `DELETE FROM sessions WHERE token_hash=$1`, th)
	}
	http.SetCookie(w, &http.Cookie{
		Name:     sessionCookie,
		Value:    "",
		Path:     "/",
		MaxAge:   -1,
		HttpOnly: true,
		SameSite: http.SameSiteLaxMode,
		Secure:   s.CookieSecure,
	})
	writeJSON(w, http.StatusOK, map[string]bool{"ok": true})
}

func (s *Server) handleTOTPSetup(w http.ResponseWriter, r *http.Request) {
	userID := r.Context().Value(ctxUserID).(uuid.UUID)
	var email string
	if err := s.DB.QueryRow(r.Context(), `SELECT email FROM users WHERE id=$1`, userID).Scan(&email); err != nil {
		writeError(w, http.StatusInternalServerError, "user missing")
		return
	}
	key, err := totp.Generate(totp.GenerateOpts{
		Issuer:      "SAMEH SEO Platform",
		AccountName: email,
	})
	if err != nil {
		writeError(w, http.StatusInternalServerError, "totp generate failed")
		return
	}
	enc, err := security.Encrypt(s.EncryptionKey, []byte(key.Secret()))
	if err != nil {
		writeError(w, http.StatusInternalServerError, "encrypt failed: set ENCRYPTION_KEY")
		return
	}
	_, err = s.DB.Exec(r.Context(), `
		INSERT INTO totp_secrets (user_id, secret_encrypted)
		VALUES ($1, $2)
		ON CONFLICT (user_id) DO UPDATE SET secret_encrypted=EXCLUDED.secret_encrypted, verified_at=NULL`,
		userID, enc,
	)
	if err != nil {
		writeError(w, http.StatusInternalServerError, "store secret failed")
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{
		"secret":  key.Secret(),
		"otpauth": key.URL(),
		"message": "scan otpauth URL then POST /auth/totp/verify with code",
	})
}

func (s *Server) handleTOTPVerify(w http.ResponseWriter, r *http.Request) {
	userID := r.Context().Value(ctxUserID).(uuid.UUID)
	var body struct {
		Code string `json:"code"`
	}
	if err := json.NewDecoder(r.Body).Decode(&body); err != nil || body.Code == "" {
		writeError(w, http.StatusBadRequest, "code required")
		return
	}
	var enc []byte
	err := s.DB.QueryRow(r.Context(), `SELECT secret_encrypted FROM totp_secrets WHERE user_id=$1`, userID).Scan(&enc)
	if err != nil {
		writeError(w, http.StatusBadRequest, "run /auth/totp/setup first")
		return
	}
	secret, err := security.Decrypt(s.EncryptionKey, enc)
	if err != nil {
		writeError(w, http.StatusInternalServerError, "decrypt failed")
		return
	}
	if !totp.Validate(body.Code, string(secret)) {
		writeError(w, http.StatusUnauthorized, "invalid code")
		return
	}
	_, err = s.DB.Exec(r.Context(), `
		UPDATE totp_secrets SET verified_at=now() WHERE user_id=$1;
		UPDATE users SET totp_enabled=true, updated_at=now() WHERE id=$1`, userID)
	if err != nil {
		writeError(w, http.StatusInternalServerError, "enable failed")
		return
	}
	_, _ = s.DB.Exec(r.Context(), `
		INSERT INTO audit_events (actor_user_id, action, resource_type, resource_id, ip_address)
		VALUES ($1,'user.totp_enabled','user',$2,$3)`, userID, userID.String(), clientIP(r))
	writeJSON(w, http.StatusOK, map[string]bool{"totp_enabled": true})
}

func (s *Server) handleMe(w http.ResponseWriter, r *http.Request) {
	userID := r.Context().Value(ctxUserID).(uuid.UUID)
	var email, displayName string
	var isOwner, totpEnabled bool
	err := s.DB.QueryRow(r.Context(), `
		SELECT email, display_name, is_platform_owner, totp_enabled FROM users WHERE id=$1`, userID,
	).Scan(&email, &displayName, &isOwner, &totpEnabled)
	if err != nil {
		writeError(w, http.StatusInternalServerError, "user missing")
		return
	}
	rows, err := s.DB.Query(r.Context(), `
		SELECT o.id, o.name, o.slug, roles.name
		FROM memberships m
		JOIN organizations o ON o.id = m.organization_id
		JOIN roles ON roles.id = m.role_id
		WHERE m.user_id=$1`, userID)
	if err != nil {
		writeError(w, http.StatusInternalServerError, "memberships failed")
		return
	}
	defer rows.Close()
	type mem struct {
		OrgID   uuid.UUID `json:"organization_id"`
		OrgName string    `json:"organization_name"`
		Slug    string    `json:"slug"`
		Role    string    `json:"role"`
	}
	var memberships []mem
	for rows.Next() {
		var m mem
		if err := rows.Scan(&m.OrgID, &m.OrgName, &m.Slug, &m.Role); err != nil {
			writeError(w, http.StatusInternalServerError, "scan failed")
			return
		}
		memberships = append(memberships, m)
	}
	if memberships == nil {
		memberships = []mem{}
	}
	writeJSON(w, http.StatusOK, map[string]any{
		"id":                userID,
		"email":             email,
		"display_name":      displayName,
		"is_platform_owner": isOwner,
		"totp_enabled":      totpEnabled,
		"memberships":       memberships,
	})
}

func (s *Server) createSession(w http.ResponseWriter, r *http.Request, userID uuid.UUID) error {
	raw, hash, err := auth.NewSessionToken()
	if err != nil {
		return err
	}
	exp := time.Now().UTC().Add(s.SessionTTL)
	_, err = s.DB.Exec(r.Context(), `
		INSERT INTO sessions (user_id, token_hash, expires_at, user_agent, ip_address)
		VALUES ($1,$2,$3,$4,$5)`,
		userID, hash, exp, r.UserAgent(), clientIP(r),
	)
	if err != nil {
		return err
	}
	http.SetCookie(w, &http.Cookie{
		Name:     sessionCookie,
		Value:    raw,
		Path:     "/",
		Expires:  exp,
		HttpOnly: true,
		SameSite: http.SameSiteLaxMode,
		Secure:   s.CookieSecure,
	})
	return nil
}

func (s *Server) requireAuth(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		c, err := r.Cookie(sessionCookie)
		if err != nil || c.Value == "" {
			writeError(w, http.StatusUnauthorized, "not authenticated")
			return
		}
		th := auth.HashSessionToken(c.Value)
		var userID uuid.UUID
		var exp time.Time
		err = s.DB.QueryRow(r.Context(), `
			SELECT user_id, expires_at FROM sessions WHERE token_hash=$1`, th,
		).Scan(&userID, &exp)
		if err != nil {
			if err == pgx.ErrNoRows {
				writeError(w, http.StatusUnauthorized, "not authenticated")
				return
			}
			writeError(w, http.StatusInternalServerError, "session lookup failed")
			return
		}
		if time.Now().UTC().After(exp) {
			_, _ = s.DB.Exec(r.Context(), `DELETE FROM sessions WHERE token_hash=$1`, th)
			writeError(w, http.StatusUnauthorized, "session expired")
			return
		}
		_, _ = s.DB.Exec(r.Context(), `UPDATE sessions SET last_seen_at=now() WHERE token_hash=$1`, th)
		ctx := context.WithValue(r.Context(), ctxUserID, userID)
		next.ServeHTTP(w, r.WithContext(ctx))
	})
}

func clientIP(r *http.Request) string {
	if xff := r.Header.Get("X-Forwarded-For"); xff != "" {
		parts := strings.Split(xff, ",")
		return strings.TrimSpace(parts[0])
	}
	host := r.RemoteAddr
	if i := strings.LastIndex(host, ":"); i >= 0 {
		return host[:i]
	}
	return host
}

func slugify(s string) string {
	s = strings.ToLower(strings.TrimSpace(s))
	var b strings.Builder
	for _, r := range s {
		if (r >= 'a' && r <= 'z') || (r >= '0' && r <= '9') {
			b.WriteRune(r)
		} else if r == ' ' || r == '-' || r == '_' {
			b.WriteByte('-')
		}
	}
	out := b.String()
	if out == "" {
		return "org"
	}
	return out
}
