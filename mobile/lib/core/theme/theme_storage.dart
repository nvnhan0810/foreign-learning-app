import 'package:flutter_secure_storage/flutter_secure_storage.dart';

enum ThemePreference {
  light,
  dark,
  system;

  static ThemePreference fromString(String? value) {
    return switch (value) {
      'light' => ThemePreference.light,
      'dark' => ThemePreference.dark,
      _ => ThemePreference.system,
    };
  }

  String get storageValue => name;
}

class ThemeStorage {
  ThemeStorage() : _storage = const FlutterSecureStorage();

  final FlutterSecureStorage _storage;
  static const _key = 'flc_theme_mode';

  Future<ThemePreference> get() async {
    final value = await _storage.read(key: _key);
    return ThemePreference.fromString(value);
  }

  Future<void> set(ThemePreference mode) async {
    await _storage.write(key: _key, value: mode.storageValue);
  }
}
