package workspace

import (
	"testing"

	"github.com/google/uuid"
)

func TestAssertSiteInOrg(t *testing.T) {
	org := uuid.New()
	other := uuid.New()
	if err := AssertSiteInOrg(org, org); err != nil {
		t.Fatalf("same org: %v", err)
	}
	if err := AssertSiteInOrg(org, other); err != ErrSiteNotInWorkspace {
		t.Fatalf("expected ErrSiteNotInWorkspace, got %v", err)
	}
	if err := AssertSiteInOrg(uuid.Nil, org); err != ErrSiteNotInWorkspace {
		t.Fatalf("nil org: expected error")
	}
}

func TestFilterSitesByOrg(t *testing.T) {
	orgA := uuid.New()
	orgB := uuid.New()
	s1, s2, s3 := uuid.New(), uuid.New(), uuid.New()
	m := map[uuid.UUID]uuid.UUID{s1: orgA, s2: orgB, s3: orgA}
	got := FilterSitesByOrg(orgA, m)
	if len(got) != 2 {
		t.Fatalf("expected 2 sites, got %d", len(got))
	}
}
