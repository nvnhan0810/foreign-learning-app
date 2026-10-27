package application

import (
	"context"
	"flc/backend/internal/identity/domain"
	"strings"
)

type Provider interface {
	Exchange(context.Context, string, string, string) (domain.Claims, error)
	Reader(context.Context, string) (domain.Claims, error)
}
type Users interface {
	Allowed(context.Context, string) (bool, error)
	Upsert(context.Context, domain.Claims) (domain.User, error)
	ByID(context.Context, int64) (domain.User, error)
	IssueToken(context.Context, int64, string) (string, error)
	Bearer(context.Context, string) (domain.Principal, error)
	Revoke(context.Context, int64) error
}
type Challenge struct {
	Client   string `json:"client"`
	Redirect string `json:"redirect"`
	UserID   int64  `json:"user_id,omitempty"`
	Next     string `json:"next,omitempty"`
}
type Challenges interface {
	Put(context.Context, string, string, Challenge, int) error
	Pull(context.Context, string, string) (Challenge, error)
}
type CompleteLogin struct{ Client, Redirect, Code string }
type Commands struct {
	Provider Provider
	Users    Users
}

func (c Commands) Login(ctx context.Context, in CompleteLogin) (domain.User, error) {
	claims, err := c.Provider.Exchange(ctx, in.Client, in.Redirect, in.Code)
	if err != nil {
		return domain.User{}, domain.ErrUnauthorized
	}
	return c.Admit(ctx, claims)
}
func (c Commands) Admit(ctx context.Context, claims domain.Claims) (domain.User, error) {
	claims.Email = strings.ToLower(strings.TrimSpace(claims.Email))
	if claims.Email == "" {
		return domain.User{}, domain.ErrUnauthorized
	}
	allowed, err := c.Users.Allowed(ctx, claims.Email)
	if err != nil {
		return domain.User{}, err
	}
	if !allowed {
		return domain.User{}, domain.ErrDenied
	}
	if strings.TrimSpace(claims.Name) == "" {
		claims.Name = strings.Split(claims.Email, "@")[0]
	}
	return c.Users.Upsert(ctx, claims)
}
