import 'package:dio/dio.dart';
import 'package:flc_mobile/config/app_config.dart';
import 'package:flc_mobile/core/api/api_client.dart';
import 'package:flc_mobile/core/api/flc_api.dart';
import 'package:flc_mobile/core/storage/token_storage.dart';
import 'package:flutter/services.dart';
import 'package:flutter_web_auth_2/flutter_web_auth_2.dart';

/// User dismissed the Google / OAuth browser sheet (back / cancel).
class AuthCanceledException implements Exception {
  const AuthCanceledException();

  @override
  String toString() => 'AuthCanceledException';
}

class AuthService {
  AuthService(this._tokenStorage, this._api);

  final TokenStorage _tokenStorage;
  final FlcApi _api;

  Future<bool> isLoggedIn() async {
    final token = await _tokenStorage.getToken();
    return token != null && token.isNotEmpty;
  }

  /// Returns `true` when login succeeded. Throws [AuthCanceledException] if the
  /// user cancels so the UI can retry without showing a PlatformException.
  Future<bool> loginWithGoogle() async {
    final startUrl =
        '$apiBaseUrl/auth/sso/redirect?redirect_uri=${Uri.encodeComponent(oauthRedirectUri)}';

    final String result;
    try {
      result = await FlutterWebAuth2.authenticate(
        url: startUrl,
        callbackUrlScheme: 'flc',
      );
    } on PlatformException catch (e) {
      if (e.code == 'CANCELED') {
        throw const AuthCanceledException();
      }
      rethrow;
    }

    final parsed = Uri.parse(result);
    final error = parsed.queryParameters['error'];
    if (error != null && error.isNotEmpty) {
      throw Exception(error);
    }

    final code = parsed.queryParameters['code'];
    final state = parsed.queryParameters['state'];
    if (code == null || code.isEmpty || state == null || state.isEmpty) {
      throw Exception('No authorization code received from SSO.');
    }

    final dio = Dio(BaseOptions(baseUrl: apiBaseUrl));
    final exchange = await dio.post<Map<String, dynamic>>(
      '/auth/sso/exchange',
      data: {
        'code': code,
        'state': state,
        'redirect_uri': oauthRedirectUri,
      },
    );

    final body = exchange.data;
    final token = body?['token'] as String?;
    if (token == null || token.isEmpty) {
      throw Exception('No token received from server.');
    }

    await _tokenStorage.save(
      token: token,
      email: body?['email'] as String?,
      name: body?['name'] as String?,
    );
    return true;
  }

  Future<String> mintWebviewHandoffUrl({String? next}) {
    return _api.mintWebviewHandoffUrl(next: next);
  }

  Future<void> logout() async {
    try {
      await _api.logout();
    } on ApiException {
      // Token may already be invalid; still clear local state.
    } catch (_) {}
    await _tokenStorage.clear();
  }
}
