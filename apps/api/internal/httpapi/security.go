package httpapi

import (
	"encoding/json"
	"net/http"
	"time"

	"github.com/google/uuid"
)

func (s *Server) handleGetKillSwitch(w http.ResponseWriter, r *http.Request) {
	var id uuid.UUID
	var enabled bool
	var reason string
	var updatedAt time.Time
	err := s.DB.QueryRow(r.Context(), `
		SELECT id, enabled, reason, updated_at FROM kill_switches WHERE scope='global' LIMIT 1`,
	).Scan(&id, &enabled, &reason, &updatedAt)
	if err != nil {
		writeError(w, http.StatusInternalServerError, "kill switch missing")
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{
		"id":         id,
		"scope":      "global",
		"enabled":    enabled,
		"reason":     reason,
		"updated_at": updatedAt,
	})
}

func (s *Server) handleSetKillSwitch(w http.ResponseWriter, r *http.Request) {
	userID := r.Context().Value(ctxUserID).(uuid.UUID)
	var isOwner bool
	if err := s.DB.QueryRow(r.Context(), `SELECT is_platform_owner FROM users WHERE id=$1`, userID).Scan(&isOwner); err != nil || !isOwner {
		writeError(w, http.StatusForbidden, "platform owner required")
		return
	}
	var body struct {
		Enabled bool   `json:"enabled"`
		Reason  string `json:"reason"`
	}
	if err := json.NewDecoder(r.Body).Decode(&body); err != nil {
		writeError(w, http.StatusBadRequest, "invalid json")
		return
	}
	_, err := s.DB.Exec(r.Context(), `
		UPDATE kill_switches SET enabled=$1, reason=$2, set_by_user_id=$3, updated_at=now()
		WHERE scope='global'`, body.Enabled, body.Reason, userID)
	if err != nil {
		writeError(w, http.StatusInternalServerError, "update failed")
		return
	}
	action := "kill_switch.disable"
	if body.Enabled {
		action = "kill_switch.enable"
	}
	_, _ = s.DB.Exec(r.Context(), `
		INSERT INTO audit_events (actor_user_id, action, resource_type, metadata, ip_address)
		VALUES ($1,$2,'kill_switch',$3,$4)`,
		userID, action, mustJSON(map[string]any{"enabled": body.Enabled, "reason": body.Reason}), clientIP(r))
	s.handleGetKillSwitch(w, r)
}

func mustJSON(v any) []byte {
	b, _ := json.Marshal(v)
	return b
}
