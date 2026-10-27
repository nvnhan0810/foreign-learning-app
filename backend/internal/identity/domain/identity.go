package domain

import (
	"errors"
	"net/url"
	"strings"
)

var ErrUnauthorized = errors.New("unauthenticated")
var ErrDenied = errors.New("account is not allowed")
var ErrInvalid = errors.New("invalid authentication request")

type User struct {
	ID    int64  `json:"id"`
	Email string `json:"email"`
	Name  string `json:"name"`
}
type Claims struct {
	Email string `json:"email"`
	Name  string `json:"name"`
}
type Principal struct {
	User       User
	TokenID    int64
	FullAccess bool
}

func Allowed(email string, all bool, patterns []string) bool {
	if all {
		return true
	}
	email = strings.ToLower(strings.TrimSpace(email))
	for _, p := range patterns {
		p = strings.ToLower(strings.TrimSpace(p))
		if p == email || strings.HasPrefix(p, "*@") && len(p) > 2 && strings.HasSuffix(email, p[1:]) {
			return true
		}
	}
	return false
}
func ValidMobileRedirect(raw string) bool {
	if len(raw) > 2048 {
		return false
	}
	u, e := url.Parse(raw)
	if e != nil || u.Host == "" || u.User != nil || u.Fragment != "" {
		return false
	}
	host := strings.ToLower(u.Hostname())
	if u.Scheme == "flc" {
		return u.Host == "oauth-callback" && (u.Path == "" || u.Path == "/")
	}
	if u.Scheme != "http" && u.Scheme != "https" {
		return false
	}
	return host == "localhost" || host == "127.0.0.1" || u.Scheme == "https" && strings.HasSuffix(host, ".chromiumapp.org") && u.Port() == ""
}
func SafeNext(raw string) string {
	raw = strings.TrimSpace(raw)
	decoded, e := url.PathUnescape(raw)
	if e != nil {
		return ""
	}
	for _, s := range []string{raw, decoded} {
		if !strings.HasPrefix(s, "/") || strings.HasPrefix(s, "//") || strings.ContainsAny(s, "\\\r\n\x00") || strings.Contains(s, "://") {
			return ""
		}
	}
	u, e := url.Parse(raw)
	if e != nil || u.Host != "" {
		return ""
	}
	if strings.Contains(decoded, "//") || strings.Contains(decoded, "/../") || strings.HasSuffix(decoded, "/..") {
		return ""
	}
	for _, prefix := range []string{"/lookup", "/home", "/listening"} {
		if u.Path == prefix || strings.HasPrefix(u.Path, prefix+"/") {
			return raw
		}
	}
	return ""
}
