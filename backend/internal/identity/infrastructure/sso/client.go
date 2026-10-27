package sso

import (
	"context"
	"encoding/json"
	"flc/backend/internal/identity/domain"
	"fmt"
	"io"
	"net/http"
	"net/mail"
	"net/url"
	"strings"
)

type Client struct {
	BaseURL *url.URL
	Secret  string
	HTTP    *http.Client
}

func (c Client) Exchange(ctx context.Context, client, redirect, code string) (domain.Claims, error) {
	form := url.Values{"grant_type": {"authorization_code"}, "client_id": {client}, "client_secret": {c.Secret}, "code": {code}, "redirect_uri": {redirect}}
	req, e := http.NewRequestWithContext(ctx, "POST", c.BaseURL.ResolveReference(&url.URL{Path: "/api/auth/sso/token"}).String(), strings.NewReader(form.Encode()))
	if e != nil {
		return domain.Claims{}, e
	}
	req.Header.Set("Content-Type", "application/x-www-form-urlencoded")
	req.Header.Set("Accept", "application/json")
	return c.send(req, false)
}
func (c Client) Reader(ctx context.Context, token string) (domain.Claims, error) {
	req, e := http.NewRequestWithContext(ctx, "GET", c.BaseURL.ResolveReference(&url.URL{Path: "/api/v1/me"}).String(), nil)
	if e != nil {
		return domain.Claims{}, e
	}
	req.Header.Set("Authorization", "Bearer "+token)
	req.Header.Set("Accept", "application/json")
	return c.send(req, true)
}
func (c Client) send(req *http.Request, envelope bool) (domain.Claims, error) {
	resp, e := c.HTTP.Do(req)
	if e != nil {
		return domain.Claims{}, e
	}
	defer resp.Body.Close()
	if resp.StatusCode != 200 {
		return domain.Claims{}, fmt.Errorf("identity provider returned %d", resp.StatusCode)
	}
	var raw json.RawMessage
	if e = json.NewDecoder(io.LimitReader(resp.Body, 1<<20)).Decode(&raw); e != nil {
		return domain.Claims{}, e
	}
	if envelope {
		var wrapped struct {
			User json.RawMessage `json:"user"`
		}
		if json.Unmarshal(raw, &wrapped) == nil && len(wrapped.User) > 0 {
			raw = wrapped.User
		}
	}
	var claims domain.Claims
	if e = json.Unmarshal(raw, &claims); e != nil {
		return claims, e
	}
	claims.Email = strings.ToLower(strings.TrimSpace(claims.Email))
	parsed, e := mail.ParseAddress(claims.Email)
	if e != nil || parsed.Address != claims.Email {
		return domain.Claims{}, domain.ErrUnauthorized
	}
	return claims, nil
}
