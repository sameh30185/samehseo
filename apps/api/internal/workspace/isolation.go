package workspace

import (
	"errors"

	"github.com/google/uuid"
)

var ErrSiteNotInWorkspace = errors.New("site not in workspace")

// AssertSiteInOrg returns ErrSiteNotInWorkspace when siteOrgID does not match orgID.
// Used for multi-tenant site isolation stubs.
func AssertSiteInOrg(orgID, siteOrgID uuid.UUID) error {
	if orgID == uuid.Nil || siteOrgID == uuid.Nil {
		return ErrSiteNotInWorkspace
	}
	if orgID != siteOrgID {
		return ErrSiteNotInWorkspace
	}
	return nil
}

// FilterSitesByOrg returns only sites belonging to orgID.
func FilterSitesByOrg(orgID uuid.UUID, siteOrgIDs map[uuid.UUID]uuid.UUID) []uuid.UUID {
	var out []uuid.UUID
	for siteID, so := range siteOrgIDs {
		if so == orgID {
			out = append(out, siteID)
		}
	}
	return out
}
