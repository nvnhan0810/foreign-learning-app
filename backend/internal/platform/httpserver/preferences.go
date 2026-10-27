package httpserver

import (
	"encoding/json"
	"fmt"
	"github.com/jackc/pgx/v5"
	"net/http"
)

func (h *LearningHandler) registerPreferences(m *http.ServeMux) {
	m.HandleFunc("GET /api/me/notification-settings", h.notificationSettings)
	m.HandleFunc("PUT /api/me/notification-settings", h.notificationSettings)
	for _, p := range []string{"/api/me/push-token", "/home/push-token"} {
		m.HandleFunc("POST "+p, h.pushToken)
		m.HandleFunc("DELETE "+p, h.pushToken)
	}
}
func (h *LearningHandler) notificationSettings(w http.ResponseWriter, r *http.Request) {
	u, ok := h.authorize(w, r)
	if !ok {
		return
	}
	if r.Method == "PUT" {
		var in struct {
			Enabled *bool `json:"vocab_quiz_push_enabled"`
		}
		if decode(w, r, &in) != nil || in.Enabled == nil {
			learningError(w, fmt.Errorf("vocab_quiz_push_enabled is required"))
			return
		}
		if _, e := h.Pool.Exec(r.Context(), `INSERT INTO user_notification_preferences(user_id,vocab_quiz_push_enabled,created_at,updated_at) VALUES($1,$2,now(),now()) ON CONFLICT(user_id) DO UPDATE SET vocab_quiz_push_enabled=excluded.vocab_quiz_push_enabled,updated_at=now()`, u.ID, *in.Enabled); e != nil {
			learningError(w, e)
			return
		}
	}
	var enabled bool
	if e := h.Pool.QueryRow(r.Context(), `SELECT COALESCE((SELECT vocab_quiz_push_enabled FROM user_notification_preferences WHERE user_id=$1),true)`, u.ID).Scan(&enabled); e != nil {
		learningError(w, e)
		return
	}
	jsonResponse(w, 200, map[string]any{"data": map[string]bool{"vocab_quiz_push_enabled": enabled}})
}
func (h *LearningHandler) pushToken(w http.ResponseWriter, r *http.Request) {
	u, ok := h.Auth.WriteUser(w, r)
	if !ok {
		return
	}
	var in struct {
		Token    string `json:"token"`
		Platform string `json:"platform"`
	}
	if decode(w, r, &in) != nil || in.Token == "" || len(in.Token) > 512 {
		learningError(w, fmt.Errorf("valid push token required"))
		return
	}
	if r.Method == "DELETE" {
		_, e := h.Pool.Exec(r.Context(), `DELETE FROM device_push_tokens WHERE user_id=$1 AND token=$2`, u.ID, in.Token)
		if e != nil {
			learningError(w, e)
			return
		}
	} else {
		if in.Platform != "android" && in.Platform != "ios" && in.Platform != "web" {
			learningError(w, fmt.Errorf("invalid push platform"))
			return
		}
		_, e := h.Pool.Exec(r.Context(), `INSERT INTO device_push_tokens(user_id,token,platform,created_at,updated_at) VALUES($1,$2,$3,now(),now()) ON CONFLICT(token) DO UPDATE SET user_id=excluded.user_id,platform=excluded.platform,updated_at=now()`, u.ID, in.Token, in.Platform)
		if e != nil {
			learningError(w, e)
			return
		}
	}
	jsonResponse(w, 200, map[string]string{"message": "Push token updated."})
}
func (h *LearningHandler) config(w http.ResponseWriter, r *http.Request) {
	var raw json.RawMessage
	e := h.Pool.QueryRow(r.Context(), `SELECT COALESCE(json_object_agg(key,value),'{}') FROM app_settings WHERE key IN ('app_name','extension_notice','vocab_quiz_push_enabled')`).Scan(&raw)
	if e != nil && e != pgx.ErrNoRows {
		learningError(w, e)
		return
	}
	settings := map[string]string{}
	_ = json.Unmarshal(raw, &settings)
	name := settings["app_name"]
	if name == "" {
		name = "FLC"
	}
	jsonResponse(w, 200, map[string]any{"app_name": name, "extension_notice": settings["extension_notice"], "vocab_quiz_push_enabled": settings["vocab_quiz_push_enabled"] != "0" && settings["vocab_quiz_push_enabled"] != "false", "sso_enabled": true, "sso_redirect_url": "/api/auth/sso/redirect", "vocab_quiz_reminder_schedule": map[string]string{"timezone": "Asia/Ho_Chi_Minh", "midday": "11:00", "evening": "20:00"}})
}
