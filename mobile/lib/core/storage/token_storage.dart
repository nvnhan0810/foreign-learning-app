import 'package:flutter_secure_storage/flutter_secure_storage.dart';

class TokenStorage {
  TokenStorage() : _storage = const FlutterSecureStorage();

  final FlutterSecureStorage _storage;

  static const _tokenKey = 'flc_token';
  static const _emailKey = 'flc_email';
  static const _nameKey = 'flc_name';

  Future<String?> getToken() => _storage.read(key: _tokenKey);

  Future<String?> getEmail() => _storage.read(key: _emailKey);

  Future<String?> getName() => _storage.read(key: _nameKey);

  Future<void> save({
    required String token,
    String? email,
    String? name,
  }) async {
    await _storage.write(key: _tokenKey, value: token);
    if (email != null) await _storage.write(key: _emailKey, value: email);
    if (name != null) await _storage.write(key: _nameKey, value: name);
  }

  Future<void> clear() async {
    await _storage.delete(key: _tokenKey);
    await _storage.delete(key: _emailKey);
    await _storage.delete(key: _nameKey);
  }
}
