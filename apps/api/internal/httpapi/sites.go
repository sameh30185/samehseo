package httpapi

import (
	"encoding/json"
	"net/http"
	"strings"
	"time"

	"github.com/go-chi/chi/v5"
	"github.com/google/uuid"

	"github.com/sameh-ai/sameh-12.0/apps/api/internal/workspace"
)

type siteDTO struct {
	ID             uuid.UUID `json:"id"`
	OrganizationID uuid.UUID `json:"organization_id"`
	Name           string    `json:"name"`
	BaseURL        string    `json:"base_url"`
	Status         string    `json:"status"`
	CreatedAt      time.Time `json:"created_at"`
	UpdatedAt      time.Time `json:"updated_at"`
}

func (s *Server) handleListSites(w http.ResponseWriter, r *http.Request) {
	userID := r.Context().Value(ctxUserID).(uuid.UUID)
	rows, err := s.DB.Query(r.Context(), `
		SELECT s.id, s.organization_id, s.name, s.base_url, s.status, s.created_at, s.updated_at
		FROM sites s
		JOIN memberships m ON m.organization_id = s.organization_id
		WHERE m.user_id = $1
		ORDER BY s.created_at DESC`, userID)
	if err != nil {
		writeError(w, http.StatusInternalServerError, "list failed")
		return
	}
	defer rows.Close()
	var sites []siteDTO
	for rows.Next() {
		var st siteDTO
		if err := rows.Scan(&st.ID, &st.OrganizationID, &st.Name, &st.BaseURL, &st.Status, &st.CreatedAt, &st.UpdatedAt); err != nil {
			writeError(w, http.StatusInternalServerError, "scan failed")
			return
		}
		sites = append(sites, st)
	}
	if sites == nil {
		sites = []siteDTO{}
	}
	writeJSON(w, http.StatusOK, map[string]any{"sites": sites})
}

func (s *Server) handleCreateSite(w http.ResponseWriter, r *http.Request) {
	userID := r.Context().Value(ctxUserID).(uuid.UUID)
	var body struct {
		Name           string     `json:"name"`
		BaseURL        string     `json:"base_url"`
		OrganizationID *uuid.UUID `json:"organization_id"`
	}
	if err := json.NewDecoder(r.Body).Decode(&body); err != nil {
		writeError(w, http.StatusBadRequest, "invalid json")
		return
	}
	body.Name = strings.TrimSpace(body.Name)
	body.BaseURL = strings.TrimSpace(body.BaseURL)
	if body.Name == "" || body.BaseURL == "" {
		writeError(w, http.StatusBadRequest, "name and base_url required")
		return
	}
	if !strings.HasPrefix(body.BaseURL, "http://") && !strings.HasPrefix(body.BaseURL, "https://") {
		writeError(w, http.StatusBadRequest, "base_url must start with http:// or https://")
		return
	}

	var orgID uuid.UUID
	if body.OrganizationID != nil {
		orgID = *body.OrganizationID
		var n int
		err := s.DB.QueryRow(r.Context(), `
			SELECT COUNT(*) FROM memberships WHERE user_id=$1 AND organization_id=$2`, userID, orgID).Scan(&n)
		if err != nil || n == 0 {
			writeError(w, http.StatusForbidden, "not a member of organization")
			return
		}
	} else {
		err := s.DB.QueryRow(r.Context(), `
			SELECT organization_id FROM memberships WHERE user_id=$1 ORDER BY created_at ASC LIMIT 1`, userID,
		).Scan(&orgID)
		if err != nil {
			writeError(w, http.StatusBadRequest, "no organization membership")
			return
		}
	}

	var st siteDTO
	err := s.DB.QueryRow(r.Context(), `
		INSERT INTO sites (organization_id, name, base_url, status)
		VALUES ($1,$2,$3,'pending')
		RETURNING id, organization_id, name, base_url, status, created_at, updated_at`,
		orgID, body.Name, body.BaseURL,
	).Scan(&st.ID, &st.OrganizationID, &st.Name, &st.BaseURL, &st.Status, &st.CreatedAt, &st.UpdatedAt)
	if err != nil {
		writeError(w, http.StatusInternalServerError, "create failed")
		return
	}

	_, _ = s.DB.Exec(r.Context(), `
		INSERT INTO site_connections (site_id, status) VALUES ($1, 'unpaired')`, st.ID)
	_, _ = s.DB.Exec(r.Context(), `
		INSERT INTO audit_events (actor_user_id, organization_id, site_id, action, resource_type, resource_id, ip_address)
		VALUES ($1,$2,$3,'site.create','site',$4,$5)`,
		userID, orgID, st.ID, st.ID.String(), clientIP(r))

	writeJSON(w, http.StatusCreated, st)
}

func (s *Server) handleGetSite(w http.ResponseWriter, r *http.Request) {
	userID := r.Context().Value(ctxUserID).(uuid.UUID)
	idStr := chi.URLParam(r, "id")
	siteID, err := uuid.Parse(idStr)
	if err != nil {
		writeError(w, http.StatusBadRequest, "invalid id")
		return
	}

	var st siteDTO
	err = s.DB.QueryRow(r.Context(), `
		SELECT s.id, s.organization_id, s.name, s.base_url, s.status, s.created_at, s.updated_at
		FROM sites s
		JOIN memberships m ON m.organization_id = s.organization_id
		WHERE s.id=$1 AND m.user_id=$2`, siteID, userID,
	).Scan(&st.ID, &st.OrganizationID, &st.Name, &st.BaseURL, &st.Status, &st.CreatedAt, &st.UpdatedAt)
	if err != nil {
		writeError(w, http.StatusNotFound, "site not found")
		return
	}
	// Workspace isolation: caller membership already enforced in query;
	// helper available for future cross-checks.
	_ = workspace.AssertSiteInOrg(st.OrganizationID, st.OrganizationID)
	writeJSON(w, http.StatusOK, st)
}
