package httpserver

import (
	"crypto/subtle"
	"errors"
	"flc/backend/internal/identity/application"
	"flc/backend/internal/identity/domain"
	"flc/backend/internal/identity/infrastructure/postgres"
	"flc/backend/internal/identity/infrastructure/session"
	"net/http"
	"net/url"
	"strings"
)

type AuthHandler struct {
	Commands       application.Commands
	Challenges     application.Challenges
	Sessions       session.Manager
	PublicURL, IDP *url.URL
}

func (h *AuthHandler) Register(m *http.ServeMux) {
	m.HandleFunc("GET /auth/sso", h.redirectWeb)
	m.HandleFunc("GET /auth/sso/callback", h.callback)
	m.HandleFunc("GET /api/auth/sso/redirect", h.redirectMobile)
	m.HandleFunc("POST /api/auth/sso/exchange", h.exchange)
	m.HandleFunc("POST /api/auth/webview-session", h.ticket)
	m.HandleFunc("POST /api/auth/bridge/ticket", h.ticket)
	m.HandleFunc("GET /auth/webview-handoff", h.consume)
	m.HandleFunc("GET /auth/bridge/consume", h.consume)
	m.HandleFunc("GET /api/user", h.apiUser)
	m.HandleFunc("POST /api/logout", h.apiLogout)
	m.HandleFunc("POST /logout", h.logout)
	m.HandleFunc("GET /login", h.login)
}
func authError(w http.ResponseWriter, e error) {
	status := 500
	message := "Authentication unavailable."
	if errors.Is(e, domain.ErrUnauthorized) {
		status = 401
		message = "Session invalid or expired."
	}
	if errors.Is(e, domain.ErrDenied) {
		status = 403
		message = "This account is not allowed."
	}
	if errors.Is(e, domain.ErrInvalid) {
		status = 422
		message = "Invalid authentication request."
	}
	jsonResponse(w, status, map[string]string{"message": message})
}
func bearer(r *http.Request) string {
	value := r.Header.Get("Authorization")
	if !strings.HasPrefix(value, "Bearer ") {
		return ""
	}
	return strings.TrimSpace(strings.TrimPrefix(value, "Bearer "))
}
func (h *AuthHandler) User(r *http.Request) (domain.User, error) {
	if token := bearer(r); token != "" {
		p, e := h.Commands.Users.Bearer(r.Context(), token)
		return p.User, e
	}
	d, e := h.Sessions.Read(r)
	if e != nil {
		return domain.User{}, e
	}
	return h.Sessions.User(r.Context(), d, h.Commands.Users)
}
func (h *AuthHandler) UserID(r *http.Request) (int64, error) { u, e := h.User(r); return u.ID, e }
func (h *AuthHandler) WriteUser(w http.ResponseWriter, r *http.Request) (domain.User, bool) {
	if token := bearer(r); token != "" {
		u, e := h.User(r)
		if e != nil {
			authError(w, e)
			return u, false
		}
		return u, true
	}
	d, e := h.Sessions.Read(r)
	if e != nil {
		authError(w, e)
		return domain.User{}, false
	}
	if !h.Sessions.CSRF(r, d) {
		jsonResponse(w, 419, map[string]string{"message": "CSRF token mismatch."})
		return domain.User{}, false
	}
	u, e := h.Sessions.User(r.Context(), d, h.Commands.Users)
	if e != nil {
		authError(w, e)
		return u, false
	}
	return u, true
}
func (h *AuthHandler) start(w http.ResponseWriter, r *http.Request, client, redirect string, bind bool) {
	state, e := postgres.Random()
	if e != nil {
		authError(w, e)
		return
	}
	if e = h.Challenges.Put(r.Context(), "state", state, application.Challenge{Client: client, Redirect: redirect}, 600); e != nil {
		authError(w, e)
		return
	}
	if bind {
		if e = h.Sessions.Cookie(w, h.Sessions.BindingName(), state, 600, true); e != nil {
			authError(w, e)
			return
		}
	}
	target := h.IDP.ResolveReference(&url.URL{Path: "/auth/sso/authorize"})
	target.RawQuery = url.Values{"client_id": {client}, "redirect_uri": {redirect}, "response_type": {"code"}, "state": {state}}.Encode()
	http.Redirect(w, r, target.String(), 302)
}
func (h *AuthHandler) redirectWeb(w http.ResponseWriter, r *http.Request) {
	h.start(w, r, "flc-web", h.PublicURL.ResolveReference(&url.URL{Path: "/auth/sso/callback"}).String(), true)
}
func (h *AuthHandler) redirectMobile(w http.ResponseWriter, r *http.Request) {
	redirect := r.URL.Query().Get("redirect_uri")
	if !domain.ValidMobileRedirect(redirect) {
		authError(w, domain.ErrInvalid)
		return
	}
	h.start(w, r, "flc-mobile", redirect, false)
}
func (h *AuthHandler) callback(w http.ResponseWriter, r *http.Request) {
	state, code := r.URL.Query().Get("state"), r.URL.Query().Get("code")
	binding, e := h.Sessions.CookieValue(r, h.Sessions.BindingName())
	if e != nil || len(state) > 128 || state == "" || code == "" || len(code) > 128 || subtle.ConstantTimeCompare([]byte(binding), []byte(state)) != 1 {
		http.Redirect(w, r, "/login?error=sso", 303)
		return
	}
	pending, e := h.Challenges.Pull(r.Context(), "state", state)
	if e != nil || pending.Client != "flc-web" {
		http.Redirect(w, r, "/login?error=sso", 303)
		return
	}
	u, e := h.Commands.Login(r.Context(), application.CompleteLogin{Client: pending.Client, Redirect: pending.Redirect, Code: code})
	if e != nil {
		http.Redirect(w, r, "/login?error=sso", 303)
		return
	}
	if _, e = h.Sessions.Create(w, r, &u); e != nil {
		authError(w, e)
		return
	}
	http.SetCookie(w, &http.Cookie{Name: h.Sessions.BindingName(), Path: "/", Secure: h.Sessions.Secure, HttpOnly: true, MaxAge: -1, SameSite: http.SameSiteLaxMode})
	http.Redirect(w, r, "/home/lookup", 303)
}
func (h *AuthHandler) exchange(w http.ResponseWriter, r *http.Request) {
	var in struct {
		Code     string `json:"code"`
		State    string `json:"state"`
		Redirect string `json:"redirect_uri"`
	}
	if decode(w, r, &in) != nil || !domain.ValidMobileRedirect(in.Redirect) || in.State == "" || len(in.State) > 128 || in.Code == "" || len(in.Code) > 128 {
		authError(w, domain.ErrInvalid)
		return
	}
	pending, e := h.Challenges.Pull(r.Context(), "state", in.State)
	if e != nil || pending.Client != "flc-mobile" || pending.Redirect != in.Redirect {
		authError(w, domain.ErrUnauthorized)
		return
	}
	u, e := h.Commands.Login(r.Context(), application.CompleteLogin{Client: pending.Client, Redirect: pending.Redirect, Code: in.Code})
	if e != nil {
		authError(w, e)
		return
	}
	token, e := h.Commands.Users.IssueToken(r.Context(), u.ID, "flc-mobile")
	if e != nil {
		authError(w, e)
		return
	}
	jsonResponse(w, 200, map[string]string{"token": token, "email": u.Email, "name": u.Name})
}
func (h *AuthHandler) ticket(w http.ResponseWriter, r *http.Request) {
	token := bearer(r)
	if token == "" {
		authError(w, domain.ErrUnauthorized)
		return
	}
	p, e := h.Commands.Users.Bearer(r.Context(), token)
	if e == nil && !p.FullAccess {
		authError(w, domain.ErrDenied)
		return
	}
	if errors.Is(e, domain.ErrUnauthorized) {
		claims, err := h.Commands.Provider.Reader(r.Context(), token)
		if err != nil {
			authError(w, domain.ErrUnauthorized)
			return
		}
		p.User, e = h.Commands.Admit(r.Context(), claims)
	}
	if e != nil {
		authError(w, e)
		return
	}
	var in struct {
		Next string `json:"next"`
	}
	if decode(w, r, &in) != nil {
		authError(w, domain.ErrInvalid)
		return
	}
	next := domain.SafeNext(in.Next)
	ticket, e := postgres.Random()
	if e != nil {
		authError(w, e)
		return
	}
	if e = h.Challenges.Put(r.Context(), "ticket", ticket, application.Challenge{UserID: p.User.ID, Next: next}, 60); e != nil {
		authError(w, e)
		return
	}
	handoff := h.PublicURL.ResolveReference(&url.URL{Path: "/auth/webview-handoff", RawQuery: url.Values{"code": {ticket}, "flc_app": {"1"}}.Encode()})
	consume := h.PublicURL.ResolveReference(&url.URL{Path: "/auth/bridge/consume", RawQuery: url.Values{"ticket": {ticket}, "next": {next}}.Encode()})
	jsonResponse(w, 200, map[string]any{"ticket": ticket, "expires_in": 60, "handoff_url": handoff.String(), "consume_url": consume.String()})
}
func (h *AuthHandler) consume(w http.ResponseWriter, r *http.Request) {
	ticket := r.URL.Query().Get("ticket")
	if ticket == "" {
		ticket = r.URL.Query().Get("code")
	}
	if len(ticket) > 128 || ticket == "" {
		http.Redirect(w, r, "/login?error=ticket", 303)
		return
	}
	p, e := h.Challenges.Pull(r.Context(), "ticket", ticket)
	if e != nil || p.UserID <= 0 {
		http.Redirect(w, r, "/login?error=ticket", 303)
		return
	}
	u, e := h.Commands.Users.ByID(r.Context(), p.UserID)
	if e != nil {
		authError(w, e)
		return
	}
	if _, e = h.Sessions.Create(w, r, &u); e != nil {
		authError(w, e)
		return
	}
	if r.URL.Query().Get("flc_app") == "1" {
		http.SetCookie(w, &http.Cookie{Name: "flc_app", Value: "1", Path: "/", Secure: h.Sessions.Secure, HttpOnly: true, SameSite: http.SameSiteLaxMode, MaxAge: 86400 * 30})
	}
	next := domain.SafeNext(r.URL.Query().Get("next"))
	if next == "" {
		next = p.Next
	}
	if next == "" {
		next = "/home/lookup"
	}
	http.Redirect(w, r, next, 303)
}
func (h *AuthHandler) apiUser(w http.ResponseWriter, r *http.Request) {
	u, e := h.User(r)
	if e != nil {
		authError(w, e)
		return
	}
	jsonResponse(w, 200, map[string]any{"user": u})
}
func (h *AuthHandler) apiLogout(w http.ResponseWriter, r *http.Request) {
	if bearer(r) == "" {
		h.logout(w, r)
		return
	}
	p, e := h.Commands.Users.Bearer(r.Context(), bearer(r))
	if e != nil {
		authError(w, e)
		return
	}
	if e = h.Commands.Users.Revoke(r.Context(), p.TokenID); e != nil {
		authError(w, e)
		return
	}
	jsonResponse(w, 200, map[string]string{"message": "Signed out."})
}
func (h *AuthHandler) logout(w http.ResponseWriter, r *http.Request) {
	u, ok := h.WriteUser(w, r)
	if !ok {
		return
	}
	if e := h.Sessions.Delete(w, r); e != nil {
		authError(w, e)
		return
	}
	_ = u
	http.Redirect(w, r, "/login?flc_logout=1", 303)
}
func (h *AuthHandler) login(w http.ResponseWriter, r *http.Request) {
	if _, e := h.User(r); e == nil {
		http.Redirect(w, r, "/home/lookup", 303)
		return
	}
	if _, e := h.Sessions.Read(r); e != nil {
		if _, e = h.Sessions.Create(w, r, nil); e != nil {
			authError(w, e)
			return
		}
	}
	flash := map[string]any{}
	if r.URL.Query().Get("error") != "" {
		flash["error"] = "Sign-in failed or expired. Please try again."
	}
	h.Page(w, r, "Auth/Login", map[string]any{"ssoUrl": "/auth/sso", "flash": flash}, nil)
}
func (h *AuthHandler) Page(w http.ResponseWriter, r *http.Request, component string, props map[string]any, user *domain.User) {
	if props == nil {
		props = map[string]any{}
	}
	props["auth"] = map[string]any{"user": user}
	props["errors"] = map[string]string{}
	if _, ok := props["flash"]; !ok {
		props["flash"] = map[string]any{}
	}
	cookie, _ := r.Cookie("flc_app")
	props["isFlcApp"] = r.URL.Query().Get("flc_app") == "1" || cookie != nil && cookie.Value == "1" || strings.Contains(r.UserAgent(), "FLCApp/")
	props["flcTheme"] = r.URL.Query().Get("flc_theme")
	w.Header().Set("X-Inertia", "true")
	w.Header().Set("Vary", "X-Inertia")
	w.Header().Set("Cache-Control", "no-store")
	jsonResponse(w, 200, map[string]any{"component": component, "props": props, "url": r.URL.RequestURI(), "version": nil, "clearHistory": false, "encryptHistory": false, "rescuedProps": []string{}, "flash": map[string]any{}})
}
func (h *AuthHandler) PageUser(w http.ResponseWriter, r *http.Request) (domain.User, bool) {
	u, e := h.User(r)
	if e != nil {
		if errors.Is(e, domain.ErrUnauthorized) {
			http.Redirect(w, r, "/login", 303)
		} else {
			authError(w, e)
		}
		return u, false
	}
	return u, true
}
