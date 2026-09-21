import 'package:flc_mobile/core/api/api_client.dart';

/// Minimal API surface for the WebView shell (auth handoff + FCM).
class FlcApi {
  FlcApi(this._client);

  final ApiClient _client;

  Future<void> registerPushToken({
    required String token,
    required String platform,
  }) async {
    await _client.post(
      '/me/push-token',
      data: {'token': token, 'platform': platform},
    );
  }

  Future<void> deletePushToken(String token) async {
    await _client.delete(
      '/me/push-token',
      data: {'token': token},
    );
  }

  Future<void> logout() => _client.post('/logout');

  /// Mint a one-time URL that establishes a Laravel web session in the WebView.
  Future<String> mintWebviewHandoffUrl({String? next}) async {
    final json = await _client.post<Map<String, dynamic>>(
      '/auth/webview-session',
      data: {
        if (next != null && next.isNotEmpty) 'next': next,
      },
      parser: (d) => d as Map<String, dynamic>,
    );
    final url = json['handoff_url'] as String?;
    if (url == null || url.isEmpty) {
      throw ApiException('No handoff URL from server.');
    }
    return url;
  }
}
