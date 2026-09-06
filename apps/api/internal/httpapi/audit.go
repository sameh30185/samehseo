package httpapi

import (
	"encoding/json"
	"net/http"
	"strconv"
	"time"

	"github.com/google/uuid"
)

func (s *Server) handleListAudit(w http.ResponseWriter, r *http.Request) {
	userID := r.Context().Value(ctxUserID).(uuid.UUID)
	limit := 50
	if v := r.URL.Query().Get("limit"); v != "" {
		if n, err := strconv.Atoi(v); err == nil && n > 0 && n <= 200 {
			limit = n
		}
	}

	var isOwner bool
	_ = s.DB.QueryRow(r.Context(), `SELECT is_platform_owner FROM users WHERE id=$1`, userID).Scan(&isOwner)

	rows, err := s.DB.Query(r.Context(), `
		SELECT a.id, a.actor_user_id, a.organization_id, a.site_id, a.action,
		       a.resource_type, a.resource_id, a.metadata, a.ip_address, a.created_at
		FROM audit_events a
		WHERE (
			$1::boolean = true
			OR a.actor_user_id = $2
			OR a.organization_id IN (SELECT organization_id FROM memberships WHERE user_id=$2)
		)
		ORDER BY a.created_at DESC
		LIMIT $3`, isOwner, userID, limit)
	if err != nil {
		writeError(w, http.StatusInternalServerError, "audit query failed")
		return
	}
	defer rows.Close()

	type ev struct {
		ID             uuid.UUID       `json:"id"`
		ActorUserID    *uuid.UUID      `json:"actor_user_id"`
		OrganizationID *uuid.UUID      `json:"organization_id"`
		SiteID         *uuid.UUID      `json:"site_id"`
		Action         string          `json:"action"`
		ResourceType   string          `json:"resource_type"`
		ResourceID     string          `json:"resource_id"`
		Metadata       json.RawMessage `json:"metadata"`
		IPAddress      string          `json:"ip_address"`
		CreatedAt      time.Time       `json:"created_at"`
	}
	var events []ev
	for rows.Next() {
		var e ev
		if err := rows.Scan(&e.ID, &e.ActorUserID, &e.OrganizationID, &e.SiteID, &e.Action,
			&e.ResourceType, &e.ResourceID, &e.Metadata, &e.IPAddress, &e.CreatedAt); err != nil {
			writeError(w, http.StatusInternalServerError, "scan failed")
			return
		}
		if len(e.Metadata) == 0 {
			e.Metadata = json.RawMessage(`{}`)
		}
		events = append(events, e)
	}
	if events == nil {
		events = []ev{}
	}
	writeJSON(w, http.StatusOK, map[string]any{"events": events})
}
